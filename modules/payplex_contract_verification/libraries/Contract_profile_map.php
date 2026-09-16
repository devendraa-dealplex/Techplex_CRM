<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_profile_map
 *
 * The mapping between a CRM contract and the Leegality workflow that will
 * execute it, and the validation that refuses to send when the two do not
 * deterministically agree.
 *
 * WHY THIS EXISTS AT ALL
 * ----------------------
 * Leegality's `profileId` selects a WORKFLOW, and the workflow — not the API
 * request — is what defines signer order, eSign types and the security steps
 * each invitee must pass. The documented create call has no per-invitee order
 * field.
 *
 * So our roster's `signing_order` has nothing to bind to on the wire. If we
 * simply posted the invitees in our order and hoped, the provider would apply
 * the workflow's order instead, and the mismatch would be invisible: the request
 * would succeed, links would go out, and the wrong party would be asked to sign
 * first. Nothing would error. Somebody would notice weeks later, or never.
 *
 * This class makes that mismatch impossible to reach. A contract may only be
 * sent through a profile whose declared shape matches the roster exactly, and
 * "exactly" is spelled out below rather than left to judgement.
 *
 * WHY THE PROFILE IS ADMIN-ENTERED AND NOT DISCOVERED
 * ---------------------------------------------------
 * Leegality documents no API that lists workflows or fetches one by id. That was
 * checked before this file was written, not assumed. So the profile's shape
 * cannot be read from the provider; it has to be declared by an administrator
 * who is looking at the workflow in the dashboard.
 *
 * That makes the declaration a claim about someone else's configuration, which
 * can be wrong or go stale. Two consequences are built in: the declaration
 * records who made it and when, and `validationIsDestructive()` says plainly
 * that the only way to truly confirm a profile is to create a real document with
 * it — which is why validation is refused outside sandbox.
 *
 * Pure: no database, no clock of its own, no I/O.
 */
class Contract_profile_map
{
    /* ---- ordering modes ------------------------------------------------- */

    const ORDER_SEQUENTIAL = 'sequential';
    const ORDER_PARALLEL   = 'parallel';

    /* ---- authentication, as the workflow may require it ------------------ */

    const AUTH_NONE     = 'none';
    const AUTH_OTP      = 'otp';
    const AUTH_AADHAAR  = 'aadhaar';
    const AUTH_DSC      = 'dsc';

    /* ---- KYC policy ------------------------------------------------------ */

    const KYC_NONE          = 'none';
    const KYC_ALL_SIGNERS   = 'all_signers';
    const KYC_CUSTOMER_ONLY = 'customer_only';

    /**
     * A profileId looks like this, and nothing else is accepted.
     *
     * Leegality's identifiers in the documentation are ULID-shaped: 26
     * characters, Crockford base32, upper case. The pattern is deliberately a
     * little wider than that — an id that is merely unusual should be a warning,
     * not a hard refusal, because it is the provider's format to change, not
     * ours. What it does exclude is whitespace, quotes, path separators and
     * anything that could travel somewhere it should not.
     */
    const ID_PATTERN = '/^[A-Za-z0-9_-]{6,64}$/';

    /**
     * @return array
     */
    public static function orderingModes()
    {
        return array(
            self::ORDER_SEQUENTIAL => 'Sequential — each signer is invited only after the previous one completes',
            self::ORDER_PARALLEL   => 'Parallel — every signer is invited at once and may sign in any order',
        );
    }

    /**
     * @return array
     */
    public static function authMethods()
    {
        return array(
            self::AUTH_NONE    => 'No additional authentication',
            self::AUTH_OTP     => 'One-time password, run by the provider',
            self::AUTH_AADHAAR => 'Aadhaar eSign, run by the provider',
            self::AUTH_DSC     => 'Digital signature certificate',
        );
    }

    /**
     * @return array
     */
    public static function kycPolicies()
    {
        return array(
            self::KYC_NONE          => 'No Video KYC required',
            self::KYC_ALL_SIGNERS   => 'Every signer must pass Video KYC',
            self::KYC_CUSTOMER_ONLY => 'Only customer-party signers must pass Video KYC',
        );
    }

