<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Contract_signing_state.php';

/**
 * Contract_lifecycle
 *
 * The full journey of a contract through preparation, execution and identity
 * verification — the fourteen states specified for this programme:
 *
 *   Draft → Internal Review → Approved → Ready to Send → Signing Request Created
 *   → Invitation Sent → Viewed → OTP Verified → Signing In Progress
 *   → Signed Pending KYC → Video KYC Invited → Video KYC In Progress
 *   → KYC Passed → Completed
 *
 * WHY THIS IS A SECOND CLASS AND NOT AN EDIT TO Contract_signing_state
 * --------------------------------------------------------------------
 * Contract_signing_state models the PROVIDER'S request: it starts at `created`,
 * it is driven by provider events, and its "never downgrade a terminal state"
 * rule is the most safety-critical logic in the module. It is deployed and
 * covered by tests that have already caught real defects.
 *
 * This lifecycle is a wider thing. It starts before any provider is involved
 * (a draft under internal review has no signing request and may never have one)
 * and continues after signing into an identity step that a DIFFERENT provider
 * owns. Rewriting the request machine to cover all of it would have meant
 * reopening that downgrade rule to make room for states it was never about.
 *
 * So the two compose: this class owns the contract's journey, the request
 * machine owns the provider's request, and `fromRequestState()` is the single
 * place one is expressed in terms of the other.
 *
 * THE TWO RULES THAT CARRY THE WEIGHT
 * -----------------------------------
 * 1. A human cannot set `kyc_passed`. Only a verification provider's callback
 *    can, and there is no such provider yet. Every other state has some
 *    administrative path; this one deliberately has none, because a staff member
 *    marking "KYC passed" from a dropdown is precisely the fake verification
 *    this programme was told not to build.
 *
 * 2. `completed` is a conclusion, not a status. It is refused unless every
 *    condition is independently true — signatures in, evidence downloaded and
 *    hash-verified, KYC settled where it is required. `completionBlockers()`
 *    returns the reasons rather than a boolean, so the screen can say what is
 *    missing instead of just staying grey.
 *
 * Pure: no database, no clock of its own, no I/O.
 */
class Contract_lifecycle
{
    /* ---- preparation: no provider involved ----------------------------- */

    const L_DRAFT              = 'draft';
    const L_INTERNAL_REVIEW    = 'internal_review';
    const L_APPROVED           = 'approved';
    const L_READY_TO_SEND      = 'ready_to_send';

    /* ---- execution: the provider's request drives these ---------------- */

    const L_REQUEST_CREATED    = 'signing_request_created';
    const L_INVITATION_SENT    = 'invitation_sent';
    const L_VIEWED             = 'viewed';
    const L_OTP_VERIFIED       = 'otp_verified';
    const L_SIGNING_IN_PROGRESS = 'signing_in_progress';

    /* ---- identity: a different provider drives these ------------------- */

    const L_SIGNED_PENDING_KYC = 'signed_pending_kyc';
    const L_KYC_INVITED        = 'video_kyc_invited';
    const L_KYC_IN_PROGRESS    = 'video_kyc_in_progress';
    const L_KYC_PASSED         = 'kyc_passed';

    /* ---- evidence: signing is over, proof is not yet in hand ------------ */

    /**
     * Signing and verification are finished and the evidence is NOT.
     *
     * This state exists because the alternative is worse in both directions.
     * Calling it `completed` claims an executed agreement whose proof lives only
     * on someone else's server behind a URL that expires in fifteen seconds.
     * Leaving it at `signed_pending_kyc` blames the customer for a failure that
     * is entirely ours — they did everything asked of them.
     *
     * So it says exactly what is true: the execution is done, the evidence is
     * outstanding, and that is our problem to fix.
     */
    const L_EVIDENCE_PENDING   = 'execution_complete_evidence_pending';

    /** Signed document and audit trail retrieved, stored and hash-verified. */
    const L_EVIDENCE_RETRIEVED = 'evidence_retrieved';

