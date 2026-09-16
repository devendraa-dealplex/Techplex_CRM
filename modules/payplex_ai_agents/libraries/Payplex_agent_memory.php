<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_memory
 *
 * Pure, dependency-free retrieval + anti-hallucination grounding over the
 * Executive Knowledge Base and Executive Memory records.
 *
 * Deterministic by design: no external AI call, no randomness. Given the same
 * corpus and query, retrieval returns the same ranked result every time — so a
 * decision's cited evidence is reproducible and auditable.
 *
 * Two responsibilities:
 *  - retrieve(): rank the CITABLE items relevant to a query (only approved,
 *    in-effect knowledge is ever returned to an agent).
 *  - groundingCheck(): before a decision that cites knowledge is accepted,
 *    verify every cited id actually exists and is citable. Any cited id that
 *    is missing, unapproved or expired fails the check — the agent is not
 *    allowed to "remember" something the knowledge base cannot back up.
 */
class Payplex_agent_memory
{
    /** English-ish stopwords kept tiny + deterministic. */
    private static $stop = array(
        'the','a','an','of','to','and','or','in','on','for','is','are','be','by','with',
        'that','this','it','as','at','from','we','our','you','your','will','shall','can',
        'should','must','not','no','do','does','if','then','than','so','but','into','per',
    );

    /** Lowercase word tokens, punctuation stripped, stopwords + very short dropped. */
    public static function tokenize($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
        $out  = array();
        foreach (explode(' ', $text) as $w) {
            if ($w === '' || strlen($w) < 2) { continue; }
            if (in_array($w, self::$stop, true)) { continue; }
            $out[] = $w;
        }
        return $out;
    }

    /**
     * Deterministic relevance of one entry to a query, in [0,1].
     * Title matches weigh 3x, tags 2x, body 1x. Score is the weighted fraction
     * of distinct query tokens found, lightly boosted by the entry's confidence
     * so a well-sourced fact outranks a shaky one on a tie.
     */
    public static function matchScore($query, $entry)
    {
        $q = array_values(array_unique(self::tokenize($query)));
        if (empty($q)) { return 0.0; }
        $e = (array) $entry;

        $title = self::tokenize(isset($e['title']) ? $e['title'] : '');
        $tags  = self::tokenize(isset($e['tags'])  ? $e['tags']  : '');
        $body  = self::tokenize(isset($e['body'])  ? $e['body']  : '');

        $tset = array_flip($title);
        $gset = array_flip($tags);
        $bset = array_flip($body);

        $score = 0.0; $maxPer = 3.0;
        foreach ($q as $tok) {
            if (isset($tset[$tok]))      { $score += 3.0; }
            elseif (isset($gset[$tok]))  { $score += 2.0; }
            elseif (isset($bset[$tok]))  { $score += 1.0; }
        }
        $frac = $score / ($maxPer * count($q));           // 0..1
        $conf = isset($e['confidence']) ? (float) $e['confidence'] : 0.5;
        // final: 85% relevance, 15% confidence — relevance dominates, confidence breaks ties
        return round(min(1.0, 0.85 * $frac + 0.15 * $conf * ($frac > 0 ? 1 : 0)), 4);
    }

