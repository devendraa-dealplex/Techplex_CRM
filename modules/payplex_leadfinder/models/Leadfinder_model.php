<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_phone.php';
require_once __DIR__ . '/../libraries/Leadfinder_secret.php';
require_once __DIR__ . '/../libraries/Leadfinder_scope.php';
require_once __DIR__ . '/../libraries/Leadfinder_claim.php';
require_once __DIR__ . '/../libraries/Leadfinder_status.php';
require_once __DIR__ . '/../libraries/Leadfinder_fieldmask.php';
require_once __DIR__ . '/../libraries/Leadfinder_retention.php';
require_once __DIR__ . '/../libraries/Leadfinder_places.php';
require_once __DIR__ . '/../libraries/Leadfinder_dupe.php';
require_once __DIR__ . '/../libraries/Leadfinder_event.php';
require_once __DIR__ . '/../libraries/Leadfinder_suppression.php';
require_once __DIR__ . '/../libraries/Leadfinder_profile_select.php';
require_once __DIR__ . '/../libraries/Leadfinder_quota.php';
require_once __DIR__ . '/../libraries/Leadfinder_quota_plan.php';
require_once __DIR__ . '/../libraries/Leadfinder_waste.php';
require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';
require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
require_once __DIR__ . '/../libraries/Leadfinder_ui.php';
require_once __DIR__ . '/../libraries/Leadfinder_retention_policy.php';

/**
 * Leadfinder_model — the data layer, and the only place that talks to Google.
 *
 * WHY THIS FILE IS THIN
 * ---------------------
 * Every decision it needs has already been made by a pure library with its own
 * tests: may this person claim it, is this a duplicate, what may be cached, what
 * does the FieldMask cost. This file does the parts that need a database and a
 * socket, and calls out for every judgement.
 *
 * That is deliberate. The thing this module was missing entirely — and shipped
 * without for a whole phase — was exactly this wiring, and the reason nobody
 * noticed is that all the interesting logic lived elsewhere and passed. Keeping
 * the glue boring is what makes the boring structural test in
 * DeployabilityTest sufficient.
 *
 * NO SILENT NO-OPS
 * ----------------
 * Every method either does its work or returns a named failure. Nothing returns
 * an empty array to mean "something went wrong" — an empty result and a failed
 * result are different answers and callers act on them differently.
 */
class Leadfinder_model extends App_Model
{
    /** The eight tables migration 101 creates. */
    private function tables()
    {
        return array('payplex_lf_api_profiles', 'payplex_lf_profile_staff',
                     'payplex_lf_prospects', 'payplex_lf_ownership',
                     'payplex_lf_searches', 'payplex_lf_usage',
                     'payplex_lf_audit', 'payplex_lf_config');
    }

    private function t($name) { return db_prefix() . $name; }

    /* ================================================================
     * Schema
     * ============================================================== */

    /**
     * Are all eight tables present?
     *
     * Checks every one, not just the first. A partially applied migration is
     * the state most likely to produce a confusing fatal halfway through a
     * request, and it is exactly what a "does the first table exist" check
     * would wave through.
     */
    public function schemaReady()
    {
        foreach ($this->tables() as $t) {
            if (!$this->db->table_exists($this->t($t))) { return false; }
        }
        return true;
    }

    /** Which tables are missing — for the not_migrated view. */
    public function missingTables()
    {
        $missing = array();
        foreach ($this->tables() as $t) {
            if (!$this->db->table_exists($this->t($t))) { $missing[] = $this->t($t); }
        }
        return $missing;
    }

    /* ================================================================
     * Configuration
     * ============================================================== */

    private $cfgCache = null;

    /**
     * One configuration value, latest effective row wins.
     *
     * Returns '' for an absent key rather than null, because every caller
     * compares against a string and a null would make `=== '1'` quietly false
     * in a way that reads like a deliberate "off".
     */
    public function config($key, $default = '')
    {
        if ($this->cfgCache === null) {
            $this->cfgCache = array();
            if ($this->db->table_exists($this->t('payplex_lf_config'))) {
                $rows = $this->db->order_by('effective_from', 'ASC')
                                 ->get($this->t('payplex_lf_config'))->result_array();
                foreach ($rows as $r) { $this->cfgCache[$r['ckey']] = (string) $r['cvalue']; }
            }
        }
        return array_key_exists($key, $this->cfgCache) ? $this->cfgCache[$key] : $default;
    }

    public function configInt($key, $default = 0)
    {
        $v = trim($this->config($key, ''));
        return $v === '' ? (int) $default : (int) $v;
    }

    /** The phone profile, decoded. An unparseable value yields no profile, so
     *  Payplex_phone refuses national numbers rather than guessing a country. */
    public function phoneProfile()
    {
        $j = $this->config('phone_profile', '');
        if ($j === '') { return array(); }
        $d = json_decode($j, true);
        return is_array($d) ? $d : array();
    }

    public function claimConfig()
    {
        return array(
            'idle_release_seconds' => $this->configInt('claim_idle_release_seconds', 0),
            'daily_claim_limit'    => $this->configInt('daily_claim_limit', 0),
        );
    }

    /**
     * Why searching is blocked right now, or '' if it is not.
     *
     * The compliance date is a hard gate: §18 requires the operator to have
     * confirmed Google's current terms, and running searches on an unconfirmed
     * assumption is the thing that gate exists to prevent.
     */
    public function complianceBlock()
    {
        if (trim($this->config('google_terms_confirmed_on', '')) === '') {
            return 'Google Maps Platform terms have not been confirmed. An administrator must '
                 . 'record the date they checked the current Service Specific Terms before any '
                 . 'search may run.';
        }
        if ($this->configInt('coords_max_calendar_days', 0) <= 0) {
            return 'The coordinate retention period is not configured. Searching is blocked '
                 . 'rather than storing coordinates with no expiry.';
        }
        return '';
    }

    /* ================================================================
     * API connection profiles
     * ============================================================== */

    private function encryptor()
    {
        $ci = &get_instance();
        $ci->load->library('encryption');
        return function ($plain) use ($ci) { return $ci->encryption->encrypt($plain); };
    }

    private function decryptor()
    {
        $ci = &get_instance();
        $ci->load->library('encryption');
        return function ($cipher) use ($ci) { return $ci->encryption->decrypt($cipher); };
    }

    /** Profiles this employee may spend. Active only — an inactive profile is
     *  not an option an employee should be able to pick and then be refused. */
    public function profilesForStaff($staffId)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { return array(); }

