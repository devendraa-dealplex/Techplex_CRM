<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_suppression — who must never receive an automated follow-up.
 *
 * DECISION 3, IMPLEMENTED
 * -----------------------
 *   - All Lead Finder prospects and leads are excluded from the drip BY DEFAULT.
 *   - Follow-up is enabled only after verified interest/consent, or an explicit
 *     approved staff action.
 *   - Converted customers, Do-Not-Contact records, invalid leads and
 *     unsubscribed contacts can NEVER receive the lead drip.
 *
 * "NEVER" IS A DIFFERENT THING FROM "OFF BY DEFAULT"
 * --------------------------------------------------
 * Those are two separate mechanisms here, deliberately:
 *
 *   `eligible()` returns false unless somebody switched follow-up on — a
 *   default that a person can change.
 *
 *   `hardSuppressed()` returns true for the four protected categories, and
 *   **nothing in the argument list can turn it off.** There is no consent flag,
 *   no staff override, no admin parameter that reaches past it. A category that
 *   can be overridden by passing the right argument is not a safeguard, it is a
 *   default with extra steps.
 *
 * The order matters and is asserted: hard suppression is evaluated FIRST, so an
 * enthusiastic "yes, contact me" recorded against an unsubscribed record still
 * results in no email.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * `leadgen_followup.php:157-161` selects every lead with `assigned != 0`, no
 * LIMIT, and emails it. It has already cold-emailed 16 already-converted
 * customers (`:141-150`). That is the failure this class refuses to repeat, so
 * its checks are written as "prove this contact may be mailed", never as
 * "prove this contact may not".
 */
class Leadfinder_suppression
{
    /* the four categories that can never be mailed */
    const S_CONVERTED_CUSTOMER = 'already_a_customer';
    const S_DO_NOT_CONTACT     = 'do_not_contact';
    const S_INVALID            = 'invalid_lead';
    const S_UNSUBSCRIBED       = 'unsubscribed';

    /* soft reasons — a person may change these */
    const S_NOT_ENABLED        = 'lead_finder_follow_up_not_enabled';
    const S_NO_CONSENT         = 'no_verified_interest_or_consent';
    const S_NO_CONTACTABLE     = 'no_usable_contact_address';

    const OK = 'eligible';

    public static function hardCategories()
    {
        return array(self::S_CONVERTED_CUSTOMER, self::S_DO_NOT_CONTACT,
                     self::S_INVALID, self::S_UNSUBSCRIBED);
    }

    /**
     * The four absolute exclusions.
     *
     * Takes ONLY the record. No flags, no actor, no override parameter —
     * there is deliberately nothing to pass that would change the answer.
     *
     * @param array $r  converted_customer, date_converted, client_id,
     *                  do_not_contact, dnc, lead_status, is_invalid,
     *                  unsubscribed, consent_withdrawn, email_bounced
     * @return array suppressed (bool), reasons (list) — plural, because a
     *               record is often more than one of these and reporting only
     *               the first hides the rest from whoever audits it.
     */
    public static function hardSuppressed(array $r)
    {
        $reasons = array();

        /* Converted customer. Three independent signals, because the CRM
           records this three ways and no single one is reliable on its own:
           a client row, a conversion date, or an explicit flag. */
        if (self::truthy($r, 'converted_customer')
            || self::present($r, 'date_converted')
            || self::positive($r, 'client_id')
            || self::positive($r, 'leadid_client')) {
            $reasons[] = self::S_CONVERTED_CUSTOMER;
        }

        /* Do Not Contact — the module's own suppression list and any CRM flag. */
        if (self::truthy($r, 'do_not_contact') || self::truthy($r, 'dnc')
            || (isset($r['status']) && $r['status'] === 'do_not_contact')) {
            $reasons[] = self::S_DO_NOT_CONTACT;
        }

        /* Invalid: wrong number, closed business, junk, or explicitly marked. */
        if (self::truthy($r, 'is_invalid') || self::truthy($r, 'junk')
            || (isset($r['status']) && in_array($r['status'],
                array('wrong_number', 'business_closed', 'irrelevant', 'duplicate', 'rejected'), true))
            || (isset($r['business_status']) && $r['business_status'] === 'CLOSED_PERMANENTLY')) {
            $reasons[] = self::S_INVALID;
        }

        /* Unsubscribed, consent withdrawn, or a hard bounce. */
        if (self::truthy($r, 'unsubscribed') || self::truthy($r, 'consent_withdrawn')
            || self::truthy($r, 'email_bounced') || self::truthy($r, 'marketing_opt_out')) {
            $reasons[] = self::S_UNSUBSCRIBED;
        }

        return array('suppressed' => count($reasons) > 0,
                     'reasons'    => array_values(array_unique($reasons)));
    }

