<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_consent
 *
 * Consent state machine for personal-data processing (spec section 6).
 *
 * Policy approved 2026-09-10: location tracking is EXPLICIT OPT-IN and revocable
 * at any time. India's DPDP Act treats precise location as personal data, so the
 * rules below are deliberately strict and are enforced server-side, never in the
 * UI alone:
 *
 *   1. Consent is an APPEND-ONLY ledger. Granting writes a row; withdrawing writes
 *      another row. Nothing is ever updated or deleted, so the consent state at any
 *      past instant can be reconstructed for an audit or a dispute.
 *   2. State is derived from the LATEST row for (staff, purpose). No cached flag on
 *      the staff profile, because a stale flag is how systems keep collecting data
 *      after a withdrawal.
 *   3. Withdrawal blocks all FUTURE collection. It never deletes history — the
 *      already-collected points remain until the retention purge removes them, so
 *      payroll and expense claims already filed stay auditable.
 *   4. A grant records the exact policy version and a hash of the notice text the
 *      person actually saw, so "what did they agree to" is answerable later.
 *
 * Pure and dependency-free: every method takes the ledger rows as data, so the
 * rules are unit-testable without a database.
 */
class Payplex_staff_consent
{
    const PURPOSE_LOCATION = 'location_tracking';
    const POLICY_VERSION   = '1.0';

    const ACTION_GRANT    = 'granted';
    const ACTION_WITHDRAW = 'withdrawn';

    /** Purposes this module knows how to gate. */
    public static function purposes()
    {
        return array(
            self::PURPOSE_LOCATION => 'Field location tracking during check-in sessions',
        );
    }

    public static function normalizePurpose($p)
    {
        $p = strtolower(trim((string) $p));
        return isset(self::purposes()[$p]) ? $p : self::PURPOSE_LOCATION;
    }

    public static function normalizeAction($a)
    {
        $a = strtolower(trim((string) $a));
        return $a === self::ACTION_GRANT ? self::ACTION_GRANT : self::ACTION_WITHDRAW;
    }

    /**
     * The notice a staff member must be shown before opting in. Kept in code (not
     * the database) so it is version-controlled and its hash is reproducible.
     */
    public static function noticeText($policyVersion = self::POLICY_VERSION)
    {
        return implode("\n", array(
            'Field location tracking — consent notice (v' . $policyVersion . ')',
            '',
            'What is collected: your device GPS coordinates and their accuracy.',
            'When: ONLY between the moment you start a field check-in and the moment',
            '  you check out. Nothing is collected outside a check-in session — not',
            '  during off hours, not on weekends, not while the app merely runs.',
            'Why: to verify customer visits, calculate travel distance for TA-DA and',
            '  expense claims, and substantiate field allowances.',
            'Who can see it: you, your reporting manager, and authorised HR/Finance',
            '  staff with the field tracking permission.',
            'How long: raw location points are automatically deleted after 90 days.',
            '  Only visit summaries (start, end, duration, total distance) are kept',
            '  after that, because payroll and expense records must remain auditable.',
            'Your rights: you may view all data held about you at any time, and you',
            '  may withdraw this consent at any time. Withdrawal stops all future',
            '  collection immediately. It does not erase visits already recorded, as',
            '  those may support expense claims you have already filed.',
        ));
    }

    public static function noticeSha1($policyVersion = self::POLICY_VERSION)
    {
        return sha1(self::noticeText($policyVersion));
    }

    /**
     * Derive the current consent record from an append-only ledger.
     *
     * @param array $rows ledger rows (arrays or objects) with action/occurred_at/id
     * @return array|null the latest row as an array, or null if the person has
     *                    never been asked
     */
    public static function latest($rows, $purpose = self::PURPOSE_LOCATION)
    {
        $purpose = self::normalizePurpose($purpose);
        $best = null;
        foreach ((array) $rows as $r) {
            $r = (array) $r;
            if (self::normalizePurpose(isset($r['purpose']) ? $r['purpose'] : '') !== $purpose) { continue; }
            if ($best === null || self::isLater($r, $best)) { $best = $r; }
        }
        return $best;
    }

    /**
     * Ordering for ledger rows. Timestamp first; id breaks ties, because a grant
     * and a withdrawal can land in the same second and getting that order wrong
     * would flip the answer to "may we collect right now".
     */
    private static function isLater($a, $b)
    {
        $ta = isset($a['occurred_at']) ? strtotime((string) $a['occurred_at']) : 0;
        $tb = isset($b['occurred_at']) ? strtotime((string) $b['occurred_at']) : 0;
        if ($ta === false) { $ta = 0; }
        if ($tb === false) { $tb = 0; }
        if ($ta !== $tb) { return $ta > $tb; }
        return (int) (isset($a['id']) ? $a['id'] : 0) > (int) (isset($b['id']) ? $b['id'] : 0);
    }