    /* ---- conclusions --------------------------------------------------- */

    const L_COMPLETED          = 'completed';
    const L_DECLINED           = 'declined';
    const L_EXPIRED            = 'expired';
    const L_CANCELLED          = 'cancelled';
    const L_FAILED             = 'failed';
    const L_KYC_FAILED         = 'kyc_failed';

    /**
     * Every state: what an operator sees, what it actually means, whether it
     * ends the journey, and who is allowed to cause it.
     *
     * `actor` is the important column:
     *   'staff'    — an authorised person, through the UI
     *   'provider' — only a verified provider message or a status pull
     *   'system'   — only this module's own transactional logic
     *
     * A state whose actor is 'provider' can never be reached from a form post,
     * whatever permission the poster holds.
     *
     * @return array
     */
    public static function states()
    {
        return array(

            self::L_DRAFT => array(
                'label' => 'Draft', 'rank' => 10, 'terminal' => false, 'actor' => 'staff',
                'means' => 'Being prepared. Content, placements and signers may all still change.',
            ),

            self::L_INTERNAL_REVIEW => array(
                'label' => 'Internal review', 'rank' => 20, 'terminal' => false, 'actor' => 'staff',
                'means' => 'Submitted for review. The maker has finished; the checker has not started.',
            ),

            self::L_APPROVED => array(
                'label' => 'Approved', 'rank' => 30, 'terminal' => false, 'actor' => 'staff',
                'means' => 'A checker other than the maker approved it. Material change revokes this.',
            ),

            self::L_READY_TO_SEND => array(
                'label' => 'Ready to send', 'rank' => 40, 'terminal' => false, 'actor' => 'system',
                'means' => 'Approved AND every send precondition is satisfied — signers complete, '
                         . 'placements approved, document prepared. Reached by checking, not by asserting.',
            ),

            self::L_REQUEST_CREATED => array(
                'label' => 'Signing request created', 'rank' => 50, 'terminal' => false, 'actor' => 'provider',
                'means' => 'The provider accepted the request and returned its identifier. '
                         . 'Nothing has reached a signer yet.',
            ),

            self::L_INVITATION_SENT => array(
                'label' => 'Invitation sent', 'rank' => 60, 'terminal' => false, 'actor' => 'provider',
                'means' => 'A signing link exists for at least one signer.',
            ),

            self::L_VIEWED => array(
                'label' => 'Viewed', 'rank' => 70, 'terminal' => false, 'actor' => 'provider',
                'means' => 'A signer opened the document. Not consent, not identity, not a signature.',
            ),

            self::L_OTP_VERIFIED => array(
                'label' => 'OTP verified', 'rank' => 80, 'terminal' => false, 'actor' => 'provider',
                'means' => 'A signer completed the provider-owned authentication step. '
                         . 'This module never runs its own competing OTP ceremony.',
            ),

            self::L_SIGNING_IN_PROGRESS => array(
                'label' => 'Signing in progress', 'rank' => 90, 'terminal' => false, 'actor' => 'provider',
                'means' => 'At least one signer has signed and at least one mandatory signer has not.',
            ),

            self::L_SIGNED_PENDING_KYC => array(
                /*
                 * The label is specified and must not be softened. A contract
                 * sitting here is signed but NOT executed, and anybody reading
                 * the screen has to be able to see that at a glance.
                 */
                'label' => 'Signed — Video KYC Pending', 'rank' => 100, 'terminal' => false, 'actor' => 'provider',
                'means' => 'Every mandatory signature is in. Identity verification is required and '
                         . 'has not passed, so the agreement is NOT yet complete.',
            ),

            self::L_KYC_INVITED => array(
                'label' => 'Video KYC link sent', 'rank' => 110, 'terminal' => false, 'actor' => 'system',
                'means' => 'A verification session was created and a single-use link was delivered '
                         . 'to the signer.',
            ),

            self::L_KYC_IN_PROGRESS => array(
                'label' => 'Video KYC in progress', 'rank' => 120, 'terminal' => false, 'actor' => 'provider',
                'means' => 'The signer joined the verification session. No outcome yet.',
            ),

            self::L_KYC_PASSED => array(
                'label' => 'KYC passed', 'rank' => 130, 'terminal' => false, 'actor' => 'provider',
                'means' => 'A verification provider affirmatively passed the signer. '
                         . 'NO staff action can produce this state — see staffSettable().',
            ),

            self::L_EVIDENCE_PENDING => array(
                'label' => 'Execution complete — evidence pending', 'rank' => 140,
                'terminal' => false, 'actor' => 'system',
                'means' => 'Every signature and every required verification is in. The signed '
                         . 'document and audit trail have NOT been retrieved, so this is not yet a '
                         . 'completed agreement. The outstanding work is ours, not the customer\'s.',
            ),

            self::L_EVIDENCE_RETRIEVED => array(
                'label' => 'Evidence retrieved', 'rank' => 150, 'terminal' => false, 'actor' => 'system',
                'means' => 'Signed document and audit trail retrieved, stored and hash-verified.',
            ),

            self::L_COMPLETED => array(
                'label' => 'Completed', 'rank' => 200, 'terminal' => true, 'actor' => 'system',
                'means' => 'Fully executed: signatures in, signed document and audit trail retrieved '
                         . 'and hash-verified, and identity settled where it was required.',
            ),

            self::L_DECLINED => array(
                'label' => 'Declined', 'rank' => 200, 'terminal' => true, 'actor' => 'provider',
                'means' => 'A signer refused. This request is over; a new one would be needed.',
            ),

            self::L_EXPIRED => array(
                'label' => 'Expired', 'rank' => 200, 'terminal' => true, 'actor' => 'provider',
                'means' => 'The signing window closed before completion.',
            ),

            self::L_CANCELLED => array(
                'label' => 'Cancelled', 'rank' => 200, 'terminal' => true, 'actor' => 'staff',
                'means' => 'Withdrawn locally by authorised staff, with a reason. The provider-side '
                         . 'record is deliberately LEFT IN PLACE — see cancellationIsLocalOnly().',
            ),

            self::L_FAILED => array(
                'label' => 'Failed', 'rank' => 200, 'terminal' => true, 'actor' => 'system',
                'means' => 'The request could not be created or progressed. See Contract_failures.',
            ),

            self::L_KYC_FAILED => array(
                'label' => 'KYC failed', 'rank' => 200, 'terminal' => true, 'actor' => 'provider',
                'means' => 'Verification did not pass. The document is signed but the agreement is '
                         . 'NOT complete, and this needs a human decision.',
            ),
        );
    }