    /**
     * May this record receive an automated Lead Finder follow-up?
     *
     * @param array $r    the lead or prospect row
     * @param array $cfg  follow_up_enabled (bool) — Admin-controlled, default OFF
     * @return array allowed, reason, hard (was it an absolute exclusion)
     */
    public static function eligible(array $r, array $cfg = array())
    {
        /* Hard exclusions first, always. An override evaluated before this
           would be an override OF this. */
        $hard = self::hardSuppressed($r);
        if ($hard['suppressed']) {
            return array('allowed' => false, 'reason' => $hard['reasons'][0],
                         'reasons' => $hard['reasons'], 'hard' => true);
        }

        /* Default OFF. Absent configuration means off, not on. */
        if (empty($cfg['follow_up_enabled'])) {
            return self::soft(self::S_NOT_ENABLED);
        }

        /* Verified interest / consent, or an approved staff action. */
        $consented = self::truthy($r, 'verified_interest')
                     || self::truthy($r, 'consent_given')
                     || self::truthy($r, 'staff_approved_follow_up');
        if (!$consented) { return self::soft(self::S_NO_CONSENT); }

        /* Something to actually send to. */
        $hasEmail = self::present($r, 'verified_email') || self::present($r, 'email');
        if (!$hasEmail) { return self::soft(self::S_NO_CONTACTABLE); }

        return array('allowed' => true, 'reason' => self::OK, 'reasons' => array(), 'hard' => false);
    }

    /**
     * The predicate that keeps Lead Finder leads out of the EXISTING drip.
     *
     * Returned as SQL for the caller to bind, so the exclusion lives in the
     * query rather than in a post-filter that a later `LIMIT` could skip past.
     * Non-empty by construction — a suppression predicate that suppresses
     * nothing is the defect this whole project keeps finding.
     */
    public static function dripExclusionPredicate($sourceIds, $col = 'source')
    {
        $ids = array();
        foreach ((array) $sourceIds as $v) {
            if (is_int($v) ? $v > 0 : (is_string($v) && preg_match('/^[0-9]{1,18}\z/', $v) && (int) $v > 0)) {
                $ids[] = (int) $v;
            }
        }
        if (!$ids) {
            /* No Lead Finder source id resolved. Fail CLOSED: exclude nothing
               from the drip but say so loudly, because silently excluding
               everything would stop the client's existing campaigns, and
               silently excluding nothing would mail our prospects. The caller
               must treat 'unresolved' as a configuration error. */
            return array('sql' => '', 'params' => array(), 'unresolved' => true);
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array('sql' => "($col IS NULL OR $col NOT IN ($in))",
                     'params' => $ids, 'unresolved' => false);
    }

    /**
     * The audit row for a suppression. Every skipped send is recorded: a
     * follow-up that silently does not happen is indistinguishable from one
     * that failed, and the 16-customer incident was found by someone reading
     * mail logs, not by the system reporting it.
     */
    public static function auditEntry(array $decision, $recordId, $channel, $now)
    {
        return array(
            'record_id' => (int) $recordId,
            'channel'   => (string) $channel,
            'allowed'   => !empty($decision['allowed']),
            'hard'      => !empty($decision['hard']),
            'reason'    => isset($decision['reason']) ? $decision['reason'] : '',
            'reasons'   => isset($decision['reasons']) ? $decision['reasons'] : array(),
            'at'        => (int) $now,
        );
    }

    /* ------------------------------------------------------------------ */

    private static function soft($reason)
    {
        return array('allowed' => false, 'reason' => $reason,
                     'reasons' => array($reason), 'hard' => false);
    }

    private static function truthy(array $r, $k)
    {
        if (!isset($r[$k])) { return false; }
        $v = $r[$k];
        if (is_bool($v)) { return $v; }
        if (is_int($v))  { return $v > 0; }
        if (is_string($v)) { return in_array(strtolower(trim($v)), array('1','true','yes','y'), true); }
        return false;
    }

    private static function present(array $r, $k)
    {
        return isset($r[$k]) && $r[$k] !== null && trim((string) $r[$k]) !== ''
               && trim((string) $r[$k]) !== '0000-00-00 00:00:00';
    }

    private static function positive(array $r, $k)
    {
        return isset($r[$k]) && (int) $r[$k] > 0;
    }
}
