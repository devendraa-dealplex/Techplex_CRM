<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_exec_knowledge
 *
 * Pure, dependency-free governance for the EXECUTIVE Knowledge Base — the
 * curated, citable facts / policies / rulings the C-suite AI agents may ground
 * their decisions on. (Distinct from the M2 customer-support knowledge base in
 * Payplex_agent_knowledge, which powers agent replies.)
 *
 * Anti-hallucination principle: an agent may only cite executive knowledge that
 * is APPROVED and currently in effect. A draft, returned, archived or expired
 * entry is NOT citable, so an agent can never justify a board-level decision
 * with unreviewed or stale "knowledge".
 *
 * Governance principle (same as Decision Packets): maker != approver. Whoever
 * wrote or last edited an entry cannot be the one who approves it.
 */
class Payplex_agent_exec_knowledge
{
    const DRAFT    = 'draft';       // being written
    const REVIEW   = 'review';      // submitted, awaiting a reviewer/approver
    const APPROVED = 'approved';    // live and citable (within effective window)
    const RETURNED = 'returned';    // sent back for changes
    const ARCHIVED = 'archived';    // retired, no longer citable

    /** Knowledge categories. */
    public static function categories()
    {
        return array('policy', 'financial', 'market', 'product', 'legal', 'operations', 'security', 'strategy', 'general');
    }

    public static function normalizeCategory($c)
    {
        $c = strtolower(trim((string) $c));
        return in_array($c, self::categories(), true) ? $c : 'general';
    }

    /**
     * Validate + normalize an executive knowledge entry for saving.
     * Returns array('ok'=>bool, 'errors'=>[], 'warnings'=>[], 'entry'=>[]).
     */
    public static function validate(array $in)
    {
        $errors   = array();
        $warnings = array();

        $title = trim((string) (isset($in['title']) ? $in['title'] : ''));
        $body  = trim((string) (isset($in['body']) ? $in['body'] : ''));
        if ($title === '')                 { $errors[] = 'title_required'; }
        if (mb_strlen($title) > 200)       { $errors[] = 'title_too_long'; }
        if ($body === '')                  { $errors[] = 'body_required'; }

        $category = self::normalizeCategory(isset($in['category']) ? $in['category'] : '');

        // confidence: clamp to 0..1; default 0.5
        $confidence = isset($in['confidence']) && $in['confidence'] !== '' ? (float) $in['confidence'] : 0.5;
        if ($confidence < 0) { $confidence = 0.0; }
        if ($confidence > 1) { $confidence = 1.0; }

        // source strongly recommended for a citable fact (provenance)
        $source = trim((string) (isset($in['source']) ? $in['source'] : ''));
        if ($source === '') { $warnings[] = 'no_source_low_trust'; }

        $url = trim((string) (isset($in['url']) ? $in['url'] : ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) { $warnings[] = 'url_not_http'; }

        $tags = self::normalizeTags(isset($in['tags']) ? $in['tags'] : '');

        // effective window (optional). If both set, from <= to.
        $from = self::normDate(isset($in['effective_from']) ? $in['effective_from'] : '');
        $to   = self::normDate(isset($in['effective_to'])   ? $in['effective_to']   : '');
        if ($from !== '' && $to !== '' && $to < $from) { $errors[] = 'effective_window_inverted'; }

        $entry = array(
            'title'          => $title,
            'body'           => $body,
            'category'       => $category,
            'company'        => strtolower(trim((string) (isset($in['company']) ? $in['company'] : ''))),
            'source'         => $source,
            'url'            => $url,
            'confidence'     => round($confidence, 3),
            'tags'           => implode(',', $tags),
            'effective_from' => $from,
            'effective_to'   => $to,
        );

        return array('ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'entry' => $entry);
    }

    public static function normalizeTags($tags)
    {
        if (is_array($tags)) { $parts = $tags; }
        else { $parts = preg_split('/[,\n]+/', (string) $tags); }
        $out = array();
        foreach ($parts as $p) {
            $p = strtolower(trim((string) $p));
            $p = preg_replace('/\s+/', '-', $p);
            if ($p !== '' && !in_array($p, $out, true)) { $out[] = $p; }
        }
        return $out;
    }

    private static function normDate($d)
    {
        $d = trim((string) $d);
        if ($d === '') { return ''; }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $d, $m)) { return $m[1]; }
        return '';
    }