        $this->db->select('p.id, p.name, p.monthly_search_limit, p.monthly_detail_limit, p.daily_usage_limit');
        $this->db->from($this->t('payplex_lf_api_profiles') . ' p');
        $this->db->join($this->t('payplex_lf_profile_staff') . ' ps', 'ps.profile_id = p.id');
        $this->db->where('ps.staff_id', $staffId);
        $this->db->where('p.active', 1);
        $this->db->order_by('p.name', 'ASC');
        return $this->db->get()->result_array();
    }

    /**
     * The admin list. Each row carries a mask and a fingerprint and NEVER the
     * ciphertext — the view has no use for it and every value handed to a
     * template can end up in a page source or a screenshot.
     */
    public function profilesForDisplay()
    {
        $dec  = $this->decryptor();
        $rows = $this->db->order_by('name', 'ASC')
                         ->get($this->t('payplex_lf_api_profiles'))->result_array();
        $out = array();
        foreach ($rows as $r) {
            $key = Leadfinder_secret::forDisplay(isset($r['api_key_enc']) ? $r['api_key_enc'] : '', $dec);
            unset($r['api_key_enc']);
            $r['key_state']       = $key['state'];
            $r['key_masked']      = $key['masked'];
            $r['key_fingerprint'] = $key['fingerprint'];
            $r['staff_ids']       = $this->staffForProfile((int) $r['id']);
            $r['usage']           = $this->usageFor((int) $r['id']);
            $out[] = $r;
        }
        return $out;
    }

    public function staffForProfile($profileId)
    {
        $rows = $this->db->select('staff_id')
                         ->where('profile_id', (int) $profileId)
                         ->get($this->t('payplex_lf_profile_staff'))->result_array();
        $ids = array();
        foreach ($rows as $r) { $ids[] = (int) $r['staff_id']; }
        return $ids;
    }

    /**
     * Create or update a profile.
     *
     * A blank key field means LEAVE THE STORED KEY ALONE. The value is never
     * sent to the browser, so the field always renders empty; treating blank as
     * "erase" would wipe the key every time somebody edited a monthly limit.
     */
    public function saveProfile($actorId, array $d, $apiKeyPlain)
    {
        $actorId = (int) $actorId;
        $name    = trim((string) $d['name']);
        if ($name === '') { return array('ok' => false, 'message' => 'A connection name is required.'); }

        $row = array(
            'name'                 => $name,
            'gcp_project'          => trim((string) $d['gcp_project']),
            'billing_label'        => trim((string) $d['billing_label']),
            'monthly_search_limit' => max(0, (int) $d['monthly_search_limit']),
            'monthly_detail_limit' => max(0, (int) $d['monthly_detail_limit']),
            'daily_usage_limit'    => max(0, (int) $d['daily_usage_limit']),
            'active'               => !empty($d['active']) ? 1 : 0,
            'admin_notes'          => (string) $d['admin_notes'],
            'updated_at'           => time(),
        );

        /*
         * Dates are validated on the WAY IN, not only on the way out.
         *
         * The selection library already refuses a nonsense date when it reads
         * one — `checkdate()` rejects 2026-02-31, which matches the shape of a
         * date perfectly. But refusing it on read means the column happily
         * stores it, the administrator sees their typo accepted, and the profile
         * silently behaves as though no date were set at all. Rejecting the save
         * tells them at the moment they can still fix it.
         */
        foreach (array('expires_on', 'effective_from') as $field) {
            $raw = isset($d[$field]) ? trim((string) $d[$field]) : '';

            if ($raw === '') {
                $row[$field] = null;
                continue;
            }

            $clean = Leadfinder_profile_select::validDate($raw);

            if ($clean === null) {
                return array('ok' => false,
                             'message' => 'That ' . str_replace('_', ' ', $field)
                                        . ' is not a real calendar date. Nothing was saved.');
            }

            $row[$field] = $clean;
        }

        /* The per-employee ceiling for THIS key. Zero means "no ceiling of its
           own" and the global default applies — see Leadfinder_profile_select. */
        $row['per_staff_daily_limit'] = max(0, (int) (isset($d['per_staff_daily_limit'])
            ? $d['per_staff_daily_limit'] : 0));

        /* The fingerprint before this save, so a rotation can be recorded
           against what it replaced. Read before the update, obviously. */
        $previousFingerprint = null;

        if ((int) $d['id'] > 0) {
            $prev = $this->db->select('api_key_fingerprint')
                             ->where('id', (int) $d['id'])
                             ->get($this->t('payplex_lf_api_profiles'))->row_array();
            $previousFingerprint = empty($prev) ? null : $prev['api_key_fingerprint'];
        }

        $keyChanged = false;
        if (is_string($apiKeyPlain) && trim($apiKeyPlain) !== '') {
            $sealed = Leadfinder_secret::seal($apiKeyPlain, $this->encryptor());
            if (empty($sealed['ok'])) {
                return array('ok' => false,
                             'message' => 'The API key was not stored: ' . $sealed['error']
                                        . '. Nothing was saved.');
            }
            $row['api_key_enc']         = $sealed['value'];
            $row['api_key_fingerprint'] = Leadfinder_secret::fingerprint(trim($apiKeyPlain));
            $keyChanged = true;
        }

        $id = (int) $d['id'];
        if ($id > 0) {
            $this->db->where('id', $id)->update($this->t('payplex_lf_api_profiles'), $row);
        } else {
            $row['created_by'] = $actorId;
            $row['created_at'] = time();
            $this->db->insert($this->t('payplex_lf_api_profiles'), $row);
            $id = (int) $this->db->insert_id();
        }
        if ($id <= 0) { return array('ok' => false, 'message' => 'The profile could not be saved.'); }

        $this->setProfileStaff($id, isset($d['staff_ids']) ? (array) $d['staff_ids'] : array(), $actorId);

        /* The audit records THAT the key changed and its fingerprint. Never the
           key, never its length. */
        if ($keyChanged) {
            $this->recordKeyRotation($id, $previousFingerprint,
                                     $row['api_key_fingerprint'], 'replaced via profile save', $actorId);
        }

        $this->audit($actorId, 'api_profile_saved', 'api_profile', $id, array(
            'name' => $name, 'active' => $row['active'],
            'key_changed' => $keyChanged,
            'key_fingerprint' => $keyChanged ? $row['api_key_fingerprint'] : null,
        ));

        return array('ok' => true,
                     'message' => 'Connection profile saved.'
                                . ($keyChanged ? ' The API key was replaced.' : ' The stored key was left unchanged.'),
                     'id' => $id);
    }

    /**
     * One line of rotation history. Fingerprints, actor and time — nothing else.
     *
     * The table's fingerprint columns are 24 characters wide, which is narrower
     * than any API key Google issues. That is deliberate and it is the point: a
     * rotation log that could hold the superseded key would be a worse leak than
     * the thing it audits, because the old key is exactly what an attacker wants
     * after a rotation. The column cannot hold one, so no future edit to this
     * method can quietly start storing one.
     */
    public function recordKeyRotation($profileId, $oldFingerprint, $newFingerprint, $reason, $actorId)
    {
        try {
            $table = $this->t('payplex_lf_key_rotations');

            if (!$this->db->table_exists($table)) { return false; }

            $this->db->insert($table, array(
                'profile_id'      => (int) $profileId,
                'old_fingerprint' => $oldFingerprint === null ? null : substr((string) $oldFingerprint, 0, 24),
                'new_fingerprint' => $newFingerprint === null ? null : substr((string) $newFingerprint, 0, 24),
                'reason'          => mb_substr((string) $reason, 0, 191),
                'rotated_by'      => (int) $actorId,
                'rotated_at'      => time(),
            ));

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function keyRotations($profileId = 0, $limit = 20)
    {
        try {
            $table = $this->t('payplex_lf_key_rotations');

            if (!$this->db->table_exists($table)) { return array(); }

            if ((int) $profileId > 0) { $this->db->where('profile_id', (int) $profileId); }

            return $this->db->order_by('id', 'desc')->limit((int) $limit)->get($table)->result_array();
        } catch (Throwable $e) {
            return array();
        }
    }

    /**
     * Erase a stored key — the separate, explicit action.
     *
     * Saving with a blank key field means "leave it alone", because the field
     * renders empty every time and treating blank as an instruction would wipe
     * the key whenever somebody edited a monthly limit. Erasing therefore needs
     * its own verb, and it deactivates the profile in the same breath: a profile
     * with no key that is still marked active is a profile that will be selected
     * and then fail at the API, which reads to the employee as a broken search
     * rather than a missing key.
     */
    public function clearProfileKey($profileId, $actorId, $reason = '')
    {
        $profileId = (int) $profileId;

        if ($profileId < 1) { return array('ok' => false, 'message' => 'No profile was named.'); }

        $p = $this->db->where('id', $profileId)->get($this->t('payplex_lf_api_profiles'))->row_array();

        if (empty($p)) { return array('ok' => false, 'message' => 'That profile does not exist.'); }

        $this->db->where('id', $profileId)->update($this->t('payplex_lf_api_profiles'), array(
            'api_key_enc'         => null,
            'api_key_fingerprint' => null,
            'active'              => 0,
            'updated_at'          => time(),
        ));

        $this->recordKeyRotation($profileId, $p['api_key_fingerprint'], null,
                                 $reason === '' ? 'key cleared' : $reason, $actorId);

        $this->audit($actorId, 'api_profile_key_cleared', 'api_profile', $profileId, array(
            'name' => isset($p['name']) ? $p['name'] : '',
            'previous_fingerprint' => $p['api_key_fingerprint'],
            'deactivated' => true,
        ));

        return array('ok' => true,
                     'message' => 'The stored key was erased and the connection was deactivated.');
    }

    private function setProfileStaff($profileId, array $staffIds, $actorId)
    {
        $clean = array();
        foreach ($staffIds as $s) { $s = (int) $s; if ($s > 0) { $clean[$s] = true; } }

        $existing = $this->staffForProfile($profileId);
        foreach ($existing as $s) {
            if (!isset($clean[$s])) {
                $this->db->where('profile_id', $profileId)->where('staff_id', $s)
                         ->delete($this->t('payplex_lf_profile_staff'));
            }
        }
        foreach (array_keys($clean) as $s) {
            if (!in_array($s, $existing, true)) {
                $this->db->insert($this->t('payplex_lf_profile_staff'), array(
                    'profile_id' => $profileId, 'staff_id' => $s,
                    'assigned_by' => (int) $actorId, 'assigned_at' => time()));
            }
        }
    }

    /* ================================================================
     * Usage and the hard stop
     * ============================================================== */

    public function usageFor($profileId)
    {
        $month = date('Y-m-01');
        $today = date('Y-m-d');
        $out = array('search_month' => 0, 'detail_month' => 0, 'today' => 0);

        $rows = $this->db->select('request_class, usage_date, SUM(calls) AS c', false)
                         ->where('profile_id', (int) $profileId)
                         ->where('usage_date >=', $month)
                         ->group_by(array('request_class', 'usage_date'))
                         ->get($this->t('payplex_lf_usage'))->result_array();
        foreach ($rows as $r) {
            $c = (int) $r['c'];
            if ($r['request_class'] === Leadfinder_fieldmask::CLASS_CONTACT) { $out['detail_month'] += $c; }
            else { $out['search_month'] += $c; }
            if ($r['usage_date'] === $today) { $out['today'] += $c; }
        }
        return $out;
    }

    /*
     * `quotaAllows()` used to live here and has been removed, deliberately.
     *
     * It was dead — nothing called it — and it carried the OPPOSITE policy to
     * the one the module actually enforces: it treated a limit of 0 as
     * "unlimited", while `Leadfinder_quota::evaluate()` treats an unconfigured
     * mandatory ceiling as a block. Two contradictory answers to "may this
     * request be made" sat in the same module, and the safe one was the one
     * nothing called.
     *
     * Dead code with a wrong policy is worse than no code: the next person to
     * need a quota check finds a method with exactly the right name and uses it.
     * `QuotaEnforcementTest` asserts the method stays gone, so it cannot come
     * back by accident.
     */

    public function staffCallsToday($staffId, $requestClass)
    {
        $r = $this->db->select('SUM(calls) AS c', false)
                      ->where('staff_id', (int) $staffId)
                      ->where('usage_date', date('Y-m-d'))
                      ->where('request_class', $requestClass)
                      ->get($this->t('payplex_lf_usage'))->row_array();
        return $r ? (int) $r['c'] : 0;
    }

    /** Record one billable call. Idempotent per slot via the unique key. */
    /**
     * The usage counter, moved in one atomic statement.
     *
     * WHAT THIS REPLACES
     * ------------------
     * A SELECT, then an UPDATE or an INSERT. Two searches landing in the same
     * second both found no row and both inserted — or both read the same `calls`
     * and both wrote the same increment. Either way the counter under-counts and
     * the ceiling the administrator set is quietly exceeded.
     *
     * `lf_usage_slot` is already UNIQUE on (profile_id, staff_id, usage_date,
     * request_class), so `ON DUPLICATE KEY UPDATE` makes this one statement the
     * database serialises for us. The constraint was there all along; the code
     * simply was not using it — the same shape as the follow-up module, where a
     * UNIQUE key existed and a read-then-write ran alongside it.
     *
     * `$calls` may be negative, which is how a reservation is handed back for a
     * request that never reached Google. `GREATEST(0, …)` stops a double release
     * driving the counter below zero, which would then read as free quota.
     *
     * The date comes from the CRM timezone, not PHP's ambient default. On this
     * host the database session is UTC−7 while the CRM is Asia/Kolkata — twelve
     * and a half hours apart — so "today" differs between them for half the day,
     * and a daily ceiling keyed on the wrong one resets at the wrong midnight.
     */
    public function recordUsage($profileId, $staffId, $requestClass, $calls = 1, $now = null)
    {
        $table = $this->t('payplex_lf_usage');
        $delta = (int) $calls;

        if ($delta === 0) { return; }

        $this->db->query(
            "INSERT INTO `{$table}` (profile_id, staff_id, usage_date, request_class, calls)
             VALUES (?, ?, ?, ?, GREATEST(0, ?))
             ON DUPLICATE KEY UPDATE calls = GREATEST(0, CAST(calls AS SIGNED) + ?)",
            array((int) $profileId, (int) $staffId, $this->crmDate($now),
                  (string) $requestClass, $delta, $delta)
        );
    }

    /**
     * Today's date in the CRM's configured timezone.
     *
     * Never `date('Y-m-d')` and never MySQL `CURDATE()`. Both answer in a
     * timezone nobody chose: PHP's ambient default and the database server's
     * respectively. Measured on this host, `SELECT NOW()` reads 12h30m behind
     * the CRM clock, which moves the boundary a daily ceiling resets on.
     */
    public function crmDate($now = null)
    {
        $tzName = function_exists('get_option') ? (string) get_option('default_timezone') : '';

        try {
            $tz = new DateTimeZone($tzName !== '' ? $tzName : 'UTC');
        } catch (Exception $e) {
            $tz = new DateTimeZone('UTC');
        }

        $d = new DateTimeImmutable('@' . ($now === null ? time() : (int) $now));

        return $d->setTimezone($tz)->format('Y-m-d');
    }

    /**
     * Take one unit of quota BEFORE the request goes out, or refuse.
     *
     * WHY THE ORDER MATTERS
     * ---------------------
     * The previous flow checked the ceiling, called Google, and counted
     * afterwards. Two employees searching in the same moment both read
     * "19 of 20 used" and both proceeded; Google billed for both. It is the same
     * race as the follow-up stage claim and the webhook insert, and it is the
     * expensive one, because the thing being over-spent is money.
     *
     * Reserving first can leave the counter one high if the process dies between
     * the reservation and the release. That costs nothing — one unused unit of a
     * ceiling. Counting afterwards costs paid overage. The asymmetry decides the
     * direction.
     *
     * Returns the quota decision. On `ok` the counter has already moved, and the
     * caller MUST call `settleReservation()` once the outcome is known.
     */
    public function reserveQuota($profileId, $staffId, $requestClass, $now = null, $idemKey = '')
    {
        $now       = $now === null ? time() : (int) $now;
        $profileId = (int) $profileId;
        $staffId   = (int) $staffId;
        $idemKey   = $this->idemKey($idemKey, $profileId, $staffId, $requestClass, $now);

        /*
         * FAIL CLOSED IF MIGRATION 107 HAS NOT RUN.
         *
         * The tempting alternative is to fall back to the pre-107 path when the
         * counters table is absent. That path cannot cap spending under
         * concurrent requests — it is the defect this step exists to fix — so a
         * fallback would mean a half-migrated install spends money with no
         * working ceiling and no sign that anything is wrong. Refusing is loud,
         * costs nothing, and is fixed by applying the migration.
         */
        if (!$this->db->table_exists($this->t('payplex_lf_quota_counters'))
            || !$this->db->table_exists($this->t('payplex_lf_reservations'))) {
            return $this->quotaRefusal(Leadfinder_quota::BLOCK_NO_LIMIT, $idemKey);
        }

        $p = $this->db->where('id', $profileId)
                      ->get($this->t('payplex_lf_api_profiles'))->row_array();

        if (!$p) {
            return $this->quotaRefusal(Leadfinder_quota::BLOCK_INACTIVE, $idemKey);
        }

        /*
         * The two gates that must precede any counter work: a request that
         * cannot be sent should not consume a unit, and reserving against an
         * inactive connection would show usage on a key nobody may use.
         */
        if (trim((string) $p['api_key_enc']) === '') {
            return $this->quotaRefusal(Leadfinder_quota::BLOCK_NO_KEY, $idemKey);
        }

        if ((int) $p['active'] !== 1) {
            return $this->quotaRefusal(Leadfinder_quota::BLOCK_INACTIVE, $idemKey);
        }

        $ceilings = Leadfinder_quota_plan::ceilings(
            $requestClass, $profileId, $staffId,
            $this->crmDate($now),
            $this->quotaLimits($p, $requestClass)
        );

        if (!$ceilings) {
            return $this->quotaRefusal(Leadfinder_quota::BLOCK_NO_LIMIT, $idemKey);
        }

        /*
         * ONE TRANSACTION, AND THE LOCK IS TAKEN BEFORE THE COMPARISON.
         *
         * `trans_begin()`, not `trans_start()`: the strict-mode wrapper commits
         * or rolls back for us at the end and swallows the chance to decide
         * per-branch. Here a refusal must roll back (so the idempotency row and
         * any created counter rows disappear and a later, legitimate request is
         * not answered from a refusal), while success must commit.
         */
        $this->db->trans_begin();

        try {
            /*
             * Idempotency first, inside the transaction.
             *
             * INSERT IGNORE against the UNIQUE key is the claim: exactly one
             * concurrent caller creates the row. A retry — a double click, a
             * browser replay, a pagination request re-sent after a timeout —
             * inserts nothing, and is answered from the reservation that already
             * exists instead of spending a second unit.
             */
            $this->db->query(
                "INSERT IGNORE INTO `" . $this->t('payplex_lf_reservations') . "`
                   (idem_key, profile_id, staff_id, request_class, units, state, created_at)
                 VALUES (?, ?, ?, ?, 1, 'held', ?)",
                array($idemKey, $profileId, $staffId, (string) $requestClass, $now)
            );

            if ((int) $this->db->affected_rows() === 0) {
                $existing = $this->db->query(
                    "SELECT * FROM `" . $this->t('payplex_lf_reservations') . "`
                      WHERE idem_key = ? FOR UPDATE",
                    array($idemKey)
                )->row_array();

                $this->db->trans_commit();

                return array(
                    'decision' => Leadfinder_quota::OK,
                    'ceiling'  => null,
                    'used'     => null,
                    'limit'    => null,
                    'idem_key' => $idemKey,
                    'replayed' => true,
                    'state'    => $existing ? (string) $existing['state'] : 'held',
                );
            }

            /*
             * Make every counter row exist, then lock them in ascending key
             * order.
             *
             * Both halves matter. `SELECT … FOR UPDATE` on a row that does not
             * exist locks a gap, not a row, and two callers can both pass it.
             * And taking the locks in a single agreed order is what makes a
             * deadlock impossible rather than merely rare: a request capped by
             * the profile row and the staff row would otherwise be able to take
             * them in either order, and two such requests in opposite orders
             * are a cycle InnoDB resolves by killing one of them.
             */
            $used  = array();
            $table = $this->t('payplex_lf_quota_counters');

            foreach ($ceilings as $c) {
                $this->db->query(
                    "INSERT IGNORE INTO `{$table}`
                       (scope, scope_id, period_kind, period_key, request_class, used, updated_at)
                     VALUES (?, ?, ?, ?, ?, 0, ?)",
                    array($c['scope'], (int) $c['scope_id'], $c['period_kind'],
                          $c['period_key'], $c['request_class'], $now)
                );
            }

            foreach ($ceilings as $c) {
                $row = $this->db->query(
                    "SELECT used FROM `{$table}`
                      WHERE scope = ? AND scope_id = ? AND period_kind = ?
                        AND period_key = ? AND request_class = ?
                      FOR UPDATE",
                    array($c['scope'], (int) $c['scope_id'], $c['period_kind'],
                          $c['period_key'], $c['request_class'])
                )->row_array();

                $used[Leadfinder_quota_plan::lockKey($c)] = $row ? (int) $row['used'] : 0;
            }

            /* Every lock is held. Only now is the comparison meaningful. */
            $refusal = Leadfinder_quota_plan::firstRefusal($ceilings, $used, 1);

            if ($refusal !== null) {
                $this->db->trans_rollback();

                return $this->quotaRefusal(
                    $this->refusalDecision($refusal),
                    $idemKey,
                    $refusal['ceiling'],
                    $refusal['used']
                );
            }

            $moved = array();

            foreach ($ceilings as $c) {
                $this->db->query(
                    "UPDATE `{$table}` SET used = used + 1, updated_at = ?
                      WHERE scope = ? AND scope_id = ? AND period_kind = ?
                        AND period_key = ? AND request_class = ?",
                    array($now, $c['scope'], (int) $c['scope_id'], $c['period_kind'],
                          $c['period_key'], $c['request_class'])
                );

                $moved[] = Leadfinder_quota_plan::lockKey($c);
            }

            $this->db->query(
                "UPDATE `" . $this->t('payplex_lf_reservations') . "`
                    SET counters = ? WHERE idem_key = ?",
                array(implode("\n", $moved), $idemKey)
            );

            /*
             * The per-day audit table keeps its own row, written in the same
             * transaction. It is not the enforcement surface any more — a SUM
             * cannot be locked — but it is what Step 9's reports read, and
             * letting it drift from the counters would make every report wrong
             * in a way nobody would notice.
             */
            $this->recordUsage($profileId, $staffId, $requestClass, 1, $now);

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();

                return $this->quotaRefusal(Leadfinder_quota::BLOCK_INACTIVE, $idemKey);
            }

            $this->db->trans_commit();
        } catch (Exception $e) {
            $this->db->trans_rollback();

            return $this->quotaRefusal(Leadfinder_quota::BLOCK_INACTIVE, $idemKey);
        }

        /*
         * Alerts are raised AFTER the commit, on purpose.
         *
         * They are a notification, not part of the spending decision. Raising
         * them inside the transaction would mean a failure to write an alert
         * row rolls back a reservation that was otherwise correct — trading a
         * real refusal for a cosmetic one.
         */
        $this->raiseQuotaAlerts($ceilings, $used, $now);

        $t = Leadfinder_quota_plan::tightest($ceilings, $this->bump($used, 1));

        return array(
            'decision' => Leadfinder_quota::OK,
            'ceiling'  => $t ? $t['ceiling']['name'] : null,
            'used'     => $t ? $t['used'] : null,
            'limit'    => $t ? (int) $t['ceiling']['limit'] : null,
            'idem_key' => $idemKey,
            'replayed' => false,
            'state'    => 'held',
        );
    }

    /**
     * Settle a reservation: give the unit back, or keep it.
     *
     * A response Google actually sent, including a 400 or a 403, may be billed,
     * so it keeps its unit. A request that never arrived — DNS, timeout,
     * connection refused, no key — did not cost anything and is handed back.
     * `Leadfinder_quota::shouldRelease()` draws that line and its test pins both
     * sides.
     *
     * Idempotent by construction. The state transition is claimed with a
     * conditional UPDATE and `affected_rows()`, the same claim-first shape used
     * for prospect claims and job locks: a second settle of the same key changes
     * no rows and returns false, so a double release cannot refund a unit twice.
     */
    public function settleReservation($idemKey, $httpStatus, $now = null)
    {
        $now     = $now === null ? time() : (int) $now;
        $idemKey = (string) $idemKey;
        $release = Leadfinder_quota::shouldRelease($httpStatus);
        $target  = $release ? 'released' : 'committed';
        $table   = $this->t('payplex_lf_reservations');

        $this->db->trans_begin();

        try {
            $r = $this->db->query(
                "SELECT * FROM `{$table}` WHERE idem_key = ? FOR UPDATE",
                array($idemKey)
            )->row_array();

            if (!$r) {
                $this->db->trans_rollback();
                return array('settled' => false, 'reason' => 'unknown_reservation');
            }

            $this->db->query(
                "UPDATE `{$table}`
                    SET state = ?, http_status = ?, settled_at = ?
                  WHERE idem_key = ? AND state = 'held'",
                array($target, $httpStatus === null ? null : (int) $httpStatus, $now, $idemKey)
            );

            if ((int) $this->db->affected_rows() === 0) {
                $this->db->trans_rollback();
                return array('settled' => false, 'reason' => 'already_settled',
                             'state' => (string) $r['state']);
            }

            if ($release) {
                $this->returnCounters((string) $r['counters'], $now);
                $this->recordUsage((int) $r['profile_id'], (int) $r['staff_id'],
                                   (string) $r['request_class'], -1, $now);
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();
                return array('settled' => false, 'reason' => 'transaction_failed');
            }

            $this->db->trans_commit();
        } catch (Exception $e) {
            $this->db->trans_rollback();
            return array('settled' => false, 'reason' => 'transaction_failed');
        }

        return array('settled' => true, 'released' => $release, 'state' => $target);
    }

    /**
     * Give back exactly the counters a reservation took.
     *
     * The list is read from the reservation row rather than recomputed. A
     * ceiling added or removed between the reserve and the release would
     * otherwise cause a counter to be credited a unit it never took, or a taken
     * unit never to come back.
     *
     * `GREATEST(0, …)` is the floor: a counter driven below zero would read as
     * free quota, which is the one direction this whole mechanism exists to
     * prevent.
     */
    private function returnCounters($counterList, $now)
    {
        $keys = array_filter(array_map('trim', explode("\n", (string) $counterList)));

        if (!$keys) { return; }

        sort($keys, SORT_STRING);

        $table = $this->t('payplex_lf_quota_counters');

        foreach ($keys as $k) {
            $parts = explode('|', $k);

            if (count($parts) !== 5) { continue; }

            $this->db->query(
                "UPDATE `{$table}`
                    SET used = GREATEST(0, CAST(used AS SIGNED) - 1), updated_at = ?
                  WHERE scope = ? AND scope_id = ? AND period_kind = ?
                    AND period_key = ? AND request_class = ?",
                array($now, $parts[0], (int) $parts[1], $parts[2], $parts[3], $parts[4])
            );
        }
    }

    /**
     * Release reservations whose caller never came back.
     *
     * A process that dies between reserving and calling Google holds its unit
     * until the period rolls over. Without this sweep a crash during a busy hour
     * permanently shrinks the month's allowance, and the administrator sees
     * spending they cannot account for.
     *
     * The window is configuration, not a constant, and it is generous: a slow
     * Google call must never be reclaimed out from under itself.
     */
    public function sweepStaleReservations($now = null)
    {
        $now   = $now === null ? time() : (int) $now;
        $ttl   = max(60, $this->configInt('reservation_stale_seconds', 900));
        $table = $this->t('payplex_lf_reservations');

        $rows = $this->db->query(
            "SELECT idem_key FROM `{$table}`
              WHERE state = 'held' AND created_at <= ?
              ORDER BY id ASC LIMIT 200",
            array($now - $ttl)
        )->result_array();

        $released = 0;

        foreach ($rows as $r) {
            /* http_status null == "nothing was sent", which is what a stale
               reservation is by definition. */
            $res = $this->settleReservation($r['idem_key'], null, $now);

            if (!empty($res['settled'])) { $released++; }
        }

        return array('examined' => count($rows), 'released' => $released, 'ttl' => $ttl);
    }

    /**
     * Every ceiling that applies, assembled from the profile row and config.
     *
     * The per-employee SEARCH ceiling reads its own config key. Before Step 5 a
     * search was measured against `default_staff_detail_limit_daily` — a DETAIL
     * setting — so an employee who had spent their detail allowance could not
     * search either, and an administrator raising the detail limit silently
     * raised the search limit with it.
     *
     * `per_staff_daily_limit` on the profile row wins over the installation
     * default when it is set. Migration 106 added that column for exactly this
     * purpose and, until now, nothing on the spending path read it:
     * `Leadfinder_profile_select::perStaffLimit()` consulted it and the
     * reservation did not, so the number an administrator typed into the profile
     * form had no effect on what was spent.
     */
    public function quotaLimits(array $profile, $requestClass)
    {
        $isDetail = $requestClass === Leadfinder_fieldmask::CLASS_CONTACT;

        $profilePerStaff = isset($profile['per_staff_daily_limit'])
                         ? (int) $profile['per_staff_daily_limit'] : 0;

        $defaultPerStaff = $isDetail
            ? $this->configInt('default_staff_detail_limit_daily', 0)
            : $this->configInt('default_staff_search_limit_daily', 0);

        return array(
            'global_daily'    => $isDetail
                ? $this->configInt('global_daily_contact_limit', 0)
                : $this->configInt('global_daily_search_limit', 0),
            'global_monthly'  => $isDetail
                ? $this->configInt('global_monthly_contact_limit', 0)
                : $this->configInt('global_monthly_search_limit', 0),
            'profile_daily'   => (int) $profile['daily_usage_limit'],
            'profile_monthly' => $isDetail
                ? (int) $profile['monthly_detail_limit']
                : (int) $profile['monthly_search_limit'],
            'staff_daily'     => $profilePerStaff > 0 ? $profilePerStaff : $defaultPerStaff,
            'staff_monthly'   => $isDetail
                ? $this->configInt('staff_monthly_detail_limit', 0)
                : $this->configInt('staff_monthly_search_limit', 0),
        );
    }

    /** Administrator-entered rates, in minor units. Absent means "not configured". */
    public function quotaRates()
    {
        $out = array();

        foreach (array('search' => 'rate_minor_units_search',
                       'contact' => 'rate_minor_units_contact') as $k => $ckey) {
            $v = $this->configInt($ckey, 0);

            if ($v > 0) { $out[$k] = $v; }
        }

        return $out;
    }

    /**
     * What is left, for the screen the employee looks at before searching.
     *
     * Read-only: it takes no locks and reserves nothing, so two people can both
     * be told "1 remaining" and only one of them will get it. That is correct —
     * the display is an estimate and the reservation is the decision. Making the
     * display authoritative would mean locking a counter every time a page
     * loads.
     */
    public function quotaSnapshot($profileId, $staffId, $requestClass, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        if (!$this->haveTables(array('payplex_lf_quota_counters'))) {
            return array('ceilings' => array(), 'used' => array(), 'remaining' => null,
                         'line' => 'Spending ceilings are not installed on this database yet, '
                                 . 'so searching is blocked. Apply migration 107.',
                         'cost' => null, 'currency' => $this->config('quota_currency', ''));
        }

        $p = $this->db->where('id', (int) $profileId)
                      ->get($this->t('payplex_lf_api_profiles'))->row_array();

        if (!$p) {
            return array('ceilings' => array(), 'used' => array(), 'remaining' => null,
                         'line' => 'That API connection could not be read.',
                         'cost' => null, 'currency' => $this->config('quota_currency', ''));
        }

        $ceilings = Leadfinder_quota_plan::ceilings(
            $requestClass, (int) $profileId, (int) $staffId,
            $this->crmDate($now), $this->quotaLimits($p, $requestClass)
        );

        $used = $this->counterValues($ceilings);
        $t    = Leadfinder_quota_plan::tightest($ceilings, $used);

        return array(
            'ceilings'  => $ceilings,
            'used'      => $used,
            'remaining' => $t ? $t['remaining'] : null,
            'line'      => Leadfinder_quota_plan::allowanceLine($ceilings, $used),
            'cost'      => Leadfinder_quota_plan::formatMinorUnits(
                               Leadfinder_quota_plan::estimateMinorUnits(
                                   $requestClass, 1, $this->quotaRates())),
            'currency'  => $this->config('quota_currency', ''),
        );
    }

    /**
     * Are all of these module tables present?
     *
     * THE DEFECT THIS EXISTS FOR
     * --------------------------
     * Several read paths were wrapped in `try { … } catch (Exception $e)` on the
     * assumption that querying a table a migration has not created yet would
     * throw. It does not. With `db_debug` on — which is the state on this
     * install — CodeIgniter's database driver calls `show_error()` and HALTS the
     * request. The catch block is never reached, and the employee gets a 500
     * instead of the graceful refusal the code was written to give.
     *
     * This was found on the live install, not in the suite: every test runs the
     * pure libraries, and none of them has a CodeIgniter database underneath. A
     * guard that only works against an exception that is never thrown is a guard
     * that does nothing.
     *
     * So the check happens BEFORE the query, which is the only form CodeIgniter
     * gives us that actually works.
     */
    private function haveTables(array $names)
    {
        foreach ($names as $n) {
            if (!$this->db->table_exists($this->t($n))) { return false; }
        }

        return true;
    }

    /** Current value of each counter, keyed by lock key. Missing rows read 0. */
    private function counterValues(array $ceilings)
    {
        $out = array();

        /* Before migration 107 there is no counters table. Zeroes are the
           truthful answer: nothing has been spent, because nothing can be. */
        if (!$this->haveTables(array('payplex_lf_quota_counters'))) {
            foreach ($ceilings as $c) { $out[Leadfinder_quota_plan::lockKey($c)] = 0; }

            return $out;
        }

        foreach ($ceilings as $c) {
            $row = $this->db->query(
                "SELECT used FROM `" . $this->t('payplex_lf_quota_counters') . "`
                  WHERE scope = ? AND scope_id = ? AND period_kind = ?
                    AND period_key = ? AND request_class = ?",
                array($c['scope'], (int) $c['scope_id'], $c['period_kind'],
                      $c['period_key'], $c['request_class'])
            )->row_array();

            $out[Leadfinder_quota_plan::lockKey($c)] = $row ? (int) $row['used'] : 0;
        }

        return $out;
    }

    /**
     * Raise each band once per ceiling per period.
     *
     * The UNIQUE key does the deduplication, not a query-then-insert: two
     * requests crossing 80% in the same instant would both find no alert row and
     * both insert one. `INSERT IGNORE` plus `affected_rows()` means exactly one
     * of them raises it.
     */
    private function raiseQuotaAlerts(array $ceilings, array $usedBefore, $now)
    {
        $table = $this->t('payplex_lf_quota_alerts');

        foreach ($ceilings as $c) {
            $key    = Leadfinder_quota_plan::lockKey($c);
            $before = isset($usedBefore[$key]) ? (int) $usedBefore[$key] : 0;
            $bands  = Leadfinder_quota_plan::bandsCrossed($before, $before + 1, (int) $c['limit']);

            foreach ($bands as $band) {
                try {
                    $this->db->query(
                        "INSERT IGNORE INTO `{$table}`
                           (scope, scope_id, period_kind, period_key, request_class,
                            band, used_at_raise, limit_at_raise, raised_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        array($c['scope'], (int) $c['scope_id'], $c['period_kind'],
                              $c['period_key'], $c['request_class'], (int) $band,
                              $before + 1, (int) $c['limit'], (int) $now)
                    );

                    if ((int) $this->db->affected_rows() > 0) {
                        $this->audit(0, 'quota_alert', 'quota', (int) $c['scope_id'], array(
                            'scope' => $c['scope'], 'band' => (int) $band,
                            'ceiling' => $c['name'], 'period' => $c['period_key'],
                            'request_class' => $c['request_class'],
                            'used' => $before + 1, 'limit' => (int) $c['limit'],
                        ));
                    }
                } catch (Exception $e) {
                    /* An alert that cannot be written must never fail a request
                       whose quota was legitimately reserved. */
                }
            }
        }
    }

    /** Unreleased alerts an administrator has not seen, newest first. */
    public function quotaAlerts($limit = 50)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_quota_alerts'))) {
            return array();
        }

        return $this->db->order_by('raised_at', 'DESC')
                        ->limit(max(1, (int) $limit))
                        ->get($this->t('payplex_lf_quota_alerts'))->result_array();
    }

    /**
     * The idempotency key for one request.
     *
     * A caller-supplied key is hashed rather than trusted: it arrives from a
     * form field, it lands in a CHAR(64) column, and a 64-character bound that
     * depends on the caller being well behaved is not a bound.
     *
     * With no key supplied the reservation still gets one — derived from the
     * request and the second it was made — so the ledger is complete. That
     * fallback deduplicates a double-submit within the same second and nothing
     * more, which is why the search path supplies a real one.
     */
    private function idemKey($supplied, $profileId, $staffId, $requestClass, $now)
    {
        $supplied = trim((string) $supplied);

        if ($supplied !== '') {
            return hash('sha256', 'lf|' . $supplied);
        }

        return hash('sha256', implode('|', array(
            'lf-auto', (int) $profileId, (int) $staffId, (string) $requestClass, (int) $now,
        )));
    }

    private function quotaRefusal($decision, $idemKey, $ceiling = null, $used = null)
    {
        return array(
            'decision' => $decision,
            'ceiling'  => $ceiling ? $ceiling['name'] : null,
            'used'     => $used === null ? null : (int) $used,
            'limit'    => $ceiling ? (int) $ceiling['limit'] : null,
            'label'    => $ceiling ? $ceiling['label'] : null,
            'idem_key' => $idemKey,
            'replayed' => false,
            'state'    => 'refused',
        );
    }

    /** Map a plan refusal onto the decision constant whose message fits it. */
    private function refusalDecision(array $refusal)
    {
        if ($refusal['reason'] === 'not_configured') {
            return Leadfinder_quota::BLOCK_NO_LIMIT;
        }

        $scope = $refusal['ceiling']['scope'];

        if ($scope === Leadfinder_quota_plan::SCOPE_STAFF) {
            return Leadfinder_quota::BLOCK_PER_STAFF;
        }

        return $refusal['ceiling']['period_kind'] === Leadfinder_quota_plan::PERIOD_MONTH
             ? Leadfinder_quota::BLOCK_MONTHLY
             : Leadfinder_quota::BLOCK_DAILY;
    }

    private function bump(array $used, $by)
    {
        foreach ($used as $k => $v) { $used[$k] = (int) $v + (int) $by; }

        return $used;
    }

    /* ================================================================
     * Search
     * ============================================================== */

    /**
     * Run a search and put the results in the queue.
     *
     * Order matters and is the whole safety argument:
     *   compliance gate -> profile the employee may use -> quota -> validate
     *   the mask -> spend the call -> record usage -> classify duplicates ->
     *   insert into the QUEUE. Never into tblleads.
     */
    public function runSearch($staffId, array $f)
    {
        $staffId = (int) $staffId;
        $now     = time();

        $block = $this->complianceBlock();
        if ($block !== '') { return array('prospects' => array(), 'error' => $block); }

        $profileId = (int) (isset($f['profile_id']) ? $f['profile_id'] : 0);
        $allowed   = $this->profilesForStaff($staffId);
        $ok = false;
        foreach ($allowed as $p) { if ((int) $p['id'] === $profileId) { $ok = true; break; } }
        if (!$ok) {
            return array('prospects' => array(),
                         'error' => 'That API connection is not assigned to you, or is inactive.');
        }

        /*
         * RESERVE, then call. Not check, then call, then count.
         *
         * The previous order checked the ceiling here, called Google forty lines
         * below, and recorded the usage after that. Two employees searching in
         * the same second both passed this check and both spent. The counter now
         * moves before anything leaves the building, and is handed back only if
         * the request never reached Google.
         */
        require_once __DIR__ . '/../libraries/Leadfinder_quota.php';

        /*
         * The idempotency key covers the request, not the moment.
         *
         * It is built from who is searching, on which connection, for what, and
         * on which page — so a double click, a browser retry after a timeout, or
         * a replayed pagination request all resolve to the same reservation and
         * spend one unit between them. It deliberately does NOT include the
         * clock: a key containing `time()` would make every retry a new request,
         * which is the behaviour this is here to prevent.
         *
         * `request_token` lets the form supply its own key, so two genuinely
         * separate searches for the same thing are still two searches.
         */
        $idemKey = isset($f['request_token']) && trim((string) $f['request_token']) !== ''
            ? (string) $f['request_token']
            : implode('|', array(
                'search', $staffId, $profileId,
                sha1(strtolower(trim(implode('~', array(
                    (string) $f['keyword'], (string) $f['category'], (string) $f['city'],
                    (string) $f['state'], (string) $f['pin_code'], (string) $f['product'],
                    (string) $f['campaign'], (string) $f['language'],
                    (string) $f['radius_m'], (string) $f['max_results'],
                ))))),
                isset($f['page_token']) ? sha1((string) $f['page_token']) : '1',
            ));

        $quota = $this->reserveQuota($profileId, $staffId,
                                     Leadfinder_fieldmask::CLASS_SEARCH, $now, $idemKey);

        if ($quota['decision'] !== Leadfinder_quota::OK) {
            return array('prospects' => array(),
                         'error' => Leadfinder_quota::message($quota)
                                  . ' No paid overage is permitted.');
        }

        /*
         * A replay is answered without calling Google at all.
         *
         * The reservation already exists, so this exact request has been made.
         * Sending it again would spend a unit the ledger says was already spent
         * and return results the employee already has.
         */
        if (!empty($quota['replayed'])) {
            return array('prospects' => array(), 'error' => null, 'replayed' => true,
                         'inserted' => 0, 'existing' => 0, 'capped' => false,
                         'cap_reason' => '',
                         'notice' => 'That search has already been run and was not sent again.');
        }

        $mask  = Leadfinder_fieldmask::forSearch();
        $valid = Leadfinder_places::validateSearch($f, $mask);
        if (empty($valid['ok'])) {
            return array('prospects' => array(), 'error' => 'Search refused: ' . $valid['error']);
        }

        $built = Leadfinder_places::searchBody($f);
        $query = $built['body']['textQuery'];

        $searchId = $this->logSearch($staffId, $profileId, $f, $query, $mask, $now);

        $resp = $this->callGoogle($profileId, self::searchUrl(), $built['body'], $mask, 'search');

        if (empty($resp['ok'])) {
            /*
             * Give the reservation back only if nothing was billed.
             *
             * A timeout or a refused connection never reached Google, so the
             * unit returns. A 400 or 403 DID reach the service and may be
             * charged — keeping its unit is what makes the counter match the
             * invoice rather than match the successes.
             */
            $settled  = $this->settleReservation($quota['idem_key'],
                isset($resp['http_status']) ? $resp['http_status'] : null, $now);
            $released = !empty($settled['released']);

            $this->db->where('id', $searchId)->update($this->t('payplex_lf_searches'),
                array('error_code' => substr((string) $resp['error'], 0, 60)));

            $this->audit($staffId, 'search_failed', 'search', (int) $searchId,
                array('quota_released' => $released ? 1 : 0,
                      'http_status'    => isset($resp['http_status']) ? $resp['http_status'] : null));

            return array('prospects' => array(),
                         'error' => Leadfinder_secret::scrub((string) $resp['error']));
        }

        /*
         * Google answered, so the unit is spent. Settle here rather than at the
         * end: every path below this line — a mapping error, a storage failure —
         * is a request that reached the service and may be billed, and leaving
         * the reservation `held` on those paths would let the stale sweeper
         * refund a call Google will invoice.
         */
        $this->settleReservation($quota['idem_key'],
            isset($resp['http_status']) ? $resp['http_status'] : 200, $now);

        $mapped = Leadfinder_places::mapResponse($resp['data'], array(
            'search_id' => $searchId, 'profile_id' => $profileId, 'now' => $now,
            'keyword'   => $query,
            'location'  => trim(implode(', ', array_filter(array(
                            (string) $f['city'], (string) $f['state'], (string) $f['pin_code'])))),
        ));

        if (!empty($mapped['error'])) {
            $this->db->where('id', $searchId)->update($this->t('payplex_lf_searches'),
                array('error_code' => substr($mapped['error'], 0, 60)));
            return array('prospects' => array(), 'error' => Leadfinder_secret::scrub($mapped['error']));
        }

        $allRows   = $mapped['rows'];
        $skipped   = $mapped['skipped'];
        $attempts  = isset($resp['attempts']) ? (int) $resp['attempts'] : 1;
        $simulated = !empty($resp['simulated']);
        $pages     = 1;
        $token     = isset($mapped['next_page_token']) ? $mapped['next_page_token'] : '';

        /*
         * PAGING, AND WHY EACH PAGE RESERVES ITS OWN UNIT.
         *
         * A page is a separate billable request. The first page is covered by
         * the reservation taken above; every further page takes its own, with
         * its own idempotency key, and stops the moment one is refused. That is
         * what makes "maximum results" a spending control rather than a display
         * preference — asking for 60 results costs three calls and the employee
         * is stopped at the ceiling mid-search rather than after it.
         *
         * Three bounds, and the loop needs all of them: what the employee asked
         * for, Google's documented 60 across all pages, and a configured page
         * limit. A loop that trusts `nextPageToken` alone keeps paying as long
         * as Google keeps offering.
         */
        $maxPages = max(1, min(3, $this->configInt('places_max_pages', 3)));

        while ($pages < $maxPages) {
            $plan = Leadfinder_places::pagePlan((int) $f['max_results'], count($allRows), $token);

            if (empty($plan['continue'])) { break; }

            $pageKey   = $idemKey . '|page' . ($pages + 1);
            $pageQuota = $this->reserveQuota($profileId, $staffId,
                                             Leadfinder_fieldmask::CLASS_SEARCH, $now, $pageKey);

            if ($pageQuota['decision'] !== Leadfinder_quota::OK) {
                /* Out of allowance mid-search. Keep what was fetched, say so,
                   and do not pretend the result set is complete. */
                $this->audit($staffId, 'search_paging_stopped', 'search', (int) $searchId, array(
                    'reason' => $pageQuota['decision'], 'pages_fetched' => $pages,
                    'rows_so_far' => count($allRows)));
                break;
            }

            $pageBody = Leadfinder_places::searchBody(array_merge($f, array('page_token' => $token)));
            $pageResp = $this->callGoogle($profileId, self::searchUrl(),
                                          $pageBody['body'], $mask, 'search');

            $this->settleReservation($pageQuota['idem_key'],
                isset($pageResp['http_status']) ? $pageResp['http_status'] : null, $now);

            if (empty($pageResp['ok'])) {
                $this->audit($staffId, 'search_page_failed', 'search', (int) $searchId, array(
                    'page' => $pages + 1,
                    'http_status' => isset($pageResp['http_status']) ? $pageResp['http_status'] : null));
                break;
            }

            $pageMapped = Leadfinder_places::mapResponse($pageResp['data'], array(
                'search_id' => $searchId, 'profile_id' => $profileId, 'now' => $now,
                'keyword'   => $query,
                'location'  => trim(implode(', ', array_filter(array(
                                (string) $f['city'], (string) $f['state'], (string) $f['pin_code'])))),
            ));

            if (!empty($pageMapped['error'])) { break; }

            $allRows  = array_merge($allRows, $pageMapped['rows']);
            $skipped += $pageMapped['skipped'];
            $token    = isset($pageMapped['next_page_token']) ? $pageMapped['next_page_token'] : '';
            $attempts += isset($pageResp['attempts']) ? (int) $pageResp['attempts'] : 1;
            $pages++;
        }

        /*
         * Trim to what was asked for. Google's last page can overshoot — it
         * returns a full page of 20 whether or not 20 were wanted — and storing
         * the overshoot would put businesses in the queue that the employee's
         * own limit says they did not want.
         */
        $wanted = min(Leadfinder_places::MAX_RESULTS_ALL_PAGES, max(1, (int) $f['max_results']));
        if (count($allRows) > $wanted) { $allRows = array_slice($allRows, 0, $wanted); }

        $stored = $this->storeProspects($allRows, $staffId, $simulated);

        $this->db->where('id', $searchId)->update($this->t('payplex_lf_searches'),
            array('result_count' => count($allRows), 'pages_fetched' => $pages,
                  'attempts' => $attempts, 'is_simulated' => $simulated ? 1 : 0));

        $this->audit($staffId, 'search_run', 'search', $searchId, array(
            'query' => $query, 'profile_id' => $profileId,
            'results' => count($allRows), 'new' => $stored['inserted'],
            'already_known' => $stored['existing'], 'skipped' => $skipped,
            'pages' => $pages, 'attempts' => $attempts,
            'simulated' => $simulated ? 1 : 0,
            'capped' => $built['capped']));

        return array('prospects' => $stored['rows'], 'error' => null,
                     'inserted' => $stored['inserted'], 'existing' => $stored['existing'],
                     'pages' => $pages, 'attempts' => $attempts, 'simulated' => $simulated,
                     'capped' => $built['capped'], 'cap_reason' => $built['cap_reason']);
    }

    private static function searchUrl() { return Leadfinder_places::SEARCH_URL; }

    private function logSearch($staffId, $profileId, array $f, $query, $mask, $now)
    {
        $this->db->insert($this->t('payplex_lf_searches'), array(
            'staff_id' => (int) $staffId, 'profile_id' => (int) $profileId,
            'keyword' => (string) $f['keyword'], 'category' => (string) $f['category'],
            'city' => (string) $f['city'], 'state' => (string) $f['state'],
            'pin_code' => (string) $f['pin_code'],
            'radius_m' => (int) $f['radius_m'], 'max_results' => (int) $f['max_results'],
            'product' => (string) $f['product'], 'campaign' => (string) $f['campaign'],
            'language' => (string) $f['language'],
            'query_hash' => sha1(strtolower($query)),
            'endpoint' => 'places:searchText',
            'request_class' => Leadfinder_fieldmask::classify($mask),
            'created_at' => (int) $now,
        ));
        return (int) $this->db->insert_id();
    }

    /**
     * The one place an API key is decrypted, and it never leaves the callback.
     */
    /**
     * Call Google, or the fixture, with a bounded number of attempts.
     *
     * ONE RESERVATION COVERS EVERY ATTEMPT
     * ------------------------------------
     * A retry re-sends the same logical request and reuses the unit already
     * held for it. Taking a fresh unit per attempt would mean a Google outage
     * consumed a month's allowance in a few seconds — the exact failure the
     * ceiling exists to prevent, arriving through the mechanism meant to
     * survive an outage.
     *
     * WHAT IS AND IS NOT RETRIED
     * --------------------------
     * `Leadfinder_retry` draws the line, and it is the same line
     * `Leadfinder_quota::isBillable()` draws: a response Google *sent* is a
     * decision, and asking again gets the same decision. A 400 from a malformed
     * FieldMask retried three times is three charges for one bug. Only a
     * failure to reach Google, a 429, or a 5xx is tried again.
     *
     * @return array ok, http_status, data, error, attempts, simulated
     */
    private function callGoogle($profileId, $url, array $body, $mask, $kind = 'search')
    {
        require_once __DIR__ . '/../libraries/Leadfinder_retry.php';
        require_once __DIR__ . '/../libraries/Leadfinder_transport.php';

        $max  = Leadfinder_retry::attempts($this->configInt('places_max_attempts', 0));
        $base = $this->configInt('places_retry_base_ms', Leadfinder_retry::DEFAULT_BASE_MS);

        /*
         * The fixture path returns before any key is read.
         *
         * Deliberate: in simulated mode there is no key to decrypt, so an
         * install with no key configured can still exercise the entire
         * workflow, and a simulation can never be the thing that touches a
         * credential.
         */
        if (Leadfinder_transport::isMock($this->config('places_transport', 'live'))) {
            $r = $kind === 'details'
               ? Leadfinder_transport::mockDetails(isset($body['__place_id']) ? $body['__place_id'] : '0')
               : Leadfinder_transport::mockSearch(
                     isset($body['textQuery']) ? $body['textQuery'] : '',
                     isset($body['maxResultCount']) ? $body['maxResultCount'] : 20,
                     isset($body['pageToken']) ? $body['pageToken'] : '');

            $r['attempts'] = 1;

            return $r;
        }

        $attempt = 0;
        $last    = null;

        while ($attempt < $max) {
            $attempt++;
            $last = $this->callGoogleOnce($profileId, $url, $body, $mask);

            if (!empty($last['ok'])) { break; }

            if (!Leadfinder_retry::shouldRetry($last['http_status'], $attempt, $max)) { break; }

            /*
             * Sleeping in a request a person is waiting on is a real cost, which
             * is why the backoff is capped at 8s and the attempt count at 5.
             * `usleep` takes microseconds.
             */
            usleep(Leadfinder_retry::backoffMs($attempt, $base) * 1000);
        }

        $last['attempts']  = $attempt;
        $last['simulated'] = false;

        if (empty($last['ok']) && $attempt > 1) {
            $last['error'] = Leadfinder_retry::exhaustedMessage($attempt)
                           . ' (' . $last['error'] . ')';
        }

        return $last;
    }

    /** One attempt. The only place an API key is decrypted. */
    private function callGoogleOnce($profileId, $url, array $body, $mask)
    {
        unset($body['__place_id']);

        $p = $this->db->select('api_key_enc')->where('id', (int) $profileId)
                      ->get($this->t('payplex_lf_api_profiles'))->row_array();
        if (!$p || trim((string) $p['api_key_enc']) === '') {
            /*
             * `http_status` is null on every path where nothing was sent, and
             * the real code on every path where something was. The quota
             * release reads it to decide whether the reservation comes back, so
             * a missing status would silently release a billable request.
             */
            return array('ok' => false, 'http_status' => null,
                         'error' => 'No API key is configured for this connection.');
        }

        $r = Leadfinder_secret::useFor($p['api_key_enc'], $this->decryptor(),
            function ($plainKey) use ($url, $body, $mask) {
                $ch = curl_init($url);
                curl_setopt_array($ch, array(
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($body),
                    CURLOPT_HTTPHEADER     => Leadfinder_places::headers($plainKey, $mask),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 25,
                    CURLOPT_CONNECTTIMEOUT => 10,
                ));
                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                return array('http' => $code, 'body' => $raw, 'curl_error' => $err);
            });

        if (empty($r['ok'])) {
            return array('ok' => false, 'http_status' => null,
                         'error' => 'The stored API key could not be used: ' . $r['error']);
        }
        $res = $r['value'];

        if ($res['curl_error'] !== '') {
            /* Nothing arrived: DNS, timeout, refused. The unit is released. */
            return array('ok' => false, 'http_status' => null,
                         'error' => 'Connection to Google failed: '
                                  . Leadfinder_secret::scrub($res['curl_error']));
        }
        $decoded = json_decode((string) $res['body'], true);
        if ($res['http'] !== 200) {
            $msg = is_array($decoded) && isset($decoded['error']['message'])
                   ? $decoded['error']['message'] : 'HTTP ' . $res['http'];
            /*
             * Google answered. A 4xx is a billed request on several SKUs, so the
             * real status travels back and the reservation is NOT released.
             */
            return array('ok' => false, 'http_status' => (int) $res['http'],
                         'error' => 'Google refused the request: '
                                  . Leadfinder_secret::scrub($msg));
        }
        return array('ok' => true, 'data' => $decoded, 'error' => null);
    }

    /**
     * Write results to the QUEUE. Never to tblleads.
     *
     * The place id is UNIQUE in the schema, so a result already in the queue is
     * left exactly as it is — a claimed, worked prospect must not be reset to
     * new_result because somebody searched again.
     */
    private function storeProspects(array $rows, $staffId, $simulated = false)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_transport.php';

        $out = array(); $inserted = 0; $existing = 0;
        $table = $this->t('payplex_lf_prospects');

        foreach ($rows as $r) {
            $r['dupe_state'] = $this->classifyAgainstCrm($r);

            /*
             * CLAIM FIRST, THEN LOOK.
             *
             * This was a SELECT on `google_place_id` followed by an INSERT. The
             * column is UNIQUE, so the duplicate never landed — it became a
             * database error partway through a loop, after earlier rows had
             * already been written, leaving the employee a half-stored search
             * and a 500. Two employees searching the same town at the same
             * moment is not an exotic case; it is Tuesday.
             *
             * `INSERT IGNORE` plus `affected_rows()` makes the UNIQUE key decide
             * the winner, which is what it was for. It is the same shape as the
             * reservation claim and the job lock.
             */
            $r['is_simulated'] = $simulated
                || Leadfinder_transport::looksSimulated($r['google_place_id']) ? 1 : 0;

            $cols = array_keys($r);
            $ph   = implode(',', array_fill(0, count($cols), '?'));

            $this->db->query(
                "INSERT IGNORE INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$ph})",
                array_values($r)
            );

            if ((int) $this->db->affected_rows() > 0) {
                $inserted++;
                $r['id'] = (int) $this->db->insert_id();
                $out[]   = $r;
                continue;
            }

            /*
             * Already here. The stored row is returned untouched: a claimed,
             * worked prospect must not be reset to new_result because somebody
             * searched again, and its owner must not change because a second
             * employee's search happened to return it.
             */
            $found = $this->db->where('google_place_id', $r['google_place_id'])
                              ->get($table)->row_array();

            if ($found) {
                $existing++;
                $found['dupe_state'] = Leadfinder_dupe::IN_QUEUE;
                $out[] = $found;
            }
        }

        return array('rows' => $out, 'inserted' => $inserted, 'existing' => $existing);
    }

    /* ================================================================
     * Place Details
     * ============================================================== */

    /**
     * Fetch contact details for one claimed prospect.
     *
     * WHY THIS IS A SEPARATE, EXPLICIT ACTION
     * ---------------------------------------
     * A detail call bills at a higher SKU than a search — asking for a phone
     * number promotes the whole request. Fetching details automatically for
     * every search result would multiply the cost of a 60-result search by the
     * contact rate, for businesses nobody has looked at yet. So it is an
     * explicit per-prospect action, gated on its own capability, and it is
     * refused for a prospect the employee does not hold.
     *
     * Returns a named outcome in every case. Nothing here returns an empty
     * array to mean "something went wrong".
     */
    public function fetchDetails($staffId, $prospectId, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_transport.php';

        $now      = $now === null ? time() : (int) $now;
        $staffId  = (int) $staffId;
        $prospect = $this->prospect((int) $prospectId);

        if (!$prospect) {
            return array('ok' => false, 'outcome' => 'not_found',
                         'message' => 'That prospect could not be found.');
        }

        $block = $this->complianceBlock();
        if ($block !== '') {
            return array('ok' => false, 'outcome' => 'compliance_block', 'message' => $block);
        }

        /*
         * Ownership, not just capability.
         *
         * A detail call spends money against a business somebody else is
         * working. Holding `leadfinder_fetch_details` says the employee may
         * spend on their own prospects; it does not say they may spend on
         * everyone's.
         */
        if ((int) $prospect['claimed_by'] !== $staffId
            && !(function_exists('is_admin') && is_admin())) {
            return array('ok' => false, 'outcome' => 'not_claimed_by_you',
                         'message' => 'Claim this prospect before fetching its details.');
        }

        /*
         * Already held. §13's "prevent repeated detail calls".
         *
         * The timestamp decides, not whether a phone number came back: a
         * business with genuinely no listed phone would otherwise be re-queried
         * on every visit for ever, which is precisely the repeated spend this
         * check exists to stop, arriving through the check meant to stop it.
         */
        if (Leadfinder_fieldmask::detailsAlreadyHeld($prospect)) {
            return array('ok' => true, 'outcome' => 'already_held', 'spent' => false,
                         'message' => 'Details for this prospect were already fetched. '
                                    . 'Nothing was sent and nothing was charged.');
        }

        $profileId = (int) $prospect['profile_id'];

        if ($profileId <= 0) {
            return array('ok' => false, 'outcome' => 'no_profile',
                         'message' => 'This prospect has no API connection recorded, so no '
                                    . 'detail request can be attributed to a key.');
        }

        /* The employee must still be allowed to use that connection today. */
        $allowed = false;
        foreach ($this->profilesForStaff($staffId) as $p) {
            if ((int) $p['id'] === $profileId) { $allowed = true; break; }
        }

        if (!$allowed && !(function_exists('is_admin') && is_admin())) {
            return array('ok' => false, 'outcome' => 'profile_not_yours',
                         'message' => 'The API connection this prospect came from is not '
                                    . 'assigned to you.');
        }

        $idemKey = 'details|' . $profileId . '|' . $prospect['google_place_id'];

        $quota = $this->reserveQuota($profileId, $staffId,
                                     Leadfinder_fieldmask::CLASS_CONTACT, $now, $idemKey);

        if ($quota['decision'] !== Leadfinder_quota::OK) {
            $this->logDetailFetch($prospectId, $staffId, $profileId, 'quota_refused', null, 0, $now, '');

            return array('ok' => false, 'outcome' => 'quota_refused',
                         'message' => Leadfinder_quota::message($quota)
                                    . ' No paid overage is permitted.');
        }

        if (!empty($quota['replayed'])) {
            return array('ok' => true, 'outcome' => 'already_requested', 'spent' => false,
                         'message' => 'A detail request for this prospect has already been '
                                    . 'made and was not sent again.');
        }

        $mask  = Leadfinder_fieldmask::forDetails();
        $valid = Leadfinder_fieldmask::validate($mask, Leadfinder_fieldmask::CLASS_CONTACT);

        if (empty($valid['ok'])) {
            $this->settleReservation($quota['idem_key'], null, $now);

            return array('ok' => false, 'outcome' => 'invalid_field_mask',
                         'message' => 'Details request refused: ' . $valid['error']);
        }

        $url  = Leadfinder_places::DETAILS_URL . rawurlencode((string) $prospect['google_place_id']);
        $resp = $this->callGoogle($profileId, $url,
                    array('__place_id' => $prospect['google_place_id']), $mask, 'details');

        $this->settleReservation($quota['idem_key'],
            isset($resp['http_status']) ? $resp['http_status'] : null, $now);

        if (empty($resp['ok'])) {
            $this->logDetailFetch($prospectId, $staffId, $profileId, 'failed',
                isset($resp['http_status']) ? $resp['http_status'] : null,
                isset($resp['attempts']) ? $resp['attempts'] : 1, $now,
                substr((string) $resp['error'], 0, 60));

            return array('ok' => false, 'outcome' => 'google_failed',
                         'message' => Leadfinder_secret::scrub((string) $resp['error']));
        }

        $mapped = Leadfinder_places::mapDetails($resp['data'], $this->phoneProfile(), $now);

        if (empty($mapped['ok'])) {
            $this->logDetailFetch($prospectId, $staffId, $profileId, 'unmappable', 200,
                isset($resp['attempts']) ? $resp['attempts'] : 1, $now,
                substr((string) $mapped['error'], 0, 60));

            return array('ok' => false, 'outcome' => 'unmappable',
                         'message' => Leadfinder_secret::scrub((string) $mapped['error']));
        }

        /*
         * Only the contact columns are written. `mapDetails` returns exactly
         * those, so a Google response cannot reach `status`, `claimed_by`,
         * `assigned_staff` or anything else that governs who owns the row.
         */
        $this->db->where('id', (int) $prospectId)
                 ->update($this->t('payplex_lf_prospects'), $mapped['fields']);

        $this->logDetailFetch($prospectId, $staffId, $profileId, 'ok', 200,
            isset($resp['attempts']) ? $resp['attempts'] : 1, $now, '',
            !empty($resp['simulated']));

        $this->audit($staffId, 'details_fetched', 'prospect', (int) $prospectId, array(
            'profile_id'   => $profileId,
            'has_phone'    => $mapped['fields']['phone_e164'] !== null ? 1 : 0,
            'has_website'  => $mapped['fields']['website'] !== null ? 1 : 0,
            'simulated'    => !empty($resp['simulated']) ? 1 : 0,
            'attempts'     => isset($resp['attempts']) ? (int) $resp['attempts'] : 1,
        ));

        return array('ok' => true, 'outcome' => 'fetched', 'spent' => true,
                     'fields' => $mapped['fields'],
                     'simulated' => !empty($resp['simulated']),
                     'message' => 'Details fetched.'
                                . ($mapped['fields']['phone_e164'] === null
                                   ? ' Google listed no phone number for this business.' : '')
                                . ' ' . Leadfinder_places::emailSourceNote());
    }

    /** One row per detail attempt — the only per-call record an invoice can be reconciled against. */
    private function logDetailFetch($prospectId, $staffId, $profileId, $outcome,
                                    $httpStatus, $attempts, $now, $errorCode = '', $simulated = false)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_detail_fetches'))) { return; }

        try {
            $this->db->insert($this->t('payplex_lf_detail_fetches'), array(
                'prospect_id'  => (int) $prospectId,
                'staff_id'     => (int) $staffId,
                'profile_id'   => (int) $profileId,
                'outcome'      => (string) $outcome,
                'http_status'  => $httpStatus === null ? null : (int) $httpStatus,
                'attempts'     => max(1, (int) $attempts),
                'is_simulated' => $simulated ? 1 : 0,
                'error_code'   => $errorCode === '' ? null : Leadfinder_secret::scrub((string) $errorCode),
                'created_at'   => (int) $now,
            ));
        } catch (Exception $e) {
            /* A ledger write must never fail the request it is recording. */
        }
    }

    /** Detail-fetch history for one prospect, or all of them. */
    public function detailFetches($prospectId = 0, $limit = 50)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_detail_fetches'))) { return array(); }

        if ((int) $prospectId > 0) { $this->db->where('prospect_id', (int) $prospectId); }

        return $this->db->order_by('created_at', 'DESC')->limit(max(1, (int) $limit))
                        ->get($this->t('payplex_lf_detail_fetches'))->result_array();
    }

    /**
     * §10 against the main CRM, at insert time, so the verdict is stored.
     *
     * The comparison options come from `dupeOptions()` rather than being built
     * here. They used to be built here — and when Step 7 added the
     * shared-switchboard rule it built a second set somewhere else, reading a
     * different config key for the shared-host list. Two places assembling the
     * "same" options is how one caller gets the switchboard downgrade and the
     * other does not, and the one that does not is this one: the path that
     * decides what a brand-new prospect is classified as.
     */
    private function classifyAgainstCrm(array $cand)
    {
        $crm = $this->db->select('id, name, company, email, phonenumber, phone_match_key')
                        ->limit(500)->get(db_prefix() . 'leads')->result_array();

        $c = Leadfinder_dupe::classify($cand, array(), $crm, $this->dupeOptions(array($cand)));

        return $c['verdict'];
    }

    /* ================================================================
     * Queue
     * ============================================================== */

    /**
     * The staff ids a manager may see beyond their own.
     *
     * Read from an explicit, administrator-maintained map in configuration —
     * `lf_team_map`, JSON of `{"managerStaffId": [memberIds]}`. Nothing is
     * inferred from a reporting line, because no approved manager mapping
     * exists on this install and inventing one would be inventing an access
     * grant.
     *
     * Unconfigured therefore means a manager sees exactly their own rows: the
     * fail-closed direction, and the same position the workforce scope layer
     * takes.
     *
     * This began as `return array();` with that reasoning in a comment. The
     * contract test flagged it, correctly — a method that can only ever return
     * empty is indistinguishable from an unfinished one, and the reasoning was
     * invisible to everything except a reader. Now the emptiness is a fact
     * about the configuration, which an administrator can change and a test can
     * exercise in both directions.
     */
    public function teamOf($staffId)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { return array(); }

        $map = json_decode($this->config('lf_team_map', ''), true);
        if (!is_array($map) || !isset($map[(string) $staffId])
            || !is_array($map[(string) $staffId])) {
            return array();
        }

        $out = array();
        foreach ($map[(string) $staffId] as $v) {
            $id = (int) $v;
            /* Validated, not cast: a tampered map entry must not resolve onto a
               real colleague. Same rule as every other id in this module. */
            if ($id > 0 && (string) $id === trim((string) $v)) { $out[] = $id; }
        }
        return array_values(array_unique($out));
    }

    /**
     * The scoped queue.
     *
     * Built as one parameterised statement rather than through the query
     * builder, because the scope predicate arrives as SQL-plus-bindings from
     * Leadfinder_scope and the builder would need the two halves attached
     * separately — which is exactly where a predicate gets dropped and the
     * "scoped" list quietly becomes the whole table.
     *
     * An unrecognised status filter is ignored rather than passed through, so a
     * hand-typed `?status=` cannot turn into an unbound comparison.
     */
    /**
     * The queue, scoped, filtered, sorted and paged.
     *
     * WHAT CHANGED IN STEP 7
     * ----------------------
     * It was `LIMIT 500` with no offset and one filter. Past 500 rows the rest
     * of the queue was simply unreachable through the interface — not paginated,
     * not truncated with a warning, just absent, and the page said "Showing at
     * most 500 rows" as though that were a display preference rather than a
     * ceiling on what anyone could work.
     *
     * EVERY FILTER IS VALIDATED AGAINST A LIST, NOT ESCAPED
     * -----------------------------------------------------
     * Sort column and direction are matched against fixed sets and an unknown
     * value falls back to the default. They cannot be parameterised — a column
     * name is not a bind variable — so the only safe form is a whitelist, and
     * the only safe failure is the default rather than the caller's string.
     *
     * @return array rows, total, page, pages, per_page
     */
    public function queueRows(array $scope, $status = '', array $opt = array())
    {
        $where  = array();
        $params = array();

        if (trim($scope['sql']) !== '') {
            $where[]  = '(' . $scope['sql'] . ')';
            $params   = array_merge($params, $scope['params']);
        }

        if ($status !== '' && in_array($status, Leadfinder_status::all(), true)) {
            $where[]  = 'status = ?';
            $params[] = $status;
        }

        /* Free-text filter, matched against the columns a person would search. */
        $q = trim((string) (isset($opt['q']) ? $opt['q'] : ''));
        if ($q !== '') {
            $like     = '%' . $q . '%';
            $where[]  = '(business_name LIKE ? OR city LIKE ? OR pin_code LIKE ?'
                      . ' OR search_keyword LIKE ?)';
            $params[] = $like; $params[] = $like; $params[] = $like; $params[] = $like;
        }

        $owner = isset($opt['owner']) ? (int) $opt['owner'] : -1;
        if ($owner >= 0) {
            $where[]  = 'claimed_by = ?';
            $params[] = $owner;
        }

        if (!empty($opt['exclude_simulated'])) {
            $where[] = 'is_simulated = 0';
        }

        /*
         * WASTE LEAVES THE ACTIVE QUEUE, AND THAT IS THE DEFAULT.
         *
         * `waste_only` shows the suppression screen; otherwise wasted rows are
         * excluded unless a caller asks for them explicitly. The default is
         * exclusion rather than inclusion on purpose: an employee who rejected
         * a business should not see it in tomorrow's list because somebody
         * forgot to pass a flag, and a filter that has to be remembered is a
         * filter that will be forgotten.
         *
         * `undone_at IS NOT NULL` brings a restored row back, which is what an
         * undo is for.
         */
        if (!empty($opt['waste_only'])) {
            $where[] = '(wasted_at IS NOT NULL AND undone_at IS NULL)';
        } elseif (empty($opt['include_waste'])) {
            $where[] = '(wasted_at IS NULL OR undone_at IS NOT NULL)';
        }

        /* The employee's own shortlist. Never anybody else's: the parameter is
           the actor, supplied by the controller, not a staff id from the URL. */
        $savedFor = isset($opt['saved_by']) ? (int) $opt['saved_by'] : 0;
        if ($savedFor > 0) {
            $where[]  = 'id IN (SELECT prospect_id FROM `' . $this->t('payplex_lf_saved_prospects')
                      . '` WHERE staff_id = ?)';
            $params[] = $savedFor;
        }

        if (!empty($opt['review_only'])) {
            $where[] = 'review_flag = 1';
        }

        $kind = trim((string) (isset($opt['suppression_kind']) ? $opt['suppression_kind'] : ''));
        if ($kind !== '' && in_array($kind, array_keys(Leadfinder_tombstone::suppressionKinds()), true)) {
            $where[]  = 'suppression_kind = ?';
            $params[] = $kind;
        }

        $dupe = trim((string) (isset($opt['dupe_state']) ? $opt['dupe_state'] : ''));
        if ($dupe !== '' && in_array($dupe, Leadfinder_dupe::allVerdicts(), true)) {
            $where[]  = 'dupe_state = ?';
            $params[] = $dupe;
        }

        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $table    = $this->t('payplex_lf_prospects');

        $countRow = $this->db->query("SELECT COUNT(*) AS c FROM {$table}{$whereSql}", $params)
                             ->row_array();
        $total    = $countRow ? (int) $countRow['c'] : 0;

        /* Whitelists. An unknown value becomes the default, never the input. */
        $sortable = array('created_at', 'business_name', 'city', 'status', 'claimed_at', 'id');
        $sort     = isset($opt['sort']) && in_array($opt['sort'], $sortable, true)
                  ? $opt['sort'] : 'created_at';
        $dir      = isset($opt['dir']) && strtoupper($opt['dir']) === 'ASC' ? 'ASC' : 'DESC';

        $perPage = isset($opt['per_page']) ? (int) $opt['per_page'] : 50;
        $perPage = max(10, min(200, $perPage));
        $pages   = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page    = isset($opt['page']) ? (int) $opt['page'] : 1;
        $page    = max(1, min($pages, $page));
        $offset  = ($page - 1) * $perPage;

        /*
         * `id` is the tiebreaker on every sort. Without it, two rows with the
         * same created_at can swap places between page 1 and page 2, so a row
         * is shown twice and another is never shown at all — which in a work
         * queue means a business nobody ever calls.
         */
        $sql = "SELECT * FROM {$table}{$whereSql}"
             . " ORDER BY `{$sort}` {$dir}, id {$dir}"
             . " LIMIT " . (int) $perPage . " OFFSET " . (int) $offset;

        return array(
            'rows'     => $this->db->query($sql, $params)->result_array(),
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $perPage,
            'sort'     => $sort,
            'dir'      => $dir,
        );
    }

    /**
     * Apply one action to many prospects, refusing each one on its own merits.
     *
     * WHY THIS IS NOT A LOOP AROUND THE SINGLE-ROW PATH WITH THE CHECKS HOISTED
     * ------------------------------------------------------------------------
     * The tempting shape for a bulk action is: check the actor may do this,
     * then do it to every id. That check is about the ACTOR; the refusals that
     * matter are about the ROWS — this one is out of your scope, that one is
     * held by somebody else, this one is already closed. Hoisting the check out
     * of the loop turns a bulk button into a way to act on rows the single-row
     * path would have refused, which is exactly the shape of an authorisation
     * bypass.
     *
     * So every id goes through `prospectInScope()` and the same per-row rules,
     * and the result reports what happened to each. A bulk action that silently
     * skips what it could not do is a bulk action nobody can audit.
     *
     * The list is capped: a request naming ten thousand ids is not a person
     * clicking a button.
     */
    public function bulkAction($action, array $ids, $actorId, $band, array $teamIds,
                               $isManager, $now = null)
    {
        $now     = $now === null ? time() : (int) $now;
        $actorId = (int) $actorId;

        $allowed = array('claim', 'release', 'mark_irrelevant');

        if (!in_array($action, $allowed, true)) {
            return array('ok' => false, 'reason' => 'unknown_action', 'results' => array());
        }

        $ids = array_slice(array_values(array_unique(array_map('intval', $ids))), 0, 200);

        $results = array('done' => 0, 'refused' => 0, 'detail' => array());

        foreach ($ids as $id) {
            if ($id <= 0) { continue; }

            $p = $this->prospectInScope($id, $actorId, $band, $teamIds);

            if (!$p) {
                $results['refused']++;
                $results['detail'][$id] = 'not_in_your_scope';
                continue;
            }

            $outcome = $this->bulkOne($action, $p, $actorId, $isManager, $now);

            if ($outcome === 'ok') { $results['done']++; }
            else                   { $results['refused']++; }

            $results['detail'][$id] = $outcome;
        }

        $this->audit($actorId, 'prospect_bulk_' . $action, 'prospect', 0, array(
            'requested' => count($ids), 'done' => $results['done'],
            'refused' => $results['refused']));

        return array('ok' => true, 'reason' => 'completed', 'results' => $results);
    }

    /** One row of a bulk action, with the same rules the single-row path applies. */
    private function bulkOne($action, array $p, $actorId, $isManager, $now)
    {
        if ($action === 'claim') {
            $d = Leadfinder_claim::canClaim($p, $actorId, $this->claimsToday($actorId),
                                            $now, $this->claimConfig());

            if (!$d['allowed']) { return $d['reason']; }

            $r = $this->applyClaim($p, $actorId, $d, $now);

            return !empty($r['claimed']) ? 'ok' : $r['reason'];
        }

        if ($action === 'release') {
            $d = Leadfinder_claim::canRelease($p, $actorId, $isManager === true);

            if (!$d['allowed']) { return $d['reason']; }

            $r = $this->applyRelease($p, $actorId, $d['reason'], $now, $isManager === true);

            return !empty($r['released']) ? 'ok' : $r['reason'];
        }

        /* mark_irrelevant — a state change, so it obeys the transition table. */
        $t = Leadfinder_status::canTransition($p['status'], Leadfinder_status::IRRELEVANT,
                                              array('is_manager' => $isManager === true));

        if (!$t['allowed']) { return $t['reason']; }

        if ((int) $p['claimed_by'] !== $actorId && !$isManager) {
            return Leadfinder_claim::R_NOT_OWNER;
        }

        $this->db->query(
            "UPDATE `" . $this->t('payplex_lf_prospects') . "`
                SET status = ?, last_touch_at = ?
              WHERE id = ? AND status = ?",
            array(Leadfinder_status::IRRELEVANT, (int) $now, (int) $p['id'], $p['status'])
        );

        if ((int) $this->db->affected_rows() === 0) { return 'status_changed_since_you_looked'; }

        $this->audit($actorId, 'prospect_marked_irrelevant', 'prospect', (int) $p['id'],
                     array('from' => $p['status']));

        return 'ok';
    }

    /**
     * How many distinct businesses each phone number appears on.
     *
     * Feeds the shared-switchboard downgrade: a number already on three
     * different businesses is a reception line, and the fourth match is not
     * evidence that the fourth business is a duplicate of the first.
     */
    public function phoneBusinessCounts(array $numbers)
    {
        $numbers = array_values(array_filter(array_map('trim', $numbers)));

        if (!$numbers) { return array(); }

        $in   = implode(',', array_fill(0, count($numbers), '?'));
        $rows = $this->db->query(
            "SELECT phone_e164, COUNT(DISTINCT google_place_id) AS c
               FROM `" . $this->t('payplex_lf_prospects') . "`
              WHERE phone_e164 IN ({$in})
              GROUP BY phone_e164",
            $numbers
        )->result_array();

        $out = array();
        foreach ($rows as $r) { $out[$r['phone_e164']] = (int) $r['c']; }

        return $out;
    }

    /** The duplicate options every comparison should be made with. */
    public function dupeOptions(array $candidates = array())
    {
        $numbers = array();
        foreach ($candidates as $c) {
            if (!empty($c['phone_e164'])) { $numbers[] = $c['phone_e164']; }
        }

        $listed = array_filter(array_map('trim',
            preg_split('/[,;|\r\n]+/', (string) $this->config('shared_switchboard_numbers', ''))));

        return array(
            'phone_profile'          => $this->phoneProfile(),
            /*
             * `dupe_shared_hosts` — the key the rest of the module has always
             * used. The first draft of this method invented `shared_hosts`,
             * which read empty on every install and silently disabled the
             * shared-host downgrade for every comparison that went through here.
             */
            'shared_hosts'           => array_filter(array_map('trim',
                                          preg_split('/[,;|\r\n]+/',
                                          (string) $this->config('dupe_shared_hosts', '')))),
            'shared_numbers'         => $listed,
            'shared_phone_threshold' => $this->configInt('shared_phone_business_threshold', 3),
            'phone_business_counts'  => $numbers ? $this->phoneBusinessCounts($numbers) : array(),
        );
    }

    public function prospect($id)
    {
        $id = (int) $id;
        if ($id <= 0) { return null; }
        $r = $this->db->where('id', $id)->get($this->t('payplex_lf_prospects'))->row_array();
        return $r ? $r : null;
    }

    /**
     * A prospect the actor is actually allowed to see.
     *
     * THE HOLE THIS CLOSES
     * --------------------
     * `prospect()` fetches any row by id. The queue is scoped — `queueRows()`
     * applies the band's list predicate — but the single-row fetch was not, and
     * the controller handed it a value straight from the URL. So an employee
     * whose band is self-only could address `/finder/claim/<any id>` and act on
     * a row that never appears in their queue. The claim rules refused most of
     * those attempts for their own reasons, which is why nothing leaked yet; it
     * was one endpoint away from mattering, and Task 6 and Task 8 are that
     * endpoint.
     *
     * Scope is now enforced at the fetch, so every caller inherits it rather
     * than each one remembering. Out of scope returns null, and the controller
     * answers 404 — not 403, which would confirm the id exists.
     */
    public function prospectInScope($id, $actorId, $band, array $teamIds = array())
    {
        $p = $this->prospect($id);

        if ($p === null) { return null; }

        $d = Leadfinder_scope::canRead($p, $actorId, $band, $teamIds);

        return (isset($d['allowed']) && $d['allowed'] === true) ? $p : null;
    }

    public function claimsToday($staffId)
    {
        $start = strtotime('today midnight');
        $r = $this->db->select('COUNT(*) AS c', false)
                      ->where('actor_id', (int) $staffId)
                      ->where('event', 'claimed')
                      ->where('at >=', $start)
                      ->get($this->t('payplex_lf_ownership'))->row_array();
        return $r ? (int) $r['c'] : 0;
    }

    public function claimMessage($reason)
    {
        $m = array(
            Leadfinder_claim::R_HELD         => 'Another employee is working this prospect.',
            Leadfinder_claim::R_ALREADY_MINE => 'You already hold this prospect.',
            Leadfinder_claim::R_DAILY_LIMIT  => 'You have reached your daily claim limit.',
            Leadfinder_claim::R_TERMINAL     => 'This prospect is closed and cannot be claimed.',
            Leadfinder_claim::R_SUPPRESSED   => 'This business is on the do-not-contact list.',
            Leadfinder_claim::R_BAD_PROSPECT => 'No such prospect.',
            Leadfinder_claim::R_NO_ACTOR     => 'You are not signed in.',
            Leadfinder_claim::R_NOT_OWNER    => 'That prospect is not yours to release.',
            Leadfinder_claim::R_NOT_MANAGER  => 'Only a manager may do that.',
            Leadfinder_claim::R_NOT_CLAIMED  => 'That prospect is not claimed.',
        );
        return isset($m[$reason]) ? $m[$reason] : 'That action was refused.';
    }

    /**
     * Take the claim, or lose the race and be told so.
     *
     * THE DEFECT THIS REPLACES
     * ------------------------
     * This was an unconditional `UPDATE … WHERE id = ?`. `Leadfinder_claim::canClaim()`
     * read the row, saw `claimed_by = 0`, and said yes; the update then wrote
     * the claim whatever the row said by the time it landed. Two employees
     * clicking Claim on the same prospect within the same second both passed the
     * check, both wrote, and **both were told "Prospect claimed"** — while only
     * the second one owned it. The first employee then rang a business somebody
     * else was already ringing, which is the precise thing the claim exists to
     * prevent.
     *
     * It is the same shape as the quota race and the webhook insert: a decision
     * taken against a value that was already stale, and a write that does not
     * re-check it.
     *
     * The condition re-states the claim rule in SQL: the row must still be
     * unclaimed, or held by this actor, or stale past the idle window. If no row
     * changes, somebody else got there first and the caller is told — not
     * shown a success message for work it did not do.
     *
     * @return array claimed (bool), reason
     */
    public function applyClaim(array $p, $actorId, array $decision, $now)
    {
        $actorId = (int) $actorId;
        $now     = (int) $now;
        $idle    = (int) $this->configInt('claim_idle_release_seconds', 0);
        $table   = $this->t('payplex_lf_prospects');

        $sql = "UPDATE `{$table}`
                   SET claimed_by = ?, assigned_staff = ?, claimed_at = ?,
                       last_touch_at = ?, status = ?
                 WHERE id = ?
                   AND (claimed_by = 0 OR claimed_by = ?";

        $params = array($actorId, $actorId, $now, $now, Leadfinder_status::CLAIMED,
                        (int) $p['id'], $actorId);

        /*
         * A stale claim may be taken over, but only when an idle window is
         * configured. With no window, an unreleased claim is held until somebody
         * releases it — which is a decision for the administrator, not a default
         * this method invents.
         */
        if ($idle > 0) {
            $sql .= " OR (claimed_at IS NOT NULL AND claimed_at <= ?)";
            $params[] = $now - $idle;
        }

        $sql .= ')';

        $this->db->query($sql, $params);

        if ((int) $this->db->affected_rows() === 0) {
            /*
             * Nothing changed. Either another employee claimed it between the
             * check and here, or the row vanished. Both are "you did not get
             * it", and saying so is the whole point.
             */
            $this->audit($actorId, 'prospect_claim_conflict', 'prospect', (int) $p['id'],
                         array('reason' => 'lost_race_or_row_gone'));

            return array('claimed' => false, 'reason' => Leadfinder_claim::R_HELD);
        }

        $this->ownership('claimed', $p, $actorId, $actorId, $now,
                         isset($decision['reason']) ? $decision['reason'] : '');
        $this->audit($actorId, 'prospect_claimed', 'prospect', (int) $p['id'], array());

        return array('claimed' => true, 'reason' => Leadfinder_claim::OK);
    }

    /**
     * Release a claim — only the one that is actually there.
     *
     * Conditional for the same reason as the claim. An unconditional release
     * would let a stale page, a double click, or a queued request release a
     * claim that a different employee has since taken: the releasing employee
     * saw "Release" on a row they owned when the page rendered, and by the time
     * they clicked it the row had been reclaimed by somebody else after a TTL
     * expiry.
     *
     * A manager may release anybody's claim, so their condition is the row still
     * being claimed at all rather than being claimed by them.
     */
    public function applyRelease(array $p, $actorId, $reason, $now, $isManager = false)
    {
        $actorId = (int) $actorId;
        $table   = $this->t('payplex_lf_prospects');

        $sql = "UPDATE `{$table}`
                   SET claimed_by = 0, assigned_staff = 0, claimed_at = NULL,
                       last_touch_at = ?, status = ?
                 WHERE id = ? AND claimed_by " . ($isManager ? '<> 0' : '= ?');

        $params = array((int) $now, Leadfinder_status::NEW_RESULT, (int) $p['id']);

        if (!$isManager) { $params[] = $actorId; }

        $this->db->query($sql, $params);

        if ((int) $this->db->affected_rows() === 0) {
            $this->audit($actorId, 'prospect_release_conflict', 'prospect', (int) $p['id'],
                         array('reason' => 'not_held_by_actor_any_more'));

            return array('released' => false, 'reason' => Leadfinder_claim::R_NOT_OWNER);
        }

        $this->ownership('released', $p, $actorId, 0, $now, (string) $reason);
        $this->audit($actorId, 'prospect_released', 'prospect', (int) $p['id'],
                     array('reason' => (string) $reason));

        return array('released' => true, 'reason' => 'released');
    }

    /**
     * Move a claim from one employee to another. Managers only.
     *
     * Written as one conditional UPDATE for the same reason as the claim: the
     * row must still be held by the person the manager thinks holds it. A
     * reassignment that lands after the original owner released it would
     * otherwise assign a prospect the manager never looked at.
     *
     * `$fromStaffId` comes from the row the manager was shown, not from the
     * request, so a stale page cannot be used to move somebody else's claim.
     */
    public function applyReassign(array $p, $actorId, $toStaffId, $now, $reason = '')
    {
        $toStaffId = (int) $toStaffId;
        $from      = (int) $p['claimed_by'];
        $table     = $this->t('payplex_lf_prospects');

        if ($toStaffId <= 0) {
            return array('reassigned' => false, 'reason' => 'no_target_employee');
        }

        if ($toStaffId === $from) {
            return array('reassigned' => false, 'reason' => 'already_theirs');
        }

        $this->db->query(
            "UPDATE `{$table}`
                SET claimed_by = ?, assigned_staff = ?, claimed_at = ?, last_touch_at = ?
              WHERE id = ? AND claimed_by = ?",
            array($toStaffId, $toStaffId, (int) $now, (int) $now, (int) $p['id'], $from)
        );

        if ((int) $this->db->affected_rows() === 0) {
            $this->audit($actorId, 'prospect_reassign_conflict', 'prospect', (int) $p['id'],
                         array('expected_owner' => $from));

            return array('reassigned' => false, 'reason' => 'ownership_changed_since_you_looked');
        }

        $this->ownership('reassigned', $p, $actorId, $toStaffId, $now, (string) $reason);
        $this->audit($actorId, 'prospect_reassigned', 'prospect', (int) $p['id'],
                     array('from' => $from, 'to' => $toStaffId, 'reason' => (string) $reason));

        return array('reassigned' => true, 'reason' => 'reassigned', 'from' => $from, 'to' => $toStaffId);
    }

    private function ownership($event, array $p, $actorId, $toStaff, $now, $reason)
    {
        $this->db->insert($this->t('payplex_lf_ownership'),
            Leadfinder_claim::historyEntry($event, $p, $actorId, $toStaff, $now, $reason));
    }

    /* ================================================================
     * Retention sweep (§14.3)
     * ============================================================== */

    /**
     * Clear coordinates older than the configured window. Nulls two columns and
     * nothing else; the prospect, its place id and the employee's verification
     * work all survive.
     */
    public function purgeExpiredCoordinates($now = null)
    {
        $now  = $now === null ? time() : (int) $now;
        $days = $this->configInt('coords_max_calendar_days', 0);
        if ($days <= 0) {
            return array('cleared' => 0, 'examined' => 0, 'skipped' => 'retention_not_configured');
        }

        $batch = $this->configInt('retention_job_batch_size', 500);
        if ($batch < 1) { $batch = 500; }

        $rows = $this->db->select('id, latitude, longitude, google_fetched_at')
                         ->where('coords_purged_at IS NULL', null, false)
                         ->where('(latitude IS NOT NULL OR longitude IS NOT NULL)', null, false)
                         ->limit($batch)->get($this->t('payplex_lf_prospects'))->result_array();

        $cleared = 0;
        foreach ($rows as $r) {
            /*
             * The configured day count is passed in rather than left to the
             * library's own constant. Both say 30 today, and that is exactly the
             * problem: the gate above reads config, the test read a constant, so
             * an administrator shortening the window would have seen the setting
             * accepted and nothing purge any sooner. One number, read once, used
             * for both decisions.
             */
            if (!Leadfinder_retention::coordinatesExpired($r, $now, 'UTC', $days)) { continue; }
            $u = Leadfinder_retention::expiryUpdate();
            $u['coords_purged_at'] = $now;
            /*
             * `coords_purged_at IS NULL` in the UPDATE as well as the SELECT.
             * Two sweeps that somehow overlap then cannot both clear the same
             * row — the second changes nothing and counts nothing. This is what
             * makes the job idempotent at row level, independent of the lock.
             */
            $this->db->where('id', (int) $r['id'])
                     ->where('coords_purged_at IS NULL', null, false)
                     ->update($this->t('payplex_lf_prospects'), $u);
            if ($this->db->affected_rows() > 0) { $cleared++; }
        }
        if ($cleared > 0) {
            $this->audit(0, 'coordinates_purged', 'retention', 0,
                         array('cleared' => $cleared, 'max_calendar_days' => $days));
        }
        return array('cleared' => $cleared, 'examined' => count($rows), 'skipped' => null);
    }

    /**
     * How many prospects are sitting past their retention window right now.
     *
     * Used to tell a healthy quiet run apart from a broken one: a sweep that
     * purged nothing while this returns a positive number is the silent failure
     * worth alerting on, and it looks identical to a good day without it.
     */
    public function expiredCoordinatesWaiting($now = null)
    {
        $now  = $now === null ? time() : (int) $now;
        $days = $this->configInt('coords_max_calendar_days', 0);
        if ($days <= 0) { return 0; }

        $rows = $this->db->select('id, latitude, longitude, google_fetched_at')
                         ->where('coords_purged_at IS NULL', null, false)
                         ->where('(latitude IS NOT NULL OR longitude IS NOT NULL)', null, false)
                         ->limit(1000)->get($this->t('payplex_lf_prospects'))->result_array();

        $n = 0;
        foreach ($rows as $r) {
            if (Leadfinder_retention::coordinatesExpired($r, $now, 'UTC', $days)) { $n++; }
        }
        return $n;
    }

    /* ---------------------------------------------------------------- *
     * Job lock and run history
     * ---------------------------------------------------------------- */

    /**
     * Take the lock, or fail.
     *
     * The whole decision is one conditional UPDATE, so the database picks the
     * winner. A read-then-write — "is it free? then take it" — is the race this
     * project has already paid for once in the follow-up module, where two cron
     * runs both read "not sent" and the lead was emailed twice.
     *
     * `affected_rows() < 1` means somebody else holds it. A lock older than its
     * TTL is reclaimable, so one crashed sweep costs at most a few ticks rather
     * than wedging the job for ever.
     */
    /** Lock tokens this request holds, keyed by lock name. */
    private $jobLockOwner = array();

    public function acquireJobLock($key, $now, $ttl = null)
    {
        $table = $this->t('payplex_lf_job_locks');
        if (!$this->db->table_exists($table)) { return false; }

        $now = (int) $now;
        $ttl = $ttl === null ? $this->configInt('retention_job_lock_ttl_seconds', 900) : (int) $ttl;
        if ($ttl < 1) { $ttl = 900; }

        $owner = substr(md5(uniqid('lf', true)), 0, 32);

        $this->db->query(
            "UPDATE `{$table}` SET `locked_at` = ?, `locked_by` = ?, `updated_at` = ?
             WHERE `lock_key` = ? AND (`locked_at` IS NULL OR `locked_at` <= ?)",
            array($now, $owner, $now, (string) $key, $now - $ttl)
        );

        if ($this->db->affected_rows() < 1) {
            return false;
        }

        /*
         * The owner token is remembered so the release can prove it still holds
         * the lock it is releasing. See `releaseJobLock` for why that matters.
         */
        $this->jobLockOwner[(string) $key] = $owner;

        return true;
    }

    /** Token of the lock this process currently holds, if any. */
    public function jobLockOwner($key)
    {
        return isset($this->jobLockOwner[(string) $key]) ? $this->jobLockOwner[(string) $key] : '';
    }

    /**
     * Release the lock — but only if this process still owns it.
     *
     * FOUND IN REVIEW, BEFORE DEPLOYMENT
     * ----------------------------------
     * The release used to clear the row by key alone. Consider the sequence the
     * TTL exists to handle: sweep A takes the lock and stalls; its lock ages
     * past the TTL; sweep B reclaims it and starts working; A finally returns
     * and releases. Under a key-only release, A — which no longer owns
     * anything — unlocks the row while B is mid-sweep, and the next tick starts
     * a second concurrent sweep. The lock would then be exactly as good as no
     * lock in the one scenario it was built for.
     *
     * Matching on `locked_by` makes a stale process's release a no-op. Safe to
     * call when the lock was never held, which the cron path relies on.
     */
    public function releaseJobLock($key, $now, $owner = null)
    {
        $table = $this->t('payplex_lf_job_locks');
        if (!$this->db->table_exists($table)) { return; }

        $owner = $owner === null ? $this->jobLockOwner($key) : (string) $owner;

        if ($owner === '') {
            /* Nothing was taken by this process, so there is nothing for it to
               give back. Releasing anyway would be the defect above. */
            return;
        }

        $this->db->query(
            "UPDATE `{$table}` SET `locked_at` = NULL, `locked_by` = NULL, `updated_at` = ?
             WHERE `lock_key` = ? AND `locked_by` = ?",
            array((int) $now, (string) $key, $owner)
        );
    }

    /** Epoch of the last retention run, or null if it has never run. */
    public function retentionLastRunAt()
    {
        $table = $this->t('payplex_lf_retention_runs');
        if (!$this->db->table_exists($table)) { return null; }

        $row = $this->db->select('started_at')->order_by('started_at', 'desc')
                        ->limit(1)->get($table)->row();

        return $row ? (int) $row->started_at : null;
    }

    /**
     * Run the sweep and write the run record. One call, one row, always.
     *
     * A run that examined rows and purged none is recorded exactly as carefully
     * as one that purged hundreds — the whole point of the history is to be able
     * to show the job ran, not only that it deleted something.
     */
    public function runRetentionSweep($now, $mode = 'cron', $actorId = 0)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_retention_job.php';

        $started = (int) $now;
        $result  = $this->purgeExpiredCoordinates($started);
        $rec     = Leadfinder_retention_job::runRecord(
            $started, time(), $result['examined'], $result['cleared'], null, $mode);

        $this->recordRetentionRun($rec, $actorId);

        return $rec;
    }

    public function recordRetentionFailure($now, $message, $mode = 'cron', $actorId = 0)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_retention_job.php';

        $rec = Leadfinder_retention_job::runRecord((int) $now, time(), 0, 0, $message, $mode);
        $this->recordRetentionRun($rec, $actorId);

        return $rec;
    }

    private function recordRetentionRun(array $rec, $actorId)
    {
        $table = $this->t('payplex_lf_retention_runs');
        if (!$this->db->table_exists($table)) { return; }

        $rec['actor_id'] = (int) $actorId;
        $this->db->insert($table, $rec);
    }

    /** The last N runs, for the admin screen and for evidence. */
    public function retentionRuns($limit = 20)
    {
        $table = $this->t('payplex_lf_retention_runs');
        if (!$this->db->table_exists($table)) { return array(); }

        return $this->db->order_by('id', 'desc')->limit((int) $limit)
                        ->get($table)->result_array();
    }

    /* ================================================================
     * Audit
     * ============================================================== */

    /**
     * Every security-relevant event. `detail` is scrubbed on the way in — the
     * shape-based scrub is the second line of defence behind never putting a
     * key in here in the first place.
     */
    /* ================================================================
     * Verification and the conversion gate
     * ============================================================== */

    /**
     * Record what happened on a call.
     *
     * Only the fields on `Leadfinder_verification::writableFields()` are ever
     * written. A verification form that saved whatever was posted would be a
     * form that could write `claimed_by`, `status` or `converted_lead_id` — and
     * the person filling it in is, by design, somebody who may not change those.
     */
    public function saveVerification($prospectId, $actorId, array $d, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_verification.php';

        $now       = $now === null ? time() : (int) $now;
        $actorId   = (int) $actorId;
        $prospect  = $this->prospect((int) $prospectId);

        if (!$prospect) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect could not be found.');
        }

        if ((int) $prospect['claimed_by'] !== $actorId
            && !(function_exists('is_admin') && is_admin())) {
            return array('ok' => false, 'reason' => 'not_yours',
                         'message' => 'You can only record a call on a prospect you have claimed.');
        }

        /*
         * A prospect already awaiting approval is frozen.
         *
         * Otherwise the submitter can keep editing the evidence after an
         * approver has started reading it — and the approver would be checking
         * a version that no longer exists.
         */
        if ((string) $prospect['status'] === Leadfinder_status::CONVERSION_PENDING) {
            return array('ok' => false, 'reason' => 'awaiting_approval',
                         'message' => 'This prospect is awaiting approval and cannot be edited. '
                                    . 'Ask the approver to send it back first.');
        }

        $v = Leadfinder_verification::validate($d, $now);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => 'incomplete',
                         'missing' => $v['missing'], 'errors' => $v['errors'],
                         'message' => $this->verificationMessage($v));
        }

        $row = array();

        foreach (Leadfinder_verification::writableFields() as $f) {
            if (!array_key_exists($f, $d)) { continue; }

            if ($f === 'phone_verified' || $f === 'email_verified') {
                $row[$f] = !empty($d[$f]) ? 1 : 0;
            } elseif ($f === 'next_followup_at') {
                $row[$f] = (int) $d[$f] > 0 ? (int) $d[$f] : null;
            } else {
                $v2 = trim((string) $d[$f]);
                $row[$f] = $v2 === '' ? null : $v2;
            }
        }

        $row['verified_at']   = $now;
        $row['verified_by']   = $actorId;
        $row['last_touch_at'] = $now;

        $target = Leadfinder_verification::statusFor(
            (string) $d['call_disposition'],
            isset($d['interest_level']) ? (string) $d['interest_level'] : '');

        if ($target !== null && $target !== (string) $prospect['status']) {
            $t = Leadfinder_status::canTransition($prospect['status'], $target, array(
                'reason' => isset($d['rejection_reason']) ? (string) $d['rejection_reason'] : '',
            ));

            if ($t['allowed']) {
                $row['status'] = $target;
            }
            /*
             * A refused transition is NOT an error here: the call happened and
             * its record is worth keeping even when the state machine will not
             * move. Discarding the whole verification because the status could
             * not change would lose the only account of the conversation.
             */
        }

        $this->db->where('id', (int) $prospectId)
                 ->update($this->t('payplex_lf_prospects'), $row);

        $this->audit($actorId, 'prospect_verified', 'prospect', (int) $prospectId, array(
            'disposition' => (string) $d['call_disposition'],
            'interest'    => isset($d['interest_level']) ? (string) $d['interest_level'] : '',
            'status_from' => (string) $prospect['status'],
            'status_to'   => isset($row['status']) ? $row['status'] : (string) $prospect['status'],
        ));

        return array('ok' => true, 'reason' => 'recorded',
                     'status' => isset($row['status']) ? $row['status'] : $prospect['status'],
                     'message' => 'Call recorded.');
    }

    private function verificationMessage(array $v)
    {
        $parts = array();

        foreach ($v['missing'] as $why) { $parts[] = $why; }
        foreach ($v['errors'] as $why)  { $parts[] = $why; }

        return 'Nothing was saved. Still needed: ' . implode('; ', $parts) . '.';
    }

    /**
     * Submit a verified prospect for approval. **Writes no lead.**
     *
     * This is the maker half of maker-checker. It moves the prospect into the
     * waiting room and records who put it there, and that is all it does — the
     * CRM's Leads table is not touched on this path at all, which is what makes
     * the control meaningful rather than decorative.
     */
    public function submitConversion($prospectId, $actorId, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_verification.php';

        $now      = $now === null ? time() : (int) $now;
        $actorId  = (int) $actorId;
        $prospect = $this->prospect((int) $prospectId);

        if (!$prospect) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect could not be found.');
        }

        $may = Leadfinder_verification::submitterMaySubmit($prospect, $actorId);

        if (!$may['allowed']) {
            return array('ok' => false, 'reason' => $may['reason'],
                         'message' => Leadfinder_verification::message($may['reason']));
        }

        $evidence = Leadfinder_verification::evidenceFrom($prospect);
        $gate     = Leadfinder_status::mayConvert($prospect['status'], $evidence);

        if (!$gate['allowed']) {
            return array('ok' => false, 'reason' => $gate['reason'],
                         'missing' => isset($gate['missing']) ? $gate['missing'] : array(),
                         'message' => 'Not submitted. Missing: '
                                    . implode(', ', array_map(function ($k) {
                                        $r = Leadfinder_status::conversionRequirements();
                                        return isset($r[$k]) ? $r[$k] : $k;
                                      }, isset($gate['missing']) ? $gate['missing'] : array())) . '.');
        }

        $idem  = Leadfinder_verification::conversionKey((int) $prospectId);
        $table = $this->t('payplex_lf_conversions');

        $this->db->trans_begin();

        try {
            /*
             * Claim-first against the UNIQUE key on prospect_id. Two submissions
             * at once produce one row, and the second caller is told the
             * submission already exists rather than creating a second one.
             */
            $this->db->query(
                "INSERT IGNORE INTO `{$table}`
                   (prospect_id, idem_key, state, submitted_by, submitted_at, evidence)
                 VALUES (?, ?, 'pending', ?, ?, ?)",
                array((int) $prospectId, $idem, $actorId, $now,
                      json_encode($evidence))
            );

            if ((int) $this->db->affected_rows() === 0) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'already_decided',
                             'message' => Leadfinder_verification::message('already_decided'));
            }

            /* Conditional: the status must still be what was checked. */
            $this->db->query(
                "UPDATE `" . $this->t('payplex_lf_prospects') . "`
                    SET status = ?, last_touch_at = ?
                  WHERE id = ? AND status = ?",
                array(Leadfinder_status::CONVERSION_PENDING, $now, (int) $prospectId,
                      Leadfinder_status::CONFIRMED_INTERESTED)
            );

            if ((int) $this->db->affected_rows() === 0) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'not_pending',
                             'message' => 'This prospect changed while you were working on it. '
                                        . 'Nothing was submitted.');
            }

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'transaction_failed',
                             'message' => 'Nothing was submitted.');
            }

            $this->db->trans_commit();
        } catch (Exception $e) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'transaction_failed',
                         'message' => 'Nothing was submitted.');
        }

        $this->audit($actorId, 'conversion_submitted', 'prospect', (int) $prospectId,
                     array('evidence' => $evidence));

        return array('ok' => true, 'reason' => 'submitted',
                     'message' => 'Submitted for approval. Somebody else has to approve it.');
    }

    /**
     * Approve a submission and create the CRM lead. **The only path that does.**
     *
     * Three properties, each load-bearing:
     *
     *   - the approver is not the submitter, checked against the STORED
     *     submitter rather than anything in the request;
     *   - the insert and the decision commit together, so a lead cannot exist
     *     without a recorded approval and an approval cannot exist without its
     *     lead;
     *   - a retry finds the decision already made and returns the SAME lead id,
     *     so a double-click or a browser replay creates no second lead.
     */
    public function approveConversion($prospectId, $approverId, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_verification.php';
        require_once __DIR__ . '/../libraries/Leadfinder_event.php';

        $now        = $now === null ? time() : (int) $now;
        $approverId = (int) $approverId;
        $table      = $this->t('payplex_lf_conversions');

        $conv = $this->db->where('prospect_id', (int) $prospectId)->get($table)->row_array();

        if (!$conv) {
            return array('ok' => false, 'reason' => 'not_pending',
                         'message' => Leadfinder_verification::message('not_pending'));
        }

        /*
         * Idempotent replay, answered before anything is locked.
         *
         * An already-approved conversion returns its existing lead id. That is
         * the difference between "retry is safe" and "retry creates a second
         * lead for the same business" — and a retry is not unusual: it is what a
         * double-click and a browser back-button both look like.
         */
        if ((string) $conv['state'] === Leadfinder_verification::C_APPROVED) {
            return array('ok' => true, 'reason' => 'already_converted', 'replayed' => true,
                         'lead_id' => (int) $conv['lead_id'],
                         'message' => Leadfinder_verification::message('already_converted'));
        }

        if ((string) $conv['state'] !== Leadfinder_verification::C_PENDING) {
            return array('ok' => false, 'reason' => 'already_decided',
                         'message' => Leadfinder_verification::message('already_decided'));
        }

        $may = Leadfinder_verification::approverMayApprove(
            (int) $conv['submitted_by'], $approverId,
            function_exists('is_admin') && is_admin());

        if (!$may['allowed']) {
            $this->audit($approverId, 'conversion_self_approval_refused', 'prospect',
                         (int) $prospectId, array('submitted_by' => (int) $conv['submitted_by']));

            return array('ok' => false, 'reason' => $may['reason'],
                         'message' => Leadfinder_verification::message($may['reason']));
        }

        $prospect = $this->prospect((int) $prospectId);

        if (!$prospect) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect could not be found.');
        }

        /* The gate is re-run at approval time, against the stored row. */
        $gate = Leadfinder_status::mayConvert($prospect['status'],
                    Leadfinder_verification::evidenceFrom($prospect));

        if (!$gate['allowed']) {
            return array('ok' => false, 'reason' => $gate['reason'],
                         'message' => 'Not approved: the verification evidence is no longer complete.');
        }

        $this->db->trans_begin();

        try {
            /*
             * Claim the decision FIRST, conditionally on it still being pending.
             * Two approvers acting at once: one changes a row, the other changes
             * none and is told the decision was already made. The lead is then
             * created by whoever won, inside the same transaction.
             */
            $this->db->query(
                "UPDATE `{$table}` SET state = ?, decided_by = ?, decided_at = ?
                  WHERE prospect_id = ? AND state = ?",
                array(Leadfinder_verification::C_APPROVED, $approverId, $now,
                      (int) $prospectId, Leadfinder_verification::C_PENDING)
            );

            if ((int) $this->db->affected_rows() === 0) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'already_decided',
                             'message' => Leadfinder_verification::message('already_decided'));
            }

            $leadId = $this->createLeadFromProspect($prospect, $approverId, $now);

            if ($leadId <= 0) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'lead_insert_failed',
                             'message' => 'The CRM lead could not be created, so nothing was '
                                        . 'approved. The prospect is unchanged.');
            }

            $this->db->query("UPDATE `{$table}` SET lead_id = ? WHERE prospect_id = ?",
                             array($leadId, (int) $prospectId));

            $this->db->query(
                "UPDATE `" . $this->t('payplex_lf_prospects') . "`
                    SET status = ?, converted_lead_id = ?, last_touch_at = ?
                  WHERE id = ?",
                array(Leadfinder_status::CONVERTED_TO_LEAD, $leadId, $now, (int) $prospectId)
            );

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'transaction_failed',
                             'message' => 'Nothing was approved.');
            }

            $this->db->trans_commit();
        } catch (Exception $e) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'transaction_failed',
                         'message' => 'Nothing was approved.');
        }

        $this->audit($approverId, 'conversion_approved', 'prospect', (int) $prospectId, array(
            'submitted_by' => (int) $conv['submitted_by'],
            'approved_by'  => $approverId,
            'lead_id'      => $leadId,
        ));

        return array('ok' => true, 'reason' => 'approved', 'lead_id' => $leadId,
                     'replayed' => false,
                     'message' => 'Approved. CRM lead #' . $leadId . ' created.');
    }

    /** Send a submission back, or refuse it. Both need an approver and a reason. */
    public function rejectConversion($prospectId, $approverId, $reason, $sendBack = false, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_verification.php';

        $now        = $now === null ? time() : (int) $now;
        $approverId = (int) $approverId;
        $table      = $this->t('payplex_lf_conversions');

        if (!Leadfinder_verification::rejectionReasonOk($reason)) {
            return array('ok' => false, 'reason' => 'rejection_reason_required',
                         'message' => Leadfinder_verification::message('rejection_reason_required'));
        }

        $conv = $this->db->where('prospect_id', (int) $prospectId)->get($table)->row_array();

        if (!$conv || (string) $conv['state'] !== Leadfinder_verification::C_PENDING) {
            return array('ok' => false, 'reason' => 'not_pending',
                         'message' => Leadfinder_verification::message('not_pending'));
        }

        $may = Leadfinder_verification::approverMayApprove(
            (int) $conv['submitted_by'], $approverId,
            function_exists('is_admin') && is_admin());

        if (!$may['allowed']) {
            return array('ok' => false, 'reason' => $may['reason'],
                         'message' => Leadfinder_verification::message($may['reason']));
        }

        $this->db->trans_begin();

        try {
            $this->db->query(
                "UPDATE `{$table}` SET state = ?, decided_by = ?, decided_at = ?, decision_reason = ?
                  WHERE prospect_id = ? AND state = ?",
                array(Leadfinder_verification::C_REJECTED, $approverId, $now,
                      substr(trim((string) $reason), 0, 500), (int) $prospectId,
                      Leadfinder_verification::C_PENDING)
            );

            if ((int) $this->db->affected_rows() === 0) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'already_decided',
                             'message' => Leadfinder_verification::message('already_decided'));
            }

            /*
             * Sent back for more work, or refused outright. Both are the
             * approver's decision and both are recorded as one; the difference
             * is only whether the employee can act on it again.
             */
            $target = $sendBack ? Leadfinder_status::CONFIRMED_INTERESTED
                                : Leadfinder_status::REJECTED;

            $this->db->query(
                "UPDATE `" . $this->t('payplex_lf_prospects') . "`
                    SET status = ?, rejection_reason = ?, last_touch_at = ?
                  WHERE id = ? AND status = ?",
                array($target, substr(trim((string) $reason), 0, 255), $now,
                      (int) $prospectId, Leadfinder_status::CONVERSION_PENDING)
            );

            if ($this->db->trans_status() === false) {
                $this->db->trans_rollback();

                return array('ok' => false, 'reason' => 'transaction_failed',
                             'message' => 'Nothing was changed.');
            }

            $this->db->trans_commit();
        } catch (Exception $e) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'transaction_failed',
                         'message' => 'Nothing was changed.');
        }

        $this->audit($approverId, $sendBack ? 'conversion_sent_back' : 'conversion_rejected',
                     'prospect', (int) $prospectId, array(
                        'submitted_by' => (int) $conv['submitted_by'],
                        'decided_by'   => $approverId,
                        'reason'       => substr(trim((string) $reason), 0, 200)));

        return array('ok' => true, 'reason' => $sendBack ? 'sent_back' : 'rejected',
                     'message' => $sendBack
                        ? 'Sent back to the employee with your reason.'
                        : 'Rejected, with your reason recorded.');
    }

    /**
     * Insert the CRM lead. Called only from inside the approval transaction.
     *
     * Every field is taken from the verified prospect. Nothing is invented and
     * nothing is copied from a request: this runs after an approval, and the
     * approver approved what is stored.
     *
     * `hash` is required by Perfex — a lead without one cannot be opened through
     * the public link, and three leads on this staging install already have that
     * problem. It is generated here rather than left to a default because there
     * is no default.
     */
    private function createLeadFromProspect(array $p, $approverId, $now)
    {
        $leads = db_prefix() . 'leads';

        /*
         * Last line of defence.
         *
         * The approval path already refuses a simulated prospect at submission,
         * but this is the method that writes to the CRM's own table, and a
         * fixture arriving here would become a lead that nothing afterwards
         * marks as invented. Two checks for one rule is the right number when
         * the second one is the last one.
         */
        if (!empty($p['is_simulated'])) { return 0; }

        $row = array(
            'name'        => (string) $p['business_name'],
            'company'     => (string) $p['business_name'],
            'title'       => (string) (isset($p['verified_designation']) ? $p['verified_designation'] : ''),
            'phonenumber' => (string) (isset($p['phone_e164']) ? $p['phone_e164'] : ''),
            'email'       => (string) (isset($p['verified_email']) ? $p['verified_email'] : ''),
            'website'     => (string) (isset($p['website']) ? $p['website'] : ''),
            'address'     => (string) (isset($p['address']) ? $p['address'] : ''),
            'city'        => (string) (isset($p['city']) ? $p['city'] : ''),
            'state'       => (string) (isset($p['state']) ? $p['state'] : ''),
            'zip'         => (string) (isset($p['pin_code']) ? $p['pin_code'] : ''),
            'description' => trim('Requirement: ' . (string) (isset($p['requirement']) ? $p['requirement'] : '')
                             . "\n" . (string) (isset($p['verification_notes']) ? $p['verification_notes'] : '')),
            'assigned'    => (int) $p['claimed_by'],
            'addedfrom'   => (int) $approverId,
            'dateadded'   => date('Y-m-d H:i:s', (int) $now),
            'lastcontact' => date('Y-m-d H:i:s', (int) $now),
            'hash'        => function_exists('app_generate_hash') ? app_generate_hash() : sha1(uniqid('lf', true)),
        );

        $statusId = $this->configInt('conversion_lead_status_id', 0);
        if ($statusId > 0) { $row['status'] = $statusId; }

        $sourceId = $this->configInt('conversion_lead_source_id', 0);
        if ($sourceId > 0) { $row['source'] = $sourceId; }

        $this->db->insert($leads, $row);

        return (int) $this->db->insert_id();
    }

    /** The conversion record for one prospect, or null. */
    public function conversionFor($prospectId)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_conversions'))) { return null; }

        $r = $this->db->where('prospect_id', (int) $prospectId)
                      ->get($this->t('payplex_lf_conversions'))->row_array();

        return $r ? $r : null;
    }

    /** Everything awaiting an approver, oldest first — the queue they work. */
    public function pendingConversions($limit = 100)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_conversions'))) { return array(); }

        return $this->db->query(
            "SELECT c.*, p.business_name, p.city, p.phone_e164, p.requirement, p.verified_by
               FROM `" . $this->t('payplex_lf_conversions') . "` c
               JOIN `" . $this->t('payplex_lf_prospects') . "` p ON p.id = c.prospect_id
              WHERE c.state = ?
              ORDER BY c.submitted_at ASC
              LIMIT " . max(1, (int) $limit),
            array(Leadfinder_verification::C_PENDING)
        )->result_array();
    }

    /* ================================================================
     * Reports and monitoring
     * ============================================================== */

    /**
     * Run one report.
     *
     * Every query is parameterised and every one is bounded by a row limit.
     * A report is a read, but an unbounded read over a queue that has grown for
     * a year is a page that times out and takes the database with it — and the
     * person who runs it is an administrator looking at a monitoring screen,
     * which is exactly when nothing else should be falling over.
     *
     * @return array ok, columns, rows, note
     */
    public function report($id, array $filters = array(), $limit = 1000)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_reports.php';

        if (!Leadfinder_reports::exists($id)) {
            return array('ok' => false, 'reason' => 'unknown_report',
                         'rows' => array(), 'columns' => array());
        }

        $f = Leadfinder_reports::normaliseFilters($filters);

        if (!empty($f['errors'])) {
            return array('ok' => false, 'reason' => 'bad_filters', 'errors' => $f['errors'],
                         'rows' => array(), 'columns' => array());
        }

        $need = $this->reportTables($id);

        if ($need && !$this->haveTables($need)) {
            return array('ok' => false, 'reason' => 'not_installed',
                         'rows' => array(), 'columns' => array(),
                         'errors' => array('schema' => 'this report needs a migration that has '
                                                     . 'not been applied on this database yet'));
        }

        $limit = max(1, min(5000, (int) $limit));
        $rows  = $this->reportRows($id, $f, $limit);

        return array('ok' => true, 'reason' => 'ran', 'rows' => $rows,
                     'columns' => $rows ? array_keys($rows[0]) : array(),
                     'filters' => $f);
    }

    /**
     * The queries. One `case` per report id, all parameterised.
     *
     * `$f['from']` and `$f['to']` are calendar dates already validated with
     * `checkdate()`. They are converted to epochs here because every timestamp
     * column in this module is a UTC BIGINT — the CRM runs Asia/Kolkata and the
     * database session runs UTC−7, so comparing a DATE against those columns
     * directly would move every boundary by twelve and a half hours.
     */
    private function reportRows($id, array $f, $limit)
    {
        $p  = $this->t('payplex_lf_prospects');
        $s  = $this->t('payplex_lf_searches');
        $u  = $this->t('payplex_lf_usage');
        $a  = $this->t('payplex_lf_audit');
        $c  = $this->t('payplex_lf_conversions');
        $o  = $this->t('payplex_lf_ownership');
        $df = $this->t('payplex_lf_detail_fetches');

        $fromTs = $f['from'] !== null ? strtotime($f['from'] . ' 00:00:00 UTC') : 0;
        $toTs   = $f['to'] !== null ? strtotime($f['to'] . ' 23:59:59 UTC') : 2147483647;
        $staff  = (int) $f['staff_id'];

        /* Applied to every query that has a staff column. */
        $staffSql    = $staff > 0 ? ' AND staff_id = ? ' : '';
        $staffParams = $staff > 0 ? array($staff) : array();

        switch ($id) {
            case 'searches_by_source_staff':
                return $this->q("SELECT staff_id, endpoint AS source, COUNT(*) AS searches,
                                        SUM(result_count) AS results, SUM(pages_fetched) AS pages,
                                        SUM(is_simulated) AS simulated
                                   FROM `{$s}` WHERE created_at BETWEEN ? AND ? {$staffSql}
                                  GROUP BY staff_id, endpoint ORDER BY searches DESC LIMIT {$limit}",
                                array_merge(array($fromTs, $toTs), $staffParams));

            case 'result_counts':
                return $this->q("SELECT id, staff_id, keyword, city, result_count, pages_fetched,
                                        attempts, is_simulated, created_at
                                   FROM `{$s}` WHERE created_at BETWEEN ? AND ? {$staffSql}
                                  ORDER BY created_at DESC LIMIT {$limit}",
                                array_merge(array($fromTs, $toTs), $staffParams));

            case 'unique_prospects':
                return $this->q("SELECT DATE(FROM_UNIXTIME(created_at)) AS day,
                                        COUNT(DISTINCT google_place_id) AS unique_prospects,
                                        SUM(is_simulated) AS simulated
                                   FROM `{$p}` WHERE created_at BETWEEN ? AND ?
                                  GROUP BY day ORDER BY day DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'duplicate_rate':
                return $this->q("SELECT dupe_state, COUNT(*) AS prospects,
                                        ROUND(100 * COUNT(*) / NULLIF((SELECT COUNT(*) FROM `{$p}`
                                          WHERE created_at BETWEEN ? AND ?), 0), 1) AS percent
                                   FROM `{$p}` WHERE created_at BETWEEN ? AND ?
                                  GROUP BY dupe_state ORDER BY prospects DESC LIMIT {$limit}",
                                array($fromTs, $toTs, $fromTs, $toTs));

            case 'api_usage':
                return $this->q("SELECT usage_date, request_class, profile_id,
                                        SUM(calls) AS calls
                                   FROM `{$u}` WHERE usage_date BETWEEN ? AND ?
                                  GROUP BY usage_date, request_class, profile_id
                                  ORDER BY usage_date DESC LIMIT {$limit}",
                                array($f['from'] !== null ? $f['from'] : '1970-01-01',
                                      $f['to'] !== null ? $f['to'] : '2999-12-31'));

            case 'quota_consumption':
                return $this->quotaConsumptionReport($limit);

            /*
             * `from_staff`, `to_staff`, `actor_id`, `at` — the ownership table's
             * real column names. The first draft of this query invented
             * `staff_id`, `to_staff_id` and `created_at` from the shape of the
             * other tables, which is the same mistake this project has made
             * twice before: writing a query against a schema remembered rather
             * than read. It returns "no rows" rather than an error, so it
             * would have shipped looking like an empty report.
             */
            case 'claims_and_releases':
                $ownerSql = $staff > 0 ? ' AND (from_staff = ? OR to_staff = ? OR actor_id = ?) ' : '';
                $ownerPrm = $staff > 0 ? array($staff, $staff, $staff) : array();

                return $this->q("SELECT event, from_staff, to_staff, actor_id, prospect_id,
                                        reason, at
                                   FROM `{$o}` WHERE at BETWEEN ? AND ? {$ownerSql}
                                  ORDER BY at DESC LIMIT {$limit}",
                                array_merge(array($fromTs, $toTs), $ownerPrm));

            case 'calls_and_callbacks':
                return $this->q("SELECT id, business_name, city, phone_e164, status,
                                        call_disposition, next_followup_at, verified_by, verified_at
                                   FROM `{$p}`
                                  WHERE (next_followup_at IS NOT NULL AND next_followup_at > 0)
                                     OR (verified_at BETWEEN ? AND ?)
                                  ORDER BY next_followup_at ASC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'verification_outcomes':
                return $this->q("SELECT call_disposition, COUNT(*) AS prospects
                                   FROM `{$p}` WHERE verified_at BETWEEN ? AND ?
                                    AND call_disposition IS NOT NULL
                                  GROUP BY call_disposition ORDER BY prospects DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'interest_split':
                return $this->q("SELECT interest_level, status, COUNT(*) AS prospects
                                   FROM `{$p}` WHERE verified_at BETWEEN ? AND ?
                                  GROUP BY interest_level, status ORDER BY prospects DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'invalid_outcomes':
                return $this->q("SELECT status, rejection_reason, COUNT(*) AS prospects
                                   FROM `{$p}`
                                  WHERE status IN (?, ?, ?, ?, ?)
                                    AND last_touch_at BETWEEN ? AND ?
                                  GROUP BY status, rejection_reason
                                  ORDER BY prospects DESC LIMIT {$limit}",
                                array(Leadfinder_status::WRONG_NUMBER, Leadfinder_status::REJECTED,
                                      Leadfinder_status::DO_NOT_CONTACT, Leadfinder_status::DUPLICATE,
                                      Leadfinder_status::IRRELEVANT, $fromTs, $toTs));

            case 'conversion_submissions':
                return $this->q("SELECT c.prospect_id, p.business_name, c.submitted_by,
                                        c.submitted_at, c.state
                                   FROM `{$c}` c JOIN `{$p}` p ON p.id = c.prospect_id
                                  WHERE c.submitted_at BETWEEN ? AND ?
                                  ORDER BY c.submitted_at DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'approvals_and_rejections':
                return $this->q("SELECT c.prospect_id, p.business_name, c.submitted_by, c.decided_by,
                                        c.state, c.decision_reason, c.decided_at, c.lead_id
                                   FROM `{$c}` c JOIN `{$p}` p ON p.id = c.prospect_id
                                  WHERE c.decided_at BETWEEN ? AND ? AND c.state <> 'pending'
                                  ORDER BY c.decided_at DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'conversion_rate_by_source':
                return $this->q("SELECT COALESCE(p.source_system, 'places_search') AS source,
                                        COUNT(*) AS prospects,
                                        SUM(CASE WHEN p.converted_lead_id IS NOT NULL THEN 1 ELSE 0 END) AS converted,
                                        ROUND(100 * SUM(CASE WHEN p.converted_lead_id IS NOT NULL THEN 1 ELSE 0 END)
                                              / NULLIF(COUNT(*), 0), 1) AS percent
                                   FROM `{$p}` p WHERE p.created_at BETWEEN ? AND ?
                                  GROUP BY source ORDER BY prospects DESC LIMIT {$limit}",
                                array($fromTs, $toTs));

            case 'lead_quality_by_employee':
                return $this->q("SELECT p.claimed_by AS staff_id, COUNT(*) AS worked,
                                        SUM(CASE WHEN p.status = ? THEN 1 ELSE 0 END) AS converted,
                                        SUM(CASE WHEN p.status IN (?, ?, ?) THEN 1 ELSE 0 END) AS invalid,
                                        ROUND(100 * SUM(CASE WHEN p.status = ? THEN 1 ELSE 0 END)
                                              / NULLIF(COUNT(*), 0), 1) AS conversion_percent
                                   FROM `{$p}` p
                                  WHERE p.claimed_by > 0 AND p.last_touch_at BETWEEN ? AND ?
                                  GROUP BY p.claimed_by ORDER BY worked DESC LIMIT {$limit}",
                                array(Leadfinder_status::CONVERTED_TO_LEAD,
                                      Leadfinder_status::WRONG_NUMBER, Leadfinder_status::WRONG_NUMBER,
                                      Leadfinder_status::IRRELEVANT,
                                      Leadfinder_status::CONVERTED_TO_LEAD, $fromTs, $toTs));

            case 'api_errors_and_retries':
                return $this->apiErrorsReport($fromTs, $toTs, $limit, $s, $df);

            case 'profile_expiry_and_rotation':
                return $this->profileExpiryReport($limit);

            case 'retention_runs':
                return $this->retentionRuns($limit);

            case 'access_refusals':
                return $this->q("SELECT actor_id, event, object_type, object_id, at
                                   FROM `{$a}`
                                  WHERE event IN ('prospect_claim_conflict','prospect_release_conflict',
                                                  'prospect_reassign_conflict',
                                                  'conversion_self_approval_refused',
                                                  'api_profile_key_cleared')
                                    AND at BETWEEN ? AND ?
                                  ORDER BY at DESC LIMIT {$limit}",
                                array($fromTs, $toTs));
        }

        return array();
    }

    /**
     * A parameterised read.
     *
     * The try/catch here is a backstop, NOT the guard. CodeIgniter halts the
     * request on a database error rather than throwing, so a missing table must
     * be excluded by `haveTables()` before the query is built — which is what
     * `report()` now does. Keeping the catch costs nothing and covers the
     * drivers that do throw.
     */
    private function q($sql, array $params)
    {
        try {
            return $this->db->query($sql, $params)->result_array();
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Which module tables each report needs.
     *
     * A report whose tables are not installed yet returns "not available on this
     * database" rather than an empty grid — an empty grid says "there is no
     * activity", which is a different and wrong answer.
     */
    private function reportTables($id)
    {
        $map = array(
            'searches_by_source_staff'    => array('payplex_lf_searches'),
            'result_counts'               => array('payplex_lf_searches'),
            'unique_prospects'            => array('payplex_lf_prospects'),
            'duplicate_rate'              => array('payplex_lf_prospects'),
            'api_usage'                   => array('payplex_lf_usage'),
            'quota_consumption'           => array('payplex_lf_quota_counters'),
            'claims_and_releases'         => array('payplex_lf_ownership'),
            'calls_and_callbacks'         => array('payplex_lf_prospects'),
            'verification_outcomes'       => array('payplex_lf_prospects'),
            'interest_split'              => array('payplex_lf_prospects'),
            'invalid_outcomes'            => array('payplex_lf_prospects'),
            'conversion_submissions'      => array('payplex_lf_conversions', 'payplex_lf_prospects'),
            'approvals_and_rejections'    => array('payplex_lf_conversions', 'payplex_lf_prospects'),
            'conversion_rate_by_source'   => array('payplex_lf_prospects'),
            'lead_quality_by_employee'    => array('payplex_lf_prospects'),
            'api_errors_and_retries'      => array('payplex_lf_searches', 'payplex_lf_detail_fetches'),
            'profile_expiry_and_rotation' => array('payplex_lf_api_profiles', 'payplex_lf_key_rotations'),
            'retention_runs'              => array('payplex_lf_retention_runs'),
            'access_refusals'             => array('payplex_lf_audit'),
        );

        return isset($map[$id]) ? $map[$id] : array();
    }

    private function quotaConsumptionReport($limit)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_quota_plan.php';
require_once __DIR__ . '/../libraries/Leadfinder_waste.php';
require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';
require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
require_once __DIR__ . '/../libraries/Leadfinder_ui.php';

        if (!$this->db->table_exists($this->t('payplex_lf_quota_counters'))) { return array(); }

        $rows  = $this->db->order_by('period_key', 'DESC')->limit(max(1, (int) $limit))
                          ->get($this->t('payplex_lf_quota_counters'))->result_array();
        $rates = $this->quotaRates();
        $cur   = $this->config('quota_currency', '');

        $out = array();

        foreach ($rows as $r) {
            $cost = Leadfinder_quota_plan::estimateMinorUnits(
                        $r['request_class'], (int) $r['used'], $rates);

            $out[] = array(
                'scope'         => $r['scope'],
                'scope_id'      => (int) $r['scope_id'],
                'period'        => $r['period_key'],
                'request_class' => $r['request_class'],
                'calls'         => (int) $r['used'],
                /*
                 * Null, not zero, when no rate is configured. A cost column full
                 * of zeros on a spending report reads as "this cost nothing".
                 */
                'estimated_cost' => $cost === null
                    ? 'rate not configured'
                    : trim($cur . ' ' . Leadfinder_quota_plan::formatMinorUnits($cost)),
            );
        }

        return $out;
    }

    private function apiErrorsReport($fromTs, $toTs, $limit, $searches, $detailFetches)
    {
        $a = $this->q("SELECT 'search' AS kind, profile_id, error_code, attempts,
                              COUNT(*) AS occurrences, MAX(created_at) AS last_seen
                         FROM `{$searches}`
                        WHERE error_code IS NOT NULL AND created_at BETWEEN ? AND ?
                        GROUP BY profile_id, error_code, attempts
                        ORDER BY occurrences DESC LIMIT {$limit}",
                      array($fromTs, $toTs));

        $b = $this->q("SELECT 'detail' AS kind, profile_id, error_code, attempts,
                              COUNT(*) AS occurrences, MAX(created_at) AS last_seen
                         FROM `{$detailFetches}`
                        WHERE outcome <> 'ok' AND created_at BETWEEN ? AND ?
                        GROUP BY profile_id, error_code, attempts
                        ORDER BY occurrences DESC LIMIT {$limit}",
                      array($fromTs, $toTs));

        return array_merge($a, $b);
    }

    private function profileExpiryReport($limit)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_reports.php';

        /*
         * `name`, not `label`.
         *
         * The first version of this select asked for `label`, which does not
         * exist on this table — the column is `name`, and `billing_label` is
         * something else again. CodeIgniter does not throw on an unknown column;
         * it halts the request, so the reports screen returned a bare 500 with
         * nothing in the error log. It is the third time in this project that a
         * query has been written against a schema remembered rather than read,
         * which is why ReportsMonitoringTest now pins this select list against
         * the columns the migrations actually declare.
         */
        $rows = $this->db->select('id, name, active, effective_from, expires_on, per_staff_daily_limit,
                                   monthly_search_limit, monthly_detail_limit, daily_usage_limit,
                                   last_test_at, last_test_result', false)
                         ->limit(max(1, (int) $limit))
                         ->get($this->t('payplex_lf_api_profiles'))->result_array();

        $today = $this->crmDate();
        $warn  = Leadfinder_reports::expiryWarningDays();

        foreach ($rows as $i => $r) {
            $expires = trim((string) $r['expires_on']);

            if ($expires === '' || $expires === '0000-00-00') {
                $rows[$i]['expiry_state'] = 'no expiry set';
            } elseif ($expires < $today) {
                $rows[$i]['expiry_state'] = 'EXPIRED';
            } elseif (strtotime($expires) - strtotime($today) <= $warn * 86400) {
                $rows[$i]['expiry_state'] = 'expires within ' . $warn . ' days';
            } else {
                $rows[$i]['expiry_state'] = 'current';
            }

            /* Count only. The fingerprints themselves never reach a report. */
            $rows[$i]['rotations'] = $this->haveTables(array('payplex_lf_key_rotations'))
                ? count($this->keyRotations((int) $r['id'], 100)) : 0;
        }

        return $rows;
    }

    /**
     * What an administrator should be told about right now.
     *
     * Computed on read rather than pushed, because a notification nobody
     * acknowledges is indistinguishable from one nobody sent. Each entry says
     * what is wrong and how to see it, and each maps to one of the kinds in
     * `Leadfinder_reports::alertKinds()` so the screen and this agree.
     */
    public function adminAlerts($now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_reports.php';
        require_once __DIR__ . '/../libraries/Leadfinder_retention_job.php';

        $now = $now === null ? time() : (int) $now;
        $out = array();

        /* 1. Quota bands crossed, newest first. */
        foreach ($this->quotaAlerts(20) as $a) {
            $out[] = array(
                'kind'    => 'quota_band',
                'level'   => (int) $a['band'] >= 100 ? 'critical' : 'warning',
                'message' => ucfirst($a['scope']) . ' ' . $a['request_class'] . ' usage passed '
                           . (int) $a['band'] . '% for ' . $a['period_key']
                           . ' (' . (int) $a['used_at_raise'] . ' of ' . (int) $a['limit_at_raise'] . ').',
                'at'      => (int) $a['raised_at'],
            );
        }

        /* 2. Retention: failed, or overdue. */
        $last = $this->retentionLastRunAt();
        $gap  = $this->configInt('retention_job_interval_seconds',
                                 Leadfinder_retention_job::DEFAULT_INTERVAL_SECONDS);

        if ($last === null) {
            $out[] = array('kind' => 'retention_failed', 'level' => 'critical', 'at' => $now,
                'message' => 'The coordinate retention sweep has never run. Google\'s Service '
                           . 'Specific Terms require cached coordinates to be deleted after 30 days.');
        } elseif ($now - (int) $last > max(3600, $gap) * 4) {
            $out[] = array('kind' => 'retention_failed', 'level' => 'warning', 'at' => (int) $last,
                'message' => 'The coordinate retention sweep has not run since '
                           . date('Y-m-d H:i', (int) $last) . '.');
        }

        foreach ($this->retentionRuns(5) as $r) {
            if ((string) $r['status'] !== 'ok') {
                $out[] = array('kind' => 'retention_failed', 'level' => 'critical',
                    'at' => (int) $r['started_at'],
                    'message' => 'A retention sweep failed on '
                               . date('Y-m-d H:i', (int) $r['started_at']) . '.');
                break;
            }
        }

        /* 3. Repeated API failures on one connection. */
        $streaks = $this->haveTables(array('payplex_lf_searches')) ? $this->q("SELECT profile_id, COUNT(*) AS failures
                               FROM `" . $this->t('payplex_lf_searches') . "`
                              WHERE error_code IS NOT NULL AND created_at >= ?
                              GROUP BY profile_id", array($now - 86400)) : array();

        $threshold = max(1, $this->configInt('api_error_alert_threshold', 5));

        foreach ($streaks as $st) {
            if (Leadfinder_reports::errorStreakAlerts((int) $st['failures'], $threshold)) {
                $out[] = array('kind' => 'api_error_streak', 'level' => 'warning', 'at' => $now,
                    'message' => 'API connection #' . (int) $st['profile_id'] . ' has failed '
                               . (int) $st['failures'] . ' times in the last 24 hours.');
            }
        }

        /* 4. Profiles expiring or expired. */
        foreach ($this->profileExpiryReport(50) as $pr) {
            if ($pr['expiry_state'] === 'EXPIRED' || strpos($pr['expiry_state'], 'expires within') === 0) {
                $out[] = array('kind' => 'profile_expiring',
                    'level' => $pr['expiry_state'] === 'EXPIRED' ? 'critical' : 'warning',
                    'at' => $now,
                    'message' => 'API connection "' . $pr['name'] . '" ' . $pr['expiry_state'] . '.');
            }
        }

        /* 5. Reservations held and never settled. */
        $stale = $this->haveTables(array('payplex_lf_reservations'))
            ? $this->q("SELECT COUNT(*) AS c FROM `" . $this->t('payplex_lf_reservations') . "`
                         WHERE state = 'held' AND created_at <= ?",
                       array($now - max(60, $this->configInt('reservation_stale_seconds', 900))))
            : array();

        if ($stale && (int) $stale[0]['c'] > 0) {
            $out[] = array('kind' => 'stale_reservations', 'level' => 'warning', 'at' => $now,
                'message' => (int) $stale[0]['c'] . ' quota reservations are held and unsettled. '
                           . 'The cron sweep releases them; if this number keeps growing, the cron '
                           . 'is not running.');
        }

        return $out;
    }

    /** One row per export, so a download can be traced to a person. */
    public function recordExport($reportId, $actorId, $rowCount, $now = null)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_report_exports'))) { return; }

        try {
            $this->db->insert($this->t('payplex_lf_report_exports'), array(
                'report_id'  => substr((string) $reportId, 0, 60),
                'staff_id'   => (int) $actorId,
                'row_count'  => (int) $rowCount,
                'created_at' => $now === null ? time() : (int) $now,
            ));
        } catch (Exception $e) {
            /* Never fail an export because its audit row could not be written —
               but never skip trying, either. */
        }
    }

    public function audit($actorId, $event, $objectType, $objectId, array $detail)
    {
        if (!$this->db->table_exists($this->t('payplex_lf_audit'))) { return; }
        $json = Leadfinder_secret::scrub(json_encode($detail));
        $ci   = &get_instance();
        $this->db->insert($this->t('payplex_lf_audit'), array(
            'actor_id'    => (int) $actorId,
            'event'       => (string) $event,
            'object_type' => (string) $objectType,
            'object_id'   => (int) $objectId,
            'detail'      => $json,
            'ip'          => method_exists($ci->input, 'ip_address') ? $ci->input->ip_address() : null,
            'at'          => time(),
        ));
    }

    public function auditLog($limit = 200)
    {
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)
                        ->get($this->t('payplex_lf_audit'))->result_array();
    }

    /* ================================================================
     * Phase 4 — Save, Waste, DNC, Undo, Purge, Re-key
     *
     * Every method here either does its work or returns a named failure, and
     * every one of them is reachable from a controller action that has already
     * checked a capability and scoped the row. The checks are repeated here
     * anyway: the controller decides whether the button may be pressed, this
     * file decides whether the row may be changed, and a bulk action loops
     * through this file rather than around it.
     * ============================================================== */

    /** Config the waste and suppression paths read. Blank means "not stated". */
    public function wasteConfig()
    {
        return array(
            'undo_window_seconds' => $this->configInt('waste_undo_window_seconds', 0),
            'suppression_days'    => $this->configInt('waste_suppression_days', 0),
            'pii_retention_days'  => $this->configInt('waste_pii_retention_days', 0),
            'purge_batch_size'    => $this->configInt('purge_batch_size', 200),
            'purge_lock_ttl'      => $this->configInt('purge_job_lock_ttl_seconds', 900),
            'bulk_max'            => $this->configInt('waste_bulk_max', Leadfinder_ui::BULK_MAX),
            'save_bulk_max'       => $this->configInt('save_bulk_max', Leadfinder_ui::BULK_MAX),
        );
    }

    /**
     * The keyring, reported without any part of the key in it.
     *
     * Four fields and a reason. Deliberately not "the keyring array", because
     * the only caller is a screen and a screen has no business holding pepper
     * material even for the length of a render.
     */
    public function keyringHealth()
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';

        return Leadfinder_keyring_loader::health();
    }

    /* ---------------------------------------------------------------- */
    /* Save                                                              */
    /* ---------------------------------------------------------------- */

    /**
     * Save one prospect to the actor's shortlist.
     *
     * Idempotent by construction: the unique pair key decides, and saving twice
     * is success rather than an error. A list button that fails on a double
     * click trains people to click it twice.
     *
     * This writes to the module's own shortlist table. It does not write to
     * `tblleads`, and it cannot: the only INSERT into that table in this module
     * is the approved-conversion path, which requires a verification record, a
     * submission and a second person's approval.
     */
    public function saveProspect($prospectId, $actorId, $now = null, $note = '')
    {
        $now        = $now === null ? time() : (int) $now;
        $prospectId = (int) $prospectId;
        $actorId    = (int) $actorId;

        if ($prospectId <= 0 || $actorId <= 0) {
            return array('ok' => false, 'reason' => 'bad_request', 'saved' => false);
        }

        $p = $this->prospect($prospectId);

        if (!$p) {
            return array('ok' => false, 'reason' => 'not_found', 'saved' => false);
        }

        /*
         * A wasted prospect cannot be shortlisted. Otherwise an employee saves
         * a row from a stale list, the purge clears its contact details an hour
         * later, and their shortlist quietly fills with hollow records.
         */
        if (!empty($p['wasted_at']) && empty($p['undone_at'])) {
            return array('ok' => false, 'reason' => 'prospect_is_waste', 'saved' => false);
        }

        $table = $this->t('payplex_lf_saved_prospects');
        $note  = mb_substr(trim((string) $note), 0, 255);

        $this->db->query(
            "INSERT IGNORE INTO `{$table}` (prospect_id, staff_id, saved_at, note) VALUES (?,?,?,?)",
            array($prospectId, $actorId, $now, $note === '' ? null : $note)
        );

        $inserted = (int) $this->db->affected_rows() > 0;

        if ($inserted) {
            $this->audit($actorId, 'prospect_saved', 'prospect', $prospectId, array(
                'source' => isset($p['search_id']) ? 'search' : 'queue',
            ));
        }

        return array('ok' => true, 'reason' => $inserted ? 'saved' : 'already_saved', 'saved' => true);
    }

    /**
     * Save many. Each id is authorised individually, in scope, one at a time.
     *
     * The cap is applied before the loop, not inside it, so the refusal is
     * "you asked for too many" rather than a partial success nobody can tell
     * from a full one.
     */
    public function saveProspects(array $ids, $actorId, $band, array $teamIds, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $cfg = $this->wasteConfig();
        $max = $cfg['save_bulk_max'] > 0 ? $cfg['save_bulk_max'] : Leadfinder_ui::BULK_MAX;

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) > $max) {
            return array('ok' => false, 'reason' => 'too_many',
                         'results' => array('done' => 0, 'refused' => count($ids), 'detail' => array()),
                         'max' => $max);
        }

        $results = array('done' => 0, 'refused' => 0, 'detail' => array());

        foreach ($ids as $id) {
            if ($id <= 0) { continue; }

            if (!$this->prospectInScope($id, (int) $actorId, $band, $teamIds)) {
                $results['refused']++;
                $results['detail'][$id] = 'not_in_your_scope';
                continue;
            }

            $r = $this->saveProspect($id, $actorId, $now);

            if (!empty($r['saved'])) { $results['done']++; }
            else                     { $results['refused']++; }

            $results['detail'][$id] = $r['reason'];
        }

        $this->audit((int) $actorId, 'prospect_bulk_save', 'prospect', 0, array(
            'requested' => count($ids), 'done' => $results['done'], 'refused' => $results['refused']));

        return array('ok' => true, 'reason' => 'completed', 'results' => $results, 'max' => $max);
    }

    /** Remove one prospect from the actor's own shortlist. Never anybody else's. */
    public function unsaveProspect($prospectId, $actorId)
    {
        $prospectId = (int) $prospectId;
        $actorId    = (int) $actorId;

        if ($prospectId <= 0 || $actorId <= 0) {
            return array('ok' => false, 'reason' => 'bad_request');
        }

        $this->db->where('prospect_id', $prospectId)->where('staff_id', $actorId)
                 ->delete($this->t('payplex_lf_saved_prospects'));

        $removed = (int) $this->db->affected_rows() > 0;

        if ($removed) {
            $this->audit($actorId, 'prospect_unsaved', 'prospect', $prospectId, array());
        }

        return array('ok' => true, 'reason' => $removed ? 'removed' : 'was_not_saved');
    }

    /**
     * Which of these prospects the actor has saved.
     *
     * Scoped to one staff member on purpose: the shortlist is per-employee, and
     * a query that returned everybody's would let the list screen show one
     * employee which rows another had marked.
     */
    public function savedProspectIds($actorId, array $prospectIds = array())
    {
        $actorId = (int) $actorId;

        if ($actorId <= 0) { return array(); }

        $this->db->select('prospect_id')->where('staff_id', $actorId);

        $ids = array_values(array_filter(array_map('intval', $prospectIds)));

        if ($ids) { $this->db->where_in('prospect_id', $ids); }

        $rows = $this->db->get($this->t('payplex_lf_saved_prospects'))->result_array();

        $out = array();
        foreach ($rows as $r) { $out[(int) $r['prospect_id']] = true; }

        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Waste                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Mark a prospect as waste, with a stated reason.
     *
     * ORDER OF OPERATIONS
     * -------------------
     * 1. validate the reason and notes  (refuses `other` with no explanation)
     * 2. check the actor may act on this row
     * 3. write the tombstone             (refused outright with no keyring)
     * 4. mark the prospect, in the same transaction
     * 5. queue the PII purge for after the undo window
     *
     * Step 3 before step 4 is the whole design. If the prospect were marked and
     * purged first, and the tombstone written afterwards, a failure in between
     * would destroy the identifiers the tombstone needed and leave a business
     * that is neither in the queue nor suppressed — it would return in the next
     * search, for ever, and nothing would report it.
     *
     * @param  array $opt kind, notes, source, rule, is_admin, now
     */
    public function markWaste($prospectId, $actorId, $reason, array $opt = array())
    {
        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $now        = isset($opt['now']) ? (int) $opt['now'] : time();
        $prospectId = (int) $prospectId;
        $actorId    = (int) $actorId;
        $notes      = isset($opt['notes']) ? (string) $opt['notes'] : '';
        $kind       = isset($opt['kind']) ? (string) $opt['kind'] : Leadfinder_tombstone::S_WASTE;
        $source     = isset($opt['source']) ? (string) $opt['source'] : 'manual';
        $rule       = isset($opt['rule']) ? (string) $opt['rule'] : '';
        $isAdmin    = !empty($opt['is_admin']);

        $v = Leadfinder_waste::validate($reason, $notes);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => 'invalid_reason', 'message' => $v['error']);
        }

        if (!in_array($kind, array_keys(Leadfinder_tombstone::suppressionKinds()), true)) {
            return array('ok' => false, 'reason' => 'unknown_suppression_kind',
                         'message' => 'That suppression kind is not recognised. Nothing was changed.');
        }

        $p = $this->prospect($prospectId);

        if (!$p) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect no longer exists.');
        }

        if (!empty($p['wasted_at']) && empty($p['undone_at'])) {
            return array('ok' => false, 'reason' => 'already_waste',
                         'message' => 'That prospect is already marked as waste.');
        }

        if (!empty($p['converted_lead_id'])) {
            return array('ok' => false, 'reason' => 'already_converted',
                         'message' => 'That prospect has already been converted to a lead and cannot be '
                                    . 'marked as waste. Close the lead instead.');
        }

        /*
         * Ownership. Holding `leadfinder_verify` says an employee may reject
         * prospects; it does not say they may reject somebody else's. An
         * unclaimed row in the shared pool may be wasted by anyone who can see
         * it — that is the pool working as intended.
         */
        $claimed = (int) $p['claimed_by'];

        if ($claimed > 0 && $claimed !== $actorId && !$isAdmin) {
            return array('ok' => false, 'reason' => 'not_owner',
                         'message' => 'Another employee is working this prospect. Ask them to release it '
                                    . 'first, or ask a manager to reassign it.');
        }

        $tomb = $this->writeTombstone($p, $kind, $reason, $actorId, $now);

        if (empty($tomb['ok'])) {
            return array('ok' => false, 'reason' => $tomb['reason'], 'message' => $tomb['message']);
        }

        $window  = Leadfinder_waste::undoWindowSeconds($this->configInt('waste_undo_window_seconds', 0));
        $undoTil = $kind === Leadfinder_tombstone::S_DNC ? null : ($now + $window);
        $status  = Leadfinder_waste::statusFor($reason);
        $table   = $this->t('payplex_lf_prospects');

        /*
         * Conditional on the row still being un-wasted. Two people pressing the
         * button on the same prospect at the same moment must produce one waste
         * decision and one honest refusal, not two decisions and two tombstones.
         */
        $this->db->query(
            "UPDATE `{$table}`
                SET waste_reason = ?, waste_notes = ?, waste_source = ?, waste_rule = ?,
                    suppression_kind = ?, wasted_at = ?, wasted_by = ?, undo_deadline = ?,
                    undone_at = NULL, undone_by = 0, tombstone_id = ?,
                    status = ?, claimed_by = 0, claimed_at = NULL,
                    next_followup_at = NULL, last_touch_at = ?
              WHERE id = ? AND wasted_at IS NULL",
            array($reason, $notes === '' ? null : $notes, $source, $rule === '' ? null : $rule,
                  $kind, $now, $actorId, $undoTil, (int) $tomb['id'],
                  $status !== null ? $status : Leadfinder_status::REJECTED, $now,
                  $prospectId)
        );

        if ((int) $this->db->affected_rows() === 0) {
            return array('ok' => false, 'reason' => 'changed_since_you_looked',
                         'message' => 'That prospect changed while you were looking at it. '
                                    . 'Nothing was changed — reload and try again.');
        }

        /*
         * The purge is queued, not performed. Due after the undo window for
         * waste; immediately for DNC, which has no undo — the promise not to
         * contact them is not something to take back by clicking the wrong row
         * twice.
         */
        $this->queuePurge($prospectId, (int) $tomb['id'],
                          $undoTil === null ? $now : $undoTil, $now);

        /*
         * The notes are scrubbed on the way into the audit. The audit row
         * OUTLIVES the purge by design — it is the proof of who rejected this
         * business and why — so an operator who pasted a phone number into the
         * notes would otherwise leave it in the one place the purge never
         * clears. The reason code and the author survive intact, which is what
         * "prove who and why" actually requires.
         */
        $this->audit($actorId, 'prospect_marked_waste', 'prospect', $prospectId,
            Leadfinder_waste::auditEvent(array(
                'prospect_id' => $prospectId, 'actor_id' => $actorId,
                'reason' => $reason, 'notes' => $this->scrubContactDetails($notes),
                'undo_until' => (int) $undoTil, 'at' => gmdate('c', $now))));

        return array('ok' => true, 'reason' => 'wasted', 'tombstone_id' => (int) $tomb['id'],
                     'undo_until' => $undoTil, 'kind' => $kind,
                     'message' => $kind === Leadfinder_tombstone::S_DNC
                         ? 'Recorded as Do Not Contact. This suppression is permanent and will not expire.'
                         : 'Marked as waste. You can undo this for the next '
                           . max(1, (int) round($window / 60)) . ' minutes.');
    }

    /**
     * Take a waste decision back, inside its window.
     *
     * Refused once the PII has been purged, because there is nothing left to
     * restore and returning a hollow record would be worse than refusing.
     */
    public function undoWaste($prospectId, $actorId, $isAdmin = false, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $now        = $now === null ? time() : (int) $now;
        $prospectId = (int) $prospectId;
        $actorId    = (int) $actorId;

        $p = $this->prospect($prospectId);

        if (!$p) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect no longer exists.');
        }

        if (empty($p['wasted_at']) || !empty($p['undone_at'])) {
            return array('ok' => false, 'reason' => 'not_waste',
                         'message' => 'That prospect is not currently marked as waste.');
        }

        if ((string) $p['suppression_kind'] === Leadfinder_tombstone::S_DNC) {
            return array('ok' => false, 'reason' => 'dnc_is_not_undoable',
                         'message' => 'A Do Not Contact record cannot be undone here. The business asked '
                                    . 'not to be contacted, and reversing that needs a deliberate, '
                                    . 'recorded decision by an administrator.');
        }

        if (!Leadfinder_waste::undoActorAllowed($actorId, (int) $p['wasted_by'], $isAdmin === true)) {
            return array('ok' => false, 'reason' => 'not_your_decision',
                         'message' => 'Only the person who marked this as waste, or an administrator, '
                                    . 'can undo it.');
        }

        $u = Leadfinder_waste::mayUndo((int) $p['wasted_at'], $now,
                                       $this->configInt('waste_undo_window_seconds', 0),
                                       !empty($p['pii_purged_at']));

        if (empty($u['allowed'])) {
            return array('ok' => false, 'reason' => $u['reason'],
                         'message' => $u['reason'] === 'already_purged'
                             ? 'The contact details for this prospect have already been cleared, so there '
                               . 'is nothing to restore. It stays suppressed.'
                             : 'The undo window for this decision has passed.');
        }

        $table = $this->t('payplex_lf_prospects');

        $this->db->trans_begin();

        /* The purge queue entry goes first. If the tombstone delete succeeded
           and this did not, the sweep would clear the PII of a restored row. */
        $this->db->where('prospect_id', $prospectId)
                 ->where('state', Leadfinder_tombstone::P_PENDING)
                 ->delete($this->t('payplex_lf_purge_queue'));

        /*
         * The tombstone is removed too, because the decision it recorded has
         * been withdrawn. `mayDeleteTombstone` is consulted rather than
         * bypassed even here: if the row is DNC it refuses, and that refusal
         * must hold on every path, including this one.
         */
        if ((int) $p['tombstone_id'] > 0) {
            $t = $this->db->where('id', (int) $p['tombstone_id'])
                          ->get($this->t('payplex_lf_suppressions'))->row_array();

            if ($t) {
                $may = Leadfinder_tombstone::mayDeleteTombstone($t, $now, 0);

                if (empty($may['deletable']) && $t['suppression_kind'] === Leadfinder_tombstone::S_DNC) {
                    $this->db->trans_rollback();

                    return array('ok' => false, 'reason' => 'dnc_never_deleted',
                                 'message' => 'This prospect carries a Do Not Contact record, which is '
                                            . 'never removed. Nothing was changed.');
                }

                /* An undo inside the window deletes the tombstone it just wrote.
                   This is the one case where removing a waste tombstone is
                   correct: the decision never stood long enough to mean
                   anything, and leaving it would suppress a business nobody
                   decided to reject. */
                $this->db->where('id', (int) $p['tombstone_id'])
                         ->where('suppression_kind', Leadfinder_tombstone::S_WASTE)
                         ->where('purge_state', Leadfinder_tombstone::P_PENDING)
                         ->delete($this->t('payplex_lf_suppressions'));
            }
        }

        $this->db->query(
            "UPDATE `{$table}`
                SET undone_at = ?, undone_by = ?, status = ?, tombstone_id = NULL,
                    undo_deadline = NULL, last_touch_at = ?
              WHERE id = ? AND wasted_at IS NOT NULL AND undone_at IS NULL",
            array($now, $actorId, Leadfinder_status::NEW_RESULT, $now, $prospectId)
        );

        if ((int) $this->db->affected_rows() === 0) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'changed_since_you_looked',
                         'message' => 'That prospect changed while you were looking at it. '
                                    . 'Nothing was changed.');
        }

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'transaction_failed',
                         'message' => 'The undo could not be completed. Nothing was changed.');
        }

        $this->db->trans_commit();

        $this->audit($actorId, 'prospect_waste_undone', 'prospect', $prospectId, array(
            'seconds_left_at_undo' => (int) $u['seconds_left'],
            'original_reason'      => (string) $p['waste_reason'],
            'by_admin'             => $isAdmin === true,
        ));

        return array('ok' => true, 'reason' => 'restored',
                     'message' => 'Waste decision undone. The prospect is back in the queue.');
    }

    /**
     * Record a Do Not Contact request.
     *
     * This is a waste decision in mechanism and a different thing in policy: it
     * never expires, its tombstone is never deleted, and it carries a
     * provenance row saying who asked, when and through what channel.
     *
     * The evidence note is scrubbed of anything phone- or email-shaped before it
     * is stored. The register outlives the purge, so a free-text field on it is
     * a retention hole unless something actively keeps contact details out.
     */
    public function markDnc($prospectId, $actorId, array $d, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';
        require_once __DIR__ . '/../libraries/Leadfinder_waste.php';

        $now     = $now === null ? time() : (int) $now;
        $channel = isset($d['channel']) ? (string) $d['channel'] : '';
        $when    = isset($d['requested_on']) ? (string) $d['requested_on'] : '';

        if (!in_array($channel, $this->dncChannels(), true)) {
            return array('ok' => false, 'reason' => 'unknown_channel',
                         'message' => 'Say how the request reached us. Nothing was changed.');
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $when)
            || !checkdate((int) substr($when, 5, 2), (int) substr($when, 8, 2), (int) substr($when, 0, 4))) {
            return array('ok' => false, 'reason' => 'bad_date',
                         'message' => 'Give the date the business asked, as YYYY-MM-DD.');
        }

        if (strtotime($when . ' 00:00:00 UTC') > $now) {
            return array('ok' => false, 'reason' => 'future_date',
                         'message' => 'The request date cannot be in the future.');
        }

        $notes = isset($d['evidence_note']) ? (string) $d['evidence_note'] : '';

        $r = $this->markWaste($prospectId, $actorId, Leadfinder_waste::R_NOT_INTERESTED, array(
            'kind'     => Leadfinder_tombstone::S_DNC,
            'notes'    => $notes,
            'source'   => 'manual',
            'now'      => $now,
            'is_admin' => !empty($d['is_admin']),
        ));

        if (empty($r['ok'])) { return $r; }

        $p = $this->prospect((int) $prospectId);

        $this->db->insert($this->t('payplex_lf_dnc_register'), array(
            'suppression_id'     => (int) $r['tombstone_id'],
            'channel'            => $channel,
            'requested_on'       => $when,
            'recorded_by'        => (int) $actorId,
            'recorded_at'        => $now,
            'evidence_note'      => $this->scrubContactDetails($notes),
            'pepper_version'     => (string) $this->tombstoneVersion((int) $r['tombstone_id']),
            'business_name_hint' => Leadfinder_tombstone::nameHint($p ? (string) $p['business_name'] : ''),
        ));

        $this->audit((int) $actorId, 'prospect_marked_dnc', 'prospect', (int) $prospectId, array(
            'channel' => $channel, 'requested_on' => $when,
            'suppression_id' => (int) $r['tombstone_id'],
        ));

        $r['message'] = 'Recorded as Do Not Contact. This is permanent: the suppression does not expire '
                      . 'and survives the purge.';

        return $r;
    }

    /** How a Do Not Contact request may have reached us. */
    public function dncChannels()
    {
        return array('phone_call', 'email', 'letter', 'in_person', 'regulator', 'other');
    }

    /**
     * Remove anything phone- or email-shaped from free text.
     *
     * Not a promise that the text is now PII-free — a name is not a pattern —
     * but the two identifiers this module suppresses on are the two that must
     * not survive in a register designed to outlive the purge.
     */
    public function scrubContactDetails($text)
    {
        $text = is_string($text) ? $text : '';
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/u', '[email removed]', $text);
        $text = preg_replace('/(?:\+?\d[\d\s().-]{7,}\d)/u', '[number removed]', $text);

        return mb_substr(trim((string) $text), 0, 500);
    }

    /** The pepper version a stored tombstone was written under. */
    private function tombstoneVersion($suppressionId)
    {
        $r = $this->db->select('pepper_version')->where('id', (int) $suppressionId)
                      ->get($this->t('payplex_lf_suppressions'))->row_array();

        return $r ? (string) $r['pepper_version'] : '';
    }

    /* ---------------------------------------------------------------- */
    /* Tombstones                                                        */
    /* ---------------------------------------------------------------- */

    /**
     * Write the suppression tombstone for one prospect.
     *
     * REFUSED WITHOUT A KEYRING. There is no fallback to a plain digest: a
     * plain SHA-256 of an Indian mobile is recovered by exhaustive search in
     * minutes, so a fallback would write a tombstone that claims to have
     * protected the number while still, in effect, holding it — and it would do
     * it silently, which is the part that makes it dangerous.
     *
     * An existing tombstone for the same business, kind and version is reused
     * rather than duplicated: the unique keys make that the database's decision
     * instead of a race between two requests.
     */
    private function writeTombstone(array $p, $kind, $reason, $actorId, $now)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
        require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $gate = Leadfinder_keyring_loader::purgeAllowed();

        if (empty($gate['allowed'])) {
            $this->raiseKeyringAlert($gate['alert'], $now);

            return array('ok' => false, 'reason' => 'keyring_unusable',
                         'message' => 'The suppression keyring cannot be read (' . $gate['reason'] . '). '
                                    . 'Nothing was marked and no contact details were cleared. '
                                    . 'An administrator has been alerted.');
        }

        $ring    = Leadfinder_keyring_loader::load();
        $version = Leadfinder_keyring::activeVersion($ring);

        $ids = array();
        if (!empty($p['google_place_id'])) {
            $ids[Leadfinder_keyring::D_PLACE]  = (string) $p['google_place_id'];
            $ids[Leadfinder_keyring::D_SOURCE] = array('google_places', (string) $p['google_place_id']);
        }
        if (!empty($p['phone_e164'])) { $ids[Leadfinder_keyring::D_PHONE] = (string) $p['phone_e164']; }
        if (!empty($p['verified_email'])) { $ids[Leadfinder_keyring::D_EMAIL] = (string) $p['verified_email']; }

        if (!$ids) {
            return array('ok' => false, 'reason' => 'nothing_to_key',
                         'message' => 'This prospect holds no identifier that could be suppressed, so a '
                                    . 'tombstone would recognise nothing. Nothing was changed.');
        }

        $keys = Leadfinder_tombstone::candidateKeys($ring, $ids);
        $set  = isset($keys[$version]) ? $keys[$version] : array();

        if (!$set) {
            return array('ok' => false, 'reason' => 'derivation_failed',
                         'message' => 'The suppression keys could not be derived. Nothing was changed.');
        }

        $row = array(
            'suppression_kind'   => $kind,
            'source_type'        => 'google_places',
            'pepper_version'     => $version,
            'rekey_state'        => Leadfinder_keyring::RK_NONE,
            'source_ref_key'     => isset($set[Leadfinder_keyring::D_SOURCE]) ? $set[Leadfinder_keyring::D_SOURCE] : null,
            'place_id_key'       => isset($set[Leadfinder_keyring::D_PLACE]) ? $set[Leadfinder_keyring::D_PLACE] : null,
            'phone_key'          => isset($set[Leadfinder_keyring::D_PHONE]) ? $set[Leadfinder_keyring::D_PHONE] : null,
            'email_key'          => isset($set[Leadfinder_keyring::D_EMAIL]) ? $set[Leadfinder_keyring::D_EMAIL] : null,
            'business_name_hint' => Leadfinder_tombstone::nameHint((string) $p['business_name']),
            'city'               => isset($p['city']) ? $p['city'] : null,
            'state'              => isset($p['state']) ? $p['state'] : null,
            'waste_reason'       => (string) $reason,
            'decided_at'         => (int) $now,
            'decided_by'         => (int) $actorId,
            'purge_state'        => Leadfinder_tombstone::P_PENDING,
            'purged_at'          => null,
            'prospect_id'        => (int) $p['id'],
            'created_at'         => (int) $now,
        );

        $table = $this->t('payplex_lf_suppressions');
        $cols  = array_keys($row);
        $ph    = implode(',', array_fill(0, count($cols), '?'));

        /* INSERT IGNORE, then read back: the unique keys decide, exactly as they
           do for the prospect insert and the quota reservation. */
        $this->db->query("INSERT IGNORE INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$ph})",
                         array_values($row));

        if ((int) $this->db->affected_rows() > 0) {
            return array('ok' => true, 'id' => (int) $this->db->insert_id(), 'reused' => false);
        }

        $found = $this->db->where('pepper_version', $version)
                          ->where('suppression_kind', $kind)
                          ->where('place_id_key', $row['place_id_key'])
                          ->get($table)->row_array();

        if ($found) {
            return array('ok' => true, 'id' => (int) $found['id'], 'reused' => true);
        }

        return array('ok' => false, 'reason' => 'tombstone_not_written',
                     'message' => 'The suppression record could not be written, so nothing was marked. '
                                . 'No contact details were cleared.');
    }

    /** Put a prospect in the purge queue. Idempotent: one row per prospect. */
    private function queuePurge($prospectId, $suppressionId, $dueAt, $now)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $table = $this->t('payplex_lf_purge_queue');

        $this->db->query(
            "INSERT INTO `{$table}` (prospect_id, suppression_id, due_at, state, attempts, queued_at)
             VALUES (?,?,?,?,0,?)
             ON DUPLICATE KEY UPDATE suppression_id = VALUES(suppression_id),
                                     due_at = VALUES(due_at),
                                     state = VALUES(state)",
            array((int) $prospectId, (int) $suppressionId, (int) $dueAt,
                  Leadfinder_tombstone::P_PENDING, (int) $now)
        );

        return (int) $this->db->affected_rows() > 0;
    }

    /**
     * Is this incoming search result suppressed?
     *
     * Called for every result before it is offered. Returns the tombstone's own
     * verdict: an exact place-id or source-ref match suppresses; a phone or
     * email match is reported as possible and shown to the employee rather than
     * acted on, because one switchboard serves many real businesses.
     */
    public function suppressionFor(array $result)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
        require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $ring = Leadfinder_keyring_loader::load();

        if ($ring === null) {
            /* Reading is not writing. With no keyring the module cannot check
               suppression, and it says so rather than reporting "not
               suppressed", which a caller would act on. */
            return array('suppress' => false, 'confidence' => 'unknown',
                         'matched' => null, 'kind' => null, 'note' => 'keyring_unusable');
        }

        $ids = array();
        if (!empty($result['google_place_id'])) {
            $ids[Leadfinder_keyring::D_PLACE]  = (string) $result['google_place_id'];
            $ids[Leadfinder_keyring::D_SOURCE] = array('google_places', (string) $result['google_place_id']);
        }
        if (!empty($result['phone_e164'])) { $ids[Leadfinder_keyring::D_PHONE] = (string) $result['phone_e164']; }

        if (!$ids) {
            return array('suppress' => false, 'confidence' => 'none',
                         'matched' => null, 'kind' => null, 'note' => 'no_identifier');
        }

        $keys = Leadfinder_tombstone::candidateKeys($ring, $ids);

        $lookFor = array();
        foreach ($keys as $set) {
            foreach ($set as $k) { $lookFor[] = $k; }
        }

        if (!$lookFor) {
            return array('suppress' => false, 'confidence' => 'none',
                         'matched' => null, 'kind' => null, 'note' => 'no_candidate_keys');
        }

        $table = $this->t('payplex_lf_suppressions');

        $rows = $this->db->group_start()
                         ->where_in('place_id_key', $lookFor)
                         ->or_where_in('source_ref_key', $lookFor)
                         ->or_where_in('phone_key', $lookFor)
                         ->group_end()
                         ->limit(20)->get($table)->result_array();

        $best = array('suppress' => false, 'confidence' => 'none',
                      'matched' => null, 'kind' => null, 'note' => 'no_match');

        foreach ($rows as $t) {
            $m = Leadfinder_tombstone::matches($keys, $t);

            if (!empty($m['suppress'])) { return $m; }

            if ($m['confidence'] === 'possible') { $best = $m; }
        }

        return $best;
    }

    /* ---------------------------------------------------------------- */
    /* Purge                                                             */
    /* ---------------------------------------------------------------- */

    /**
     * Clear the contact details of prospects whose purge is due.
     *
     * Locked, batched, and refused outright when the keyring is unreadable —
     * not because the purge needs the keyring (clearing a column does not), but
     * because a purge that runs while tombstones cannot be written destroys the
     * identifiers and leaves nothing able to recognise the business again.
     *
     * A DNC row is purged like any other: the contact details go, the tombstone
     * and the register stay. That is the point of the design — the promise
     * outlives the record it was made about.
     */
    public function runPurgeSweep($now = null, $mode = 'cron', $actorId = 0)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $now = $now === null ? time() : (int) $now;
        $cfg = $this->wasteConfig();

        $gate = Leadfinder_keyring_loader::purgeAllowed();

        if (empty($gate['allowed'])) {
            $this->raiseKeyringAlert($gate['alert'], $now);

            return array('ok' => false, 'reason' => $gate['reason'], 'purged' => 0, 'examined' => 0,
                         'message' => 'The purge did not run: the suppression keyring is unreadable. '
                                    . 'No contact details were cleared.');
        }

        /*
         * A retention period nobody has stated is not a licence to invent one,
         * and a blank one must not disable the purge QUIETLY. The policy
         * resolves both periods together — individually valid values can still
         * be an incoherent pair — and a refusal raises an administrator alert
         * rather than returning a clean-looking run that destroyed nothing.
         */
        $policy = Leadfinder_retention_policy::resolve(
            $this->config('waste_pii_retention_days', ''),
            $this->config('waste_suppression_days', ''));

        if (empty($policy['purge_allowed'])) {
            $this->raiseRetentionAlert($policy['alert'], $now);

            return array('ok' => false, 'reason' => $policy['reason'],
                         'purged' => 0, 'examined' => 0,
                         'message' => 'The purge did not run: ' . $policy['alert']['message']);
        }

        if (!$this->acquireJobLock('waste_purge', $now, $cfg['purge_lock_ttl'])) {
            return array('ok' => false, 'reason' => 'locked', 'purged' => 0, 'examined' => 0,
                         'message' => 'Another purge run holds the lock. Nothing was changed.');
        }

        $queue    = $this->t('payplex_lf_purge_queue');
        $batch    = $cfg['purge_batch_size'] > 0 ? $cfg['purge_batch_size'] : 200;
        $examined = 0; $purged = 0; $held = 0; $failed = 0;

        $due = $this->db->where('state', Leadfinder_tombstone::P_PENDING)
                        ->where('due_at <=', $now)
                        ->order_by('due_at', 'ASC')
                        ->limit($batch)->get($queue)->result_array();

        foreach ($due as $q) {
            $examined++;
            $r = $this->purgeOne($q, $now, $policy['pii_days']);

            if ($r === 'purged')      { $purged++; }
            elseif ($r === 'held')    { $held++; }
            else                      { $failed++; }
        }

        $this->releaseJobLock('waste_purge', $now);

        $this->audit((int) $actorId, 'leadfinder_purge_sweep', 'purge_queue', 0, array(
            'mode' => $mode, 'examined' => $examined, 'purged' => $purged,
            'held' => $held, 'failed' => $failed));

        return array('ok' => true, 'reason' => 'completed', 'examined' => $examined,
                     'purged' => $purged, 'held' => $held, 'failed' => $failed,
                     'message' => $examined . ' examined, ' . $purged . ' purged, '
                                . $held . ' held, ' . $failed . ' failed.');
    }

    /**
     * One row of the purge.
     *
     * The tombstone is re-checked here, immediately before the destructive
     * write. It was checked when the row was queued, but that was up to a day
     * ago and the queue is not the authority on whether the record that will
     * recognise this business still exists.
     */
    private function purgeOne(array $q, $now, $retentionDays)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $queue      = $this->t('payplex_lf_purge_queue');
        $prospects  = $this->t('payplex_lf_prospects');
        $prospectId = (int) $q['prospect_id'];

        $p = $this->prospect($prospectId);

        if (!$p) {
            /* The prospect is gone; the tombstone is what matters and it stays. */
            $this->db->where('id', (int) $q['id'])
                     ->update($queue, array('state' => Leadfinder_tombstone::P_DONE,
                                            'purged_at' => $now));

            return 'purged';
        }

        if (!empty($p['undone_at']) && (int) $p['undone_at'] > (int) $p['wasted_at']) {
            /* Restored after queuing. Drop the entry rather than purge it. */
            $this->db->where('id', (int) $q['id'])->delete($queue);

            return 'held';
        }

        /*
         * The tombstone is read BEFORE the retention question is asked, because
         * the tombstone is what carries the decision date and the purge state.
         * The first draft asked `piiDue()` about the prospect row, which has
         * neither column — so it compared a missing `decided_at` against the
         * retention period, returned false for every row, and the purge
         * silently did nothing at all while reporting a clean run.
         */
        $tomb = (int) $q['suppression_id'] > 0
            ? $this->db->where('id', (int) $q['suppression_id'])
                       ->get($this->t('payplex_lf_suppressions'))->row_array()
            : null;

        if ($tomb && !Leadfinder_tombstone::piiDue($tomb, $now, $retentionDays)) {
            return 'held';
        }

        if (!$tomb) {
            /* THE ORDERING RULE. No tombstone, no purge — clearing the
               identifiers now would leave nothing able to recognise this
               business, and the failure would be permanent and silent. */
            $this->db->where('id', (int) $q['id'])
                     ->update($queue, array(
                         'state'      => 'failed',
                         'attempts'   => (int) $q['attempts'] + 1,
                         'last_error' => 'no tombstone for this prospect; purge refused'));

            return 'failed';
        }

        /* Every column the tombstone design says must not survive. Written as
           NULL, not as an empty string: "we cleared this" and "they had no
           website" are different facts. */
        $clear = array(
            'phone_raw' => null, 'phone_e164' => null, 'phone_weak_key' => null,
            'website' => null, 'website_domain' => null,
            'verified_email' => null, 'verified_contact_name' => null,
            'verified_designation' => null,
            'address' => null, 'pin_code' => null,
            'latitude' => null, 'longitude' => null,
            'google_maps_uri' => null,
            'verification_notes' => null,
            'waste_notes' => null,
            'pii_purged_at' => (int) $now,
            'coords_purged_at' => (int) $now,
        );

        $this->db->trans_begin();

        $this->db->where('id', $prospectId)->update($prospects, $clear);

        $this->db->where('id', (int) $tomb['id'])
                 ->update($this->t('payplex_lf_suppressions'), array(
                     'purge_state' => Leadfinder_tombstone::P_DONE,
                     'purged_at'   => (int) $now));

        $this->db->where('id', (int) $q['id'])
                 ->update($queue, array('state' => Leadfinder_tombstone::P_DONE,
                                        'purged_at' => (int) $now));

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            $this->db->where('id', (int) $q['id'])
                     ->update($queue, array('attempts' => (int) $q['attempts'] + 1,
                                            'last_error' => 'transaction failed; nothing was cleared'));

            return 'failed';
        }

        $this->db->trans_commit();

        /* The audit records that a purge happened and to which prospect. It
           records none of what was destroyed — an audit row that listed the
           cleared phone number would be the copy nobody remembered to clear. */
        $this->audit(0, 'prospect_pii_purged', 'prospect', $prospectId, array(
            'suppression_id' => (int) $tomb['id'],
            'kind'           => (string) $tomb['suppression_kind'],
            'fields_cleared' => count($clear) - 2,
        ));

        return 'purged';
    }

    /** Purge queue rows an administrator can see, with no PII in them. */
    public function purgeQueueSummary($now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $now   = $now === null ? time() : (int) $now;
        $queue = $this->t('payplex_lf_purge_queue');

        $rows = $this->db->select('state, COUNT(*) AS n, MIN(due_at) AS soonest', false)
                         ->group_by('state')->get($queue)->result_array();

        $out = array('pending' => 0, 'purged' => 0, 'failed' => 0, 'held' => 0,
                     'due_now' => 0, 'soonest_due' => null);

        foreach ($rows as $r) {
            $state = (string) $r['state'];
            if (isset($out[$state])) { $out[$state] = (int) $r['n']; }
            if ($state === Leadfinder_tombstone::P_PENDING) {
                $out['soonest_due'] = (int) $r['soonest'];
            }
        }

        $out['due_now'] = (int) $this->db->where('state', Leadfinder_tombstone::P_PENDING)
                                         ->where('due_at <=', $now)
                                         ->count_all_results($queue);

        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Re-key                                                            */
    /* ---------------------------------------------------------------- */

    /**
     * Re-key tombstones from an older pepper version to the active one.
     *
     * Only rows whose source identifiers still exist can be re-keyed. Once the
     * purge has run, the plaintext is gone and the keys cannot be re-derived —
     * those rows are marked `impossible_source_purged` and stay on their
     * original version, which is precisely why retiring a version is refused
     * while anything still references it.
     *
     * This never deletes an old version and never overwrites one. Rotation adds.
     */
    public function rekeyTombstones($fromVersion, $actorId, $now = null, $limit = 500)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring_loader.php';
        require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $now  = $now === null ? time() : (int) $now;
        $ring = Leadfinder_keyring_loader::load();

        if ($ring === null) {
            return array('ok' => false, 'reason' => 'keyring_unusable', 'rekeyed' => 0,
                         'message' => 'The keyring cannot be read. Nothing was re-keyed.');
        }

        $to = Leadfinder_keyring::activeVersion($ring);

        if ((string) $fromVersion === (string) $to) {
            return array('ok' => false, 'reason' => 'already_active', 'rekeyed' => 0,
                         'message' => 'Those rows already use the active version.');
        }

        if (!in_array((string) $fromVersion, Leadfinder_keyring::versions($ring), true)) {
            return array('ok' => false, 'reason' => 'unknown_source_version', 'rekeyed' => 0,
                         'message' => 'The keyring does not contain version ' . $fromVersion
                                    . '. It must not be removed while tombstones still reference it.');
        }

        if (!$this->acquireJobLock('pepper_rekey', $now, $this->configInt('purge_job_lock_ttl_seconds', 900))) {
            return array('ok' => false, 'reason' => 'locked', 'rekeyed' => 0,
                         'message' => 'Another re-key run holds the lock.');
        }

        $ledger = $this->t('payplex_lf_pepper_rekeys');

        $this->db->insert($ledger, array(
            'from_version' => (string) $fromVersion, 'to_version' => (string) $to,
            'started_at' => $now, 'status' => 'running', 'actor_id' => (int) $actorId));

        $runId = (int) $this->db->insert_id();

        $rows = $this->db->where('pepper_version', (string) $fromVersion)
                         ->where_in('rekey_state', array(Leadfinder_keyring::RK_NONE,
                                                         Leadfinder_keyring::RK_PENDING))
                         ->limit((int) $limit)
                         ->get($this->t('payplex_lf_suppressions'))->result_array();

        $examined = 0; $done = 0; $impossible = 0;

        foreach ($rows as $t) {
            $examined++;
            $r = $this->rekeyOne($t, $ring, $to, $now);

            if ($r === 'rekeyed')         { $done++; }
            elseif ($r === 'impossible')  { $impossible++; }
        }

        $this->db->where('id', $runId)->update($ledger, array(
            'finished_at' => $now, 'examined' => $examined, 'rekeyed' => $done,
            'impossible' => $impossible, 'status' => 'finished'));

        $this->releaseJobLock('pepper_rekey', $now);

        $this->audit((int) $actorId, 'leadfinder_tombstones_rekeyed', 'suppression', 0, array(
            'from_version' => (string) $fromVersion, 'to_version' => (string) $to,
            'examined' => $examined, 'rekeyed' => $done, 'impossible' => $impossible));

        return array('ok' => true, 'reason' => 'completed', 'run_id' => $runId,
                     'examined' => $examined, 'rekeyed' => $done, 'impossible' => $impossible,
                     'message' => $examined . ' examined, ' . $done . ' re-keyed, '
                                . $impossible . ' impossible (source identifiers already purged).');
    }

    /** One tombstone re-keyed, or honestly marked impossible. */
    private function rekeyOne(array $t, $ring, $toVersion, $now)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_keyring.php';
        require_once __DIR__ . '/../libraries/Leadfinder_tombstone.php';

        $table = $this->t('payplex_lf_suppressions');
        $may   = Leadfinder_keyring::mayRekey($t);

        if (empty($may['ok'])) {
            $this->db->where('id', (int) $t['id'])
                     ->update($table, array('rekey_state' => Leadfinder_keyring::RK_IMPOSSIBLE));

            return 'impossible';
        }

        $p = (int) $t['prospect_id'] > 0 ? $this->prospect((int) $t['prospect_id']) : null;

        /* No prospect, or a purged one, means no plaintext to derive from. */
        if (!$p || !empty($p['pii_purged_at'])) {
            $this->db->where('id', (int) $t['id'])
                     ->update($table, array('rekey_state' => Leadfinder_keyring::RK_IMPOSSIBLE));

            return 'impossible';
        }

        $ids = array();
        if (!empty($p['google_place_id'])) {
            $ids[Leadfinder_keyring::D_PLACE]  = (string) $p['google_place_id'];
            $ids[Leadfinder_keyring::D_SOURCE] = array('google_places', (string) $p['google_place_id']);
        }
        if (!empty($p['phone_e164'])) { $ids[Leadfinder_keyring::D_PHONE] = (string) $p['phone_e164']; }
        if (!empty($p['verified_email'])) { $ids[Leadfinder_keyring::D_EMAIL] = (string) $p['verified_email']; }

        $keys = Leadfinder_tombstone::candidateKeys($ring, $ids);
        $set  = isset($keys[$toVersion]) ? $keys[$toVersion] : array();

        if (!$set) {
            $this->db->where('id', (int) $t['id'])
                     ->update($table, array('rekey_state' => Leadfinder_keyring::RK_IMPOSSIBLE));

            return 'impossible';
        }

        /*
         * A NEW ROW under the new version. The original is left exactly as it
         * is, still matching under its own version, until the old version is
         * retired deliberately. Updating in place would mean a failure halfway
         * through the batch left rows keyed under a version nobody can tell
         * apart from the ones that succeeded.
         */
        $row = $t;
        unset($row['id']);
        $row['pepper_version'] = (string) $toVersion;
        $row['rekey_state']    = Leadfinder_keyring::RK_DONE;
        $row['source_ref_key'] = isset($set[Leadfinder_keyring::D_SOURCE]) ? $set[Leadfinder_keyring::D_SOURCE] : null;
        $row['place_id_key']   = isset($set[Leadfinder_keyring::D_PLACE]) ? $set[Leadfinder_keyring::D_PLACE] : null;
        $row['phone_key']      = isset($set[Leadfinder_keyring::D_PHONE]) ? $set[Leadfinder_keyring::D_PHONE] : null;
        $row['email_key']      = isset($set[Leadfinder_keyring::D_EMAIL]) ? $set[Leadfinder_keyring::D_EMAIL] : null;
        $row['created_at']     = (int) $now;

        $cols = array_keys($row);
        $ph   = implode(',', array_fill(0, count($cols), '?'));

        $this->db->query("INSERT IGNORE INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES ({$ph})",
                         array_values($row));

        $this->db->where('id', (int) $t['id'])
                 ->update($table, array('rekey_state' => Leadfinder_keyring::RK_DONE));

        return 'rekeyed';
    }

    /** How many tombstones still depend on each pepper version. */
    public function tombstoneVersionCounts()
    {
        $rows = $this->db->select('pepper_version, COUNT(*) AS n', false)
                         ->group_by('pepper_version')
                         ->get($this->t('payplex_lf_suppressions'))->result_array();

        $out = array();
        foreach ($rows as $r) { $out[(string) $r['pepper_version']] = (int) $r['n']; }

        return $out;
    }

    /* ---------------------------------------------------------------- */
    /* Administrative deletion                                           */
    /* ---------------------------------------------------------------- */

    /**
     * Delete a prospect outright. Administrator only, reason required.
     *
     * The tombstone is NOT deleted with it. Removing a prospect is an
     * administrative tidy-up; removing the record that stops us contacting the
     * business again is a different decision, and conflating them is how a DNC
     * list quietly empties itself one cleanup at a time.
     */
    public function deleteProspect($prospectId, $actorId, $reason, $isAdmin, $now = null)
    {
        $now        = $now === null ? time() : (int) $now;
        $prospectId = (int) $prospectId;
        $reason     = trim((string) $reason);

        if (!$isAdmin) {
            return array('ok' => false, 'reason' => 'not_admin',
                         'message' => 'Only an administrator can delete a prospect.');
        }

        if ($reason === '' || mb_strlen($reason) < 4) {
            return array('ok' => false, 'reason' => 'reason_required',
                         'message' => 'Give a reason for the deletion. Nothing was changed.');
        }

        $p = $this->prospect($prospectId);

        if (!$p) {
            return array('ok' => false, 'reason' => 'not_found',
                         'message' => 'That prospect no longer exists.');
        }

        if (!empty($p['converted_lead_id'])) {
            return array('ok' => false, 'reason' => 'converted',
                         'message' => 'That prospect has been converted to a lead. Deleting it here would '
                                    . 'leave the lead with no origin record. Nothing was changed.');
        }

        $this->db->trans_begin();

        $this->db->where('prospect_id', $prospectId)->delete($this->t('payplex_lf_saved_prospects'));
        $this->db->where('prospect_id', $prospectId)->delete($this->t('payplex_lf_purge_queue'));

        /* The tombstone survives, and loses only its back-reference. */
        $this->db->where('prospect_id', $prospectId)
                 ->update($this->t('payplex_lf_suppressions'), array('prospect_id' => null));

        $this->db->where('id', $prospectId)->delete($this->t('payplex_lf_prospects'));

        if ($this->db->trans_status() === false) {
            $this->db->trans_rollback();

            return array('ok' => false, 'reason' => 'transaction_failed',
                         'message' => 'The prospect could not be deleted. Nothing was changed.');
        }

        $this->db->trans_commit();

        $this->audit((int) $actorId, 'prospect_deleted', 'prospect', $prospectId, array(
            'reason' => mb_substr($reason, 0, 255),
            'was_waste' => !empty($p['wasted_at']),
            'tombstone_kept' => true,
        ));

        return array('ok' => true, 'reason' => 'deleted',
                     'message' => 'Prospect deleted. Any suppression record for that business was kept.');
    }

    /* ---------------------------------------------------------------- */
    /* Saved filters                                                     */
    /* ---------------------------------------------------------------- */

    /** One employee's saved filters, newest first. */
    public function savedFilters($staffId)
    {
        $staffId = (int) $staffId;

        if ($staffId <= 0) { return array(); }

        return $this->db->where('staff_id', $staffId)->order_by('created_at', 'DESC')
                        ->get($this->t('payplex_lf_saved_filters'))->result_array();
    }

    /**
     * Save a filter set under a name.
     *
     * The payload is normalised before storage and normalised again on read.
     * A row written by an older build is untrusted input by the time a newer
     * one reads it, and a filter is a thing that goes straight into a query.
     */
    public function saveFilter($staffId, $name, array $filters, $now = null)
    {
        require_once __DIR__ . '/../libraries/Leadfinder_ui.php';

        $now     = $now === null ? time() : (int) $now;
        $staffId = (int) $staffId;
        $table   = $this->t('payplex_lf_saved_filters');

        $count = (int) $this->db->where('staff_id', $staffId)->count_all_results($table);

        $v = Leadfinder_ui::validateSavedFilter($name, $filters, $count);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => 'rejected', 'message' => $v['error']);
        }

        $clean = Leadfinder_ui::normaliseFilters($filters);

        $this->db->query(
            "INSERT INTO `{$table}` (staff_id, name, payload, created_at, updated_at)
             VALUES (?,?,?,?,NULL)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = ?",
            array($staffId, trim((string) $name), json_encode($clean), $now, $now)
        );

        $this->audit($staffId, 'leadfinder_filter_saved', 'saved_filter', 0, array(
            'keys' => array_keys($clean)));

        return array('ok' => true, 'reason' => 'saved', 'message' => 'Filter saved.');
    }

    /** Delete one of the actor's own saved filters. Never anybody else's. */
    public function deleteSavedFilter($staffId, $id)
    {
        $this->db->where('id', (int) $id)->where('staff_id', (int) $staffId)
                 ->delete($this->t('payplex_lf_saved_filters'));

        $gone = (int) $this->db->affected_rows() > 0;

        if ($gone) {
            $this->audit((int) $staffId, 'leadfinder_filter_deleted', 'saved_filter', (int) $id, array());
        }

        return array('ok' => $gone, 'reason' => $gone ? 'deleted' : 'not_found');
    }

    /* ---------------------------------------------------------------- */
    /* The browser map key                                               */
    /* ---------------------------------------------------------------- */

    /**
     * The browser map key, in plaintext, for the one place it may appear.
     *
     * Returns '' unless a key is stored. It never falls back to the server
     * Places key and never to the Perfex core key: those are different
     * credentials with different restrictions, and substituting one would put a
     * Places key with a large budget into a public page.
     *
     * The caller must already have established that the route may render it —
     * `Leadfinder_map::mayRenderBrowserKey()` decides that, by exact route
     * match, and this method is not a second opinion on it.
     */
    public function browserMapKey($profileId = 0)
    {
        $profileId = (int) $profileId;

        $q = $this->db->select('browser_map_key_enc');

        if ($profileId > 0) { $q->where('id', $profileId); }
        else                { $q->where('active', 1)->order_by('id', 'ASC'); }

        $r = $q->limit(1)->get($this->t('payplex_lf_api_profiles'))->row_array();

        if (!$r || trim((string) $r['browser_map_key_enc']) === '') { return ''; }

        $out = Leadfinder_secret::useFor($r['browser_map_key_enc'], $this->decryptor(),
            function ($plain) { return $plain; });

        return !empty($out['ok']) ? (string) $out['value'] : '';
    }

    /** What an administrator may see about the browser key: never the key. */
    public function browserMapKeyStatus($profileId = 0)
    {
        $profileId = (int) $profileId;

        $q = $this->db->select('id, name, browser_map_key_fingerprint, browser_map_referrers,'
                             . ' browser_map_key_set_at, browser_map_key_set_by,'
                             . ' LENGTH(browser_map_key_enc) AS stored_bytes', false);

        if ($profileId > 0) { $q->where('id', $profileId); }
        else                { $q->where('active', 1)->order_by('id', 'ASC'); }

        $r = $q->limit(1)->get($this->t('payplex_lf_api_profiles'))->row_array();

        if (!$r) {
            return array('configured' => false, 'fingerprint' => null, 'referrers' => null,
                         'set_at' => null, 'set_by' => 0, 'profile_id' => 0, 'profile_name' => null);
        }

        return array(
            'configured'   => (int) $r['stored_bytes'] > 0,
            'fingerprint'  => $r['browser_map_key_fingerprint'],
            'referrers'    => $r['browser_map_referrers'],
            'set_at'       => $r['browser_map_key_set_at'] === null ? null : (int) $r['browser_map_key_set_at'],
            'set_by'       => (int) $r['browser_map_key_set_by'],
            'profile_id'   => (int) $r['id'],
            'profile_name' => (string) $r['name'],
        );
    }

    /**
     * Store a browser map key an administrator entered.
     *
     * Blank means keep, exactly as it does for the server key, so a save that
     * did not intend to touch the credential cannot erase it.
     */
    public function saveBrowserMapKey($profileId, $keyPlain, $referrers, $actorId)
    {
        $profileId = (int) $profileId;
        $keyPlain  = trim((string) $keyPlain);
        $table     = $this->t('payplex_lf_api_profiles');

        $row = $this->db->where('id', $profileId)->get($table)->row_array();

        if (!$row) {
            return array('ok' => false, 'message' => 'That API connection does not exist.');
        }

        $update = array(
            'browser_map_referrers' => mb_substr(trim((string) $referrers), 0, 500),
        );

        $changed = false;

        if ($keyPlain !== '') {
            $sealed = Leadfinder_secret::seal($keyPlain, $this->encryptor());

            if (empty($sealed['ok'])) {
                return array('ok' => false, 'message' => 'The key could not be stored securely, '
                                                       . 'so it was not stored at all.');
            }

            $update['browser_map_key_enc']         = $sealed['value'];
            $update['browser_map_key_fingerprint'] = Leadfinder_secret::fingerprint($keyPlain);
            $update['browser_map_key_set_at']      = time();
            $update['browser_map_key_set_by']      = (int) $actorId;
            $changed = true;
        }

        $this->db->where('id', $profileId)->update($table, $update);

        /* The fingerprint, never the key, never its length. */
        $this->audit((int) $actorId, 'browser_map_key_saved', 'api_profile', $profileId, array(
            'key_changed' => $changed,
            'fingerprint' => $changed ? $update['browser_map_key_fingerprint'] : null,
            'referrers_set' => $update['browser_map_referrers'] !== '',
        ));

        return array('ok' => true,
                     'message' => $changed
                         ? 'Browser map key stored. Confirm in Google Cloud Console that it is '
                           . 'restricted by HTTP referrer to this site and enabled only for the '
                           . 'Maps JavaScript API.'
                         : 'Referrer note updated. The stored key was left unchanged.');
    }

    /* ---------------------------------------------------------------- */

    /**
     * Record a keyring failure where an administrator will see it.
     *
     * Redacted: the reason is a short machine token, never a path, never any
     * part of a key. The alert row is the thing somebody acts on, so it says
     * what stopped and what to do, and nothing about what the secret looks like.
     */
    private function raiseRetentionAlert($alert, $now)
    {
        if (!is_array($alert) || empty($alert['kind'])) { return; }

        $this->audit(0, 'leadfinder_retention_not_configured', 'config', 0, array(
            'reason'  => (string) $alert['reason'],
            'blocked' => array('purge_pii'),
            'at'      => gmdate('c', (int) $now),
        ));
    }

    /**
     * The retention settings, resolved and reportable.
     *
     * Used by the settings screen and the waste screen. Returns the operating
     * decision, not just the raw values, so a screen cannot show "7 days" next
     * to a purge that is actually refusing to run.
     */
    public function retentionSettings()
    {
        $policy = Leadfinder_retention_policy::resolve(
            $this->config('waste_pii_retention_days', ''),
            $this->config('waste_suppression_days', ''));

        $policy['raw_pii']         = $this->config('waste_pii_retention_days', '');
        $policy['raw_suppression'] = $this->config('waste_suppression_days', '');
        $policy['dnc']             = Leadfinder_retention_policy::dncRevocation();

        return $policy;
    }

    /**
     * Store the two retention periods, validated as a pair.
     *
     * Writes EXACTLY the two config rows and nothing else — asserted by the
     * suite, because a settings save that quietly touched a third row is how a
     * keyring or a quota ceiling changes without anybody deciding to change it.
     *
     * The keyring is not read, written or referenced here at all.
     */
    public function saveRetentionSettings($piiRaw, $suppressionRaw, $actorId, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        $v = Leadfinder_retention_policy::validatePair($piiRaw, $suppressionRaw);

        if (empty($v['ok'])) {
            return array('ok' => false, 'reason' => $v['reason'], 'message' => $v['error'], 'changed' => 0);
        }

        $table   = $this->t('payplex_lf_config');
        $changed = 0;

        foreach (array('waste_pii_retention_days' => $v['pii'],
                       'waste_suppression_days'   => $v['suppression']) as $key => $value) {
            $before = $this->config($key, '');

            if ((string) $before === (string) $value) { continue; }

            $this->db->where('ckey', $key)->update($table, array(
                'cvalue'  => (string) $value,
                'set_by'  => (int) $actorId,
                'set_at'  => $now,
            ));

            $changed += (int) $this->db->affected_rows();

            /* The value is recorded in the audit. It is a policy decision, not a
               secret, and "who set the retention period to 7" is exactly the
               question somebody will ask. */
            $this->audit((int) $actorId, 'leadfinder_retention_changed', 'config', 0, array(
                'setting' => $key, 'from' => (string) $before, 'to' => (string) $value));
        }

        $this->cfgCache = null;

        return array('ok' => true, 'reason' => 'saved', 'changed' => $changed,
                     'pii_days' => $v['pii'], 'suppression_days' => $v['suppression'],
                     'message' => 'Retention saved: contact details purged after ' . $v['pii']
                                . ' days, waste suppression kept for ' . $v['suppression'] . ' days. '
                                . 'Do Not Contact is unaffected and does not expire.');
    }

    private function raiseKeyringAlert($alert, $now)
    {
        if (!is_array($alert) || empty($alert['kind'])) { return; }

        $this->audit(0, 'leadfinder_keyring_unusable', 'keyring', 0, array(
            'reason'  => (string) $alert['reason'],
            'blocked' => array('create_tombstone', 'purge_pii'),
            'at'      => gmdate('c', (int) $now),
        ));
    }
}
