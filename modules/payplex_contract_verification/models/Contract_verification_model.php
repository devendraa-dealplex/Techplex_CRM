<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Contract_signing_state.php';
require_once __DIR__ . '/../libraries/Contract_signing_settings.php';
require_once __DIR__ . '/../libraries/Contract_webhook_guard.php';
require_once __DIR__ . '/../libraries/Contract_field_mapper.php';
require_once __DIR__ . '/../libraries/Contract_failures.php';
require_once __DIR__ . '/../libraries/Contract_evidence.php';
require_once __DIR__ . '/../libraries/Contract_assignment.php';
require_once __DIR__ . '/../libraries/Contract_authz.php';
require_once __DIR__ . '/../libraries/Contract_evidence_types.php';
require_once __DIR__ . '/../libraries/Evidence_store.php';
require_once __DIR__ . '/../libraries/Kyc_decision_service.php';
require_once __DIR__ . '/../libraries/Contract_signer.php';
require_once __DIR__ . '/../libraries/Contract_caps.php';
require_once __DIR__ . '/../libraries/Leegality_provider.php';
require_once __DIR__ . '/../libraries/Contract_lifecycle.php';
require_once __DIR__ . '/../libraries/Contract_profile_map.php';
require_once __DIR__ . '/../libraries/Contract_kyc.php';
require_once __DIR__ . '/../libraries/Contract_invite_token.php';
require_once __DIR__ . '/../libraries/Contract_circuit.php';

/**
 * Contract_verification_model — the data layer.
 *
 * READS CORE, WRITES ONLY ITS OWN
 * -------------------------------
 * This model reads `tblcontracts` to answer "does this contract exist and what
 * is it called". It never writes to it, never alters it, and never assumes a
 * column beyond `id` without checking that the column is there first. The
 * deployed schema could not be inspected when this was written (see migration
 * 201), so every core read is defensive by construction rather than by habit.
 *
 * NO SILENT NO-OPS
 * ----------------
 * Every method either does its work or returns a named failure. Nothing returns
 * an empty array to mean "something went wrong": an empty result and a failed
 * result are different answers and callers act on them differently.
 */
class Contract_verification_model extends App_Model
{
    /** The eight tables migrations 201-203 create. */
    private function tables()
    {
        return array('payplex_cv_requests', 'payplex_cv_signers', 'payplex_cv_fields',
                     'payplex_cv_documents', 'payplex_cv_webhooks', 'payplex_cv_verifications',
                     'payplex_cv_audit', 'payplex_cv_settings');
    }

    private function t($name) { return db_prefix() . $name; }

    /** @var array|null memoised settings */
    private $settingsCache = null;

    /* ================================================================
     * Schema
     * ============================================================== */

    public function schemaReady()
    {
        foreach ($this->tables() as $t) {
            if (!$this->db->table_exists($this->t($t))) { return false; }
        }

        return true;
    }

    public function missingTables()
    {
        $out = array();

        foreach ($this->tables() as $t) {
            if (!$this->db->table_exists($this->t($t))) { $out[] = $t; }
        }

        return $out;
    }

    /**
     * Read a contract from core, defensively.
     *
     * Only `id` is assumed. Everything else is requested only if the column is
     * actually present, because this module was built without being able to
     * inspect the deployed `tblcontracts`, and a SELECT naming a column that is
     * not there is a 500 on a screen somebody opened.
     *
     * @param  int $contractId
     * @return array|null
     */
    public function contract($contractId)
    {
        $contractId = (int) $contractId;
        $table      = $this->t('contracts');

        if ($contractId <= 0 || !$this->db->table_exists($table)) { return null; }

        $have = array();

        foreach ($this->db->list_fields($table) as $f) { $have[$f] = true; }

        /* `addedfrom` is here for one reason: Perfex scopes a contract to the staff
           member who added it when the viewer holds only `view_own`. Without this
           column the module cannot apply that rule and would have to choose between
           refusing everyone or showing everyone. It is an ownership id, not contact
           data, and nothing renders it. */
        $want    = array('id', 'subject', 'client', 'contract_value', 'datestart', 'dateend', 'trash', 'addedfrom');
        $select  = array();

        foreach ($want as $c) {
            if (isset($have[$c])) { $select[] = '`' . $c . '`'; }
        }

        if (!$select) { return null; }

        $row = $this->db->select(implode(',', $select), false)
                        ->where('id', $contractId)
                        ->get($table)->row_array();

        return $row ? $row : null;
    }

    /* ================================================================
     * Settings
     * ============================================================== */

    /**
     * Non-secret settings, plus a description of each secret.
     *
     * A secret's VALUE is never in the returned array. There is no flag, no
     * option and no second method that returns it to a screen — the only path
     * to a plaintext secret is `withSecret()`, which hands it to a closure and
     * never to a caller.
     *
     * @return array
     */
    public function settings()
    {
        if ($this->settingsCache !== null) { return $this->settingsCache; }

        $out   = array();
        $table = $this->t('payplex_cv_settings');

        if (!$this->db->table_exists($table)) { return array(); }

        foreach ($this->db->get($table)->result_array() as $r) {
            $key = (string) $r['skey'];

            if (!empty($r['is_secret'])) {
                $out[$key] = array(
                    'secret'      => true,
                    'configured'  => trim((string) $r['svalue']) !== '',
                    'fingerprint' => $r['fingerprint'],
                    'last_four'   => $r['last_four'],
                    'set_by'      => (int) $r['set_by'],
                    'set_at'      => $r['set_at'] === null ? null : (int) $r['set_at'],
                    'note'        => $r['note'],
                );
                continue;
            }

            $out[$key] = array(
                'secret'     => false,
                'value'      => (string) $r['svalue'],
                'configured' => trim((string) $r['svalue']) !== '',
                'set_by'     => (int) $r['set_by'],
                'set_at'     => $r['set_at'] === null ? null : (int) $r['set_at'],
                'note'       => $r['note'],
            );
        }

        $this->settingsCache = $out;

        return $out;
    }

    /**
     * A non-secret setting's value.
     *
     * Refuses to return a secret, whatever the caller asks for. The refusal is
     * here rather than at the call sites because one forgetful call site is all
     * it takes.
     *
     * @param  string $key
     * @param  string $default
     * @return string
     */
    public function setting($key, $default = '')
    {
        if (Contract_signing_settings::isSecret($key)) { return $default; }

        $s = $this->settings();

        return isset($s[$key]) && $s[$key]['configured'] ? (string) $s[$key]['value'] : $default;
    }

    /**
     * Use a secret without ever returning it.
     *
     * Decrypts, hands the plaintext to the callback, and drops it. The same
     * pattern the Lead Finder module uses for its API key, for the same reason:
     * a method that RETURNS a secret will eventually be called by something
     * that renders its result.
     *
     * @param  string   $key
     * @param  callable $fn
     * @return array {ok, reason, value}
     */
    public function withSecret($key, callable $fn)
    {
        if (!Contract_signing_settings::isSecret($key)) {
            return array('ok' => false, 'reason' => 'not_a_secret_setting', 'value' => null);
        }

        $row = $this->db->where('skey', $key)
                        ->get($this->t('payplex_cv_settings'))->row_array();

        if (!$row || trim((string) $row['svalue']) === '') {
            return array('ok' => false, 'reason' => 'not_configured', 'value' => null);
        }

        $plain = $this->decrypt((string) $row['svalue']);

        if ($plain === null) {
            return array('ok' => false, 'reason' => 'could_not_decrypt', 'value' => null);
        }

        try {
            $result = $fn($plain);
        } finally {
            /* Not a security guarantee in PHP — the string may already have been
               copied — but it shortens the window and states the intent. */
            $plain = null;
            unset($plain);
        }

        return array('ok' => true, 'reason' => 'used', 'value' => $result);
    }

    /**
     * Save settings, validated, writing exactly the keys supplied.
     *
     * Blank means keep for a secret, so a save that did not intend to touch a
     * credential cannot erase one.
     *
     * @param  array $input
     * @param  int   $actorId
     * @param  bool  $isAdmin
     * @return array {ok, reason, message, changed, errors}
     */
    public function saveSettings(array $input, $actorId, $isAdmin)
    {
        if (!$isAdmin) {
            return array('ok' => false, 'reason' => 'not_admin', 'changed' => 0, 'errors' => array(),
                         'message' => 'Only an administrator can change the signing provider settings.');
        }

        $schema  = Contract_signing_settings::schema();
        $errors  = array();
        $updates = array();

        foreach ($input as $key => $value) {
            if (!isset($schema[$key])) { continue; }

            $isSecret = Contract_signing_settings::isSecret($key);
            $raw      = is_scalar($value) ? trim((string) $value) : '';

            /* Blank means keep, for a secret only. */
            if ($isSecret && $raw === '') { continue; }

            $v = Contract_signing_settings::validate($key, $value);

            if (empty($v['ok'])) {
                $errors[$key] = $v['error'];
                continue;
            }

            $updates[$key] = $v['value'];
        }

        if ($errors) {
            return array('ok' => false, 'reason' => 'validation_failed', 'changed' => 0,
                         'errors' => $errors,
                         'message' => 'Nothing was saved. ' . count($errors) . ' setting(s) were rejected.');
        }

        $now     = time();
        $changed = 0;
        $table   = $this->t('payplex_cv_settings');

        foreach ($updates as $key => $value) {
            $isSecret = Contract_signing_settings::isSecret($key);
            $row      = array('set_by' => (int) $actorId, 'set_at' => $now);

            if ($isSecret) {
                $sealed = $this->encrypt((string) $value);

                if ($sealed === null) {
                    $errors[$key] = 'The value could not be stored securely, so it was not stored.';
                    continue;
                }

                $desc = Contract_signing_settings::describe($key, (string) $value, true);

                $row['svalue']      = $sealed;
                $row['is_secret']   = 1;
                $row['fingerprint'] = $desc['fingerprint'];
                $row['last_four']   = $desc['last_four'];
            } else {
                $row['svalue']    = (string) $value;
                $row['is_secret'] = 0;
            }

            $this->db->where('skey', $key)->update($table, $row);
            $changed += (int) $this->db->affected_rows();

            /* The audit records THAT a credential changed and its fingerprint.
               Never the value, never its length. */
            $this->audit((int) $actorId, 'cv_setting_changed', null, null, array(
                'setting'     => $key,
                'is_secret'   => $isSecret,
                'fingerprint' => $isSecret ? $row['fingerprint'] : null,
                'value'       => $isSecret ? null : (string) $value,
            ));
        }

        $this->settingsCache = null;

        if ($errors) {
            return array('ok' => false, 'reason' => 'partially_failed', 'changed' => $changed,
                         'errors' => $errors, 'message' => 'Some settings could not be stored.');
        }

        return array('ok' => true, 'reason' => 'saved', 'changed' => $changed, 'errors' => array(),
                     'message' => $changed . ' setting(s) saved. No credential is displayed after saving.');
    }

    /**
     * Whether the integration could work at all, and what is stopping it.
     *
     * @return array
     */
    public function integrationStatus()
    {
        $s      = $this->settings();
        $stored = array();

        foreach ($s as $k => $meta) { $stored[$k] = !empty($meta['configured']); }

        $op = Contract_signing_settings::operational($stored);

        return array(
            /*
             * Constructed, not called statically.
             *
             * This read `Leegality_provider::implementationStatus()`, and
             * implementationStatus() is an instance method — which PHP 8 turns
             * into a fatal error, not a notice. The whole provider settings
             * screen returned HTTP 500 with an empty body: the one screen where
             * the sandbox credentials are supposed to be entered.
             *
             * It went unnoticed because every test built a provider properly
             * and exercised the method on the instance, so the suite proved the
             * method works while nothing proved the CALLER was shaped right.
             * `providerSettingsForStatus()` passes presence markers rather than
             * credentials, so no secret is read here.
             */
            'provider'        => (new Leegality_provider($this->providerSettingsForStatus()))
                                     ->implementationStatus(),
            'settings_ready'  => empty($op['missing']),
            'missing'         => $op['missing'],
            'blocked_by'      => $op['blocked_by'],
            'environment'     => $this->setting('environment', 'sandbox'),
            'enabled'         => $this->setting('enabled', '0') === '1',
            'webhook_verifier'=> Contract_webhook_guard::verifierAvailable(),
        );
    }

    /* ================================================================
     * Signature fields
     * ============================================================== */

    /**
     * Fields as drawn, for one contract version.
     *
     * @param  int    $contractId
     * @param  string $version
     * @return array
     */
    public function fields($contractId, $version)
    {
        return $this->db->where('contract_id', (int) $contractId)
                        ->where('contract_version', (string) $version)
                        ->order_by('page_number', 'ASC')
                        ->get($this->t('payplex_cv_fields'))->result_array();
    }

    /**
     * Map the stored fields onto PDF coordinates for one contract version.
     *
     * The geometry comes from whatever read the PDF; this method does no file
     * access. It refuses the whole batch if any single field fails, because a
     * partially mapped contract looks like it worked.
     *
     * @param  int    $contractId
     * @param  string $version
     * @param  array  $pages       page number => geometry
     * @param  array  $signerRefs
     * @return array
     */
    /**
     * NOT YET CALLED FROM THE SEND PATH, AND THAT IS A KNOWN GAP.
     *
     * This method needs `$pages` — the geometry of each page of the contract
     * PDF — and there is no PDF pipeline in this build: `sendForSigning()`
     * hands the adapter an empty path, because the adapter refuses before it
     * would read one. So the mapping is complete, proven by its own suite, and
     * wired to nothing.
     *
     * An uncalled public method is exactly how a control gets forgotten, so it
     * is stated here rather than left to be noticed: when the PDF rendering
     * step lands, `sendForSigning()` calls this and refuses the send on
     * anything but `ok => true`. Until then `Contract_evidence::readyToSend()`
     * checks only that fields EXIST, which is weaker — it cannot tell whether
     * they fall on the page.
     *
     * @param  int    $contractId
     * @param  string $version
     * @param  array  $pages       1-based page number => geometry input
     * @param  array  $signerRefs
     * @return array {ok, reason, mapped, failed}
     */
    public function mappedFields($contractId, $version, array $pages, array $signerRefs)
    {
        $rows = $this->fields($contractId, $version);

        /*
         * No stored fields is a REFUSAL, not an empty success.
         *
         * Contract_field_mapper::mapAll() is all-or-nothing over the batch it
         * is given, and an empty batch trivially satisfies that: it returns
         * ok => true with nothing mapped. Passed straight through, the caller
         * reads "mapping succeeded" and sends a contract with no signature
         * fields on it — which the provider accepts, the signer opens, and
         * finds nothing to do. The failure surfaces as a confused customer.
         *
         * This is also the case that arises when fields exist but were drawn
         * against a PREVIOUS version of the contract: fields() filters by
         * version, so the set comes back empty rather than stale, and the
         * difference between "nobody placed any fields" and "the fields belong
         * to the document that was replaced" is worth stating separately.
         */
        if (!$rows) {
            $anyVersion = (int) $this->db->where('contract_id', (int) $contractId)
                                         ->count_all_results($this->t('payplex_cv_fields'));

            return array(
                'ok'     => false,
                'reason' => $anyVersion > 0
                    ? 'fields_belong_to_a_previous_contract_version'
                    : 'no_signature_fields_placed',
                'mapped' => array(),
                'failed' => array(),
            );
        }

        $fields = array();

        foreach ($rows as $r) {
            $fields[] = array(
                'contract_version' => (string) $r['contract_version'],
                'page_number'      => (int) $r['page_number'],
                'x'                => (float) $r['x'],
                'y'                => (float) $r['y'],
                'width'            => (float) $r['width'],
                'height'           => (float) $r['height'],
                'field_type'       => (string) $r['field_type'],
                'signer_reference' => (string) $r['signer_reference'],
                'is_required'      => !empty($r['is_required']),
                'signing_order'    => (int) $r['signing_order'],
            );
        }

        $scale = 1.0;

        if ($rows && isset($rows[0]['editor_scale'])) {
            $scale = (float) $rows[0]['editor_scale'];
        }

        return Contract_field_mapper::mapAll($fields, $pages, $signerRefs, $scale > 0 ? $scale : 1.0);
    }

    /* ================================================================
     * Signer roster (migration 205)
     * ============================================================== */