    /**
     * Authentication this module will NOT run itself.
     *
     * OTP and Aadhaar are the provider's own ceremonies. Building a local OTP
     * beside the provider's would give a signer two codes, make ours the weaker
     * of the two, and produce a second authentication record that can disagree
     * with the authoritative one.
     *
     * So where the workflow owns authentication, we record its verified result
     * and run nothing. This method exists so that rule is checkable rather than
     * merely intended.
     *
     * @return array
     */
    public static function providerOwnedAuth()
    {
        return array(self::AUTH_OTP, self::AUTH_AADHAAR, self::AUTH_DSC);
    }

    /**
     * @param  string $method
     * @return bool
     */
    public static function authIsProviderOwned($method)
    {
        return in_array($method, self::providerOwnedAuth(), true);
    }

    /**
     * Is a profile id well formed?
     *
     * Shape only. A well-formed id can still be wrong, belong to another
     * account, or have been deleted — which is exactly why this is called
     * `looksValid` and not `isValid`.
     *
     * @param  string $id
     * @return bool
     */
    public static function looksValid($id)
    {
        return is_string($id) && preg_match(self::ID_PATTERN, trim($id)) === 1;
    }

    /**
     * Why a profile cannot be confirmed without side effects.
     *
     * Returned as data so the admin screen can show the same sentences the code
     * is written against, and so a test can assert the warning has not been
     * quietly softened into "Validate".
     *
     * @return array
     */
    public static function validationIsDestructive()
    {
        return array(
            'can_validate_read_only' => false,
            'why' => 'Leegality documents no endpoint that lists workflows or fetches one by id. '
                   . 'The only way to prove a profileId is accepted is to create a request with it, '
                   . 'which creates a REAL document in the account.',
            'therefore' => 'Validation is permitted in sandbox only. It creates a genuine sandbox '
                         . 'document, which is then left to expire — it is never deleted, because the '
                         . 'delete operation destroys audit trails.',
            'production' => 'refused',
        );
    }

    /**
     * May a validation run in this environment?
     *
     * @param  string $environment
     * @return array {allowed, reason}
     */
    public static function mayValidate($environment)
    {
        if ($environment !== 'sandbox') {
            return array(
                'allowed' => false,
                'reason'  => 'validation_creates_a_real_document_so_sandbox_only',
            );
        }

        return array('allowed' => true, 'reason' => 'sandbox');
    }

    /* ---- the declaration ------------------------------------------------- */

