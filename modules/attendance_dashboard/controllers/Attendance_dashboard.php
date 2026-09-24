<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Attendance_dashboard extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('attendance_dashboard_model', 'att');
        if (!in_array($this->router->fetch_method(), ['image', 'begin', 'submit'])) {
            $this->att->syncStaff(); // keep employees in step with Setup > Staff
        }
    }

    private function need($cap)
    {
        if (staff_cant($cap, ATT_MODULE)) {
            access_denied(ATT_MODULE);
        }
    }

    /** Employee id for an employee who may only act on their own attendance; null for kiosk/admin users. */
    private function selfOnlyEmployee()
    {
        if (staff_can('view', ATT_MODULE) || staff_can('mark', ATT_MODULE)) {
            return null;
        }
        if (!staff_can('self', ATT_MODULE)) {
            access_denied(ATT_MODULE);
        }

        return (int) $this->att->employeeByStaff(get_staff_user_id()); // 0 when no linked employee: sees nothing
    }

    private function json($data, $code = 200)
    {
        $this->output->set_status_header($code)->set_content_type('application/json')->set_output(json_encode($data));
    }

    private function range($defaultFrom = null)
    {
        $defaultFrom = $defaultFrom ?: date('Y-m-01');
        $from = $this->input->get('from') ?: $defaultFrom;
        $to   = $this->input->get('to') ?: date('Y-m-d');
        $ok   = function ($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };

        return [$ok($from) ? $from : $defaultFrom, $ok($to) ? $to : date('Y-m-d')];
    }

    private function filters($defaultFrom = null)
    {
        list($from, $to) = $this->range($defaultFrom);

        return [
            'from' => $from, 'to' => $to,
            'q' => trim((string) $this->input->get('q')), 'role' => trim((string) $this->input->get('role')),
            'workplace_id' => (int) $this->input->get('workplace_id'), 'status' => (string) $this->input->get('status'),
        ];
    }

    /* ---------------- pages ---------------- */

    public function index()
    {
        $own = $this->selfOnlyEmployee();
        if ($own !== null) {
            $f = $this->filters(date('Y-m-01'));
            $f['employee_id'] = $own ?: -1;
            if ($this->input->get('export')) {
                $this->exportDayRows($own ? $this->att->dayRows($f, 10000) : [], $f);
            }
            $this->load->view('dashboard', [
                'title' => 'My Attendance', 'f' => $f, 'today' => null, 'pct' => 0, 'self' => true,
                'rows' => $own ? $this->att->dayRows($f) : [], 'workplaces' => [],
            ]);

            return;
        }
        $this->need('view');
        $f = $this->filters(date('Y-m-d')); // dashboard defaults to today
        if ($this->input->get('export')) {
            $this->exportDayRows($this->att->dayRows($f, 10000), $f);
        }
        $sum = $this->att->summary($f['from'], $f['to']);
        $this->load->view('dashboard', [
            'title' => 'Attendance Dashboard', 'f' => $f, 'today' => $this->att->todayStats(),
            'pct' => $sum['expected'] ? round($sum['present'] / $sum['expected'] * 100, 1) : 0,
            'rows' => $this->att->dayRows($f), 'workplaces' => $this->att->workplaces(), 'self' => false,
        ]);
    }

    /** CSV of the dashboard table for the current filters (same rows as on screen, not just the first page). */
    private function exportDayRows(array $rows, array $f)
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="attendance_' . $f['from'] . '_to_' . $f['to'] . '.csv"');
        $o = fopen('php://output', 'w');
        fwrite($o, "\xEF\xBB\xBF"); // lets Excel read UTF-8 names correctly
        fputcsv($o, ['Employee ID', 'Employee', 'Date', 'Location', 'Status', 'Clock in', 'Clock out', 'Late (min)', 'Early exit (min)', 'Overtime (min)']);
        foreach ($rows as $r) {
            fputcsv($o, [
                $this->csvSafe($r['code']), $this->csvSafe($r['name']), $r['date'], $this->csvSafe((string) $r['workplace']), $r['status'],
                $r['in_ts'] ? date('H:i', strtotime($r['in_ts'])) : '', $r['out_ts'] ? date('H:i', strtotime($r['out_ts'])) : '',
                $r['late'], $r['early'], $r['ot'],
            ]);
        }
        fclose($o);
        exit;
    }

    /** Admin-only: correct the clock times of an existing check-in/out (never adds attendance). */
    public function record_edit()
    {
        $this->adminOnly();
        $p = $this->input->post();
        $res = $this->att->correctDay((int) $p['employee_id'], (string) $p['date'], (string) ($p['in_time'] ?? ''), (string) ($p['out_time'] ?? ''), (string) ($p['note'] ?? ''), get_staff_user_id());
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Attendance corrected.' : $res);
        redirect(admin_url(ATT_MODULE));
    }

    /** Admin-only. */
    public function record_delete()
    {
        $this->adminOnly();
        $p = $this->input->post();
        $res = $this->att->deleteDay((int) $p['employee_id'], (string) $p['date'], get_staff_user_id());
        set_alert($res === true ? 'success' : 'warning', $res === true ? 'Attendance deleted.' : $res);
        redirect(admin_url(ATT_MODULE));
    }

    private function adminOnly()
    {
        if (!is_admin() || !$this->input->post()) {
            access_denied(ATT_MODULE);
        }
    }

    /* ---------------- leave requests ---------------- */

    /** Employee id when the current login may only see/apply its own leave; null for view/admin users. */
    private function selfLeaveEmployee()
    {
        if (staff_can('view', ATT_MODULE)) {
            return null;
        }
        if (!staff_can('self', ATT_MODULE)) {
            access_denied(ATT_MODULE);
        }

        return (int) $this->att->employeeByStaff(get_staff_user_id());
    }

    public function leaves()
    {
        $own = $this->selfLeaveEmployee();
        if ($own !== null) {
            $this->load->view('leaves', [
                'title' => 'Leave Requests', 'self' => true, 'canReview' => false, 'employees' => [],
                'types' => $this->att->leaveTypes(), 'me' => $own,
                'rows' => $own ? $this->att->leaves(['employee_id' => $own], 200) : [],
            ]);

            return;
        }
        $f = ['status' => (string) $this->input->get('status'), 'employee_id' => (int) $this->input->get('employee_id')];
        $this->load->view('leaves', [
            'title' => 'Leave Requests', 'self' => false, 'canReview' => staff_can('edit', ATT_MODULE), 'me' => null,
            'employees' => $this->att->employees(['active_only' => 1]), 'types' => $this->att->leaveTypes(),
            'rows' => $this->att->leaves($f), 'f' => $f,
        ]);
    }

    /** Self-service employees file for themselves; view/create users may file on behalf of anyone. */
    public function leave_apply()
    {
        $empId = (int) $this->input->post('employee_id');
        $own = $this->selfLeaveEmployee();
        if ($own !== null) {
            if (!$own || $own !== $empId) {
                access_denied(ATT_MODULE);
            }
        } elseif (staff_cant('view', ATT_MODULE) && staff_cant('create', ATT_MODULE)) {
            access_denied(ATT_MODULE);
        }
        $res = $this->att->applyLeave($empId, (string) $this->input->post('from_date'), (string) $this->input->post('to_date'),
            (string) $this->input->post('leave_type'), (string) $this->input->post('reason'), get_staff_user_id(),
            (bool) $this->input->post('half_day'), (string) $this->input->post('half_session'));
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Leave request submitted.' : $res);
        redirect(admin_url(ATT_MODULE . '/leaves'));
    }

    /** Approve/reject; requires edit rights so it can be delegated without full admin. */
    public function leave_review($id)
    {
        $this->need('edit');
        $res = $this->att->reviewLeave((int) $id, (string) $this->input->post('decision'), (string) $this->input->post('note'), get_staff_user_id());
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Leave request updated.' : $res);
        redirect(admin_url(ATT_MODULE . '/leaves'));
    }

    /** Employee withdraws their own still-pending request. */
    public function leave_cancel($id)
    {
        $own = (int) $this->att->employeeByStaff(get_staff_user_id());
        $res = $own ? $this->att->cancelLeave((int) $id, $own) : 'Not permitted.';
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Leave request cancelled.' : $res);
        redirect(admin_url(ATT_MODULE . '/leaves'));
    }

    public function employees()
    {
        $this->need('view');
        $this->load->view('employees', [
            'title' => 'Employees', 'defaults' => $this->att->defaultSchedule(), 'f' => $this->filters(), 'workplaces' => $this->att->workplaces(),
            'rows' => $this->att->employees($this->filters()),
        ]);
    }

    public function employee_save()
    {
        $id = (int) $this->input->post('id');
        $this->need($id ? 'edit' : 'create');
        $res = $this->att->saveEmployee($this->input->post(), isset($_FILES['photo']) ? $_FILES['photo'] : null);
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Employee saved.' : $res);
        redirect(admin_url(ATT_MODULE . '/employees'));
    }

    public function employee_delete($id)
    {
        $this->need('delete');
        if ($this->input->post()) {
            $res = $this->att->deleteEmployee($id);
            set_alert($res === true ? 'success' : 'warning', $res === true ? 'Employee deleted.' : $res);
        }
        redirect(admin_url(ATT_MODULE . '/employees'));
    }

    public function workplaces()
    {
        $this->need('view');
        $this->load->view('workplaces', ['title' => 'Workplaces', 'rows' => $this->att->workplaces()]);
    }

    public function workplace_save()
    {
        $this->need($this->input->post('id') ? 'edit' : 'create');
        $res = $this->att->saveWorkplace($this->input->post());
        set_alert($res === true ? 'success' : 'danger', $res === true ? 'Workplace saved.' : $res);
        redirect(admin_url(ATT_MODULE . '/workplaces'));
    }

    public function workplace_delete($id)
    {
        $this->need('delete');
        if ($this->input->post()) {
            $res = $this->att->deleteWorkplace($id);
            set_alert($res === true ? 'success' : 'warning', $res === true ? 'Workplace deleted.' : $res);
        }
        redirect(admin_url(ATT_MODULE . '/workplaces'));
    }

    public function records()
    {
        $this->need('view');
        $f = $this->filters();
        $this->load->view('records', ['title' => 'Attendance Records', 'f' => $f, 'rows' => $this->att->records($f), 'workplaces' => $this->att->workplaces()]);
    }

    public function reports()
    {
        $this->need('view');
        $f = $this->filters();
        $sum = $this->att->summary($f['from'], $f['to']);
        if ($this->input->get('export')) {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="attendance_' . $f['from'] . '_' . $f['to'] . '.csv"');
            $o = fopen('php://output', 'w');
            fputcsv($o, ['Employee ID', 'Employee', 'Role', 'Expected days', 'Present', 'Late', 'Hours worked', 'Missing check-outs', 'Attendance %']);
            foreach ($sum['employees'] as $e) {
                fputcsv($o, [$this->csvSafe($e['code']), $this->csvSafe($e['name']), $this->csvSafe($e['role']), $e['expected'], $e['present'], $e['late'], round($e['minutes'] / 60, 2), $e['missing'], $e['expected'] ? round($e['present'] / $e['expected'] * 100, 1) : 0]);
            }
            fclose($o);
            exit;
        }
        $this->load->view('reports', ['title' => 'Reports', 'f' => $f, 'sum' => $sum]);
    }

    private function csvSafe($v)
    {
        return preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : $v; // block spreadsheet formula injection
    }

    public function settings()
    {
        $this->need('edit');
        if ($this->input->post()) {
            update_option('att_allow_duplicate', $this->input->post('allow_duplicate') ? '1' : '0');
            update_option('att_early_minutes', max(0, min(240, (int) $this->input->post('early_minutes'))));
            update_option('att_late_grace_minutes', max(0, min(120, (int) $this->input->post('late_grace_minutes'))));
            update_option('att_checkout_late_minutes', max(0, min(1440, (int) $this->input->post('checkout_late_minutes'))));
            update_option('att_max_accuracy_m', max(10, min(1000, (int) $this->input->post('max_accuracy_m'))));
            $res = $this->att->saveDefaultSchedule((string) $this->input->post('work_start'), (string) $this->input->post('work_end'),
                (array) $this->input->post('work_days'), (bool) $this->input->post('apply_all'), get_staff_user_id());
            if (is_string($res)) {
                set_alert('danger', $res);
                redirect(admin_url(ATT_MODULE . '/settings'));
            }
            set_alert('success', $res ? 'Settings saved. Working hours applied to ' . $res . ' employees.' : 'Settings saved.');
            redirect(admin_url(ATT_MODULE . '/settings'));
        }
        $this->load->view('settings', ['title' => 'Attendance Settings', 's' => $this->att->settings(), 'def' => $this->att->defaultSchedule()]);
    }

    /* ---------------- attendance capture ---------------- */

    public function mark()
    {
        $own = $this->selfOnlyEmployee();
        if ($own !== null) {
            $this->load->view('mark', ['title' => 'Mark Attendance', 'lock' => true, 'employees' => $own ? $this->att->employees(['employee_id' => $own, 'active_only' => 1]) : []]);

            return;
        }
        $this->need('mark');
        $this->load->view('mark', ['title' => 'Mark Attendance', 'lock' => false, 'employees' => array_filter($this->att->employees(['active_only' => 1]), function ($e) { return $e['workplace_id'] > 0; })]);
    }

    /** Step 1: identify employee, receive a single-use capture token. */
    public function begin()
    {
        if ($deny = $this->ownershipError()) {
            return $this->json(['ok' => false, 'reason' => $deny]);
        }
        $this->json($this->att->beginCapture((int) $this->input->post('employee_id'), $this->input->post('type') === 'out' ? 'out' : 'in'));
    }

    /** Step 2: server verifies image + location + time. No status is accepted from the client. */
    public function submit()
    {
        if ($deny = $this->ownershipError()) {
            return $this->json(['ok' => false, 'reason' => $deny]);
        }
        $p = $this->input->post(null, true);
        $this->json($this->att->markAttendance(
            (int) ($p['employee_id'] ?? 0), $p['token'] ?? '', $p['lat'] ?? null, $p['lng'] ?? null,
            $p['accuracy'] ?? null, $this->rawImage(), get_staff_user_id(), ($p['type'] ?? '') === 'out' ? 'out' : 'in'
        ));
    }

    /** Kiosk users may mark anyone; self-service users only the employee linked to their own login. */
    private function ownershipError()
    {
        $own = $this->selfOnlyEmployee();
        if ($own === null) {
            $this->need('mark');

            return null;
        }

        return ($own && (int) $this->input->post('employee_id') === $own) ? null : 'You can only mark your own attendance.';
    }

    /** global_xss_filtering is on and would corrupt the base64 payload, so read it from the raw body. */
    private function rawImage()
    {
        parse_str((string) file_get_contents('php://input'), $raw);

        return (string) ($raw['image'] ?? '');
    }

    /** Authorised streaming of private images. */
    public function image($type, $id)
    {
        $name = null;
        if ($type === 'record' && (staff_can('view', ATT_MODULE) || staff_can('self', ATT_MODULE))) {
            $r = $this->att->record($id);
            if ($r && !staff_can('view', ATT_MODULE) && (int) $r['employee_id'] !== (int) $this->att->employeeByStaff(get_staff_user_id())) {
                access_denied(ATT_MODULE);
            }
            $name = $r ? $r['captured_image'] : null;
        } elseif ($type === 'photo' && (staff_can('view', ATT_MODULE) || staff_can('mark', ATT_MODULE))) {
            $e = $this->att->employee($id);
            $name = $e ? $e['photo'] : null;
        } else {
            access_denied(ATT_MODULE);
        }
        $path = $name ? $this->att->dir() . basename($name) : null;
        if (!$path || !is_file($path)) {
            show_404();
        }
        header('Content-Type: ' . (substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg'));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
