<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_staff_types.php';
require_once __DIR__ . '/../libraries/Payplex_staff_lifecycle.php';
require_once __DIR__ . '/../libraries/Payplex_staff_profile.php';
require_once __DIR__ . '/../libraries/Payplex_staff_perms.php';
require_once __DIR__ . '/../libraries/Payplex_staff_activity.php';
require_once __DIR__ . '/../libraries/Payplex_staff_kpi.php';
require_once __DIR__ . '/../libraries/Payplex_staff_consent.php';
require_once __DIR__ . '/../libraries/Payplex_staff_geo.php';

/**
 * Payplex_staff_model
 *
 * Data layer for the Staff System. Reads Perfex core staff READ-ONLY; all of
 * this module's data lives in its own payplex_staff_* tables. Financial and
 * approval actions are audited immutably; employment-type changes create a new
 * effective-dated profile version; bank data is encrypted at rest.
 */
require_once __DIR__ . '/../libraries/Payplex_staff_sync.php';
require_once __DIR__ . '/../libraries/Workforce_access.php';
require_once __DIR__ . '/../libraries/Workforce_attendance.php';
require_once __DIR__ . '/../libraries/Workforce_documents.php';
require_once __DIR__ . '/../libraries/Workforce_expense.php';
require_once __DIR__ . '/../libraries/Workforce_bank.php';
/* Emergency IDOR containment. A hard require, never a class_exists() guard: if
   this file is missing the request must die, not silently skip the check. */
require_once __DIR__ . '/../libraries/Workforce_emergency_guard.php';

/** The legacy work_mode vocabulary, mapped forward without coupling to another module. */
function Workforce_classification_legacy_mode($v)
{
    $map = array('in_house' => 'office', 'field' => 'field_sales');
    $k = strtolower(trim((string) $v));
    return isset($map[$k]) ? $map[$k] : '';
}

class Payplex_staff_model extends App_Model
{
    private function pTable() { return db_prefix() . 'payplex_staff_profiles'; }
    private function tTable() { return db_prefix() . 'payplex_staff_types'; }
    private function tplTable() { return db_prefix() . 'payplex_staff_perm_templates'; }
    private function aTable() { return db_prefix() . 'payplex_staff_audit'; }
    private function sTable() { return db_prefix() . 'payplex_staff_settings'; }
    private function conTable()  { return db_prefix() . 'payplex_staff_consent'; }
    private function fsTable()   { return db_prefix() . 'payplex_staff_field_sessions'; }
    private function locTable()  { return db_prefix() . 'payplex_staff_locations'; }
    private function plogTable() { return db_prefix() . 'payplex_staff_privacy_log'; }

    /* ---------------- settings ---------------- */
    public function getSetting($name, $default = null)
    {
        if (!$this->db->table_exists($this->sTable())) { return $default; }
        $r = $this->db->where('name', $name)->get($this->sTable())->row();
        return $r ? $r->value : $default;
    }

    public function setSetting($name, $value)
    {
        if (!$this->db->table_exists($this->sTable())) { return; }
        $exists = $this->db->where('name', $name)->get($this->sTable())->row();
        if ($exists) {
            $this->db->where('name', $name)->update($this->sTable(), array('value' => $value));
        } else {
            $this->db->insert($this->sTable(), array('name' => $name, 'value' => $value));
        }
    }

    /* ---------------- bank encryption ---------------- */
    private function encKey()
    {
        $k = $this->config->item('encryption_key');
        if (!$k) { $k = $this->getSetting('bank_enc_key', ''); }
        if (!$k) { $k = bin2hex(random_bytes(16)); $this->setSetting('bank_enc_key', $k); }
        return hash('sha256', (string) $k, true);
    }

    public function encryptBank($plainArray)
    {
        $json = json_encode($plainArray);
        $iv = random_bytes(16);
        $ct = openssl_encrypt($json, 'AES-256-CBC', $this->encKey(), OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ct);
    }

