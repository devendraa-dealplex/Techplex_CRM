<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Video_kyc_provider.php';
require_once __DIR__ . '/Contract_failures.php';

/**
 * Contract_kyc
 *
 * The Video KYC vocabulary, the session state machine, the capability list an
 * implementation may declare, and the provider that refuses everything because
 * no provider has been chosen.
 *
 * WHY THE REFUSING PROVIDER IS SHIPPED RATHER THAN OMITTED
 * --------------------------------------------------------
 * Without it, a contract whose profile requires KYC reaches the point where a
 * session must be created and hits a null. That surfaces as a PHP error to a
 * member of staff, which invites exactly one response: someone adds a
 * "mark as verified" control to get the contract moving.
 *
 * With it, the same contract stops at a sentence explaining that no verification
 * provider is configured, the lifecycle stays at "Signed — Video KYC Pending",
 * and the completion gate refuses for a named reason. The system is blocked, and
 * correctly so, instead of being broken.
 */

/* ---------------------------------------------------------------------- */

class Contract_kyc
{
    /* ---- session states -------------------------------------------------- */

    const K_NONE           = 'not_required';
    const K_PENDING        = 'pending';          /* required, no session yet */
    const K_SESSION_CREATED = 'session_created';
    const K_LINK_SENT      = 'link_sent';
    const K_IN_PROGRESS    = 'in_progress';
    const K_MANUAL_REVIEW  = 'manual_review';
    const K_PASSED         = 'passed';
    const K_FAILED         = 'failed';
    const K_EXPIRED        = 'expired';
    const K_CANCELLED      = 'cancelled';

    /**
     * Every session state, with its rank and whether it ends the session.
     *
     * `passed` and `failed` are terminal; `expired` and `cancelled` are terminal
     * too. `manual_review` deliberately is NOT — a referred session is still
     * live and is waiting on a person at the provider.
     *
     * @return array
     */
    public static function states()
    {
        return array(
            self::K_NONE => array(
                'label' => 'Not required', 'rank' => 0, 'terminal' => true,
                'means' => 'This signer is not required to complete Video KYC.',
            ),
            self::K_PENDING => array(
                'label' => 'Verification pending', 'rank' => 10, 'terminal' => false,
                'means' => 'Verification is required and no session exists yet.',
            ),
            self::K_SESSION_CREATED => array(
                'label' => 'Session created', 'rank' => 20, 'terminal' => false,
                'means' => 'The provider has a session. The signer has not been invited to it yet.',
            ),
            self::K_LINK_SENT => array(
                'label' => 'Invitation sent', 'rank' => 30, 'terminal' => false,
                'means' => 'A single-use verification link was delivered to the signer.',
            ),
            self::K_IN_PROGRESS => array(
                'label' => 'Verification in progress', 'rank' => 40, 'terminal' => false,
                'means' => 'The signer joined the session. There is no decision yet.',
            ),
            self::K_MANUAL_REVIEW => array(
                'label' => 'Referred for review', 'rank' => 50, 'terminal' => false,
                'means' => 'A reviewer at the provider is deciding. Still live, still not passed.',
            ),
            self::K_PASSED => array(
                'label' => 'Verified', 'rank' => 100, 'terminal' => true,
                'means' => 'The provider affirmatively passed this signer. Only a provider may '
                         . 'produce this state.',
            ),
            self::K_FAILED => array(
                'label' => 'Verification failed', 'rank' => 100, 'terminal' => true,
                'means' => 'The provider did not pass this signer. The agreement cannot complete.',
            ),
            self::K_EXPIRED => array(
                'label' => 'Verification expired', 'rank' => 100, 'terminal' => true,
                'means' => 'The session window closed without a decision.',
            ),
            self::K_CANCELLED => array(
                'label' => 'Verification cancelled', 'rank' => 100, 'terminal' => true,
                'means' => 'The session was ended without a decision.',
            ),
        );
    }

    public static function all() { return array_keys(self::states()); }

    public static function isState($s)
    {
        return is_string($s) && array_key_exists($s, self::states());
    }

    public static function isTerminal($s)
    {
        $x = self::states();

        return isset($x[$s]) ? (bool) $x[$s]['terminal'] : false;
    }

    public static function rank($s)
    {
        $x = self::states();

        return isset($x[$s]) ? (int) $x[$s]['rank'] : -1;
    }

    public static function label($s)
    {
        $x = self::states();

        return isset($x[$s]) ? $x[$s]['label'] : 'Unknown';
    }

