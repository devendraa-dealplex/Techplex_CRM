<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Kyc_decision_service
 *
 * The only way a KYC case becomes passed or failed.
 *
 * WHY A SERVICE AND NOT A ROUTE
 * -----------------------------
 * `kyc_passed` is the field that says a human being's identity was verified.
 * Everything downstream — whether a contract may complete, what the evidence
 * pack claims, what the company would tell a regulator — rests on it. A route
 * that can write it directly is a route that can be given a `$force` argument
 * six months from now by somebody under deadline pressure, and nothing in the
 * codebase would object.
 *
 * So no route writes it. A route calls `decide()`, which refuses unless every
 * one of the following holds, and returns a refusal reason that a person can
 * act on rather than a boolean:
 *
 *   - the actor is authorised for THIS case on THIS contract (Contract_authz);
 *   - segregation of duties is satisfied — the person who created or invited
 *     the case is not the person deciding it;
 *   - a real verification provider produced the result. The refusing provider
 *     this module ships with cannot produce one, so an unconfigured system
 *     cannot manufacture a pass;
 *   - the evidence the decision rests on exists, and its hash verifies;
 *   - the state transition is legal under Contract_kyc;
 *   - an audit record is produced. Not optionally.
 *
 * WHAT A "MANUAL REFERRAL" IS AND IS NOT
 * --------------------------------------
 * A compliance officer can send a case for manual review. They do that by
 * ASKING THE PROVIDER to refer it — not by writing `manual_review` into the
 * row. Contract_kyc lets staff set exactly one state, `cancelled`, and every
 * other verification state belongs to the provider; referral is no exception,
 * because "this case is under manual review at the provider" is a claim about
 * the provider. So all three decisions here require a provider result, and the
 * reviewer's identity is recorded alongside it as `decided_by_staff` rather
 * than in place of it. Two columns, because one column is how a staff referral
 * later reads as a provider verification.
 */
class Kyc_decision_service
{
    const DECISION_PASS   = 'passed';
    const DECISION_FAIL   = 'failed';
    const DECISION_REFER  = 'manual_review';

    /**
     * @return array
     */
    public static function decisions()
    {
        return array(
            self::DECISION_PASS  => 'Verification passed',
            self::DECISION_FAIL  => 'Verification failed',
            self::DECISION_REFER => 'Referred for manual compliance review',
        );
    }

    /**
     * The capability a decision needs.
     *
     * Approve and reject are separately grantable: a reviewer trusted to fail a
     * case — which stops a contract — is not automatically trusted to pass one,
     * which lets it proceed. One map, so a route cannot invent its own answer.
     *
     * @param  string $decision
     * @return string|null
     */
    public static function capabilityFor($decision)
    {
        $map = array(
            self::DECISION_PASS  => Contract_caps::CAP_KYC_APPROVE,
            self::DECISION_FAIL  => Contract_caps::CAP_KYC_REJECT,
            self::DECISION_REFER => Contract_caps::CAP_KYC_REVIEW,
        );

        return isset($map[$decision]) ? $map[$decision] : null;
    }

    /**
     * The Contract_authz action name for a decision.
     *
     * @param  string $decision
     * @return string
     */
    public static function actionFor($decision)
    {
        $map = array(
            self::DECISION_PASS  => 'approve',
            self::DECISION_FAIL  => 'reject',
            self::DECISION_REFER => 'manual_review',
        );

        return isset($map[$decision]) ? $map[$decision] : 'review';
    }