    /**
     * Cancellation never calls the provider, and this is why.
     *
     * The only documented Leegality operation resembling a withdrawal is
     * `DELETE /v3.0/sign/request`, which permanently deletes the document AND
     * ALL ASSOCIATED DATA — including the audit trail. It does not set a
     * cancelled status; it destroys the record.
     *
     * Wiring a Cancel button to that would present the irreversible destruction
     * of legal evidence as an ordinary workflow action, and the loss would
     * typically be discovered months later, by somebody asking for the very
     * audit trail that was deleted.
     *
     * So cancellation is local: we stop, we record who and why, and we leave the
     * provider's document to expire on its own. Its record surviving is the
     * correct outcome for evidence, not a loose end.
     *
     * @return array {local_only, never_call, why}
     */
    public static function cancellationIsLocalOnly()
    {
        return array(
            'local_only' => true,
            'never_call' => 'DELETE /v3.0/sign/request',
            'why'        => 'That endpoint permanently deletes the document and its audit trail. '
                          . 'It is a destruction operation, not a withdrawal, and this module '
                          . 'never calls it as part of a lifecycle action.',
        );
    }

    /* ---- basic predicates ---------------------------------------------- */

    public static function all()
    {
        return array_keys(self::states());
    }

    public static function isState($state)
    {
        return is_string($state) && array_key_exists($state, self::states());
    }

