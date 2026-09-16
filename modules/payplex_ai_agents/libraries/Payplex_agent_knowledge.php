<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_knowledge
 *
 * Pure, dependency-free knowledge-base retrieval. The rule the spec demands is:
 * an agent answers ONLY from permitted knowledge and escalates when it is not
 * confident. This class encodes that decision so it can be unit-tested without
 * a DB or an AI provider.
 *
 * An entry is a plain array:
 *   ['id','title','content','keywords'(array|csv),'category',
 *    'is_active','indexing_status','scope'(array|'all')]
 */
class Payplex_agent_knowledge
{
    /** Tokenise text into lowercase word tokens (length >= 3). */
    public static function tokens($text)
    {
        $text = strtolower((string) $text);
        $text = preg_replace('/[^a-z0-9 ]+/', ' ', $text);
        $parts = preg_split('/\s+/', trim($text));
        $out = array();
        foreach ($parts as $p) {
            if (strlen($p) >= 3) {
                $out[$p] = true;
            }
        }
        return array_keys($out);
    }

    /** Is this KB entry usable at all? Active + indexed. */
    public static function isUsable($entry)
    {
        $active = isset($entry['is_active']) ? (int) $entry['is_active'] === 1 : true;
        $status = isset($entry['indexing_status']) ? $entry['indexing_status'] : 'indexed';
        return $active && $status === 'indexed';
    }

    /** Is this entry permitted for the given agent id? scope 'all' or contains the id. */
    public static function isPermitted($entry, $agentId)
    {
        if (!isset($entry['scope']) || $entry['scope'] === 'all' || $entry['scope'] === '') {
            return true;
        }
        $scope = $entry['scope'];
        if (is_string($scope)) {
            $decoded = json_decode($scope, true);
            $scope = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $scope)));
        }
        if (!is_array($scope) || empty($scope)) {
            return true; // empty scope == all
        }
        return in_array((string) $agentId, array_map('strval', $scope), true);
    }

    /** Filter a set of entries to those usable AND permitted for the agent. */
    public static function eligible($entries, $agentId)
    {
        $out = array();
        foreach ($entries as $e) {
            if (self::isUsable($e) && self::isPermitted($e, $agentId)) {
                $out[] = $e;
            }
        }
        return $out;
    }

    /** Score one entry against the query tokens (0..1 = fraction of query tokens matched). */
    public static function scoreEntry($queryTokens, $entry)
    {
        if (empty($queryTokens)) {
            return 0.0;
        }
        $hay = self::tokens(
            (isset($entry['title']) ? $entry['title'] . ' ' : '') .
            (isset($entry['content']) ? $entry['content'] . ' ' : '') .
            self::keywordString($entry)
        );
        $hay = array_flip($hay);
        $matched = 0;
        foreach ($queryTokens as $t) {
            if (isset($hay[$t])) {
                $matched++;
            }
        }
        return round($matched / count($queryTokens), 4);
    }

    private static function keywordString($entry)
    {
        if (!isset($entry['keywords'])) {
            return '';
        }
        $k = $entry['keywords'];
        if (is_array($k)) {
            return implode(' ', $k);
        }
        return (string) $k;
    }

    /**
     * Answer a query from permitted knowledge only.
     *
     * Returns:
     *   ['hit'=>true,  'entry'=>..., 'score'=>float, 'escalate'=>false]
     *   ['hit'=>false, 'entry'=>null, 'score'=>float, 'escalate'=>true, 'reason'=>'no_confident_match'|'no_permitted_knowledge']
     */
    public static function answer($query, $entries, $agentId, $threshold = 0.34)
    {
        $eligible = self::eligible($entries, $agentId);
        if (empty($eligible)) {
            return array('hit' => false, 'entry' => null, 'score' => 0.0, 'escalate' => true, 'reason' => 'no_permitted_knowledge');
        }
        $qt = self::tokens($query);
        $best = null;
        $bestScore = 0.0;
        foreach ($eligible as $e) {
            $s = self::scoreEntry($qt, $e);
            if ($s > $bestScore) {
                $bestScore = $s;
                $best = $e;
            }
        }
        if ($best !== null && $bestScore >= $threshold) {
            return array('hit' => true, 'entry' => $best, 'score' => $bestScore, 'escalate' => false);
        }
        return array('hit' => false, 'entry' => null, 'score' => $bestScore, 'escalate' => true, 'reason' => 'no_confident_match');
    }

    /** Categories an admin can file knowledge under. */
    public static function categories()
    {
        return array('product', 'faq', 'pricing', 'policy', 'script', 'document', 'webpage', 'objection');
    }
}
