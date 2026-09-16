<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leadfinder_waste
 *
 * The waste workflow: why a prospect was discarded, what that closes off, and
 * how long the decision can be undone.
 *
 * Pure. No database, no session, no clock of its own — every time value arrives
 * as a parameter so the boundaries can be tested instead of hoped for.
 *
 * THE ONE DISTINCTION THAT MATTERS HERE
 *
 * Do-not-contact is NOT a waste reason and is not in this class's vocabulary.
 * Waste means "this result was not worth working". DNC means "this business has
 * told us not to contact them", which is a promise we keep even after the
 * record is otherwise gone. They are separate actions with separate retention:
 * see Leadfinder_tombstone, where a waste tombstone can expire and a DNC
 * suppression key cannot.
 *
 * Collapsing the two is the obvious shortcut and it is the one that eventually
 * calls a business that asked not to be called.
 */
class Leadfinder_waste
{
    /* ---- reasons ------------------------------------------------------- */

    const R_IRRELEVANT        = 'irrelevant_business';
    const R_WRONG_CATEGORY    = 'wrong_category';
    const R_CLOSED            = 'closed_business';
    const R_INVALID_CONTACT   = 'invalid_contact';
    const R_DUPLICATE         = 'duplicate';
    const R_OUTSIDE_TERRITORY = 'outside_territory';
    const R_NOT_INTERESTED    = 'not_interested';
    const R_FAKE_SPAM         = 'fake_spam';
    const R_OTHER             = 'other';

    /** Notes are mandatory for this reason and optional for the rest. */
    const NOTES_REQUIRED_FOR = self::R_OTHER;

    /** Free-text notes bounds. Short enough to stay a reason, not a dossier. */
    const NOTES_MIN = 4;
    const NOTES_MAX = 500;

    /* ---- undo window --------------------------------------------------- */

    /**
     * Undo exists so a misclick is recoverable, not so a decision stays open.
     *
     * The window is administrator-configurable, but it is clamped here. An
     * unbounded window would quietly defeat the purge schedule: a record that
     * can always come back is a record whose PII can never be cleared.
     */
    const UNDO_DEFAULT_SECONDS = 900;      // 15 minutes
    const UNDO_MIN_SECONDS     = 60;       // 1 minute
    const UNDO_MAX_SECONDS     = 86400;    // 24 hours, hard ceiling

    /* ---- what waste closes off ----------------------------------------- */

    /**
     * Actions that a wasted prospect must refuse, whatever the UI shows.
     * Checked server-side; a hidden button is not an enforcement mechanism.
     */
    public static function blockedActions()
    {
        return array(
            'convert',          // never reaches tblleads
            'submit_conversion',
            'approve_conversion',
            'call',
            'email',
            'sms',
            'whatsapp',
            'schedule_callback',
            'drip_enrol',
        );
    }

    /**
     * Every reason, with the label and the prospect status it settles into.
     *
     * The statuses are the ones Leadfinder_status already defines. Waste does
     * not invent a parallel state machine — it selects a terminal state in the
     * existing one, so nothing downstream has to learn a second vocabulary.
     */
    public static function reasons()
    {
        return array(
            self::R_IRRELEVANT => array(
                'label'  => 'Irrelevant business',
                'status' => 'irrelevant',
                'notes'  => 'optional',
            ),
            self::R_WRONG_CATEGORY => array(
                'label'  => 'Wrong category',
                'status' => 'irrelevant',
                'notes'  => 'optional',
            ),
            self::R_CLOSED => array(
                'label'  => 'Closed business',
                'status' => 'business_closed',
                'notes'  => 'optional',
            ),
            self::R_INVALID_CONTACT => array(
                'label'  => 'Invalid contact',
                'status' => 'wrong_number',
                'notes'  => 'optional',
            ),
            self::R_DUPLICATE => array(
                'label'  => 'Duplicate',
                'status' => 'duplicate',
                'notes'  => 'optional',
            ),
            self::R_OUTSIDE_TERRITORY => array(
                'label'  => 'Outside territory',
                'status' => 'rejected',
                'notes'  => 'optional',
            ),
            self::R_NOT_INTERESTED => array(
                'label'  => 'Not interested',
                'status' => 'valid_not_interested',
                'notes'  => 'optional',
            ),
            self::R_FAKE_SPAM => array(
                'label'  => 'Fake or spam',
                'status' => 'rejected',
                'notes'  => 'optional',
            ),
            self::R_OTHER => array(
                'label'  => 'Other',
                'status' => 'rejected',
                'notes'  => 'required',
            ),
        );
    }

    /**
     * @return array reason keys
     */
    public static function reasonKeys()
    {
        return array_keys(self::reasons());
    }

    /**
     * Is this a reason this class knows?
     *
     * @param  string $reason
     * @return bool
     */
    public static function isReason($reason)
    {
        return is_string($reason) && array_key_exists($reason, self::reasons());
    }

    /**
     * The prospect status a reason settles into.
     *
     * @param  string $reason
     * @return string|null
     */
    public static function statusFor($reason)
    {
        $r = self::reasons();

        return isset($r[$reason]) ? $r[$reason]['status'] : null;
    }