    public static function isTerminal($state)
    {
        $s = self::states();

        return isset($s[$state]) ? (bool) $s[$state]['terminal'] : false;
    }

    public static function rank($state)
    {
        $s = self::states();

        return isset($s[$state]) ? (int) $s[$state]['rank'] : -1;
    }

    /**
     * The operator-facing label. Never derived from the key, because
     * `signed_pending_kyc` must read as "Signed — Video KYC Pending" and a
     * prettified key would read as "Signed pending kyc", which loses the warning.
     *
     * @param  string $state
     * @return string
     */
    public static function label($state)
    {
        $s = self::states();

        return isset($s[$state]) ? $s[$state]['label'] : 'Unknown';
    }

    /**
     * Which states an authorised member of staff may cause directly.
     *
     * The exclusions are the point of the method:
     *
     *   - every `provider` state, because staff asserting a provider's
     *     observation is staff fabricating evidence;
     *   - every `system` state, because those are conclusions computed from
     *     preconditions — `ready_to_send` means the checks passed, and letting
     *     somebody set it directly would let them skip the checks;
     *   - `kyc_passed` above all, which is a provider state AND the one a
     *     well-meaning person is most likely to want to set by hand to unblock
     *     a stuck contract.
     *
     * @return array
     */
    public static function staffSettable()
    {
        $out = array();

        foreach (self::states() as $k => $meta) {
            if ($meta['actor'] === 'staff') { $out[] = $k; }
        }

        return $out;
    }

    /**
     * @param  string $state
     * @return bool
     */
    public static function isStaffSettable($state)
    {
        return in_array($state, self::staffSettable(), true);
    }

    /**
     * States that only a verified provider message or an authenticated status
     * pull may produce.
     *
     * @return array
     */
    public static function providerOnly()
    {
        $out = array();

        foreach (self::states() as $k => $meta) {
            if ($meta['actor'] === 'provider') { $out[] = $k; }
        }

        return $out;
    }

    /* ---- transitions ---------------------------------------------------- */