    public function decryptBank($blob)
    {
        if (!$blob) { return array(); }
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 17) { return array(); }
        $iv = substr($raw, 0, 16); $ct = substr($raw, 16);
        $json = openssl_decrypt($ct, 'AES-256-CBC', $this->encKey(), OPENSSL_RAW_DATA, $iv);
        $a = json_decode((string) $json, true);
        return is_array($a) ? $a : array();
    }

    public static function maskAccount($acct)
    {
        $acct = (string) $acct;
        $n = strlen($acct);
        if ($n <= 4) { return str_repeat('*', $n); }
        return str_repeat('*', $n - 4) . substr($acct, -4);
    }

    /* ---------------- Perfex staff (read-only) ---------------- */
    /** One core staff row, or null. */
    public function coreStaffMember($staffId)
    {
        $id = (int) $staffId;
        if ($id <= 0) { return null; }
        return $this->db->where('staffid', $id)->get(db_prefix() . 'staff')->row();
    }

    public function coreStaff()
    {
        $t = db_prefix() . 'staff';
        if (!$this->db->table_exists($t)) { return array(); }
        return $this->db->select('staffid, firstname, lastname, email, active')->get($t)->result();
    }

    /* ---------------- profiles ---------------- */
    public function currentProfile($staffId)
    {
        if (!$this->db->table_exists($this->pTable())) { return null; }
        return $this->db->where('staff_id', (int) $staffId)->where('is_current', 1)
            ->order_by('version', 'DESC')->get($this->pTable())->row();
    }

    public function profileVersions($staffId)
    {
        if (!$this->db->table_exists($this->pTable())) { return array(); }
        return $this->db->where('staff_id', (int) $staffId)->order_by('version', 'DESC')->get($this->pTable())->result();
    }

    /** All current profiles joined with a display name. Optional filters. */
    public function currentProfiles($filters = array())
    {
        if (!$this->db->table_exists($this->pTable())) { return array(); }
        $this->db->where('is_current', 1);
        if (!empty($filters['classification_required'])) { $this->db->where('classification_required', 1); }
        if (!empty($filters['status'])) { $this->db->where('status', $filters['status']); }
        if (!empty($filters['employment_type'])) { $this->db->where('employment_type', $filters['employment_type']); }
        return $this->db->order_by('full_name', 'ASC')->get($this->pTable())->result();
    }

    /**
     * Create the first version, or a new effective-dated version if the
     * employment type changed, else update the current version in place.
     */
    public function saveProfile($data, $actorId)
    {
        $v = Payplex_staff_profile::validate($data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']); }
        $e = $v['entry'];
        $staffId = (int) $e['staff_id'];
        if ($staffId <= 0) { return array('ok' => false, 'errors' => array('staff_id_required')); }

        $now = date('Y-m-d H:i:s');
        $row = array(
            'staff_id' => $staffId,
            'full_name' => $e['full_name'], 'employee_code' => $e['employee_code'],
            'official_email' => $e['official_email'], 'official_mobile' => $e['official_mobile'],
            'department' => $e['department'], 'designation' => $e['designation'],
            'reporting_manager_id' => $e['reporting_manager_id'], 'branch' => $e['branch'], 'territory' => $e['territory'],
            'employment_type' => $e['employment_type'], 'joining_date' => $e['joining_date'], 'probation_end_date' => $e['probation_end_date'],
            'salary_eligibility' => $e['salary_eligibility'] ? 1 : 0,
            'commission_eligibility' => $e['commission_eligibility'] ? 1 : 0,
            'expense_eligibility' => $e['expense_eligibility'] ? 1 : 0,
            'tada_eligibility' => $e['tada_eligibility'] ? 1 : 0,
            'attendance_required' => $e['attendance_required'] ? 1 : 0,
            'target_plan' => $e['target_plan'], 'commission_plan' => $e['commission_plan'],
            'payout_frequency' => $e['payout_frequency'],
            'pan_status' => $e['pan_status'], 'kyc_status' => $e['kyc_status'],
            'exit_reason' => $e['exit_reason'], 'exit_date' => $e['exit_date'],
            'classification_required' => $e['classification_required'],
            'dateupdated' => $now,
        );

        $cur = $this->currentProfile($staffId);
        if (!$cur) {
            $row['version'] = 1; $row['is_current'] = 1; $row['status'] = 'draft';
            $row['created_by'] = (int) $actorId; $row['datecreated'] = $now;
            $row['effective_from'] = date('Y-m-d');
            $this->db->insert($this->pTable(), $row);
            $id = $this->db->insert_id();
            $this->audit($staffId, 'profile_created', 'Staff profile created (' . $e['employment_type'] . ')', array('profile_id' => $id), $actorId);
            return array('ok' => true, 'profile_id' => $id, 'versioned' => false, 'warnings' => $v['warnings']);
        }

        // employment type change => new effective-dated version (preserve history)
        if (Payplex_staff_lifecycle::shouldVersion($cur->employment_type, $e['employment_type'])) {
            $this->db->where('staff_id', $staffId)->update($this->pTable(), array('is_current' => 0));
            $row['version'] = (int) $cur->version + 1; $row['is_current'] = 1;
            $row['status'] = $cur->status; $row['bank_verified'] = (int) $cur->bank_verified;
            $row['created_by'] = (int) $cur->created_by; $row['datecreated'] = $now;
            $row['effective_from'] = date('Y-m-d');
            $this->db->insert($this->pTable(), $row);
            $id = $this->db->insert_id();
            $this->audit($staffId, 'type_change_versioned', 'Employment type ' . $cur->employment_type . ' -> ' . $e['employment_type'] . ' (v' . $row['version'] . ')', array('profile_id' => $id), $actorId);
            return array('ok' => true, 'profile_id' => $id, 'versioned' => true, 'warnings' => $v['warnings']);
        }

        // in-place update of current version
        $this->db->where('id', (int) $cur->id)->update($this->pTable(), $row);
        $this->audit($staffId, 'profile_updated', 'Staff profile updated', array('profile_id' => (int) $cur->id), $actorId);
        return array('ok' => true, 'profile_id' => (int) $cur->id, 'versioned' => false, 'warnings' => $v['warnings']);
    }

    /* ---------------- lifecycle ---------------- */
    public function transition($staffId, $action, $actorId, $ctx = array())
    {
        $cur = $this->currentProfile($staffId);
        if (!$cur) { return array('ok' => false, 'error' => 'no_profile'); }
        $ctx = array_merge(array(
            'actor_id'    => (int) $actorId,
            'is_approver' => payplex_staff_can('approve'),
            'is_verifier' => payplex_staff_can('verify'),
        ), $ctx);
        $res = Payplex_staff_lifecycle::transition(array('status' => $cur->status, 'created_by' => (int) $cur->created_by), $action, $ctx);
        if (!$res['ok']) { return $res; }

        $upd = array('status' => $res['status'], 'dateupdated' => date('Y-m-d H:i:s'));
        if ($action === 'verify')   { $upd['verified_by'] = (int) $actorId; }
        if ($action === 'approve')  { $upd['approved_by'] = (int) $actorId; }
        if ($action === 'submit')   { $upd['submitted_by'] = (int) $actorId; }
        if ($action === 'suspend')  { $upd['suspension_date'] = date('Y-m-d'); }
        if ($action === 'exit')     { $upd['exit_date'] = date('Y-m-d'); }
        $this->db->where('id', (int) $cur->id)->update($this->pTable(), $upd);
        $this->audit($staffId, 'lifecycle', 'Staff #' . $staffId . ' ' . $action . ' -> ' . $res['status'], array('from' => $res['from']), $actorId);
        return array('ok' => true, 'status' => $res['status']);
    }

    /* ---------------- backfill ---------------- */
    public function backfillExistingStaff($actorId)
    {
        if (!$this->db->table_exists($this->pTable())) { return 0; }
        $count = 0;
        foreach ($this->coreStaff() as $s) {
            $exists = $this->db->where('staff_id', (int) $s->staffid)->get($this->pTable())->row();
            if ($exists) { continue; }
            $bf = Payplex_staff_profile::backfillProfile(array(
                'staff_id' => (int) $s->staffid,
                'full_name' => trim($s->firstname . ' ' . $s->lastname),
                'official_email' => $s->email,
            ));
            $now = date('Y-m-d H:i:s');
            $this->db->insert($this->pTable(), array_merge($bf, array(
                'version' => 1, 'is_current' => 1, 'effective_from' => date('Y-m-d'),
                'created_by' => (int) $actorId, 'datecreated' => $now, 'dateupdated' => $now,
            )));
            $count++;
        }
        if ($count > 0) { $this->audit(null, 'backfill', 'Backfilled ' . $count . ' existing staff as classification_required', array('count' => $count), $actorId); }
        return $count;
    }

    /**
     * Create a workforce profile for a staff member who has none.
     *
     * Called from the staff_member_created hook so a new joiner is covered the
     * moment they exist, and safe to call repeatedly: a staff member who already
     * has a current profile is left alone. backfillExistingStaff() remains, for
     * repairing a gap rather than being the only way to close one.
     *
     * @return array created (bool), reason
     */
    public function ensureProfile($staffId, $actorId = 0, $source = 'manual')
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0) { return array('created' => false, 'reason' => 'bad_staff_id'); }
        if (!$this->db->table_exists($this->pTable())) { return array('created' => false, 'reason' => 'no_table'); }

        $core = $this->db->where('staffid', $staffId)->get(db_prefix() . 'staff')->row();
        if (!$core) { return array('created' => false, 'reason' => 'staff_not_found'); }

        $existing = $this->db->where('staff_id', $staffId)->where('is_current', 1)
                             ->get($this->pTable())->row();
        if ($existing) { return array('created' => false, 'reason' => 'already_has_profile'); }

        $bf = Payplex_staff_profile::backfillProfile(array(
            'staff_id'       => $staffId,
            'full_name'      => trim($core->firstname . ' ' . $core->lastname),
            'official_email' => $core->email,
        ));

        /*
         * backfillProfile() hard-codes status 'active', which is right for staff
         * who were already working when this module arrived and wrong for
         * everybody created afterwards. A person created today has not been
         * classified, verified or approved.
         */
        $bf['status'] = Payplex_staff_sync::initialStatusFor($core->active);

        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->pTable(), array_merge($bf, array(
            'version' => 1, 'is_current' => 1, 'effective_from' => date('Y-m-d'),
            'created_by' => (int) $actorId, 'datecreated' => $now, 'dateupdated' => $now,
        )));
        $ok = $this->db->insert_id() > 0;

        if ($ok) {
            $this->audit($staffId, 'profile_created',
                'Workforce profile created automatically (' . $source . ') in status ' . $bf['status'],
                array('source' => $source, 'status' => $bf['status']), $actorId);
        }
        return array('created' => $ok, 'reason' => $ok ? '' : 'insert_failed');
    }

    /**
     * The CRM account was activated or deactivated. Record the disagreement if
     * there is one; never silently overwrite an employment decision with an
     * account setting, because they are different facts.
     */
    public function noteCrmStatusChange($staffId, $active)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0 || !$this->db->table_exists($this->pTable())) { return false; }
        if ($active === null) {
            $core = $this->db->where('staffid', $staffId)->get(db_prefix() . 'staff')->row();
            $active = $core ? (int) $core->active : null;
        }
        if ($active === null) { return false; }

        $row = $this->db->where('staff_id', $staffId)->where('is_current', 1)->get($this->pTable())->row();
        if (!$row) { return $this->ensureProfile($staffId, 0, 'after_staff_status_change')['created']; }

        $verdict = Payplex_staff_sync::reconcile($row->status, $active);
        $this->audit($staffId, 'crm_status_changed',
            'CRM account ' . ($active ? 'activated' : 'deactivated')
            . '; workforce record says "' . $row->status . '"'
            . ($verdict['verdict'] === 'aligned' ? '' : ' — ' . $verdict['message']),
            array('crm_active' => $active, 'profile_status' => $row->status, 'verdict' => $verdict['verdict']), 0);
        return true;
    }

    /** The CRM account is gone. The workforce record is not deleted with it. */
    public function noteCrmAccountRemoved($staffId)
    {
        $staffId = (int) $staffId;
        if ($staffId <= 0 || !$this->db->table_exists($this->pTable())) { return false; }
        $row = $this->db->where('staff_id', $staffId)->where('is_current', 1)->get($this->pTable())->row();
        if (!$row) { return false; }
        $this->audit($staffId, 'crm_account_removed',
            'The CRM staff account was deleted. This workforce record is kept, with its approval '
            . 'history, consent and audit trail intact.',
            array('profile_status' => $row->status), 0);
        return true;
    }

    /**
     * Both headcounts, each labelled with the question it answers. The summary
     * tiles used to print one number called "Active" that meant neither.
     */
    public function headcount()
    {
        if (!$this->db->table_exists($this->pTable())) { return array(); }
        $rows = $this->db->query(
            'SELECT p.status, s.active AS crm_active
             FROM ' . $this->pTable() . ' p
             LEFT JOIN ' . db_prefix() . 'staff s ON s.staffid = p.staff_id
             WHERE p.is_current = 1'
        )->result_array();
        return Payplex_staff_sync::headcount($rows);
    }

    /** Every profile whose employment record disagrees with its CRM account. */
    public function mismatches()
    {
        if (!$this->db->table_exists($this->pTable())) { return array(); }
        $rows = $this->db->query(
            'SELECT p.staff_id, p.full_name, p.status, s.active AS crm_active, s.last_login
             FROM ' . $this->pTable() . ' p
             LEFT JOIN ' . db_prefix() . 'staff s ON s.staffid = p.staff_id
             WHERE p.is_current = 1'
        )->result_array();
        $out = array();
        foreach ($rows as $r) {
            $v = Payplex_staff_sync::reconcile($r['status'], $r['crm_active']);
            if ($v['verdict'] !== 'aligned') { $out[] = array_merge($r, $v); }
        }
        return $out;
    }

    /**
     * The access picture, computed rather than eyeballed.
     *
     * Four classes of problem were found on staging and none of them is visible
     * from any screen in this CRM: capabilities granted directly to a person
     * with no role, grants left on deactivated accounts, grants on accounts
     * flagged "not a staff member", and a role assigned without the capability
     * rows that make it mean anything. See Workforce_access for the measurements.
     */
    public function accessAudit()
    {
        $rows = $this->db->query(
            'SELECT s.staffid, CONCAT(s.firstname," ",s.lastname) AS nm, s.admin, s.active,
                    s.is_not_staff, s.role, r.name AS role_name,
                    (SELECT COUNT(*) FROM ' . db_prefix() . 'staff_permissions sp
                      WHERE sp.staff_id = s.staffid) AS caps
             FROM ' . db_prefix() . 'staff s
             LEFT JOIN ' . db_prefix() . 'roles r ON r.roleid = s.role
             ORDER BY s.staffid'
        )->result_array();

        $out = array('staff' => array(), 'counts' => array());
        foreach ($rows as $r) {
            $r['anomalies'] = Workforce_access::grantAnomalies($r);
            foreach ($r['anomalies'] as $a) {
                $out['counts'][$a['code']] = (isset($out['counts'][$a['code']]) ? $out['counts'][$a['code']] : 0) + 1;
            }
            $out['staff'][] = $r;
        }
        return $out;
    }

    /** feature => capabilities that at least one person holds. */
    public function grantedCapabilities()
    {
        $out = array();
        foreach ($this->db->query(
            'SELECT feature, capability FROM ' . db_prefix() . 'staff_permissions
             GROUP BY feature, capability')->result_array() as $r) {
            $out[$r['feature']][] = $r['capability'];
        }
        return $out;
    }

    /* ---------------- attendance ---------------- */

    /**
     * Attendance defaults per engagement type, taken from the type table the
     * module already has. Falls back to the code taxonomy when the table has
     * not been seeded, so this never silently answers "not required" because a
     * migration has not run.
     */
    public function attendanceTypeDefaults()
    {
        $out = array();
        $t = db_prefix() . 'payplex_staff_types';
        if ($this->db->table_exists($t)) {
            foreach ($this->db->get($t)->result_array() as $r) {
                $out[$r['slug']] = (int) $r['attendance_required'] === 1;
            }
        }
        if (!$out) {
            foreach (Payplex_staff_types::types() as $slug => $def) {
                $out[$slug] = !empty($def['elig']['attendance']);
            }
        }
        return $out;
    }

    /** Today's raw attendance events for one person. */
    public function attendanceEvents($staffId, $day = null)
    {
        if (!$this->db->table_exists($this->actTable())) { return array(); }
        $day = $day ?: date('Y-m-d');
        $rows = $this->db->select('event_type, occurred_at')
            ->where('staff_id', (int) $staffId)
            ->where_in('event_type', array('attendance_checkin', 'attendance_checkout'))
            ->where('DATE(occurred_at)', $day)
            ->order_by('occurred_at', 'ASC')
            ->get($this->actTable())->result_array();
        $out = array();
        foreach ($rows as $r) { $out[] = array('type' => $r['event_type'], 'at' => $r['occurred_at']); }
        return $out;
    }

    /**
     * One person's attendance picture for a day: what is required of them,
     * which window applies, what they actually did, and the verdict.
     */
    public function attendanceDay($staffId, $day = null)
    {
        $day     = $day ?: date('Y-m-d');
        $profile = $this->currentProfile($staffId);
        $p       = $profile ? (array) $profile : array();

        $required = Workforce_attendance::isRequired($p, $this->attendanceTypeDefaults());

        /* work category lives on the classification, not the profile */
        $wc = '';
        $ct = db_prefix() . 'staff_classification';
        if ($this->db->table_exists($ct)) {
            $row = $this->db->select('work_category, work_mode')->where('staff_id', (int) $staffId)
                            ->get($ct)->row_array();
            if ($row) {
                $wc = (string) $row['work_category'];
                if ($wc === '' && !empty($row['work_mode'])) {
                    $wc = Workforce_classification_legacy_mode($row['work_mode']);
                }
            }
        }

        $settings = array(
            'start'         => $this->getSetting('attendance_start', Workforce_attendance::DEFAULT_START),
            'end'           => $this->getSetting('attendance_end', Workforce_attendance::DEFAULT_END),
            'grace_minutes' => (int) $this->getSetting('attendance_grace_minutes', Workforce_attendance::DEFAULT_GRACE_MIN),
        );
        $window = Workforce_attendance::windowFor($wc, $settings);
        $paired = Workforce_attendance::pairEvents($this->attendanceEvents($staffId, $day));
        $verdict = Workforce_attendance::dayVerdict($paired, $window, $required['required']);

        return array('day' => $day, 'staff_id' => (int) $staffId, 'work_category' => $wc,
                     'required' => $required, 'window' => $window, 'paired' => $paired,
                     'verdict' => $verdict);
    }

    /**
     * Record a check-in or check-out.
     *
     * The event vocabulary already existed — Payplex_staff_activity has carried
     * attendance_checkin and attendance_checkout since batch 2 and nothing had
     * ever written one. These are self-recorded, so they are logged UNVERIFIED:
     * a person asserting their own arrival is a claim, and this module's whole
     * position is that a claim and evidence are different things.
     *
     * @return array ok, reason
     */
    public function markAttendance($staffId, $direction, $actorId)
    {
        $staffId = (int) $staffId;
        $type = $direction === 'in' ? 'attendance_checkin' : 'attendance_checkout';

        $paired = Workforce_attendance::pairEvents($this->attendanceEvents($staffId));
        $gate = $direction === 'in'
            ? Workforce_attendance::canCheckIn($paired)
            : Workforce_attendance::canCheckOut($paired);
        if (!$gate['allowed']) { return array('ok' => false, 'reason' => $gate['reason']); }

        $id = $this->logActivity($staffId, $type, array(
            'created_by' => (int) $actorId,
            'note'       => $direction === 'in' ? 'Self-recorded check-in' : 'Self-recorded check-out',
        ));
        return array('ok' => $id > 0, 'reason' => $id > 0 ? '' : 'The event could not be recorded.');
    }

    /* ---------------- permission templates ---------------- */
    public function templates()
    {
        if (!$this->db->table_exists($this->tplTable())) { return array(); }
        return $this->db->order_by('name', 'ASC')->get($this->tplTable())->result();
    }

    public function saveTemplate($data, $actorId)
    {
        $v = Payplex_staff_perms::validateTemplate($data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }
        $e = $v['entry'];
        $now = date('Y-m-d H:i:s');
        $row = array(
            'name' => $e['name'],
            'base_role' => isset($data['base_role']) ? substr((string) $data['base_role'], 0, 40) : null,
            'allowed_json' => json_encode($e['allowed']),
            'prohibited_json' => json_encode($e['prohibited']),
            'dateupdated' => $now,
        );
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id > 0) {
            $this->db->where('id', $id)->update($this->tplTable(), $row);
        } else {
            $row['created_by'] = (int) $actorId; $row['datecreated'] = $now;
            $this->db->insert($this->tplTable(), $row); $id = $this->db->insert_id();
        }
        $this->audit(null, 'perm_template', 'Permission template saved: ' . $e['name'], array('template_id' => $id), $actorId);
        return array('ok' => true, 'template_id' => $id);
    }

    public function deleteTemplate($id, $actorId)
    {
        if (!$this->db->table_exists($this->tplTable())) { return; }
        $this->db->where('id', (int) $id)->delete($this->tplTable());
        $this->audit(null, 'perm_template', 'Permission template deleted #' . (int) $id, array(), $actorId);
    }

    /* ---------------- audit ---------------- */
    public function audit($staffId, $eventType, $message, $data = array(), $actorId = 0)
    {
        if (!$this->db->table_exists($this->aTable())) { return; }
        $ip = '';
        if (isset($this->input)) { $ip = $this->input->ip_address(); }
        // never store raw bank data in the audit payload
        unset($data['bank_account'], $data['bank_ifsc'], $data['bank_enc']);
        $this->db->insert($this->aTable(), array(
            'staff_id' => $staffId ? (int) $staffId : null,
            'event_type' => $eventType,
            'actor_id' => (int) $actorId,
            'message' => substr((string) $message, 0, 500),
            'data_json' => json_encode($data),
            'ip' => $ip,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }

    /* ===================================================================== */
    /*  EMERGENCY IDOR GUARD — model half. TEMPORARY.                         */
    /*  Added 2026-09-12. No schema dependency: every table read below already */
    /*  exists on staging. Remove with the guard once Workforce_scope ships.   */
    /* ===================================================================== */

    /**
     * Whose record is this?
     *
     * Several endpoints take a RECORD id rather than a staff id — a document,
     * an expense claim, a bank row, a field session, an activity event — and
     * each resolves to a person. Guarding the staff-id routes and not these
     * would leave the same door open one level down: `documents/7` refused,
     * `documents_download/912` not.
     *
     * Returns 0 when the record does not exist, which the guard refuses with
     * the SAME wording as a forbidden one — so missing and forbidden stay
     * indistinguishable from outside.
     */
    public function guardOwnerOf($type, $recordId)
    {
        $id = (int) $recordId;
        if ($id <= 0) { return 0; }

        switch ($type) {
            case 'document':
                $r = $this->documentRow($id);
                return $r ? (int) $r['staff_id'] : 0;
            case 'expense':
                $r = $this->claim($id);
                return $r ? (int) $r['claimant_id'] : 0;
            case 'bank':
                $r = $this->bankAccountRow($id);
                return $r ? (int) $r['staff_id'] : 0;
            case 'field_session':
                $r = $this->fieldSession($id);
                return $r ? (int) $r['staff_id'] : 0;
            case 'activity':
                if (!$this->db->table_exists($this->actTable())) { return 0; }
                $r = $this->db->where('id', $id)->get($this->actTable())->row();
                return $r ? (int) $r->staff_id : 0;
        }
        /* An unknown type resolves to nobody, so it refuses. Defaulting to the
           current user would turn a typo in a call site into a grant. */
        return 0;
    }

    /**
     * Record a guard decision in the EXISTING audit table.
     *
     * Deliberately reuses `payplex_staff_audit` rather than adding a table: a
     * hotfix that needs a migration is not a hotfix.
     */
    public function logGuardDecision(array $d, $actorId, $targetId, $resource)
    {
        $this->audit(
            Workforce_emergency_guard::cleanId($targetId) ?: null,
            !empty($d['allowed']) ? 'guard_allow' : 'guard_refuse',
            (!empty($d['allowed']) ? 'Allowed ' : 'Refused ') . $resource
                . ' for staff #' . Workforce_emergency_guard::cleanId($targetId),
            Workforce_emergency_guard::auditData($d, $targetId, $resource),
            (int) $actorId
        );
    }

    public function auditLog($limit = 200)
    {
        if (!$this->db->table_exists($this->aTable())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->aTable())->result();
    }

    /* ---------------- summary ---------------- */
    public function summary()
    {
        $rows = $this->currentProfiles();
        $out = array('total' => count($rows), 'classification_required' => 0, 'active' => 0, 'commission_eligible' => 0);
        foreach ($rows as $r) {
            if ((int) $r->classification_required === 1) { $out['classification_required']++; }
            if ($r->status === 'active') { $out['active']++; }
            if ((int) $r->commission_eligibility === 1) { $out['commission_eligible']++; }
        }
        return $out;
    }

    public function byType()
    {
        $rows = $this->currentProfiles();
        $out = array();
        foreach ($rows as $r) { $t = $r->employment_type; $out[$t] = (isset($out[$t]) ? $out[$t] : 0) + 1; }
        return $out;
    }


    /* ================= Batch 2: activity + performance ================= */

    private function actTable()  { return db_prefix() . 'payplex_staff_activity'; }
    private function kpiTable()  { return db_prefix() . 'payplex_staff_kpi_defs'; }
    private function perfTable() { return db_prefix() . 'payplex_staff_performance'; }

    /** Which KPI metric (if any) an activity event feeds, and how. */
    private static function metricForEvent($eventType)
    {
        switch ($eventType) {
            case 'conversion':       return array('metric' => 'conversions',     'use_value' => false);
            case 'collection':       return array('metric' => 'amount_collected','use_value' => true);
            case 'manager_verified': return array('metric' => 'tasks_completed', 'use_value' => false);
            case 'visit_verified':   return array('metric' => 'verified_visits', 'use_value' => false);
            /*
             * M8-003. A field visit is logged as 'customer_visit' and is then
             * VERIFIED by setting the row's `verified` column — that is what the
             * verification workflow does. Scoring, however, read only the event
             * TYPE, and 'customer_visit' was absent from this map, so a visit
             * somebody had independently confirmed still scored nothing. The
             * only scoreable spelling was a separate 'visit_verified' event that
             * nothing writes.
             *
             * Two mechanisms modelled the same distinction — an event type and a
             * column — and the scoring path read the one the UI does not write.
             * Mapping it here is safe: every record built from this map carries
             * its own verified/excluded flags, and metricEvidence() counts only
             * verified, non-excluded ones. An unconfirmed visit still scores
             * nothing.
             */
            case 'customer_visit':   return array('metric' => 'verified_visits', 'use_value' => false);
            case 'proposal':         return array('metric' => 'proposals',       'use_value' => false);
            case 'meeting':          return array('metric' => 'meetings',        'use_value' => false);
            case 'lead_accepted':    return array('metric' => 'leads_qualified', 'use_value' => false);
            default:                 return null;
        }
    }

    /** Log a legitimate business event for a staff member. */
    public function logActivity($staffId, $eventType, $opts = array())
    {
        if (!$this->db->table_exists($this->actTable())) { return 0; }
        $type = Payplex_staff_activity::normalizeEvent($eventType);
        $now  = date('Y-m-d H:i:s');

        /*
         * Backstop, independent of the controller's check.
         *
         * The capability half of the rule belongs to the caller — the model has
         * no session. The beneficiary half is pure arithmetic on two integers,
         * so it is enforced here as well: whatever route reaches this method,
         * nobody's own record arrives verified. Defence in depth is cheap when
         * the check is a comparison.
         */
        $verified = isset($opts['verified']) ? (int) $opts['verified'] : 0;
        $actorId  = isset($opts['actor_id']) ? (int) $opts['actor_id'] : 0;
        if ($verified === 1 && ($actorId <= 0 || $actorId === (int) $staffId)) {
            $verified = 0;
        }

        $this->db->insert($this->actTable(), array(
            'staff_id'    => (int) $staffId,
            'event_type'  => $type,
            'category'    => Payplex_staff_activity::category($type),
            'ref_type'    => isset($opts['ref_type']) ? substr((string) $opts['ref_type'], 0, 40) : null,
            'ref_id'      => isset($opts['ref_id']) && $opts['ref_id'] !== '' ? (int) $opts['ref_id'] : null,
            'verified'    => $verified,
            'excluded'    => isset($opts['excluded']) ? (int) $opts['excluded'] : 0,
            'quality'     => isset($opts['quality']) && $opts['quality'] !== '' ? (float) $opts['quality'] : null,
            'value'       => isset($opts['value']) && $opts['value'] !== '' ? (float) $opts['value'] : null,
            'meta_json'   => isset($opts['meta']) ? json_encode($opts['meta']) : null,
            'occurred_at' => isset($opts['occurred_at']) && $opts['occurred_at'] ? date('Y-m-d H:i:s', strtotime($opts['occurred_at'])) : $now,
            'created_by'  => isset($opts['actor_id']) ? (int) $opts['actor_id'] : 0,
            'datecreated' => $now,
        ));
        return $this->db->insert_id();
    }

    /**
     * Verify an existing activity record — the second half of the workflow.
     *
     * Without this there was no way for anyone to verify anything except at the
     * moment of creation, which is precisely why the verify capability went
     * unused and the create form carried a Verified dropdown. The agent logs
     * the visit; someone holding 'verify' confirms it; only then does it score.
     *
     * The verifier may be neither the subject nor the author of the record.
     * The subject rule is the one that matters for scoring; the author rule is
     * the maker-checker rule the commission module already applies, and it
     * stops the loophole where A logs B's activity and B logs A's.
     *
     * There is no verified_by column on this table and adding one is a schema
     * change this patch does not need, so the verifier and the timestamp are
     * merged into meta_json and written to the audit log, which is where an
     * approval belongs anyway.
     *
     * @return array ok => bool, refusal => null|string
     */
    public function verifyActivity($activityId, $actorId, $canVerify)
    {
        $activityId = (int) $activityId;
        $actorId    = (int) $actorId;

        if (!$canVerify)             { return array('ok' => false, 'refusal' => 'not_permitted'); }
        if ($actorId <= 0)           { return array('ok' => false, 'refusal' => 'unknown_actor'); }
        if (!$this->db->table_exists($this->actTable())) { return array('ok' => false, 'refusal' => 'not_found'); }

        $row = $this->db->where('id', $activityId)->get($this->actTable())->row();
        if (!$row)                              { return array('ok' => false, 'refusal' => 'not_found'); }
        if ((int) $row->verified === 1)         { return array('ok' => false, 'refusal' => 'already_verified'); }
        if ((int) $row->staff_id === $actorId)  { return array('ok' => false, 'refusal' => 'self_verification'); }
        if ((int) $row->created_by === $actorId) { return array('ok' => false, 'refusal' => 'own_entry'); }

        $meta = array();
        if (!empty($row->meta_json)) {
            $decoded = json_decode($row->meta_json, true);
            if (is_array($decoded)) { $meta = $decoded; }
        }
        $meta['verified_by'] = $actorId;
        $meta['verified_at'] = date('Y-m-d H:i:s');

        $this->db->where('id', $activityId)
                 ->where('verified', 0)          // idempotent: a second click changes nothing
                 ->update($this->actTable(), array('verified' => 1, 'meta_json' => json_encode($meta)));

        if ($this->db->affected_rows() < 1) {
            return array('ok' => false, 'refusal' => 'already_verified');
        }

        $this->audit((int) $row->staff_id, 'activity_verified',
            'Activity #' . $activityId . ' (' . $row->event_type . ') verified',
            array('activity_id' => $activityId, 'event_type' => $row->event_type,
                  'created_by' => (int) $row->created_by),
            $actorId);

        return array('ok' => true, 'refusal' => null);
    }

    public function activityFor($staffId, $from = null, $to = null, $limit = 1000)
    {
        if (!$this->db->table_exists($this->actTable())) { return array(); }
        $this->db->where('staff_id', (int) $staffId);
        if ($from) { $this->db->where('occurred_at >=', date('Y-m-d 00:00:00', strtotime($from))); }
        if ($to)   { $this->db->where('occurred_at <=', date('Y-m-d 23:59:59', strtotime($to))); }
        return $this->db->order_by('occurred_at', 'DESC')->limit((int) $limit)->get($this->actTable())->result();
    }

    private function activityArrays($staffId, $from = null, $to = null)
    {
        $out = array();
        foreach ($this->activityFor($staffId, $from, $to) as $r) {
            $out[] = array(
                'event_type' => $r->event_type, 'occurred_at' => $r->occurred_at,
                'verified' => (int) $r->verified, 'excluded' => (int) $r->excluded,
                'value' => $r->value !== null ? (float) $r->value : null,
            );
        }
        return $out;
    }

    public function timelineFor($staffId, $period = 'daily', $from = null, $to = null)
    {
        return Payplex_staff_activity::timeline($this->activityArrays($staffId, $from, $to), $period);
    }

    public function activitySummary($staffId, $from = null, $to = null)
    {
        return Payplex_staff_activity::aggregate($this->activityArrays($staffId, $from, $to));
    }

    /* ---- KPI definitions ---- */
    public function kpiDefs($role = null, $activeOnly = false)
    {
        if (!$this->db->table_exists($this->kpiTable())) { return array(); }
        if ($role !== null && $role !== '') { $this->db->group_start()->where('role', $role)->or_where('role', null)->or_where('role', '')->group_end(); }
        if ($activeOnly) { $this->db->where('active', 1); }
        return $this->db->order_by('metric', 'ASC')->get($this->kpiTable())->result();
    }

    private function kpiDefArrays($role = null)
    {
        $out = array();
        foreach ($this->kpiDefs($role, true) as $d) {
            $out[] = array(
                'role' => $d->role, 'metric' => $d->metric, 'weight' => (float) $d->weight,
                'cap' => $d->cap !== null ? (float) $d->cap : null,
                'target' => $d->target !== null ? (float) $d->target : null,
                'penalty' => (float) $d->penalty,
                'effective_from' => $d->effective_from, 'effective_to' => $d->effective_to,
                'active' => (int) $d->active,
            );
        }
        return $out;
    }

    public function saveKpiDef($data, $actorId)
    {
        $v = Payplex_staff_kpi::validateDef($data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }
        $e = $v['entry']; $now = date('Y-m-d H:i:s');
        $row = array(
            'role' => $e['role'] !== '' ? $e['role'] : null, 'metric' => $e['metric'], 'weight' => $e['weight'],
            'cap' => $e['cap'], 'target' => $e['target'], 'penalty' => $e['penalty'],
            'effective_from' => $e['effective_from'], 'effective_to' => $e['effective_to'], 'active' => $e['active'],
        );
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id > 0) { $this->db->where('id', $id)->update($this->kpiTable(), $row); }
        else { $row['created_by'] = (int) $actorId; $row['datecreated'] = $now; $this->db->insert($this->kpiTable(), $row); $id = $this->db->insert_id(); }
        $this->audit(null, 'kpi_def', 'KPI definition saved: ' . $e['metric'] . ' (w=' . $e['weight'] . ')', array('kpi_id' => $id), $actorId);
        return array('ok' => true, 'kpi_id' => $id);
    }

    public function deleteKpiDef($id, $actorId)
    {
        if (!$this->db->table_exists($this->kpiTable())) { return; }
        $this->db->where('id', (int) $id)->delete($this->kpiTable());
        $this->audit(null, 'kpi_def', 'KPI definition deleted #' . (int) $id, array(), $actorId);
    }

    /** Seed a sensible default scorecard once (guarded by a setting). */
    public function seedKpiDefaults($actorId = 0)
    {
        if (!$this->db->table_exists($this->kpiTable())) { return 0; }
        if ((int) $this->getSetting('kpi_seeded', 0) === 1) { return 0; }
        $defaults = array(
            array('metric' => 'conversions',        'weight' => 0.30, 'target' => 5),
            array('metric' => 'amount_collected',   'weight' => 0.25, 'target' => 100000),
            array('metric' => 'tasks_completed',    'weight' => 0.20, 'target' => 20),
            array('metric' => 'sla_compliance',     'weight' => 0.15, 'target' => 1),
            array('metric' => 'attendance_compliance','weight' => 0.10, 'target' => 1),
        );
        $now = date('Y-m-d H:i:s'); $n = 0;
        foreach ($defaults as $d) {
            $exists = $this->db->where('metric', $d['metric'])->where('role', null)->get($this->kpiTable())->row();
            if ($exists) { continue; }
            $this->db->insert($this->kpiTable(), array_merge($d, array('penalty' => 0, 'active' => 1, 'created_by' => (int) $actorId, 'datecreated' => $now)));
            $n++;
        }
        $this->setSetting('kpi_seeded', 1);
        if ($n > 0) { $this->audit(null, 'kpi_def', 'Seeded ' . $n . ' default KPI definitions', array('count' => $n), $actorId); }
        return $n;
    }

    /**
     * Compute a staff member's performance from VERIFIED activity only.
     * Cancelled/duplicate/test/self-approved rows are excluded. No verified
     * activity => 0 / no_data (never a guessed default). Optionally stores a
     * snapshot for the period.
     */
    public function computePerformance($staffId, $period = 'monthly', $from = null, $to = null, $store = false)
    {
        $profile = $this->currentProfile($staffId);
        $role = 'employee';
        if ($profile && $profile->employment_type === 'commission_only') { $role = 'commission_employee'; }
        if ($profile && $profile->employment_type === 'manager') { $role = 'manager'; }

        // build KPI records + penalty counts from activity
        $records = array(); $penaltyCounts = array('sla_breaches' => 0, 'rejections' => 0, 'reopened' => 0);
        foreach ($this->activityFor($staffId, $from, $to) as $r) {
            $map = self::metricForEvent($r->event_type);
            if ($map) {
                $records[] = array('metric' => $map['metric'], 'verified' => (int) $r->verified, 'excluded' => (int) $r->excluded,
                    'value' => $map['use_value'] ? ($r->value !== null ? (float) $r->value : 0.0) : 1.0);
            }
            if ((int) $r->verified === 1 && (int) $r->excluded !== 1) {
                if ($r->event_type === 'sla_breach') { $penaltyCounts['sla_breaches']++; }
                if ($r->event_type === 'rejection')  { $penaltyCounts['rejections']++; }
                if ($r->event_type === 'reopened')   { $penaltyCounts['reopened']++; }
            }
        }

        $defs = $this->kpiDefArrays($role);
        $values = array();
        $evidence = array();
        foreach ($defs as $d) {
            $values[$d['metric']]   = Payplex_staff_kpi::metricValue($records, $d['metric']);
            /*
             * Record counts alongside the sums. A sum of 0.0 means both "measured
             * zero" and "no record at all", and score() needs to tell them apart
             * before it is willing to call anybody's performance poor.
             */
            $evidence[$d['metric']] = Payplex_staff_kpi::metricEvidence($records, $d['metric']);
        }
        $result = Payplex_staff_kpi::score($defs, $values, $to, $evidence);

        // penalties from configured penalty defs (metric names sla_breaches/rejections/reopened)
        $penaltyPerUnit = array();
        foreach ($defs as $d) { if (isset($penaltyCounts[$d['metric']]) && $d['penalty'] > 0) { $penaltyPerUnit[$d['metric']] = $d['penalty']; } }
        if ($result['rating'] !== 'no_data' && !empty($penaltyPerUnit)) {
            $result['score'] = Payplex_staff_kpi::applyPenalty($result['score'], $penaltyCounts, $penaltyPerUnit);
            $result['rating'] = Payplex_staff_kpi::rating($result['score']);
        }
        $result['role'] = $role;
        $result['penalty_counts'] = $penaltyCounts;

        if ($store && $this->db->table_exists($this->perfTable())) {
            $key = Payplex_staff_activity::bucketKey($to ? $to : date('Y-m-d'), $period);
            $existing = $this->db->where('staff_id', (int) $staffId)->where('period', $period)->where('period_key', $key)->get($this->perfTable())->row();
            $row = array('staff_id' => (int) $staffId, 'period' => $period, 'period_key' => $key,
                'score' => $result['score'], 'rating' => $result['rating'],
                'components_json' => json_encode($result['components']), 'computed_at' => date('Y-m-d H:i:s'));
            if ($existing) { $this->db->where('id', (int) $existing->id)->update($this->perfTable(), $row); }
            else { $this->db->insert($this->perfTable(), $row); }
        }
        return $result;
    }

    /** Performance board across in-scope staff (admin: all; else scoped). */
    public function performanceBoard($staffId, $isAdmin, $companyView = '', $from = null, $to = null)
    {
        $out = array();
        foreach ($this->currentProfiles(array()) as $p) {
            // reuse profile scoping already applied in currentProfiles
            $perf = $this->computePerformance($p->staff_id, 'monthly', $from, $to, false);
            $out[] = array('profile' => $p, 'perf' => $perf);
        }
        usort($out, function ($a, $b) { return $b['perf']['score'] <=> $a['perf']['score']; });
        return $out;
    }

    /* ================= Batch 3: field tracking, consent & privacy ================= */

    /* ---------------- consent ledger (append-only) ---------------- */

    /** All ledger rows for a staff member, oldest first. */
    public function consentLedger($staffId, $purpose = Payplex_staff_consent::PURPOSE_LOCATION)
    {
        if (!$this->db->table_exists($this->conTable())) { return array(); }
        return $this->db->where('staff_id', (int) $staffId)
            ->where('purpose', Payplex_staff_consent::normalizePurpose($purpose))
            ->order_by('occurred_at', 'ASC')->order_by('id', 'ASC')
            ->get($this->conTable())->result_array();
    }

    /** Is location consent active right now? Never assumed — always derived. */
    public function hasConsent($staffId, $purpose = Payplex_staff_consent::PURPOSE_LOCATION)
    {
        return Payplex_staff_consent::isGranted($this->consentLedger($staffId, $purpose), $purpose);
    }

    public function consentStatus($staffId, $purpose = Payplex_staff_consent::PURPOSE_LOCATION)
    {
        return Payplex_staff_consent::status($this->consentLedger($staffId, $purpose), $purpose);
    }

    /**
     * Append a consent action. Returns the new row id, or 0 when the action was a
     * no-op (already in that state). Never updates or deletes an existing row.
     */
    public function recordConsent($staffId, $action, $opts = array(), $purpose = Payplex_staff_consent::PURPOSE_LOCATION)
    {
        if (!$this->db->table_exists($this->conTable())) { return 0; }

        $ledger = $this->consentLedger($staffId, $purpose);
        if (!isset($opts['ip']) && isset($this->input))         { $opts['ip'] = $this->input->ip_address(); }
        if (!isset($opts['user_agent']) && isset($this->input)) { $opts['user_agent'] = $this->input->user_agent(); }

        $row = Payplex_staff_consent::buildRow($ledger, $staffId, $action, $opts, $purpose);
        if ($row === null) { return 0; }

        $this->db->insert($this->conTable(), $row);
        $id = (int) $this->db->insert_id();

        $this->audit($staffId, 'consent_' . $row['action'],
            'Location consent ' . $row['action'] . ' (policy v' . $row['policy_version'] . ')',
            array('purpose' => $row['purpose'], 'policy_version' => $row['policy_version'], 'consent_id' => $id),
            (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0));

        // Withdrawal must take effect immediately: close any session still open,
        // otherwise the open-session gate would keep letting points through.
        if ($row['action'] === Payplex_staff_consent::ACTION_WITHDRAW) {
            $this->closeOpenSessionsFor($staffId, 'consent_withdrawn',
                (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0));
        }
        return $id;
    }

    /* ---------------- field sessions ---------------- */

    public function openSession($staffId)
    {
        if (!$this->db->table_exists($this->fsTable())) { return null; }
        $r = $this->db->where('staff_id', (int) $staffId)->where('status', 'open')
            ->order_by('id', 'DESC')->limit(1)->get($this->fsTable())->row_array();
        return $r ?: null;
    }

    public function fieldSession($id)
    {
        if (!$this->db->table_exists($this->fsTable())) { return null; }
        $r = $this->db->where('id', (int) $id)->get($this->fsTable())->row_array();
        return $r ?: null;
    }

    public function fieldSessions($staffId = 0, $limit = 200)
    {
        if (!$this->db->table_exists($this->fsTable())) { return array(); }
        if ($staffId) { $this->db->where('staff_id', (int) $staffId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)
            ->get($this->fsTable())->result_array();
    }

    /**
     * Start a field visit. Refused without active consent — this is the gate that
     * makes "opt-in" real rather than cosmetic. One open session per staff member.
     *
     * @return array ok, session_id, reason
     */
    public function checkIn($staffId, $opts = array())
    {
        $staffId = (int) $staffId;
        if (!$this->db->table_exists($this->fsTable())) {
            return array('ok' => false, 'code' => 'no_table', 'reason' => 'Field tracking is not installed.');
        }
        $ledger = $this->consentLedger($staffId);
        if (!Payplex_staff_consent::isGranted($ledger)) {
            return array('ok' => false, 'code' => 'no_consent',
                'reason' => 'This staff member has not given location consent, or has withdrawn it.');
        }
        if ($this->openSession($staffId)) {
            return array('ok' => false, 'code' => 'already_open',
                'reason' => 'A field session is already open. Check out of it first.');
        }

        $lat = isset($opts['lat']) ? $opts['lat'] : null;
        $lng = isset($opts['lng']) ? $opts['lng'] : null;
        if ($lat !== null && $lat !== '' && $lng !== null && $lng !== '') {
            $v = Payplex_staff_geo::validatePoint(array(
                'lat' => $lat, 'lng' => $lng,
                'accuracy_m' => isset($opts['accuracy_m']) ? $opts['accuracy_m'] : null,
            ));
            if (!$v['ok']) { return array('ok' => false, 'code' => $v['code'], 'reason' => $v['reason']); }
        } else {
            $lat = null; $lng = null;
        }

        $latest = Payplex_staff_consent::latest($ledger);
        $now    = date('Y-m-d H:i:s');

        $this->db->trans_start();
        $this->db->insert($this->fsTable(), array(
            'staff_id'    => $staffId,
            'status'      => 'open',
            'purpose'     => isset($opts['purpose']) ? substr((string) $opts['purpose'], 0, 60) : null,
            'ref_type'    => isset($opts['ref_type']) ? substr((string) $opts['ref_type'], 0, 40) : null,
            'ref_id'      => isset($opts['ref_id']) && $opts['ref_id'] !== '' ? (int) $opts['ref_id'] : null,
            'consent_id'  => (int) (isset($latest['id']) ? $latest['id'] : 0),
            'started_at'  => $now,
            'start_lat'   => $lat,
            'start_lng'   => $lng,
            'created_by'  => (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0),
            'datecreated' => $now,
        ));
        $sid = (int) $this->db->insert_id();

        // The opening fix is itself a location point, subject to the same retention.
        if ($sid && $lat !== null) {
            $this->db->insert($this->locTable(), array(
                'session_id'  => $sid,
                'staff_id'    => $staffId,
                'lat'         => $lat,
                'lng'         => $lng,
                'accuracy_m'  => isset($opts['accuracy_m']) && $opts['accuracy_m'] !== '' ? (int) $opts['accuracy_m'] : null,
                'source'      => 'checkin',
                'captured_at' => $now,
                'datecreated' => $now,
            ));
            $this->db->where('id', $sid)->update($this->fsTable(), array('point_count' => 1));
        }
        $this->db->trans_complete();

        if ($sid) {
            $this->logActivity($staffId, 'customer_visit', array(
                'ref_type' => 'field_session', 'ref_id' => $sid, 'verified' => 0,
                'occurred_at' => $now, 'actor_id' => (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0),
            ));
        }
        return array('ok' => (bool) $sid, 'code' => $sid ? 'ok' : 'insert_failed',
            'session_id' => $sid, 'reason' => $sid ? '' : 'Could not open the session.');
    }

    /**
     * Record a location point. Every write passes the consent + open-session gate,
     * server-side, regardless of what the caller claims.
     */
    public function recordLocation($staffId, $point, $actorId = 0)
    {
        $staffId = (int) $staffId;
        if (!$this->db->table_exists($this->locTable())) {
            return array('ok' => false, 'code' => 'no_table', 'reason' => 'Field tracking is not installed.');
        }
        $session = $this->openSession($staffId);
        $gate    = Payplex_staff_consent::gate($this->consentLedger($staffId), $session);
        if (!$gate['allowed']) {
            return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']);
        }
        $v = Payplex_staff_geo::validatePoint($point);
        if (!$v['ok']) { return array('ok' => false, 'code' => $v['code'], 'reason' => $v['reason']); }

        $p   = (array) $point;
        $now = date('Y-m-d H:i:s');
        $cap = isset($p['captured_at']) && $p['captured_at'] !== '' ? (string) $p['captured_at'] : $now;

        $this->db->trans_start();
        $this->db->insert($this->locTable(), array(
            'session_id'  => (int) $session['id'],
            'staff_id'    => $staffId,
            'lat'         => $p['lat'],
            'lng'         => $p['lng'],
            'accuracy_m'  => isset($p['accuracy_m']) && $p['accuracy_m'] !== '' ? (int) $p['accuracy_m'] : null,
            'source'      => isset($p['source']) ? substr((string) $p['source'], 0, 20) : 'app',
            'captured_at' => $cap,
            'datecreated' => $now,
        ));
        $id = (int) $this->db->insert_id();
        $this->db->where('id', (int) $session['id'])
            ->set('point_count', 'point_count + 1', false)
            ->update($this->fsTable());
        $this->db->trans_complete();

        return array('ok' => (bool) $id, 'code' => $id ? 'ok' : 'insert_failed',
            'location_id' => $id, 'session_id' => (int) $session['id'], 'reason' => '');
    }

    /** Points for a session, oldest first. Empty once purged. */
    public function sessionPoints($sessionId)
    {
        if (!$this->db->table_exists($this->locTable())) { return array(); }
        return $this->db->where('session_id', (int) $sessionId)
            ->order_by('captured_at', 'ASC')->order_by('id', 'ASC')
            ->get($this->locTable())->result_array();
    }

    /**
     * End a field visit and freeze its summary. The aggregate is computed here,
     * while the raw points still exist, so the 90-day purge can never change a
     * distance an expense claim was based on.
     */
    public function checkOut($staffId, $opts = array())
    {
        $staffId = (int) $staffId;
        $session = $this->openSession($staffId);
        if (!$session) {
            return array('ok' => false, 'code' => 'no_session', 'reason' => 'No open field session to check out of.');
        }
        return $this->closeSession((int) $session['id'], 'closed', $opts);
    }

    /** Close one session, computing and storing its durable summary. */
    public function closeSession($sessionId, $status = 'closed', $opts = array())
    {
        $sessionId = (int) $sessionId;
        $session   = $this->fieldSession($sessionId);
        if (!$session) {
            return array('ok' => false, 'code' => 'not_found', 'reason' => 'Session not found.');
        }
        if ((string) $session['status'] !== 'open') {
            return array('ok' => false, 'code' => 'already_closed', 'reason' => 'Session is already closed.');
        }

        $now = date('Y-m-d H:i:s');
        $lat = isset($opts['lat']) ? $opts['lat'] : null;
        $lng = isset($opts['lng']) ? $opts['lng'] : null;

        $this->db->trans_start();

        // A closing fix counts as a point too — but only while consent still holds.
        if ($lat !== null && $lat !== '' && $lng !== null && $lng !== ''
            && Payplex_staff_consent::isGranted($this->consentLedger((int) $session['staff_id']))) {
            $v = Payplex_staff_geo::validatePoint(array('lat' => $lat, 'lng' => $lng,
                'accuracy_m' => isset($opts['accuracy_m']) ? $opts['accuracy_m'] : null));
            if ($v['ok']) {
                $this->db->insert($this->locTable(), array(
                    'session_id'  => $sessionId,
                    'staff_id'    => (int) $session['staff_id'],
                    'lat'         => $lat,
                    'lng'         => $lng,
                    'accuracy_m'  => isset($opts['accuracy_m']) && $opts['accuracy_m'] !== '' ? (int) $opts['accuracy_m'] : null,
                    'source'      => 'checkout',
                    'captured_at' => $now,
                    'datecreated' => $now,
                ));
            }
        }

        $session['ended_at'] = $now;
        $summary = Payplex_staff_geo::summarise($session, $this->sessionPoints($sessionId));

        /*
         * Store how the distance was arrived at, not just what it came to.
         *
         * summarise() has always returned legs, skipped_jitter and
         * skipped_implausible; this method used to discard all three. That made
         * a session where the engine refused to believe most of the trail look
         * identical on screen to a clean one — same distance field, same point
         * count, no hint that anything had been thrown away. Since the summary
         * is deliberately frozen against later recomputation, the row has to
         * carry its own provenance or nobody can ever check it again.
         */
        $update = array(
            'status'      => $status === 'abandoned' ? 'abandoned' : 'closed',
            'ended_at'    => $now,
            'end_lat'     => $summary['end_lat'],
            'end_lng'     => $summary['end_lng'],
            'point_count' => $summary['point_count'],
            'distance_m'  => $summary['distance_m'],
            'duration_s'  => $summary['duration_s'],
        );
        foreach ($this->provenanceColumns($summary) as $col => $val) { $update[$col] = $val; }
        $this->db->where('id', $sessionId)->update($this->fsTable(), $update);
        $this->db->trans_complete();

        $this->audit((int) $session['staff_id'], 'field_session_closed',
            'Field session #' . $sessionId . ' ' . ($status === 'abandoned' ? 'auto-closed' : 'closed')
            . ' — ' . Payplex_staff_geo::formatDistance($summary['distance_m']),
            array('session_id' => $sessionId, 'distance_m' => $summary['distance_m'],
                  'duration_s' => $summary['duration_s'], 'points' => $summary['point_count'],
                  'reason' => isset($opts['reason']) ? $opts['reason'] : null),
            (int) (isset($opts['actor_id']) ? $opts['actor_id'] : 0));

        return array('ok' => true, 'code' => 'ok', 'session_id' => $sessionId,
            'summary' => $summary, 'reason' => '');
    }

    /**
     * The provenance columns, included only where the schema actually has them.
     *
     * The migration is additive and runs on admin_init, so there is a window on
     * any given install where the code is new and the columns are not there
     * yet — and a check-out that fails because of an audit improvement would be
     * a worse bug than the one being fixed. Six wrong column names on this
     * project were caught by guards like this one; the seventh was not guessed
     * at all because of it.
     */
    private function provenanceColumns($summary)
    {
        $out = array();
        $map = array(
            'legs_counted'        => isset($summary['legs']) ? (int) $summary['legs'] : 0,
            'skipped_jitter'      => isset($summary['skipped_jitter']) ? (int) $summary['skipped_jitter'] : 0,
            'skipped_implausible' => isset($summary['skipped_implausible']) ? (int) $summary['skipped_implausible'] : 0,
            'engine_version'      => Payplex_staff_geo::DISTANCE_ENGINE_VERSION,
        );
        foreach ($map as $col => $val) {
            if ($this->db->field_exists($col, $this->fsTable())) { $out[$col] = $val; }
        }
        return $out;
    }

    /**
     * Re-run the current distance rules over a closed session's surviving points
     * and report whether they agree with the frozen figure.
     *
     * This deliberately does NOT overwrite the stored distance. The freeze is
     * the whole guarantee: a number an expense claim was based on must not
     * change underneath the claim, not even to become more correct. What was
     * missing was any way to find out that it disagrees — so the comparison is
     * computed, returned, and written to the audit log, and a human decides.
     *
     * Once the 90-day purge has taken the points, there is nothing left to
     * re-verify against, and saying so is the honest answer.
     *
     * @return array ok, code, stored, recomputed, agrees, reason
     */
    public function reverifySession($sessionId, $actorId = 0)
    {
        $sessionId = (int) $sessionId;
        $session   = $this->fieldSession($sessionId);
        if (!$session) {
            return array('ok' => false, 'code' => 'not_found', 'reason' => 'Session not found.');
        }
        if ((string) $session['status'] === 'open') {
            return array('ok' => false, 'code' => 'still_open',
                'reason' => 'This session has not been closed yet, so there is no frozen summary to check.');
        }

        $points = $this->sessionPoints($sessionId);
        if (!$points) {
            return array('ok' => false, 'code' => 'points_purged',
                'reason' => 'The raw points for this session have been deleted under the retention policy, '
                          . 'so the stored summary can no longer be checked against them.');
        }

        $recomputed = Payplex_staff_geo::summarise($session, $points);
        $stored     = array(
            'distance_m'  => (int) $session['distance_m'],
            'point_count' => (int) $session['point_count'],
            'duration_s'  => (int) $session['duration_s'],
        );
        $agrees = ((int) $recomputed['distance_m'] === $stored['distance_m']);

        $this->audit((int) $session['staff_id'], 'field_session_reverified',
            'Field session #' . $sessionId . ' re-checked against the current distance rules — '
            . ($agrees ? 'agrees' : 'DISAGREES')
            . ' (stored ' . $stored['distance_m'] . ' m, recomputed ' . (int) $recomputed['distance_m'] . ' m)',
            array('session_id' => $sessionId, 'stored' => $stored,
                  'recomputed_distance_m' => (int) $recomputed['distance_m'],
                  'legs' => (int) $recomputed['legs'],
                  'skipped_implausible' => (int) $recomputed['skipped_implausible'],
                  'engine_version' => Payplex_staff_geo::DISTANCE_ENGINE_VERSION,
                  'agrees' => $agrees),
            (int) $actorId);

        return array('ok' => true, 'code' => 'ok', 'stored' => $stored,
            'recomputed' => $recomputed, 'agrees' => $agrees, 'reason' => '');
    }

    /**
     * What is still held about someone whose consent is no longer active.
     *
     * Withdrawal stops collection and closes any open session. It deletes
     * nothing — the points already gathered stay until the retention purge
     * reaches them, which can be up to ninety days later. That may well be the
     * right policy, but a consent screen that says "consent withdrawn" and
     * shows nothing else lets a person believe their location history is gone.
     * This is what makes the screen able to tell them the truth.
     */
    public function heldAfterWithdrawal($staffId)
    {
        $staffId = (int) $staffId;
        $out = array('points' => 0, 'sessions' => 0, 'oldest' => null, 'newest' => null,
                     'erase_by' => null, 'retention_days' => Payplex_staff_geo::retentionDays());
        if (!$this->db->table_exists($this->locTable())) { return $out; }

        $r = $this->db->select('COUNT(*) AS n, COUNT(DISTINCT session_id) AS s,
                                MIN(captured_at) AS oldest, MAX(captured_at) AS newest', false)
            ->where('staff_id', $staffId)->get($this->locTable())->row();
        if ($r) {
            $out['points']   = (int) $r->n;
            $out['sessions'] = (int) $r->s;
            $out['oldest']   = $r->oldest;
            $out['newest']   = $r->newest;
            if ($r->newest) {
                /* the last point to go is the newest one */
                $out['erase_by'] = date('Y-m-d',
                    strtotime($r->newest) + (Payplex_staff_geo::retentionDays() * 86400));
            }
        }
        return $out;
    }

    /** Close every open session for a staff member (used on consent withdrawal). */
    public function closeOpenSessionsFor($staffId, $reason = '', $actorId = 0)
    {
        if (!$this->db->table_exists($this->fsTable())) { return 0; }
        $rows = $this->db->where('staff_id', (int) $staffId)->where('status', 'open')
            ->get($this->fsTable())->result_array();
        $n = 0;
        foreach ($rows as $r) {
            $res = $this->closeSession((int) $r['id'], 'closed',
                array('reason' => $reason, 'actor_id' => $actorId));
            if (!empty($res['ok'])) { $n++; }
        }
        return $n;
    }

    /** Sweep sessions left open past the safety limit. */
    public function closeAbandonedSessions($actorId = 0)
    {
        if (!$this->db->table_exists($this->fsTable())) { return 0; }
        $rows = $this->db->where('status', 'open')->get($this->fsTable())->result_array();
        $n = 0;
        foreach ($rows as $r) {
            if (!Payplex_staff_geo::isAbandoned($r)) { continue; }
            $res = $this->closeSession((int) $r['id'], 'abandoned',
                array('reason' => 'exceeded ' . Payplex_staff_geo::MAX_SESSION_HOURS . 'h limit', 'actor_id' => $actorId));
            if (!empty($res['ok'])) { $n++; }
        }
        return $n;
    }

    /* ---------------- retention ---------------- */

    /**
     * Delete raw GPS points older than the retention window. Session summaries are
     * deliberately left intact, and every run is written to an immutable log so the
     * deletion itself is auditable.
     */
    public function purgeExpiredLocations($actorId = 0, $now = null)
    {
        if (!$this->db->table_exists($this->locTable())) {
            return array('ok' => false, 'deleted' => 0, 'cutoff' => null, 'reason' => 'Not installed.');
        }
        $cutoff = Payplex_staff_geo::purgeCutoff($now);

        $doomed = $this->db->select('id, session_id')
            ->where('captured_at <', $cutoff . ' 00:00:00')
            ->get($this->locTable())->result_array();

        if (!$doomed) {
            /*
             * A no-op is worth recording once a day, not once a cron pass.
             *
             * The privacy log exists so that a deletion of someone's location
             * history is auditable. On this install the cron fires every five
             * to fifteen minutes and every pass wrote "No points due for
             * deletion" — 29 rows in four hours, roughly 150 a day, every one
             * of them saying nothing happened. At that rate the first real
             * deletion would arrive as row 50,000 in a log of noise, which is
             * the same as not logging it.
             *
             * The reason no-ops were logged at all is a good one and is kept:
             * a job that never runs looks exactly like a job with nothing to
             * do, so silence must not be the only evidence. One heartbeat per
             * day preserves that — a missing day is still visible — while
             * every actual deletion is logged unconditionally, below.
             */
            $logged = $this->noopPurgeLoggedToday();
            if (!$logged) {
                $this->privacyLog('retention_purge', null, $actorId, 0, $cutoff,
                    'No points due for deletion. (Daily heartbeat; the purge runs every cron pass.)');
            }
            return array('ok' => true, 'deleted' => 0, 'sessions' => 0, 'cutoff' => $cutoff,
                'logged' => !$logged, 'reason' => '');
        }

        $sessions = array();
        foreach ($doomed as $d) { $sessions[(int) $d['session_id']] = true; }

        $this->db->trans_start();
        $this->db->where('captured_at <', $cutoff . ' 00:00:00')->delete($this->locTable());
        $deleted = (int) $this->db->affected_rows();

        /*
         * affected_rows() returns -1 when the statement failed. Treating that as
         * a count would write "Purged -1 points" into the immutable privacy log
         * and return ok — a deletion that did not happen, recorded as one that
         * did. This project has already had two migrations saved by checking
         * for -1 rather than trusting it as a zero.
         */
        if ($deleted < 0) {
            $this->db->trans_rollback();
            $this->privacyLog('retention_purge_failed', null, $actorId, 0, $cutoff,
                'Purge FAILED: the delete statement did not report a row count. Nothing was deleted.');
            return array('ok' => false, 'deleted' => 0, 'sessions' => 0, 'cutoff' => $cutoff,
                'reason' => 'The purge statement failed; no points were deleted.');
        }

        // Mark the sessions whose raw trail is gone, so the UI can be honest about
        // showing a summary without a map rather than implying the points are lost.
        foreach (array_keys($sessions) as $sid) {
            $remaining = (int) $this->db->where('session_id', $sid)->count_all_results($this->locTable());
            if ($remaining === 0) {
                $this->db->where('id', $sid)->update($this->fsTable(), array(
                    'points_purged' => 1, 'purged_at' => date('Y-m-d H:i:s'),
                ));
            }
        }
        $this->db->trans_complete();

        $this->privacyLog('retention_purge', null, $actorId, $deleted, $cutoff,
            'Purged ' . $deleted . ' raw location points captured before ' . $cutoff . '.',
            array('sessions_affected' => count($sessions)));

        return array('ok' => true, 'deleted' => $deleted, 'sessions' => count($sessions),
            'cutoff' => $cutoff, 'reason' => '');
    }

    /** Has a no-op purge already been recorded today? */
    private function noopPurgeLoggedToday()
    {
        if (!$this->db->table_exists($this->plogTable())) { return true; }
        return (int) $this->db->where('event_type', 'retention_purge')
            ->where('affected_rows', 0)
            ->where('occurred_at >=', date('Y-m-d 00:00:00'))
            ->count_all_results($this->plogTable()) > 0;
    }

    /** How much raw data is currently held, and how much is due for deletion. */
    public function retentionStats($now = null)
    {
        $out = array(
            'retention_days' => Payplex_staff_geo::retentionDays(),
            'cutoff'         => Payplex_staff_geo::purgeCutoff($now),
            'points_total'   => 0, 'points_due' => 0,
            'sessions_open'  => 0, 'sessions_total' => 0, 'sessions_purged' => 0,
            'consent_active' => 0,
            'oldest_point'   => null,
        );
        if ($this->db->table_exists($this->locTable())) {
            $out['points_total'] = (int) $this->db->count_all_results($this->locTable());
            $out['points_due']   = (int) $this->db->where('captured_at <', $out['cutoff'] . ' 00:00:00')
                ->count_all_results($this->locTable());
            $r = $this->db->select('MIN(captured_at) AS oldest')->get($this->locTable())->row();
            $out['oldest_point'] = $r && $r->oldest ? $r->oldest : null;
        }
        if ($this->db->table_exists($this->fsTable())) {
            $out['sessions_total']  = (int) $this->db->count_all_results($this->fsTable());
            $out['sessions_open']   = (int) $this->db->where('status', 'open')->count_all_results($this->fsTable());
            $out['sessions_purged'] = (int) $this->db->where('points_purged', 1)->count_all_results($this->fsTable());
        }
        if ($this->db->table_exists($this->conTable())) {
            foreach ($this->currentProfiles(array()) as $p) {
                if ($this->hasConsent((int) $p->staff_id)) { $out['consent_active']++; }
            }
        }
        return $out;
    }

    /* ---------------- subject access ---------------- */

    /**
     * Everything the system holds about one person's location, for the DPDP right
     * of access. Reading someone's file is itself logged.
     */
    public function locationDataFor($staffId, $actorId = 0, $log = true)
    {
        $staffId = (int) $staffId;
        $sessions = $this->fieldSessions($staffId, 500);
        $points   = 0;
        foreach ($sessions as $s) { $points += (int) $s['point_count']; }
        $data = array(
            'staff_id'  => $staffId,
            'consent'   => $this->consentStatus($staffId),
            'ledger'    => $this->consentLedger($staffId),
            'sessions'  => $sessions,
            'point_count' => $points,
            'retention_days' => Payplex_staff_geo::retentionDays(),
        );
        if ($log) {
            $this->privacyLog('subject_access', $staffId, $actorId, count($sessions), null,
                'Location record accessed for staff #' . $staffId . '.');
        }
        return $data;
    }

    /* ---------------- privacy log (immutable) ---------------- */

    public function privacyLog($eventType, $staffId, $actorId, $rows = 0, $cutoff = null, $message = '', $data = array())
    {
        if (!$this->db->table_exists($this->plogTable())) { return; }
        $this->db->insert($this->plogTable(), array(
            'event_type'    => substr((string) $eventType, 0, 40),
            'staff_id'      => $staffId ? (int) $staffId : null,
            'actor_id'      => (int) $actorId,
            'affected_rows' => (int) $rows,
            'cutoff_date'   => $cutoff,
            'message'       => substr((string) $message, 0, 500),
            'data_json'     => json_encode($data),
            'occurred_at'   => date('Y-m-d H:i:s'),
        ));
    }

    public function privacyLogEntries($limit = 200)
    {
        if (!$this->db->table_exists($this->plogTable())) { return array(); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)
            ->get($this->plogTable())->result();
    }

    /* ==================================================================== *
     * Compliance documents
     * ==================================================================== */

    private function docTable()    { return db_prefix() . 'wf_documents'; }
    private function docLogTable() { return db_prefix() . 'wf_document_access_log'; }

    /**
     * The directory the files live in, created if it is missing.
     *
     * Two locks, not one. The directory sits outside every document root
     * (Workforce_documents::resolveStorageRoot works that out from FCPATH), and
     * it also carries an .htaccess and an index.html in case somebody later
     * points a vhost at it. The filenames are random on top of that. Any one of
     * the three failing should not expose an Aadhaar scan.
     *
     * @return array path, ready, safe, message
     */
    public function documentStorageRoot()
    {
        $root = Workforce_documents::resolveStorageRoot(FCPATH);
        $safe = Workforce_documents::storageIsSafe($root, FCPATH);

        $out = array('path' => $root, 'ready' => false,
                     'safe' => $safe['safe'], 'message' => $safe['message']);
        if (!$safe['safe']) { return $out; }

        if (!is_dir($root)) { @mkdir($root, 0700, true); }
        if (!is_dir($root)) {
            $out['message'] = 'The storage directory could not be created at ' . $root . '.';
            return $out;
        }
        @file_put_contents($root . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($root . '/index.html', '');

        $out['ready'] = is_writable($root);
        if (!$out['ready']) { $out['message'] = 'The storage directory is not writable.'; }
        return $out;
    }

    public function documentPath($row)
    {
        $root = Workforce_documents::resolveStorageRoot(FCPATH);
        $name = is_array($row) ? $row['stored_name'] : $row->stored_name;
        return $root . '/' . basename((string) $name);
    }

    /** Current documents for one person (superseded versions optional). */
    public function documentsFor($staffId, $includeSuperseded = false)
    {
        $this->db->where('staff_id', (int) $staffId);
        if (!$includeSuperseded) { $this->db->where('is_current', 1); }
        return $this->db->order_by('doc_type ASC, id DESC')->get($this->docTable())->result_array();
    }

    public function documentRow($id)
    {
        $id = (int) $id;
        if ($id <= 0) { return null; }
        return $this->db->where('id', $id)->get($this->docTable())->row_array();
    }

    /**
     * Accept an upload.
     *
     * Order matters: validate, then check the storage is safe, then move, then
     * insert, and only then supersede the previous version. A supersede that
     * runs before the new row exists is how somebody ends up with no current
     * document at all after a failed upload.
     *
     * @return array ok, code, reason, id
     */
    public function storeDocument($staffId, $docType, array $file, array $meta, $actorId)
    {
        $staffId = (int) $staffId;
        $actorId = (int) $actorId;

        if (!Workforce_documents::isType($docType)) {
            return array('ok' => false, 'code' => 'unknown_type',
                         'reason' => 'That is not a document type this system knows.');
        }
        if (!$this->coreStaffMember($staffId)) {
            return array('ok' => false, 'code' => 'no_such_staff',
                         'reason' => 'Staff member #' . $staffId . ' does not exist.');
        }

        $v = Workforce_documents::validateUpload(array(
            'name' => isset($file['name']) ? $file['name'] : '',
            'size' => isset($file['size']) ? $file['size'] : 0,
            'mime' => isset($file['type']) ? $file['type'] : '',
        ));
        if (!$v['ok']) { return array('ok' => false, 'code' => $v['code'], 'reason' => $v['reason']); }

        $store = $this->documentStorageRoot();
        if (!$store['ready']) {
            return array('ok' => false, 'code' => 'storage_unavailable', 'reason' => $store['message']);
        }

        $tmp = isset($file['tmp_name']) ? $file['tmp_name'] : '';
        if ($tmp === '' || !is_file($tmp)) {
            return array('ok' => false, 'code' => 'no_upload', 'reason' => 'No uploaded file was received.');
        }

        $stored = Workforce_documents::storedName($v['extension'], bin2hex(random_bytes(20)));
        $dest   = $store['path'] . '/' . $stored;
        $moved  = @is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dest) : @rename($tmp, $dest);
        if (!$moved) {
            return array('ok' => false, 'code' => 'write_failed', 'reason' => 'The file could not be stored.');
        }
        @chmod($dest, 0600);

        $expires = isset($meta['expires_on']) ? trim((string) $meta['expires_on']) : '';
        $issued  = isset($meta['issued_on'])  ? trim((string) $meta['issued_on'])  : '';
        $isDate  = function ($d) { return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) === 1; };

        $this->db->insert($this->docTable(), array(
            'staff_id'      => $staffId,
            'doc_type'      => $docType,
            'original_name' => substr((string) $file['name'], 0, 255),
            'stored_name'   => $stored,
            'mime'          => substr((string) (isset($file['type']) ? $file['type'] : ''), 0, 100),
            'bytes'         => (int) $file['size'],
            'sha256'        => hash_file('sha256', $dest),
            'issued_on'     => $isDate($issued)  ? $issued  : null,
            'expires_on'    => $isDate($expires) ? $expires : null,
            /* Uploaded is not verified. A document starts as a claim. */
            'status'        => 'pending',
            'uploaded_by'   => $actorId,
            'uploaded_at'   => date('Y-m-d H:i:s'),
            'is_current'    => 1,
        ));
        $id = (int) $this->db->insert_id();
        if ($id <= 0) {
            @unlink($dest);
            return array('ok' => false, 'code' => 'insert_failed', 'reason' => 'The document could not be recorded.');
        }

        /* Supersede the previous current document of this type — keep the row. */
        $this->db->where('staff_id', $staffId)->where('doc_type', $docType)
                 ->where('is_current', 1)->where('id !=', $id)
                 ->update($this->docTable(), array('is_current' => 0, 'replaced_by_id' => $id));
        $superseded = (int) $this->db->affected_rows();

        $this->logDocumentAccess($id, $staffId, $actorId, 'upload',
            Workforce_documents::label($docType) . ($superseded ? ' (replaced a previous version)' : ''));

        return array('ok' => true, 'code' => 'ok', 'reason' => '', 'id' => $id, 'superseded' => $superseded);
    }

    /**
     * Verify or reject. Both go through the same gate, because the separation
     * of duties applies to a rejection exactly as much as to an approval.
     */
    public function decideDocument($id, $decision, $actorId, $hasCapability, $reason = '')
    {
        $row = $this->documentRow($id);
        if (!$row) { return array('ok' => false, 'code' => 'not_found', 'reason' => 'No such document.'); }

        $gate = Workforce_documents::canVerify($row['staff_id'], $actorId, $hasCapability);
        if (!$gate['allowed']) {
            $this->logDocumentAccess((int) $row['id'], (int) $row['staff_id'], (int) $actorId,
                'refused', $gate['code']);
            return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']);
        }
        if (!in_array($decision, array('verified', 'rejected'), true)) {
            return array('ok' => false, 'code' => 'bad_decision', 'reason' => 'Unknown decision.');
        }
        if ($decision === 'rejected' && trim((string) $reason) === '') {
            return array('ok' => false, 'code' => 'reason_required',
                'reason' => 'A rejection needs a reason. "Rejected" with no reason tells the '
                          . 'person nothing about what to send instead.');
        }

        $this->db->where('id', (int) $row['id'])->update($this->docTable(), array(
            'status'           => $decision,
            'verified_by'      => (int) $actorId,
            'verified_at'      => date('Y-m-d H:i:s'),
            'rejection_reason' => $decision === 'rejected' ? substr(trim((string) $reason), 0, 255) : null,
        ));
        $this->logDocumentAccess((int) $row['id'], (int) $row['staff_id'], (int) $actorId,
            $decision, $decision === 'rejected' ? substr(trim((string) $reason), 0, 255) : '');

        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Every read, write and refusal on a document, written before the file is served. */
    public function logDocumentAccess($documentId, $ownerStaffId, $actorId, $action, $detail = '')
    {
        $this->db->insert($this->docLogTable(), array(
            'document_id'    => (int) $documentId,
            'owner_staff_id' => (int) $ownerStaffId,
            'actor_id'       => (int) $actorId,
            'action'         => substr((string) $action, 0, 20),
            'detail'         => substr((string) $detail, 0, 255),
            'ip'             => substr((string) $this->input->ip_address(), 0, 45),
            'occurred_at'    => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function documentAccessLog($documentId = 0, $staffId = 0, $limit = 200)
    {
        if ((int) $documentId > 0) { $this->db->where('document_id', (int) $documentId); }
        if ((int) $staffId > 0)    { $this->db->where('owner_staff_id', (int) $staffId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)
                        ->get($this->docLogTable())->result_array();
    }

    /**
     * One person's compliance picture: what their engagement requires, what
     * they hold, and what is about to lapse.
     */
    public function documentCompliance($staffId)
    {
        $staffId = (int) $staffId;
        /* currentProfile() returns a row OBJECT, not an array. Reading it with
           array syntax would silently yield '' — which requiredFor() would then
           treat as an unclassified person and hand back the provisional list.
           Everybody would look 60% compliant against the wrong requirement. */
        $profile = $this->currentProfile($staffId);
        $type    = ($profile && isset($profile->employment_type)) ? (string) $profile->employment_type : '';

        $required = Workforce_documents::requiredFor($type);
        $docs     = $this->documentsFor($staffId);

        $held = array(); $expiry = array();
        foreach ($docs as $d) {
            $held[$d['doc_type']] = $d['status'];
            $expiry[$d['doc_type']] = Workforce_documents::expiryState(
                $d['doc_type'], $d['expires_on'], date('Y-m-d'));
        }

        return array(
            'employment_type' => $type,
            'provisional'     => Workforce_documents::requirementIsProvisional($type),
            'required'        => $required,
            'documents'       => $docs,
            'expiry'          => $expiry,
            'summary'         => Workforce_documents::completeness($required, $held),
        );
    }

    /** The register: everybody, with what is missing, unverified or lapsing. */
    public function documentComplianceBoard()
    {
        $out = array();
        $staff = $this->db->select('staffid, firstname, lastname, active')
                          ->order_by('staffid', 'ASC')->get(db_prefix() . 'staff')->result_array();
        foreach ($staff as $s) {
            $c = $this->documentCompliance((int) $s['staffid']);
            $lapsing = 0; $expired = 0;
            foreach ($c['expiry'] as $e) {
                if ($e['state'] === 'expiring_soon') { $lapsing++; }
                if ($e['state'] === 'expired')       { $expired++; }
            }
            $out[] = array(
                'staffid'      => (int) $s['staffid'],
                'name'         => trim($s['firstname'] . ' ' . $s['lastname']),
                'crm_active'   => (int) $s['active'],
                'employment'   => $c['employment_type'],
                'provisional'  => $c['provisional'],
                'percent'      => $c['summary']['percent'],
                'missing'      => count($c['summary']['missing']),
                'unverified'   => count($c['summary']['unverified']),
                'rejected'     => count($c['summary']['rejected']),
                'expiring_soon'=> $lapsing,
                'expired'      => $expired,
            );
        }
        return $out;
    }

    /* ==================================================================== *
     * Expense claims
     * ==================================================================== */

    private function ecTable()  { return db_prefix() . 'wf_expense_claims'; }
    private function eeTable()  { return db_prefix() . 'wf_expense_events'; }
    private function baTable()  { return db_prefix() . 'wf_bank_accounts'; }

    /**
     * Every capability this person actually holds, feature-qualified.
     *
     * Asked of Perfex's own staff_can(), which takes a staff id as its third
     * argument — so this is the CRM answering about real permission rows, not a
     * reimplementation that could agree with my expectations and disagree with
     * the application. An administrator is expanded to the full set because
     * is_admin() short-circuits every gate; the maker-checker rules in the
     * library refuse them anyway, which is the point.
     */
    public function capabilitiesFor($staffId)
    {
        $staffId = (int) $staffId;
        $wanted  = Workforce_expense::allCapabilities();
        $out     = array();
        $isAdmin = function_exists('is_admin') && is_admin($staffId);

        foreach ($wanted as $feature => $caps) {
            foreach ($caps as $cap) {
                if ($isAdmin || (function_exists('staff_can') && staff_can($cap, $feature, $staffId))) {
                    $out[] = $feature . ':' . $cap;
                }
            }
        }
        return $out;
    }

    public function claim($id)
    {
        $id = (int) $id;
        if ($id <= 0) { return null; }
        return $this->db->where('id', $id)->get($this->ecTable())->row_array();
    }

    public function claimByRef($ref)
    {
        return $this->db->where('claim_ref', (string) $ref)->get($this->ecTable())->row_array();
    }

    /**
     * @param array $filter claimant_id, state, from, to
     * @param int   $viewerId
     * @param bool  $viewAll
     */
    /**
     * The scope and filter rules, in ONE place.
     *
     * The list and the export must never disagree about who may see what. When
     * each built its own WHERE clause, a change to one silently changed what the
     * other showed - and the one that matters here is `$viewAll`, which is the
     * difference between a person exporting their own claims and exporting
     * everybody's.
     */
    private function applyClaimScope($filter, $viewerId, $viewAll)
    {
        if (!$viewAll) { $this->db->where('claimant_id', (int) $viewerId); }
        elseif (!empty($filter['claimant_id'])) { $this->db->where('claimant_id', (int) $filter['claimant_id']); }

        if (!empty($filter['state']))  { $this->db->where('state', (string) $filter['state']); }
        if (!empty($filter['from']))   { $this->db->where('expense_date >=', (string) $filter['from']); }
        if (!empty($filter['to']))     { $this->db->where('expense_date <=', (string) $filter['to']); }
    }

    public function claims($filter = array(), $viewerId = 0, $viewAll = false)
    {
        $this->applyClaimScope($filter, $viewerId, $viewAll);

        /* The screen caps at 500 because nobody reads page 501. Exports do not
           use this method - they page through everything. */
        $lim = isset($filter['limit']) ? max(1, (int) $filter['limit']) : 500;

        return $this->db->order_by('id', 'DESC')->limit($lim)->get($this->ecTable())->result_array();
    }

    /**
     * One page of claims, ids strictly below $afterId, newest first.
     *
     * Keyset paging, not LIMIT/OFFSET: an offset window shifts when somebody
     * raises or withdraws a claim while the file is being written, and the
     * result is a record emitted twice or missed entirely, with nothing in the
     * output to say so. The primary key does not shift.
     */
    public function claimsPage($filter, $viewerId, $viewAll, $afterId, $limit)
    {
        $this->applyClaimScope((array) $filter, $viewerId, $viewAll);
        if ($afterId !== null) { $this->db->where('id <', (int) $afterId); }

        return $this->db->order_by('id', 'DESC')->limit(max(1, (int) $limit))
                        ->get($this->ecTable())->result_array();
    }

    /** Claims the duplicate check needs to see: this person's, any key. */
    public function claimsOf($claimantId)
    {
        return $this->db->select('id, idempotency_key, state')
            ->where('claimant_id', (int) $claimantId)->get($this->ecTable())->result_array();
    }

    public function nextClaimRef()
    {
        $row = $this->db->select('id')->order_by('id', 'DESC')->limit(1)->get($this->ecTable())->row();
        return 'EXP-' . date('Ym') . '-' . str_pad((string) ((int) ($row ? $row->id : 0) + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * Create a claim.
     *
     * The duplicate check runs twice on purpose: once here, so the person gets
     * a sentence explaining which claim already covers this bill, and once in
     * the database as a UNIQUE index, which is what actually holds when two
     * requests arrive together. The existing payout engine guards duplicates
     * with a `where_not_in` and nothing else — a query two concurrent requests
     * both pass — and that is the gap this closes.
     *
     * @return array ok, code, reason, id, errors
     */
    public function createClaim($post, $claimantId, $actorId, $billDigest = '')
    {
        $claimantId = (int) $claimantId;
        $actorId    = (int) $actorId;

        $v = Workforce_expense::validate($post);
        if (!$v['ok']) {
            return array('ok' => false, 'code' => 'invalid', 'reason' => 'Some details need correcting.',
                         'errors' => $v['errors']);
        }
        if (!$this->coreStaffMember($claimantId)) {
            return array('ok' => false, 'code' => 'no_such_staff',
                         'reason' => 'Staff member #' . $claimantId . ' does not exist.', 'errors' => array());
        }

        $key = Workforce_expense::idempotencyKey($claimantId, $post['category'], $post['amount'],
                                                 $post['expense_date'], $billDigest);
        $dup = Workforce_expense::duplicateOf($key, $this->claimsOf($claimantId));
        if ($dup['duplicate']) {
            return array('ok' => false, 'code' => 'duplicate', 'reason' => $dup['reason'],
                         'errors' => array(), 'of_id' => $dup['of_id']);
        }

        $now = date('Y-m-d H:i:s');
        $ref = $this->nextClaimRef();
        $this->db->insert($this->ecTable(), array(
            'claim_ref'       => $ref,
            'claimant_id'     => $claimantId,
            'delegate_id'     => $actorId !== $claimantId ? $actorId : null,
            'category'        => (string) $post['category'],
            'payable_kind'    => (string) $post['payable_kind'],
            'purpose'         => substr(trim((string) $post['purpose']), 0, 500),
            'expense_date'    => (string) $post['expense_date'],
            'currency'        => 'INR',
            'amount'          => (float) $post['amount'],
            'tax_amount'      => isset($post['tax_amount']) && is_numeric($post['tax_amount'])
                                    ? (float) $post['tax_amount'] : 0,
            'state'           => Workforce_expense::DRAFT,
            'state_since'     => $now,
            'idempotency_key' => $key,
            'created_by'      => $actorId,
            'created_at'      => $now,
        ));
        $id = (int) $this->db->insert_id();
        if ($id <= 0) {
            /* The UNIQUE index refused it. Two requests raced and the database
               settled it, which is exactly what the index is there for. */
            return array('ok' => false, 'code' => 'duplicate_race',
                'reason' => 'An identical claim was created at the same moment. Only one was kept.',
                'errors' => array());
        }

        $this->claimEvent($id, 'created', null, Workforce_expense::DRAFT, $actorId, '',
                          null, (float) $post['amount'], array('claim_ref' => $ref));
        return array('ok' => true, 'code' => 'ok', 'reason' => '', 'id' => $id,
                     'claim_ref' => $ref, 'errors' => array());
    }

    /**
     * Move a claim. The gate is the library's; this only records what it decided.
     *
     * @return array ok, code, reason
     */
    public function claimTransition($claimId, $to, $actorId, $reason = '')
    {
        $claim = $this->claim($claimId);
        if (!$claim) { return array('ok' => false, 'code' => 'not_found', 'reason' => 'No such claim.'); }

        $caps = $this->capabilitiesFor($actorId);
        $gate = Workforce_expense::canAct($claim, $to, $actorId, $caps, $reason);
        if (!$gate['allowed']) {
            /* A refusal is recorded. The refusals are the interesting half of an
               audit trail: an approval that never happened leaves no other trace. */
            $this->claimEvent((int) $claim['id'], 'refused', $claim['state'], $to, $actorId,
                $gate['code'] . ': ' . $gate['reason'], null, null,
                array('required_capability' => isset($gate['required_capability']) ? $gate['required_capability'] : null,
                      'capability_checked'  => $gate['capability_checked']));
            return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']);
        }

        $now = date('Y-m-d H:i:s');
        $set = array('state' => $to, 'state_since' => $now, 'updated_at' => $now);
        if ($to === Workforce_expense::MANAGER_REVIEW) {
            $set['manager_reviewer_id'] = (int) $actorId; $set['manager_reviewed_at'] = $now;
        }
        if ($to === Workforce_expense::FINANCE_REVIEW) {
            $set['finance_reviewer_id'] = (int) $actorId; $set['finance_reviewed_at'] = $now;
        }
        if ($to === Workforce_expense::APPROVED) {
            $set['approver_id'] = (int) $actorId; $set['approved_at'] = $now;
        }
        if ($to === Workforce_expense::PAID) { $set['paid_at'] = $now; }
        if (in_array($to, array(Workforce_expense::REJECTED, Workforce_expense::DRAFT,
                                Workforce_expense::REVERSED), true) && $reason !== '') {
            $set['decided_reason'] = substr(trim((string) $reason), 0, 500);
        }

        $this->db->where('id', (int) $claim['id'])->update($this->ecTable(), $set);
        $this->claimEvent((int) $claim['id'], 'transition', $claim['state'], $to, $actorId, $reason,
                          (float) $claim['amount'], (float) $claim['amount'],
                          array('required_capability' => $gate['required_capability']));
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * Insert-only. There is deliberately no method on this model that updates or
     * deletes a row in the events table.
     */
    public function claimEvent($claimId, $action, $from, $to, $actorId, $reason = '',
                               $amountBefore = null, $amountAfter = null, $data = array())
    {
        $this->db->insert($this->eeTable(), array(
            'claim_id'      => (int) $claimId,
            'action'        => substr((string) $action, 0, 40),
            'from_state'    => $from,
            'to_state'      => $to,
            'actor_id'      => (int) $actorId,
            'reason'        => $reason === '' ? null : substr((string) $reason, 0, 500),
            'amount_before' => $amountBefore,
            'amount_after'  => $amountAfter,
            'data_json'     => $data ? json_encode($data) : null,
            'ip'            => substr((string) $this->input->ip_address(), 0, 45),
            'occurred_at'   => date('Y-m-d H:i:s'),
        ));
        return (int) $this->db->insert_id();
    }

    public function claimEvents($claimId = 0, $limit = 300)
    {
        if ((int) $claimId > 0) { $this->db->where('claim_id', (int) $claimId); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->eeTable())->result_array();
    }

    /** Claims approved and not yet attached to a payout item. */
    public function claimsPayable()
    {
        return $this->db->where('state', Workforce_expense::APPROVED)
            ->group_start()->where('payout_item_id', null)->or_where('payout_item_id', 0)->group_end()
            ->order_by('id', 'ASC')->get($this->ecTable())->result_array();
    }

    /** Pending work, with who is holding it and for how long. */
    public function claimAgeing($viewerId = 0, $viewAll = true)
    {
        $rows = $this->claims(array(), $viewerId, $viewAll);
        $out  = array('buckets' => array(), 'rows' => array(), 'total_waiting' => 0);
        foreach (Workforce_expense::ageingBuckets() as $b => $label) { $out['buckets'][$b] = 0; }
        foreach ($rows as $r) {
            $a = Workforce_expense::ageing($r);
            if (!$a['waiting']) { continue; }
            $out['buckets'][$a['bucket']]++;
            $out['total_waiting']++;
            $r['ageing'] = $a;
            $out['rows'][] = $r;
        }
        usort($out['rows'], function ($x, $y) { return $y['ageing']['days'] - $x['ageing']['days']; });
        return $out;
    }

    /* ==================================================================== *
     * Beneficiary bank accounts
     * ==================================================================== */

    public function bankAccount($staffId)
    {
        return $this->db->where('staff_id', (int) $staffId)->where('is_current', 1)
            ->order_by('id', 'DESC')->limit(1)->get($this->baTable())->row_array();
    }

    public function bankAccountRow($id)
    {
        return $this->db->where('id', (int) $id)->get($this->baTable())->row_array();
    }

    public function bankHistory($staffId)
    {
        return $this->db->where('staff_id', (int) $staffId)->order_by('id', 'DESC')
            ->get($this->baTable())->result_array();
    }

    /**
     * Record bank details. A change supersedes rather than overwrites, and
     * anything that alters where the money goes lands unverified.
     *
     * @return array ok, code, reason, errors, id, reset
     */
    public function saveBankAccount($staffId, $post, $actorId)
    {
        $staffId = (int) $staffId;
        $v = Workforce_bank::validate($post);
        if (!$v['ok']) {
            return array('ok' => false, 'code' => 'invalid', 'reason' => 'Some details need correcting.',
                         'errors' => $v['errors']);
        }
        $n = $v['normalised'];

        $current = $this->bankAccount($staffId);
        $impact  = array('resets' => false, 'changed' => array(), 'reason' => '');
        if ($current) {
            $impact = Workforce_bank::verificationImpact(array(
                /* the stored number is encrypted, so compare on the masked form
                   and the last four — which is what actually changes when the
                   digits change */
                'account_number'   => (string) $current['masked_account'],
                'ifsc'             => (string) $current['ifsc'],
                'beneficiary_name' => (string) $current['beneficiary_name'],
            ), array(
                'account_number'   => Workforce_bank::mask($n['account_number']),
                'ifsc'             => $n['ifsc'],
                'beneficiary_name' => $n['beneficiary_name'],
            ));
        }

        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->baTable(), array(
            'staff_id'           => $staffId,
            'beneficiary_name'   => $n['beneficiary_name'],
            'account_enc'        => $this->encryptBank(array('account' => $n['account_number'],
                                                             'ifsc' => $n['ifsc'])),
            'account_last4'      => substr($n['account_number'], -4),
            'masked_account'     => $n['masked_account'],
            'ifsc'               => $n['ifsc'],
            'bank_name'          => $n['bank_name'],
            'account_type'       => $n['account_type'],
            'verification_state' => Workforce_bank::UNVERIFIED,
            'is_current'         => 1,
            'created_by'         => (int) $actorId,
            'created_at'         => $now,
        ));
        $id = (int) $this->db->insert_id();
        if ($id <= 0) {
            return array('ok' => false, 'code' => 'insert_failed',
                         'reason' => 'The account could not be recorded.', 'errors' => array());
        }
        if ($current) {
            $this->db->where('id', (int) $current['id'])
                ->update($this->baTable(), array('is_current' => 0, 'replaced_by_id' => $id));
        }

        $this->claimEvent(0, 'bank_recorded', null, null, (int) $actorId,
            $current ? ('Replaced account #' . (int) $current['id']
                        . ($impact['resets'] ? ' — ' . $impact['reason'] : '')) : 'First account on file',
            null, null, array('staff_id' => $staffId, 'bank_account_id' => $id,
                              'masked' => $n['masked_account'], 'changed' => $impact['changed']));

        return array('ok' => true, 'code' => 'ok', 'reason' => '', 'errors' => array(),
                     'id' => $id, 'reset' => $impact);
    }

    /** @return array ok, code, reason */
    public function decideBankAccount($id, $decision, $actorId, $reason = '')
    {
        $row = $this->bankAccountRow($id);
        if (!$row) { return array('ok' => false, 'code' => 'not_found', 'reason' => 'No such account.'); }

        $has  = payplex_staff_can('bank_verify');
        $gate = Workforce_bank::canVerify((int) $row['staff_id'], (int) $actorId, $has);
        if (!$gate['allowed']) {
            $this->claimEvent(0, 'bank_refused', null, null, (int) $actorId, $gate['code'],
                null, null, array('bank_account_id' => (int) $row['id'], 'staff_id' => (int) $row['staff_id']));
            return array('ok' => false, 'code' => $gate['code'], 'reason' => $gate['reason']);
        }
        if (!in_array($decision, array(Workforce_bank::VERIFIED, Workforce_bank::REJECTED), true)) {
            return array('ok' => false, 'code' => 'bad_decision', 'reason' => 'Unknown decision.');
        }
        if ($decision === Workforce_bank::REJECTED && trim((string) $reason) === '') {
            return array('ok' => false, 'code' => 'reason_required',
                         'reason' => 'A rejection needs a reason.');
        }

        $this->db->where('id', (int) $row['id'])->update($this->baTable(), array(
            'verification_state' => $decision,
            'verified_by'        => (int) $actorId,
            'verified_at'        => date('Y-m-d H:i:s'),
            'rejection_reason'   => $decision === Workforce_bank::REJECTED
                                        ? substr(trim((string) $reason), 0, 255) : null,
        ));
        $this->claimEvent(0, 'bank_' . $decision, null, null, (int) $actorId, $reason,
            null, null, array('bank_account_id' => (int) $row['id'], 'staff_id' => (int) $row['staff_id']));
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /** Is this person payable, and if not, why not? */
    public function bankPayable($staffId)
    {
        return Workforce_bank::payable((array) $this->bankAccount($staffId));
    }

    /** Rows for the claims export, with the claimant's name resolved once. */
    /**
     * Stream every claim matching the filter, one row at a time.
     *
     * Nothing is accumulated here: rows go to $emit as they arrive and are not
     * kept, so a 50,000-claim export costs one page of memory rather than
     * fifty thousand rows of it. Totals are added up while streaming, over the
     * whole set rather than over whatever happened to fit.
     *
     * The name map is loaded once. It is bounded by the number of staff, not by
     * the number of claims, so it does not grow with the export.
     */
    public function claimsExportEach($filter, $viewerId, $viewAll, $emit, $pageSize = 500)
    {
        $names = array();
        foreach ($this->db->select('staffid, firstname, lastname')
                     ->get(db_prefix() . 'staff')->result_array() as $s) {
            $names[(int) $s['staffid']] = trim($s['firstname'] . ' ' . $s['lastname']);
        }

        $self = $this;
        return Workforce_expense::streamExport(
            function ($afterId, $limit) use ($self, $filter, $viewerId, $viewAll) {
                return $self->claimsPage($filter, $viewerId, $viewAll, $afterId, $limit);
            },
            function ($claim) use ($emit, $names) {
                $cid = (int) (isset($claim['claimant_id']) ? $claim['claimant_id'] : 0);
                call_user_func($emit, Workforce_expense::exportRow(
                    $claim, isset($names[$cid]) ? $names[$cid] : ''));
            },
            $pageSize
        );
    }

    /**
     * The whole export as an array. Convenience for callers that genuinely want
     * it in memory; the download route streams instead.
     */
    public function claimsExportResult($filter = array(), $viewerId = 0, $viewAll = false)
    {
        $rows = array();
        $t = $this->claimsExportEach($filter, $viewerId, $viewAll,
            function ($row) use (&$rows) { $rows[] = $row; });
        return array('rows' => $rows, 'totals' => $t,
                     'truncated' => false, 'complete' => !empty($t['ok']));
    }

    public function claimsExport($filter = array(), $viewerId = 0, $viewAll = false)
    {
        $claims = $this->claims($filter, $viewerId, $viewAll);
        $names  = array();
        foreach ($this->db->select('staffid, firstname, lastname')->get(db_prefix() . 'staff')->result_array() as $s) {
            $names[(int) $s['staffid']] = trim($s['firstname'] . ' ' . $s['lastname']);
        }
        $rows = array();
        foreach ($claims as $c) {
            $rows[] = Workforce_expense::exportRow($c,
                isset($names[(int) $c['claimant_id']]) ? $names[(int) $c['claimant_id']] : '');
        }
        return $rows;
    }

    public function claimDashboard($viewerId = 0, $viewAll = true)
    {
        return Workforce_expense::dashboard($this->claims(array(), $viewerId, $viewAll));
    }
}
