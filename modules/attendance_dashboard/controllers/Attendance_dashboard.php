<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Attendance_dashboard extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('attendance_dashboard_model', 'att');
    }

    private function need($cap)
    {
        if (staff_cant($cap, ATT_MODULE)) {
            access_denied(ATT_MODULE);
        }
    }

    private function json($data, $code = 200)
    {
        $this->output->set_status_header($code)->set_content_type('application/json')->set_output(json_encode($data));
    }

    private function range()
    {
        $from = $this->input->get('from') ?: date('Y-m-01');
        $to   = $this->input->get('to') ?: date('Y-m-d');
        $ok   = function ($d) { return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };

        return [$ok($from) ? $from : date('Y-m-01'), $ok($to) ? $to : date('Y-m-d')];
    }

    private function filters()
    {
        list($from, $to) = $this->range();

        return [
            'from' => $from, 'to' => $to,
            'q' => trim((string) $this->input->get('q')), 'role' => trim((string) $this->input->get('role')),
            'workplace_id' => (int) $this->input->get('workplace_id'), 'status' => (string) $this->input->get('status'),
        ];
    }

    /* ---------------- pages ---------------- */

    public function index()
    {
        $this->need('view');
        $f = $this->filters();
        $sum = $this->att->summary($f['from'], $f['to']);
        $this->load->view('dashboard', [
            'title' => 'Attendance Dashboard', 'f' => $f, 'today' => $this->att->todayStats(), 'sum' => $sum,
            'pct' => $sum['expected'] ? round($sum['present'] / $sum['expected'] * 100, 1) : 0,
            'recent' => $this->att->records($f, 15), 'workplaces' => $this->att->workplaces(),
        ]);
    }

    public function employees()
    {
        $this->need('view');
        $this->load->view('employees', [
            'title' => 'Employees', 'f' => $this->filters(), 'workplaces' => $this->att->workplaces(),
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
            fputcsv($o, ['Employee ID', 'Employee', 'Role', 'Expected days', 'Present', 'Late', 'Attendance %']);
            foreach ($sum['employees'] as $e) {
                fputcsv($o, [$this->csvSafe($e['code']), $this->csvSafe($e['name']), $this->csvSafe($e['role']), $e['expected'], $e['present'], $e['late'], $e['expected'] ? round($e['present'] / $e['expected'] * 100, 1) : 0]);
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
            update_option('att_max_accuracy_m', max(10, min(1000, (int) $this->input->post('max_accuracy_m'))));
            set_alert('success', 'Settings saved.');
            redirect(admin_url(ATT_MODULE . '/settings'));
        }
        $this->load->view('settings', ['title' => 'Attendance Settings', 's' => $this->att->settings()]);
    }

    /* ---------------- attendance capture ---------------- */

    public function mark()
    {
        $this->need('mark');
        $this->load->view('mark', ['title' => 'Mark Attendance', 'employees' => $this->att->employees(['active_only' => 1])]);
    }

    /** Step 1: identify employee, receive a single-use capture token. */
    public function begin()
    {
        $this->need('mark');
        $this->json($this->att->beginCapture((int) $this->input->post('employee_id')));
    }

    /** Step 2: server verifies image + location + time. No status is accepted from the client. */
    public function submit()
    {
        $this->need('mark');
        $p = $this->input->post(null, true);
        $this->json($this->att->markAttendance(
            (int) ($p['employee_id'] ?? 0), $p['token'] ?? '', $p['lat'] ?? null, $p['lng'] ?? null,
            $p['accuracy'] ?? null, $this->input->post('image'), get_staff_user_id()
        ));
    }

    /** Authorised streaming of private images. */
    public function image($type, $id)
    {
        $name = null;
        if ($type === 'record' && staff_can('view', ATT_MODULE)) {
            $r = $this->att->record($id);
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