    /**
     * Which states may follow which.
     *
     * Written out rather than derived from rank, for the same reason as in the
     * request machine: progress is not simply "rank increases". A contract may
     * be sent back from review to draft, `signing_in_progress` repeats as each
     * signer completes, and abandonment is reachable from almost anywhere.
     *
     * @return array
     */
    public static function transitions()
    {
        /* Reachable from any live state. */
        $abandon = array(self::L_CANCELLED, self::L_FAILED);

        /* Reachable only once a provider request exists. */
        $providerAbandon = array(self::L_DECLINED, self::L_EXPIRED);

        return array(

            self::L_DRAFT => array_merge(
                array(self::L_INTERNAL_REVIEW), $abandon),

            /* Review can send it back. That is the point of review. */
            self::L_INTERNAL_REVIEW => array_merge(
                array(self::L_DRAFT, self::L_APPROVED), $abandon),

            /*
             * Approved can fall back to draft: a material change revokes
             * approval, and the revocation has to land somewhere.
             */
            self::L_APPROVED => array_merge(
                array(self::L_DRAFT, self::L_READY_TO_SEND), $abandon),

            self::L_READY_TO_SEND => array_merge(
                array(self::L_DRAFT, self::L_APPROVED, self::L_REQUEST_CREATED), $abandon),

            /*
             * `completed` is deliberately absent from every signing state's
             * successor list. Nothing reaches completion by signing alone: the
             * path out of signing is either the KYC track or the evidence
             * track, and `completed` is only ever entered from
             * `evidence_retrieved`.
             */
            self::L_REQUEST_CREATED => array_merge(
                array(self::L_INVITATION_SENT, self::L_VIEWED, self::L_OTP_VERIFIED,
                      self::L_SIGNING_IN_PROGRESS, self::L_SIGNED_PENDING_KYC,
                      self::L_EVIDENCE_PENDING),
                $providerAbandon, $abandon),

            self::L_INVITATION_SENT => array_merge(
                array(self::L_VIEWED, self::L_OTP_VERIFIED, self::L_SIGNING_IN_PROGRESS,
                      self::L_SIGNED_PENDING_KYC, self::L_EVIDENCE_PENDING),
                $providerAbandon, $abandon),

            self::L_VIEWED => array_merge(
                array(self::L_OTP_VERIFIED, self::L_SIGNING_IN_PROGRESS,
                      self::L_SIGNED_PENDING_KYC, self::L_EVIDENCE_PENDING),
                $providerAbandon, $abandon),

            self::L_OTP_VERIFIED => array_merge(
                array(self::L_VIEWED, self::L_SIGNING_IN_PROGRESS,
                      self::L_SIGNED_PENDING_KYC, self::L_EVIDENCE_PENDING),
                $providerAbandon, $abandon),

            /* Repeats as each further signer completes. */
            self::L_SIGNING_IN_PROGRESS => array_merge(
                array(self::L_SIGNING_IN_PROGRESS, self::L_OTP_VERIFIED,
                      self::L_SIGNED_PENDING_KYC, self::L_EVIDENCE_PENDING),
                $providerAbandon, $abandon),

            /*
             * The KYC track. `completed` is NOT reachable from here — a
             * contract that reached signed_pending_kyc did so because KYC is
             * required, and the only ways out are through verification or
             * through an authorised review.
             */
            self::L_SIGNED_PENDING_KYC => array_merge(
                array(self::L_KYC_INVITED), $abandon),

            self::L_KYC_INVITED => array_merge(
                array(self::L_KYC_IN_PROGRESS, self::L_KYC_PASSED, self::L_KYC_FAILED), $abandon),

            self::L_KYC_IN_PROGRESS => array_merge(
                array(self::L_KYC_PASSED, self::L_KYC_FAILED), $abandon),

            self::L_KYC_PASSED => array_merge(
                array(self::L_EVIDENCE_PENDING, self::L_EVIDENCE_RETRIEVED), $abandon),

            /*
             * The evidence track. This is the ONLY road to `completed`, and
             * `evidence_retrieved` is the only state it leaves from — so an
             * agreement cannot be completed without the proof of it having been
             * fetched, stored and hash-verified first.
             */
            self::L_EVIDENCE_PENDING => array_merge(
                array(self::L_EVIDENCE_RETRIEVED), $abandon),

            self::L_EVIDENCE_RETRIEVED => array_merge(
                array(self::L_COMPLETED), $abandon),

            /* Terminal. Leaving one is an authorised review, not a transition. */
            self::L_COMPLETED  => array(),
            self::L_DECLINED   => array(),
            self::L_EXPIRED    => array(),
            self::L_CANCELLED  => array(),
            self::L_FAILED     => array(),
            self::L_KYC_FAILED => array(),
        );
    }

    /**
     * May the contract move from $from to $to, and is $actor allowed to do it?
     *
     * Fails closed on anything not positively recognised — an unknown state on
     * either side is a refusal, not a permission, so a typo in a mapping table
     * cannot become a legal transition.
     *
     * @param  string $from
     * @param  string $to
     * @param  string $actor 'staff' | 'provider' | 'system'
     * @return array {allowed, reason}
     */
    public static function canTransition($from, $to, $actor = 'system')
    {
        if (!self::isState($from)) { return self::no('unknown_current_state'); }
        if (!self::isState($to))   { return self::no('unknown_target_state'); }

        if ($from === $to) { return self::no('no_change'); }

        if (self::isTerminal($from)) { return self::no('current_state_is_terminal'); }

        $t = self::transitions();

        if (!in_array($to, $t[$from], true)) {
            return self::no('transition_not_permitted');
        }

        $required = self::states();
        $required = $required[$to]['actor'];

        /*
         * Staff may never cause a provider state. This is the check that stops
         * "mark KYC passed" existing at all, and it is enforced here rather than
         * only in the controller so that a future route cannot bypass it by
         * forgetting to ask.
         */
        if ($actor === 'staff' && $required !== 'staff') {
            return self::no('state_not_settable_by_staff');
        }

        /* A provider message may not cause a purely administrative state. */
        if ($actor === 'provider' && $required === 'staff') {
            return self::no('state_not_settable_by_provider');
        }

        return array('allowed' => true, 'reason' => 'permitted');
    }