    /**
     * Rank the citable items relevant to $query.
     *
     * @param array  $items   rows (arrays or objects) each with status/title/body/tags/confidence/id
     * @param string $query
     * @param array  $opts    limit(int=5), category(string), minConfidence(float),
     *                        asOf(date), requireCitable(bool=true)
     * @return array ranked list of array('item'=>row,'score'=>float)
     */
    public static function retrieve($items, $query, array $opts = array())
    {
        $limit    = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 5;
        $category = isset($opts['category']) ? Payplex_agent_exec_knowledge::normalizeCategory($opts['category']) : '';
        $minConf  = isset($opts['minConfidence']) ? (float) $opts['minConfidence'] : 0.0;
        $asOf     = isset($opts['asOf']) ? $opts['asOf'] : null;
        $requireCitable = !isset($opts['requireCitable']) || $opts['requireCitable'];

        $scored = array();
        foreach ($items as $row) {
            $e = (array) $row;
            if ($requireCitable && !Payplex_agent_exec_knowledge::isCitable($e, $asOf)) { continue; }
            if ($category !== '' && isset($e['category']) && $e['category'] !== $category) { continue; }
            if (isset($e['confidence']) && (float) $e['confidence'] < $minConf) { continue; }
            $s = self::matchScore($query, $e);
            if ($s <= 0) { continue; }
            $scored[] = array('item' => $row, 'score' => $s,
                              'conf' => isset($e['confidence']) ? (float) $e['confidence'] : 0.0,
                              'id'   => isset($e['id']) ? (int) $e['id'] : 0);
        }

        // deterministic sort: score desc, then confidence desc, then newest id desc
        usort($scored, function ($a, $b) {
            if ($a['score'] !== $b['score']) { return $a['score'] < $b['score'] ? 1 : -1; }
            if ($a['conf']  !== $b['conf'])  { return $a['conf']  < $b['conf']  ? 1 : -1; }
            return $b['id'] <=> $a['id'];
        });

        $out = array();
        foreach (array_slice($scored, 0, $limit) as $r) {
            $out[] = array('item' => $r['item'], 'score' => $r['score']);
        }
        return $out;
    }

    /**
     * Anti-hallucination gate. Given the ids an agent claims to be citing and
     * the corpus of known entries, confirm each cited id exists AND is citable.
     *
     * @return array(
     *   'ok'        => bool,   // true only if every cited id is valid & citable
     *   'valid'     => int[],  // cited ids that are citable
     *   'missing'   => int[],  // cited ids not found in the corpus (hallucinated)
     *   'uncitable' => int[],  // cited ids found but draft/returned/archived/expired
     * )
     */
    public static function groundingCheck($citedIds, $entries, $asOf = null)
    {
        $byId = array();
        foreach ($entries as $row) {
            $e = (array) $row;
            if (isset($e['id'])) { $byId[(int) $e['id']] = $e; }
        }
        $valid = array(); $missing = array(); $uncitable = array();
        foreach ((array) $citedIds as $cid) {
            $cid = (int) $cid;
            if ($cid <= 0) { continue; }
            if (!isset($byId[$cid])) { $missing[] = $cid; continue; }
            if (Payplex_agent_exec_knowledge::isCitable($byId[$cid], $asOf)) { $valid[] = $cid; }
            else { $uncitable[] = $cid; }
        }
        $ok = empty($missing) && empty($uncitable);
        return array('ok' => $ok, 'valid' => $valid, 'missing' => $missing, 'uncitable' => $uncitable);
    }

    /** Days since the entry was created/updated (freshness). */
    public static function freshnessDays($entry, $asOf = null)
    {
        $e = (array) $entry;
        $ref = isset($e['dateupdated']) && $e['dateupdated'] ? $e['dateupdated']
             : (isset($e['datecreated']) ? $e['datecreated'] : '');
        if (!$ref) { return null; }
        $t0 = strtotime((string) $ref);
        $t1 = $asOf ? strtotime((string) $asOf) : time();
        if ($t0 === false || $t1 === false) { return null; }
        return (int) floor(($t1 - $t0) / 86400);
    }

    public static function freshnessLabel($days)
    {
        if ($days === null) { return 'unknown'; }
        if ($days <= 30)  { return 'fresh'; }
        if ($days <= 180) { return 'recent'; }
        if ($days <= 365) { return 'aging'; }
        return 'stale';
    }

    /** Compact provenance string for display/audit. */
    public static function provenance($entry, $asOf = null)
    {
        $e    = (array) $entry;
        $band = Payplex_agent_exec_knowledge::confidenceBand(isset($e['confidence']) ? $e['confidence'] : 0.5);
        $fd   = self::freshnessDays($e, $asOf);
        $src  = isset($e['source']) && $e['source'] !== '' ? $e['source'] : 'no source';
        return Payplex_agent_exec_knowledge::citationRef($e) . ' · ' . $band . ' confidence · '
             . self::freshnessLabel($fd) . ' · ' . $src;
    }
}