    /**
     * Is consent active right now?
     * Absence of any record means NO. Consent is never assumed from silence.
     */
    public static function isGranted($rows, $purpose = self::PURPOSE_LOCATION)
    {
        $latest = self::latest($rows, $purpose);
        if ($latest === null) { return false; }
        return self::normalizeAction(isset($latest['action']) ? $latest['action'] : '') === self::ACTION_GRANT;
    }

    /**
     * Consent state as it stood at a given instant — for reconstructing whether a
     * historical point was lawfully collected.
     */
    public static function wasGrantedAt($rows, $when, $purpose = self::PURPOSE_LOCATION)
    {
        $t = is_numeric($when) ? (int) $when : strtotime((string) $when);
        if ($t === false) { return false; }
        $filtered = array();
        foreach ((array) $rows as $r) {
            $r  = (array) $r;
            $rt = isset($r['occurred_at']) ? strtotime((string) $r['occurred_at']) : false;
            if ($rt === false || $rt > $t) { continue; }
            $filtered[] = $r;
        }
        return self::isGranted($filtered, $purpose);
    }

    /** Human-readable status for the consent centre. */
    public static function status($rows, $purpose = self::PURPOSE_LOCATION)
    {
        $latest = self::latest($rows, $purpose);
        if ($latest === null) {
            return array(
                'state'   => 'never_asked',
                'label'   => 'Not yet asked',
                'class'   => 'default',
                'since'   => null,
                'may_collect' => false,
            );
        }
        $granted = self::isGranted($rows, $purpose);
        return array(
            'state'       => $granted ? 'granted' : 'withdrawn',
            'label'       => $granted ? 'Consent given' : 'Consent withdrawn',
            'class'       => $granted ? 'success' : 'danger',
            'since'       => isset($latest['occurred_at']) ? $latest['occurred_at'] : null,
            'policy'      => isset($latest['policy_version']) ? $latest['policy_version'] : null,
            'consent_id'  => isset($latest['id']) ? (int) $latest['id'] : 0,
            'may_collect' => $granted,
        );
    }

    /**
     * Is a transition allowed? Re-granting an active consent, or withdrawing one
     * that is already withdrawn, is a no-op rather than a new ledger row — this
     * keeps the ledger meaningful and stops a double-clicked button from writing
     * a misleading pair of rows.
     */
    public static function canTransition($rows, $action, $purpose = self::PURPOSE_LOCATION)
    {
        $action  = self::normalizeAction($action);
        $granted = self::isGranted($rows, $purpose);
        if ($action === self::ACTION_GRANT)    { return !$granted; }
        return $granted;
    }

    /**
     * Build the row to append for a consent action. Returns null when the
     * transition is a no-op.
     */
    public static function buildRow($rows, $staffId, $action, $opts = array(), $purpose = self::PURPOSE_LOCATION)
    {
        if (!self::canTransition($rows, $action, $purpose)) { return null; }
        $action  = self::normalizeAction($action);
        $purpose = self::normalizePurpose($purpose);
        $version = isset($opts['policy_version']) && $opts['policy_version'] !== ''
            ? (string) $opts['policy_version']
            : self::POLICY_VERSION;

        return array(
            'staff_id'         => (int) $staffId,
            'purpose'          => $purpose,
            'action'           => $action,
            'policy_version'   => $version,
            // only a grant meaningfully binds to notice text
            'notice_text_sha1' => $action === self::ACTION_GRANT ? self::noticeSha1($version) : null,
            'source'           => isset($opts['source']) ? substr((string) $opts['source'], 0, 30) : 'web',
            'ip'               => isset($opts['ip']) ? substr((string) $opts['ip'], 0, 45) : null,
            'user_agent'       => isset($opts['user_agent']) ? substr((string) $opts['user_agent'], 0, 255) : null,
            'actor_id'         => (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0),
            'reason'           => isset($opts['reason']) ? substr((string) $opts['reason'], 0, 255) : null,
            'occurred_at'      => isset($opts['occurred_at']) && $opts['occurred_at'] !== ''
                ? (string) $opts['occurred_at']
                : date('Y-m-d H:i:s'),
        );
    }

    /**
     * The single gate every location write must pass. Returns an array with
     * 'allowed' and, when refused, a 'reason' safe to show the user.
     *
     * Deliberately takes the ledger AND the session so that neither can be
     * bypassed: consent alone is not enough, an open session alone is not enough.
     */
    public static function gate($rows, $session, $purpose = self::PURPOSE_LOCATION)
    {
        if (!self::isGranted($rows, $purpose)) {
            return array('allowed' => false, 'code' => 'no_consent',
                'reason' => 'Location consent is not active for this staff member.');
        }
        $s = (array) $session;
        if (empty($s) || !isset($s['id'])) {
            return array('allowed' => false, 'code' => 'no_session',
                'reason' => 'No open field session — location is only captured between check-in and check-out.');
        }
        if ((string) (isset($s['status']) ? $s['status'] : '') !== 'open') {
            return array('allowed' => false, 'code' => 'session_closed',
                'reason' => 'The field session is already closed.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }
}
