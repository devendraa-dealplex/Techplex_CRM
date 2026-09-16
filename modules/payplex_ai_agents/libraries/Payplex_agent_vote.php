<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_vote
 *
 * Pure, dependency-free voting math for the Executive Council.
 *
 * M6 already gives the council a qualitative cross-review (each C-suite role
 * files a stance and the Group CEO consolidates). M11 adds the layer above it:
 * a formal MOTION put to the council, decided by a WEIGHTED VOTE under an
 * explicit quorum and threshold rule, with every dissent preserved.
 *
 * Design rules (consistent with the rest of the system):
 *  - Deterministic: same votes + same options => same outcome, every time.
 *  - Agents may disagree; dissent is never discarded, it is surfaced.
 *  - A vote is a RECOMMENDATION, never an execution. A motion that authorises a
 *    real-world action (binding) or that mentions an external action still
 *    requires human ratification + a Decision Packet — the ballot can never be
 *    a side-channel around the approval controls.
 *  - Maker != approver: whoever proposed a motion cannot be its ratifier.
 */
class Payplex_agent_vote
{
    /* Ballot values. */
    const FOR     = 'for';
    const AGAINST = 'against';
    const ABSTAIN = 'abstain';

    /* Threshold rules. */
    const SIMPLE    = 'simple_majority';
    const SUPER     = 'supermajority';
    const UNANIMOUS = 'unanimous';

    /* Outcome results. */
    const PASSED    = 'passed';
    const FAILED    = 'failed';
    const NO_QUORUM = 'no_quorum';
    const TIE       = 'tie';

    /* Motion lifecycle statuses (used by model/controller; defined here for reuse). */
    const DRAFT    = 'draft';
    const OPEN     = 'open';
    const RATIFIED = 'ratified';
    const VETOED   = 'vetoed';
    const CLOSED   = 'closed';

    const WEIGHT_MAX = 10.0;

    public static function voteValues()
    {
        return array(self::FOR, self::AGAINST, self::ABSTAIN);
    }

    public static function normalizeVote($v)
    {
        $v = strtolower(trim((string) $v));
        return in_array($v, self::voteValues(), true) ? $v : self::ABSTAIN;
    }

    public static function thresholdRules()
    {
        return array(self::SIMPLE, self::SUPER, self::UNANIMOUS);
    }

    public static function normalizeRule($r)
    {
        $r = strtolower(trim((string) $r));
        return in_array($r, self::thresholdRules(), true) ? $r : self::SIMPLE;
    }

    public static function motionTypes()
    {
        return array('advisory', 'policy', 'binding');
    }

    public static function normalizeType($t)
    {
        $t = strtolower(trim((string) $t));
        return in_array($t, self::motionTypes(), true) ? $t : 'advisory';
    }

    /** Vote weight in [0, WEIGHT_MAX]; blank/invalid => 1.0. */
    public static function normalizeWeight($w)
    {
        if ($w === null || $w === '' || !is_numeric($w)) { return 1.0; }
        $w = (float) $w;
        if ($w < 0) { return 0.0; }
        if ($w > self::WEIGHT_MAX) { return self::WEIGHT_MAX; }
        return $w;
    }

    /**
     * Tally a set of ballots.
     * @param array $votes list of ['vote'=>, 'weight'=>]
     */
    public static function tally(array $votes)
    {
        $forC = $againstC = $absC = 0;
        $forW = $againstW = $absW = 0.0;
        foreach ($votes as $v) {
            $val = self::normalizeVote(isset($v['vote']) ? $v['vote'] : '');
            $w   = self::normalizeWeight(isset($v['weight']) ? $v['weight'] : 1);
            if ($val === self::FOR)          { $forC++;     $forW += $w; }
            elseif ($val === self::AGAINST)  { $againstC++; $againstW += $w; }
            else                             { $absC++;     $absW += $w; }
        }
        return array(
            'for_count'       => $forC,
            'against_count'   => $againstC,
            'abstain_count'   => $absC,
            'for_weight'      => round($forW, 4),
            'against_weight'  => round($againstW, 4),
            'abstain_weight'  => round($absW, 4),
            'cast'            => $forC + $againstC + $absC,
            'decisive'        => $forC + $againstC,
            'decisive_weight' => round($forW + $againstW, 4),
        );
    }

    /**
     * Is quorum met? $quorum as a fraction in (0,1] tests cast/eligible;
     * as an integer >= 1 tests the raw number of ballots cast; 0 is always met
     * once at least one ballot is cast.
     */
    public static function quorumMet($eligible, $cast, $quorum)
    {
        $eligible = (int) $eligible;
        $cast     = (int) $cast;
        $quorum   = (float) $quorum;
        if ($quorum <= 0) { return $cast > 0; }
        if ($quorum < 1) {
            if ($eligible <= 0) { return false; }
            return ($cast / $eligible) >= ($quorum - 1e-9);
        }
        return $cast >= (int) ceil($quorum);
    }

