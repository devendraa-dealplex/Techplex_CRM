<?php

defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_tombstone.php';

/**
 * Leadfinder_retention_policy
 *
 * The two retention periods, and the rules that keep them coherent.
 *
 * THE APPROVED POLICY, IN ONE PLACE
 * ---------------------------------
 *   - Raw waste contact details are purged 7 days after the decision.
 *   - The HMAC suppression tombstone is retained for 365 days.
 *   - After 365 days ordinary WASTE suppression may expire.
 *   - Do Not Contact never expires through this policy and is never deleted
 *     by it. Correcting a DNC record is a separate, authorised
 *     administrative/legal revocation — not something the retention sweep,
 *     an undo button, or a tidy-up job can do.
 *   - Audit metadata proving WHO marked a record and WHY is never deleted by
 *     the purge.
 *
 * WHY THE TWO PERIODS ARE ORDERED, NOT INDEPENDENT
 * ------------------------------------------------
 * PII retention must be shorter than or equal to suppression. The reverse
 * ordering is incoherent: it would hold a rejected business's phone number
 * after the suppression that justified keeping any record of them had already
 * expired. That is not a preference — it is the difference between "we keep a
 * key so we don't call them again" and "we kept their number".
 *
 * WHY BLANK IS NOT ZERO AND NOT A DEFAULT
 * ---------------------------------------
 * A blank period does not mean "purge immediately" and does not mean "never
 * purge quietly". It means the setting has not been made, which is a
 * misconfiguration: the purge refuses to run AND an administrator is alerted.
 * The failure a blank value must never produce is the silent one — a purge
 * that reports a clean run while destroying nothing, or destroying everything.
 *
 * Pure. No database, no clock of its own, no I/O.
 */
class Leadfinder_retention_policy
{
    /* The two settings this class governs. */
    const K_PII         = 'waste_pii_retention_days';
    const K_SUPPRESSION = 'waste_suppression_days';

    /*
     * Bounds.
     *
     * The maxima are not arbitrary decoration. An operator who types an extra
     * digit — 70 instead of 7, 3650 instead of 365 — has made a data-protection
     * decision by accident, and the value looks entirely plausible in a config
     * row. Refusing outside the range turns that into a message rather than a
     * year of unnoticed retention.
     */
    const PII_MIN_DAYS         = 1;
    const PII_MAX_DAYS         = 365;
    const SUPPRESSION_MIN_DAYS = 1;
    const SUPPRESSION_MAX_DAYS = 3650;   // ten years

    /**
     * The settings this class governs, with their bounds and what they mean.
     *
     * @return array
     */
    public static function settings()
    {
        return array(
            self::K_PII => array(
                'label'   => 'Waste contact-detail retention (days)',
                'min'     => self::PII_MIN_DAYS,
                'max'     => self::PII_MAX_DAYS,
                'approved' => 7,
                'means'   => 'How long the raw contact details of a rejected prospect are kept '
                           . 'before the purge clears them. The suppression tombstone is written '
                           . 'first and survives; only the contact details go.',
            ),
            self::K_SUPPRESSION => array(
                'label'   => 'Waste suppression period (days)',
                'min'     => self::SUPPRESSION_MIN_DAYS,
                'max'     => self::SUPPRESSION_MAX_DAYS,
                'approved' => 365,
                'means'   => 'How long a waste tombstone keeps suppressing a re-imported '
                           . 'business. Applies to ORDINARY WASTE ONLY. Do Not Contact does '
                           . 'not expire and is not governed by this value.',
            ),
        );
    }

    /**
     * Validate one retention value.
     *
     * Fails closed on everything it does not positively recognise as a whole
     * number inside the range. `'7 days'`, `'007'`, `7.5`, `'1e3'`, `true` and
     * an array are all refused rather than coerced — PHP would happily turn
     * most of them into an integer, and a retention period arrived at by
     * coercion is one nobody decided.
     *
     * @param  string $key
     * @param  mixed  $value
     * @return array {ok, value, error, reason}
     */
    public static function validate($key, $value)
    {
        $s = self::settings();

        if (!isset($s[$key])) {
            return self::bad('unknown_setting', 'That is not a retention setting this module governs.');
        }

        if (is_array($value) || is_bool($value) || $value === null) {
            return self::bad('not_a_number', 'Give a whole number of days.');
        }

        $raw = trim((string) $value);

        if ($raw === '') {
            return self::bad('blank',
                'A retention period is required. Leaving it blank does not disable the purge '
                . 'quietly — it stops the purge running at all and raises an administrator alert.');
        }

        /* A whole number, written plainly. No sign, no decimal point, no
           exponent, no leading zeros to be read as octal by something later. */
        if (!preg_match('/^[1-9][0-9]{0,4}$/', $raw)) {
            return self::bad('not_a_whole_number',
                'Give a whole number of days, with no sign, decimal point or spaces. '
                . 'Zero and negative values are not accepted.');
        }

        $n   = (int) $raw;
        $min = (int) $s[$key]['min'];
        $max = (int) $s[$key]['max'];

        if ($n < $min) {
            return self::bad('below_minimum', 'The minimum is ' . $min . ' day(s).');
        }

        if ($n > $max) {
            return self::bad('above_maximum',
                'The maximum is ' . $max . ' days. A longer period is a data-protection decision '
                . 'that should not be made by typing an extra digit.');
        }

        return array('ok' => true, 'value' => $n, 'error' => null, 'reason' => 'valid');
    }

