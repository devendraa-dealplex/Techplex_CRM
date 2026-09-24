<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * The guard class, required HERE and not left to the model.
 *
 * Every other library this controller calls statically (Payplex_staff_types,
 * Workforce_access, ...) arrives only because the model's own require_once
 * block happens to run first. That is load-order luck. An authorization gate
 * must not depend on it, so the controller requires its own dependency.
 *
 * require_once, never a class_exists() guard: if this file is missing the
 * request must die rather than quietly skip the check. A fatal is an outage;
 * a skipped authorization check is the incident we are fixing.
 */
require_once __DIR__ . '/../libraries/Workforce_emergency_guard.php';
/* SHADOW MODE ONLY. The emergency guard above remains the authoritative
   decision; these two compute a comparison and can never grant access. */
require_once __DIR__ . '/../libraries/Workforce_scope.php';
require_once __DIR__ . '/../libraries/Workforce_shadow.php';

/**
 * Staff — Payplex Staff System (Batch 1).
 *
 * Classification, lifecycle, permission templates, role matrix and audit.
 * Every capability is checked server-side (guard()); hiding a menu is never
 * treated as sufficient. Approval enforces maker != approver in the model.
 */
class Staff extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_staff/payplex_staff_model', 'm');
    }

    private function guard($cap)
    {
        if (!payplex_staff_can($cap)) { access_denied('payplex_staff'); }
    }

    /* ===================================================================== */
    /*  EMERGENCY IDOR GUARD — controller half. TEMPORARY.                    */
    /*                                                                        */
    /*  guard() answers "may you use this screen". It never answered "may you  */
    /*  use it on THAT person", which is why attendance(), activity(),         */
    /*  performance() and view() returned whoever the URL named, and why six   */
    /*  write paths — lifecycle exit, bank verification, expense transition,   */
    /*  document decision, activity verification, profile save — were reachable */
    /*  for any staff id by anyone holding the screen's capability.            */
    /*                                                                        */
    /*  Policy until Workforce_scope ships: SELF ONLY, except full             */
    /*  administrators, whose cross-staff access is audited. No manager rung,  */
    /*  no TL rung, no team rung.                                             */
    /* ===================================================================== */

    /**
     * Resolve the subject of a staff-id route, server-side.
     *
     * The raw value is passed through untouched so the guard can REJECT a
     * tampered id rather than cast it. An empty id means "me", resolved from
     * the session and never from the request.
     */
    private function guardSubject($staffId)
    {
        if ($staffId === 0 || $staffId === '0' || $staffId === '' || $staffId === null) {
            return $this->actor();
        }
        return $staffId;
    }

    /**
     * The gate. Refuses with 404 and no record data.
     *
     * 404 rather than 403 on purpose: 403 confirms the record exists, and a
     * refusal that confirms existence is an enumeration oracle. The body is the
     * application's own 404 page and carries nothing about the record.
     */
    private function guardStaffTarget($staffId, $resource, $route = null)
    {
        $actor = $this->actor();
        $detectable = Workforce_emergency_guard::adminDetectable();
        $isAdmin = $detectable ? (bool) is_admin() : null;

        $d = Workforce_emergency_guard::decide($actor, $staffId, $isAdmin, $detectable);

        if (Workforce_emergency_guard::shouldAudit($d, $actor, $staffId)) {
            $this->m->logGuardDecision($d, $actor, $staffId, $resource);
        }
        /* SHADOW COMPARISON - returns void, cannot influence the line below. */
        $this->shadowCompare($route, $staffId, !empty($d['allowed']));

        if (empty($d['allowed'])) { show_404(); }
        return (int) $staffId;
    }

    /**
     * Record what the new policy WOULD have decided. Never grants anything.
     * Returns void; there is no value for a caller to mistake for authorization.
     */
    private function shadowCompare($route, $staffId, $guardAllowed)
    {
        try {
            if (!class_exists('Workforce_shadow') || !Workforce_shadow::enabled()) { return; }
            $map = Workforce_scope::callSiteMap();
            $key = (string) $route;
            if ($key === '' || !isset($map[$key])) { return; }
            $spec = $map[$key];
            if (!isset($spec['action'])) { return; }
            $ctx = Workforce_shadow::context($this, $this->actor(), (int) $staffId);
            Workforce_shadow::compare($key, $spec['domain'], $spec['action'], $guardAllowed, $ctx);
        } catch (Exception $e) {
            /* shadow must never alter the response */
        } catch (Throwable $e) {
        }
    }

    /** The same gate for endpoints that take a RECORD id. */
    private function guardRecordOwner($type, $recordId, $resource, $route = null)
    {
        $owner = $this->m->guardOwnerOf($type, $recordId);
        return $this->guardStaffTarget($owner ?: 0, $resource, $route);
    }

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    public function index()
    {
        $this->guard('view');
        $filters = array(
            'status' => (string) $this->input->get('status'),
            'employment_type' => (string) $this->input->get('type'),
            'classification_required' => $this->input->get('needs_class') ? 1 : 0,
        );
        $data['title']    = 'Staff Classification';
        $data['rows']     = $this->m->currentProfiles($filters);
        $data['summary']  = $this->m->summary();
        $data['byType']   = $this->m->byType();
        $data['types']    = Payplex_staff_types::options();
        $data['statuses'] = Payplex_staff_lifecycle::states();
        $data['canManage']= payplex_staff_can('manage');
        $this->load->view('payplex_staff/staff_list', $data);
    }

    public function form($staffId = 0)
    {
        $this->guard('manage');
        if ($staffId !== 0 && $staffId !== '0' && $staffId !== '' && $staffId !== null) {
            $staffId = $this->guardStaffTarget($staffId, 'staff_profile', 'form');
        }
        $staffId = (int) $staffId;
        $data['title']    = $staffId ? 'Classify Staff' : 'Classify Staff';
        $data['profile']  = $staffId ? $this->m->currentProfile($staffId) : null;
        $data['core']     = $this->m->coreStaff();
        $data['types']    = Payplex_staff_types::options();
        $data['managers'] = $this->m->currentProfiles();
        $this->load->view('payplex_staff/staff_form', $data);
    }

    /**
     * JSON used by the Classify Staff form to auto-fill itself when a staff member is picked.
     * Existing profile wins; otherwise the CRM account supplies what it knows. Gated exactly
     * like form(): the same manage capability and the same per-staff guard, so nobody can read
     * another person's profile through this that they could not open through the form.
     */
    public function profile_data($staffId = 0)
    {
        $this->guard('manage');
        $staffId = $this->guardStaffTarget($staffId, 'staff_profile', 'form');
        $out = array('source' => 'none', 'fields' => new stdClass());

        $p = $this->m->currentProfile((int) $staffId);
        if ($p) {
            $keys = array('employee_code', 'employment_type', 'official_email', 'official_mobile', 'department',
                'designation', 'branch', 'territory', 'reporting_manager_id', 'joining_date', 'probation_end_date',
                'payout_frequency', 'emergency_contact', 'salary_eligibility', 'commission_eligibility',
                'expense_eligibility', 'tada_eligibility', 'attendance_required', 'kyc_status', 'pan_status',
                'target_plan', 'commission_plan');
            $f = array();
            foreach ($keys as $k) {
                if (property_exists($p, $k)) { $f[$k] = $p->$k === null ? '' : (string) $p->$k; }
            }
            $out = array(
                'source' => 'profile', 'fields' => $f,
                'meta' => array('version' => (int) $p->version, 'status' => (string) $p->status,
                    'classification_required' => (int) $p->classification_required),
            );
        } else {
            $c = $this->m->coreStaffMember((int) $staffId);
            if ($c) {
                $dept = $this->db->select('d.name')->from(db_prefix() . 'staff_departments sd')
                    ->join(db_prefix() . 'departments d', 'd.departmentid = sd.departmentid')
                    ->where('sd.staffid', (int) $staffId)->limit(1)->get()->row();
                $out = array('source' => 'core', 'fields' => array(
                    'official_email'  => (string) $c->email,
                    'official_mobile' => (string) ($c->phonenumber ?? ''),
                    'department'      => $dept ? (string) $dept->name : '',
                ));
            }
        }
        $this->output->set_content_type('application/json')->set_output(json_encode($out));
    }

    public function store()
    {
        $this->guard('manage');
        /* The POST carries its own staff_id. Gating the GET that renders the
           form is not gating the POST that saves it. */
        $posted = $this->input->post('staff_id');
        if ($posted !== null && $posted !== '' && (int) $posted !== 0) {
            $this->guardStaffTarget($posted, 'staff_profile', 'store');
        }
        $res = $this->m->saveProfile($this->input->post(), $this->actor());
        if (!empty($res['ok'])) {
            $msg = 'Staff profile saved.' . (!empty($res['versioned']) ? ' A new effective-dated version was created (employment type changed).' : '');
            if (!empty($res['warnings'])) { $msg .= ' Note: ' . implode(', ', $res['warnings']) . '.'; }
            set_alert('success', $msg);
            redirect(admin_url('payplex_staff/staff/view/' . (int) $this->input->post('staff_id')));
        }
        set_alert('warning', 'Could not save: ' . implode(', ', array_map(function ($e) { return str_replace('_', ' ', $e); }, $res['errors'])));
        redirect(admin_url('payplex_staff/staff/form/' . (int) $this->input->post('staff_id')));
    }

    public function view($staffId)
    {
        $this->guard('view');
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'staff_profile', 'view');
        $p = $this->m->currentProfile((int) $staffId);
        if (!$p) { set_alert('warning', 'No profile — create one first.'); redirect(admin_url('payplex_staff/staff/form/' . (int) $staffId)); }
        $data['title']    = 'Staff #' . (int) $staffId;
        $data['p']        = $p;
        $data['versions'] = $this->m->profileVersions((int) $staffId);
        $data['canApprove'] = payplex_staff_can('approve');
        $data['canLifecycle'] = payplex_staff_can('lifecycle');
        $data['canManage'] = payplex_staff_can('manage');
        $data['financialBlocked'] = Payplex_staff_profile::financialBlocked((array) $p);
        $this->load->view('payplex_staff/staff_view', $data);
    }

    /** Lifecycle action: submit_docs|submit|verify|return|approve|activate|suspend|reinstate|issue_notice|exit|archive */
    public function act($staffId, $action)
    {
        // approve/verify/lifecycle need their own caps; others need manage
        if ($action === 'approve') { $this->guard('approve'); }
        elseif ($action === 'verify') { $this->guard('verify'); }
        elseif (in_array($action, array('suspend','reinstate','issue_notice','exit','archive'), true)) { $this->guard('lifecycle'); }
        else { $this->guard('submit'); }

        /* A lifecycle transition is a WRITE. Anyone holding `lifecycle` could
           exit any employee by changing the id in the URL. */
        $staffId = $this->guardStaffTarget($staffId, 'staff_profile', 'act');
        $res = $this->m->transition((int) $staffId, $action, $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Staff -> ' . $res['status'] . '.' : 'Cannot ' . $action . ': ' . str_replace('_', ' ', (string) $res['error']));
        redirect(admin_url('payplex_staff/staff/view/' . (int) $staffId));
    }

    /**
     * Who holds what, and which grants nobody can see.
     *
     * Read-only. It changes nothing: its purpose is that four classes of
     * permission problem stop being invisible.
     */
    public function access_matrix()
    {
        $this->guard('view');

        $audit = $this->m->accessAudit();
        $data['title']     = 'Access matrix';
        $data['staff']     = $audit['staff'];
        $data['counts']    = $audit['counts'];
        $data['unreached'] = Workforce_access::unreachedCapabilities(
            Workforce_access::payplexCapabilityMap(),
            $this->m->grantedCapabilities()
        );
        $this->load->view('payplex_staff/access_matrix', $data);
    }

    /**
     * Attendance for one person and one day.
     *
     * `attendance_required` has been stored, set from the engagement type,
     * editable on the form and printed on the staff view since batch 1, and
     * nothing in the module ever read it. This is the screen that honours it.
     */
    public function attendance($staffId = 0)
    {
        $this->guard('view');
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'attendance', 'attendance');

        $day = trim((string) $this->input->get('day'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { $day = date('Y-m-d'); }

        $member = $this->m->coreStaffMember($staffId);
        if (!$member) {
            set_alert('warning', 'Staff member #' . $staffId . ' does not exist.');
            redirect(admin_url('payplex_staff/staff'));
        }

        $data['title']  = 'Attendance';
        $data['member'] = $member;
        $data['day']    = $day;
        $data['att']    = $this->m->attendanceDay($staffId, $day);
        $data['is_self'] = $staffId === (int) get_staff_user_id();
        $this->load->view('payplex_staff/attendance', $data);
    }

    /** Record a check-in or check-out for oneself. */
    public function attendance_mark($direction = '')
    {
        $this->guard('view');
        if (!in_array($direction, array('in', 'out'), true)) {
            set_alert('danger', 'Unrecognised attendance action.');
            redirect(admin_url('payplex_staff/staff/attendance'));
        }
        /*
         * Self-recorded only. Marking attendance ON BEHALF OF somebody else is a
         * different act with a different risk, and it is not offered here rather
         * than being offered without a control.
         */
        $me  = (int) get_staff_user_id();
        $res = $this->m->markAttendance($me, $direction, $me);
        set_alert($res['ok'] ? 'success' : 'warning',
            $res['ok'] ? ('Checked ' . $direction . ' at ' . date('H:i') . '.') : $res['reason']);
        redirect(admin_url('payplex_staff/staff/attendance'));
    }

    public function templates()
    {
        $this->guard('perm_templates');
        $data['title']     = 'Permission Templates';
        $data['templates'] = $this->m->templates();
        $data['commissionProfile'] = Payplex_staff_perms::commissionEmployeeProfile();
        $data['roles']     = Payplex_staff_perms::roles();
        $this->load->view('payplex_staff/perm_templates', $data);
    }

    public function template_store()
    {
        $this->guard('perm_templates');
        $post = $this->input->post();
        $post['allowed'] = array_filter(array_map('trim', explode(',', (string) $this->input->post('allowed'))));
        $post['prohibited'] = array_filter(array_map('trim', explode(',', (string) $this->input->post('prohibited'))));
        $res = $this->m->saveTemplate($post, $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'Template saved.' : 'Could not save: ' . implode(', ', $res['errors']));
        redirect(admin_url('payplex_staff/staff/templates'));
    }

    public function template_delete($id)
    {
        $this->guard('perm_templates');
        $this->m->deleteTemplate((int) $id, $this->actor());
        set_alert('success', 'Template deleted.');
        redirect(admin_url('payplex_staff/staff/templates'));
    }

    public function matrix()
    {
        $this->guard('view');
        $data['title']   = 'Role Permission Matrix';
        $data['roles']   = Payplex_staff_perms::roles();
        $data['domains'] = Payplex_staff_perms::domains();
        $this->load->view('payplex_staff/role_matrix', $data);
    }

    public function audit()
    {
        $this->guard('audit');
        $data['title'] = 'Staff Audit Log';
        $data['rows']  = $this->m->auditLog(300);
        $this->load->view('payplex_staff/audit_log', $data);
    }

    /** Re-run the safe backfill for any staff added since. */
    public function backfill()
    {
        $this->guard('manage');
        $n = $this->m->backfillExistingStaff($this->actor());
        set_alert('success', 'Backfill complete — ' . $n . ' new staff flagged classification-required.');
        redirect(admin_url('payplex_staff/staff'));
    }

    /* ================= Batch 2: Activity + Performance + KPI ================= */

    /** Activity timeline for one staff member. */
    public function activity($staffId = 0)
    {
        $this->guard('activity');
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'activity', 'activity');
        $from = (string) $this->input->get('from');
        $to   = (string) $this->input->get('to');
        $period = in_array((string) $this->input->get('period'), array('daily','weekly','monthly'), true)
            ? (string) $this->input->get('period') : 'daily';
        $data['title']    = 'Activity Timeline — Staff #' . $staffId;
        $data['staffId']  = $staffId;
        $data['profile']  = $this->m->currentProfile($staffId);
        $data['events']   = $this->m->activityFor($staffId, $from ?: null, $to ?: null, 500);
        $data['timeline'] = $this->m->timelineFor($staffId, $period, $from ?: null, $to ?: null);
        $data['summary']  = $this->m->activitySummary($staffId, $from ?: null, $to ?: null);
        $data['period']   = $period;
        $data['from']     = $from;
        $data['to']       = $to;
        $data['eventTypes'] = Payplex_staff_activity::eventTypes();
        $this->load->view('payplex_staff/activity_timeline', $data);
    }

    /** Log a new activity event (POST). */
    public function activity_log()
    {
        $this->guard('activity');
        $staffId = (int) $this->input->post('staff_id');
        $type    = (string) $this->input->post('event_type');

        /*
         * 'verified' came straight off the POST body under the same capability
         * that permits creating the record, so one person could assert an
         * outcome and certify it in a single request — and only verified
         * records score. The decision is now made against the verify capability
         * and against who the record credits.
         */
        $decision = Payplex_staff_activity::verificationDecision(
            $staffId, $this->actor(), $this->input->post('verified'), payplex_staff_can('verify')
        );

        $id = $this->m->logActivity($staffId, $type, array(
            'ref_type'    => $this->input->post('ref_type'),
            'ref_id'      => $this->input->post('ref_id'),
            'verified'    => $decision['verified'],
            'value'       => $this->input->post('value'),
            'occurred_at' => $this->input->post('occurred_at'),
            'actor_id'    => $this->actor(),
        ));
        if ($id) {
            set_alert('success', 'Activity logged: ' . html_escape($type) . ' #' . $id);
            if ($decision['refusal'] !== null) {
                set_alert('warning', Payplex_staff_activity::refusalMessage($decision['refusal']));
            }
        } else {
            set_alert('warning', 'Could not log activity.');
        }
        redirect(admin_url('payplex_staff/staff/activity/' . $staffId));
    }

    /** Verify an existing activity record — the checker half of the workflow. */
    public function activity_verify($activityId = 0)
    {
        $this->guard('verify');
        $staffId = $this->guardRecordOwner('activity', $activityId, 'activity', 'activity_verify');
        $result  = $this->m->verifyActivity($activityId, $this->actor(), payplex_staff_can('verify'));
        if ($result['ok']) {
            set_alert('success', 'Activity #' . (int) $activityId . ' verified.');
        } else {
            set_alert('warning', Payplex_staff_activity::refusalMessage($result['refusal']));
        }
        redirect(admin_url('payplex_staff/staff/activity/' . $staffId));
    }

    /** Performance board (all staff or single). */
    public function performance($staffId = 0)
    {
        $this->guard('performance');
        if ($staffId !== 0 && $staffId !== '0' && $staffId !== '' && $staffId !== null) {
            $staffId = $this->guardStaffTarget($staffId, 'performance', 'performance');
        }
        $staffId = (int) $staffId;
        $from = (string) $this->input->get('from');
        $to   = (string) $this->input->get('to');
        if ($staffId > 0) {
            // detail view for a single staff member
            $data['title']   = 'Performance — Staff #' . $staffId;
            $data['staffId'] = $staffId;
            $data['profile'] = $this->m->currentProfile($staffId);
            $data['perf']    = $this->m->computePerformance($staffId, 'monthly', $from ?: null, $to ?: null, false);
            $data['summary'] = $this->m->activitySummary($staffId, $from ?: null, $to ?: null);
            $data['from']    = $from;
            $data['to']      = $to;
            $this->load->view('payplex_staff/performance_detail', $data);
        } else {
            // board view — scoped by permissions
            $data['title'] = 'Performance Board';
            $data['board'] = $this->m->performanceBoard($this->actor(), is_admin(), '', $from ?: null, $to ?: null);
            $data['from']  = $from;
            $data['to']    = $to;
            $this->load->view('payplex_staff/performance_list', $data);
        }
    }

    /** Compute + store a performance snapshot (POST). */
    public function performance_compute()
    {
        $this->guard('performance');
        $staffId = (int) $this->input->post('staff_id');
        $from = (string) $this->input->post('from');
        $to   = (string) $this->input->post('to');
        $this->m->computePerformance($staffId, 'monthly', $from ?: null, $to ?: null, true);
        set_alert('success', 'Performance snapshot stored for Staff #' . $staffId . '.');
        redirect(admin_url('payplex_staff/staff/performance/' . $staffId));
    }

    /** KPI config (view + manage definitions). */
    public function kpi()
    {
        $this->guard('kpi_config');
        $data['title'] = 'KPI Configuration';
        $data['defs']  = $this->m->kpiDefs(null, false);
        $data['catalog'] = Payplex_staff_kpi::metricCatalog();
        $data['roles']   = Payplex_staff_perms::roles();
        $this->load->view('payplex_staff/kpi_config', $data);
    }

    /** Save a KPI definition (POST). */
    public function kpi_store()
    {
        $this->guard('kpi_config');
        $res = $this->m->saveKpiDef($this->input->post(), $this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'warning',
            !empty($res['ok']) ? 'KPI definition saved.' : 'Error: ' . implode(', ', $res['errors']));
        redirect(admin_url('payplex_staff/staff/kpi'));
    }

    /** Delete a KPI definition. */
    public function kpi_delete($id)
    {
        $this->guard('kpi_config');
        $this->m->deleteKpiDef((int) $id, $this->actor());
        set_alert('success', 'KPI definition deleted.');
        redirect(admin_url('payplex_staff/staff/kpi'));
    }

    /** Seed default KPI definitions once. */
    public function kpi_seed()
    {
        $this->guard('kpi_config');
        $n = $this->m->seedKpiDefaults($this->actor());
        set_alert('success', $n > 0 ? 'Seeded ' . $n . ' default KPI definitions.' : 'Already seeded — no changes.');
        redirect(admin_url('payplex_staff/staff/kpi'));
    }

    /* ================= Batch 3: field tracking, consent & privacy ================= */

    /**
     * May the caller act on this staff member's location data?
     * A person can always see and control their own record — that is a data-subject
     * right, not a privilege — but touching someone else's needs the capability.
     */
    private function fieldScope($staffId)
    {
        $staffId = (int) $staffId;
        if ($staffId > 0 && $staffId === $this->actor()) { return true; }
        return payplex_staff_can('field_tracking');
    }

    private function requireFieldScope($staffId)
    {
        if (!$this->fieldScope($staffId)) { access_denied('payplex_staff'); }
    }

    /** Consent centre: notice text, current state and the full ledger. */
    public function consent($staffId = 0)
    {
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'consent', 'consent');
        $this->requireFieldScope($staffId);

        $data['title']    = 'Location Consent';
        $data['staffId']  = $staffId;
        $data['profile']  = $this->m->currentProfile($staffId);
        $data['status']   = $this->m->consentStatus($staffId);
        $data['ledger']   = $this->m->consentLedger($staffId);
        $data['notice']   = Payplex_staff_consent::noticeText();
        $data['policy']   = Payplex_staff_consent::POLICY_VERSION;
        $data['isSelf']   = ($staffId === $this->actor());
        $data['retention']= Payplex_staff_geo::retentionDays();
        /* Withdrawal stops collection; it does not erase. Say so on the screen
         * rather than letting "consent withdrawn" imply the history is gone. */
        $data['held']     = $this->m->heldAfterWithdrawal($staffId);
        $this->load->view('payplex_staff/consent_center', $data);
    }

    /**
     * Grant consent (POST).
     *
     * Only the person themselves may grant it. An administrator cannot opt someone
     * else in — consent given on your behalf by your employer is not consent, and
     * allowing it here would make the whole opt-in model decorative.
     */
    public function consent_grant()
    {
        $staffId = (int) $this->input->post('staff_id');
        if ($staffId !== $this->actor() || $staffId <= 0) {
            set_alert('danger', 'Location consent can only be given by the staff member themselves.');
            redirect(admin_url('payplex_staff/staff/consent/' . $staffId));
        }
        if (!$this->input->post('accept')) {
            set_alert('warning', 'You must tick the acknowledgement to give consent.');
            redirect(admin_url('payplex_staff/staff/consent/' . $staffId));
        }
        $id = $this->m->recordConsent($staffId, Payplex_staff_consent::ACTION_GRANT, array(
            'actor_id' => $this->actor(),
            'source'   => 'web',
        ));
        set_alert($id ? 'success' : 'info', $id
            ? 'Location consent recorded. You can withdraw it at any time.'
            : 'Consent was already active — nothing changed.');
        redirect(admin_url('payplex_staff/staff/consent/' . $staffId));
    }

    /**
     * Withdraw consent (POST). Allowed for the person themselves, and for an
     * administrator acting on their request — withdrawal only ever reduces what is
     * collected, so it is safe to permit more widely than granting.
     */
    public function consent_withdraw()
    {
        $staffId = (int) $this->input->post('staff_id');
        $this->requireFieldScope($staffId);

        $id = $this->m->recordConsent($staffId, Payplex_staff_consent::ACTION_WITHDRAW, array(
            'actor_id' => $this->actor(),
            'source'   => 'web',
            'reason'   => (string) $this->input->post('reason'),
        ));
        set_alert($id ? 'success' : 'info', $id
            ? 'Consent withdrawn. Location collection has stopped and any open session was closed.'
            : 'Consent was not active — nothing changed.');
        redirect(admin_url('payplex_staff/staff/consent/' . $staffId));
    }

    /** Field session list (all staff, or one). */
    public function field($staffId = 0)
    {
        $staffId = (int) $staffId;
        if ($staffId) {
            $staffId = $this->guardStaffTarget($staffId, 'field_sessions', 'field');
            $this->requireFieldScope($staffId);
        } else { $this->guard('field_tracking'); }

        $data['title']     = $staffId ? 'Field Sessions — Staff #' . $staffId : 'Field Sessions';
        $data['staffId']   = $staffId;
        $data['sessions']  = $this->m->fieldSessions($staffId, 200);
        $data['open']      = $staffId ? $this->m->openSession($staffId) : null;
        $data['consent']   = $staffId ? $this->m->consentStatus($staffId) : null;
        $data['profiles']  = $this->m->currentProfiles();
        $data['retention'] = Payplex_staff_geo::retentionDays();
        $this->load->view('payplex_staff/field_sessions', $data);
    }

    /** Session detail with its (possibly purged) point trail. */
    public function field_session($sessionId = 0)
    {
        $this->guardRecordOwner('field_session', $sessionId, 'field_sessions', 'field_session');
        $session = $this->m->fieldSession((int) $sessionId);
        if (!$session) { show_404(); }
        $this->requireFieldScope((int) $session['staff_id']);

        $data['title']     = 'Field Session #' . (int) $sessionId;
        $data['session']   = $session;
        $data['points']    = $this->m->sessionPoints((int) $sessionId);
        $data['profile']   = $this->m->currentProfile((int) $session['staff_id']);
        $data['retention'] = Payplex_staff_geo::retentionDays();
        $data['trust']     = Payplex_staff_geo::summaryTrust($session);
        $this->load->view('payplex_staff/field_session_detail', $data);
    }

    /**
     * Re-check a frozen summary against the current distance rules.
     *
     * Reports; never rewrites. The freeze is the guarantee that a figure an
     * expense claim rests on cannot move underneath the claim — including when
     * the newer answer is the better one. A person decides what to do about a
     * disagreement; this only makes the disagreement visible.
     */
    public function field_session_reverify($sessionId = 0)
    {
        $this->guardRecordOwner('field_session', $sessionId, 'field_sessions', 'field_session_reverify');
        $session = $this->m->fieldSession((int) $sessionId);
        if (!$session) { show_404(); }
        $this->requireFieldScope((int) $session['staff_id']);

        $res = $this->m->reverifySession((int) $sessionId, $this->actor());
        if (!$res['ok']) {
            set_alert('warning', $res['reason']);
        } elseif ($res['agrees']) {
            set_alert('success', 'Re-checked: the stored distance of '
                . (int) $res['stored']['distance_m'] . ' m matches the current rules.');
        } else {
            set_alert('danger', 'Re-checked: the stored distance is '
                . (int) $res['stored']['distance_m'] . ' m, but the current rules give '
                . (int) $res['recomputed']['distance_m'] . ' m ('
                . (int) $res['recomputed']['skipped_implausible']
                . ' leg(s) rejected as impossible). The stored figure has NOT been changed — '
                . 'the comparison is in the audit log.');
        }
        redirect(admin_url('payplex_staff/staff/field_session/' . (int) $sessionId));
    }

    /** Start a field visit (POST). Refused server-side without active consent. */
    public function field_checkin()
    {
        $staffId = (int) $this->input->post('staff_id');
        $this->requireFieldScope($staffId);

        $res = $this->m->checkIn($staffId, array(
            'lat'        => $this->input->post('lat'),
            'lng'        => $this->input->post('lng'),
            'accuracy_m' => $this->input->post('accuracy_m'),
            'purpose'    => $this->input->post('purpose'),
            'ref_type'   => $this->input->post('ref_type'),
            'ref_id'     => $this->input->post('ref_id'),
            'actor_id'   => $this->actor(),
        ));
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Checked in — field session #' . (int) $res['session_id'] . ' is now open.'
            : $res['reason']);
        redirect(admin_url('payplex_staff/staff/field/' . $staffId));
    }

    /** End the open field visit (POST) and freeze its summary. */
    public function field_checkout()
    {
        $staffId = (int) $this->input->post('staff_id');
        $this->requireFieldScope($staffId);

        $res = $this->m->checkOut($staffId, array(
            'lat'        => $this->input->post('lat'),
            'lng'        => $this->input->post('lng'),
            'accuracy_m' => $this->input->post('accuracy_m'),
            'actor_id'   => $this->actor(),
        ));
        if (!empty($res['ok'])) {
            $s = $res['summary'];
            set_alert('success', 'Checked out — ' . Payplex_staff_geo::formatDistance($s['distance_m'])
                . ' over ' . Payplex_staff_geo::formatDuration($s['duration_s'])
                . ' (' . (int) $s['point_count'] . ' points).');
        } else {
            set_alert('danger', $res['reason']);
        }
        redirect(admin_url('payplex_staff/staff/field/' . $staffId));
    }

    /**
     * Location ping (POST) — the endpoint a field app calls during a session.
     * Answers JSON so a refusal is machine-readable rather than a redirect.
     */
    public function location_ping()
    {
        $staffId = (int) $this->input->post('staff_id');
        if (!$this->fieldScope($staffId)) {
            echo json_encode(array('ok' => false, 'code' => 'denied',
                'reason' => 'Not permitted to record location for this staff member.'));
            return;
        }
        $res = $this->m->recordLocation($staffId, array(
            'lat'         => $this->input->post('lat'),
            'lng'         => $this->input->post('lng'),
            'accuracy_m'  => $this->input->post('accuracy_m'),
            'captured_at' => $this->input->post('captured_at'),
            'source'      => $this->input->post('source'),
        ), $this->actor());
        echo json_encode($res);
    }

    /** Privacy & retention dashboard. */
    public function privacy()
    {
        $this->guard('privacy_admin');
        $data['title']     = 'Privacy & Data Retention';
        $data['stats']     = $this->m->retentionStats();
        $data['log']       = $this->m->privacyLogEntries(100);
        $data['notice']    = Payplex_staff_consent::noticeText();
        $data['policy']    = Payplex_staff_consent::POLICY_VERSION;
        $data['retention'] = Payplex_staff_geo::retentionDays();
        $this->load->view('payplex_staff/privacy_status', $data);
    }

    /** Run the retention purge now (POST). Aggregates are preserved. */
    public function purge_run()
    {
        $this->guard('privacy_admin');
        $res = $this->m->purgeExpiredLocations($this->actor());
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Retention purge complete — ' . (int) $res['deleted'] . ' raw location points deleted (cutoff '
              . $res['cutoff'] . '). Visit summaries were preserved.'
            : $res['reason']);
        redirect(admin_url('payplex_staff/staff/privacy'));
    }

    /** Close sessions left open past the safety limit (POST). */
    public function sessions_sweep()
    {
        $this->guard('field_tracking');
        $n = $this->m->closeAbandonedSessions($this->actor());
        set_alert('success', $n > 0 ? 'Auto-closed ' . $n . ' abandoned session(s).' : 'No abandoned sessions found.');
        redirect(admin_url('payplex_staff/staff/field'));
    }

    /** Subject access: everything held about one person's location. */
    public function my_location_data($staffId = 0)
    {
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'location_data', 'my_location_data');
        $this->requireFieldScope($staffId);

        $data['title']     = 'Location Data — Staff #' . $staffId;
        $data['staffId']   = $staffId;
        $data['record']    = $this->m->locationDataFor($staffId, $this->actor());
        $data['profile']   = $this->m->currentProfile($staffId);
        $data['retention'] = Payplex_staff_geo::retentionDays();
        $this->load->view('payplex_staff/field_sessions', array_merge($data, array(
            'sessions' => $data['record']['sessions'],
            'open'     => $this->m->openSession($staffId),
            'consent'  => $data['record']['consent'],
            'profiles' => $this->m->currentProfiles(),
        )));
    }

    /* ==================================================================== *
     * Compliance documents
     * ==================================================================== */

    /**
     * May $me look at a document belonging to $ownerId?
     *
     * Owning it is enough. Anything else needs the verification capability —
     * there is deliberately no "everybody with staff view can read everybody's
     * Aadhaar" middle rung, because that is what a document store must not be.
     */
    private function mayReadDocuments($ownerId)
    {
        $me = (int) get_staff_user_id();
        if ((int) $ownerId === $me) { return true; }
        return payplex_staff_can('documents_verify');
    }

    public function documents($staffId = 0)
    {
        $this->guard('view');
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'documents', 'documents');
        if (!$this->mayReadDocuments($staffId)) {
            set_alert('warning', 'You can only see your own documents.');
            redirect(admin_url('payplex_staff/staff/documents'));
        }

        $member = $this->m->coreStaffMember($staffId);
        if (!$member) {
            set_alert('warning', 'Staff member #' . $staffId . ' does not exist.');
            redirect(admin_url('payplex_staff/staff'));
        }

        $data['title']      = 'Documents';
        $data['member']     = $member;
        $data['staff_id']   = $staffId;
        $data['is_self']    = $staffId === (int) get_staff_user_id();
        $data['can_verify'] = payplex_staff_can('documents_verify');
        $data['compliance'] = $this->m->documentCompliance($staffId);
        $data['storage']    = $this->m->documentStorageRoot();
        $data['history']    = $this->m->documentsFor($staffId, true);
        $data['log']        = payplex_staff_can('audit')
                                ? $this->m->documentAccessLog(0, $staffId, 100) : array();
        $this->load->view('payplex_staff/documents', $data);
    }

    public function documents_upload()
    {
        $this->guard('view');
        $staffId = (int) $this->input->post('staff_id');
        $me      = (int) get_staff_user_id();

        /*
         * Uploading FOR somebody else is a real need (HR collecting paperwork),
         * so it is allowed — but only with the verification capability, and the
         * access log records who actually pressed the button.
         */
        if ($staffId !== $me && !payplex_staff_can('documents_verify')) {
            set_alert('warning', 'You can only upload your own documents.');
            redirect(admin_url('payplex_staff/staff/documents'));
        }

        $file = isset($_FILES['document']) ? $_FILES['document'] : array();
        $res  = $this->m->storeDocument($staffId, (string) $this->input->post('doc_type'),
            is_array($file) ? $file : array(),
            array('expires_on' => $this->input->post('expires_on'),
                  'issued_on'  => $this->input->post('issued_on')),
            $me);

        set_alert($res['ok'] ? 'success' : 'danger',
            $res['ok'] ? 'Document uploaded. It counts toward compliance once it is verified.'
                       : $res['reason']);
        redirect(admin_url('payplex_staff/staff/documents/' . $staffId));
    }

    public function documents_decide($id = 0)
    {
        $this->guard('view');
        $this->guardRecordOwner('document', $id, 'documents', 'documents_decide');
        $decision = (string) $this->input->post('decision');
        $res = $this->m->decideDocument((int) $id, $decision, (int) get_staff_user_id(),
            payplex_staff_can('documents_verify'), (string) $this->input->post('reason'));

        $row = $this->m->documentRow((int) $id);
        set_alert($res['ok'] ? 'success' : 'warning',
            $res['ok'] ? ('Document marked ' . $decision . '.') : $res['reason']);
        redirect(admin_url('payplex_staff/staff/documents/' . (int) ($row ? $row['staff_id'] : 0)));
    }

    /**
     * Serve a file.
     *
     * The access log is written BEFORE the bytes are sent, and before any early
     * return. A log written afterwards is missing exactly the reads that went
     * wrong, which are the ones worth having.
     */
    public function documents_download($id = 0)
    {
        $this->guard('view');
        /* Before a byte is read. */
        $this->guardRecordOwner('document', $id, 'documents', 'documents_download');
        $me  = (int) get_staff_user_id();
        $row = $this->m->documentRow((int) $id);

        if (!$row) {
            $this->m->logDocumentAccess((int) $id, 0, $me, 'refused', 'not_found');
            show_404();
        }
        if (!$this->mayReadDocuments((int) $row['staff_id'])) {
            $this->m->logDocumentAccess((int) $row['id'], (int) $row['staff_id'], $me, 'refused', 'not_permitted');
            access_denied('payplex_staff');
        }

        $path = $this->m->documentPath($row);
        $this->m->logDocumentAccess((int) $row['id'], (int) $row['staff_id'], $me,
            'download', $row['doc_type'] . ' / ' . basename($row['original_name']));

        if (!is_file($path)) {
            set_alert('danger', 'The file is recorded but missing from storage.');
            redirect(admin_url('payplex_staff/staff/documents/' . (int) $row['staff_id']));
        }

        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $row['original_name']);
        header('Content-Type: ' . ($row['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** Everybody's compliance in one place. */
    public function documents_register()
    {
        $this->guard('view');
        if (!payplex_staff_can('documents_verify')) { access_denied('payplex_staff'); }

        $data['title']   = 'Document Compliance';
        $data['board']   = $this->m->documentComplianceBoard();
        $data['storage'] = $this->m->documentStorageRoot();
        $this->load->view('payplex_staff/documents_register', $data);
    }

    /* ==================================================================== *
     * Expense claims
     * ==================================================================== */

    private function expCan($cap) { return payplex_staff_can($cap); }

    public function expenses()
    {
        $this->guard('view');
        if (!$this->expCan('expense_view_own') && !$this->expCan('expense_view_all')) {
            access_denied('payplex_staff');
        }
        $me      = (int) get_staff_user_id();
        $viewAll = $this->expCan('expense_view_all');

        $data['title']    = 'Expense Claims';
        $data['me']       = $me;
        $data['view_all'] = $viewAll;
        $data['claims']   = $this->m->claims(array('state' => $this->input->get('state')), $me, $viewAll);
        $data['ageing']   = $viewAll ? $this->m->claimAgeing($me, true) : $this->m->claimAgeing($me, false);
        $data['can']      = array(
            'submit'  => $this->expCan('expense_submit'),
            'manager' => $this->expCan('expense_review_manager'),
            'finance' => $this->expCan('expense_review_finance'),
            'approve' => $this->expCan('expense_approve'),
            'bills'   => $this->expCan('expense_bill_view'),
        );
        $data['bank']     = $this->m->bankPayable($me);
        $data['dash']     = $this->m->claimDashboard($me, $viewAll);
        $this->load->view('payplex_staff/expenses', $data);
    }

    public function expense_new()
    {
        $this->guard('view');
        if (!$this->expCan('expense_submit')) { access_denied('payplex_staff'); }

        $me  = (int) get_staff_user_id();
        $for = (int) $this->input->post('claimant_id') ?: $me;

        /*
         * Raising a claim FOR somebody else is a real need, and it is not a
         * quiet one: it needs the wider expense permission, and the claim records
         * who actually typed it as the delegate.
         */
        if ($for !== $me && !$this->expCan('expense_view_all')) {
            set_alert('warning', 'You can only raise your own claims.');
            redirect(admin_url('payplex_staff/staff/expenses'));
        }

        $res = $this->m->createClaim(array(
            'category'     => $this->input->post('category'),
            'payable_kind' => $this->input->post('payable_kind'),
            'purpose'      => $this->input->post('purpose'),
            'amount'       => $this->input->post('amount'),
            'tax_amount'   => $this->input->post('tax_amount'),
            'expense_date' => $this->input->post('expense_date'),
        ), $for, $me, (string) $this->input->post('bill_digest'));

        if (!$res['ok']) {
            $msg = $res['reason'];
            if (!empty($res['errors'])) { $msg .= ' ' . implode(' ', $res['errors']); }
            set_alert('danger', $msg);
            redirect(admin_url('payplex_staff/staff/expenses'));
        }
        set_alert('success', 'Claim ' . $res['claim_ref'] . ' created as a draft. '
                           . 'Attach the bill, then submit it.');
        redirect(admin_url('payplex_staff/staff/expense/' . (int) $res['id']));
    }

    public function expense($id = 0)
    {
        $this->guard('view');
        $this->guardRecordOwner('expense', $id, 'expense', 'expense');
        $claim = $this->m->claim($id);
        if (!$claim) {
            set_alert('warning', 'No such claim.');
            redirect(admin_url('payplex_staff/staff/expenses'));
        }
        $me = (int) get_staff_user_id();
        if (!Workforce_expense::visibleToScope($claim, $me, $this->expCan('expense_view_all'))) {
            $this->m->claimEvent((int) $claim['id'], 'refused', $claim['state'], null, $me,
                'not_permitted: tried to open a claim belonging to #' . (int) $claim['claimant_id']);
            access_denied('payplex_staff');
        }

        $caps = $this->m->capabilitiesFor($me);
        $moves = array();
        foreach (Workforce_expense::transitions()[$claim['state']] as $to) {
            $g = Workforce_expense::canAct($claim, $to, $me, $caps, 'probe');
            $moves[$to] = $g;
        }

        $data['title']   = $claim['claim_ref'];
        $data['claim']   = $claim;
        $data['me']      = $me;
        $data['moves']   = $moves;
        $data['events']  = $this->m->claimEvents((int) $claim['id']);
        $data['ageing']  = Workforce_expense::ageing($claim);
        $data['bill']    = Workforce_expense::canSeeBill($claim, $me, $this->expCan('expense_bill_view'));
        $data['claimant']= $this->m->coreStaffMember((int) $claim['claimant_id']);
        $data['bank']    = $this->m->bankPayable((int) $claim['claimant_id']);
        $this->load->view('payplex_staff/expense_detail', $data);
    }

    public function expense_transition($id = 0)
    {
        $this->guard('view');
        $this->guardRecordOwner('expense', $id, 'expense', 'expense_transition');
        $to  = (string) $this->input->post('to');
        $res = $this->m->claimTransition((int) $id, $to, (int) get_staff_user_id(),
                                         (string) $this->input->post('reason'));
        set_alert($res['ok'] ? 'success' : 'warning',
            $res['ok'] ? ('Moved to "' . Workforce_expense::label($to) . '".') : $res['reason']);
        redirect(admin_url('payplex_staff/staff/expense/' . (int) $id));
    }

    /* ==================================================================== *
     * Bank details
     * ==================================================================== */

    public function bank($staffId = 0)
    {
        $this->guard('view');
        $me      = (int) get_staff_user_id();
        $staffId = $this->guardStaffTarget($this->guardSubject($staffId), 'bank', 'bank');
        if ($staffId !== $me && !$this->expCan('bank_manage') && !$this->expCan('bank_verify')) {
            set_alert('warning', 'You can only see your own bank details.');
            redirect(admin_url('payplex_staff/staff/bank'));
        }
        $member = $this->m->coreStaffMember($staffId);
        if (!$member) {
            set_alert('warning', 'Staff member #' . $staffId . ' does not exist.');
            redirect(admin_url('payplex_staff/staff'));
        }

        $current = $this->m->bankAccount($staffId);
        $data['title']     = 'Bank Details';
        $data['member']    = $member;
        $data['staff_id']  = $staffId;
        $data['is_self']   = $staffId === $me;
        $data['account']   = $current ? Workforce_bank::forDisplay($current, $staffId === $me) : null;
        $data['account_id']= $current ? (int) $current['id'] : 0;
        $data['payable']   = $this->m->bankPayable($staffId);
        $data['history']   = $this->m->bankHistory($staffId);
        $data['can_manage']= $this->expCan('bank_manage') || $staffId === $me;
        $data['can_verify']= $this->expCan('bank_verify');
        $this->load->view('payplex_staff/bank', $data);
    }

    public function bank_save()
    {
        $this->guard('view');
        $me      = (int) get_staff_user_id();
        $staffId = (int) $this->input->post('staff_id') ?: $me;

        if ($staffId !== $me && !$this->expCan('bank_manage')) {
            set_alert('warning', 'You can only record your own bank details.');
            redirect(admin_url('payplex_staff/staff/bank'));
        }

        $res = $this->m->saveBankAccount($staffId, array(
            'beneficiary_name'       => $this->input->post('beneficiary_name'),
            'account_number'         => $this->input->post('account_number'),
            'account_number_confirm' => $this->input->post('account_number_confirm'),
            'ifsc'                   => $this->input->post('ifsc'),
            'bank_name'              => $this->input->post('bank_name'),
            'account_type'           => $this->input->post('account_type'),
        ), $me);

        if (!$res['ok']) {
            set_alert('danger', $res['reason'] . ' ' . implode(' ', $res['errors']));
        } else {
            set_alert('success', 'Bank details recorded. They are NOT verified yet — somebody else '
                               . 'has to confirm them before any payment can be made.');
        }
        redirect(admin_url('payplex_staff/staff/bank/' . $staffId));
    }

    public function bank_decide($id = 0)
    {
        $this->guard('view');
        /* Verifying a beneficiary account is the control that releases money
           to it. It was gated by capability alone. */
        $this->guardRecordOwner('bank', $id, 'bank', 'bank_decide');
        $res = $this->m->decideBankAccount((int) $id, (string) $this->input->post('decision'),
            (int) get_staff_user_id(), (string) $this->input->post('reason'));
        $row = $this->m->bankAccountRow((int) $id);
        set_alert($res['ok'] ? 'success' : 'warning',
            $res['ok'] ? 'Bank details marked ' . $this->input->post('decision') . '.' : $res['reason']);
        redirect(admin_url('payplex_staff/staff/bank/' . (int) ($row ? $row['staff_id'] : 0)));
    }

    /**
     * Download the claims as CSV.
     *
     * Exporting everybody's claims is a data act, not a page view, so it writes
     * an audit event naming who did it and how many rows left the building. The
     * export carries no bank detail at all — that is the payout engine's bank
     * file, which is a different document with a different permission.
     */
    public function expenses_export()
    {
        $this->guard('view');
        if (!$this->expCan('expense_view_own') && !$this->expCan('expense_view_all')) {
            access_denied('payplex_staff');
        }
        $me      = (int) get_staff_user_id();
        $viewAll = $this->expCan('expense_view_all');

        $filter = array(
            'state' => $this->input->get('state'),
            'from'  => $this->input->get('from'),
            'to'    => $this->input->get('to'),
        );

        /*
         * Streamed, not built in memory. The export used to borrow the screen's
         * 500-row cap and stop there silently; building the whole file in an
         * array instead would just move the failure from "wrong file" to "out
         * of memory on a big enough export". Rows are written as they are read.
         *
         * No Content-Length: the size is not known until the last row is
         * written, and guessing it truncates the download.
         */
        $name = 'expense-claims-' . date('Ymd-Hi') . ($viewAll ? '' : '-own') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');

        while (ob_get_level() > 0) { ob_end_flush(); }

        $cols = Workforce_expense::exportColumns();
        echo implode(',', array_map(array('Workforce_expense', 'csvCell'), array_values($cols))) . "\r\n";

        $keys = array_keys($cols);
        $t = $this->m->claimsExportEach($filter, $me, $viewAll,
            function ($row) use ($keys) {
                $line = array();
                foreach ($keys as $k) {
                    $line[] = Workforce_expense::csvCell(isset($row[$k]) ? $row[$k] : '');
                }
                echo implode(',', $line) . "\r\n";
                flush();
            });

        /*
         * Logged after the file is written, with totals added up over every row
         * that actually went out - not over a page of them. If paging stopped
         * early the reason is recorded, because by then the person already has
         * the file and the audit trail is the only place that can still say it
         * was incomplete.
         */
        $this->m->claimEvent(0, 'export', null, null, $me,
            ($viewAll ? 'All claims' : 'Own claims') . ' — ' . (int) $t['rows'] . ' row(s)'
            . (empty($t['ok']) ? ' — INCOMPLETE: ' . $t['error'] : ''),
            null, null, array(
                'scope'      => $viewAll ? 'all' : 'own',
                'rows'       => (int) $t['rows'],
                'pages'      => (int) $t['pages'],
                'complete'   => empty($t['ok']) ? 0 : 1,
                'sum_amount' => isset($t['sum_amount']) ? $t['sum_amount'] : null,
                'sum_paid'   => isset($t['sum_paid']) ? $t['sum_paid'] : null,
                'first_ref'  => isset($t['first_claim_ref']) ? $t['first_claim_ref'] : null,
                'last_ref'   => isset($t['last_claim_ref']) ? $t['last_claim_ref'] : null,
                'filters'    => array_filter($filter)));
        exit;
    }
}
