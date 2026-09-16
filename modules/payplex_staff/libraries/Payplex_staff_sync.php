<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Keeping the workforce record and the CRM account from drifting apart.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * The module's own summary tiles read "7 active". tblstaff read 5. Both numbers
 * were correct about different things and neither said which:
 *
 *   - profile `status` is the EMPLOYMENT state (draft ... active ... exited)
 *   - tblstaff.active is whether the CRM ACCOUNT can sign in
 *
 * backfillExistingStaff() hard-codes `status => 'active'` for every staff member
 * it finds, regardless of whether that person's login is switched off. Four
 * deactivated accounts therefore carried an "active" employment record, and the
 * workforce module reported a headcount two higher than the CRM.
 *
 * Worse in the other direction: Arif Ansari (#27) was marked `exited` in the
 * profile while tblstaff.active stayed 1, and he signed in on 2026-09-04. An
 * employment record saying somebody has left, next to a working login, is not a
 * reporting inconsistency — it is an access-control failure.
 *
 * COVERAGE
 * --------
 * ARK Gupta (#29) was created on 2026-09-10 and had no profile at all. Nothing
 * in the module hooked staff creation; coverage depended on an admin pressing
 * "Backfill existing staff". A workforce system that silently misses new joiners
 * is worse than one that has none, because the gap is invisible.
 *
 * Perfex fires `staff_member_created`, `staff_member_updated`,
 * `after_staff_status_change` and `staff_member_deleted` from
 * application/models/Staff_model.php — verified by reading that file on this
 * install, not assumed. The names are real, which is why this module now uses
 * them instead of a manual button.
 *
 * The rules below are pure so they can be tested without a database.
 */
class Payplex_staff_sync
{
    /**
     * What the two systems are each saying, and whether they agree.
     *
     * @param string $profileStatus employment state
     * @param int    $crmActive     tblstaff.active
     * @return array verdict, severity, message
     *
     * Verdicts:
     *   aligned            — nothing to do
     *   login_without_job  — the account works, the employment record says it should not
     *   job_without_login  — employed, but cannot sign in (normal for pre-start and suspension)
     */
    public static function reconcile($profileStatus, $crmActive)
    {
        $s = strtolower(trim((string) $profileStatus));
        $a = (int) $crmActive === 1;

        /* states in which a person is genuinely working */
        $working = array('active', 'approved');
        /* states in which they are not, and must not hold a live login */
        $gone    = array('exited', 'archived');

        if (in_array($s, $gone, true) && $a) {
            return array(
                'verdict'  => 'login_without_job',
                'severity' => 'critical',
                'message'  => 'The employment record says "' . $s . '" while the CRM account can still '
                            . 'sign in. Someone who has left must not keep a working login.',
            );
        }
        if (in_array($s, $working, true) && !$a) {
            return array(
                'verdict'  => 'job_without_login',
                'severity' => 'warning',
                'message'  => 'The employment record says "' . $s . '" while the CRM account is '
                            . 'deactivated. Either the person has left and the record is stale, or '
                            . 'their access was removed by mistake.',
            );
        }
        return array('verdict' => 'aligned', 'severity' => 'none', 'message' => '');
    }

    /**
     * Which headcount figure a screen is allowed to print.
     *
     * A tile labelled "Active" that counts employment records while the CRM
     * counts logins is how one system comes to report two different truths. Both
     * numbers are returned, each with the question it answers.
     */
    public static function headcount(array $rows)
    {
        $employed = 0; $signIn = 0; $mismatched = 0;
        foreach ($rows as $r) {
            $status = isset($r['status']) ? strtolower((string) $r['status']) : '';
            $active = isset($r['crm_active']) ? (int) $r['crm_active'] : 0;
            if (in_array($status, array('active', 'approved'), true)) { $employed++; }
            if ($active === 1) { $signIn++; }
            if (self::reconcile($status, $active)['verdict'] !== 'aligned') { $mismatched++; }
        }
        return array(
            'employed'   => $employed,
            'can_sign_in'=> $signIn,
            'mismatched' => $mismatched,
            'labels'     => array(
                'employed'    => 'Currently employed (workforce record)',
                'can_sign_in' => 'Can sign in (CRM account)',
                'mismatched'  => 'Records that disagree with the CRM',
            ),
        );
    }

    /**
     * The profile status a NEW staff member should start in.
     *
     * backfill used 'active' unconditionally, which is right for staff who were
     * already working when the module arrived and wrong for everybody after. A
     * person created today has not been classified, verified or approved, so
     * they start in draft with classification_required set — the same
     * fail-closed posture the module already takes on financial eligibility.
     */
    public static function initialStatusFor($crmActive)
    {
        return ((int) $crmActive === 1) ? 'draft' : 'suspended';
    }

    /**
     * Normalise whatever a Perfex hook hands us into a staff id.
     *
     * The hooks pass different shapes in different Perfex versions — an int, an
     * array, or the data array with the id inside it. Guessing one shape is how
     * a callback ends up running on every save and doing nothing.
     */
    public static function staffIdFrom($payload)
    {
        if (is_numeric($payload)) { return (int) $payload; }
        if (is_array($payload)) {
            foreach (array('staffid', 'staff_id', 'id') as $k) {
                if (isset($payload[$k]) && is_numeric($payload[$k])) { return (int) $payload[$k]; }
            }
        }
        if (is_object($payload)) {
            foreach (array('staffid', 'staff_id', 'id') as $k) {
                if (isset($payload->$k) && is_numeric($payload->$k)) { return (int) $payload->$k; }
            }
        }
        return 0;
    }

    /**
     * A status-change hook carries the new state as well as the id.
     * @return array staff_id, active (int|null when it could not be read)
     */
    public static function statusChangeFrom($payload)
    {
        $id = self::staffIdFrom($payload);
        $active = null;
        foreach (array('status', 'active') as $k) {
            if (is_array($payload) && isset($payload[$k]) && is_numeric($payload[$k])) { $active = (int) $payload[$k]; break; }
            if (is_object($payload) && isset($payload->$k) && is_numeric($payload->$k)) { $active = (int) $payload->$k; break; }
        }
        return array('staff_id' => $id, 'active' => $active);
    }
}
