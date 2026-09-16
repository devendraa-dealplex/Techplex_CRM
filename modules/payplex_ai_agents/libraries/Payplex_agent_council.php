<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_council
 *
 * Pure, dependency-free logic for the AI Executive Council cross-review.
 *
 * For a material decision the council reviews across dimensions:
 *   CFO (financial) -> CRO (risk) -> Legal/Compliance (regulatory) ->
 *   CISO (security/data) -> CAO (independent evidence & conflict check)
 * then the Group CEO consolidates a brief and the Chairman makes the final call.
 *
 * Design rules:
 *  - Agents MUST be able to disagree. consolidate() never forces consensus;
 *    every stance and every dissent is preserved and surfaced.
 *  - Some roles are HARD BLOCKERS: if Legal, CISO or the independent Audit
 *    officer opposes, the council cannot recommend "proceed" — it goes back
 *    for rework. The Chairman still decides, but never on a green light the
 *    council did not give.
 */
class Payplex_agent_council
{
    /* Stances a reviewer can take. */
    const SUPPORT  = 'support';
    const CONCERNS = 'concerns';
    const OPPOSE   = 'oppose';
    const ABSTAIN  = 'abstain';

    /* Council recommendations (what the CEO consolidation reports). */
    const PROCEED            = 'proceed';
    const PROCEED_CONDITIONS = 'proceed_with_conditions';
    const BLOCKED            = 'blocked';        // a hard-blocker opposed
    const INCOMPLETE         = 'incomplete';     // not all required reviews in

    /**
     * The review roster: role => [label, dimension, hard_blocker].
     * Order is the review sequence.
     */
    public static function roster()
    {
        return array(
            'cfo'   => array('label' => 'AI CFO',                 'dimension' => 'financial',  'blocker' => false),
            'cro'   => array('label' => 'AI Chief Risk Officer',  'dimension' => 'risk',       'blocker' => false),
            'legal' => array('label' => 'AI Legal & Compliance',  'dimension' => 'regulatory', 'blocker' => true),
            'ciso'  => array('label' => 'AI CISO',                'dimension' => 'security',   'blocker' => true),
            'cao'   => array('label' => 'AI Chief Audit Officer', 'dimension' => 'evidence',   'blocker' => true),
        );
    }

    /** Ordered list of reviewer role keys. */
    public static function reviewerRoles()
    {
        return array_keys(self::roster());
    }

    public static function isBlocker($role)
    {
        $r = self::roster();
        return isset($r[$role]) ? (bool) $r[$role]['blocker'] : false;
    }

    public static function validStances()
    {
        return array(self::SUPPORT, self::CONCERNS, self::OPPOSE, self::ABSTAIN);
    }

    /** The role that gives the next expected review, or null when all in. */
    public static function nextReviewer(array $doneRoles)
    {
        foreach (self::reviewerRoles() as $role) {
            if (!in_array($role, $doneRoles, true)) {
                return $role;
            }
        }
        return null;
    }

    /**
     * Consolidate the council's reviews (the Group CEO step). Pure.
     *
     * @param array $reviews list of ['role'=>, 'stance'=>, 'confidence'=>float, 'comments'=>]
     * @return array {
     *   recommendation, conflict(bool), council_confidence(min), avg_confidence,
     *   dissents[], blockers[], supported(int), opposed(int), concerns(int),
     *   abstained(int), missing_roles[], stances(role=>stance), summary
     * }
     */
    public static function consolidate(array $reviews)
    {
        $roster = self::roster();
        $byRole = array();
        foreach ($reviews as $r) {
            $role = isset($r['role']) ? $r['role'] : '';
            if ($role !== '') { $byRole[$role] = $r; }
        }

        $stances = array();
        $confidences = array();
        $support = $oppose = $concerns = $abstain = 0;
        $dissents = array();
        $blockers = array();

        foreach ($byRole as $role => $r) {
            $stance = isset($r['stance']) && in_array($r['stance'], self::validStances(), true) ? $r['stance'] : self::ABSTAIN;
            $stances[$role] = $stance;
            if (isset($r['confidence']) && is_numeric($r['confidence'])) {
                $confidences[] = (float) $r['confidence'];
            }
            switch ($stance) {
                case self::SUPPORT:  $support++;  break;
                case self::OPPOSE:   $oppose++;   break;
                case self::CONCERNS: $concerns++; break;
                default:             $abstain++;  break;
            }
            if ($stance === self::OPPOSE || $stance === self::CONCERNS) {
                $dissents[] = array(
                    'role'    => $role,
                    'label'   => isset($roster[$role]) ? $roster[$role]['label'] : $role,
                    'stance'  => $stance,
                    'comments'=> isset($r['comments']) ? $r['comments'] : '',
                );
            }
            if ($stance === self::OPPOSE && self::isBlocker($role)) {
                $blockers[] = $role;
            }
        }

        // Which required roles have not reviewed yet?
        $missing = array();
        foreach (self::reviewerRoles() as $role) {
            if (!isset($byRole[$role])) { $missing[] = $role; }
        }

        // conflict = at least one support AND at least one oppose (genuine split)
        $conflict = ($support > 0 && $oppose > 0);

        // council confidence: the weakest link (min) drives the gate; also report avg
        $councilConf = empty($confidences) ? null : min($confidences);
        $avgConf     = empty($confidences) ? null : array_sum($confidences) / count($confidences);

        // recommendation
        if (!empty($blockers)) {
            $rec = self::BLOCKED;
        } elseif (!empty($missing)) {
            $rec = self::INCOMPLETE;
        } elseif ($oppose === 0 && $concerns === 0) {
            $rec = self::PROCEED;
        } else {
            // dissent present but no hard blocker -> proceed with conditions
            $rec = self::PROCEED_CONDITIONS;
        }

        $summary = self::summaryLine($rec, $support, $concerns, $oppose, $abstain, $blockers, $missing);

        return array(
            'recommendation'   => $rec,
            'conflict'         => $conflict,
            'council_confidence' => $councilConf,
            'avg_confidence'   => $avgConf,
            'dissents'         => $dissents,
            'blockers'         => $blockers,
            'supported'        => $support,
            'opposed'          => $oppose,
            'concerns'         => $concerns,
            'abstained'        => $abstain,
            'missing_roles'    => $missing,
            'stances'          => $stances,
            'summary'          => $summary,
        );
    }

    private static function summaryLine($rec, $support, $concerns, $oppose, $abstain, $blockers, $missing)
    {
        $counts = $support . ' support, ' . $concerns . ' concerns, ' . $oppose . ' oppose, ' . $abstain . ' abstain';
        switch ($rec) {
            case self::BLOCKED:
                return 'Council recommends NOT proceeding — hard blocker(s): ' . implode(', ', $blockers) . '. (' . $counts . ')';
            case self::INCOMPLETE:
                return 'Council review incomplete — awaiting: ' . implode(', ', $missing) . '. (' . $counts . ')';
            case self::PROCEED:
                return 'Council supports proceeding (no dissent). (' . $counts . ')';
            case self::PROCEED_CONDITIONS:
                return 'Council can proceed WITH CONDITIONS — dissent preserved for the Chairman. (' . $counts . ')';
        }
        return $counts;
    }

    /**
     * Does this council result allow the decision to be recommended to the
     * Chairman as approvable? (Chairman still decides; this only gates the
     * green light the council itself is willing to give.)
     */
    public static function allowsApproval($consolidation)
    {
        $rec = isset($consolidation['recommendation']) ? $consolidation['recommendation'] : self::INCOMPLETE;
        return in_array($rec, array(self::PROCEED, self::PROCEED_CONDITIONS), true);
    }
}