    /**
     * The ONE state no human may cause, anywhere, ever.
     *
     * Mirrors Contract_lifecycle::staffSettable() so the rule holds at both
     * levels: a signer's KYC cannot be passed by hand, and neither can the
     * contract's.
     *
     * @param  string $state
     * @return bool
     */
    public static function isStaffSettable($state)
    {
        /*
         * Staff may cancel a session — that is an administrative decision to
         * stop, not a claim about identity. Everything else is the provider's.
         */
        return $state === self::K_CANCELLED;
    }

    /**
     * Permitted transitions.
     *
     * @return array
     */
    public static function transitions()
    {
        $end = array(self::K_EXPIRED, self::K_CANCELLED);

        return array(
            self::K_NONE            => array(),
            self::K_PENDING         => array_merge(array(self::K_SESSION_CREATED), $end),
            self::K_SESSION_CREATED => array_merge(array(self::K_LINK_SENT, self::K_IN_PROGRESS), $end),
            self::K_LINK_SENT       => array_merge(array(self::K_LINK_SENT, self::K_IN_PROGRESS), $end),
            self::K_IN_PROGRESS     => array_merge(
                array(self::K_MANUAL_REVIEW, self::K_PASSED, self::K_FAILED), $end),
            self::K_MANUAL_REVIEW   => array_merge(array(self::K_PASSED, self::K_FAILED), $end),
            self::K_PASSED          => array(),
            self::K_FAILED          => array(),
            self::K_EXPIRED         => array(),
            self::K_CANCELLED       => array(),
        );
    }

    /**
     * Apply an observed session state.
     *
     * Same defences as the contract lifecycle: terminal states are never left by
     * a message, stale lower-ranked observations never overwrite, unknown states
     * move nothing, and `passed` is refused from any actor but a provider.
     *
     * @param  string $current
     * @param  string $observed
     * @param  string $actor 'provider' | 'staff' | 'system'
     * @return array {apply, state, reason, alert}
     */
    public static function apply($current, $observed, $actor = 'provider')
    {
        if (!self::isState($observed)) { return self::keep($current, 'unknown_observed_state', false); }
        if (!self::isState($current))  { return self::keep($current, 'unknown_current_state', true); }

        if ($current === $observed)    { return self::keep($current, 'already_in_this_state', false); }

        if (self::isTerminal($current)) {
            return self::keep($current, 'refused_downgrade_from_terminal', true);
        }

        if (self::rank($observed) < self::rank($current)) {
            return self::keep($current, 'stale_lower_ranked_observation', false);
        }

        /*
         * THE RULE. A verification decision belongs to the verification
         * provider. Staff may stop a session; they may not conclude one.
         */
        if ($actor !== 'provider' && !self::isStaffSettable($observed)) {
            return self::keep($current, 'only_a_provider_may_decide_verification', true);
        }

        $t = self::transitions();

        if (!in_array($observed, $t[$current], true)) {
            return self::keep($current, 'transition_not_permitted', true);
        }

        return array('apply' => true, 'state' => $observed,
                     'reason' => 'applied_by_' . $actor, 'alert' => false);
    }

    /* ---- capabilities ---------------------------------------------------- */

    /**
     * Every capability a Video KYC provider may declare.
     *
     * A provider declares which of these it actually offers; nothing is assumed.
     * The two annotated below are the ones most often misrepresented.
     *
     * @return array capability => what it means
     */
    public static function knownCapabilities()
    {
        return array(
            'recording_consent'      => 'Records the signer\'s consent to being recorded, before recording starts',
            'camera_mic_preflight'   => 'Checks camera and microphone before the session begins',
            'liveness'               => 'Verifies a live human is present, not a photograph or a replay',
            'face_match'             => 'Compares the live face against an identity document photograph',
            'identity_document'      => 'Verifies an approved identity document',
            'pan_verification'       => 'Verifies a PAN against the issuing source',
            'name_match'             => 'Compares the verified name against the name on the contract',
            'agent_assisted'         => 'A trained agent conducts or reviews the session',
            'session_metadata'       => 'Returns session timestamps and technical metadata',
            'provider_location'      => 'Returns a location the PROVIDER determined during the session — '
                                      . 'not a location the browser reported',
            'decision_reason'        => 'Returns a machine-readable reason for a rejection',
            'evidence_hash'          => 'Returns a hash of each evidence item, so storage can be verified',
            'configurable_retention' => 'Retention period and deletion can be configured',
        );
    }

