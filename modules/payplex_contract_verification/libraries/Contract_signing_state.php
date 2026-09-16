<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_signing_state
 *
 * The internal state machine for a signing request, and the rules that stop a
 * late or hostile message undoing a completed execution.
 *
 * WHY THE VOCABULARY IS OURS AND NOT THE PROVIDER'S
 * -------------------------------------------------
 * Every provider names its events differently and adds new ones without asking.
 * If contract status were the provider's enum, then the day they add an event
 * this code has never seen, a contract moves into a state nothing downstream
 * knows how to reason about — and the mapping is scattered across every file
 * that touched a webhook.
 *
 * So provider events map INTO this vocabulary at exactly one place, and an
 * event that maps to nothing is recorded and changes no state at all.
 *
 * THE RULE THAT MATTERS MOST
 * --------------------------
 * A completed execution is never downgraded. Webhook delivery is at-least-once
 * and out-of-order in every system that has one: a `viewed` event emitted
 * before a `completed` event can easily arrive after it. Applying it naively
 * turns a fully executed agreement back into a half-signed one, and the audit
 * trail then says the contract un-signed itself.
 *
 * `canTransition()` is therefore not a formality. It is the thing standing
 * between an out-of-order packet and a legal record.
 *
 * Pure: no database, no clock of its own, no I/O.
 */
class Contract_signing_state
{
    /* ---- the eleven internal states ------------------------------------ */

    const S_CREATED                = 'created';
    const S_INVITATION_SENT        = 'invitation_sent';
    const S_VIEWED                 = 'viewed';
    const S_AUTHENTICATION_PENDING = 'authentication_pending';
    const S_SIGNED_BY_PARTY        = 'signed_by_party';
    const S_PARTIALLY_SIGNED       = 'partially_signed';
    const S_COMPLETED              = 'completed';
    const S_DECLINED               = 'declined';
    const S_EXPIRED                = 'expired';
    const S_CANCELLED              = 'cancelled';
    const S_FAILED                 = 'failed';

    /**
     * Every state, in workflow order, with what it means and how it behaves.
     *
     * `terminal` states accept no further transition except through the
     * authorised-review path. `rank` orders progress so a lower-ranked event
     * arriving late can be recognised as stale rather than applied.
     *
     * @return array
     */
    public static function states()
    {
        return array(
            self::S_CREATED => array(
                'label' => 'Created', 'rank' => 10, 'terminal' => false,
                'means' => 'The signing request exists at the provider and the CRM has stored its reference.',
            ),
            self::S_INVITATION_SENT => array(
                'label' => 'Invitation sent', 'rank' => 20, 'terminal' => false,
                'means' => 'A signing link has been issued to at least one signer.',
            ),
            self::S_VIEWED => array(
                'label' => 'Viewed', 'rank' => 30, 'terminal' => false,
                'means' => 'A signer opened the document. Not consent, not a signature.',
            ),
            self::S_AUTHENTICATION_PENDING => array(
                'label' => 'Authentication pending', 'rank' => 40, 'terminal' => false,
                'means' => 'A signer began an identity step and has not finished it.',
            ),
            self::S_SIGNED_BY_PARTY => array(
                'label' => 'Signed by a party', 'rank' => 50, 'terminal' => false,
                'means' => 'One signer completed. Says nothing about the others.',
            ),
            self::S_PARTIALLY_SIGNED => array(
                'label' => 'Partially signed', 'rank' => 60, 'terminal' => false,
                'means' => 'At least one mandatory signer has completed and at least one has not.',
            ),
            self::S_COMPLETED => array(
                'label' => 'Completed', 'rank' => 100, 'terminal' => true,
                'means' => 'Every mandatory signer completed. NOT the same as "fully executed" — '
                         . 'see Contract_evidence, which additionally requires the signed document '
                         . 'and the completion certificate to be downloaded and hash-verified.',
            ),
            self::S_DECLINED => array(
                'label' => 'Declined', 'rank' => 100, 'terminal' => true,
                'means' => 'A signer refused. The request is over.',
            ),
            self::S_EXPIRED => array(
                'label' => 'Expired', 'rank' => 100, 'terminal' => true,
                'means' => 'The signing window closed before completion.',
            ),
            self::S_CANCELLED => array(
                'label' => 'Cancelled', 'rank' => 100, 'terminal' => true,
                'means' => 'Withdrawn by an authorised member of staff, with a stated reason.',
            ),
            self::S_FAILED => array(
                'label' => 'Failed', 'rank' => 100, 'terminal' => true,
                'means' => 'The request could not be created or progressed. See Contract_failures.',
            ),
        );
    }