    /**
     * Validate the pair together.
     *
     * Individually valid values can still be an incoherent pair. See the class
     * docblock: PII retention longer than suppression means holding contact
     * details after the reason for holding anything has expired.
     *
     * @param  mixed $pii
     * @param  mixed $suppression
     * @return array {ok, pii, suppression, error, reason}
     */
    public static function validatePair($pii, $suppression)
    {
        $a = self::validate(self::K_PII, $pii);

        if (empty($a['ok'])) {
            return array('ok' => false, 'pii' => null, 'suppression' => null,
                         'error' => $a['error'], 'reason' => 'pii:' . $a['reason']);
        }

        $b = self::validate(self::K_SUPPRESSION, $suppression);

        if (empty($b['ok'])) {
            return array('ok' => false, 'pii' => null, 'suppression' => null,
                         'error' => $b['error'], 'reason' => 'suppression:' . $b['reason']);
        }

        if ($a['value'] > $b['value']) {
            return array('ok' => false, 'pii' => null, 'suppression' => null,
                'reason' => 'pii_outlives_suppression',
                'error' => 'Contact details cannot be kept longer than the suppression they belong to. '
                         . 'The retention period (' . $a['value'] . ' days) must be less than or equal '
                         . 'to the suppression period (' . $b['value'] . ' days).');
        }

        return array('ok' => true, 'pii' => $a['value'], 'suppression' => $b['value'],
                     'error' => null, 'reason' => 'valid');
    }

    /**
     * Resolve the configured values into an operating decision.
     *
     * This is what the purge asks before it does anything. It never invents a
     * period, never falls back to a default, and says which of the two is
     * wrong when one of them is.
     *
     * @param  mixed $piiRaw
     * @param  mixed $suppressionRaw
     * @return array {purge_allowed, pii_days, suppression_days, reason, alert}
     */
    public static function resolve($piiRaw, $suppressionRaw)
    {
        $v = self::validatePair($piiRaw, $suppressionRaw);

        if (!empty($v['ok'])) {
            return array(
                'purge_allowed'    => true,
                'pii_days'         => $v['pii'],
                'suppression_days' => $v['suppression'],
                'reason'           => 'configured',
                'alert'            => null,
            );
        }

        /*
         * Refused, loudly. The alert carries the machine reason and the
         * operator-facing sentence; it carries no value, because a
         * misconfigured retention period is not a secret but the alert travels
         * to places a configuration row does not.
         */
        return array(
            'purge_allowed'    => false,
            'pii_days'         => null,
            'suppression_days' => null,
            'reason'           => $v['reason'],
            'alert'            => array(
                'kind'    => 'leadfinder_retention_not_configured',
                'reason'  => $v['reason'],
                'message' => 'The Lead Finder contact-detail purge is not running: '
                           . $v['error'] . ' Rejected prospects are keeping their contact details '
                           . 'until an administrator sets a valid period.',
            ),
        );
    }

    /* ---- what the policy does NOT govern ------------------------------- */

    /**
     * Does the suppression period apply to this kind of suppression?
     *
     * Waste: yes. DNC: never. Stated as a method so the answer is one place
     * and so a test can assert it, rather than being a condition repeated at
     * every call site until one copy is written the other way round.
     *
     * @param  string $kind
     * @return bool
     */
    public static function expiryApplies($kind)
    {
        return $kind === Leadfinder_tombstone::S_WASTE;
    }

    /**
     * How a DNC record may be corrected, as data rather than as prose.
     *
     * Deliberately NOT an action this module performs. A DNC record says a
     * business asked not to be contacted; withdrawing that is an authorised
     * administrative or legal act with its own evidence, and it does not belong
     * behind an undo button, a retention sweep, or a bulk tidy-up.
     *
     * @return array
     */
    public static function dncRevocation()
    {
        return array(
            'expires'              => false,
            'deleted_by_sweep'     => false,
            'undoable_in_ui'       => false,
            'requires'             => 'separate authorised administrative or legal revocation workflow',
            'evidence_required'    => array('who authorised it', 'on what basis', 'when', 'reference'),
            'implemented_here'     => false,
        );
    }

    /**
     * Audit metadata the purge must never destroy.
     *
     * The purge clears contact details. It does not clear the record of who
     * made the decision, when, and for what stated reason — that is the
     * evidence the whole workflow exists to leave behind, and a purge that took
     * it would make every waste decision unattributable a week later.
     *
     * @return array
     */
    public static function auditFieldsPreserved()
    {
        return array(
            'waste_reason',       // the stated reason code
            'waste_source',       // manual or automatic
            'waste_rule',         // which deterministic rule, when automatic
            'wasted_at',
            'wasted_by',
            'undone_at',
            'undone_by',
            'suppression_kind',
            'decided_at',         // on the tombstone
            'decided_by',
        );
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function bad($reason, $error)
    {
        return array('ok' => false, 'value' => null, 'error' => $error, 'reason' => $reason);
    }
}