    /** Does the weighted for/against split clear the threshold rule? */
    public static function thresholdMet($forWeight, $againstWeight, $rule)
    {
        $f = (float) $forWeight;
        $a = (float) $againstWeight;
        switch (self::normalizeRule($rule)) {
            case self::UNANIMOUS:
                return $f > 0 && $a == 0.0;
            case self::SUPER:
                // for must be at least twice against, and positive
                return $f > 0 && $f >= (2.0 * $a) - 1e-9;
            case self::SIMPLE:
            default:
                return $f > $a + 1e-9;
        }
    }

    /**
     * Full outcome of a motion.
     *
     * @param array $votes list of ['vote'=>,'weight'=>,'agent_id'=>,'rationale'=>]
     * @param array $opts  eligible(int), quorum(float=0.5), rule(string=simple)
     * @return array result/passed/quorum_met/rule/quorum + tally fields + dissent[]
     */
    public static function outcome(array $votes, array $opts = array())
    {
        $eligible = isset($opts['eligible']) ? (int) $opts['eligible'] : count($votes);
        $quorum   = isset($opts['quorum']) ? (float) $opts['quorum'] : 0.5;
        $rule     = self::normalizeRule(isset($opts['rule']) ? $opts['rule'] : self::SIMPLE);

        $t = self::tally($votes);
        $quorumMet = self::quorumMet($eligible, $t['cast'], $quorum);

        $dissent = array();
        foreach ($votes as $v) {
            if (self::normalizeVote(isset($v['vote']) ? $v['vote'] : '') === self::AGAINST) {
                $dissent[] = array(
                    'agent_id'  => isset($v['agent_id']) ? (int) $v['agent_id'] : 0,
                    'weight'    => self::normalizeWeight(isset($v['weight']) ? $v['weight'] : 1),
                    'rationale' => isset($v['rationale']) ? (string) $v['rationale'] : '',
                );
            }
        }

        if (!$quorumMet) {
            $result = self::NO_QUORUM;
            $passed = false;
        } elseif ($rule === self::SIMPLE && abs($t['for_weight'] - $t['against_weight']) < 1e-9 && $t['decisive'] > 0) {
            $result = self::TIE;
            $passed = false;
        } elseif (self::thresholdMet($t['for_weight'], $t['against_weight'], $rule)) {
            $result = self::PASSED;
            $passed = true;
        } else {
            $result = self::FAILED;
            $passed = false;
        }

        return array_merge($t, array(
            'result'     => $result,
            'passed'     => $passed,
            'quorum_met' => $quorumMet,
            'rule'       => $rule,
            'quorum'     => $quorum,
            'eligible'   => $eligible,
            'dissent'    => $dissent,
        ));
    }

    /**
     * Does a passed motion still need a human (Chairman) to ratify before
     * anything acts on it? Always yes for a binding motion or when the motion
     * text mentions a real external action.
     */
    public static function requiresRatification($motionType, $externalFlags = array())
    {
        if (!empty($externalFlags)) { return true; }
        return self::normalizeType($motionType) === 'binding';
    }

    /**
     * Maker != approver: the proposer can never ratify their own motion, and
     * only an admin/Chairman may ratify at all.
     */
    public static function canRatify($actorId, $proposedByStaffId, $isAdmin)
    {
        if (!$isAdmin) { return false; }
        return (int) $actorId !== (int) $proposedByStaffId;
    }

    /* ---------------- presentation helpers ---------------- */

    public static function voteClass($v)
    {
        switch (self::normalizeVote($v)) {
            case self::FOR:     return 'success';
            case self::AGAINST: return 'danger';
            default:            return 'default';
        }
    }

    public static function resultClass($r)
    {
        switch ($r) {
            case self::PASSED:    return 'success';
            case self::FAILED:    return 'danger';
            case self::TIE:       return 'warning';
            case self::NO_QUORUM: return 'warning';
            case self::RATIFIED:  return 'success';
            case self::VETOED:    return 'danger';
            default:              return 'default';
        }
    }

    public static function ruleLabel($rule)
    {
        switch (self::normalizeRule($rule)) {
            case self::SUPER:     return 'Super-majority (2:1)';
            case self::UNANIMOUS: return 'Unanimous (no dissent)';
            case self::SIMPLE:
            default:              return 'Simple majority';
        }
    }

    public static function summarize($outcome)
    {
        $r = isset($outcome['result']) ? $outcome['result'] : self::NO_QUORUM;
        $fw = isset($outcome['for_weight']) ? $outcome['for_weight'] : 0;
        $aw = isset($outcome['against_weight']) ? $outcome['against_weight'] : 0;
        $ab = isset($outcome['abstain_count']) ? $outcome['abstain_count'] : 0;
        $counts = 'for ' . $fw . ' vs against ' . $aw . ', ' . $ab . ' abstain';
        switch ($r) {
            case self::PASSED:    return 'Motion PASSED (' . $counts . ').';
            case self::FAILED:    return 'Motion FAILED to reach the threshold (' . $counts . ').';
            case self::TIE:       return 'Motion TIED — no majority (' . $counts . ').';
            case self::NO_QUORUM: return 'No quorum — vote is not decisive yet (' . $counts . ').';
        }
        return $counts;
    }
}