    /**
     * Apply an observed state, defending against stale and out-of-order news.
     *
     * Same three refusals as the request machine, for the same reasons: a
     * terminal state is never left by a message, a lower-ranked observation
     * never overwrites a higher-ranked one, and an unknown state moves nothing.
     *
     * @param  string $current
     * @param  string $observed
     * @param  string $actor
     * @return array {apply, state, reason, alert}
     */
    public static function apply($current, $observed, $actor = 'provider')
    {
        if (!self::isState($observed)) {
            return self::keep($current, 'unknown_observed_state', false);
        }

        if (!self::isState($current)) {
            return self::keep($current, 'unknown_current_state', true);
        }

        if ($current === $observed) {
            return self::keep($current, 'already_in_this_state', false);
        }

        if (self::isTerminal($current)) {
            return self::keep($current, 'refused_downgrade_from_terminal', true);
        }

        if (self::rank($observed) < self::rank($current)) {
            return self::keep($current, 'stale_lower_ranked_observation', false);
        }

        $can = self::canTransition($current, $observed, $actor);

        if (empty($can['allowed'])) {
            return self::keep($current, $can['reason'], true);
        }

        return array('apply' => true, 'state' => $observed,
                     'reason' => 'applied_by_' . $actor, 'alert' => false);
    }

    /* ---- where signing lands, and what completion demands --------------- */

    /**
     * Where a contract goes when the last mandatory signature arrives.
     *
     * The specified default workflow is: signature completed → automatic KYC
     * invitation → KYC passed → agreement completed. So a contract requiring
     * KYC lands in `signed_pending_kyc`, NOT in `completed`, and the label it
     * then carries says so in as many words.
     *
     * A contract NOT requiring KYC does not go to `completed` either. At the
     * instant the last signature lands, the signed document and audit trail have
     * definitionally not been fetched yet — the webhook that told us about the
     * signature arrived before anything was downloaded. So it lands in
     * `execution_complete_evidence_pending`, which is simply what is true, and
     * moves on once the evidence is in hand.
     *
     * This is stricter than returning `completed` and letting completionBlockers()
     * catch it. The blockers would have caught it; but a method that NAMES
     * `completed` as the state after a signature invites a caller to write that
     * state somewhere without asking the blockers, and one day a caller will.
     *
     * @param  bool $kycRequired
     * @return string
     */
    public static function stateAfterFinalSignature($kycRequired)
    {
        return $kycRequired ? self::L_SIGNED_PENDING_KYC : self::L_EVIDENCE_PENDING;
    }

    /**
     * Where a contract goes when its evidence has been retrieved and verified.
     *
     * Still not `completed` on its own authority — the caller must consult
     * completionBlockers(), which re-checks every condition including the ones
     * this state implies. Two independent checks of the same fact is the point:
     * a state can be written by a bug, but the blockers read the underlying data.
     *
     * @return string
     */
    public static function stateAfterEvidenceRetrieved()
    {
        return self::L_EVIDENCE_RETRIEVED;
    }