    /**
     * Is this entry citable by an agent right now?
     * Must be APPROVED, active (not soft-deleted), and within its effective window.
     */
    public static function isCitable($entry, $asOf = null)
    {
        $e = (array) $entry;
        $status = isset($e['status']) ? $e['status'] : '';
        if ($status !== self::APPROVED) { return false; }
        if (isset($e['active']) && (int) $e['active'] === 0) { return false; }
        return self::effectiveWindowOk($e, $asOf);
    }

    public static function effectiveWindowOk($entry, $asOf = null)
    {
        $e   = (array) $entry;
        $now = $asOf ? substr((string) $asOf, 0, 10) : date('Y-m-d');
        $from = isset($e['effective_from']) ? (string) $e['effective_from'] : '';
        $to   = isset($e['effective_to'])   ? (string) $e['effective_to']   : '';
        if ($from !== '' && $now < $from) { return false; }  // not yet in effect
        if ($to !== '' && $now > $to)     { return false; }  // expired
        return true;
    }

    /**
     * Governance state machine. Returns array('ok'=>bool,'status'=>?,'error'=>?).
     *
     * @param string $action   submit|approve|return|archive|reopen
     * @param bool   $isMaker  is the actor the entry's maker/last-editor?
     * @param bool   $canApprove does the actor hold the approve capability?
     */
    public static function transition($entry, $action, $isMaker, $canApprove)
    {
        $e    = (array) $entry;
        $from = isset($e['status']) ? $e['status'] : self::DRAFT;

        switch ($action) {
            case 'submit':
                if (!in_array($from, array(self::DRAFT, self::RETURNED), true)) {
                    return self::err('only_draft_or_returned_can_submit');
                }
                return self::ok(self::REVIEW);

            case 'approve':
                if ($from !== self::REVIEW) { return self::err('only_review_can_be_approved'); }
                if (!$canApprove)           { return self::err('not_authorized_to_approve'); }
                if ($isMaker)               { return self::err('maker_cannot_approve_own_entry'); }
                return self::ok(self::APPROVED);

            case 'return':
                if ($from !== self::REVIEW) { return self::err('only_review_can_be_returned'); }
                if (!$canApprove)           { return self::err('not_authorized_to_return'); }
                return self::ok(self::RETURNED);

            case 'archive':
                if (!in_array($from, array(self::APPROVED, self::REVIEW, self::RETURNED, self::DRAFT), true)) {
                    return self::err('cannot_archive_from_' . $from);
                }
                if (!$canApprove) { return self::err('not_authorized_to_archive'); }
                return self::ok(self::ARCHIVED);

            case 'reopen':
                if ($from !== self::ARCHIVED) { return self::err('only_archived_can_reopen'); }
                if (!$canApprove)             { return self::err('not_authorized_to_reopen'); }
                return self::ok(self::DRAFT);
        }
        return self::err('unknown_action');
    }

    /** Short, stable citation reference for an entry. */
    public static function citationRef($entry)
    {
        $e = (array) $entry;
        $id = isset($e['id']) ? (int) $e['id'] : 0;
        return 'KB#' . $id;
    }

    /** Human confidence band. */
    public static function confidenceBand($c)
    {
        $c = (float) $c;
        if ($c >= 0.8) { return 'high'; }
        if ($c >= 0.5) { return 'medium'; }
        return 'low';
    }

    private static function ok($status) { return array('ok' => true, 'status' => $status, 'error' => null); }
    private static function err($e)      { return array('ok' => false, 'status' => null, 'error' => $e); }
}
