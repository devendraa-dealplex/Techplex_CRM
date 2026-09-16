<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_signer
 *
 * What is sent about a person, what is not, and how they are referred to in a
 * URL a stranger can see.
 *
 * THE RULE THAT SHAPES THIS FILE
 * ------------------------------
 * Internal database ids never appear in a public signing URL. Not because an
 * id is secret, but because a sequential id is an invitation: change 41 to 42
 * and see whose contract loads. Every public reference here is a random opaque
 * token with no relationship to the row it points at, so guessing gets nowhere
 * and enumeration gets nothing.
 *
 * MINIMISATION IS A DESIGN CONSTRAINT, NOT A PREFERENCE
 * -----------------------------------------------------
 * Only the fields the selected workflow actually requires are sent. Sending
 * "everything we have, in case they want it" means a third party holds a
 * customer's address, GST number and internal notes because it was convenient
 * — and every one of those becomes their retention problem and our disclosure.
 *
 * Pure: no database, no randomness at construction — the reference generator
 * is the one call that needs entropy and it is isolated.
 */
class Contract_signer
{
    /* ---- roles --------------------------------------------------------- */

    const ROLE_CUSTOMER = 'customer';
    const ROLE_COMPANY  = 'company';
    const ROLE_WITNESS  = 'witness';
    const ROLE_GUARANTOR = 'guarantor';

    /* ---- signing order ------------------------------------------------- */

    const ORDER_PARALLEL   = 'parallel';
    const ORDER_SEQUENTIAL = 'sequential';

    /**
     * The fields that may be sent to the provider about a signer.
     *
     * `required` means the workflow cannot proceed without it. Everything else
     * is sent only when the selected workflow asks for it.
     *
     * @return array
     */
    public static function transmittableFields()
    {
        return array(
            'reference'             => array('required' => true,
                'means' => 'Our opaque signer token. Not a database id.'),
            'name'                  => array('required' => true,
                'means' => 'Full legal name, as it should appear on the executed document.'),
            'email'                 => array('required' => true,
                'means' => 'Where the invitation goes.'),
            'mobile'                => array('required' => false,
                'means' => 'Verified mobile, in E.164. Required by some signing methods.'),
            'role'                  => array('required' => true, 'means' => 'Customer, company, witness.'),
            'signing_order'         => array('required' => true, 'means' => 'Position in a sequential flow.'),
            'signature_method'      => array('required' => true,
                'means' => 'Only a method confirmed enabled on the account.'),
            'consent_language'      => array('required' => true,
                'means' => 'The language the consent text is presented in.'),
            'required_fields'       => array('required' => true,
                'means' => 'Which signature fields this signer must complete.'),
            'redirect_url'          => array('required' => false,
                'means' => 'Where the signer lands afterwards. Carries no identifier.'),
            'notification_preference' => array('required' => false,
                'means' => 'Email, SMS or both — subject to what the account has enabled.'),
        );
    }

    /**
     * Fields that must NEVER be transmitted to the signing provider.
     *
     * Stated as a list so a test can assert the payload builder never includes
     * one, rather than relying on nobody adding a convenient extra later.
     *
     * @return array
     */
    public static function neverTransmitted()
    {
        return array(
            'id', 'signer_id', 'contract_id', 'client_id', 'staff_id',   // internal ids
            'aadhaar', 'aadhaar_number', 'pan', 'pan_number',            // identity numbers
            'gst', 'gstin',
            'bank_account', 'ifsc',
            'internal_notes', 'credit_rating', 'lead_source',
            'password', 'api_key', 'webhook_secret',
        );
    }

    /**
     * Generate an opaque public reference for a signer.
     *
     * 128 bits from a cryptographic source. Not a UUIDv4 formatted by hand, not
     * `uniqid()`, not a hash of the row id — the last is the tempting one and
     * it is reversible by anyone who can guess the input space, which for a
     * sequential id is every integer up to a few million.
     *
     * @return string 32 lowercase hex characters
     */
    public static function newReference()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Is this a reference this module issued?
     *
     * @param  mixed $ref
     * @return bool
     */
    public static function isReference($ref)
    {
        return is_string($ref) && preg_match('/^[a-f0-9]{32}$/', $ref) === 1;
    }