    /**
     * Everything standing between this contract and "Completed".
     *
     * Returns REASONS, not a boolean, so the screen can say what is missing.
     * A blocked completion that cannot explain itself gets overridden by
     * somebody eventually.
     *
     * The evidence conditions are not ceremony. A contract marked executed
     * without the signed PDF and the audit trail in hand is a contract whose
     * proof lives only on somebody else's server, under a URL that expires in
     * fifteen seconds.
     *
     * @param  array $c {
     *     mandatory_signers, signed_mandatory_signers,
     *     kyc_required, kyc_state,
     *     signed_document_stored, signed_document_hash_verified,
     *     audit_trail_stored, audit_trail_hash_verified
     * }
     * @return array of {code, message}
     */
    public static function completionBlockers(array $c)
    {
        $out = array();

        /*
         * Approval and document integrity come first, because they are the two
         * that make everything below meaningless if they are wrong. A perfectly
         * evidenced signature on a document nobody approved, or on a version
         * other than the approved one, is not a smaller problem than a missing
         * audit trail — it is a larger one.
         */
        if (!self::pick($c, 'approved', false)) {
            $out[] = self::blocker('not_approved',
                'This contract was never approved for signing, so it cannot be completed.');
        }

        if (!self::pick($c, 'document_version_unchanged', false)) {
            $out[] = self::blocker('document_version_changed',
                'The document has changed since it was approved. What was signed is not what was '
              . 'approved, and completing would record agreement to a version nobody authorised.');
        }

        $mandatory = (int) self::pick($c, 'mandatory_signers', 0);
        $signed    = (int) self::pick($c, 'signed_mandatory_signers', 0);

        if ($mandatory <= 0) {
            $out[] = self::blocker('no_mandatory_signer',
                'This contract has no mandatory signer, so there is nothing that completion could mean.');
        } elseif ($signed < $mandatory) {
            $out[] = self::blocker('signatures_outstanding',
                sprintf('%d of %d mandatory signatures are in.', $signed, $mandatory));
        }

        if (!self::pick($c, 'signed_document_stored', false)) {
            $out[] = self::blocker('signed_document_not_stored',
                'The signed document has not been retrieved and stored.');
        } elseif (!self::pick($c, 'signed_document_hash_verified', false)) {
            $out[] = self::blocker('signed_document_not_verified',
                'The stored signed document has not been hash-verified against what was downloaded.');
        }

        if (!self::pick($c, 'audit_trail_stored', false)) {
            $out[] = self::blocker('audit_trail_not_stored',
                'The audit trail has not been retrieved and stored.');
        } elseif (!self::pick($c, 'audit_trail_hash_verified', false)) {
            $out[] = self::blocker('audit_trail_not_verified',
                'The stored audit trail has not been hash-verified.');
        }

        if (self::pick($c, 'kyc_required', false)) {
            $required = (int) self::pick($c, 'kyc_sessions_required', 0);
            $passed   = (int) self::pick($c, 'kyc_sessions_passed', 0);
            $kyc      = (string) self::pick($c, 'kyc_state', '');

            /*
             * Two independent conditions, and both must hold. The aggregate
             * state can be right while a count is wrong — that is precisely the
             * shape of bug where a second signer's verification is quietly
             * skipped and the contract completes on the strength of the first.
             */
            if ($required > 0 && $passed < $required) {
                $out[] = self::blocker('kyc_sessions_outstanding',
                    sprintf('%d of %d required Video KYC verifications have passed.',
                        $passed, $required));
            }

            if ($kyc !== self::L_KYC_PASSED) {
                $out[] = self::blocker('kyc_not_passed',
                    'Video KYC is required for this contract and has not passed. '
                  . 'The document is signed; the agreement is not complete.');
            }
        }

        /*
         * An unresolved provider failure means our picture of this contract is
         * known to be incomplete. Completing on top of that is asserting an
         * outcome while holding an unread message that may contradict it.
         */
        if (self::pick($c, 'unresolved_provider_failure', false)) {
            $out[] = self::blocker('unresolved_provider_failure',
                'There is an unresolved failure against the signing provider for this contract. '
              . 'Resolve or reconcile it before completing — until then our record of what '
              . 'happened is known to be incomplete.');
        }

        return $out;
    }

    /**
     * @param  array $context
     * @return bool
     */
    public static function mayComplete(array $context)
    {
        return count(self::completionBlockers($context)) === 0;
    }

