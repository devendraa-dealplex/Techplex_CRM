<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_activity
 *
 * The legitimate-business-event taxonomy for employee activity, plus timeline
 * bucketing and aggregation. Pure + dependency-free.
 *
 * A deliberate design rule (spec section 5/7): performance must NOT be computed
 * from clicks, login duration or raw activity count. So every event carries a
 * category and, crucially, a flag for whether it is a real PERFORMANCE OUTCOME
 * (a verified conversion, collection, manager-verification, SLA result) versus
 * mere raw activity (login, a call placed). Only performance outcomes — and only
 * when verified — ever feed the KPI engine.
 */
class Payplex_staff_activity
{
    /**
     * event => [category, outcome(bool), negative(bool)]
     *  - outcome=true  : a business result that can earn/lose performance points
     *  - outcome=false : raw activity, tracked for the timeline but never scored
     *  - negative=true : a bad signal (breach/rejection) — scored as a penalty
     */
    private static function map()
    {
        return array(
            'login'                => array('auth',       false, false),
            'logout'               => array('auth',       false, false),
            'attendance_checkin'   => array('attendance', false, false),
            'attendance_checkout'  => array('attendance', false, false),
            'lead_assigned'        => array('lead',       false, false),
            'lead_accepted'        => array('lead',       false, false),
            'call'                 => array('lead',       false, false),
            'call_outcome'         => array('lead',       false, false),
            'followup'             => array('lead',       false, false),
            'meeting'              => array('lead',       false, false),
            'task'                 => array('task',       false, false),
            'task_progress'        => array('task',       false, false),
            'evidence_submitted'   => array('task',       false, false),
            'manager_verified'     => array('task',       true,  false),
            'proposal'             => array('sales',      false, false),
            'conversion'           => array('sales',      true,  false),
            'collection'           => array('sales',      true,  false),
            'customer_visit'       => array('field',      false, false),
            'visit_verified'       => array('field',      true,  false),
            'expense_submitted'    => array('finance',    false, false),
            'sla_breach'           => array('exception',  true,  true),
            'idle_lead'            => array('exception',  false, true),
            'reassignment'         => array('exception',  false, false),
            'rejection'            => array('exception',  true,  true),
            'reopened'             => array('exception',  true,  true),
            'other'                => array('other',      false, false),
        );
    }

    public static function eventTypes()
    {
        $out = array();
        foreach (self::map() as $k => $v) { $out[$k] = $v[0]; }
        return $out;
    }

    public static function normalizeEvent($e)
    {
        $e = strtolower(trim((string) $e));
        $e = str_replace(array(' ', '-'), '_', $e);
        return isset(self::map()[$e]) ? $e : 'other';
    }

    public static function category($e)
    {
        $m = self::map();
        $e = self::normalizeEvent($e);
        return $m[$e][0];
    }

    public static function isPerformanceOutcome($e)
    {
        $m = self::map();
        return (bool) $m[self::normalizeEvent($e)][1];
    }

    public static function isNegative($e)
    {
        $m = self::map();
        return (bool) $m[self::normalizeEvent($e)][2];
    }

    /**
     * Who may mark an activity record verified.
     *
     * Only verified records earn performance points, so `verified` is not a
     * field like any other — it is the moment a claim becomes evidence. The
     * logging endpoint took it straight from the POST body under the same
     * 'activity' capability that lets someone create the record, which meant
     * one person, in one request, could both assert that they made a customer
     * visit and certify that they had. A 'verify' capability was registered for
     * this and used nowhere.
     *
     * Two rules, matching the commission module's: the verifier needs the
     * capability, and the verifier may not be the beneficiary.
     *
     * A refusal never discards the record. The activity is still logged — it
     * simply stays unverified, which is the truthful state for a claim nobody
     * independent has confirmed. Callers surface the reason so the difference
     * between "saved" and "saved and counted" is visible rather than silent.
     *
     * @return array verified => 0|1, refusal => null|string
     */
    public static function verificationDecision($staffId, $actorId, $wantsVerified, $canVerify)
    {
        if ((int) $wantsVerified !== 1) {
            return array('verified' => 0, 'refusal' => null);
        }
        if (!$canVerify) {
            return array('verified' => 0, 'refusal' => 'not_permitted');
        }
        if ((int) $actorId <= 0) {
            return array('verified' => 0, 'refusal' => 'unknown_actor');
        }
        if ((int) $actorId === (int) $staffId) {
            return array('verified' => 0, 'refusal' => 'self_verification');
        }
        return array('verified' => 1, 'refusal' => null);
    }