    /**
     * Check an administrator's profile declaration before it is stored.
     *
     * @param  array $in
     * @return array {ok, errors, clean}
     */
    public static function validateDeclaration(array $in)
    {
        $e     = array();
        $clean = array();

        $clean['profile_id'] = trim((string) self::pick($in, 'profile_id', ''));

        if ($clean['profile_id'] === '') {
            $e['profile_id'] = 'Enter the Workflow (profile) ID from the Leegality dashboard.';
        } elseif (!self::looksValid($clean['profile_id'])) {
            $e['profile_id'] = 'That does not look like a Leegality Workflow ID. Copy it from the '
                             . 'dashboard without surrounding quotes or spaces.';
        }

        $clean['label'] = trim((string) self::pick($in, 'label', ''));

        if (strlen($clean['label']) < 3) {
            $e['label'] = 'Give this workflow a name staff will recognise on the send screen.';
        }

        /* 0 means "any template" — the fallback mapping. */
        $clean['contract_template_id'] = (int) self::pick($in, 'contract_template_id', 0);

        $clean['signer_count'] = (int) self::pick($in, 'signer_count', 0);

        if ($clean['signer_count'] < 1 || $clean['signer_count'] > 20) {
            $e['signer_count'] = 'How many signers does this workflow define? It must be between 1 and 20.';
        }

        $clean['ordering_mode'] = (string) self::pick($in, 'ordering_mode', '');

        if (!array_key_exists($clean['ordering_mode'], self::orderingModes())) {
            $e['ordering_mode'] = 'Choose whether this workflow signs sequentially or in parallel.';
        }

        $clean['auth_method'] = (string) self::pick($in, 'auth_method', '');

        if (!array_key_exists($clean['auth_method'], self::authMethods())) {
            $e['auth_method'] = 'Choose the authentication this workflow applies.';
        }

        $clean['kyc_policy'] = (string) self::pick($in, 'kyc_policy', '');

        if (!array_key_exists($clean['kyc_policy'], self::kycPolicies())) {
            $e['kyc_policy'] = 'Choose the Video KYC policy for contracts sent through this workflow.';
        }

        /* Roles, in signing order, as the workflow defines them. */
        $roles = self::pick($in, 'signer_roles', array());

        if (is_string($roles)) {
            $roles = array_filter(array_map('trim', explode(',', $roles)), 'strlen');
        }

        $roles = is_array($roles) ? array_values($roles) : array();
        $clean['signer_roles'] = $roles;

        if (!isset($e['signer_count']) && count($roles) !== $clean['signer_count']) {
            $e['signer_roles'] = 'List one role per signer, in signing order — '
                               . $clean['signer_count'] . ' expected, ' . count($roles) . ' given.';
        }

        /*
         * A KYC policy that requires verification while no verification provider
         * exists is not rejected here — it is a legitimate declaration of intent
         * and the completion gate enforces it honestly by refusing to complete.
         * Rejecting it would push people towards declaring `none` to make
         * contracts finish, which is the outcome this whole design exists to
         * prevent.
         */

        return array('ok' => count($e) === 0, 'errors' => $e, 'clean' => $clean);
    }

    /* ---- the check that actually protects a send ------------------------- */

    /**
     * Does this roster map deterministically onto this profile?
     *
     * Returns REASONS rather than a boolean, because a refused send has to tell
     * somebody what to change. A send refused with "cannot map" gets overridden;
     * a send refused with "this workflow expects 3 signers and the roster has 2"
     * gets fixed.
     *
     * The rules:
     *
     *   - the signer COUNT must match exactly. Fewer means somebody is never
     *     invited; more means somebody is silently dropped.
     *   - the signing order must be a complete 1..N run with no gaps and no
     *     duplicates. A gap in a SEQUENTIAL workflow stalls the contract at the
     *     missing slot with no error anywhere.
     *   - every roster role must match the workflow's role for that slot, when
     *     the workflow declares roles. This is what stops the lender signing in
     *     the borrower's position.
     *   - nothing may be sent through a profile whose id does not even look like
     *     one.
     *
     * @param  array $profile declaration
     * @param  array $signers LIVE roster rows, any order
     * @return array {ok, blockers:[{code,message}], mapping:[{signing_order, role, signer}]}
     */
    public static function mapRoster(array $profile, array $signers)
    {
        $blockers = array();
        $mapping  = array();

        $profileId = trim((string) self::pick($profile, 'profile_id', ''));

        if (!self::looksValid($profileId)) {
            $blockers[] = self::blocker('profile_not_configured',
                'No valid Leegality Workflow ID is configured for this contract. An administrator '
              . 'has to set one before anything can be sent.');

            /* Without a profile there is nothing to map against, so stop here
               rather than emitting a cascade of derived complaints. */
            return array('ok' => false, 'blockers' => $blockers, 'mapping' => array());
        }

        $expected = (int) self::pick($profile, 'signer_count', 0);
        $actual   = count($signers);

        if ($expected < 1) {
            $blockers[] = self::blocker('profile_signer_count_unknown',
                'The configured workflow does not say how many signers it defines, so the roster '
              . 'cannot be checked against it.');
        } elseif ($actual !== $expected) {
            $blockers[] = self::blocker('signer_count_mismatch',
                sprintf('This workflow defines %d signer%s and the roster has %d. Sending would '
                      . 'either leave somebody uninvited or drop somebody silently.',
                    $expected, $expected === 1 ? '' : 's', $actual));
        }

        /* Order integrity, independent of the count check above. */
        $orders = array();

        foreach ($signers as $s) {
            $orders[] = (int) self::pick($s, 'signing_order', 0);
        }

        sort($orders);

        if (count($orders) > 0) {
            if (count(array_unique($orders)) !== count($orders)) {
                $blockers[] = self::blocker('duplicate_signing_order',
                    'Two signers hold the same position in the signing order.');
            }

            $expectedRun = range(1, count($orders));

            if (array_values(array_unique($orders)) !== $expectedRun) {
                $blockers[] = self::blocker('signing_order_has_gaps',
                    'The signing order is not a complete run from 1. In a sequential workflow a gap '
                  . 'stalls the contract at the missing position, with no error anywhere.');
            }
        }

        /* Role agreement, slot by slot. */
        $roles = self::pick($profile, 'signer_roles', array());
        $roles = is_array($roles) ? array_values($roles) : array();

        $bySlot = array();

        foreach ($signers as $s) {
            $bySlot[(int) self::pick($s, 'signing_order', 0)] = $s;
        }

        ksort($bySlot);

        foreach ($bySlot as $slot => $s) {
            $want = isset($roles[$slot - 1]) ? trim((string) $roles[$slot - 1]) : '';
            $have = trim((string) self::pick($s, 'role', ''));

            if ($want !== '' && $have !== '' && strcasecmp($want, $have) !== 0) {
                $blockers[] = self::blocker('role_mismatch_at_slot_' . $slot,
                    sprintf('Position %d: the workflow expects "%s" and the roster has "%s".',
                        $slot, $want, $have));
            }

            $mapping[] = array(
                'signing_order' => $slot,
                'role'          => $want !== '' ? $want : $have,
                'name'          => (string) self::pick($s, 'full_name', ''),
                'email'         => (string) self::pick($s, 'email', ''),
            );
        }

        if ($actual === 0) {
            $blockers[] = self::blocker('no_signers',
                'There are no live signers on this contract.');
        }

        return array(
            'ok'       => count($blockers) === 0,
            'blockers' => $blockers,
            'mapping'  => $mapping,
        );
    }