    /**
     * Does this URL leak an internal identifier?
     *
     * Checks a constructed signing or redirect URL before it is issued. The
     * patterns are the ones that actually appear: a bare numeric path segment,
     * and the obvious query parameter names.
     *
     * @param  string $url
     * @return array {ok, reason}
     */
    public static function urlIsOpaque($url)
    {
        if (!is_string($url) || $url === '') {
            return array('ok' => false, 'reason' => 'empty_url');
        }

        if (preg_match('~[?&](id|contract_id|client_id|staff_id|signer_id|user_id)=~i', $url)) {
            return array('ok' => false, 'reason' => 'internal_id_in_query_string');
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        if (preg_match('~/\d{1,9}(/|$)~', $path)) {
            return array('ok' => false, 'reason' => 'numeric_id_in_path');
        }

        if (preg_match('~[?&](email|phone|mobile|name)=~i', $url)) {
            return array('ok' => false, 'reason' => 'personal_data_in_query_string');
        }

        return array('ok' => true, 'reason' => 'opaque');
    }

    /**
     * Validate one signer before anything is sent.
     *
     * @param  array $s
     * @return array {ok, reason, errors}
     */
    public static function validate(array $s)
    {
        $errors = array();

        if (!self::isReference(isset($s['reference']) ? $s['reference'] : null)) {
            $errors[] = 'reference_missing_or_not_opaque';
        }

        $name = isset($s['name']) ? trim((string) $s['name']) : '';

        if ($name === '' || mb_strlen($name) < 2) {
            $errors[] = 'name_missing';
        }

        $email = isset($s['email']) ? trim((string) $s['email']) : '';

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = 'email_missing_or_invalid';
        }

        $role = isset($s['role']) ? (string) $s['role'] : '';

        if (!in_array($role, self::roles(), true)) {
            $errors[] = 'role_unknown';
        }

        if (!isset($s['signing_order']) || (int) $s['signing_order'] < 0) {
            $errors[] = 'signing_order_missing';
        }

        /*
         * Mobile is validated only when present, because whether it is required
         * depends on the signing method, which depends on the account's enabled
         * methods — which is documentation we do not have. Requiring it
         * unconditionally would be inventing a rule.
         */
        if (!empty($s['mobile'])) {
            if (!preg_match('/^\+[1-9][0-9]{7,14}$/', (string) $s['mobile'])) {
                $errors[] = 'mobile_not_e164';
            }
        }

        if ($errors) {
            return array('ok' => false, 'reason' => 'invalid_signer_details', 'errors' => $errors);
        }

        return array('ok' => true, 'reason' => 'valid', 'errors' => array());
    }

    /**
     * @return array
     */
    public static function roles()
    {
        return array(self::ROLE_CUSTOMER, self::ROLE_COMPANY,
                     self::ROLE_WITNESS, self::ROLE_GUARANTOR);
    }

    /**
     * Build the payload for one signer, carrying only what may be transmitted.
     *
     * Built by allow-list, not by removing the forbidden fields from the row.
     * A deny-list stops working the day somebody adds a column, and the failure
     * is a customer's identity number arriving at a third party.
     *
     * @param  array $signer full internal row
     * @return array {ok, reason, payload}
     */
    public static function toPayload(array $signer)
    {
        $v = self::validate($signer);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => $v['reason'], 'payload' => array());
        }

        $payload = array();

        foreach (self::transmittableFields() as $field => $meta) {
            if (!array_key_exists($field, $signer)) { continue; }

            $value = $signer[$field];

            if ($value === null || $value === '') { continue; }

            $payload[$field] = $value;
        }

        return array('ok' => true, 'reason' => 'built', 'payload' => $payload);
    }

    /**
     * Is a payload free of everything that must not leave?
     *
     * Asserted by the suite against real-shaped input. A belt-and-braces check
     * over the allow-list build, because the cost of being wrong here is a
     * customer's identity number in somebody else's database.
     *
     * @param  array $payload
     * @return array {ok, leaked}
     */
    public static function payloadIsClean(array $payload)
    {
        $leaked = array();

        foreach (self::neverTransmitted() as $forbidden) {
            if (array_key_exists($forbidden, $payload)) { $leaked[] = $forbidden; }
        }

        return array('ok' => !$leaked, 'leaked' => $leaked);
    }

    /**
     * Ordering rules for a set of signers.
     *
     * @param  array  $signers
     * @param  string $mode
     * @return array {ok, reason}
     */
    public static function validateOrder(array $signers, $mode)
    {
        if (!in_array($mode, array(self::ORDER_PARALLEL, self::ORDER_SEQUENTIAL), true)) {
            return array('ok' => false, 'reason' => 'unknown_ordering_mode');
        }

        if (!$signers) {
            return array('ok' => false, 'reason' => 'no_signers');
        }

        if ($mode === self::ORDER_PARALLEL) {
            return array('ok' => true, 'reason' => 'parallel_order_not_constrained');
        }

        $orders = array();

        foreach ($signers as $s) {
            $orders[] = (int) (isset($s['signing_order']) ? $s['signing_order'] : 0);
        }

        if (count(array_unique($orders)) !== count($orders)) {
            /* Two signers at the same position in a sequence is not a sequence.
               Left unchecked it becomes "whichever the provider happens to ask
               first", which differs between runs. */
            return array('ok' => false, 'reason' => 'duplicate_signing_order_in_sequential_flow');
        }

        sort($orders);

        if ($orders[0] !== 1) {
            return array('ok' => false, 'reason' => 'sequential_order_must_start_at_1');
        }

        foreach ($orders as $i => $o) {
            if ($o !== $i + 1) {
                return array('ok' => false, 'reason' => 'sequential_order_has_a_gap');
            }
        }

        return array('ok' => true, 'reason' => 'sequential_order_valid');
    }
}