    /**
     * @return array state keys
     */
    public static function all()
    {
        return array_keys(self::states());
    }

    /**
     * @param  string $state
     * @return bool
     */
    public static function isState($state)
    {
        return is_string($state) && array_key_exists($state, self::states());
    }

    /**
     * @param  string $state
     * @return bool
     */
    public static function isTerminal($state)
    {
        $s = self::states();

        return isset($s[$state]) ? (bool) $s[$state]['terminal'] : false;
    }

    /**
     * @param  string $state
     * @return int
     */
    public static function rank($state)
    {
        $s = self::states();

        return isset($s[$state]) ? (int) $s[$state]['rank'] : -1;
    }

    /**
     * Which states may follow which.
     *
     * Deliberately written out rather than derived from the ranks. Progress is
     * not simply "rank must increase": a request may be cancelled from almost
     * anywhere, and `signed_by_party` may repeat as each signer completes. A
     * derived rule would be shorter and would quietly permit transitions nobody
     * intended.
     *
     * @return array
     */
    public static function transitions()
    {
        $abandon = array(self::S_DECLINED, self::S_EXPIRED, self::S_CANCELLED, self::S_FAILED);

        return array(
            self::S_CREATED => array_merge(
                array(self::S_INVITATION_SENT, self::S_VIEWED, self::S_AUTHENTICATION_PENDING,
                      self::S_SIGNED_BY_PARTY, self::S_PARTIALLY_SIGNED, self::S_COMPLETED), $abandon),

            self::S_INVITATION_SENT => array_merge(
                array(self::S_VIEWED, self::S_AUTHENTICATION_PENDING, self::S_SIGNED_BY_PARTY,
                      self::S_PARTIALLY_SIGNED, self::S_COMPLETED), $abandon),

            self::S_VIEWED => array_merge(
                array(self::S_AUTHENTICATION_PENDING, self::S_SIGNED_BY_PARTY,
                      self::S_PARTIALLY_SIGNED, self::S_COMPLETED), $abandon),

            self::S_AUTHENTICATION_PENDING => array_merge(
                array(self::S_VIEWED, self::S_SIGNED_BY_PARTY, self::S_PARTIALLY_SIGNED,
                      self::S_COMPLETED), $abandon),

            /* A second signer completing is another signed_by_party. */
            self::S_SIGNED_BY_PARTY => array_merge(
                array(self::S_SIGNED_BY_PARTY, self::S_PARTIALLY_SIGNED, self::S_COMPLETED), $abandon),

            self::S_PARTIALLY_SIGNED => array_merge(
                array(self::S_SIGNED_BY_PARTY, self::S_PARTIALLY_SIGNED, self::S_COMPLETED), $abandon),

            /* Terminal states go nowhere. Reopening one is an authorised review,
               not a transition — see reopen(). */
            self::S_COMPLETED => array(),
            self::S_DECLINED  => array(),
            self::S_EXPIRED   => array(),
            self::S_CANCELLED => array(),
            self::S_FAILED    => array(),
        );
    }

    /**
     * May the request move from $from to $to?
     *
     * Fails closed on anything it does not positively recognise, including an
     * unknown state name on either side — a typo in a mapping table must not
     * become a permitted transition.
     *
     * @param  string $from
     * @param  string $to
     * @return array {allowed: bool, reason: string}
     */
    public static function canTransition($from, $to)
    {
        if (!self::isState($from)) {
            return self::no('unknown_current_state');
        }

        if (!self::isState($to)) {
            return self::no('unknown_target_state');
        }

        if ($from === $to) {
            /* Not an error and not a change. Repeat deliveries are normal. */
            return self::no('no_change');
        }

        if (self::isTerminal($from)) {
            return self::no('current_state_is_terminal');
        }

        $t = self::transitions();

        if (!in_array($to, $t[$from], true)) {
            return self::no('transition_not_permitted');
        }

        return array('allowed' => true, 'reason' => 'permitted');
    }