    /**
     * Which signers this profile's KYC policy requires verification for.
     *
     * @param  array $profile
     * @param  array $signers
     * @return array emails
     */
    public static function kycRequiredFor(array $profile, array $signers)
    {
        $policy = (string) self::pick($profile, 'kyc_policy', self::KYC_NONE);
        $out    = array();

        /*
         * There is deliberately NO early return for a `none` policy.
         *
         * There used to be, and a test caught it: returning early skipped the
         * per-signer loop below, so a signer explicitly flagged as requiring
         * verification was silently exempted whenever the workflow's policy said
         * `none`. The flag is supposed to be able to ADD to the policy; with the
         * early return it could only ever agree with it, which made it a control
         * that appeared to work and did nothing — the exact defect shape this
         * project keeps producing.
         */
        if ($policy !== self::KYC_NONE) {
            foreach ($signers as $s) {
                $email = trim((string) self::pick($s, 'email', ''));

                if ($email === '') { continue; }

                if ($policy === self::KYC_ALL_SIGNERS) {
                    $out[] = $email;
                    continue;
                }

                if ($policy === self::KYC_CUSTOMER_ONLY
                    && strcasecmp((string) self::pick($s, 'party', ''), 'customer') === 0) {
                    $out[] = $email;
                }
            }
        }

        /*
         * A signer's own kyc_required flag can only ADD to the policy, never
         * remove. A per-row checkbox that could switch verification off would be
         * a way around the profile's policy, reachable by anyone who can edit
         * the roster.
         */
        foreach ($signers as $s) {
            if (!empty($s['kyc_required'])) {
                $email = trim((string) self::pick($s, 'email', ''));

                if ($email !== '' && !in_array($email, $out, true)) { $out[] = $email; }
            }
        }

        return $out;
    }

    /* ---- helpers --------------------------------------------------------- */

    private static function blocker($code, $message)
    {
        return array('code' => $code, 'message' => $message);
    }

    private static function pick($a, $k, $default)
    {
        if (is_array($a) && array_key_exists($k, $a)) { return $a[$k]; }

        return $default;
    }
}