    /* ---- expressing the provider's request in lifecycle terms ----------- */

    /**
     * Map a Contract_signing_state value onto this lifecycle.
     *
     * The single place the two machines meet. `signed_by_party` and
     * `partially_signed` both mean the same thing here — somebody has signed and
     * we are not finished — so both map to `signing_in_progress`.
     *
     * `completed` is deliberately NOT mapped to `completed`. The request machine
     * calling a request complete means every mandatory signer signed; this
     * lifecycle's `completed` additionally requires evidence and KYC. Mapping
     * one onto the other would let a provider message alone declare an
     * agreement executed, which is the whole thing completionBlockers() exists
     * to prevent. It maps to the signing endpoint instead, and the caller then
     * asks stateAfterFinalSignature().
     *
     * @param  string $requestState
     * @return string|null null when it maps to nothing
     */
    public static function fromRequestState($requestState)
    {
        $map = array(
            Contract_signing_state::S_CREATED                => self::L_REQUEST_CREATED,
            Contract_signing_state::S_INVITATION_SENT        => self::L_INVITATION_SENT,
            Contract_signing_state::S_VIEWED                 => self::L_VIEWED,
            Contract_signing_state::S_AUTHENTICATION_PENDING => self::L_VIEWED,
            Contract_signing_state::S_SIGNED_BY_PARTY        => self::L_SIGNING_IN_PROGRESS,
            Contract_signing_state::S_PARTIALLY_SIGNED       => self::L_SIGNING_IN_PROGRESS,
            Contract_signing_state::S_DECLINED               => self::L_DECLINED,
            Contract_signing_state::S_EXPIRED                => self::L_EXPIRED,
            Contract_signing_state::S_CANCELLED              => self::L_CANCELLED,
            Contract_signing_state::S_FAILED                 => self::L_FAILED,
        );

        return isset($map[$requestState]) ? $map[$requestState] : null;
    }

    /**
     * Is the request machine's `completed` the state being reported?
     *
     * Separate from fromRequestState() precisely so that a caller has to make a
     * conscious decision about it rather than receiving `completed` by accident.
     *
     * @param  string $requestState
     * @return bool
     */
    public static function isFinalSignature($requestState)
    {
        return $requestState === Contract_signing_state::S_COMPLETED;
    }

    /* ---- authorised review --------------------------------------------- */

    /**
     * Leaving a terminal state requires an authorised human review, recorded.
     *
     * Without this, "never downgrade" would mean "stuck forever" the first time
     * a provider genuinely corrects itself — and a stuck contract is what makes
     * somebody edit the database by hand.
     *
     * `kyc_passed` is excluded as a review target. A KYC failure corrected by
     * review would be a human declaring identity verified, which is the fake
     * verification this design refuses. The correct remedy is a fresh
     * verification session, not an override.
     *
     * @param  string $from
     * @param  string $to
     * @param  array  $review {actor_id, reason, authorised}
     * @return array {allowed, reason}
     */
    public static function reopen($from, $to, array $review)
    {
        if (!self::isTerminal($from)) { return self::no('not_a_terminal_state'); }
        if (!self::isState($to))      { return self::no('unknown_target_state'); }

        if ($to === self::L_KYC_PASSED) {
            return self::no('kyc_passed_is_not_reviewable');
        }

        if (empty($review['authorised'])) { return self::no('not_authorised'); }

        if ((int) self::pick($review, 'actor_id', 0) <= 0) {
            return self::no('no_actor_recorded');
        }

        $reason = trim((string) self::pick($review, 'reason', ''));

        if (strlen($reason) < 10) { return self::no('reason_too_short'); }

        return array('allowed' => true, 'reason' => 'authorised_review');
    }

    /* ---- helpers -------------------------------------------------------- */

    private static function pick(array $a, $k, $default)
    {
        return array_key_exists($k, $a) ? $a[$k] : $default;
    }

    private static function blocker($code, $message)
    {
        return array('code' => $code, 'message' => $message);
    }

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