    /**
     * Location labelling, which is not a detail.
     *
     * A coordinate collected by `navigator.geolocation` is a value the browser
     * was willing to report. It can be spoofed by anybody who can open developer
     * tools, and it says nothing about where a person was. Presenting it beside
     * verified identity attributes lends it a credibility it has not got, and
     * that is how a browser coordinate ends up quoted in a dispute.
     *
     * So the two are separate fields with separate labels, and the browser one
     * says what it is everywhere it appears.
     *
     * @return array
     */
    public static function locationLabels()
    {
        return array(
            'browser'  => 'Browser-reported location (not verified)',
            'provider' => 'Provider-determined location',
            'rule'     => 'A browser-reported coordinate is never displayed, exported or stored as a '
                        . 'verified location. It is self-reported by the signer\'s device and can be '
                        . 'set to any value.',
        );
    }

    private static function keep($state, $reason, $alert)
    {
        return array('apply' => false, 'state' => $state,
                     'reason' => $reason, 'alert' => (bool) $alert);
    }
}

/* ---------------------------------------------------------------------- */

/**
 * Unconfigured_kyc_provider
 *
 * The provider in force until an approved Video KYC vendor is selected and
 * activated. It refuses everything, by name, without pretending.
 *
 * This is the honest state of the system today: Leegality does not provide Video
 * KYC, no other vendor has been chosen, and no compliance determination has been
 * made about whether V-CIP even applies to these contracts. Until those three
 * things happen, a contract that requires verification cannot complete — and it
 * says so rather than quietly completing.
 */
class Unconfigured_kyc_provider implements VideoKycProvider
{
    const REASON = Contract_failures::F_PROVIDER_UNAVAILABLE;

    const DETAIL =
        'No Video KYC provider is configured. Leegality does not offer Video KYC — its Face Match, '
      . 'Capture Photo and Liveliness features are security steps inside the signing ceremony, not '
      . 'V-CIP — so verification requires a separate approved vendor. Until one is selected and '
      . 'activated, contracts that require verification will stay at "Signed — Video KYC Pending" '
      . 'and will not complete.';

    public function implementationStatus()
    {
        return array(
            'implemented' => false,
            'usable'      => false,
            'reason'      => self::REASON,
            'detail'      => self::DETAIL,
            'missing'     => array(
                'approved_video_kyc_vendor_selected',
                'commercial_plan_activated',
                'vcip_applicability_determined',
                'retention_and_deletion_policy_approved',
                'provider_credentials_entered_by_an_administrator',
            ),
        );
    }

    public function healthCheck()
    {
        return $this->no(array('detail' => self::DETAIL));
    }

    public function createSession(array $signer, array $context)
    {
        /*
         * Refuses BEFORE touching the signer's details. A refusal that first
         * assembled a payload would be moving personal data around for no
         * purpose, and identity data least of all.
         */
        return $this->no(array('session_reference' => null, 'expires_at' => null));
    }

    public function invitationUrl($sessionReference)
    {
        return $this->no(array('url' => null, 'expires_at' => null));
    }

    public function getSessionStatus($sessionReference)
    {
        return $this->no(array('state' => null, 'decision' => null,
                               'decided_at' => null, 'reject_reason' => null));
    }

    /**
     * Verify an inbound webhook.
     *
     * Returns false, always. No provider is configured, so no scheme is known,
     * so no payload can be authenticated, so none may be trusted. A stub
     * returning true would make a public KYC webhook route accept anything the
     * internet sent it — and what it would be asked to accept is "this person is
     * verified".
     *
     * @param  string $rawPayload
     * @param  array  $headers
     * @return bool
     */
    public function verifyWebhook($rawPayload, array $headers)
    {
        return false;
    }

    public function retrieveEvidence($sessionReference)
    {
        return $this->no(array('items' => array()));
    }

    public function retrySession($sessionReference, $reason)
    {
        return $this->no(array('session_reference' => null));
    }

    public function sendForManualReview($sessionReference, $reason)
    {
        return $this->no(array());
    }

    public function cancelSession($sessionReference, $reason)
    {
        return $this->no(array());
    }

    /**
     * No capability is claimed.
     *
     * Every known capability is present as a key and every one is false, rather
     * than the map being empty. An empty map reads as "not asked yet"; a map of
     * falses reads as "asked, and the answer is no to all of it".
     *
     * @return array
     */
    public function capabilities()
    {
        $out = array();

        foreach (Contract_kyc::knownCapabilities() as $k => $ignored) { $out[$k] = false; }

        return $out;
    }

    private function no(array $extra)
    {
        return array_merge(array(
            'ok'      => false,
            'reason'  => self::REASON,
            'message' => 'Video KYC is not available: no verification provider has been configured. '
                       . 'Nothing was sent and no identity data was handled.',
            'blocked' => true,
        ), $extra);
    }
}