    /**
     * Validate a waste submission.
     *
     * Fails closed: an unknown reason, a missing reason, or missing notes where
     * notes are required all refuse. There is no default reason, because
     * "marked waste, reason unknown" is the state this whole workflow exists to
     * prevent.
     *
     * @param  string $reason
     * @param  string $notes
     * @return array {ok: bool, error: string|null}
     */
    public static function validate($reason, $notes = '')
    {
        if (!is_string($reason) || $reason === '') {
            return self::err('A reason is required.');
        }

        if (!self::isReason($reason)) {
            return self::err('Unknown reason.');
        }

        $notes = is_string($notes) ? trim($notes) : '';

        if (self::notesRequired($reason)) {
            if ($notes === '') {
                return self::err('Notes are required when the reason is "Other".');
            }
            if (self::mbLen($notes) < self::NOTES_MIN) {
                return self::err('Please say a little more — at least ' . self::NOTES_MIN . ' characters.');
            }
        }

        if ($notes !== '' && self::mbLen($notes) > self::NOTES_MAX) {
            return self::err('Notes are limited to ' . self::NOTES_MAX . ' characters.');
        }

        return array('ok' => true, 'error' => null);
    }

    /**
     * @param  string $reason
     * @return bool
     */
    public static function notesRequired($reason)
    {
        $r = self::reasons();

        return isset($r[$reason]) && $r[$reason]['notes'] === 'required';
    }

    /* ---- undo ---------------------------------------------------------- */

    /**
     * Clamp a configured undo window into the allowed range.
     *
     * A non-numeric or absent setting falls back to the default rather than to
     * zero: silently removing undo because a config row was malformed would
     * make a misclick unrecoverable without telling anyone.
     *
     * @param  mixed $configured
     * @return int seconds
     */
    public static function undoWindowSeconds($configured = null)
    {
        if ($configured === null || $configured === '' || !is_numeric($configured)) {
            return self::UNDO_DEFAULT_SECONDS;
        }

        $v = (int) $configured;

        if ($v < self::UNDO_MIN_SECONDS) {
            return self::UNDO_MIN_SECONDS;
        }

        if ($v > self::UNDO_MAX_SECONDS) {
            return self::UNDO_MAX_SECONDS;
        }

        return $v;
    }

    /**
     * May this waste decision still be undone?
     *
     * @param  int   $wastedAtTs   unix seconds when the waste was recorded
     * @param  int   $nowTs
     * @param  mixed $configured   configured window, clamped
     * @param  bool  $purged       has the purge already run for this record?
     * @return array {allowed: bool, reason: string, seconds_left: int}
     */
    public static function mayUndo($wastedAtTs, $nowTs, $configured = null, $purged = false)
    {
        if (!is_numeric($wastedAtTs) || !is_numeric($nowTs)) {
            return self::undoNo('unknown_timing', 0);
        }

        // Once the PII is gone there is nothing to restore. Offering undo here
        // would hand back a hollow record and call it a recovery.
        if ($purged) {
            return self::undoNo('already_purged', 0);
        }

        $window = self::undoWindowSeconds($configured);
        $left   = ((int) $wastedAtTs + $window) - (int) $nowTs;

        if ($left <= 0) {
            return self::undoNo('window_expired', 0);
        }

        return array('allowed' => true, 'reason' => 'within_window', 'seconds_left' => $left);
    }

    /**
     * Who may undo: the staff member who wasted it, or an administrator.
     *
     * @param  int  $actorId
     * @param  int  $wastedBy
     * @param  bool $isAdmin
     * @return bool
     */
    public static function undoActorAllowed($actorId, $wastedBy, $isAdmin = false)
    {
        if ($isAdmin) {
            return true;
        }

        return (int) $actorId > 0 && (int) $actorId === (int) $wastedBy;
    }

    /* ---- side effects the caller must perform -------------------------- */

    /**
     * What marking waste has to do, as data rather than as prose in a
     * controller. The model applies these; the test asserts the list.
     *
     * @return array
     */
    public static function sideEffects()
    {
        return array(
            'remove_from_active_queue',   // gone from the employee's list and the map
            'cancel_open_claim',
            'cancel_scheduled_callback',
            'block_conversion',
            'block_outbound_actions',
            'write_audit_event',
            'schedule_purge',
        );
    }

    /**
     * The audit event for a waste decision, with no contact detail in it.
     *
     * The reason and the notes are recorded; the phone, email and address are
     * not, because the audit row outlives the purge and would otherwise become
     * the copy of the PII that nobody remembered to clear.
     *
     * @param  array $in
     * @return array
     */
    public static function auditEvent(array $in)
    {
        return array(
            'event'        => 'prospect_marked_waste',
            'prospect_id'  => isset($in['prospect_id']) ? (int) $in['prospect_id'] : 0,
            'actor_id'     => isset($in['actor_id']) ? (int) $in['actor_id'] : 0,
            'reason'       => isset($in['reason']) ? (string) $in['reason'] : '',
            'status'       => self::statusFor(isset($in['reason']) ? $in['reason'] : ''),
            'notes'        => isset($in['notes']) ? self::truncate((string) $in['notes'], self::NOTES_MAX) : '',
            'undo_until'   => isset($in['undo_until']) ? (int) $in['undo_until'] : 0,
            'at'           => isset($in['at']) ? (string) $in['at'] : '',
        );
    }

    /**
     * Fields that must never appear in a waste audit event.
     * Asserted by the test suite against auditEvent()'s output keys.
     *
     * @return array
     */
    public static function forbiddenAuditFields()
    {
        return array('phone', 'phone_raw', 'email', 'email_raw', 'address',
                     'formatted_address', 'lat', 'lng', 'api_payload', 'website');
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function err($msg)
    {
        return array('ok' => false, 'error' => $msg);
    }

    private static function undoNo($reason, $left)
    {
        return array('allowed' => false, 'reason' => $reason, 'seconds_left' => (int) $left);
    }

    private static function mbLen($s)
    {
        return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
    }

    private static function truncate($s, $max)
    {
        if (self::mbLen($s) <= $max) {
            return $s;
        }

        return function_exists('mb_substr') ? mb_substr($s, 0, $max, 'UTF-8') : substr($s, 0, $max);
    }
}