    /** Human-readable reason for a refused verification. */
    public static function refusalMessage($refusal)
    {
        switch ($refusal) {
            case 'not_permitted':
                return 'Recorded as unverified: verifying an activity record requires the "Verify staff records" permission.';
            case 'self_verification':
                return 'Recorded as unverified: you cannot verify your own activity. Someone with the verify permission must confirm it.';
            case 'unknown_actor':
                return 'Recorded as unverified: the verifying user could not be identified.';
            case 'already_verified':
                return 'That record is already verified.';
            case 'not_found':
                return 'That activity record no longer exists.';
            case 'own_entry':
                return 'You created this record, so you cannot also verify it.';
            default:
                return '';
        }
    }

    /** Bucket key for a timestamp: daily (Y-m-d), monthly (Y-m), weekly (Y-\WW ISO). */
    public static function bucketKey($ts, $period = 'daily')
    {
        $t = is_numeric($ts) ? (int) $ts : strtotime((string) $ts);
        if ($t === false) { return ''; }
        switch ($period) {
            case 'monthly': return date('Y-m', $t);
            case 'weekly':  return date('o-\WW', $t);
            case 'daily':
            default:        return date('Y-m-d', $t);
        }
    }

    /**
     * Group events into time buckets with counts and verified-outcome counts.
     * @param array $events each with event_type, occurred_at, verified
     */
    public static function timeline($events, $period = 'daily')
    {
        $out = array();
        foreach ($events as $e) {
            $key = self::bucketKey(isset($e['occurred_at']) ? $e['occurred_at'] : '', $period);
            if ($key === '') { continue; }
            if (!isset($out[$key])) { $out[$key] = array('count' => 0, 'verified_outcomes' => 0, 'by_category' => array()); }
            $out[$key]['count']++;
            $type = self::normalizeEvent(isset($e['event_type']) ? $e['event_type'] : '');
            $cat  = self::category($type);
            $out[$key]['by_category'][$cat] = (isset($out[$key]['by_category'][$cat]) ? $out[$key]['by_category'][$cat] : 0) + 1;
            if (self::isPerformanceOutcome($type) && (int) (isset($e['verified']) ? $e['verified'] : 0) === 1) {
                $out[$key]['verified_outcomes']++;
            }
        }
        ksort($out);
        return $out;
    }

    /** Flat aggregate across a set of events. */
    public static function aggregate($events)
    {
        $out = array('total' => 0, 'by_category' => array(), 'verified_outcomes' => 0, 'raw_activity' => 0);
        foreach ($events as $e) {
            $out['total']++;
            $type = self::normalizeEvent(isset($e['event_type']) ? $e['event_type'] : '');
            $cat  = self::category($type);
            $out['by_category'][$cat] = (isset($out['by_category'][$cat]) ? $out['by_category'][$cat] : 0) + 1;
            $verified = (int) (isset($e['verified']) ? $e['verified'] : 0) === 1;
            if (self::isPerformanceOutcome($type) && $verified) {
                $out['verified_outcomes']++;
            } else {
                $out['raw_activity']++;
            }
        }
        return $out;
    }

    public static function categoryClass($cat)
    {
        switch ($cat) {
            case 'sales':      return 'success';
            case 'task':       return 'info';
            case 'field':      return 'primary';
            case 'exception':  return 'danger';
            case 'finance':    return 'warning';
            default:           return 'default';
        }
    }
}
