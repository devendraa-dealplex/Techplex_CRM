<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Attendance_dashboard_model extends App_Model
{
    const CHALLENGE_TTL = 300;
    const MAX_IMAGE_BYTES = 1500000;

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
        ];
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
        if ($name === '') {
            return 'Workplace name is required.';
        }
        if (!Attendance_verifier::validCoords($lat, $lng)) {
            return 'Valid latitude (-90..90) and longitude (-180..180) are required.';
        }
        if ($rad < 10 || $rad > 5000) {
            return 'Radius must be between 10 and 5000 metres.';
        }
        $row = ['name' => $name, 'address' => trim($d['address'] ?? ''), 'latitude' => $lat, 'longitude' => $lng, 'radius_m' => $rad, 'updated_at' => date('Y-m-d H:i:s')];
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

    /** Issues a single-use token bound to one employee; a capture is only accepted with a fresh one. */
    public function beginCapture($employeeId)
    {
        $emp = $this->employee($employeeId);
        if (!$emp || !$emp['active']) {
            return ['ok' => false, 'reason' => 'Employee not found or inactive.'];
        }
        if (!$this->settings()['allow_duplicate'] && $this->hasVerified($emp['id'], date('Y-m-d'))) {
            return ['ok' => false, 'reason' => 'Attendance already recorded.'];
        }
        $token = bin2hex(random_bytes(16));
        $c = $this->session->userdata('att_challenges') ?: [];
        $c = array_filter($c, function ($x) { return $x['ts'] > time() - self::CHALLENGE_TTL; });
        $c[$token] = ['emp' => (int) $emp['id'], 'ts' => time()];
        $this->session->set_userdata('att_challenges', $c);

        return ['ok' => true, 'token' => $token, 'employee' => [
            'name' => $emp['full_name'], 'workplace' => $emp['workplace_name'],
            'window' => substr($emp['start_time'], 0, 5) . ' - ' . substr($emp['end_time'], 0, 5),
        ]];
    }

    private function consumeToken($token, $employeeId)
    {
        $c = $this->session->userdata('att_challenges') ?: [];
        $ok = isset($c[$token]) && $c[$token]['emp'] === (int) $employeeId && $c[$token]['ts'] > time() - self::CHALLENGE_TTL;
        unset($c[$token]);
        $this->session->set_userdata('att_challenges', $c);

        return $ok;
    }

    private function hasVerified($employeeId, $date)
    {
        return $this->db->where(['employee_id' => (int) $employeeId, 'att_date' => $date, 'verification_status' => 'verified'])
            ->count_all_results($this->p . 'records') > 0;
    }

    /**
     * The only path that creates a "verified" record. Status is decided here, never by the client.
     * @return array [ok, reason, distance_m?, id?]
     */
    public function markAttendance($employeeId, $token, $lat, $lng, $accuracy, $imageDataUrl, $staffId)
    {
        $emp = $this->employee($employeeId);
        if (!$emp || !$emp['active']) {
            return ['ok' => false, 'reason' => 'Employee not found or inactive.'];
        }
        $set = $this->settings();
        $now = time();
        $date = date('Y-m-d', $now);

        if (!$set['allow_duplicate'] && $this->hasVerified($emp['id'], $date)) {
            return ['ok' => false, 'reason' => 'Attendance already recorded.'];
        }

        $reason = null;
        $bytes  = null;
        $tokenOk = $this->consumeToken((string) $token, $emp['id']);
        if (!$tokenOk) {
            $reason = 'Camera verification required. Session expired - retake the photo.';
        } else {
            $bytes = $this->decodeImage((string) $imageDataUrl);
            if ($bytes === null) {
                $reason = 'Camera verification required.';
            } elseif ($this->db->where('image_hash', hash('sha256', $bytes))->count_all_results($this->p . 'records') > 0) {
                $reason = 'Camera verification required. This image was already used.';
                $bytes = null;
            }
        }

        $eval = ['ok' => false, 'reason' => $reason, 'distance_m' => null, 'is_late' => 0];
        $accuracy = is_numeric($accuracy) ? (float) $accuracy : null;
        if ($reason === null) {
            $eval = Attendance_verifier::evaluate($emp, $emp, $lat, $lng, $accuracy, $now, $set);
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
            'employee_id' => $emp['id'], 'workplace_id' => $emp['workplace_id'], 'att_date' => $date,
            'timestamp' => date('Y-m-d H:i:s', $now),
            'latitude' => Attendance_verifier::validCoords($lat, $lng) ? $lat : null,
            'longitude' => Attendance_verifier::validCoords($lat, $lng) ? $lng : null,
            'accuracy_m' => $accuracy !== null ? (int) $accuracy : null,
            'distance_m' => $eval['distance_m'], 'captured_image' => $file,
            'image_hash' => $bytes !== null ? hash('sha256', $bytes) : null,
            'verification_status' => $verified ? 'verified' : 'failed',
            'verification_reason' => mb_substr($eval['reason'], 0, 255),
            'is_late' => $eval['is_late'],
            'unique_key' => ($verified && !$set['allow_duplicate']) ? 1 : null,
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
            return null;
        }
        $raw = base64_decode($m[1], true);
        if ($raw === false || strlen($raw) < 3000 || strlen($raw) > self::MAX_IMAGE_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($raw);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || $info[0] < 240 || $info[1] < 240) {
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
        $this->db->select('r.*, e.full_name, e.emp_code, e.role, w.name AS workplace_name')
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
            $this->db->where(['r.is_late' => 1, 'r.verification_status' => 'verified']);
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
            ->where(['att_date >=' => $from, 'att_date <=' => $to, 'verification_status' => 'verified'])
            ->group_by(['employee_id', 'att_date'])->get($this->p . 'records')->result_array();
        $map = [];
        foreach ($rows as $r) {
            $map[$r['employee_id'] . '|' . $r['att_date']] = $r;
        }

        return $map;
    }

    /** Expected/present/late per employee and per workplace over a date range (capped at today). */
    public function summary($from, $to)
    {
        $to = min($to, date('Y-m-d'));
        $map = $this->verifiedMap($from, $to);
        $emps = $this->employees(['active_only' => 1]);
        $byEmp = $byLoc = [];
        $expected = $present = 0;
        foreach ($emps as $e) {
            $days = array_filter(explode(',', (string) $e['working_days']), 'strlen');
            $exp = $pre = $late = 0;
            for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
                if (in_array(date('N', $t), $days)) {
                    $exp++;
                    $k = $e['id'] . '|' . date('Y-m-d', $t);
                    if (isset($map[$k])) {
                        $pre++;
                        $late += (int) $map[$k]['late'];
                    }
                }
            }
            $byEmp[] = ['id' => $e['id'], 'name' => $e['full_name'], 'code' => $e['emp_code'], 'role' => $e['role'], 'expected' => $exp, 'present' => $pre, 'late' => $late];
            $l = &$byLoc[$e['workplace_id']];
            $l = $l ?? ['name' => $e['workplace_name'], 'expected' => 0, 'present' => 0, 'late' => 0];
            $l['expected'] += $exp;
            $l['present'] += $pre;
            $l['late'] += $late;
            unset($l);
            $expected += $exp;
            $present += $pre;
        }

        return ['employees' => $byEmp, 'locations' => array_values($byLoc), 'expected' => $expected, 'present' => $present];
    }

    public function todayStats()
    {
        $today = date('Y-m-d');
        $emps = $this->employees(['active_only' => 1]);
        $map = $this->verifiedMap($today, $today);
        $scheduled = $present = $late = 0;
        foreach ($emps as $e) {
            if (in_array(date('N'), explode(',', (string) $e['working_days']))) {
                $scheduled++;
                if (isset($map[$e['id'] . '|' . $today])) {
                    $present++;
                    $late += (int) $map[$e['id'] . '|' . $today]['late'];
                }
            }
        }

        return ['total' => count($emps), 'present' => $present, 'absent' => $scheduled - $present, 'late' => $late];
    }
}