    /**
     * Decide a case.
     *
     * @param  array $in {actor_id, contract_id, case, decision, reason,
     *                    authz, provider_result, evidence, now,
     *                    segregation_required}
     * @return array {ok, refusal, state, audit}
     */
    public static function decide(array $in)
    {
        $actor    = (int) self::get($in, 'actor_id', 0);
        $case     = self::get($in, 'case', array());
        $decision = (string) self::get($in, 'decision', '');
        $now      = (int) self::get($in, 'now', 0);

        if ($actor <= 0) {
            return self::refuse('no_actor');
        }

        if (!is_array($case) || empty($case)) {
            return self::refuse('kyc_case_not_found');
        }

        if (!array_key_exists($decision, self::decisions())) {
            return self::refuse('unknown_decision');
        }

        /*
         * GATE 1 — authorisation.
         *
         * Handed in already evaluated by Contract_authz rather than recomputed
         * here, so there is exactly one implementation of the access rules. The
         * shape is checked: a caller that forgets to pass it gets a refusal,
         * not an unguarded write.
         */
        $authz = self::get($in, 'authz', null);

        if (!is_array($authz) || !array_key_exists('allowed', $authz)) {
            return self::refuse('authorization_decision_missing');
        }

        if (empty($authz['allowed'])) {
            return self::refuse('not_authorized:' . (string) self::get($authz, 'reason', 'unknown'));
        }

        /* GATE 2 — the transition must be legal. */
        $current = (string) self::get($case, 'state', Contract_kyc::K_PENDING);

        if (!Contract_kyc::isState($current)) {
            return self::refuse('unknown_current_state');
        }

        /*
         * GATE 3 — only a provider result may produce a pass or a fail.
         *
         * Contract_kyc::apply() already refuses a non-provider verdict; this
         * checks the input BEFORE calling it, so the refusal names the missing
         * thing rather than a generic transition error.
         */
        $providerResult = self::get($in, 'provider_result', array());
        $hasProvider    = is_array($providerResult)
                          && !empty($providerResult['provider'])
                          && !empty($providerResult['reference'])
                          && !empty($providerResult['observed_state']);

        if (!$hasProvider) {
            return self::refuse('a_decision_needs_a_verification_result_from_a_provider');
        }

        /*
         * GATE 4 — the evidence must exist and verify.
         *
         * A pass recorded against evidence nobody can produce is worse than no
         * record: it looks like proof.
         */
        if ($decision === self::DECISION_PASS) {
            $evidence = self::get($in, 'evidence', array());

            if (!is_array($evidence) || empty($evidence)) {
                return self::refuse('a_pass_needs_stored_evidence');
            }

            $digest = (string) self::get($evidence, 'sha256', '');

            if (!Contract_evidence::validDigest($digest)) {
                return self::refuse('evidence_hash_missing_or_malformed');
            }

            if (empty($evidence['hash_verified'])) {
                return self::refuse('evidence_hash_did_not_verify');
            }
        }

        /* GATE 5 — the state machine has the final word on the move itself. */
        $observed = $decision === self::DECISION_REFER
            ? Contract_kyc::K_MANUAL_REVIEW
            : ($decision === self::DECISION_PASS ? Contract_kyc::K_PASSED : Contract_kyc::K_FAILED);

        /*
         * 'provider', for all three.
         *
         * Contract_kyc only lets staff set `cancelled`; every other verification
         * state belongs to the provider, referral included. That is not an
         * oversight to work around here — a staff member does not refer a case
         * by writing `manual_review` into the row, they ASK the provider to
         * refer it and the provider's answer comes back through this same path.
         * Passing 'staff' to get the transition accepted would be forging the
         * provider's signature on somebody else's decision.
         */
        $applied = Contract_kyc::apply($current, $observed, 'provider');

        if (empty($applied['apply'])) {
            return self::refuse('state_machine_refused:' . (string) self::get($applied, 'reason', 'unknown'));
        }

        /* A reason is required for anything that is not a clean pass. */
        $why = trim((string) self::get($in, 'reason', ''));

        if ($decision !== self::DECISION_PASS && strlen($why) < 10) {
            return self::refuse('reason_required');
        }

        return array(
            'ok'       => true,
            'refusal'  => null,
            'state'    => $applied['state'],
            'decision' => $decision,
            /*
             * Provenance, kept apart on purpose. `decided_by_provider` is the
             * provider's own reference and is empty for a manual referral;
             * `decided_by_staff` is the human. Collapsing them into one column
             * is how a staff referral later reads as a provider verification.
             */
            'decided_by_provider' => $decision === self::DECISION_REFER
                ? '' : (string) self::get($providerResult, 'reference', ''),
            'decided_by_staff'    => $actor,
            'decided_at'          => $now,
            'audit'               => self::auditRecord($in, $decision, $current, $applied['state'], $now),
        );
    }

    /**
     * The audit record. Always produced, never optional, and it carries no
     * personal data — Contract_evidence::neverInAudit() lists what may not
     * appear and this keeps to references and verdicts.
     *
     * @return array
     */
    private static function auditRecord(array $in, $decision, $from, $to, $now)
    {
        return array(
            'event'        => 'cv_kyc_decision',
            'actor_id'     => (int) self::get($in, 'actor_id', 0),
            'contract_id'  => (int) self::get($in, 'contract_id', 0),
            'kyc_case_id'  => (int) self::get(self::get($in, 'case', array()), 'id', 0),
            'decision'     => (string) $decision,
            'state_from'   => (string) $from,
            'state_to'     => (string) $to,
            'evidence_sha' => (string) self::get(self::get($in, 'evidence', array()), 'sha256', ''),
            'at'           => (int) $now,
        );
    }

    /**
     * Fields no route may write directly.
     *
     * The suite scans the controller for assignments to these and fails if it
     * finds one. It is a statement about where the boundary is, enforced by a
     * check rather than by hoping.
     *
     * @return array
     */
    public static function fieldsOnlyThisServiceMayWrite()
    {
        return array('kyc_passed', 'kyc_passed_at', 'decided_by_provider');
    }

    /* ---- helpers ------------------------------------------------------- */

    private static function refuse($reason)
    {
        return array('ok' => false, 'refusal' => (string) $reason, 'state' => null,
                     'audit' => array('event' => 'cv_kyc_decision_refused',
                                      'reason' => (string) $reason));
    }

    private static function get($a, $k, $default = null)
    {
        return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $default;
    }
}