    /**
     * Apply an observed state, defending against stale and out-of-order news.
     *
     * This is what every webhook and every reconciliation goes through. The
     * three refusals below are the ones that matter:
     *
     *   - a terminal state is never left by a message
     *   - a lower-ranked observation never overwrites a higher-ranked one
     *   - an unknown state changes nothing at all
     *
     * @param  string $current
     * @param  string $observed
     * @param  string $source  'webhook' | 'reconciliation' | 'internal'
     * @return array {apply: bool, state: string, reason: string, alert: bool}
     */
    public static function apply($current, $observed, $source = 'webhook')
    {
        if (!self::isState($observed)) {
            /* An event the provider invented after this code was written.
               Recorded by the caller; it moves nothing. */
            return self::keep($current, 'unknown_observed_state', false);
        }

        if (!self::isState($current)) {
            return self::keep($current, 'unknown_current_state', true);
        }

        if ($current === $observed) {
            return self::keep($current, 'already_in_this_state', false);
        }

        if (self::isTerminal($current)) {
            /*
             * The rule this class exists for. A completed execution stays
             * completed. The disagreement is surfaced for review rather than
             * resolved by whichever message arrived last.
             */
            return self::keep($current, 'refused_downgrade_from_terminal', true);
        }

        if (self::rank($observed) < self::rank($current)) {
            return self::keep($current, 'stale_lower_ranked_observation', false);
        }

        $can = self::canTransition($current, $observed);

        if (empty($can['allowed'])) {
            return self::keep($current, $can['reason'], true);
        }

        return array('apply' => true, 'state' => $observed,
                     'reason' => 'applied_from_' . $source, 'alert' => false);
    }

    /**
     * Leaving a terminal state requires an authorised human review.
     *
     * Not a transition, and deliberately not reachable from a webhook or the
     * reconciliation job — only from an administrator action that records who
     * decided it and why. Without this, "never downgrade" would mean "stuck
     * forever" the first time a provider genuinely corrects a mistake.
     *
     * @param  string $from
     * @param  string $to
     * @param  array  $review {actor_id, reason, authorised}
     * @return array {allowed: bool, reason: string}
     */
    public static function reopen($from, $to, array $review)
    {
        if (!self::isTerminal($from)) {
            return self::no('not_a_terminal_state');
        }

        if (!self::isState($to)) {
            return self::no('unknown_target_state');
        }

        if (empty($review['authorised'])) {
            return self::no('not_authorised');
        }

        if ((int) (isset($review['actor_id']) ? $review['actor_id'] : 0) <= 0) {
            return self::no('no_actor_recorded');
        }

        $reason = isset($review['reason']) ? trim((string) $review['reason']) : '';

        if (strlen($reason) < 10) {
            return self::no('reason_too_short');
        }

        return array('allowed' => true, 'reason' => 'authorised_review');
    }

    /**
     * States in which a signing request may still be cancelled.
     *
     * @return array
     */
    public static function cancellable()
    {
        $out = array();

        foreach (self::states() as $k => $meta) {
            if (!$meta['terminal']) { $out[] = $k; }
        }

        return $out;
    }

    /**
     * States in which an outbound reminder or a re-sent link is permitted.
     *
     * Deliberately excludes `created`: before an invitation exists there is
     * nothing to re-send, and "resend" would silently become "send", which is a
     * different permission.
     *
     * @return array
     */
    public static function resendable()
    {
        return array(self::S_INVITATION_SENT, self::S_VIEWED,
                     self::S_AUTHENTICATION_PENDING, self::S_PARTIALLY_SIGNED);
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function no($reason)
    {
        return array('allowed' => false, 'reason' => $reason);
    }

    private static function keep($state, $reason, $alert)
    {
        return array('apply' => false, 'state' => $state,
                     'reason' => $reason, 'alert' => (bool) $alert);
    }
}
