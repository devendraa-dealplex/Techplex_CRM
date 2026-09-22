<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Attendance_dashboard_model extends App_Model
{
    const CHALLENGE_TTL = 300;
    const MAX_IMAGE_BYTES = 3000000;

    private $imageError = '';

    private $p;

    public function __construct()
    {
        parent::__construct();
        $this->p = db_prefix() . 'att_';
        require_once __DIR__ . '/../libraries/Attendance_verifier.php';
    }

    public function dir()
    {
        return FCPATH . 'uploads/attendance/';
    }

    public function settings()
    {
        return [
            'allow_duplicate'    => get_option('att_allow_duplicate') === '1',
            'early_minutes'      => (int) get_option('att_early_minutes'),
            'late_grace_minutes' => (int) get_option('att_late_grace_minutes'),
            'max_accuracy_m'     => max(10, (int) get_option('att_max_accuracy_m')),
            'checkout_late_minutes' => get_option('att_checkout_late_minutes') === '' ? 240 : (int) get_option('att_checkout_late_minutes'),
        ];
    }

    /* ---------------- Perfex staff -> employees ---------------- */

    /**
     * Idempotent. Creates an employee (no workplace yet, default 09:00-18:00 Mon-Fri schedule)
     * for every Perfex staff member not yet linked, and deactivates employees whose staff
     * account is inactive. Employees stay unable to mark attendance until a workplace is assigned.
     */
    public function syncStaff($staffId = 0)
    {
        $sp = db_prefix();
        $q = $this->db->select('s.staffid, s.firstname, s.lastname, s.email, s.phonenumber, s.active, r.name AS role_name, GROUP_CONCAT(d.name SEPARATOR ", ") AS depts', false)
            ->from($sp . 'staff s')
            ->join($sp . 'roles r', 'r.roleid = s.role', 'left')
            ->join($sp . 'staff_departments sd', 'sd.staffid = s.staffid', 'left')
            ->join($sp . 'departments d', 'd.departmentid = sd.departmentid', 'left')
            ->where('s.is_not_staff', 0);
        if ($staffId) {
            $this->db->where('s.staffid', (int) $staffId);
        }
        $staff = $q->group_by('s.staffid')->get()->result_array();

        $this->backfillSelfOnce();
        $linked = array_column($this->db->select('id, staff_id, active')->where('staff_id IS NOT NULL', null, false)->get($this->p . 'employees')->result_array(), null, 'staff_id');
        $now = date('Y-m-d H:i:s');
        foreach ($staff as $s) {
            if (isset($linked[$s['staffid']])) {
                if (!$s['active'] && $linked[$s['staffid']]['active']) {
                    $this->db->where('id', $linked[$s['staffid']]['id'])->update($this->p . 'employees', ['active' => 0, 'updated_at' => $now]);
                }
                continue;
            }
            $code = 'STF-' . $s['staffid'];
            if ($this->db->where('emp_code', $code)->count_all_results($this->p . 'employees')) {
                $code .= '-' . substr(md5(uniqid('', true)), 0, 4);
            }
            $this->db->trans_start();
            $this->grantSelf($s['staffid']);
            $this->db->insert($this->p . 'employees', [
                'emp_code' => $code, 'staff_id' => (int) $s['staffid'],
                'full_name' => trim($s['firstname'] . ' ' . $s['lastname']),
                'role' => (string) $s['role_name'], 'department' => (string) $s['depts'],
                'workplace_id' => 0, 'phone' => (string) $s['phonenumber'], 'email' => (string) $s['email'],
                'notes' => 'Imported from staff. Assign a workplace and check the schedule.',
                'active' => (int) $s['active'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->insert($this->p . 'schedules', [
                'employee_id' => $this->db->insert_id(), 'start_time' => '09:00:00', 'end_time' => '18:00:00',
                'working_days' => '1,2,3,4,5', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->trans_complete();
        }
    }

    /** The attendance employee linked to a Perfex staff account (null if none). */
    public function employeeByStaff($staffId)
    {
        $row = $this->db->select('id')->where(['staff_id' => (int) $staffId, 'active' => 1])->get($this->p . 'employees')->row_array();

        return $row ? (int) $row['id'] : null;
    }

    /** Lets a staff account mark/view ONLY its own attendance. */
    public function grantSelf($staffId)
    {
        $where = ['staff_id' => (int) $staffId, 'feature' => ATT_MODULE, 'capability' => 'self'];
        if (!$this->db->where($where)->count_all_results(db_prefix() . 'staff_permissions')) {
            $this->db->insert(db_prefix() . 'staff_permissions', $where);
        }
    }

    /** One-time: give every already-imported employee's staff account the self-service capability. */
    private function backfillSelfOnce()
    {
        if (get_option('att_self_backfill') === '1') {
            return;
        }
        foreach ($this->db->select('staff_id')->where('staff_id IS NOT NULL', null, false)->get($this->p . 'employees')->result_array() as $r) {
            $this->grantSelf($r['staff_id']);
        }
        update_option('att_self_backfill', '1');
    }

    /* ---------------- workplaces ---------------- */

    public function workplaces()
    {
        return $this->db->order_by('name')->get($this->p . 'workplaces')->result_array();
    }

    public function workplace($id)
    {
        return $this->db->get_where($this->p . 'workplaces', ['id' => (int) $id])->row_array();
    }

    public function saveWorkplace(array $d)
    {
        $lat = $d['latitude'] ?? '';
        $lng = $d['longitude'] ?? '';
        $rad = (int) ($d['radius_m'] ?? 0);
        $name = trim($d['name'] ?? '');
        $pin  = trim($d['pincode'] ?? '');
        if ($pin !== '' && !preg_match('/^[A-Za-z0-9 -]{3,12}$/', $pin)) {
            return 'Invalid pincode.';
        }
        if ($name === '') {
            return 'Workplace name is required.';
        }
        if (!Attendance_verifier::validCoords($lat, $lng)) {
            return 'Valid latitude (-90..90) and longitude (-180..180) are required.';
        }
        if ($rad < 10 || $rad > 5000) {
            return 'Radius must be between 10 and 5000 metres.';
        }
        $row = ['name' => $name, 'address' => trim($d['address'] ?? ''), 'pincode' => $pin, 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => $rad, 'updated_at' => date('Y-m-d H:i:s')];
        if (!empty($d['id'])) {
            $this->db->where('id', (int) $d['id'])->update($this->p . 'workplaces', $row);
        } else {
            $row['created_at'] = $row['updated_at'];
            $this->db->insert($this->p . 'workplaces', $row);
        }

        return true;
    }

    public function deleteWorkplace($id)
    {
        if ($this->db->where('workplace_id', (int) $id)->count_all_results($this->p . 'employees') > 0
            || $this->db->where('workplace_id', (int) $id)->count_all_results($this->p . 'records') > 0) {
            return 'Workplace is in use by employees or attendance records and cannot be deleted.';
        }
        $this->db->delete($this->p . 'workplaces', ['id' => (int) $id]);

        return true;
    }

    /* ---------------- employees ---------------- */

    public function employees(array $f = [])
    {
        $this->db->select('e.*, w.name AS workplace_name, s.start_time, s.end_time, s.working_days')
            ->from($this->p . 'employees e')
            ->join($this->p . 'workplaces w', 'w.id = e.workplace_id', 'left')
            ->join($this->p . 'schedules s', 's.employee_id = e.id', 'left');
        if (!empty($f['q'])) {
            $this->db->group_start()->like('e.full_name', $f['q'])->or_like('e.emp_code', $f['q'])->group_end();
        }
        if (!empty($f['role'])) {
            $this->db->like('e.role', $f['role']);
        }
        if (!empty($f['workplace_id'])) {
            $this->db->where('e.workplace_id', (int) $f['workplace_id']);
        }
        if (!empty($f['active_only'])) {
            $this->db->where('e.active', 1);
        }
        if (!empty($f['employee_id'])) {
            $this->db->where('e.id', (int) $f['employee_id']);
        }

        return $this->db->order_by('e.full_name')->get()->result_array();
    }

    public function employee($id)
    {
        return $this->employees_one(['e.id' => (int) $id]);
    }

    private function employees_one(array $where)
    {
        return $this->db->select('e.*, w.name AS workplace_name, w.latitude, w.longitude, w.radius_m, s.start_time, s.end_time, s.working_days')
            ->from($this->p . 'employees e')
            ->join($this->p . 'workplaces w', 'w.id = e.workplace_id', 'left')
            ->join($this->p . 'schedules s', 's.employee_id = e.id', 'left')
            ->where($where)->get()->row_array();
    }

    /** @return true|string  error message on failure */
    public function saveEmployee(array $d, $file)
    {
        $id   = (int) ($d['id'] ?? 0);
        $code = trim($d['emp_code'] ?? '');
        $name = trim($d['full_name'] ?? '');
        if ($code === '' || $name === '') {
            return 'Full name and Employee ID are required.';
        }
        if (!$this->workplace($d['workplace_id'] ?? 0)) {
            return 'Assign a valid workplace.';
        }
        $start = $d['start_time'] ?? '';
        $end   = $d['end_time'] ?? '';
        if (!preg_match('/^\d{2}:\d{2}$/', $start) || !preg_match('/^\d{2}:\d{2}$/', $end) || $start === $end) {
            return 'Valid, different work start and end times are required.';
        }
        $days = array_values(array_intersect(range(1, 7), array_map('intval', (array) ($d['working_days'] ?? []))));
        if (!$days) {
            return 'Select at least one working day.';
        }
        if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            return 'Invalid email address.';
        }
        $dup = $this->db->where('emp_code', $code)->where('id !=', $id)->count_all_results($this->p . 'employees');
        if ($dup) {
            return 'Employee ID already exists.';
        }

        $photo = null;
        if ($file && !empty($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $photo = $this->storeUploadedPhoto($file);
            if ($photo === false) {
                return 'Profile photo must be a JPG/PNG image under 2 MB.';
            }
        }

        $now = date('Y-m-d H:i:s');
        $row = [
            'emp_code' => $code, 'full_name' => $name,
            'role' => trim($d['role'] ?? ''), 'department' => trim($d['department'] ?? ''),
            'workplace_id' => (int) $d['workplace_id'],
            'phone' => trim($d['phone'] ?? ''), 'email' => trim($d['email'] ?? ''),
            'notes' => trim($d['notes'] ?? ''), 'active' => empty($d['inactive']) ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($photo) {
            $row['photo'] = $photo;
        }
        $sched = ['start_time' => $start, 'end_time' => $end, 'working_days' => implode(',', $days), 'updated_at' => $now];

        $this->db->trans_start();
        if ($id) {
            $this->db->where('id', $id)->update($this->p . 'employees', $row);
            $this->db->where('employee_id', $id)->update($this->p . 'schedules', $sched);
            if (!$this->db->affected_rows() && !$this->db->where('employee_id', $id)->count_all_results($this->p . 'schedules')) {
                $this->db->insert($this->p . 'schedules', $sched + ['employee_id' => $id, 'created_at' => $now]);
            }
        } else {
            $row['created_at'] = $now;
            $this->db->insert($this->p . 'employees', $row);
            $this->db->insert($this->p . 'schedules', $sched + ['employee_id' => $this->db->insert_id(), 'created_at' => $now]);
        }
        $this->db->trans_complete();

        return $this->db->trans_status() ? true : 'Could not save employee.';
    }

    public function deleteEmployee($id)
    {
        $id = (int) $id;
        if ($this->db->where('employee_id', $id)->count_all_results($this->p . 'records') > 0) {
            // Keep attendance history intact; deactivate instead.
            $this->db->where('id', $id)->update($this->p . 'employees', ['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);

            return 'Employee has attendance history, so was deactivated instead of deleted.';
        }
        $this->db->delete($this->p . 'schedules', ['employee_id' => $id]);
        $this->db->delete($this->p . 'employees', ['id' => $id]);

        return true;
    }

    private function storeUploadedPhoto($file)
    {
        if ($file['size'] > 2000000) {
            return false;
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            return false;
        }
        $raw = file_get_contents($file['tmp_name']);
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        if (function_exists('imagecreatefromstring') && ($img = @imagecreatefromstring($raw))) {
            $raw = $this->encodeJpeg($img); // re-encode: strips any embedded payload
            $ext = 'jpg';
        }
        $name = 'p_' . bin2hex(random_bytes(12)) . '.' . $ext;

        return file_put_contents($this->dir() . $name, $raw) !== false ? $name : false;
    }

    private function encodeJpeg($img)
    {
        ob_start();
        imagejpeg($img, null, 85);
        $out = ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /* ---------------- live-capture challenge ---------------- */

    /**
     * Issues a single-use token bound to one employee and one action ('in'|'out');
     * a capture is only accepted with a fresh one.
     */
    public function beginCapture($employeeId, $type = 'in')
    {
        $type = $type === 'out' ? 'out' : 'in';
        $emp = $this->employee($employeeId);
        if (!$emp || !$emp['active']) {
            return ['ok' => false, 'reason' => 'Employee not found or inactive.'];
        }
        if (!$emp['workplace_name']) {
            return ['ok' => false, 'reason' => 'No workplace assigned to this employee. Ask an admin to assign one.'];
        }
        $blocked = $this->sequenceError($emp, $type, time());
        if ($blocked) {
            return ['ok' => false, 'reason' => $blocked];
        }
        $token = bin2hex(random_bytes(16));
        $c = $this->session->userdata('att_challenges') ?: [];
        $c = array_filter($c, function ($x) { return $x['ts'] > time() - self::CHALLENGE_TTL; });
        $c[$token] = ['emp' => (int) $emp['id'], 'type' => $type, 'ts' => time()];
        $this->session->set_userdata('att_challenges', $c);

        return ['ok' => true, 'token' => $token, 'type' => $type, 'employee' => [
            'name' => $emp['full_name'], 'workplace' => $emp['workplace_name'],
            'window' => substr($emp['start_time'], 0, 5) . ' - ' . substr($emp['end_time'], 0, 5),
        ]];
    }

    /** Check-in once per shift; check-out only after a check-in, once per shift (unless duplicates are allowed). */
    private function sequenceError($emp, $type, $now)
    {
        $date = Attendance_verifier::shiftDate($emp, $now, $type);
        $dup  = $this->settings()['allow_duplicate'];
        if ($type === 'in') {
            return (!$dup && $this->hasVerified($emp['id'], $date, 'in')) ? 'Attendance already recorded.' : null;
        }
        if (!$this->hasVerified($emp['id'], $date, 'in')) {
            return 'Check-in required before check-out.';
        }

        return (!$dup && $this->hasVerified($emp['id'], $date, 'out')) ? 'Check-out already recorded.' : null;
    }

    private function consumeToken($token, $employeeId, $type)
    {
        $c = $this->session->userdata('att_challenges') ?: [];
        $ok = isset($c[$token]) && $c[$token]['emp'] === (int) $employeeId && $c[$token]['type'] === $type && $c[$token]['ts'] > time() - self::CHALLENGE_TTL;
        unset($c[$token]);
        $this->session->set_userdata('att_challenges', $c);

        return $ok;
    }

    private function hasVerified($employeeId, $date, $type)
    {
        return $this->db->where(['employee_id' => (int) $employeeId, 'att_date' => $date, 'att_type' => $type, 'verification_status' => 'verified'])
            ->count_all_results($this->p . 'records') > 0;
    }

    /**
     * The only path that creates a "verified" record. Status is decided here, never by the client.
     * @return array [ok, reason, distance_m?, id?]
     */
    public function markAttendance($employeeId, $token, $lat, $lng, $accuracy, $imageDataUrl, $staffId, $type = 'in')
    {
        $type = $type === 'out' ? 'out' : 'in';
        $emp = $this->employee($employeeId);
        if (!$emp || !$emp['active']) {
            return ['ok' => false, 'reason' => 'Employee not found or inactive.'];
        }
        if (!$emp['workplace_name']) {
            return ['ok' => false, 'reason' => 'No workplace assigned to this employee. Ask an admin to assign one.'];
        }
        $set = $this->settings();
        $now = time();
        $date = Attendance_verifier::shiftDate($emp, $now, $type);

        if ($blocked = $this->sequenceError($emp, $type, $now)) {
            return ['ok' => false, 'reason' => $blocked];
        }

        $reason = null;
        $bytes  = null;
        $tokenOk = $this->consumeToken((string) $token, $emp['id'], $type);
        if (!$tokenOk) {
            $reason = 'Camera verification required. Session expired - retake the photo.';
        } else {
            $bytes = $this->decodeImage((string) $imageDataUrl);
            if ($bytes === null) {
                $reason = 'Camera verification required. ' . $this->imageError;
            } elseif ($this->db->where('image_hash', hash('sha256', $bytes))->count_all_results($this->p . 'records') > 0) {
                $reason = 'Camera verification required. This image was already used.';
                $bytes = null;
            }
        }

        $eval = ['ok' => false, 'reason' => $reason, 'distance_m' => null, 'is_late' => 0, 'is_early_out' => 0];
        $accuracy = is_numeric($accuracy) ? (float) $accuracy : null;
        if ($reason === null) {
            $eval = Attendance_verifier::evaluate($emp, $emp, $lat, $lng, $accuracy, $now, $set, $type);
        } elseif (!Attendance_verifier::validCoords($lat, $lng)) {
            $lat = $lng = null;
        }

        $file = null;
        if ($bytes !== null) {
            $file = 'a_' . bin2hex(random_bytes(12)) . '.jpg';
            file_put_contents($this->dir() . $file, $bytes);
        }
        $verified = $eval['ok'];
        $row = [
            'employee_id' => $emp['id'], 'workplace_id' => $emp['workplace_id'], 'att_date' => $date, 'att_type' => $type,
            'timestamp' => date('Y-m-d H:i:s', $now),
            'latitude' => Attendance_verifier::validCoords($lat, $lng) ? $lat : null,
            'longitude' => Attendance_verifier::validCoords($lat, $lng) ? $lng : null,
            'accuracy_m' => $accuracy !== null ? (int) $accuracy : null,
            'distance_m' => $eval['distance_m'], 'captured_image' => $file,
            'image_hash' => $bytes !== null ? hash('sha256', $bytes) : null,
            'verification_status' => $verified ? 'verified' : 'failed',
            'verification_reason' => mb_substr($eval['reason'], 0, 255),
            'is_late' => $eval['is_late'], 'is_early_out' => $eval['is_early_out'],
            'unique_key' => ($verified && !$set['allow_duplicate']) ? ($type === 'out' ? 2 : 1) : null,
            'ip_address' => $this->input->ip_address(), 'marked_by' => (int) $staffId,
            'created_at' => date('Y-m-d H:i:s', $now),
        ];
        $this->db->db_debug = false;
        $ins = $this->db->insert($this->p . 'records', $row);
        $this->db->db_debug = true;
        if (!$ins) { // unique index hit: concurrent duplicate
            if ($file) {
                @unlink($this->dir() . $file);
            }

            return ['ok' => false, 'reason' => 'Attendance already recorded.'];
        }

        return ['ok' => $verified, 'reason' => $eval['reason'], 'distance_m' => $eval['distance_m'], 'id' => $this->db->insert_id()];
    }

    /** Validates a JPEG data URL from the camera canvas and re-encodes it. Returns bytes or null. */
    private function decodeImage($dataUrl)
    {
        if (!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) {
            $this->imageError = $dataUrl === '' ? '(no image received by server)' : '(image format not recognised)';

            return null;
        }
        $raw = base64_decode($m[1], true);
        if ($raw === false || strlen($raw) < 3000 || strlen($raw) > self::MAX_IMAGE_BYTES) {
            $this->imageError = '(image size invalid)';

            return null;
        }
        $info = @getimagesizefromstring($raw);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 240 || $info[1] < 240) {
            $this->imageError = '(image too small or not a JPEG)';

            return null;
        }
        if (function_exists('imagecreatefromstring')) { // GD optional: re-encode when available
            $img = @imagecreatefromstring($raw);
            if (!$img) {
                return null;
            }
            $raw = $this->encodeJpeg($img);
        }

        return $raw;
    }

    /* ---------------- queries for dashboard / records / reports ---------------- */

    public function records(array $f = [], $limit = 500)
    {
        $this->db->select("r.*, e.full_name, e.emp_code, e.role, w.name AS workplace_name,
                (SELECT MIN(i.timestamp) FROM {$this->p}records i WHERE i.employee_id = r.employee_id AND i.att_date = r.att_date
                    AND i.att_type = 'in' AND i.verification_status = 'verified') AS in_ts", false)
            ->from($this->p . 'records r')
            ->join($this->p . 'employees e', 'e.id = r.employee_id', 'left')
            ->join($this->p . 'workplaces w', 'w.id = r.workplace_id', 'left');
        if (!empty($f['from'])) {
            $this->db->where('r.att_date >=', $f['from']);
        }
        if (!empty($f['to'])) {
            $this->db->where('r.att_date <=', $f['to']);
        }
        if (!empty($f['q'])) {
            $this->db->group_start()->like('e.full_name', $f['q'])->or_like('e.emp_code', $f['q'])->group_end();
        }
        if (!empty($f['role'])) {
            $this->db->like('e.role', $f['role']);
        }
        if (!empty($f['workplace_id'])) {
            $this->db->where('r.workplace_id', (int) $f['workplace_id']);
        }
        if (($f['status'] ?? '') === 'verified' || ($f['status'] ?? '') === 'failed') {
            $this->db->where('r.verification_status', $f['status']);
        } elseif (($f['status'] ?? '') === 'late') {
            $this->db->where(['r.is_late' => 1, 'r.att_type' => 'in', 'r.verification_status' => 'verified']);
        }

        return $this->db->order_by('r.timestamp', 'DESC')->limit($limit)->get()->result_array();
    }

    public function record($id)
    {
        return $this->db->get_where($this->p . 'records', ['id' => (int) $id])->row_array();
    }

    /** Verified rows keyed by employee|date, first check-in of the day. */
    private function verifiedMap($from, $to)
    {
        $rows = $this->db->select('employee_id, att_date, MIN(timestamp) AS ts, MAX(is_late) AS late', false)
            ->where(['att_date >=' => $from, 'att_date <=' => $to, 'att_type' => 'in', 'verification_status' => 'verified'])
            ->group_by(['employee_id', 'att_date'])->get($this->p . 'records')->result_array();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['employee_id'] . '|' . $r['att_date']] = $r;
        }

        return $map;
    }

    /** Last verified check-out per employee/day, keyed employee|date. */
    private function outMap($from, $to)
    {
        $rows = $this->db->select('employee_id, att_date, MAX(timestamp) AS ts', false)
            ->where(['att_date >=' => $from, 'att_date <=' => $to, 'att_type' => 'out', 'verification_status' => 'verified'])
            ->group_by(['employee_id', 'att_date'])->get($this->p . 'records')->result_array();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['employee_id'] . '|' . $r['att_date']] = $r['ts'];
        }

        return $map;
    }

    /**
     * Expected/present/late/hours per employee and per workplace over a date range (capped at today).
     * "missing" = past days with a check-in but no check-out.
     */
    public function summary($from, $to)
    {
        $today = date('Y-m-d');
        $to = min($to, $today);
        $map = $this->verifiedMap($from, $to);
        $out = $this->outMap($from, $to);
        $emps = $this->employees(['active_only' => 1]);
        $byEmp = $byLoc = [];
        $expected = $present = 0;
        foreach ($emps as $e) {
            $days = array_filter(explode(',', (string) $e['working_days']), 'strlen');
            $exp = $pre = $late = $mins = $missing = 0;
            for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
                if (in_array(date('N', $t), $days)) {
                    $exp++;
                    $d = date('Y-m-d', $t);
                    $k = $e['id'] . '|' . $d;
                    if (isset($map[$k])) {
                        $pre++;
                        $late += (int) $map[$k]['late'];
                        if (isset($out[$k])) {
                            $mins += max(0, (int) round((strtotime($out[$k]) - strtotime($map[$k]['ts'])) / 60));
                        } elseif ($d < $today) {
                            $missing++;
                        }
                    }
                }
            }
            $byEmp[] = ['id' => $e['id'], 'name' => $e['full_name'], 'code' => $e['emp_code'], 'role' => $e['role'], 'expected' => $exp, 'present' => $pre, 'late' => $late, 'minutes' => $mins, 'missing' => $missing];
            $l = &$byLoc[$e['workplace_id']];
            $l = $l ?? ['name' => $e['workplace_name'] ?: 'Not assigned', 'expected' => 0, 'present' => 0, 'late' => 0, 'minutes' => 0, 'missing' => 0];
            $l['expected'] += $exp;
            $l['present'] += $pre;
            $l['late'] += $late;
            $l['minutes'] += $mins;
            $l['missing'] += $missing;
            unset($l);
            $expected += $exp;
            $present += $pre;
        }

        return ['employees' => $byEmp, 'locations' => array_values($byLoc), 'expected' => $expected, 'present' => $present];
    }

    /**
     * One row per employee per shift day in the range (working days up to today, plus any day with a record).
     * Status: Present | Late | Working | Incomplete (no check-out) | Absent | Pending (today, shift not over).
     */
    public function dayRows(array $f, $limit = 1000)
    {
        $today = date('Y-m-d');
        $to = min($f['to'], $today);
        $set = $this->settings();
        $emps = $this->employees(['q' => $f['q'] ?? '', 'role' => $f['role'] ?? '', 'workplace_id' => $f['workplace_id'] ?? 0, 'employee_id' => $f['employee_id'] ?? 0, 'active_only' => 1]);
        if (!$emps) {
            return [];
        }
        $ids = array_column($emps, 'id');
        $recs = $this->db->where(['att_date >=' => $f['from'], 'att_date <=' => $to, 'verification_status' => 'verified'])
            ->where_in('employee_id', $ids)->order_by('timestamp')->get($this->p . 'records')->result_array();
        $in = $out = [];
        foreach ($recs as $r) {
            $k = $r['employee_id'] . '|' . $r['att_date'];
            if ($r['att_type'] === 'out') {
                $out[$k] = $r; // latest check-out wins
            } elseif (!isset($in[$k])) {
                $in[$k] = $r; // first check-in wins
            }
        }

        $rows = [];
        foreach ($emps as $e) {
            $days = array_filter(explode(',', (string) $e['working_days']), 'strlen');
            for ($t = strtotime($f['from']); $t <= strtotime($to); $t += 86400) {
                $d = date('Y-m-d', $t);
                $k = $e['id'] . '|' . $d;
                if (!isset($in[$k]) && !in_array(date('N', $t), $days)) {
                    continue;
                }
                $i = $in[$k] ?? null;
                $o = $out[$k] ?? null;
                $inTs = $i ? strtotime($i['timestamp']) : null;
                $outTs = $o ? strtotime($o['timestamp']) : null;
                $m = Attendance_verifier::metrics($e, $d, $inTs, $outTs, $set['late_grace_minutes']);
                $shiftOver = $d < $today || time() > strtotime($d . ' ' . $e['end_time']);
                if (!$i) {
                    $status = $shiftOver ? 'Absent' : 'Pending';
                } elseif (!$o) {
                    $status = $shiftOver ? 'Incomplete' : 'Working';
                } else {
                    $status = $m['late'] ? 'Late' : 'Present';
                }
                $rows[] = [
                    'employee_id' => $e['id'], 'name' => $e['full_name'], 'code' => $e['emp_code'], 'date' => $d,
                    'workplace' => $e['workplace_name'], 'status' => $status,
                    'in_ts' => $i ? $i['timestamp'] : null, 'out_ts' => $o ? $o['timestamp'] : null,
                    'in_id' => $i && $i['captured_image'] ? $i['id'] : null, 'out_id' => $o && $o['captured_image'] ? $o['id'] : null,
                    'late' => $m['late'], 'early' => $m['early'], 'ot' => $m['ot'],
                    'edited' => ($i && $i['edited_at']) || ($o && $o['edited_at']),
                ];
            }
        }
        $want = strtolower($f['status'] ?? '');
        if (in_array($want, ['present', 'late', 'absent', 'incomplete', 'working'], true)) {
            $rows = array_filter($rows, function ($r) use ($want) { return strtolower($r['status']) === $want; });
        }
        usort($rows, function ($a, $b) { return strcmp($b['date'], $a['date']) ?: strcmp($a['name'], $b['name']); });

        return array_slice($rows, 0, $limit);
    }

    /* ---------------- admin corrections (audited; can never create attendance) ---------------- */

    /** Adjust the clock times of EXISTING verified check-in/out records for one employee-day. */
    public function correctDay($employeeId, $date, $inTime, $outTime, $note, $adminId)
    {
        $emp = $this->employee($employeeId);
        $note = trim($note);
        if (!$emp || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 'Employee or date not found.';
        }
        if ($note === '') {
            return 'A reason for the correction is required.';
        }
        $get = function ($type) use ($employeeId, $date) {
            return $this->db->where(['employee_id' => (int) $employeeId, 'att_date' => $date, 'att_type' => $type, 'verification_status' => 'verified'])
                ->order_by($type === 'in' ? 'timestamp ASC' : 'timestamp DESC')->get($this->p . 'records')->row_array();
        };
        $in = $get('in');
        $out = $get('out');
        if (!$in) {
            return 'There is no verified check-in to correct. Attendance cannot be added manually.';
        }
        $ts = function ($hhmm) use ($date) {
            return preg_match('/^\d{2}:\d{2}$/', $hhmm) ? strtotime($date . ' ' . $hhmm . ':00') : false;
        };
        $inTs = $inTime !== '' ? $ts($inTime) : strtotime($in['timestamp']);
        if ($inTs === false) {
            return 'Invalid clock-in time.';
        }
        $outTs = null;
        if ($out) {
            $outTs = $outTime !== '' ? $ts($outTime) : strtotime($out['timestamp']);
            if ($outTs === false) {
                return 'Invalid clock-out time.';
            }
            if ($outTs <= $inTs) {
                $outTs += 86400; // overnight shift
            }
            if ($outTs - $inTs > 20 * 3600) {
                return 'Clock-out must be after clock-in on the same shift.';
            }
        } elseif ($outTime !== '') {
            return 'There is no check-out record. Attendance cannot be added manually.';
        }

        $m = Attendance_verifier::metrics($emp, $date, $inTs, $outTs, $this->settings()['late_grace_minutes']);
        $now = date('Y-m-d H:i:s');
        $tag = ' [corrected by admin]';
        $this->db->trans_start();
        $this->db->where('id', $in['id'])->update($this->p . 'records', [
            'timestamp' => date('Y-m-d H:i:s', $inTs), 'is_late' => $m['late'] ? 1 : 0, 'edited_by' => (int) $adminId,
            'edited_at' => $now, 'edit_note' => mb_substr($note, 0, 255),
            'verification_reason' => mb_substr(str_replace($tag, '', $in['verification_reason']) . $tag, 0, 255),
        ]);
        if ($out) {
            $this->db->where('id', $out['id'])->update($this->p . 'records', [
                'timestamp' => date('Y-m-d H:i:s', $outTs), 'is_early_out' => $m['early'] ? 1 : 0, 'edited_by' => (int) $adminId,
                'edited_at' => $now, 'edit_note' => mb_substr($note, 0, 255),
                'verification_reason' => mb_substr(str_replace($tag, '', $out['verification_reason']) . $tag, 0, 255),
            ]);
        }
        $this->db->trans_complete();
        log_activity('Attendance corrected: ' . $emp['emp_code'] . ' on ' . $date . ' (' . $note . ')');

        return $this->db->trans_status() ? true : 'Could not save the correction.';
    }

    /** Remove the verified check-in/out records of one employee-day (and their images). */
    public function deleteDay($employeeId, $date, $adminId)
    {
        $recs = $this->db->where(['employee_id' => (int) $employeeId, 'att_date' => $date, 'verification_status' => 'verified'])
            ->get($this->p . 'records')->result_array();
        if (!$recs) {
            return 'Nothing to delete.';
        }
        foreach ($recs as $r) {
            if ($r['captured_image']) {
                @unlink($this->dir() . basename($r['captured_image']));
            }
            $this->db->delete($this->p . 'records', ['id' => $r['id']]);
        }
        log_activity('Attendance deleted: employee #' . (int) $employeeId . ' on ' . $date, $adminId);

        return true;
    }
    public function todayStats()
    {
        $today = date('Y-m-d');
        $emps = $this->employees(['active_only' => 1]);
        $map = $this->verifiedMap($today, $today);
        $out = $this->outMap($today, $today);
        $scheduled = $present = $late = $notOut = 0;
        foreach ($emps as $e) {
            if (in_array(date('N'), explode(',', (string) $e['working_days']))) {
                $scheduled++;
                $k = $e['id'] . '|' . $today;
                if (isset($map[$k])) {
                    $present++;
                    $late += (int) $map[$k]['late'];
                    $notOut += isset($out[$k]) ? 0 : 1;
                }
            }
        }

        return ['total' => count($emps), 'present' => $present, 'absent' => $scheduled - $present, 'late' => $late, 'not_out' => $notOut];
    }
}