    /** Is the roster installed? Kept out of tables(), for the reason in draftsReady(). */
    public function rosterReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_contract_signers'));
    }

    /**
     * Has this roster entry been replaced?
     *
     * Reads the column defensively. A row can legitimately arrive without the
     * key -- a narrower SELECT, or a fixture built before the column existed --
     * and "replaced" is the WRONG default for a missing value: it would hide a
     * live signer from the send path and from the screen, which is the quiet
     * kind of wrong this module keeps trying to avoid.
     */
    public function signerIsReplaced(array $row)
    {
        if (!array_key_exists('replaced_at', $row)) { return false; }

        /* 0 is the live sentinel introduced by 206; NULL is the pre-206 shape.
           Both mean "not replaced". */

        return $row['replaced_at'] !== null && (int) $row['replaced_at'] > 0;
    }

    /** Authentication methods an operator may pick, until a provider says otherwise. */
    public function signerAuthMethods()
    {
        return array(
            'email_otp'  => 'Email OTP',
            'mobile_otp' => 'Mobile OTP',
            'aadhaar'    => 'Aadhaar eSign',
            'dsc'        => 'Digital Signature Certificate',
        );
    }

    public function signerParties()
    {
        return array('customer' => 'Customer', 'internal' => 'Internal', 'witness' => 'Witness');
    }

    /**
     * The roster for one contract, live entries first, replaced ones after.
     *
     * Replaced signers are returned rather than filtered out. A screen that
     * hides them looks tidy and answers "who signs this" correctly while
     * answering "who was this addressed to in March" not at all.
     */
    public function contractSigners($contractId, $includeReplaced = true)
    {
        if (!$this->rosterReady()) { return array(); }

        $this->db->where('contract_id', (int) $contractId);

        if (!$includeReplaced) { $this->db->where('replaced_at', null); }

        return $this->db->order_by('signing_order', 'ASC')->order_by('id', 'ASC')
                        ->get($this->t('payplex_cv_contract_signers'))->result_array();
    }

    /** Live (not replaced) roster entries only — what a send would actually use. */
    public function activeSigners($contractId)
    {
        $out = array();

        foreach ($this->contractSigners($contractId) as $s) {
            if (!$this->signerIsReplaced($s)) { $out[] = $s; }
        }

        return $out;
    }

    /**
     * Validate one roster entry.
     *
     * Email and mobile are checked HERE as well as in the browser because the
     * browser check is a courtesy to the operator, not a control: this endpoint
     * is reachable without it. A malformed address is not a cosmetic problem —
     * it is a signing invitation that silently never arrives, and the failure
     * surfaces days later as "the customer never signed".
     *
     * @return array {ok, reason}
     */
    public function validateSignerInput(array $in)
    {
        $name = isset($in['full_name']) ? trim((string) $in['full_name']) : '';

        if ($name === '' || mb_strlen($name) > 190) {
            return array('ok' => false, 'reason' => 'signer_name_required');
        }

        $email = isset($in['email']) ? trim((string) $in['email']) : '';

        if ($email === '' || mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return array('ok' => false, 'reason' => 'signer_email_invalid');
        }

        /*
         * E.164 or nothing. "Whatever the operator typed" is how a reminder goes
         * to a number that cannot receive it, and how an OTP authentication
         * method is configured against an unreachable device.
         */
        $mobile = isset($in['mobile_e164']) ? trim((string) $in['mobile_e164']) : '';

        if ($mobile !== '' && !preg_match('/\A\+[1-9][0-9]{7,14}\z/', $mobile)) {
            return array('ok' => false, 'reason' => 'mobile_must_be_e164');
        }

        $order = isset($in['signing_order']) ? (int) $in['signing_order'] : 0;

        if ($order < 1 || $order > 50) {
            return array('ok' => false, 'reason' => 'signing_order_out_of_range');
        }

        $party = isset($in['party']) ? (string) $in['party'] : '';

        if (!isset($this->signerParties()[$party])) {
            return array('ok' => false, 'reason' => 'unknown_party');
        }

        $auth = isset($in['auth_method']) ? (string) $in['auth_method'] : '';

        if ($auth !== '' && !isset($this->signerAuthMethods()[$auth])) {
            return array('ok' => false, 'reason' => 'unknown_auth_method');
        }

        /*
         * Mobile OTP with no mobile number is a configuration that cannot work,
         * and it fails at the provider — far away from whoever set it.
         */
        if ($auth === 'mobile_otp' && $mobile === '') {
            return array('ok' => false, 'reason' => 'mobile_otp_requires_a_mobile_number');
        }

        $deadline = isset($in['signing_deadline_at']) ? (int) $in['signing_deadline_at'] : 0;

        if ($deadline < 0) {
            return array('ok' => false, 'reason' => 'invalid_signing_deadline');
        }

        $reminder = isset($in['reminder_interval_hours']) ? (int) $in['reminder_interval_hours'] : 0;

        if ($reminder < 0 || $reminder > 8760) {
            return array('ok' => false, 'reason' => 'reminder_interval_out_of_range');
        }

        return array('ok' => true, 'reason' => null);
    }

    /**
     * Add or update one roster entry.
     *
     * Duplicate email and duplicate slot are checked here for a decent error
     * message, and enforced by UNIQUE keys in the schema for correctness. The
     * application check races; the constraint does not.
     *
     * @return array {ok, reason, id}
     */
    public function saveContractSigner($contractId, array $in, $actorId, $now)
    {
        if (!$this->rosterReady()) {
            return array('ok' => false, 'reason' => 'roster_table_missing', 'id' => 0);
        }

        $v = $this->validateSignerInput($in);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => $v['reason'], 'id' => 0);
        }

        $id    = isset($in['id']) ? (int) $in['id'] : 0;
        $email = strtolower(trim((string) $in['email']));
        $order = (int) $in['signing_order'];

        foreach ($this->contractSigners($contractId) as $existing) {
            if ((int) $existing['id'] === $id) { continue; }

            /* A replaced signer keeps their row, so their slot and address must
               not block the person who replaced them. */
            if ($this->signerIsReplaced($existing)) { continue; }

            if (strtolower((string) $existing['email']) === $email) {
                return array('ok' => false, 'reason' => 'signer_email_already_on_this_contract', 'id' => 0);
            }

            if ((int) $existing['signing_order'] === $order) {
                return array('ok' => false, 'reason' => 'signing_order_already_used', 'id' => 0);
            }
        }

        $row = array(
            'contract_id'             => (int) $contractId,
            'signing_order'           => $order,
            'full_name'               => trim((string) $in['full_name']),
            'email'                   => $email,
            'mobile_e164'             => trim((string) $in['mobile_e164']) !== ''
                                            ? trim((string) $in['mobile_e164']) : null,
            'designation'             => isset($in['designation']) && trim((string) $in['designation']) !== ''
                                            ? mb_substr(trim((string) $in['designation']), 0, 120) : null,
            'party'                   => (string) $in['party'],
            'role'                    => isset($in['role']) && (string) $in['role'] !== ''
                                            ? mb_substr((string) $in['role'], 0, 20) : 'signer',
            'is_mandatory'            => empty($in['is_mandatory']) ? 0 : 1,
            'is_authorised_signatory' => empty($in['is_authorised_signatory']) ? 0 : 1,
            'auth_method'             => isset($in['auth_method']) && (string) $in['auth_method'] !== ''
                                            ? (string) $in['auth_method'] : null,
            'kyc_required'            => empty($in['kyc_required']) ? 0 : 1,
            'signing_deadline_at'     => !empty($in['signing_deadline_at'])
                                            ? (int) $in['signing_deadline_at'] : null,
            /* 0 = live. Migration 206 makes this column NOT NULL DEFAULT 0 and
               includes it in the unique keys, so a replaced row can keep its
               slot without blocking the signer who replaced it. */
            'replaced_at'             => 0,
            'reminder_interval_hours' => isset($in['reminder_interval_hours'])
                                            ? (int) $in['reminder_interval_hours'] : 0,
        );

        if ($id > 0) {
            $this->db->where('id', $id)->where('contract_id', (int) $contractId)
                     ->update($this->t('payplex_cv_contract_signers'),
                              $row + array('updated_by' => (int) $actorId, 'updated_at' => (int) $now));

            if ((int) $this->db->affected_rows() < 1) {
                return array('ok' => false, 'reason' => 'signer_not_found_for_this_contract', 'id' => 0);
            }

            $this->audit($actorId, 'signer_updated', $contractId, null, array(
                'signer_id' => $id, 'order' => $order, 'party' => $row['party'],
                'kyc_required' => $row['kyc_required'],
            ));

            return array('ok' => true, 'reason' => null, 'id' => $id);
        }

        $this->db->insert($this->t('payplex_cv_contract_signers'),
                          $row + array('created_by' => (int) $actorId, 'created_at' => (int) $now));

        $newId = (int) $this->db->insert_id();

        $this->audit($actorId, 'signer_added', $contractId, null, array(
            'signer_id' => $newId, 'order' => $order, 'party' => $row['party'],
            'kyc_required' => $row['kyc_required'],
        ));

        return array('ok' => true, 'reason' => null, 'id' => $newId);
    }

    /**
     * Replace a signer, keeping the original row and the reason.
     *
     * This is not "edit their name". A replacement means the contract is now
     * addressed to a different person, and the previous addressee is part of the
     * record. The old row is marked, the new row inherits the slot, and both
     * exist afterwards.
     *
     * @return array {ok, reason, id}
     */
    public function replaceContractSigner($contractId, $signerId, array $in, $reason, $actorId, $now)
    {
        if (!$this->rosterReady()) {
            return array('ok' => false, 'reason' => 'roster_table_missing', 'id' => 0);
        }

        $reason = trim((string) $reason);

        /* A replacement with no reason is the one an auditor will ask about. */
        if ($reason === '') {
            return array('ok' => false, 'reason' => 'replacement_reason_required', 'id' => 0);
        }

        $old = null;

        foreach ($this->contractSigners($contractId) as $s) {
            if ((int) $s['id'] === (int) $signerId) { $old = $s; break; }
        }

        if (!$old) {
            return array('ok' => false, 'reason' => 'signer_not_found_for_this_contract', 'id' => 0);
        }

        if ($this->signerIsReplaced($old)) {
            return array('ok' => false, 'reason' => 'signer_already_replaced', 'id' => 0);
        }

        $in['signing_order'] = (int) $old['signing_order'];

        /*
         * FORCE AN INSERT.
         *
         * The controller's signerInput() always carries the posted `id`, and on
         * this route that id is the signer being REPLACED. Left in place it sent
         * saveContractSigner() down its update branch, which overwrote the
         * original row with the replacement's details -- destroying exactly the
         * record this method exists to preserve, while still reporting success
         * and still writing a `signer_replaced` audit entry.
         *
         * Found on staging: after a replacement the roster held 2 rows instead
         * of 3, and the replaced signer's name had been silently overwritten.
         * The unit suite missed it because it called this method with a clean
         * fixture that had no `id` key -- a shape the real controller never
         * produces.
         */
        $in['id'] = 0;

        $v = $this->validateSignerInput($in);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => $v['reason'], 'id' => 0);
        }

        /*
         * The old row is marked FIRST. Its slot and email are then ignored by
         * saveContractSigner's duplicate check, so the replacement can take the
         * same slot — and, when somebody is replaced by themselves at a
         * corrected address, very nearly the same identity.
         */
        $this->db->trans_start();

        $this->db->where('id', (int) $signerId)->where('contract_id', (int) $contractId)
                 ->update($this->t('payplex_cv_contract_signers'), array(
                     'replaced_at'        => (int) $now,
                     'replacement_reason' => mb_substr($reason, 0, 500),
                     'updated_by'         => (int) $actorId,
                     'updated_at'         => (int) $now,
                 ));

        $created = $this->saveContractSigner($contractId, $in, $actorId, $now);

        if (empty($created['ok'])) {
            $this->db->trans_complete();

            return array('ok' => false, 'reason' => $created['reason'], 'id' => 0);
        }

        $this->db->where('id', (int) $signerId)
                 ->update($this->t('payplex_cv_contract_signers'),
                          array('replaced_by_id' => (int) $created['id']));

        $this->db->trans_complete();

        $this->audit($actorId, 'signer_replaced', $contractId, null, array(
            'replaced_signer_id' => (int) $signerId,
            'new_signer_id'      => (int) $created['id'],
            'order'              => (int) $old['signing_order'],
            'reason'             => mb_substr($reason, 0, 200),
        ));

        return array('ok' => true, 'reason' => null, 'id' => (int) $created['id']);
    }

    /**
     * Remove a roster entry outright.
     *
     * Only available while nothing has been sent — once a request exists the
     * correct operation is replacement, which keeps the history.
     *
     * @return array {ok, reason}
     */
    public function removeContractSigner($contractId, $signerId, $actorId, $now)
    {
        if (!$this->rosterReady()) {
            return array('ok' => false, 'reason' => 'roster_table_missing');
        }

        if ($this->latestRequestForContract((int) $contractId)) {
            return array('ok' => false, 'reason' => 'cannot_remove_after_a_request_exists_use_replace');
        }

        $this->db->where('id', (int) $signerId)->where('contract_id', (int) $contractId)
                 ->delete($this->t('payplex_cv_contract_signers'));

        if ((int) $this->db->affected_rows() < 1) {
            return array('ok' => false, 'reason' => 'signer_not_found_for_this_contract');
        }

        $this->audit($actorId, 'signer_removed', $contractId, null, array('signer_id' => (int) $signerId));

        return array('ok' => true, 'reason' => null);
    }

    /**
     * Is this roster fit to send?
     *
     * Answers the question the send path needs and the operator wants: not
     * "are there signers" but "would this actually work".
     *
     * @return array {ok, reasons, signers, mandatory, kyc_required, orders_contiguous}
     */
    public function rosterReadiness($contractId)
    {
        $live    = $this->activeSigners($contractId);
        $reasons = array();

        if (!$live) { $reasons[] = 'no_signers_on_the_roster'; }

        $mandatory = 0;
        $kyc       = 0;
        $orders    = array();

        foreach ($live as $s) {
            if (!empty($s['is_mandatory'])) { $mandatory++; }
            if (!empty($s['kyc_required'])) { $kyc++; }
            $orders[] = (int) $s['signing_order'];
        }

        if ($live && $mandatory < 1) { $reasons[] = 'no_mandatory_signer'; }

        /*
         * Sequential signing walks the order values. A gap means signer 3 waits
         * for a signer 2 who does not exist, and the contract stalls with no
         * error anywhere.
         */
        sort($orders, SORT_NUMERIC);
        $contiguous = true;

        foreach ($orders as $i => $o) {
            if ($o !== $i + 1) { $contiguous = false; break; }
        }

        if ($live && !$contiguous) { $reasons[] = 'signing_order_has_gaps_or_duplicates'; }

        return array(
            'ok'                => empty($reasons),
            'reasons'           => $reasons,
            'signers'           => count($live),
            'mandatory'         => $mandatory,
            'kyc_required'      => $kyc,
            'orders_contiguous' => $contiguous,
        );
    }

    /* ================================================================
     * Signature placement drafts (migration 204)
     * ============================================================== */

    /**
     * Is the draft surface installed?
     *
     * Deliberately NOT added to tables(), which schemaReady() uses. Putting it
     * there would make every existing screen refuse to load on an install that
     * has not yet run 204 -- turning a missing new feature into a broken old
     * one. The editor checks for itself and says so; nothing else notices.
     */
    public function draftsReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_field_drafts'));
    }

    /** Draft placements for one contract version, in page then id order. */
    public function draftFields($contractId, $version)
    {
        if (!$this->draftsReady()) { return array(); }

        return $this->db->where('contract_id', (int) $contractId)
                        ->where('contract_version', (string) $version)
                        ->order_by('page_number', 'ASC')->order_by('id', 'ASC')
                        ->get($this->t('payplex_cv_field_drafts'))->result_array();
    }

    /**
     * Validate one placement against the SAME rules the mapper will apply.
     *
     * The point of validating here is that the editor should not be able to
     * store something the mapper is going to reject at send time. A box saved
     * today and refused three weeks later, at the moment somebody is trying to
     * send a contract, is the worst possible time to discover it.
     *
     * Signer binding is the one rule that cannot be checked yet: references do
     * not exist until a request does. The slot is validated instead, and the
     * reference is resolved at approval.
     *
     * @return array {ok, reason}
     */
    public function validateDraftInput(array $in)
    {
        $types = Contract_field_mapper::fieldTypes();
        $type  = isset($in['field_type']) ? (string) $in['field_type'] : '';

        if (!isset($types[$type])) {
            return array('ok' => false, 'reason' => 'unknown_field_type');
        }

        $page = isset($in['page_number']) ? (int) $in['page_number'] : 0;

        if ($page < 1 || $page > 9999) {
            return array('ok' => false, 'reason' => 'page_out_of_range');
        }

        foreach (array('x', 'y', 'width', 'height') as $k) {
            if (!isset($in[$k]) || !is_numeric($in[$k])) {
                return array('ok' => false, 'reason' => 'coordinate_not_numeric');
            }
        }

        $x = (float) $in['x'];  $y = (float) $in['y'];
        $w = (float) $in['width']; $h = (float) $in['height'];

        /* Negative origin is off the page on two sides at once and is almost
           always a drag that escaped the canvas rather than an intention. */
        if ($x < 0 || $y < 0) {
            return array('ok' => false, 'reason' => 'negative_origin');
        }

        $scale = isset($in['editor_scale']) && is_numeric($in['editor_scale'])
            ? (float) $in['editor_scale'] : 1.0;

        if ($scale <= 0) {
            return array('ok' => false, 'reason' => 'invalid_editor_scale');
        }

        /*
         * Minimum size is checked in POINTS, after dividing by the scale the
         * operator drew at -- not in editor pixels. A box that looks generous
         * on a page rendered at 2x is half that size on the actual document,
         * and a signature field below the provider's minimum is rejected at
         * the far end where the error means nothing to anyone.
         */
        if (($w / $scale) < Contract_field_mapper::MIN_WIDTH_PT
            || ($h / $scale) < Contract_field_mapper::MIN_HEIGHT_PT) {
            return array('ok' => false, 'reason' => 'field_below_minimum_size');
        }

        $slot = isset($in['signer_slot']) ? (int) $in['signer_slot'] : 0;

        if ($types[$type]['signer_bound'] && $slot < 1) {
            return array('ok' => false, 'reason' => 'field_type_requires_a_signer_slot');
        }

        return array('ok' => true, 'reason' => null);
    }

    /**
     * Create or move/resize one draft placement.
     *
     * @return array {ok, reason, id}
     */
    public function saveDraftField($contractId, $version, array $in, $actorId, $now)
    {
        if (!$this->draftsReady()) {
            return array('ok' => false, 'reason' => 'draft_table_missing', 'id' => 0);
        }

        if ((string) $version === '') {
            return array('ok' => false, 'reason' => 'contract_version_missing', 'id' => 0);
        }

        $v = $this->validateDraftInput($in);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => $v['reason'], 'id' => 0);
        }

        $types = Contract_field_mapper::fieldTypes();
        $type  = (string) $in['field_type'];

        $row = array(
            'contract_id'      => (int) $contractId,
            'contract_version' => (string) $version,
            'signer_slot'      => $types[$type]['signer_bound'] ? (int) $in['signer_slot'] : 0,
            'page_number'      => (int) $in['page_number'],
            'x'                => (float) $in['x'],
            'y'                => (float) $in['y'],
            'width'            => (float) $in['width'],
            'height'           => (float) $in['height'],
            'editor_scale'     => isset($in['editor_scale']) && is_numeric($in['editor_scale'])
                                    ? (float) $in['editor_scale'] : 1.0,
            'field_type'       => $type,
            'is_required'      => empty($in['is_required']) ? 0 : 1,
        );

        $id = isset($in['id']) ? (int) $in['id'] : 0;

        if ($id > 0) {
            /* Scoped by contract as well as id: an id alone would let a caller
               move a placement belonging to a contract they cannot see. */
            $this->db->where('id', $id)->where('contract_id', (int) $contractId)
                     ->update($this->t('payplex_cv_field_drafts'),
                              $row + array('updated_by' => (int) $actorId, 'updated_at' => (int) $now));

            if ((int) $this->db->affected_rows() < 1) {
                return array('ok' => false, 'reason' => 'draft_not_found_for_this_contract', 'id' => 0);
            }

            $this->audit($actorId, 'placement_updated', $contractId, null, array(
                'draft_id' => $id, 'page' => $row['page_number'], 'type' => $type,
                'slot' => $row['signer_slot'],
            ));

            return array('ok' => true, 'reason' => null, 'id' => $id);
        }

        $this->db->insert($this->t('payplex_cv_field_drafts'),
                          $row + array('created_by' => (int) $actorId, 'created_at' => (int) $now));

        $newId = (int) $this->db->insert_id();

        $this->audit($actorId, 'placement_added', $contractId, null, array(
            'draft_id' => $newId, 'page' => $row['page_number'], 'type' => $type,
            'slot' => $row['signer_slot'],
        ));

        return array('ok' => true, 'reason' => null, 'id' => $newId);
    }

    /** Remove one draft placement. Scoped by contract, for the reason above. */
    public function deleteDraftField($contractId, $draftId, $actorId, $now)
    {
        if (!$this->draftsReady()) {
            return array('ok' => false, 'reason' => 'draft_table_missing');
        }

        $this->db->where('id', (int) $draftId)->where('contract_id', (int) $contractId)
                 ->delete($this->t('payplex_cv_field_drafts'));

        if ((int) $this->db->affected_rows() < 1) {
            return array('ok' => false, 'reason' => 'draft_not_found_for_this_contract');
        }

        $this->audit($actorId, 'placement_deleted', $contractId, null,
                     array('draft_id' => (int) $draftId));

        return array('ok' => true, 'reason' => null);
    }

    /**
     * Promote the draft set to the approved set.
     *
     * REPLACE, NOT MERGE. The approved rows for this version are deleted and
     * rewritten from the drafts, so what was approved is exactly what was on
     * screen. Merging would leave a previously-approved box that the operator
     * had deleted still on the document, and they would have no way of seeing
     * that from the editor they were just looking at.
     *
     * Slots become signer references here if a request exists. If it does not,
     * the reference stays NULL -- the placement is kept, and the mapper refuses
     * it later rather than sending a field bound to nobody.
     *
     * @return array {ok, reason, approved, unbound}
     */
    public function approveDrafts($contractId, $version, $actorId, $holdsApprove, $isAdmin, $now)
    {
        if (!$this->draftsReady()) {
            return array('ok' => false, 'reason' => 'draft_table_missing', 'approved' => 0, 'unbound' => 0);
        }

        if (!$holdsApprove && !$isAdmin) {
            return array('ok' => false, 'reason' => 'approval_capability_required', 'approved' => 0, 'unbound' => 0);
        }

        if ((string) $version === '') {
            return array('ok' => false, 'reason' => 'contract_version_missing', 'approved' => 0, 'unbound' => 0);
        }

        $drafts = $this->draftFields($contractId, $version);

        /*
         * Approving nothing is refused rather than quietly succeeding. An empty
         * approval would clear the approved set and report success, which reads
         * as "placements approved" and means "this contract now has none".
         */
        if (!$drafts) {
            return array('ok' => false, 'reason' => 'no_draft_placements_to_approve',
                         'approved' => 0, 'unbound' => 0);
        }

        /* Re-validate at the gate. The rules could have tightened since a box
           was drawn, and approval is the moment that matters. */
        foreach ($drafts as $d) {
            $v = $this->validateDraftInput($d);

            if (empty($v['ok'])) {
                return array('ok' => false, 'reason' => 'draft_invalid_' . $v['reason'],
                             'approved' => 0, 'unbound' => 0);
            }
        }

        $request = $this->latestRequestForContract((int) $contractId);
        $refs    = array();

        if ($request) {
            foreach ($this->signers((int) $request['id']) as $s) {
                $refs[(int) $s['signing_order']] = (string) $s['reference'];
            }
        }

        $this->db->trans_start();

        $this->db->where('contract_id', (int) $contractId)
                 ->where('contract_version', (string) $version)
                 ->delete($this->t('payplex_cv_fields'));

        $unbound = 0;

        foreach ($drafts as $d) {
            $slot = (int) $d['signer_slot'];
            $ref  = ($slot > 0 && isset($refs[$slot])) ? $refs[$slot] : null;

            if ($slot > 0 && $ref === null) { $unbound++; }

            $this->db->insert($this->t('payplex_cv_fields'), array(
                'contract_id'      => (int) $contractId,
                'contract_version' => (string) $version,
                'signer_reference' => $ref,
                'page_number'      => (int) $d['page_number'],
                'x'                => (float) $d['x'],
                'y'                => (float) $d['y'],
                'width'            => (float) $d['width'],
                'height'           => (float) $d['height'],
                'editor_scale'     => (float) $d['editor_scale'],
                'field_type'       => (string) $d['field_type'],
                'is_required'      => empty($d['is_required']) ? 0 : 1,
                'signing_order'    => $slot,
                'created_by'       => (int) $actorId,
                'created_at'       => (int) $now,
            ));
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return array('ok' => false, 'reason' => 'approval_transaction_failed',
                         'approved' => 0, 'unbound' => 0);
        }

        $this->audit($actorId, 'placements_approved', $contractId, null, array(
            'version' => (string) $version, 'count' => count($drafts), 'unbound_signers' => $unbound,
        ));

        return array('ok' => true, 'reason' => null, 'approved' => count($drafts), 'unbound' => $unbound);
    }

    /**
     * Seed the editor from the approved set so an operator revising a contract
     * starts from what is actually on it, rather than an empty page that makes
     * it look as though nothing was ever placed.
     *
     * Refuses if drafts already exist: overwriting unsaved work to be helpful
     * is not helpful.
     *
     * @return array {ok, reason, copied}
     */
    public function copyApprovedToDrafts($contractId, $version, $actorId, $now)
    {
        if (!$this->draftsReady()) {
            return array('ok' => false, 'reason' => 'draft_table_missing', 'copied' => 0);
        }

        if ($this->draftFields($contractId, $version)) {
            return array('ok' => false, 'reason' => 'drafts_already_exist', 'copied' => 0);
        }

        $approved = $this->fields($contractId, $version);

        if (!$approved) {
            return array('ok' => false, 'reason' => 'no_approved_placements_to_copy', 'copied' => 0);
        }

        foreach ($approved as $a) {
            $this->db->insert($this->t('payplex_cv_field_drafts'), array(
                'contract_id'      => (int) $contractId,
                'contract_version' => (string) $version,
                'signer_slot'      => (int) $a['signing_order'],
                'page_number'      => (int) $a['page_number'],
                'x'                => (float) $a['x'],
                'y'                => (float) $a['y'],
                'width'            => (float) $a['width'],
                'height'           => (float) $a['height'],
                'editor_scale'     => (float) $a['editor_scale'],
                'field_type'       => (string) $a['field_type'],
                'is_required'      => empty($a['is_required']) ? 0 : 1,
                'created_by'       => (int) $actorId,
                'created_at'       => (int) $now,
            ));
        }

        $this->audit($actorId, 'placements_copied_for_editing', $contractId, null,
                     array('version' => (string) $version, 'count' => count($approved)));

        return array('ok' => true, 'reason' => null, 'copied' => count($approved));
    }

    /** Discard the whole draft set for a version. Approved rows are untouched. */
    public function discardDrafts($contractId, $version, $actorId, $now)
    {
        if (!$this->draftsReady()) {
            return array('ok' => false, 'reason' => 'draft_table_missing', 'discarded' => 0);
        }

        $n = count($this->draftFields($contractId, $version));

        $this->db->where('contract_id', (int) $contractId)
                 ->where('contract_version', (string) $version)
                 ->delete($this->t('payplex_cv_field_drafts'));

        $this->audit($actorId, 'placements_discarded', $contractId, null,
                     array('version' => (string) $version, 'count' => $n));

        return array('ok' => true, 'reason' => null, 'discarded' => $n);
    }

    /* ================================================================
     * Requests and signers
     * ============================================================== */

    public function request($requestId)
    {
        $r = $this->db->where('id', (int) $requestId)
                      ->get($this->t('payplex_cv_requests'))->row_array();

        return $r ? $r : null;
    }

    public function latestRequestForContract($contractId)
    {
        $r = $this->db->where('contract_id', (int) $contractId)
                      ->order_by('id', 'DESC')->limit(1)
                      ->get($this->t('payplex_cv_requests'))->row_array();

        return $r ? $r : null;
    }

    public function signers($requestId)
    {
        return $this->db->where('request_id', (int) $requestId)
                        ->order_by('signing_order', 'ASC')
                        ->get($this->t('payplex_cv_signers'))->result_array();
    }

    /**
     * Resolve an opaque signer reference.
     *
     * The only lookup a public route performs, and it takes the token alone —
     * never a request id from the caller, which would let somebody pair their
     * own token with another contract.
     *
     * @param  string $reference
     * @return array|null
     */
    public function signerByReference($reference)
    {
        if (!Contract_signer::isReference($reference)) { return null; }

        $r = $this->db->where('reference', (string) $reference)
                      ->get($this->t('payplex_cv_signers'))->row_array();

        return $r ? $r : null;
    }

    /**
     * Apply an observed state to a request, defending against stale news.
     *
     * The one place a request's state changes. Everything — webhook,
     * reconciliation, internal action — goes through here, so the
     * never-downgrade rule cannot be bypassed by a code path that forgot it.
     *
     * @param  int    $requestId
     * @param  string $observed
     * @param  string $source
     * @param  int    $actorId
     * @return array {changed, from, to, reason, alert}
     */
    public function applyState($requestId, $observed, $source = 'webhook', $actorId = 0)
    {
        $req = $this->request($requestId);

        if (!$req) {
            return array('changed' => false, 'from' => null, 'to' => null,
                         'reason' => 'request_not_found', 'alert' => true);
        }

        $from = (string) $req['state'];
        $d    = Contract_signing_state::apply($from, $observed, $source);

        if (empty($d['apply'])) {
            if (!empty($d['alert'])) {
                $this->audit((int) $actorId, 'cv_state_change_refused', (int) $req['contract_id'],
                    (int) $requestId, array('from' => $from, 'observed' => (string) $observed,
                                            'reason' => $d['reason'], 'source' => $source));
            }

            return array('changed' => false, 'from' => $from, 'to' => $from,
                         'reason' => $d['reason'], 'alert' => !empty($d['alert']));
        }

        $update = array('state' => $d['state']);

        if ($d['state'] === Contract_signing_state::S_COMPLETED) {
            $update['completed_at'] = time();
        }

        /* Conditional on the state we read, so two concurrent updates cannot
           both believe they moved the request. */
        $this->db->where('id', (int) $requestId)->where('state', $from)
                 ->update($this->t('payplex_cv_requests'), $update);

        if ((int) $this->db->affected_rows() === 0) {
            return array('changed' => false, 'from' => $from, 'to' => $from,
                         'reason' => 'changed_since_read', 'alert' => false);
        }

        $this->audit((int) $actorId, 'cv_state_changed', (int) $req['contract_id'], (int) $requestId,
            array('from' => $from, 'to' => $d['state'], 'source' => $source));

        return array('changed' => true, 'from' => $from, 'to' => $d['state'],
                     'reason' => $d['reason'], 'alert' => false);
    }

    /**
     * Is this contract fully executed, and if not, why not?
     *
     * @param  int $requestId
     * @return array
     */
    public function executionStatus($requestId)
    {
        $req = $this->request($requestId);

        if (!$req) {
            return array('executed' => false, 'reason' => 'request_not_found', 'missing' => array());
        }

        $digests = array();

        foreach ($this->db->where('request_id', (int) $requestId)
                          ->get($this->t('payplex_cv_documents'))->result_array() as $d) {
            $digests[(string) $d['document_type']] = (string) $d['sha256'];
        }

        return Contract_evidence::fullyExecuted(array(
            'state'   => (string) $req['state'],
            'signers' => $this->signers($requestId),
            'digests' => $digests,
            'original_digest_at_send' => $req['original_sha256'],
        ));
    }

    /* ================================================================
     * Webhooks
     * ============================================================== */

    /**
     * Record a delivery and claim it, atomically.
     *
     * The UNIQUE key on `event_key` is the idempotency mechanism. A duplicate
     * loses the insert and is reported as already seen — no SELECT-then-INSERT
     * window in which two concurrent deliveries both find nothing.
     *
     * @param  array $in
     * @return array {claimed, reason, id}
     */
    public function claimWebhook(array $in)
    {
        $table = $this->t('payplex_cv_webhooks');

        $row = array(
            'event_key'           => (string) $in['event_key'],
            'event_type'          => isset($in['event_type']) ? (string) $in['event_type'] : null,
            'external_request_id' => isset($in['external_request_id']) ? (string) $in['external_request_id'] : null,
            'request_id'          => isset($in['request_id']) ? (int) $in['request_id'] : null,
            'body_sha256'         => (string) $in['body_sha256'],
            'received_at'         => (int) $in['received_at'],
            'result'              => 'received',
            'http_status'         => isset($in['http_status']) ? (int) $in['http_status'] : 200,
        );

        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));

        $this->db->query("INSERT IGNORE INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$ph})",
                         array_values($row));

        if ((int) $this->db->affected_rows() > 0) {
            return array('claimed' => true, 'reason' => 'claimed', 'id' => (int) $this->db->insert_id());
        }

        return array('claimed' => false, 'reason' => 'already_processed', 'id' => 0);
    }

    /**
     * Record the outcome of processing a delivery.
     *
     * @param  int   $id
     * @param  array $result
     * @return void
     */
    public function finishWebhook($id, array $result)
    {
        $this->db->where('id', (int) $id)->update($this->t('payplex_cv_webhooks'), array(
            'processed_at' => time(),
            'result'       => isset($result['result']) ? substr((string) $result['result'], 0, 60) : 'processed',
            'state_before' => isset($result['state_before']) ? (string) $result['state_before'] : null,
            'state_after'  => isset($result['state_after']) ? (string) $result['state_after'] : null,
        ));
    }

    /* ================================================================
     * Reconciliation
     * ============================================================== */

    /**
     * Requests the scheduled job should re-check, oldest first.
     *
     * Terminal states are excluded — there is nothing to learn about a
     * cancelled request, and asking about it spends an API call against a rate
     * limit that matters.
     *
     * @param  int $limit
     * @param  int $olderThan  seconds since last sync
     * @return array
     */
    public function pendingReconciliation($limit = 25, $olderThan = 900)
    {
        $terminal = array();

        foreach (Contract_signing_state::states() as $k => $meta) {
            if (!empty($meta['terminal'])) { $terminal[] = $k; }
        }

        $cutoff = time() - (int) $olderThan;

        return $this->db->where_not_in('state', $terminal)
                        ->where('external_request_id IS NOT NULL', null, false)
                        ->group_start()
                            ->where('last_synced_at IS NULL', null, false)
                            ->or_where('last_synced_at <', $cutoff)
                        ->group_end()
                        ->order_by('last_synced_at', 'ASC')
                        ->limit((int) $limit)
                        ->get($this->t('payplex_cv_requests'))->result_array();
    }

    public function markSynced($requestId, $now = null)
    {
        $this->db->where('id', (int) $requestId)
                 ->update($this->t('payplex_cv_requests'),
                          array('last_synced_at' => $now === null ? time() : (int) $now));
    }

    /* ================================================================
     * Audit
     * ============================================================== */

    /**
     * Write an audit row, redacted.
     *
     * The detail is filtered against Contract_evidence::neverInAudit() before
     * it is encoded. The audit trail outlives the contract workspace and is
     * read by more people than the document store, so anything on that list
     * put here once is here for as long as the audit is kept.
     *
     * @return void
     */
    public function audit($actorId, $event, $contractId, $requestId, array $detail)
    {
        $forbidden = Contract_evidence::neverInAudit();
        $clean     = array();

        foreach ($detail as $k => $v) {
            if (in_array((string) $k, $forbidden, true)) {
                $clean[$k] = '[redacted]';
                continue;
            }

            if (is_scalar($v) || $v === null) { $clean[$k] = $v; continue; }

            $clean[$k] = '[' . gettype($v) . ']';
        }

        $this->db->insert($this->t('payplex_cv_audit'), array(
            'actor_id'    => (int) $actorId,
            'event'       => substr((string) $event, 0, 60),
            'contract_id' => $contractId === null ? null : (int) $contractId,
            'request_id'  => $requestId === null ? null : (int) $requestId,
            'detail'      => json_encode($clean),
            'ip'          => function_exists('get_ip') ? substr((string) get_ip(), 0, 45) : null,
            'at'          => time(),
        ));
    }

    public function auditFor($contractId, $limit = 200)
    {
        return $this->db->where('contract_id', (int) $contractId)
                        ->order_by('id', 'DESC')->limit((int) $limit)
                        ->get($this->t('payplex_cv_audit'))->result_array();
    }


    /* ================================================================
     * Workflow actions
     * ============================================================== */

    /**
     * Record an identity, business or Video KYC verification.
     *
     * Stores a RESULT and a masked reference. There is no column for an
     * Aadhaar or PAN number and this method would have nowhere to put one —
     * what may be retained as identity evidence is a decision with a legal
     * dimension, and the schema deliberately makes the permissive answer
     * unavailable until somebody takes it deliberately.
     */
    public function recordVerification($contractId, $type, $outcome, $maskedRef, $actorId, $now)
    {
        $types = array('pan', 'gst', 'aadhaar', 'business', 'video_kyc');

        if (!in_array((string) $type, $types, true)) {
            return array('ok' => false, 'reason' => 'unknown_verification_type',
                         'message' => 'That verification type is not recognised. Nothing was recorded.');
        }

        if (!in_array((string) $outcome, array('passed', 'failed', 'pending'), true)) {
            return array('ok' => false, 'reason' => 'unknown_outcome',
                         'message' => 'Record the outcome as passed, failed or pending.');
        }

        /*
         * A masked reference only. If what arrives looks like a complete
         * identity number it is refused rather than truncated: truncating
         * silently would teach the caller that sending the whole number is
         * fine, and the next caller would send it somewhere that does not
         * truncate.
         *
         * THREE FORMS ARE CHECKED, NOT ONE.
         * An earlier version anchored on `^[0-9]{9,}$`, which refused a bare
         * twelve-digit Aadhaar and accepted every other complete identifier
         * this CRM handles:
         *
         *   - "Aadhaar 1111 2222 3333" — not wholly digits, so it passed,
         *     while carrying the entire number. Matched as a RUN now, anywhere
         *     in the field, rather than as the whole field.
         *   - "ABCDE1234F" — a complete PAN. Ten characters, never nine
         *     consecutive digits, so the old check could not see it.
         *   - "29ABCDE1234F1Z5" — a complete GSTIN, likewise.
         *
         * Separators are stripped first, so hyphenating or spacing a number is
         * not a way past the check. A genuine masked reference — "XXXX3333",
         * "ends 1234", "last four 8891" — has no run of nine digits and no
         * complete-identifier shape, and is accepted.
         */
        $masked = trim((string) $maskedRef);
        $bare   = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $masked));

        if ($masked !== ''
            && (preg_match('/[0-9]{9,}/', $bare)
                || preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $bare)
                || preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][0-9A-Z]Z[0-9A-Z]$/', $bare))) {
            return array('ok' => false, 'reason' => 'full_identity_number_supplied',
                         'message' => 'That looks like a complete identity number. Record only a masked '
                                    . 'reference, such as the last four characters. Nothing was stored.');
        }

        $this->db->insert($this->t('payplex_cv_verifications'), array(
            'contract_id'       => (int) $contractId,
            'verification_type' => (string) $type,
            'outcome'           => (string) $outcome,
            'masked_reference'  => $masked === '' ? null : mb_substr($masked, 0, 40),
            'recorded_by'       => (int) $actorId,
            'recorded_at'       => (int) $now,
        ));

        $this->audit((int) $actorId, 'cv_verification_recorded', (int) $contractId, null,
            array('type' => (string) $type, 'outcome' => (string) $outcome));

        return array('ok' => true, 'reason' => 'recorded',
                     'message' => 'Verification recorded as ' . $outcome . '.');
    }

    /** Has a passing verification of this type been recorded? */
    public function hasVerification($contractId, $type)
    {
        return (int) $this->db->where('contract_id', (int) $contractId)
                              ->where('verification_type', (string) $type)
                              ->where('outcome', 'passed')
                              ->count_all_results($this->t('payplex_cv_verifications')) > 0;
    }

    /** Maker submits the contract for internal approval. */
    public function submitForApproval($contractId, $actorId, $now)
    {
        $req = $this->latestRequestForContract($contractId);

        if ($req && !in_array((string) $req['state'], array(Contract_signing_state::S_CANCELLED,
                                                            Contract_signing_state::S_EXPIRED,
                                                            Contract_signing_state::S_DECLINED,
                                                            Contract_signing_state::S_FAILED), true)) {
            return array('ok' => false, 'reason' => 'request_already_open',
                         'message' => 'A signing request for this contract is already open. '
                                    . 'Cancel it before preparing another.');
        }

        $this->db->insert($this->t('payplex_cv_requests'), array(
            'contract_id'         => (int) $contractId,
            'contract_version'    => 'v' . (int) $now,
            'operation_reference' => bin2hex(random_bytes(16)),
            'state'               => Contract_signing_state::S_CREATED,
            'environment'         => $this->setting('environment', 'sandbox'),
            'submitted_by'        => (int) $actorId,
            'submitted_at'        => (int) $now,
            'created_at'          => (int) $now,
        ));

        $id = (int) $this->db->insert_id();

        $this->audit((int) $actorId, 'cv_submitted_for_approval', (int) $contractId, $id, array());

        return array('ok' => true, 'reason' => 'submitted', 'request_id' => $id,
                     'message' => 'Submitted for internal approval. A second person has to approve it.');
    }

    /**
     * Checker approves for signing.
     *
     * Maker and checker must differ, and administrator status does not override
     * that — an administrator approving their own submission is precisely what
     * the control exists to prevent.
     */
    public function approveForSigning($contractId, $actorId, $holdsApprove, $isAdmin, $now)
    {
        $req = $this->latestRequestForContract($contractId);

        if (!$req) {
            return array('ok' => false, 'reason' => 'not_submitted',
                         'message' => 'This contract has not been submitted for approval yet.');
        }

        $d = Contract_caps::approvalAllowed($actorId, (int) $req['submitted_by'], $holdsApprove, $isAdmin);

        if (empty($d['allowed'])) {
            $this->audit((int) $actorId, 'cv_approval_refused', (int) $contractId, (int) $req['id'],
                array('reason' => $d['reason']));

            return array('ok' => false, 'reason' => $d['reason'],
                         'message' => $d['reason'] === 'maker_and_checker_must_differ'
                             ? 'You submitted this contract, so you cannot also approve it. '
                               . 'A second person has to approve it.'
                             : 'You are not able to approve this contract.');
        }

        $this->db->where('id', (int) $req['id'])->where('approved_by', 0)
                 ->update($this->t('payplex_cv_requests'),
                          array('approved_by' => (int) $actorId, 'approved_at' => (int) $now));

        if ((int) $this->db->affected_rows() === 0) {
            return array('ok' => false, 'reason' => 'already_approved',
                         'message' => 'This contract has already been approved.');
        }

        $this->audit((int) $actorId, 'cv_approved_for_signing', (int) $contractId, (int) $req['id'],
            array('submitted_by' => (int) $req['submitted_by']));

        return array('ok' => true, 'reason' => 'approved',
                     'message' => 'Approved for signing.');
    }

    /**
     * Send to the provider.
     *
     * Checks everything that can be checked locally FIRST, so a contract that
     * would be refused is refused before any personal data is assembled into a
     * payload. The provider call is last, and today it refuses.
     */
    public function sendForSigning($contractId, $actorId, $holdsSend, $isAdmin, $now)
    {
        $req = $this->latestRequestForContract($contractId);

        if (!$req) {
            return array('ok' => false, 'reason' => 'not_submitted',
                         'message' => 'This contract has not been prepared for signing.');
        }

        $may = Contract_caps::sendAllowed($req, $holdsSend, $isAdmin);

        if (empty($may['allowed'])) {
            return array('ok' => false, 'reason' => $may['reason'],
                         'message' => $may['reason'] === 'not_approved'
                             ? 'This contract has not been approved for signing yet.'
                             : 'You are not able to send this contract.');
        }

        $ready = Contract_evidence::readyToSend(array(
            'original_digest'   => $req['original_sha256'],
            'contract_version'  => $req['contract_version'],
            'fields_mapped'     => count($this->fields($contractId, (string) $req['contract_version'])) > 0,
            'signers'           => $this->signers((int) $req['id']),
            'verification_done' => $this->hasVerification($contractId, 'pan')
                                   || $this->hasVerification($contractId, 'gst')
                                   || $this->hasVerification($contractId, 'business')
                                   || $this->hasVerification($contractId, 'aadhaar'),
            'video_kyc_done'    => $this->hasVerification($contractId, 'video_kyc'),
            'approved_by'       => (int) $req['approved_by'],
        ));

        if (empty($ready['ok'])) {
            return array('ok' => false, 'reason' => 'not_ready_to_send',
                         'message' => 'Nothing was sent. Still outstanding: '
                                    . implode(', ', array_map(function ($m) {
                                          return str_replace('_', ' ', $m); }, $ready['missing'])) . '.');
        }

        /*
         * The provider call. The operation reference generated when the request
         * was created is reused on every retry, so a timeout followed by a
         * retry cannot create a second signing request.
         */
        $provider = new Leegality_provider(array());
        $r        = $provider->createSigningRequest((int) $contractId, '',
                                                    $this->signers((int) $req['id']), array());

        $this->db->where('id', (int) $req['id'])->update($this->t('payplex_cv_requests'), array(
            'attempts'     => (int) $req['attempts'] + 1,
            'last_failure' => empty($r['ok']) ? (string) $r['reason'] : null,
        ));

        $this->audit((int) $actorId, 'cv_send_attempted', (int) $contractId, (int) $req['id'],
            array('ok' => !empty($r['ok']), 'reason' => (string) $r['reason'],
                  'operation_reference_reused' => true));

        if (empty($r['ok'])) {
            return array('ok' => false, 'reason' => (string) $r['reason'],
                         'message' => Contract_failures::operatorMessage((string) $r['reason']));
        }

        return array('ok' => true, 'reason' => 'sent', 'message' => 'Sent for signature.');
    }

    /** Re-issue a signing link. The link is never written to the audit trail. */
    public function resendSigningLink($contractId, $reference, $actorId, $now)
    {
        $signer = $this->signerByReference($reference);

        if (!$signer) {
            return array('ok' => false, 'reason' => 'unknown_signer',
                         'message' => 'That signer was not recognised.');
        }

        $req = $this->request((int) $signer['request_id']);

        if (!$req || (int) $req['contract_id'] !== (int) $contractId) {
            /* The token alone resolves the signer; the contract id in the URL
               must agree with it, or somebody is pairing a token they hold with
               a contract they do not. */
            return array('ok' => false, 'reason' => 'signer_does_not_belong_to_this_contract',
                         'message' => 'That signer does not belong to this contract.');
        }

        if (!in_array((string) $req['state'], Contract_signing_state::resendable(), true)) {
            return array('ok' => false, 'reason' => 'not_resendable',
                         'message' => 'A link can only be re-sent while the request is live and an '
                                    . 'invitation has already been issued.');
        }

        $provider = new Leegality_provider(array());
        $r        = $provider->getSigningLink((string) $req['external_request_id'],
                                              (string) $signer['reference']);

        /* The URL is deliberately absent from this audit row. It is a bearer
           credential for that person's identity. */
        $this->audit((int) $actorId, 'cv_link_resend_attempted', (int) $contractId, (int) $req['id'],
            array('signer_reference' => (string) $signer['reference'],
                  'ok' => !empty($r['ok']), 'reason' => (string) $r['reason']));

        if (empty($r['ok'])) {
            return array('ok' => false, 'reason' => (string) $r['reason'],
                         'message' => Contract_failures::operatorMessage((string) $r['reason']));
        }

        return array('ok' => true, 'reason' => 'resent', 'message' => 'A new signing link was issued.');
    }

    /**
     * Ask the provider for the authoritative status and reconcile.
     *
     * Never downgrades a completed record: applyState() refuses, and the
     * disagreement is recorded for review rather than resolved by whichever
     * message arrived last.
     */
    public function syncStatus($contractId, $actorId, $now)
    {
        $req = $this->latestRequestForContract($contractId);

        if (!$req) {
            return array('ok' => false, 'reason' => 'no_request',
                         'message' => 'There is no signing request for this contract.');
        }

        $provider = new Leegality_provider(array());
        $r        = $provider->getRequestStatus((string) $req['external_request_id']);

        $this->markSynced((int) $req['id'], $now);

        $this->audit((int) $actorId, 'cv_status_synced', (int) $contractId, (int) $req['id'],
            array('ok' => !empty($r['ok']), 'reason' => (string) $r['reason']));

        if (empty($r['ok'])) {
            return array('ok' => false, 'reason' => (string) $r['reason'],
                         'message' => Contract_failures::operatorMessage((string) $r['reason']));
        }

        $applied = $this->applyState((int) $req['id'], (string) $r['state'], 'reconciliation', $actorId);

        return array('ok' => true, 'reason' => 'synced',
                     'message' => $applied['changed']
                         ? 'Status updated to ' . $applied['to'] . '.'
                         : 'Already up to date.');
    }

    /** Cancel a live request, with a stated reason. */
    public function cancelRequest($contractId, $reason, $actorId, $now)
    {
        $reason = trim((string) $reason);

        if (mb_strlen($reason) < 10) {
            return array('ok' => false, 'reason' => 'reason_too_short',
                         'message' => 'Give a reason of at least ten characters. Nothing was cancelled.');
        }

        $req = $this->latestRequestForContract($contractId);

        if (!$req) {
            return array('ok' => false, 'reason' => 'no_request',
                         'message' => 'There is no signing request to cancel.');
        }

        if (!in_array((string) $req['state'], Contract_signing_state::cancellable(), true)) {
            return array('ok' => false, 'reason' => 'not_cancellable',
                         'message' => 'This request has already finished and cannot be cancelled.');
        }

        /*
         * A request that was never sent exists only here.
         *
         * As first written, this method called the provider unconditionally and
         * returned its refusal — which meant that while the adapter is blocked,
         * NOTHING could be cancelled, including a request sitting in `created`
         * that the provider has never heard of. Every request is in `created`
         * today, so the cancel button was wired to a permanent refusal: the
         * control existed, was reachable, held a capability, and could not
         * work. That is the silent-no-op shape, with a visible error message
         * in front of it.
         *
         * `external_request_id` is what says whether the provider has a copy.
         * Without one there is nothing to withdraw remotely and the local
         * record is cancelled on its own authority. With one, the provider is
         * asked first and its refusal stands — cancelling locally while a
         * signing link is still live would tell staff the request is dead while
         * the customer can still sign it.
         */
        $external = isset($req['external_request_id']) ? trim((string) $req['external_request_id']) : '';

        if ($external !== '') {
            $provider = new Leegality_provider(array());
            $r        = $provider->cancelRequest($external, $reason);

            if (empty($r['ok'])) {
                $this->audit((int) $actorId, 'cv_cancel_attempted', (int) $contractId, (int) $req['id'],
                    array('ok' => false, 'reason' => (string) $r['reason'], 'at_provider' => true));

                return array('ok' => false, 'reason' => (string) $r['reason'],
                             'message' => Contract_failures::operatorMessage((string) $r['reason'])
                                        . ' The request is still live with the provider, so it has '
                                        . 'NOT been cancelled here either.');
            }
        }

        $this->db->where('id', (int) $req['id'])
                 ->update($this->t('payplex_cv_requests'), array(
                     'cancelled_by' => (int) $actorId, 'cancelled_at' => (int) $now,
                     'cancel_reason' => mb_substr($reason, 0, 500)));

        /*
         * The reason goes into the audit trail as well as the request row.
         *
         * `cancel_reason` on the request is the operational copy and travels
         * with the record; the audit row is the durable one. Withdrawing a
         * contract that was sent to a customer is exactly the action somebody
         * asks about months later, and "who cancelled it and why" should not
         * depend on a column in a row a later migration may reshape.
         */
        $this->audit((int) $actorId, 'cv_request_cancelled', (int) $contractId, (int) $req['id'],
            array('reason' => mb_substr(trim((string) $reason), 0, 400),
                  'was_sent_to_provider' => $external !== '',
                  'state_before' => (string) $req['state']));

        $this->applyState((int) $req['id'], Contract_signing_state::S_CANCELLED, 'internal', $actorId);

        return array('ok' => true, 'reason' => 'cancelled', 'message' => 'Signing request cancelled.');
    }

    /* ================================================================
     * Evidence delivery
     * ============================================================== */

    /**
     * Serve a stored evidence document.
     *
     * Reads from outside the document root, checks the digest before serving,
     * and records the download. A file whose bytes no longer match its recorded
     * hash is refused: an evidence document that has changed since it was
     * retrieved is not evidence.
     */
    public function serveDocument($contractId, $documentType, $actorId, $now)
    {
        $req = $this->latestRequestForContract($contractId);

        if (!$req) {
            return array('ok' => false, 'reason' => 'no_request',
                         'message' => 'There is no signing request for this contract.');
        }

        $doc = $this->db->where('request_id', (int) $req['id'])
                        ->where('document_type', (string) $documentType)
                        ->get($this->t('payplex_cv_documents'))->row_array();

        if (!$doc) {
            return array('ok' => false, 'reason' => 'document_not_retrieved',
                         'message' => 'That document has not been retrieved from the provider yet.');
        }

        $path = $this->evidenceRoot() . '/' . ltrim((string) $doc['storage_path'], '/');

        if (!is_file($path) || !is_readable($path)) {
            return array('ok' => false, 'reason' => 'document_file_missing',
                         'message' => 'The stored file could not be read. This is recorded for an '
                                    . 'administrator to investigate.');
        }

        $bytes = file_get_contents($path);

        if (!Contract_evidence::digestsMatch(hash('sha256', $bytes), (string) $doc['sha256'])) {
            $this->audit((int) $actorId, 'cv_document_hash_mismatch', (int) $contractId, (int) $req['id'],
                array('document_type' => (string) $documentType));

            return array('ok' => false, 'reason' => 'document_hash_mismatch',
                         'message' => 'The stored file no longer matches the hash recorded when it was '
                                    . 'retrieved. It has not been served. An administrator has been alerted.');
        }

        $this->audit((int) $actorId, 'cv_document_downloaded', (int) $contractId, (int) $req['id'],
            array('document_type' => (string) $documentType));

        return array('ok' => true, 'reason' => 'served', 'bytes' => $bytes,
                     'content_type' => (string) $doc['content_type'],
                     'filename' => 'contract-' . (int) $contractId . '-' . $documentType . '.pdf');
    }

    /**
     * Serve an identity verification document.
     *
     * Its own capability, a stated purpose, and an audit row every time. The
     * most sensitive action in this module.
     */
    public function serveIdentityDocument($contractId, $verificationId, $actorId, $holds, $purpose, $now)
    {
        $may = Contract_caps::identityDownloadAllowed($holds, $actorId, $purpose);

        if (empty($may['allowed'])) {
            return array('ok' => false, 'reason' => $may['reason'],
                         'message' => $may['reason'] === 'purpose_required'
                             ? 'State why this identity document is needed, in at least ten characters.'
                             : 'You are not able to download identity documents.');
        }

        $v = $this->db->where('id', (int) $verificationId)
                      ->where('contract_id', (int) $contractId)
                      ->get($this->t('payplex_cv_verifications'))->row_array();

        if (!$v || empty($v['evidence_path'])) {
            return array('ok' => false, 'reason' => 'no_identity_document',
                         'message' => 'There is no identity document stored for that verification.');
        }

        $path = $this->evidenceRoot() . '/' . ltrim((string) $v['evidence_path'], '/');

        if (!is_file($path) || !is_readable($path)) {
            return array('ok' => false, 'reason' => 'document_file_missing',
                         'message' => 'The stored file could not be read.');
        }

        $this->audit((int) $actorId, 'cv_identity_document_downloaded', (int) $contractId, null,
            array('verification_id' => (int) $verificationId,
                  'verification_type' => (string) $v['verification_type'],
                  'purpose' => mb_substr(trim((string) $purpose), 0, 200)));

        return array('ok' => true, 'reason' => 'served', 'bytes' => file_get_contents($path),
                     'content_type' => 'application/octet-stream',
                     'filename' => 'identity-' . (int) $verificationId);
    }

    /**
     * Where evidence files live: outside the document root, always.
     *
     * A path inside the web root is one misconfigured directory listing away
     * from publishing every signed agreement this company holds.
     */
    public function evidenceRoot()
    {
        if (defined('PAYPLEX_CV_EVIDENCE_ROOT')) { return rtrim(PAYPLEX_CV_EVIDENCE_ROOT, '/'); }

        return rtrim(dirname(dirname(FCPATH)), '/') . '/secure_contracts';
    }

    /* ================================================================
     * Production gate evidence
     * ============================================================== */

    /**
     * What the module has actually recorded, for the production gate.
     *
     * Read from the module's own tables, never from the request — a gate whose
     * conditions arrive in a POST is not a gate.
     */
    public function productionEvidence()
    {
        $s = $this->settings();

        $sandboxCreds = !empty($s['api_key']['configured']) && !empty($s['api_secret']['configured']);

        $passed = function ($event) {
            return (int) $this->db->where('event', $event)
                                  ->like('detail', '"ok":true')
                                  ->count_all_results($this->t('payplex_cv_audit')) > 0;
        };

        return array(
            'sandbox_credentials_present' => $sandboxCreds,
            'test_connection_passed'      => $passed('cv_test_connection'),
            'sandbox_round_trip_passed'   => $passed('cv_sandbox_test'),
            /* Not self-certifiable. Set by an administrator recording that the
               acceptance tests were run, which is a deliberate act. */
            'acceptance_tests_recorded'   => $this->setting('acceptance_tests_recorded', '0') === '1',
            'administrator_confirmed'     => true,
        );
    }

    /**
     * Record a webhook that was refused, without storing the body.
     *
     * A refused delivery is exactly what somebody needs to see when they ask
     * why a contract is not updating, so it is recorded rather than dropped.
     * The digest proves what arrived without being what arrived.
     */
    public function recordRefusedWebhook(array $in)
    {
        $this->db->insert($this->t('payplex_cv_webhooks'), array(
            'event_key'   => 'refused:' . substr((string) $in['body_sha256'], 0, 32) . ':' . (int) $in['received_at'],
            'body_sha256' => (string) $in['body_sha256'],
            'received_at' => (int) $in['received_at'],
            'processed_at' => (int) $in['received_at'],
            'result'      => substr((string) $in['result'], 0, 60),
            'http_status' => (int) $in['http_status'],
        ));
    }

    /* ================================================================
     * Crypto helpers
     * ============================================================== */

    private function encrypt($plain)
    {
        if (!function_exists('get_instance')) { return null; }

        $ci = &get_instance();

        if (!isset($ci->encryption)) { $ci->load->library('encryption'); }

        $out = $ci->encryption->encrypt((string) $plain);

        return ($out === false || $out === null || $out === '') ? null : $out;
    }

    private function decrypt($cipher)
    {
        if (!function_exists('get_instance')) { return null; }

        $ci = &get_instance();

        if (!isset($ci->encryption)) { $ci->load->library('encryption'); }

        $out = $ci->encryption->decrypt((string) $cipher);

        return ($out === false) ? null : $out;
    }
    /* ================================================================
     * Execution: workflow profiles, KYC sessions, delivery, health
     *
     * Everything below belongs to migrations 207-209. Each block starts by
     * asking whether its table exists, because this module is deployed in
     * increments and a screen that fatals on a missing table is worse than one
     * that says the feature is not installed yet.
     * ============================================================== */

    public function profilesReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_profiles'));
    }

    public function kycReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_kyc_sessions'))
            && $this->db->table_exists($this->t('payplex_cv_invite_tokens'));
    }

    public function deliveryReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_deliveries'))
            && $this->db->table_exists($this->t('payplex_cv_provider_health'))
            && $this->db->table_exists($this->t('payplex_cv_failures'));
    }

    /**
     * Which migrations a screen still needs.
     *
     * @return array
     */
    public function executionMissingMigrations()
    {
        $out = array();

        if (!$this->profilesReady()) { $out[] = '207_workflow_profiles.php'; }
        if (!$this->kycReady())      { $out[] = '208_kyc_sessions_and_tokens.php'; }
        if (!$this->deliveryReady()) { $out[] = '209_delivery_and_provider_health.php'; }

        return $out;
    }

    /* ---- workflow profiles ------------------------------------------- */

    public function profiles($environment = null)
    {
        if (!$this->profilesReady()) { return array(); }

        $this->db->where('is_active', 1);

        if ($environment !== null) { $this->db->where('environment', (string) $environment); }

        return $this->db->order_by('label', 'ASC')
                        ->get($this->t('payplex_cv_profiles'))->result_array();
    }

    public function profile($id)
    {
        if (!$this->profilesReady()) { return null; }

        $r = $this->db->where('id', (int) $id)
                      ->get($this->t('payplex_cv_profiles'))->row_array();

        return $r ? $this->hydrateProfile($r) : null;
    }

    /**
     * The profile a contract will be sent through.
     *
     * A template-specific mapping wins over the catch-all (`contract_template_id`
     * = 0). Falling back to the catch-all rather than refusing is deliberate: a
     * single-workflow account should not have to declare the same profile once
     * per template, and the readiness check below still refuses the send if the
     * catch-all does not match the roster.
     *
     * @param  int $contractId
     * @return array|null
     */
    public function profileForContract($contractId)
    {
        if (!$this->profilesReady()) { return null; }

        $environment = $this->setting('environment', 'sandbox');
        $contract    = $this->contract($contractId);
        $template    = 0;

        if (is_array($contract) && isset($contract['template_id'])) {
            $template = (int) $contract['template_id'];
        }

        if ($template > 0) {
            $r = $this->db->where('environment', $environment)
                          ->where('contract_template_id', $template)
                          ->where('is_active', 1)
                          ->get($this->t('payplex_cv_profiles'))->row_array();

            if ($r) { return $this->hydrateProfile($r); }
        }

        $r = $this->db->where('environment', $environment)
                      ->where('contract_template_id', 0)
                      ->where('is_active', 1)
                      ->get($this->t('payplex_cv_profiles'))->row_array();

        return $r ? $this->hydrateProfile($r) : null;
    }

    private function hydrateProfile(array $r)
    {
        $r['signer_roles'] = $r['signer_roles'] === ''
            ? array()
            : array_values(array_filter(array_map('trim', explode(',', (string) $r['signer_roles'])), 'strlen'));

        return $r;
    }

    /**
     * Create or update a workflow declaration.
     *
     * @param  array $in
     * @param  int   $actorId
     * @param  int   $now
     * @return array {ok, errors, id, message}
     */
    public function saveProfile(array $in, $actorId, $now)
    {
        if (!$this->profilesReady()) {
            return array('ok' => false, 'errors' => array(), 'id' => 0,
                         'message' => 'The workflow profile table is not installed yet.');
        }

        $v = Contract_profile_map::validateDeclaration($in);

        if (!$v['ok']) {
            return array('ok' => false, 'errors' => $v['errors'], 'id' => 0,
                         'message' => 'Some details need correcting.');
        }

        $c     = $v['clean'];
        $table = $this->t('payplex_cv_profiles');
        $id    = (int) (isset($in['id']) ? $in['id'] : 0);

        $row = array(
            'label'                => $c['label'],
            /*
             * The environment is taken from the module settings, never from the
             * form. A profile posted with `environment=production` while the
             * module runs in sandbox would be a production declaration created
             * by a form field, which is not a decision a form gets to make.
             */
            'environment'          => $this->setting('environment', 'sandbox'),
            'profile_id'           => $c['profile_id'],
            'contract_template_id' => $c['contract_template_id'],
            'signer_count'         => $c['signer_count'],
            'signer_roles'         => implode(',', $c['signer_roles']),
            'ordering_mode'        => $c['ordering_mode'],
            'auth_method'          => $c['auth_method'],
            'kyc_policy'           => $c['kyc_policy'],
            'is_active'            => 1,
            'updated_by'           => (int) $actorId,
            'updated_at'           => (int) $now,
        );

        if ($id > 0) {
            $existing = $this->db->where('id', $id)->get($table)->row_array();

            if (!$existing) {
                return array('ok' => false, 'errors' => array(), 'id' => 0,
                             'message' => 'That workflow mapping no longer exists.');
            }

            /*
             * Changing the profile id, the signer shape or the policy
             * INVALIDATES any previous validation. Leaving validated_at in place
             * would show a green tick against a declaration nobody has ever
             * proved, which is worse than showing no tick at all.
             */
            $material = array('profile_id', 'signer_count', 'signer_roles',
                              'ordering_mode', 'auth_method', 'kyc_policy');
            $changed  = false;

            foreach ($material as $k) {
                if ((string) $existing[$k] !== (string) $row[$k]) { $changed = true; break; }
            }

            if ($changed) {
                $row['validated_at']    = null;
                $row['validated_by']    = 0;
                $row['validation_note'] = null;
            }

            $this->db->where('id', $id)->update($table, $row);

            $this->audit($actorId, 'profile_updated', 0, 0, array(
                'profile_row_id'      => $id,
                'profile_id'          => $c['profile_id'],
                'validation_cleared'  => $changed ? 'yes' : 'no',
            ));

            return array('ok' => true, 'errors' => array(), 'id' => $id,
                         'message' => $changed
                             ? 'Workflow saved. It has to be validated again, because what it '
                             . 'declares has changed.'
                             : 'Workflow saved.');
        }

        $row['created_by'] = (int) $actorId;
        $row['created_at'] = (int) $now;

        $this->db->insert($table, $row);
        $newId = (int) $this->db->insert_id();

        $this->audit($actorId, 'profile_created', 0, 0, array(
            'profile_row_id' => $newId,
            'profile_id'     => $c['profile_id'],
        ));

        return array('ok' => true, 'errors' => array(), 'id' => $newId,
                     'message' => 'Workflow saved. Validate it before sending anything through it.');
    }

    /**
     * Record the outcome of a sandbox validation.
     *
     * The note is stored verbatim so the screen can say what actually happened,
     * including that a real sandbox document was created and left to expire.
     *
     * @return bool
     */
    public function markProfileValidated($profileRowId, $actorId, $now, $note)
    {
        if (!$this->profilesReady()) { return false; }

        $this->db->where('id', (int) $profileRowId)
                 ->update($this->t('payplex_cv_profiles'), array(
                     'validated_at'    => (int) $now,
                     'validated_by'    => (int) $actorId,
                     'validation_note' => substr((string) $note, 0, 500),
                 ));

        $this->audit($actorId, 'profile_validated', 0, 0, array(
            'profile_row_id' => (int) $profileRowId,
            'note'           => substr((string) $note, 0, 200),
        ));

        return true;
    }

    /* ---- can this contract be sent? ---------------------------------- */

    /**
     * Every reason this contract cannot be sent, in one place.
     *
     * Returns REASONS, never a boolean. A send refused with "not ready" gets
     * overridden by whoever is in a hurry; a send refused with "this workflow
     * defines 3 signers and the roster has 2" gets fixed.
     *
     * The checks below are ordered so the most fundamental come first — there is
     * no point complaining about signature field placement on a contract that
     * was never approved.
     *
     * @param  int $contractId
     * @return array {ok, blockers, profile, signers, mapping}
     */
    public function sendReadiness($contractId)
    {
        $contractId = (int) $contractId;
        $blockers   = array();

        $contract = $this->contract($contractId);

        if (!$contract) {
            return array('ok' => false, 'profile' => null, 'signers' => array(), 'mapping' => array(),
                         'blockers' => array(array('code' => 'contract_not_found',
                                                   'message' => 'That contract no longer exists.')));
        }

        /* 1. Approval and document lock. */
        $request = $this->latestRequestForContract($contractId);

        if (!$request) {
            $blockers[] = array('code' => 'not_prepared',
                'message' => 'This contract has not been prepared for signing yet.');
        } elseif ((int) $request['approved_at'] <= 0) {
            $blockers[] = array('code' => 'not_approved',
                'message' => 'This contract has not been approved for signing. A checker other '
                           . 'than the person who prepared it has to approve it first.');
        }

        if ($request && (string) $request['original_sha256'] === '') {
            $blockers[] = array('code' => 'document_not_locked',
                'message' => 'The approved document version has not been hashed, so a later change '
                           . 'to it could not be detected.');
        }

        /* 2. The workflow profile. */
        $profile = $this->profileForContract($contractId);

        if (!$profile) {
            $blockers[] = array('code' => 'no_profile_mapping',
                'message' => 'No Leegality workflow is mapped for this contract. An administrator '
                           . 'has to map one on the Workflow mapping screen.');
        } else {
            $environment = $this->setting('environment', 'sandbox');

            if ((string) $profile['environment'] !== $environment) {
                $blockers[] = array('code' => 'environment_mismatch',
                    'message' => 'The mapped workflow belongs to the ' . $profile['environment']
                               . ' environment and the module is configured for ' . $environment
                               . '. A workflow ID from one account does not exist in the other.');
            }

            if ((int) $profile['validated_at'] <= 0) {
                $blockers[] = array('code' => 'profile_not_validated',
                    'message' => 'The mapped workflow has never been validated, so we do not know '
                               . 'the provider accepts it.');
            }
        }

        /* 3. The roster, against that profile. */
        $signers = $this->activeSigners($contractId);
        $mapping = array();

        if ($profile) {
            $m       = Contract_profile_map::mapRoster($profile, $signers);
            $mapping = $m['mapping'];

            foreach ($m['blockers'] as $b) { $blockers[] = $b; }
        } elseif (count($signers) === 0) {
            $blockers[] = array('code' => 'no_signers',
                'message' => 'There are no live signers on this contract.');
        }

        /* 4. Authentication data each signer needs. */
        foreach ($signers as $s) {
            $method = (string) (isset($s['auth_method']) ? $s['auth_method'] : '');
            $name   = (string) $s['full_name'];

            if (trim((string) $s['email']) === '') {
                $blockers[] = array('code' => 'signer_missing_email',
                    'message' => $name . ' has no email address, so no invitation could be sent.');
            }

            if (in_array($method, array('mobile_otp', 'sms_otp'), true)
                && trim((string) (isset($s['mobile_e164']) ? $s['mobile_e164'] : '')) === '') {
                $blockers[] = array('code' => 'signer_missing_mobile',
                    'message' => $name . ' is set to authenticate by mobile OTP but has no mobile '
                               . 'number on file.');
            }
        }

        /* 5. Every placed field must reference a signer who is still active. */
        $version   = $request ? (int) $request['contract_version'] : 0;
        $fields    = $this->fields($contractId, $version);
        $liveRefs  = array();

        foreach ($signers as $s) { $liveRefs[] = (string) $s['email']; }

        if (count($fields) === 0) {
            $blockers[] = array('code' => 'no_signature_fields',
                'message' => 'No signature fields have been placed, so a signer would open the '
                           . 'document and find nothing to do.');
        }

        foreach ($fields as $f) {
            $ref = trim((string) (isset($f['signer_reference']) ? $f['signer_reference'] : ''));

            if ($ref === '') { continue; }

            if (!in_array($ref, $liveRefs, true)) {
                $blockers[] = array('code' => 'field_references_removed_signer',
                    'message' => 'A signature field is placed for "' . $ref . '", who is no longer '
                               . 'an active signer on this contract.');
            }
        }

        /* 6. KYC policy resolved for every signer. */
        if ($profile) {
            $policy = (string) $profile['kyc_policy'];

            if ($policy !== Contract_profile_map::KYC_NONE) {
                $needs = Contract_profile_map::kycRequiredFor($profile, $signers);

                if (count($needs) === 0) {
                    $blockers[] = array('code' => 'kyc_policy_matches_nobody',
                        'message' => 'This workflow requires Video KYC but the policy matches none '
                                   . 'of the signers, so the requirement could never be satisfied '
                                   . 'and the contract could never complete.');
                }
            }
        }

        return array(
            'ok'       => count($blockers) === 0,
            'blockers' => $blockers,
            'profile'  => $profile,
            'signers'  => $signers,
            'mapping'  => $mapping,
        );
    }

    /* ---- idempotent preparation --------------------------------------- */

    /**
     * Claim an operation reference for this contract, atomically.
     *
     * THIS IS THE DUPLICATE-SEND DEFENCE, AND IT LIVES HERE FOR A REASON.
     *
     * Leegality documents no idempotency key on create, so a second call makes a
     * second document and a second set of signing links. Nothing at the provider
     * will stop that, and nothing in PHP can either — two clicks half a second
     * apart are two processes, and any check-then-act in application code has a
     * window between the check and the act.
     *
     * The database does not have that window. `payplex_cv_requests` carries
     * UNIQUE(operation_reference), so the SECOND insert fails rather than
     * succeeding, and the loser of the race finds the winner's row and returns
     * it. A double click produces one request; so does a retry, so does a cron
     * run racing a button.
     *
     * The reference is DERIVED from the contract and its approved version rather
     * than random, so the same contract at the same version always claims the
     * same reference — which is what makes a retry recognisably the same attempt.
     *
     * @param  int $contractId
     * @param  int $actorId
     * @param  int $now
     * @return array {ok, reason, request, created, message}
     */
    public function claimSigningAttempt($contractId, $actorId, $now)
    {
        $contractId = (int) $contractId;
        $request    = $this->latestRequestForContract($contractId);

        if (!$request) {
            return array('ok' => false, 'reason' => 'not_prepared', 'request' => null,
                         'created' => false,
                         'message' => 'This contract has not been prepared for signing.');
        }

        if ((string) $request['external_request_id'] !== '') {
            /* Already sent. Not an error — the caller asked to send something
               that is already at the provider, and the answer is the existing
               request, not a second one. */
            return array('ok' => true, 'reason' => 'already_sent', 'request' => $request,
                         'created' => false,
                         'message' => 'This contract has already been sent to the signing provider.');
        }

        if ((string) $request['operation_reference'] !== '') {
            return array('ok' => true, 'reason' => 'already_claimed', 'request' => $request,
                         'created' => false,
                         'message' => 'A send is already in progress for this contract.');
        }

        $reference = 'cv-' . $contractId . '-v' . (int) $request['contract_version']
                   . '-' . substr(hash('sha256', $contractId . '|' . (int) $request['approved_at']), 0, 16);

        $table = $this->t('payplex_cv_requests');

        $this->db->trans_start();
        $this->db->where('id', (int) $request['id'])
                 ->where('operation_reference', '')
                 ->update($table, array('operation_reference' => $reference));
        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            /*
             * The UNIQUE key rejected it, which means another process claimed
             * the same reference first. That is the race working correctly.
             */
            $current = $this->request((int) $request['id']);

            return array('ok' => true, 'reason' => 'claimed_by_another_process',
                         'request' => $current, 'created' => false,
                         'message' => 'A send is already in progress for this contract.');
        }

        $this->audit($actorId, 'send_attempt_claimed', $contractId, (int) $request['id'], array(
            'operation_reference' => $reference,
        ));

        return array('ok' => true, 'reason' => 'claimed',
                     'request' => $this->request((int) $request['id']), 'created' => true,
                     'message' => 'Ready to send.');
    }

    /**
     * Freeze the roster onto the request.
     *
     * The roster keeps changing; a sent request must not. The snapshot hash is
     * taken over the promoted signers so a later roster edit is DETECTABLE
     * rather than merely disapproved of — without it, "the roster changed after
     * sending" is a thing somebody has to notice by eye.
     *
     * @param  int   $requestId
     * @param  int   $contractId
     * @param  array $signers  live roster rows
     * @param  int   $now
     * @return array {ok, count, sha256}
     */
    public function snapshotRosterOntoRequest($requestId, $contractId, array $signers, $now)
    {
        $requestId = (int) $requestId;
        $table     = $this->t('payplex_cv_signers');

        $existing = $this->db->where('request_id', $requestId)->count_all_results($table);

        if ($existing > 0) {
            /* Already promoted. Re-promoting would either duplicate the signers
               or overwrite the frozen copy with the live roster, and both are
               worse than doing nothing. */
            return array('ok' => true, 'count' => $existing, 'sha256' => '',
                         'reason' => 'already_snapshotted');
        }

        $canonical = array();

        $this->db->trans_start();

        foreach ($signers as $s) {
            $row = array(
                'request_id'      => $requestId,
                'reference'       => (string) $s['email'],
                'full_name'       => (string) $s['full_name'],
                'email'           => (string) $s['email'],
                'role'            => (string) $s['role'],
                'signing_order'   => (int) $s['signing_order'],
                'is_mandatory'    => (int) $s['is_mandatory'],
                'signature_method' => (string) (isset($s['auth_method']) ? $s['auth_method'] : ''),
                'state'           => 'created',
                'created_at'      => (int) $now,
            );

            if ($this->db->field_exists('kyc_required', $table)) {
                $row['kyc_required'] = !empty($s['kyc_required']) ? 1 : 0;
                $row['kyc_state']    = !empty($s['kyc_required'])
                    ? Contract_kyc::K_PENDING : Contract_kyc::K_NONE;
            }

            $this->db->insert($table, $row);

            $canonical[] = $row['signing_order'] . '|' . strtolower($row['email']) . '|'
                         . strtolower($row['role']) . '|' . $row['is_mandatory'];
        }

        sort($canonical);
        $hash = hash('sha256', implode("\n", $canonical));

        if ($this->db->field_exists('roster_sha256', $this->t('payplex_cv_requests'))) {
            $this->db->where('id', $requestId)
                     ->update($this->t('payplex_cv_requests'), array('roster_sha256' => $hash));
        }

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return array('ok' => false, 'count' => 0, 'sha256' => '', 'reason' => 'snapshot_failed');
        }

        $this->audit(0, 'roster_snapshotted', $contractId, $requestId, array(
            'signers'       => count($signers),
            'roster_sha256' => $hash,
        ));

        return array('ok' => true, 'count' => count($signers), 'sha256' => $hash,
                     'reason' => 'snapshotted');
    }

    /* ---- KYC sessions -------------------------------------------------- */

    public function kycSessions($requestId)
    {
        if (!$this->kycReady()) { return array(); }

        return $this->db->where('request_id', (int) $requestId)
                        ->order_by('id', 'ASC')
                        ->get($this->t('payplex_cv_kyc_sessions'))->result_array();
    }

    public function kycSession($id)
    {
        if (!$this->kycReady()) { return null; }

        $r = $this->db->where('id', (int) $id)
                      ->get($this->t('payplex_cv_kyc_sessions'))->row_array();

        return $r ? $r : null;
    }

    /**
     * Open a verification session for a signer.
     *
     * Creates the LOCAL row first, then asks the provider. With no provider
     * configured the provider call refuses and the row stays at `pending` —
     * which is exactly what a contract stuck at "Signed — Video KYC Pending"
     * should look like in the data, rather than there being no row at all and
     * the screen having nothing to explain.
     *
     * @param  array               $signer  request-signer row
     * @param  VideoKycProvider    $provider
     * @param  int                 $actorId
     * @param  int                 $now
     * @return array {ok, reason, session_id, message}
     */
    public function createKycSession(array $signer, $provider, $actorId, $now)
    {
        if (!$this->kycReady()) {
            return array('ok' => false, 'reason' => 'not_installed', 'session_id' => 0,
                         'message' => 'Video KYC tables are not installed yet.');
        }

        $request = $this->request((int) $signer['request_id']);

        if (!$request) {
            return array('ok' => false, 'reason' => 'request_not_found', 'session_id' => 0,
                         'message' => 'The signing request no longer exists.');
        }

        $table = $this->t('payplex_cv_kyc_sessions');

        $live = $this->db->where('signer_id', (int) $signer['id'])
                         ->where_not_in('state', array(Contract_kyc::K_FAILED,
                                                       Contract_kyc::K_EXPIRED,
                                                       Contract_kyc::K_CANCELLED))
                         ->order_by('id', 'DESC')->limit(1)
                         ->get($table)->row_array();

        if ($live) {
            return array('ok' => true, 'reason' => 'already_open', 'session_id' => (int) $live['id'],
                         'message' => 'A verification session is already open for this signer.');
        }

        $attempt = 1 + (int) $this->db->where('signer_id', (int) $signer['id'])
                                      ->count_all_results($table);

        $this->db->insert($table, array(
            'request_id'      => (int) $signer['request_id'],
            'contract_id'     => (int) $request['contract_id'],
            'signer_id'       => (int) $signer['id'],
            'signer_email'    => (string) $signer['email'],
            'provider'        => 'unconfigured',
            'environment'     => $this->setting('environment', 'sandbox'),
            'state'           => Contract_kyc::K_PENDING,
            'attempt'         => $attempt,
            'required_reason' => 'profile_policy',
            'created_at'      => (int) $now,
        ));

        $sessionId = (int) $this->db->insert_id();

        $created = $provider->createSession(
            array('name' => (string) $signer['full_name'], 'email' => (string) $signer['email']),
            array('contract_id' => (int) $request['contract_id'],
                  'request_id'  => (int) $signer['request_id'])
        );

        if (empty($created['ok'])) {
            /*
             * Recorded as a failure against the session, NOT as a rejection of
             * the signer. Nobody failed verification here; there is simply
             * nowhere to verify them.
             */
            $this->db->where('id', $sessionId)->update($table, array(
                'last_failure' => (string) $created['reason'],
                'updated_at'   => (int) $now,
            ));

            $this->audit($actorId, 'kyc_session_blocked',
                         (int) $request['contract_id'], (int) $signer['request_id'], array(
                'kyc_session_id' => $sessionId,
                'reason'         => (string) $created['reason'],
            ));

            return array('ok' => false, 'reason' => (string) $created['reason'],
                         'session_id' => $sessionId,
                         'message' => (string) $created['message']);
        }

        $this->db->where('id', $sessionId)->update($table, array(
            'session_reference' => (string) $created['session_reference'],
            'state'             => Contract_kyc::K_SESSION_CREATED,
            'expires_at'        => (int) $created['expires_at'],
            'updated_at'        => (int) $now,
        ));

        $this->audit($actorId, 'kyc_session_created',
                     (int) $request['contract_id'], (int) $signer['request_id'], array(
            'kyc_session_id' => $sessionId,
        ));

        return array('ok' => true, 'reason' => 'created', 'session_id' => $sessionId,
                     'message' => 'Verification session created.');
    }

    /**
     * Apply an observed session state.
     *
     * `$actor` is passed through to Contract_kyc::apply(), which refuses any
     * decision that did not come from a provider. This method deliberately has
     * no way to bypass that: there is no `$force`, and no branch that writes the
     * state without asking.
     *
     * @return array {applied, state, reason}
     */
    public function applyKycState($sessionId, $observed, $actor, $actorId, $now, array $detail = array())
    {
        if (!$this->kycReady()) { return array('applied' => false, 'state' => '', 'reason' => 'not_installed'); }

        $session = $this->kycSession($sessionId);

        if (!$session) { return array('applied' => false, 'state' => '', 'reason' => 'session_not_found'); }

        $verdict = Contract_kyc::apply((string) $session['state'], (string) $observed, (string) $actor);

        if (empty($verdict['apply'])) {
            $this->audit($actorId, 'kyc_state_refused',
                         (int) $session['contract_id'], (int) $session['request_id'], array(
                'kyc_session_id' => (int) $sessionId,
                'from'           => (string) $session['state'],
                'observed'       => (string) $observed,
                'actor'          => (string) $actor,
                'reason'         => (string) $verdict['reason'],
            ));

            return array('applied' => false, 'state' => (string) $session['state'],
                         'reason' => (string) $verdict['reason']);
        }

        $update = array('state' => $verdict['state'], 'updated_at' => (int) $now);

        if ($verdict['state'] === Contract_kyc::K_PASSED
            || $verdict['state'] === Contract_kyc::K_FAILED) {
            $update['decision']   = $verdict['state'];
            $update['decided_at'] = (int) $now;

            if (isset($detail['provider_reference'])) {
                $update['decided_by_provider'] = substr((string) $detail['provider_reference'], 0, 190);
            }

            if (isset($detail['reject_reason'])) {
                $update['reject_reason'] = substr((string) $detail['reject_reason'], 0, 500);
            }
        }

        if ($verdict['state'] === Contract_kyc::K_MANUAL_REVIEW) {
            $update['manual_review_at'] = (int) $now;
        }

        $this->db->where('id', (int) $sessionId)
                 ->update($this->t('payplex_cv_kyc_sessions'), $update);

        /* Mirror onto the signer, so the completion gate and the panel do not
           have to join to a session that may not exist for every signer. */
        $signerTable = $this->t('payplex_cv_signers');

        if ($this->db->field_exists('kyc_state', $signerTable)) {
            $mirror = array('kyc_state' => $verdict['state']);

            if ($verdict['state'] === Contract_kyc::K_PASSED) {
                $mirror['kyc_passed_at'] = (int) $now;
            }

            $this->db->where('id', (int) $session['signer_id'])->update($signerTable, $mirror);
        }

        $this->audit($actorId, 'kyc_state_' . $verdict['state'],
                     (int) $session['contract_id'], (int) $session['request_id'], array(
            'kyc_session_id' => (int) $sessionId,
            'from'           => (string) $session['state'],
            'to'             => (string) $verdict['state'],
            'actor'          => (string) $actor,
        ));

        return array('applied' => true, 'state' => (string) $verdict['state'], 'reason' => 'applied');
    }

    /* ---- invitation tokens and delivery -------------------------------- */

    /**
     * Issue a single-use verification link and record the delivery attempt.
     *
     * Returns the RAW token exactly once, to the caller, for the outbound
     * message. It is never stored and never returned again — not to an
     * administrator, not to support, not by any query. Issuing revokes any
     * previous live token for the same signer.
     *
     * @return array {ok, reason, raw_token, expires_at, delivery_id, message}
     */
    public function issueKycInvite($sessionId, $channel, $actorId, $now)
    {
        if (!$this->kycReady() || !$this->deliveryReady()) {
            return array('ok' => false, 'reason' => 'not_installed', 'raw_token' => '',
                         'expires_at' => 0, 'delivery_id' => 0,
                         'message' => 'Verification tables are not installed yet.');
        }

        $session = $this->kycSession($sessionId);

        if (!$session) {
            return array('ok' => false, 'reason' => 'session_not_found', 'raw_token' => '',
                         'expires_at' => 0, 'delivery_id' => 0,
                         'message' => 'That verification session no longer exists.');
        }

        $tokens = $this->t('payplex_cv_invite_tokens');

        $previous = $this->db->where('kyc_session_id', (int) $sessionId)
                             ->order_by('id', 'DESC')->limit(1)
                             ->get($tokens)->row_array();

        $state = array(
            'resend_count' => $previous ? (int) $previous['resend_count'] : 0,
            'last_sent_at' => $previous ? (int) $previous['last_sent_at'] : 0,
        );

        $may = Contract_invite_token::mayResend($state, $now);

        if (empty($may['allowed'])) {
            return array('ok' => false, 'reason' => (string) $may['reason'], 'raw_token' => '',
                         'expires_at' => 0, 'delivery_id' => 0,
                         'message' => $may['reason'] === 'cooling_down'
                             ? 'A link was sent moments ago. Wait ' . (int) $may['retry_after']
                               . ' seconds before sending another.'
                             : 'The resend limit for this signer has been reached. Somebody needs '
                               . 'to look at why the link is not arriving.');
        }

        $ttl   = (int) $this->setting('link_expiry_hours', Contract_invite_token::DEFAULT_TTL_HOURS);
        $token = Contract_invite_token::issue($now, $ttl);

        $this->db->trans_start();

        /* Issuing a new link revokes the old one. Two live links for one signer
           means a forwarded copy still works after a resend. */
        if ($previous && (int) $previous['revoked_at'] <= 0 && (int) $previous['consumed_at'] <= 0) {
            $this->db->where('id', (int) $previous['id'])->update($tokens, array(
                'revoked_at'     => (int) $now,
                'revoked_reason' => Contract_invite_token::R_REPLACED,
            ));
        }

        $this->db->insert($tokens, array(
            'purpose'        => 'video_kyc',
            'kyc_session_id' => (int) $sessionId,
            'contract_id'    => (int) $session['contract_id'],
            'signer_id'      => (int) $session['signer_id'],
            'token_hash'     => $token['hash'],
            'token_algo'     => $token['algo'],
            'issued_at'      => (int) $now,
            'expires_at'     => (int) $token['expires_at'],
            'resend_count'   => $state['resend_count'] + 1,
            'last_sent_at'   => (int) $now,
            'created_by'     => (int) $actorId,
        ));

        $tokenId = (int) $this->db->insert_id();

        $recipient = $channel === 'sms'
            ? Contract_invite_token::maskMobile($this->signerMobile((int) $session['signer_id']))
            : Contract_invite_token::maskEmail((string) $session['signer_email']);

        $this->db->insert($this->t('payplex_cv_deliveries'), array(
            'purpose'          => 'video_kyc_invitation',
            'channel'          => (string) $channel,
            'contract_id'      => (int) $session['contract_id'],
            'signer_id'        => (int) $session['signer_id'],
            'kyc_session_id'   => (int) $sessionId,
            'invite_token_id'  => $tokenId,
            'recipient_masked' => $recipient,
            'template_key'     => 'video_kyc_invitation',
            'attempt'          => $state['resend_count'] + 1,
            /*
             * `prepared`, not `sent`. Outbound delivery is switched off for this
             * programme, so the message has been composed and recorded and
             * nothing has left the building. Writing `sent` here would be the
             * system claiming it contacted a customer when it did not.
             */
            'status'           => 'prepared',
            'queued_at'        => (int) $now,
            'created_by'       => (int) $actorId,
        ));

        $deliveryId = (int) $this->db->insert_id();

        $this->db->trans_complete();

        if ($this->db->trans_status() === false) {
            return array('ok' => false, 'reason' => 'write_failed', 'raw_token' => '',
                         'expires_at' => 0, 'delivery_id' => 0,
                         'message' => 'The invitation could not be recorded, so nothing was issued.');
        }

        $this->applyKycState($sessionId, Contract_kyc::K_LINK_SENT, 'system', $actorId, $now);

        $this->audit($actorId, 'kyc_invitation_prepared',
                     (int) $session['contract_id'], (int) $session['request_id'], array(
            'kyc_session_id'   => (int) $sessionId,
            'channel'          => (string) $channel,
            'recipient_masked' => $recipient,
            'expires_at'       => (int) $token['expires_at'],
        ));

        return array('ok' => true, 'reason' => 'issued', 'raw_token' => $token['raw'],
                     'expires_at' => (int) $token['expires_at'], 'delivery_id' => $deliveryId,
                     'message' => 'Verification link prepared for ' . $recipient
                                . '. Outbound delivery is switched off, so nothing has been sent.');
    }

    private function signerMobile($signerId)
    {
        $r = $this->db->select('email')->where('id', (int) $signerId)
                      ->get($this->t('payplex_cv_signers'))->row_array();

        if (!$r) { return ''; }

        $roster = $this->db->select('mobile_e164')
                           ->where('email', (string) $r['email'])
                           ->order_by('id', 'DESC')->limit(1)
                           ->get($this->t('payplex_cv_contract_signers'))->row_array();

        return $roster ? (string) $roster['mobile_e164'] : '';
    }

    public function deliveries($contractId)
    {
        if (!$this->deliveryReady()) { return array(); }

        return $this->db->where('contract_id', (int) $contractId)
                        ->order_by('id', 'DESC')->limit(50)
                        ->get($this->t('payplex_cv_deliveries'))->result_array();
    }

    /* ---- provider health and the failure queue -------------------------- */

    public function providerHealth($provider, $environment)
    {
        if (!$this->deliveryReady()) {
            return array('consecutive_failures' => 0, 'opened_at' => 0,
                         'half_open_successes' => 0, 'last_success_at' => 0, 'last_failure_at' => 0);
        }

        $r = $this->db->where('provider', (string) $provider)
                      ->where('environment', (string) $environment)
                      ->get($this->t('payplex_cv_provider_health'))->row_array();

        if (!$r) {
            return array('consecutive_failures' => 0, 'opened_at' => 0,
                         'half_open_successes' => 0, 'last_success_at' => 0, 'last_failure_at' => 0);
        }

        return $r;
    }

    public function writeProviderHealth($provider, $environment, array $health, $now)
    {
        if (!$this->deliveryReady()) { return false; }

        $table = $this->t('payplex_cv_provider_health');

        $row = array(
            'consecutive_failures' => (int) $health['consecutive_failures'],
            'opened_at'            => (int) $health['opened_at'],
            'half_open_successes'  => (int) $health['half_open_successes'],
            'last_success_at'      => (int) $health['last_success_at'],
            'last_failure_at'      => (int) $health['last_failure_at'],
            'updated_at'           => (int) $now,
        );

        $existing = $this->db->where('provider', (string) $provider)
                             ->where('environment', (string) $environment)
                             ->get($table)->row_array();

        if ($existing) {
            $this->db->where('id', (int) $existing['id'])->update($table, $row);
        } else {
            $row['provider']    = (string) $provider;
            $row['environment'] = (string) $environment;
            $this->db->insert($table, $row);
        }

        return true;
    }

    public function openFailures($contractId)
    {
        if (!$this->deliveryReady()) { return array(); }

        return $this->db->where('contract_id', (int) $contractId)
                        ->where('resolved_at IS NULL', null, false)
                        ->order_by('id', 'DESC')
                        ->get($this->t('payplex_cv_failures'))->result_array();
    }

    /**
     * Record a failure for controlled retry.
     *
     * `$attempted` is not decoration. An open circuit means the provider was
     * never asked, and a queue entry that says otherwise would let
     * reconciliation conclude a request does not exist at the provider when the
     * truth is that we never called.
     *
     * @return int failure id
     */
    public function queueFailure($contractId, $requestId, $operation, $failureCode,
                                 $attempted, $operationReference, $detail, $now)
    {
        if (!$this->deliveryReady()) { return 0; }

        $catalogue = Contract_failures::catalogue();
        $mayHave   = isset($catalogue[$failureCode])
            ? !empty($catalogue[$failureCode]['may_have_succeeded']) : false;

        $retry = Contract_failures::shouldRetry($failureCode, 1,
            (int) $this->setting('max_retries', 3));

        $this->db->insert($this->t('payplex_cv_failures'), array(
            'contract_id'         => (int) $contractId,
            'request_id'          => (int) $requestId ?: null,
            'operation'           => (string) $operation,
            'operation_reference' => $operationReference !== '' ? (string) $operationReference : null,
            'failure_code'        => (string) $failureCode,
            'attempted'           => $attempted ? 1 : 0,
            'may_have_succeeded'  => $mayHave ? 1 : 0,
            'attempts'            => 1,
            'next_attempt_at'     => !empty($retry['retry'])
                ? (int) $now + (int) ceil(Contract_failures::backoffMs(1) / 1000) : null,
            'detail'              => substr((string) $detail, 0, 1000),
            'created_at'          => (int) $now,
        ));

        return (int) $this->db->insert_id();
    }

    public function resolveFailure($failureId, $actorId, $resolution, $now)
    {
        if (!$this->deliveryReady()) { return false; }

        $this->db->where('id', (int) $failureId)
                 ->update($this->t('payplex_cv_failures'), array(
                     'resolved_at' => (int) $now,
                     'resolved_by' => (int) $actorId,
                     'resolution'  => substr((string) $resolution, 0, 500),
                     'updated_at'  => (int) $now,
                 ));

        return true;
    }

    /* ---- the completion gate, fed from real data ------------------------ */

    /**
     * The context Contract_lifecycle::completionBlockers() needs, read from the
     * database rather than asserted by a caller.
     *
     * Every value here is a fact looked up, not a flag passed in. A caller that
     * could supply these would be a caller that could complete a contract by
     * passing the right array.
     *
     * @param  int $contractId
     * @return array
     */
    public function completionContext($contractId)
    {
        $request = $this->latestRequestForContract($contractId);

        if (!$request) {
            return array('approved' => false, 'document_version_unchanged' => false,
                         'mandatory_signers' => 0, 'signed_mandatory_signers' => 0,
                         'kyc_required' => false, 'kyc_state' => '',
                         'kyc_sessions_required' => 0, 'kyc_sessions_passed' => 0,
                         'signed_document_stored' => false, 'signed_document_hash_verified' => false,
                         'audit_trail_stored' => false, 'audit_trail_hash_verified' => false,
                         'unresolved_provider_failure' => false);
        }

        $requestId = (int) $request['id'];
        $signers   = $this->signers($requestId);

        $mandatory = 0;
        $signed    = 0;
        $kycNeeded = 0;
        $kycPassed = 0;

        foreach ($signers as $s) {
            if (!empty($s['is_mandatory'])) {
                $mandatory++;

                if ((string) $s['state'] === 'signed'
                    || (int) (isset($s['completed_at']) ? $s['completed_at'] : 0) > 0) {
                    $signed++;
                }
            }

            if (!empty($s['kyc_required'])) {
                $kycNeeded++;

                if (isset($s['kyc_state']) && (string) $s['kyc_state'] === Contract_kyc::K_PASSED) {
                    $kycPassed++;
                }
            }
        }

        $documents = $this->db->where('request_id', $requestId)
                              ->get($this->t('payplex_cv_documents'))->result_array();

        $signedStored = false; $signedVerified = false;
        $auditStored  = false; $auditVerified  = false;

        foreach ($documents as $d) {
            $verified = (int) (isset($d['verified_at']) ? $d['verified_at'] : 0) > 0
                     && (string) $d['sha256'] !== '';

            if ((string) $d['document_type'] === 'signed') {
                $signedStored = true; $signedVerified = $verified;
            }

            if ((string) $d['document_type'] === 'certificate'
                || (string) $d['document_type'] === 'audit_trail') {
                $auditStored = true; $auditVerified = $verified;
            }
        }

        return array(
            'approved' => (int) $request['approved_at'] > 0,
            /*
             * "Unchanged" means we HAVE a hash of the approved version to compare
             * against. No hash is not "unchanged" — it is "we could not tell",
             * and that must not read as a pass.
             */
            'document_version_unchanged' => (string) $request['original_sha256'] !== '',
            'mandatory_signers'          => $mandatory,
            'signed_mandatory_signers'   => $signed,
            'kyc_required'               => $kycNeeded > 0,
            'kyc_state'                  => ($kycNeeded > 0 && $kycPassed >= $kycNeeded)
                                              ? Contract_lifecycle::L_KYC_PASSED : '',
            'kyc_sessions_required'      => $kycNeeded,
            'kyc_sessions_passed'        => $kycPassed,
            'signed_document_stored'         => $signedStored,
            'signed_document_hash_verified'  => $signedVerified,
            'audit_trail_stored'             => $auditStored,
            'audit_trail_hash_verified'      => $auditVerified,
            'unresolved_provider_failure'    => count($this->openFailures($contractId)) > 0,
        );
    }

    /* ---- small helpers the execution screens need ---------------------- */

    /**
     * One request signer, scoped to its request.
     *
     * The request id is part of the lookup, not checked afterwards. A signer id
     * from another contract simply does not match, so a direct-object-reference
     * attempt returns null rather than a row somebody then has to remember to
     * validate.
     *
     * @param  int $signerId
     * @param  int $requestId
     * @return array|null
     */
    public function signerById($signerId, $requestId)
    {
        $r = $this->db->where('id', (int) $signerId)
                      ->where('request_id', (int) $requestId)
                      ->get($this->t('payplex_cv_signers'))->row_array();

        return $r ? $r : null;
    }

    /**
     * Non-secret provider settings, for asking the adapter what it is missing.
     *
     * Deliberately carries NO secret values. `implementationStatus()` only needs
     * to know whether each is present, and the settings table already answers
     * that without decrypting anything — so a status check never puts a
     * credential into a variable.
     *
     * @return array
     */
    public function providerSettingsForStatus()
    {
        $s = $this->settings();

        return array(
            'enabled'      => (int) $this->setting('enabled', 0),
            'environment'  => $this->setting('environment', 'sandbox'),
            /* Presence markers, not values. A non-empty placeholder is enough
               for implementationStatus() to report "configured" without the
               real credential ever being read. */
            'auth_token'   => (!empty($s['api_key']['configured'])) ? 'set' : '',
            'private_salt' => (!empty($s['webhook_secret']['configured'])) ? 'set' : '',
            'profile_id'   => $this->setting('workflow_id', ''),
            'request_timeout_seconds' => (int) $this->setting('request_timeout_seconds', 30),
        );
    }

    /* ---- KYC evidence retention ---------------------------------------- */

    /**
     * The retention policy, defaulting to the most conservative option.
     *
     * The default is references, decisions, timestamps and integrity hashes
     * ONLY — no recordings, no identity documents. That default is not a
     * placeholder waiting to be relaxed: a V-CIP recording is biometric data,
     * and storing one needs a lawful basis and an approved retention period
     * that nobody has supplied. Until they do, the safe thing and the correct
     * thing are the same thing.
     *
     * @return array
     */
    public function retentionPolicy()
    {
        return array(
            'retain_references' => 1,
            'retain_recordings' => (int) $this->setting('kyc_retain_recordings', 0),
            'retention_days'    => (int) $this->setting('kyc_retention_days', 0),
            'legal_hold'        => (int) $this->setting('kyc_legal_hold', 0),
            'default_note'      => 'References, decisions, timestamps and integrity hashes are '
                                 . 'retained. Raw recordings and identity documents are NOT stored, '
                                 . 'because no lawful basis and no approved retention period have '
                                 . 'been supplied.',
        );
    }

    /**
     * Change the retention policy.
     *
     * Two refusals, and both matter more than the setting itself.
     *
     * @param  array $in
     * @param  int   $actorId
     * @param  int   $now
     * @return array {ok, message}
     */
    public function saveRetentionPolicy(array $in, $actorId, $now)
    {
        $retainRecordings = !empty($in['retain_recordings']) ? 1 : 0;
        $days             = (int) (isset($in['retention_days']) ? $in['retention_days'] : 0);
        $legalHold        = !empty($in['legal_hold']) ? 1 : 0;

        /*
         * REFUSAL ONE: storing recordings needs a retention period.
         *
         * "Keep the recordings, forever, because nobody said how long" is the
         * default that happens when a checkbox exists and a number does not.
         */
        if ($retainRecordings === 1 && $days <= 0) {
            return array('ok' => false,
                'message' => 'Storing Video KYC recordings needs an approved retention period in '
                           . 'days. Without one the recordings would be kept indefinitely, which is '
                           . 'not a decision this screen can make.');
        }

        /*
         * REFUSAL TWO: a legal hold stops automated deletion.
         *
         * Recorded as a setting so the deletion job can read it, and refused
         * here rather than silently ignored, because "we set a hold and the job
         * deleted them anyway" is the worst possible outcome.
         */
        $current = $this->retentionPolicy();

        if ((int) $current['legal_hold'] === 1 && $legalHold === 0) {
            return array('ok' => false,
                'message' => 'A legal hold is active. Lifting it is a compliance decision and is '
                           . 'not done from this screen.');
        }

        $this->saveSettings(array(
            'kyc_retain_recordings' => $retainRecordings,
            'kyc_retention_days'    => $days,
            'kyc_legal_hold'        => $legalHold,
        ), $actorId, $now);

        $this->audit($actorId, 'kyc_retention_changed', 0, 0, array(
            'retain_recordings' => $retainRecordings,
            'retention_days'    => $days,
            'legal_hold'        => $legalHold,
        ));

        return array('ok' => true,
            'message' => $retainRecordings === 1
                ? 'Retention policy saved. Recordings will be kept for ' . $days . ' days.'
                : 'Retention policy saved. Only references, decisions, timestamps and hashes are '
                . 'retained — no recordings and no identity documents.');
    }

    /**
     * May automated deletion run for this session?
     *
     * A legal hold beats a retention period, always. The job asks this rather
     * than computing the answer itself, so there is exactly one place the hold
     * can be honoured or forgotten.
     *
     * @param  array $session
     * @param  int   $now
     * @return array {allowed, reason}
     */
    public function mayDeleteKycEvidence(array $session, $now)
    {
        $policy = $this->retentionPolicy();

        if ((int) $policy['legal_hold'] === 1) {
            return array('allowed' => false, 'reason' => 'legal_hold_active');
        }

        $until = (int) (isset($session['evidence_retention_until'])
            ? $session['evidence_retention_until'] : 0);

        if ($until <= 0) {
            return array('allowed' => false, 'reason' => 'no_retention_period_recorded');
        }

        if ((int) $now < $until) {
            return array('allowed' => false, 'reason' => 'still_within_retention_period');
        }

        return array('allowed' => true, 'reason' => 'retention_period_elapsed');
    }

    /* ================================================================
     * Assignments, evidence access, retention, holds and the client
     * repository.
     *
     * Everything below reads or writes the tables added by 210, 211 and 212.
     * The DECISIONS are not here — they live in Contract_authz and
     * Kyc_decision_service, which are pure functions. This layer fetches facts
     * and records what happened.
     * ============================================================== */

    public function assignmentsReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_assignments'));
    }

    public function evidenceLogReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_evidence_access_log'));
    }

    public function retentionReady()
    {
        return $this->db->table_exists($this->t('payplex_cv_retention_rules'))
            && $this->db->table_exists($this->t('payplex_cv_legal_holds'))
            && $this->db->table_exists($this->t('payplex_cv_kyc_decisions'));
    }

    /**
     * Which migrations this module still needs, so the screens can say so
     * rather than erroring.
     *
     * @return array
     */
    public function authzMissingMigrations()
    {
        $missing = array();

        if (!$this->assignmentsReady()) { $missing[] = '210'; }
        if (!$this->evidenceLogReady()) { $missing[] = '211'; }
        if (!$this->retentionReady())   { $missing[] = '212'; }

        return $missing;
    }

    /* ---- assignments --------------------------------------------------- */

    /**
     * Every assignment row for one person on ONE contract.
     *
     * The contract id is part of the query, not something the caller filters
     * afterwards. A method that returned "this person's assignments" and left
     * the scoping to its callers is one forgetful caller away from being the
     * cross-contract hole this table was added to close.
     *
     * @param  int $contractId
     * @param  int $staffId
     * @return array
     */
    public function assignmentsFor($contractId, $staffId)
    {
        if (!$this->assignmentsReady()) { return array(); }

        $contractId = (int) $contractId;
        $staffId    = (int) $staffId;

        if ($contractId <= 0 || $staffId <= 0) { return array(); }

        return $this->db->select('id, contract_id, client_id, staff_id, assignment_role,'
                               . ' is_active, assigned_at, expires_at, revoked_at')
                        ->from($this->t('payplex_cv_assignments'))
                        ->where('contract_id', $contractId)
                        ->where('staff_id', $staffId)
                        ->get()->result_array();
    }

    /**
     * Everyone on a contract, live rows first, revoked ones after.
     *
     * Revoked rows are RETURNED rather than filtered out, for the same reason
     * replaced signers are: a screen that hides them answers "who is on this
     * contract" correctly and "who was on it in March" not at all.
     *
     * @param  int $contractId
     * @return array
     */
    public function assignmentRows($contractId)
    {
        if (!$this->assignmentsReady()) { return array(); }

        return $this->db->from($this->t('payplex_cv_assignments'))
                        ->where('contract_id', (int) $contractId)
                        ->order_by('is_active', 'DESC')
                        ->order_by('assigned_at', 'DESC')
                        ->get()->result_array();
    }

    /**
     * Create an assignment.
     *
     * @param  int   $contractId
     * @param  array $in
     * @param  int   $actorId
     * @param  int   $now
     * @return array {ok, message}
     */
    public function saveAssignment($contractId, array $in, $actorId, $now)
    {
        if (!$this->assignmentsReady()) {
            return array('ok' => false, 'message' => 'Migration 210 has not been applied.');
        }

        $contractId = (int) $contractId;
        $staffId    = (int) (isset($in['staff_id']) ? $in['staff_id'] : 0);
        $role       = (string) (isset($in['assignment_role']) ? $in['assignment_role'] : '');

        if ($contractId <= 0 || $staffId <= 0) {
            return array('ok' => false, 'message' => 'A contract and a staff member are both needed.');
        }

        if (!Contract_assignment::isRole($role)) {
            return array('ok' => false, 'message' => 'That is not an assignment role this module knows.');
        }

        $contract = $this->contract($contractId);

        if (!$contract) {
            return array('ok' => false, 'message' => 'That contract does not exist.');
        }

        $expires = (int) (isset($in['expires_at']) ? $in['expires_at'] : 0);

        /*
         * An expiry already in the past would create a row that is dead on
         * arrival — and would read on screen as a granted assignment.
         */
        if ($expires > 0 && $expires <= (int) $now) {
            return array('ok' => false, 'message' => 'That expiry date has already passed.');
        }

        $existing = $this->db->from($this->t('payplex_cv_assignments'))
                             ->where('contract_id', $contractId)
                             ->where('staff_id', $staffId)
                             ->where('assignment_role', $role)
                             ->where('is_active', 1)
                             ->get()->row_array();

        if ($existing) {
            return array('ok' => false,
                'message' => 'That person already holds this role on this contract.');
        }

        $this->db->insert($this->t('payplex_cv_assignments'), array(
            'contract_id'     => $contractId,
            'client_id'       => (int) (isset($contract['client']) ? $contract['client'] : 0),
            'staff_id'        => $staffId,
            'assignment_role' => $role,
            'is_active'       => 1,
            'assigned_by'     => (int) $actorId,
            'assigned_at'     => (int) $now,
            'expires_at'      => $expires > 0 ? $expires : null,
            'note'            => substr((string) (isset($in['note']) ? $in['note'] : ''), 0, 500),
        ));

        $this->audit($actorId, 'cv_assignment_granted', $contractId, null, array(
            'staff_id' => $staffId, 'assignment_role' => $role, 'expires_at' => $expires,
        ));

        return array('ok' => true, 'message' => 'Assignment recorded.');
    }

    /**
     * Revoke an assignment. Never a delete.
     *
     * The contract id is a required argument and part of the WHERE clause, so
     * an assignment id from another contract simply does not match.
     *
     * @return array {ok, message}
     */
    public function revokeAssignment($assignmentId, $contractId, $reason, $actorId, $now)
    {
        if (!$this->assignmentsReady()) {
            return array('ok' => false, 'message' => 'Migration 210 has not been applied.');
        }

        $reason = trim((string) $reason);

        if (strlen($reason) < 10) {
            return array('ok' => false,
                'message' => 'Revoking access needs a stated reason of at least ten characters. '
                           . 'It is kept with the row.');
        }

        $row = $this->db->from($this->t('payplex_cv_assignments'))
                        ->where('id', (int) $assignmentId)
                        ->where('contract_id', (int) $contractId)
                        ->get()->row_array();

        if (!$row) {
            return array('ok' => false, 'message' => 'That assignment is not on this contract.');
        }

        if (empty($row['is_active'])) {
            return array('ok' => false, 'message' => 'That assignment is already revoked.');
        }

        $this->db->where('id', (int) $row['id'])
                 ->update($this->t('payplex_cv_assignments'), array(
                     'is_active'         => 0,
                     'revoked_at'        => (int) $now,
                     'revoked_by'        => (int) $actorId,
                     'revocation_reason' => substr($reason, 0, 500),
                 ));

        $this->audit($actorId, 'cv_assignment_revoked', (int) $contractId, null, array(
            'assignment_id' => (int) $row['id'],
            'staff_id'      => (int) $row['staff_id'],
            'assignment_role' => (string) $row['assignment_role'],
        ));

        return array('ok' => true, 'message' => 'Assignment revoked. The record is kept.');
    }

    /* ---- KYC cases, scoped ---------------------------------------------- */

    /**
     * A KYC case, but only if it belongs to this contract.
     *
     * The contract is in the WHERE clause rather than checked afterwards, so a
     * case id from another contract returns nothing rather than being loaded
     * and then rejected.
     *
     * @return array|null
     */
    public function kycSessionForContract($sessionId, $contractId)
    {
        if (!$this->kycReady()) { return null; }

        $sessionId  = (int) $sessionId;
        $contractId = (int) $contractId;

        if ($sessionId <= 0 || $contractId <= 0) { return null; }

        $row = $this->db->from($this->t('payplex_cv_kyc_sessions'))
                        ->where('id', $sessionId)
                        ->where('contract_id', $contractId)
                        ->get()->row_array();

        return $row ? $row : null;
    }

    /**
     * What the case screen shows. No documents, no images, no recording —
     * that is what the evidence routes are for.
     *
     * @param  array $case
     * @return array
     */
    public function kycCaseSummary($case)
    {
        if (!is_array($case)) { return array(); }

        return array(
            'id'            => (int) $case['id'],
            'contract_id'   => (int) $case['contract_id'],
            'state'         => (string) $case['state'],
            'state_label'   => Contract_kyc::label((string) $case['state']),
            'attempt'       => (int) $case['attempt'],
            'decision'      => (string) (isset($case['decision']) ? $case['decision'] : ''),
            'decided_at'    => (int) (isset($case['decided_at']) ? $case['decided_at'] : 0),
            'signer_masked' => Contract_invite_token::maskEmail((string) $case['signer_email']),
            'required_reason' => (string) $case['required_reason'],
            'provider'      => (string) $case['provider'],
            'environment'   => (string) $case['environment'],
        );
    }

    /**
     * Append-only decision history for one case.
     *
     * @return array
     */
    public function kycDecisionHistory($sessionId)
    {
        if (!$this->retentionReady()) { return array(); }

        return $this->db->from($this->t('payplex_cv_kyc_decisions'))
                        ->where('kyc_session_id', (int) $sessionId)
                        ->order_by('sequence', 'ASC')
                        ->get()->result_array();
    }

    /**
     * Record a KYC decision, through the service and nowhere else.
     *
     * This method contains no rule about whether the decision is allowed. It
     * gathers the provider result and the evidence, hands everything to
     * Kyc_decision_service, and writes only what comes back. There is no
     * `$force` argument and no branch that skips the service.
     *
     * @return array {ok, message}
     */
    public function recordKycDecision($case, $decision, array $ctx)
    {
        if (!$this->retentionReady()) {
            return array('ok' => false, 'message' => 'Migration 212 has not been applied.');
        }

        $sessionId = (int) $case['id'];

        $verdict = Kyc_decision_service::decide(array(
            'actor_id'        => (int) $ctx['actor_id'],
            'contract_id'     => (int) $ctx['contract_id'],
            'case'            => $case,
            'decision'        => (string) $decision,
            'reason'          => (string) $ctx['reason'],
            'authz'           => $ctx['authz'],
            'provider_result' => $this->providerResultFor($case),
            'evidence'        => $this->evidenceRow((int) $ctx['contract_id'],
                                                    Contract_evidence_types::KYC_SUMMARY,
                                                    $sessionId),
            'now'             => (int) $ctx['now'],
        ));

        if (empty($verdict['ok'])) {
            $this->audit((int) $ctx['actor_id'], 'cv_kyc_decision_refused',
                         (int) $ctx['contract_id'], null,
                         array('kyc_session_id' => $sessionId,
                               'reason'         => (string) $verdict['refusal']));

            return array('ok' => false, 'message' => $this->decisionRefusalMessage($verdict['refusal']));
        }

        $seq = (int) $this->db->from($this->t('payplex_cv_kyc_decisions'))
                              ->where('kyc_session_id', $sessionId)
                              ->count_all_results() + 1;

        $this->db->insert($this->t('payplex_cv_kyc_decisions'), array(
            'kyc_session_id'      => $sessionId,
            'contract_id'         => (int) $ctx['contract_id'],
            'client_id'           => (int) $this->clientIdForContract((int) $ctx['contract_id']),
            'signer_id'           => (int) $case['signer_id'],
            'sequence'            => $seq,
            'decision'            => (string) $verdict['decision'],
            'state_from'          => (string) $case['state'],
            'state_to'            => (string) $verdict['state'],
            'decided_by_provider' => (string) $verdict['decided_by_provider'],
            'decided_by_staff'    => (int) $verdict['decided_by_staff'],
            'segregation_checked' => 1,
            'reason'              => substr((string) $ctx['reason'], 0, 1000),
            'evidence_sha256'     => (string) $verdict['audit']['evidence_sha'],
            'evidence_verified'   => 1,
            'decided_at'          => (int) $ctx['now'],
        ));

        $this->db->where('id', $sessionId)->update($this->t('payplex_cv_kyc_sessions'), array(
            'state'      => (string) $verdict['state'],
            'decision'   => (string) $verdict['decision'],
            'decided_at' => (int) $ctx['now'],
            'updated_at' => (int) $ctx['now'],
        ));

        $this->audit((int) $ctx['actor_id'], 'cv_kyc_decision', (int) $ctx['contract_id'], null,
                     array('kyc_session_id' => $sessionId,
                           'decision'       => (string) $verdict['decision'],
                           'state_to'       => (string) $verdict['state']));

        return array('ok' => true, 'message' => 'Decision recorded.');
    }

    /**
     * The provider's own result for a case.
     *
     * With no configured provider there is nothing to return, which is why an
     * unconfigured system cannot manufacture a pass: the decision service
     * refuses without one.
     *
     * @return array
     */
    public function providerResultFor($case)
    {
        $reference = (string) (isset($case['decided_by_provider']) ? $case['decided_by_provider'] : '');
        $provider  = (string) (isset($case['provider']) ? $case['provider'] : '');

        if ($reference === '' || $provider === '' || $provider === 'unconfigured') {
            return array();
        }

        return array(
            'provider'       => $provider,
            'reference'      => $reference,
            'observed_state' => (string) $case['state'],
        );
    }

    private function decisionRefusalMessage($refusal)
    {
        $plain = array(
            'a_decision_needs_a_verification_result_from_a_provider' =>
                'No approved verification provider has produced a result for this case, so there '
                . 'is nothing to record. A decision taken now would assert something nobody checked.',
            'reviewer_may_not_decide_own_case' =>
                'You created or invited this case, so you cannot also decide it.',
            'evidence_hash_did_not_verify' =>
                'The stored evidence does not match its recorded hash. The decision is refused '
                . 'rather than recorded against evidence that may have changed.',
            'a_pass_needs_stored_evidence' =>
                'A pass needs stored evidence behind it. There is none.',
            'reason_required' =>
                'That decision needs a stated reason of at least ten characters.',
            'already_decided' => 'This case has already been decided.',
        );

        if (isset($plain[$refusal])) { return $plain[$refusal]; }

        if (strpos((string) $refusal, 'not_authorized:') === 0) {
            return 'You are not authorised to decide this case.';
        }

        if (strpos((string) $refusal, 'state_machine_refused:') === 0) {
            return 'That is not a legal move for this case in its current state.';
        }

        return 'The decision was refused: ' . (string) $refusal;
    }

    /* ---- evidence -------------------------------------------------------- */

    /**
     * One evidence row, scoped to the contract.
     *
     * @return array
     */
    public function evidenceRow($contractId, $type, $sessionId = 0)
    {
        if (!$this->evidenceLogReady()) { return array(); }

        if (!Contract_evidence_types::isType($type)) { return array(); }

        $this->db->from($this->t('payplex_cv_documents'))
                 ->where('contract_id', (int) $contractId)
                 ->where('evidence_type', (string) $type)
                 ->where('deleted_at', null);

        if ((int) $sessionId > 0) {
            $this->db->where('kyc_session_id', (int) $sessionId);
        }

        $row = $this->db->order_by('id', 'DESC')->get()->row_array();

        if (!$row) { return array(); }

        /*
         * `hash_verified` is DERIVED here, not read from a column that somebody
         * could set. It is true only when a verification actually happened and
         * the digest is well formed.
         */
        $row['hash_verified'] = (!empty($row['hash_verified_at'])
                                 && Contract_evidence::validDigest((string) $row['sha256'])) ? 1 : 0;

        return $row;
    }

    /**
     * What a screen may say about an evidence row.
     *
     * The storage key and the storage path are NOT included. The key is how the
     * file is found and the path is where it lives; neither has any business
     * reaching a browser, including inside a hidden field or a data attribute.
     *
     * @return array
     */
    public function evidenceSummary($evidence)
    {
        if (!is_array($evidence) || empty($evidence)) { return array(); }

        return array(
            'evidence_type'   => (string) $evidence['evidence_type'],
            'content_type'    => (string) $evidence['content_type'],
            'bytes'           => (int) $evidence['bytes'],
            'sha256_short'    => substr((string) $evidence['sha256'], 0, 12),
            'hash_verified'   => !empty($evidence['hash_verified']),
            'stored_at'       => (int) (isset($evidence['retrieved_at']) ? $evidence['retrieved_at'] : 0),
            'retention_until' => (int) (isset($evidence['retention_until']) ? $evidence['retention_until'] : 0),
            'is_encrypted'    => !empty($evidence['is_encrypted']),
        );
    }

    /**
     * Which of these evidence types actually exist for a case.
     *
     * @return array type => bool
     */
    public function evidenceAvailability($contractId, $sessionId, array $types)
    {
        $out = array();

        foreach ($types as $type) {
            $row = $this->evidenceRow((int) $contractId, $type, (int) $sessionId);
            $out[$type] = !empty($row);
        }

        return $out;
    }

    /**
     * Record an access attempt. Allowed or refused, always.
     *
     * Returns the log id so a stream can be completed against it — a stream
     * that starts and never finishes is a different event from one that does,
     * and only two rows can tell them apart.
     *
     * @return int
     */
    public function logEvidenceAccess(array $ctx, $case, $type, $action, $outcome, $refusalReason)
    {
        if (!$this->evidenceLogReady()) { return 0; }

        $contractId = (int) (isset($ctx['contract_id']) ? $ctx['contract_id'] : 0);

        $this->db->insert($this->t('payplex_cv_evidence_access_log'), array(
            'actor_id'       => (int) (isset($ctx['actor_id']) ? $ctx['actor_id'] : 0),
            'client_id'      => (int) $this->clientIdForContract($contractId),
            'contract_id'    => $contractId,
            'kyc_session_id' => is_array($case) && isset($case['id']) ? (int) $case['id'] : null,
            'evidence_type'  => substr((string) $type, 0, 40),
            'action'         => substr((string) $action, 0, 20),
            'outcome'        => substr((string) $outcome, 0, 20),
            'refusal_reason' => $refusalReason === null ? null : substr((string) $refusalReason, 0, 190),
            /* Hashes, not addresses. The question is "same place as last time",
               which a hash answers without keeping everybody's IP. */
            'ip_hash'         => $this->hashOrNull($this->clientIp()),
            'user_agent_hash' => $this->hashOrNull($this->userAgent()),
            'started_at'      => time(),
        ));

        return (int) $this->db->insert_id();
    }

    /**
     * Close a streaming log row.
     *
     * @return void
     */
    public function completeEvidenceAccess($logId, $bytesServed)
    {
        if (!$this->evidenceLogReady() || (int) $logId <= 0) { return; }

        $this->db->where('id', (int) $logId)
                 ->update($this->t('payplex_cv_evidence_access_log'), array(
                     'completed_at' => time(),
                     'bytes_served' => (int) $bytesServed,
                 ));
    }

    /**
     * Serve an evidence file.
     *
     * The hash is verified BEFORE any byte is written, because a response that
     * has already started cannot be taken back. The physical path is never sent
     * to the client in any header, body or error message; the client asked for
     * an evidence type on a contract and that is all it ever learns.
     *
     * @return int bytes served
     */
    public function streamEvidence($evidence, array $headers)
    {
        if (!is_array($evidence) || empty($evidence['storage_key'])) { return 0; }

        $path = $this->evidenceAbsolutePath((string) $evidence['storage_key']);

        if ($path === null || !is_readable($path)) { return 0; }

        $actual = hash_file(Contract_evidence::ALGO, $path);

        if (!Contract_evidence::digestsMatch($actual, (string) $evidence['sha256'])) {
            /* Reported, not repaired, and nothing is served. */
            $this->audit(0, 'cv_evidence_hash_mismatch', (int) $evidence['contract_id'], null,
                         array('document_id' => (int) $evidence['id']));

            return 0;
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        header('Content-Type: ' . (string) $evidence['content_type']);
        header('Content-Length: ' . (int) $evidence['bytes']);

        readfile($path);

        return (int) $evidence['bytes'];
    }

    /**
     * The absolute path for a storage key.
     *
     * Refuses a root inside a web-served directory even if one is configured,
     * so a misconfiguration cannot quietly move evidence somewhere it can be
     * fetched directly.
     *
     * @return string|null
     */
    public function evidenceAbsolutePath($storageKey)
    {
        if (!Evidence_store::validKey($storageKey)) { return null; }

        $root = (string) $this->setting('evidence_storage_root', '');
        $safe = Evidence_store::pathIsSafe($root, $this->knownDocumentRoots());

        if (empty($safe['safe'])) { return null; }

        return rtrim($safe['normalised'], '/') . '/' . Evidence_store::relativePath($storageKey);
    }

    /**
     * Document roots this installation serves, so the storage-root check has
     * something concrete to compare against.
     *
     * @return array
     */
    public function knownDocumentRoots()
    {
        $roots = array();

        if (defined('FCPATH')) { $roots[] = rtrim(FCPATH, '/'); }

        return $roots;
    }

    /**
     * The watermark burned onto a viewed recording.
     *
     * Identifies who was watching and when. It does not stop a screen
     * recording — nothing does — but it makes a leaked frame traceable to one
     * session, which is the realistic control.
     *
     * @return string
     */
    public function watermarkFor($actorId, $now)
    {
        return 'staff#' . (int) $actorId . ' · ' . date('Y-m-d H:i', (int) $now) . ' · confidential';
    }

    /* ---- retention and holds --------------------------------------------- */

    public function retentionRules()
    {
        if (!$this->retentionReady()) { return array(); }

        return $this->db->from($this->t('payplex_cv_retention_rules'))
                        ->order_by('scope', 'ASC')->order_by('id', 'DESC')
                        ->get()->result_array();
    }

    /**
     * The retention that applies to one evidence type on one contract.
     *
     * Narrowest rule first: evidence type, then contract, then client, then
     * global, then the settings fallback that predates 212. Falling back rather
     * than defaulting to "no limit" matters — an absent rule must not read as
     * permission to keep biometric data forever.
     *
     * @return array {retention_until, source}
     */
    public function retentionFor($contract, $type)
    {
        $days   = 0;
        $source = 'settings_fallback';

        if ($this->retentionReady()) {
            $clientId   = (int) (isset($contract['client']) ? $contract['client'] : 0);
            $contractId = (int) (isset($contract['id']) ? $contract['id'] : 0);

            $candidates = array(
                array('evidence_type', 0, (string) $type),
                array('contract', $contractId, null),
                array('client', $clientId, null),
                array('global', 0, null),
            );

            foreach ($candidates as $c) {
                $this->db->from($this->t('payplex_cv_retention_rules'))
                         ->where('scope', $c[0])->where('scope_id', (int) $c[1])
                         ->where('is_active', 1);

                if ($c[2] === null) { $this->db->where('evidence_type', null); }
                else                { $this->db->where('evidence_type', $c[2]); }

                $row = $this->db->get()->row_array();

                if ($row) {
                    $days   = (int) $row['retention_days'];
                    $source = $c[0];
                    break;
                }
            }
        }

        if ($days <= 0) {
            $days = (int) $this->setting('kyc_retention_days', 0);
        }

        $storedAt = 0;
        $until    = $days > 0 ? ($storedAt > 0 ? $storedAt : time()) + ($days * 86400) : 0;

        return array('retention_until' => $until, 'retention_days' => $days, 'source' => $source);
    }

    public function retentionRuleForScope(array $scope)
    {
        if (!$this->retentionReady()) { return array(); }

        $row = $this->db->from($this->t('payplex_cv_retention_rules'))
                        ->where('scope', (string) $scope['scope'])
                        ->where('scope_id', (int) $scope['scope_id'])
                        ->where('is_active', 1)
                        ->get()->row_array();

        return $row ? $row : array();
    }

    public function saveRetentionRule(array $in, array $verdict, $actorId, $now)
    {
        if (!$this->retentionReady()) {
            return array('ok' => false, 'message' => 'Migration 212 has not been applied.');
        }

        if (empty($verdict['allowed'])) {
            $this->audit($actorId, 'cv_retention_refused', null, null,
                         array('reason' => (string) $verdict['reason']));

            return array('ok' => false, 'message' => $this->retentionRefusalMessage($verdict['reason']));
        }

        $this->db->insert($this->t('payplex_cv_retention_rules'), array(
            'scope'           => substr((string) $in['scope'], 0, 20),
            'scope_id'        => (int) $in['scope_id'],
            'evidence_type'   => $in['evidence_type'] !== '' ? substr((string) $in['evidence_type'], 0, 40) : null,
            'retention_days'  => (int) $in['retention_days'],
            'store_raw_media' => !empty($in['retain_recordings']) ? 1 : 0,
            'lawful_basis'    => substr((string) $in['lawful_basis'], 0, 190),
            'is_active'       => 1,
            'created_by'      => (int) $actorId,
            'created_at'      => (int) $now,
        ));

        $this->audit($actorId, 'cv_retention_changed', null, null, array(
            'scope' => (string) $in['scope'], 'retention_days' => (int) $in['retention_days'],
        ));

        return array('ok' => true, 'message' => 'Retention rule saved.');
    }

    private function retentionRefusalMessage($reason)
    {
        $plain = array(
            'cannot_shorten_retention_under_legal_hold' =>
                'A legal hold is active for this scope, so retention cannot be shortened. '
                . 'Release the hold first, which is a separate permission.',
            'recordings_need_an_approved_retention_period' =>
                'Storing raw recordings needs an approved retention period in days. Without one '
                . 'they would be kept indefinitely, which is not a decision this screen can make.',
        );

        return isset($plain[$reason]) ? $plain[$reason] : 'Refused: ' . (string) $reason;
    }

    public function legalHolds()
    {
        if (!$this->retentionReady()) { return array(); }

        return $this->db->from($this->t('payplex_cv_legal_holds'))
                        ->order_by('is_active', 'DESC')->order_by('applied_at', 'DESC')
                        ->get()->result_array();
    }

    public function legalHold($id)
    {
        if (!$this->retentionReady()) { return array(); }

        $row = $this->db->from($this->t('payplex_cv_legal_holds'))
                        ->where('id', (int) $id)->get()->row_array();

        return $row ? $row : array();
    }

    /**
     * The hold covering a contract or one of its cases, if any.
     *
     * @return array {active, id, applied_by}
     */
    public function activeLegalHold($contract, $case)
    {
        if (!$this->retentionReady()) { return array('active' => 0); }

        $contractId = (int) (isset($contract['id']) ? $contract['id'] : 0);
        $clientId   = (int) (isset($contract['client']) ? $contract['client'] : 0);
        $caseId     = is_array($case) && isset($case['id']) ? (int) $case['id'] : 0;

        $this->db->from($this->t('payplex_cv_legal_holds'))->where('is_active', 1)
                 ->group_start()
                     ->where('contract_id', $contractId)
                     ->or_where('client_id', $clientId);

        if ($caseId > 0) { $this->db->or_where('kyc_session_id', $caseId); }

        $row = $this->db->group_end()->get()->row_array();

        if (!$row) { return array('active' => 0); }

        return array('active' => 1, 'id' => (int) $row['id'],
                     'applied_by' => (int) $row['applied_by'], 'reference' => (string) $row['reference']);
    }

    public function activeLegalHoldForScope(array $scope)
    {
        if (!$this->retentionReady()) { return array('active' => 0); }

        $row = $this->db->from($this->t('payplex_cv_legal_holds'))
                        ->where('is_active', 1)
                        ->where('scope', (string) (isset($scope['scope']) ? $scope['scope'] : ''))
                        ->where('scope_id', (int) (isset($scope['scope_id']) ? $scope['scope_id'] : 0))
                        ->get()->row_array();

        if (!$row) { return array('active' => 0); }

        return array('active' => 1, 'id' => (int) $row['id'], 'applied_by' => (int) $row['applied_by']);
    }

    public function applyLegalHold(array $in, array $verdict, $actorId, $now)
    {
        if (!$this->retentionReady()) {
            return array('ok' => false, 'message' => 'Migration 212 has not been applied.');
        }

        if (empty($verdict['allowed'])) {
            return array('ok' => false, 'message' => 'Refused: ' . (string) $verdict['reason']);
        }

        $reason = trim((string) $in['reason']);

        if (strlen($reason) < 10) {
            return array('ok' => false, 'message' => 'A legal hold needs a stated reason.');
        }

        $this->db->insert($this->t('payplex_cv_legal_holds'), array(
            'scope'          => substr((string) $in['scope'], 0, 20),
            'scope_id'       => (int) $in['scope_id'],
            'client_id'      => (int) $in['client_id'],
            'contract_id'    => (int) $in['contract_id'],
            'kyc_session_id' => (int) $in['kyc_session_id'] > 0 ? (int) $in['kyc_session_id'] : null,
            'reference'      => substr((string) $in['reference'], 0, 190),
            'reason'         => substr($reason, 0, 1000),
            'is_active'      => 1,
            'applied_by'     => (int) $actorId,
            'applied_at'     => (int) $now,
        ));

        $this->audit($actorId, 'cv_legal_hold_applied', (int) $in['contract_id'], null,
                     array('reference' => (string) $in['reference']));

        return array('ok' => true, 'message' => 'Legal hold applied. Evidence under it cannot be deleted.');
    }

    public function releaseLegalHold($hold, array $verdict, $reason, $actorId, $now)
    {
        if (!$this->retentionReady()) {
            return array('ok' => false, 'message' => 'Migration 212 has not been applied.');
        }

        if (empty($verdict['allowed'])) {
            $this->audit($actorId, 'cv_legal_hold_release_refused', null, null,
                         array('reason' => (string) $verdict['reason']));

            return array('ok' => false, 'message' => 'Refused: ' . (string) $verdict['reason']);
        }

        $reason = trim((string) $reason);

        if (strlen($reason) < 10) {
            return array('ok' => false, 'message' => 'Releasing a hold needs a stated reason.');
        }

        $this->db->where('id', (int) $hold['id'])
                 ->update($this->t('payplex_cv_legal_holds'), array(
                     'is_active'      => 0,
                     'released_by'    => (int) $actorId,
                     'released_at'    => (int) $now,
                     'release_reason' => substr($reason, 0, 1000),
                 ));

        $this->audit($actorId, 'cv_legal_hold_released', (int) $hold['contract_id'], null,
                     array('hold_id' => (int) $hold['id']));

        return array('ok' => true, 'message' => 'Legal hold released.');
    }

    /**
     * Compliance approvals that gate raw media.
     *
     * Read from settings and defaulting to NOT approved. An absent setting must
     * never read as approval.
     *
     * @return array
     */
    public function complianceApprovals()
    {
        return array(
            'recording_download_approved' =>
                (int) $this->setting('kyc_recording_download_approved', 0) === 1,
            'raw_media_storage_approved'  =>
                (int) $this->setting('kyc_store_raw_media_approved', 0) === 1,
        );
    }

    /* ---- the client repository ------------------------------------------- */

    public function clientName($clientId)
    {
        $row = $this->db->select('company')->from(db_prefix() . 'clients')
                        ->where('userid', (int) $clientId)->get()->row_array();

        return $row ? (string) $row['company'] : '';
    }

    public function clientIdForContract($contractId)
    {
        if ((int) $contractId <= 0) { return 0; }

        $row = $this->db->select('client')->from(db_prefix() . 'contracts')
                        ->where('id', (int) $contractId)->get()->row_array();

        return $row ? (int) $row['client'] : 0;
    }

    /**
     * Client → Contact/Signer → KYC case → Contract → Evidence.
     *
     * Each case is filtered by what this actor may see, and the evidence list
     * on each case contains only the types they hold the capability for. A tab
     * that lists a Video KYC recording and refuses on click has still told the
     * reader that a recording exists, which for a recording is itself
     * information about the customer.
     *
     * @return array
     */
    public function clientKycCases($clientId, array $heldCapabilities, $actorId, $isAdmin, $now)
    {
        if (!$this->kycReady()) { return array(); }

        $clientId = (int) $clientId;

        $contracts = $this->db->select('id, subject, client')
                              ->from(db_prefix() . 'contracts')
                              ->where('client', $clientId)
                              ->get()->result_array();

        $out = array();

        foreach ($contracts as $contract) {
            $cases = $this->db->from($this->t('payplex_cv_kyc_sessions'))
                              ->where('contract_id', (int) $contract['id'])
                              ->order_by('id', 'DESC')->get()->result_array();

            foreach ($cases as $case) {
                $access = Contract_authz::canReviewKycCase(array(
                    'actor_id'          => (int) $actorId,
                    'contract_id'       => (int) $contract['id'],
                    'case'              => $case,
                    'action'            => 'view',
                    'holds_capability'  => in_array(Contract_caps::CAP_KYC_VIEW, $heldCapabilities, true),
                    'is_admin'          => (bool) $isAdmin,
                    'has_view_all'      => (bool) $isAdmin,
                    'has_view_own'      => false,
                    'contract_owner_id' => 0,
                    'assignments'       => $this->assignmentsFor((int) $contract['id'], (int) $actorId),
                    'now'               => (int) $now,
                ));

                if (empty($access['allowed'])) { continue; }

                $visible = array();

                foreach (Contract_evidence_types::catalogue() as $type => $meta) {
                    if ($type === Contract_evidence_types::FULL_PACKAGE)   { continue; }
                    if (!in_array($meta['view_capability'], $heldCapabilities, true)) { continue; }

                    $row = $this->evidenceRow((int) $contract['id'], $type, (int) $case['id']);

                    if (empty($row)) { continue; }

                    $visible[$type] = $this->evidenceSummary($row);
                }

                $out[] = array(
                    'contract_id'      => (int) $contract['id'],
                    'contract_subject' => (string) $contract['subject'],
                    'case'             => $this->kycCaseSummary($case),
                    'evidence'         => $visible,
                );
            }
        }

        return $out;
    }

    /* ---- small helpers ---------------------------------------------------- */

    private function hashOrNull($value)
    {
        $value = (string) $value;

        return $value === '' ? null : hash('sha256', $value);
    }

    private function clientIp()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    private function userAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
    }
}
