<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Admin commission: statements list, generate, approve (maker-checker), pay,
 * rules management. All state changes audited via Perfex activity where available.
 */
class Commission extends AdminController
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_commission/payplex_commission_model', 'cmodel');
    }

    private function cap($c)
    {
        return is_admin() || staff_can($c, 'payplex_commission');
    }

    public function index()
    {
        if (!$this->cap('view_all')) {
            access_denied('payplex_commission');
        }
        $data['title']       = 'Commission Statements';
        $data['statements']  = $this->cmodel->all($this->input->get('status') ?: null);
        $data['can_approve'] = $this->cap('approve');
        $data['can_pay']     = $this->cap('pay');
        $data['can_compute'] = $this->cap('compute');
        $this->load->view('payplex_commission/statements', $data);
    }

    /**
     * Legacy generation — retired, and no longer able to write anything.
     *
     * This route wrote commission statements by a cruder rule than the policy
     * path beside it. It selected sources with one query — paid invoices only,
     * the invoice TOTAL as the commission base — and so ignored everything the
     * source policy exists to handle: partial collections, tax apportionment,
     * the collection-versus-billing distinction, per-source eligibility, and
     * the idempotency keys that stop a transaction being paid twice.
     *
     * Two live routes wrote financial records by different rules, and the
     * Statements screen pointed at this one while the correct path sat behind
     * the preview. That is the same shape as the consent model whose correct
     * check went unused while the controller rolled its own.
     *
     * It now writes nothing and sends the user to the preview, where the same
     * period can be examined before anything is created. The route is kept
     * rather than deleted so an existing link or bookmark explains itself
     * instead of returning a 404.
     */
    public function generate()
    {
        if (!$this->cap('compute')) {
            ajax_access_denied();
        }
        $period = (string) ($this->input->post('period') ?: $this->input->get('period') ?: date('Y-m'));

        set_alert('warning', 'That button used an older generation route that ignored partial '
            . 'collections, tax apportionment and duplicate-payment protection, so it no longer '
            . 'creates statements. Review the period below and generate from the preview instead.');
        redirect(admin_url('payplex_commission/commission/preview?period=' . urlencode($period)));
    }

    public function view($id)
    {
        if (!$this->cap('view_all')) { access_denied('payplex_commission'); }
        $data['s']     = $this->cmodel->get($id);
        if (!$data['s']) { show_404(); }
        $data['items'] = $this->cmodel->itemsFor($id);
        $data['integrity'] = $this->cmodel->verifyIntegrity($id);
        $data['can_approve'] = $this->cap('approve');
        $data['can_pay']     = $this->cap('pay');
        /*
         * Draft-or-final is decided from workflow_state through the workflow
         * library, never from the legacy `status` enum. The two were written by
         * separate paths and could disagree, and reading `status` here would
         * have let a statement the workflow still considers a draft be
         * presented as approved.
         */
        $data['state'] = Payplex_commission_workflow::stateOf($data['s']);
        $data['issue'] = Payplex_commission_workflow::canIssue($data['s']);
        $data['title'] = 'Statement #' . (int) $id;
        $this->load->view('payplex_commission/statement_view', $data);
    }

    public function approve($id)
    {
        if (!$this->cap('approve')) { ajax_access_denied(); }
        $res = $this->cmodel->approve($id, get_staff_user_id());
        if ($res !== true) {
            $map = [
                'not_found' => 'Not found', 'not_pending' => 'Not awaiting approval',
                'maker_is_checker' => 'You cannot approve a statement you generated (segregation of duties).',
                'race_lost' => 'Already processed.',
            ];
            set_alert('warning', $map[$res] ?? 'Could not approve.');
        } else {
            set_alert('success', 'Statement approved and locked (immutable).');
        }
        redirect(admin_url('payplex_commission/commission/view/' . (int) $id));
    }

    public function pay($id)
    {
        if (!$this->cap('pay')) { ajax_access_denied(); }
        $res = $this->cmodel->markPaid($id, get_staff_user_id());
        set_alert($res === true ? 'success' : 'warning', $res === true ? 'Marked paid.' : 'Only approved statements can be paid.');
        redirect(admin_url('payplex_commission/commission/view/' . (int) $id));
    }

    public function rules()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }
        if ($this->input->post('rule_json')) {
            $json = $this->input->post('rule_json', false);
            if (json_decode($json) === null) {
                set_alert('warning', 'Rule JSON is invalid.');
            } else {
                $this->db->insert(db_prefix() . 'payplex_commission_rule_versions', [
                    'name'       => $this->input->post('name') ?: 'Rule',
                    'scope'      => $this->input->post('scope') ?: 'global',
                    'scope_ref'  => $this->input->post('scope_ref') ?: null,
                    'rule_json'  => $json,
                    'active'     => 1,
                    'created_by' => get_staff_user_id(),
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                set_alert('success', 'New rule version saved (old versions kept for historical statements).');
            }
            redirect(admin_url('payplex_commission/commission/rules'));
        }
        $data['title'] = 'Commission Rules';
        $data['rules'] = $this->db->order_by('id', 'DESC')->get(db_prefix() . 'payplex_commission_rule_versions')->result();
        $this->load->view('payplex_commission/rules', $data);
    }

    /* ================= v0.2.0 — Commission Rule Builder (§2.1) ================= */

    /** Rule builder list, with the legacy-rule warning and the audit trail. */
    public function rule_builder()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $data['title']        = 'Commission Rule Builder';
        $data['rules']        = $this->cmodel->rulesAll();
        $data['legacyActive'] = $this->cmodel->legacyActiveRules();
        $data['audit']        = $this->cmodel->ruleAuditLog(0, 60);
        $data['calcTypes']    = Payplex_commission_rule::calcTypes();
        $data['calcBases']    = Payplex_commission_rule::calcBases();
        $data['states']       = Payplex_commission_rule::states();
        $data['canApprove']   = $this->cap('rules_approve');
        // Same evidence the approval queue shows: whether the stored log still
        // hashes to what was written, rather than a claim that it must.
        $data['chain']        = $this->cmodel->verifyAuditChain('rule');
        $this->load->view('payplex_commission/rule_builder', $data);
    }

    /** New / edit form. */
    public function rule_form($id = 0)
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $id = (int) $id;
        $data['title']     = $id ? 'Edit Commission Rule' : 'New Commission Rule';
        $data['rule']      = $id ? $this->cmodel->ruleGet($id) : null;
        $data['calcTypes'] = Payplex_commission_rule::calcTypes();
        $data['calcBases'] = Payplex_commission_rule::calcBases();
        $data['dimensions']= Payplex_commission_rule::matchDimensions();
        $this->load->view('payplex_commission/rule_form', $data);
    }

    /** Create or update (POST). Validation failures are shown, not swallowed. */
    public function rule_store()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $id = (int) $this->input->post('id');
        $payload = array(
            'rule_code'             => $this->input->post('rule_code'),
            'name'                  => $this->input->post('name'),
            'company'               => $this->input->post('company'),
            'product'               => $this->input->post('product'),
            'employee_role'         => $this->input->post('employee_role'),
            'staff_id'              => $this->input->post('staff_id'),
            'sales_channel'         => $this->input->post('sales_channel'),
            'lead_source'           => $this->input->post('lead_source'),
            'customer_type'         => $this->input->post('customer_type'),
            'territory'             => $this->input->post('territory'),
            'calc_type'             => $this->input->post('calc_type'),
            'calc_base'             => $this->input->post('calc_base'),
            'rate'                  => $this->input->post('rate'),
            'amount'                => $this->input->post('amount'),
            'slabs'                 => $this->input->post('slabs_json', false),
            'min_threshold'         => $this->input->post('min_threshold'),
            'max_eligible_amount'   => $this->input->post('max_eligible_amount'),
            'accelerator_threshold' => $this->input->post('accelerator_threshold'),
            'accelerator_rate'      => $this->input->post('accelerator_rate'),
            'cap'                   => $this->input->post('cap'),
            'priority'              => $this->input->post('priority'),
            'effective_from'        => $this->input->post('effective_from'),
            'effective_to'          => $this->input->post('effective_to'),
            'is_test'               => $this->input->post('is_test'),
            'notes'                 => $this->input->post('notes'),
        );

        // slabs arrive as JSON text; reject malformed input rather than storing it
        if ($payload['slabs'] !== null && trim((string) $payload['slabs']) !== '') {
            $decoded = json_decode((string) $payload['slabs'], true);
            if (!is_array($decoded)) {
                set_alert('danger', 'Slab bands are not valid JSON.');
                redirect(admin_url('payplex_commission/commission/rule_form/' . $id));
            }
            $payload['slabs'] = $decoded;
        } else {
            unset($payload['slabs']);
        }

        $actor = get_staff_user_id();
        $res = $id ? $this->cmodel->ruleUpdate($id, $payload, $actor)
                   : $this->cmodel->ruleCreate($payload, $actor);

        if (empty($res['ok'])) {
            set_alert('danger', 'Rule not saved: ' . implode(' ', $res['errors']));
            redirect(admin_url('payplex_commission/commission/rule_form/' . $id));
        }

        if (!empty($res['versioned'])) {
            set_alert('success', 'This rule was already approved, so your changes were saved as a NEW VERSION '
                . '(draft, pending approval). Commissions already calculated are unaffected.');
        } else {
            set_alert('success', 'Rule saved as a draft. Submit it for approval when ready.');
        }
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /**
     * Lifecycle transition (POST). Approval is gated on the rules_approve
     * capability in addition to the maker-is-not-approver check in the model.
     */
    public function rule_transition()
    {
        $id = (int) $this->input->post('id');
        $to = (string) $this->input->post('to');

        /*
         * One lookup decides this, and an unrecognised target is refused.
         * The previous shape — a weak base capability plus a list of stronger
         * exceptions — allowed every target nobody had listed.
         */
        $need = Payplex_commission_rule::capabilityFor($to);
        if ($need === null || !$this->cap($need)) { access_denied('payplex_commission'); }

        $res = $this->cmodel->ruleTransition($id, $to, get_staff_user_id(), (string) $this->input->post('reason'));
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Rule #' . $id . ' moved to ' . $to . '.'
            : $res['reason']);
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /**
     * Retire legacy placeholder/TEST rules (POST). Reversible — the rows are
     * kept and only the active flag is cleared.
     */
    public function retire_test_rules()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $res = $this->cmodel->retireLegacyTestRules(get_staff_user_id());
        set_alert((int) $res['retired'] > 0 ? 'success' : 'info',
            (int) $res['retired'] > 0
                ? 'Deactivated ' . (int) $res['retired'] . ' legacy test/placeholder rule(s): '
                  . implode(', ', $res['names']) . '. They can no longer generate commission.'
                : 'No active legacy test or placeholder rules found.');
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /**
     * Dry-run the resolver against a hypothetical transaction. Read-only: shows
     * which rule would govern it and what it would pay, and creates nothing.
     */
    public function rule_test()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $context = array(
            'staff_id'      => $this->input->post('ctx_staff_id'),
            'employee_role' => $this->input->post('ctx_employee_role'),
            'product'       => $this->input->post('ctx_product'),
            'company'       => $this->input->post('ctx_company'),
            'sales_channel' => $this->input->post('ctx_sales_channel'),
            'lead_source'   => $this->input->post('ctx_lead_source'),
            'customer_type' => $this->input->post('ctx_customer_type'),
            'territory'     => $this->input->post('ctx_territory'),
        );
        $base        = (float) $this->input->post('ctx_base');
        $achievedPct = (float) $this->input->post('ctx_achieved_pct');
        $on          = $this->input->post('ctx_date') ?: null;

        $res = $this->cmodel->resolveRule($context, $on, true);

        $data['title']     = 'Rule Resolution Test';
        $data['context']   = $context;
        $data['base']      = $base;
        $data['achieved']  = $achievedPct;
        $data['on']        = $on;
        $data['resolution']= $res;
        $data['outcome']   = null;

        if ($res['status'] === 'ok') {
            $def = Payplex_commission_rule::toCalcDefinition($res['rule']);
            $data['outcome'] = Payplex_commission_calc::compute($base, $def, $achievedPct);
            $data['definition'] = $def;
        }

        $data['rules']        = $this->cmodel->rulesAll();
        $data['legacyActive'] = $this->cmodel->legacyActiveRules();
        $data['audit']        = $this->cmodel->ruleAuditLog(0, 60);
        $data['calcTypes']    = Payplex_commission_rule::calcTypes();
        $data['calcBases']    = Payplex_commission_rule::calcBases();
        $data['states']       = Payplex_commission_rule::states();
        $data['canApprove']   = $this->cap('rules_approve');
        $this->load->view('payplex_commission/rule_builder', $data);
    }

    /* ================= v0.2.0 — Source Policy & preview (§2.2) ================= */

    public function source_policy()
    {
        if (!$this->cap('source_policy')) { access_denied('payplex_commission'); }

        $data['title']      = 'Commission Source Policy';
        $data['policies']   = $this->cmodel->policiesAll();
        $data['active']     = $this->cmodel->activePolicy();
        $data['events']     = Payplex_commission_source::events();
        $data['gateways']   = Payplex_commission_source::gatewayFeeTreatments();
        $data['states']     = Payplex_commission_source::states();
        $data['canApprove'] = $this->cap('source_policy_approve');
        $data['warnings']   = $data['active'] ? Payplex_commission_source::warnings($data['active']) : array();
        $this->load->view('payplex_commission/source_policy', $data);
    }

    public function policy_form($id = 0)
    {
        if (!$this->cap('source_policy')) { access_denied('payplex_commission'); }

        $id = (int) $id;
        $data['title']    = $id ? 'Edit Source Policy' : 'New Source Policy';
        $data['policy']   = $id ? $this->cmodel->policyGet($id) : null;
        $data['events']   = Payplex_commission_source::events();
        $data['gateways'] = Payplex_commission_source::gatewayFeeTreatments();
        $this->load->view('payplex_commission/policy_form', $data);
    }

    public function policy_store()
    {
        if (!$this->cap('source_policy')) { access_denied('payplex_commission'); }

        $id = (int) $this->input->post('id');
        $filters = array();
        foreach (array('company', 'product', 'customer_type', 'territory', 'sales_channel', 'lead_source') as $f) {
            $val = trim((string) $this->input->post('filter_' . $f));
            if ($val !== '') { $filters[$f] = $val; }
        }

        $payload = array(
            'name'                  => $this->input->post('name'),
            'events'                => $this->input->post('events') ?: array(),
            'filters'               => $filters,
            'exclude_refunded'      => $this->input->post('exclude_refunded') ? 1 : 0,
            'exclude_cancelled'     => $this->input->post('exclude_cancelled') ? 1 : 0,
            'allow_partial'         => $this->input->post('allow_partial') ? 1 : 0,
            'include_tax'           => $this->input->post('include_tax') ? 1 : 0,
            'include_discount'      => $this->input->post('include_discount') ? 1 : 0,
            'gateway_fee_treatment' => $this->input->post('gateway_fee_treatment'),
            'min_collected_amount'  => $this->input->post('min_collected_amount'),
            'currencies'            => $this->input->post('currencies'),
            'date_from'             => $this->input->post('date_from'),
            'date_to'               => $this->input->post('date_to'),
            'notes'                 => $this->input->post('notes'),
        );

        $actor = get_staff_user_id();
        $res = $id ? $this->cmodel->policyUpdate($id, $payload, $actor)
                   : $this->cmodel->policyCreate($payload, $actor);

        if (empty($res['ok'])) {
            set_alert('danger', 'Policy not saved: ' . implode(' ', $res['errors']));
            redirect(admin_url('payplex_commission/commission/policy_form/' . $id));
        }
        set_alert('success', !empty($res['versioned'])
            ? 'This policy was already approved, so your changes were saved as a NEW VERSION in draft. '
              . 'Commissions already generated are unaffected.'
            : 'Source policy saved as a draft. Submit it for approval when ready.');
        redirect(admin_url('payplex_commission/commission/source_policy'));
    }

    public function policy_transition()
    {
        $id = (int) $this->input->post('id');
        $to = (string) $this->input->post('to');

        /*
         * One lookup decides this, and an unrecognised target is refused.
         * The previous shape — a weak base capability plus a list of stronger
         * exceptions — allowed every target nobody had listed.
         */
        $need = Payplex_commission_source::capabilityFor($to);
        if ($need === null || !$this->cap($need)) { access_denied('payplex_commission'); }

        $res = $this->cmodel->policyTransition($id, $to, get_staff_user_id(), (string) $this->input->post('reason'));
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Policy #' . $id . ' moved to ' . $to . '.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/source_policy'));
    }

    /**
     * Preview screen. Runs the SAME evaluation as real generation but persists
     * nothing financial, so the number shown here is the number that would be
     * generated — not an estimate of it.
     */
    public function preview()
    {
        if (!$this->cap('compute')) { access_denied('payplex_commission'); }

        $period = (string) ($this->input->post('period') ?: $this->input->get('period') ?: date('Y-m'));
        $events = $this->collectSourceEvents($period);

        $data['title']      = 'Generation Preview — ' . $period;
        $data['period']     = $period;
        $data['policy']     = $this->cmodel->activePolicy();
        $data['evaluation'] = $this->cmodel->evaluateSources($events, false, $period, get_staff_user_id());
        $data['runs']       = $this->cmodel->generationRuns(15);
        $data['sourceCount']= count($events);
        $this->load->view('payplex_commission/preview', $data);
    }

    /**
     * Real generation (POST). Refused outright unless a source policy is
     * approved and active, and idempotent: records already in the ledger are
     * skipped rather than paid a second time.
     */
    public function generate_v2()
    {
        if (!$this->cap('compute')) { access_denied('payplex_commission'); }

        $period = (string) ($this->input->post('period') ?: date('Y-m'));
        $policy = $this->cmodel->activePolicy();

        if (!$policy) {
            set_alert('danger', 'Commission generation is disabled: no source policy has been approved and '
                . 'activated. Configure one under Source Policy first.');
            redirect(admin_url('payplex_commission/commission/source_policy'));
        }

        $events = $this->collectSourceEvents($period);
        $res    = $this->cmodel->evaluateSources($events, true, $period, get_staff_user_id());

        $t = $res['totals'];
        $msg = 'Run #' . (int) $res['run_id'] . ': ' . (int) $res['statements_created'] . ' statement(s) created for '
             . $period . ' from ' . (int) $t['eligible_count'] . ' eligible transaction(s), total '
             . number_format((float) $t['commission'], 2) . '.';
        if ((int) $t['config_required_count'] > 0) {
            $msg .= ' ' . (int) $t['config_required_count'] . ' transaction(s) need rule configuration and were NOT paid.';
        }
        if ((int) $t['excluded_count'] > 0) {
            $msg .= ' ' . (int) $t['excluded_count'] . ' excluded (see the run detail for reasons).';
        }
        set_alert('success', $msg);
        redirect(admin_url('payplex_commission/commission/preview?period=' . urlencode($period)));
    }

    /**
     * Gather candidate source events for a period.
     *
     * Reads Perfex invoices and payments READ-ONLY and hands them to the policy
     * engine as neutral event rows. Nothing here decides eligibility — that is
     * the policy's job — so this deliberately returns everything in the period
     * and lets the engine explain what it excluded and why.
     */
    private function collectSourceEvents($period)
    {
        $range = $this->cmodel->periodRange($period);
        return $this->cmodel->collectInvoicePaymentEvents($range[0], $range[1]);
    }

    /** Detail of one preview or generation run. */
    public function run_detail($id = 0)
    {
        if (!$this->cap('compute')) { access_denied('payplex_commission'); }

        $run = $this->cmodel->generationRun((int) $id);
        if (!$run) { show_404(); }

        $data['title'] = ucfirst($run->mode) . ' run #' . (int) $id;
        $data['run']   = $run;
        $data['result']= json_decode((string) $run->result_json, true);
        $this->load->view('payplex_commission/run_detail', $data);
    }

    /* ================= v0.2.0 — Approval queue & clawbacks (§2.3, §2.5) ================= */

    public function approvals()
    {
        // A checker granted only approve and reject still has to reach the queue.
        if (!$this->cap('review') && !$this->cap('approve') && !$this->cap('reject')) {
            access_denied('payplex_commission');
        }

        $data['title']  = 'Commission Approval Queue';
        $data['rows']   = $this->cmodel->approvalQueue();
        $data['states'] = Payplex_commission_workflow::states();
        $data['me']     = get_staff_user_id();
        /*
         * The audit trail is its own grant. It was visible to anyone who could
         * open this queue, so "may review statements" silently carried "may
         * read every decision ever recorded" — two different authorities.
         */
        $data['can_audit'] = $this->cap('audit_view');
        $data['audit']     = $data['can_audit'] ? $this->cmodel->statementAuditLog(0, 60) : array();
        // Evidence, not assertion: the panel showed "Append-only" — a claim
        // about this code — where it can instead show whether the stored log
        // still hashes to what was written.
        $data['chain']  = $this->cmodel->verifyStatementAuditChain();
        // Whether a checker exists at all is not visible anywhere else: an
        // administrator opening this page sees a working queue either way.
        $data['approvers'] = $this->cmodel->staffWithCapability('approve');
        $this->load->view('payplex_commission/approvals', $data);
    }

    /** Move a statement through the lifecycle (POST). Every gate is server-side. */
    public function statement_transition()
    {
        $id = (int) $this->input->post('id');
        $to = (string) $this->input->post('to');

        /*
         * One lookup decides this, and an unrecognised target is refused.
         * The previous shape — a weak base capability plus a list of stronger
         * exceptions — allowed every target nobody had listed.
         */
        $need = Payplex_commission_workflow::capabilityFor($to);
        if ($need === null || !$this->cap($need)) { access_denied('payplex_commission'); }

        $res = $this->cmodel->statementTransition($id, $to, get_staff_user_id(), (string) $this->input->post('reason'));
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Statement #' . $id . ' moved to ' . $to . '.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/approvals'));
    }

    /** Statement detail with adjustments and its audit trail. */
    public function statement_detail($id = 0)
    {
        if (!$this->cap('view_all')) { access_denied('payplex_commission'); }

        $s = $this->cmodel->statementRow((int) $id);
        if (!$s) { show_404(); }

        $data['title']       = 'Statement #' . (int) $id;
        $data['s']           = $s;
        $data['items']       = $this->cmodel->itemsFor((int) $id);
        $data['adjustments'] = $this->cmodel->adjustmentsFor((int) $id);
        $data['can_audit']   = $this->cap('audit_view');
        $data['audit']       = $data['can_audit'] ? $this->cmodel->statementAuditLog((int) $id, 100) : array();
        $data['adjTypes']    = Payplex_commission_workflow::adjustmentTypes();
        $data['immutable']   = Payplex_commission_workflow::isImmutable($s);
        $data['issue']       = Payplex_commission_workflow::canIssue($s);
        $data['me']          = get_staff_user_id();
        $this->load->view('payplex_commission/statement_detail', $data);
    }

    /** Post an adjustment (POST) — the only way a frozen statement changes. */
    public function add_adjustment()
    {
        if (!$this->cap('approve')) { access_denied('payplex_commission'); }

        $id  = (int) $this->input->post('statement_id');
        $res = $this->cmodel->addAdjustment($id, array(
            'type'   => $this->input->post('type'),
            'amount' => $this->input->post('amount'),
            'reason' => $this->input->post('reason'),
        ), get_staff_user_id());

        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Adjustment recorded. The original statement was not modified.'
            : implode(' ', $res['errors']));
        redirect(admin_url('payplex_commission/commission/statement_detail/' . $id));
    }

    /* ---------------- clawbacks ---------------- */

    public function clawbacks()
    {
        if (!$this->cap('clawback')) { access_denied('payplex_commission'); }

        $data['title']    = 'Commission Clawbacks';
        $data['rows']     = $this->cmodel->clawbacks();
        $data['ledger']   = $this->cmodel->ledgerEntries(100);
        $data['triggers'] = Payplex_commission_clawback::triggers();
        $data['methods']  = Payplex_commission_clawback::recoveryMethods();
        $data['statuses'] = Payplex_commission_clawback::statuses();
        $data['me']       = get_staff_user_id();
        $this->load->view('payplex_commission/clawbacks', $data);
    }

    /** Raise a clawback against an earned commission entry (POST). */
    public function raise_clawback()
    {
        if (!$this->cap('clawback')) { access_denied('payplex_commission'); }

        $ledgerId = (int) $this->input->post('ledger_id');
        $res = $this->cmodel->raiseClawback($ledgerId, array(
            'trigger'          => $this->input->post('trigger'),
            'trigger_ref'      => $this->input->post('trigger_ref'),
            'returned_amount'  => $this->input->post('returned_amount'),
            'basis'            => $this->input->post('basis'),
            'fixed_amount'     => $this->input->post('fixed_amount'),
            'recovery_method'  => $this->input->post('recovery_method'),
            'reason'           => $this->input->post('reason'),
            'effective_period' => $this->input->post('effective_period'),
        ), get_staff_user_id());

        if (!empty($res['ok'])) {
            $msg = 'Clawback #' . (int) $res['id'] . ' raised for ' . number_format((float) $res['amount'], 2)
                 . ', pending review. The original commission entry was not modified.';
            if (!empty($res['capped'])) { $msg .= ' (Limited to the amount still outstanding.)'; }
            set_alert('success', $msg);
        } else {
            set_alert(!empty($res['duplicate']) ? 'warning' : 'danger', implode(' ', $res['errors']));
        }
        redirect(admin_url('payplex_commission/commission/clawbacks'));
    }

    /** Approve, recover, waive or reject a clawback (POST). */
    public function decide_clawback()
    {
        if (!$this->cap('clawback')) { access_denied('payplex_commission'); }

        $id  = (int) $this->input->post('id');
        $to  = (string) $this->input->post('to');
        $res = $this->cmodel->decideClawback($id, $to, get_staff_user_id(), (string) $this->input->post('reason'));

        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Clawback #' . $id . ' moved to ' . $to . '.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/clawbacks'));
    }

    /* ================= v0.2.0 — Payout batches & export (§2.4) ================= */

    public function payouts()
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $data['title']    = 'Payout Batches';
        $data['batches']  = $this->cmodel->payoutBatches();
        $data['period']   = (string) ($this->input->get('period') ?: date('Y-m'));
        $data['payable']  = $this->cmodel->payableStatements($data['period']);
        $data['events']   = $this->cmodel->payoutEvents(0, 40);
        $data['me']       = get_staff_user_id();
        $this->load->view('payplex_commission/payouts', $data);
    }

    /** Assemble a draft batch from payable statements (POST). */
    public function create_payout()
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $period = (string) ($this->input->post('period') ?: date('Y-m'));
        $res = $this->cmodel->createPayoutBatch($period, get_staff_user_id());

        if (empty($res['ok'])) {
            set_alert('danger', implode(' ', $res['errors']));
            redirect(admin_url('payplex_commission/commission/payouts'));
        }
        $msg = 'Batch ' . $res['reference'] . ' created with ' . (int) $res['count'] . ' item(s).';
        if (!empty($res['blocked'])) {
            $msg .= ' ' . count($res['blocked']) . ' item(s) cannot be paid yet and must be fixed before approval.';
        }
        set_alert(!empty($res['blocked']) ? 'warning' : 'success', $msg);
        redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $res['id']));
    }

    public function payout_detail($id = 0)
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $batch = $this->cmodel->payoutBatch((int) $id);
        if (!$batch) { show_404(); }

        $data['title']    = 'Payout Batch ' . $batch['reference'];
        $data['batch']    = $batch;
        $data['items']    = $this->cmodel->payoutItems((int) $id);
        $data['blocked']  = Payplex_commission_payout::blockingIssues($data['items']);
        $data['events']   = $this->cmodel->payoutEvents((int) $id, 60);
        $data['failures'] = Payplex_commission_payout::failureReasons();
        $data['me']       = get_staff_user_id();
        $this->load->view('payplex_commission/payout_detail', $data);
    }

    public function payout_transition()
    {
        $id = (int) $this->input->post('id');
        $to = (string) $this->input->post('to');

        /*
         * One lookup decides this, and an unrecognised target is refused.
         * The previous shape — a weak base capability plus a list of stronger
         * exceptions — allowed every target nobody had listed.
         */
        $need = Payplex_commission_payout::capabilityFor($to);
        if ($need === null || !$this->cap($need)) { access_denied('payplex_commission'); }

        $res = $this->cmodel->payoutTransition($id, $to, get_staff_user_id(), (string) $this->input->post('reason'));
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Batch #' . $id . ' moved to ' . $to . '.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/payout_detail/' . $id));
    }

    public function update_payout_item()
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $itemId = (int) $this->input->post('item_id');
        $res = $this->cmodel->updatePayoutItem($itemId, array(
            'tds_rate'          => $this->input->post('tds_rate'),
            'tds_exempt'        => $this->input->post('tds_exempt'),
            'other_deductions'  => $this->input->post('other_deductions'),
            'details_corrected' => $this->input->post('details_corrected'),
        ), get_staff_user_id());

        set_alert(!empty($res['ok']) ? 'success' : 'danger',
            !empty($res['ok']) ? 'Item updated.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $this->input->post('batch_id')));
    }

    /**
     * Download the masked payout export as CSV.
     *
     * The file carries a masked account and a verified account reference. It is
     * NOT a bank-ready file: producing one with full account numbers is a
     * separate, explicitly approved action that this module does not perform.
     */
    public function export_payout($id = 0)
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $batch  = $this->cmodel->payoutBatch((int) $id);
        if (!$batch) { show_404(); }

        /*
         * Refused early so an unapproved batch never reaches the file-building
         * step, and the attempt is recorded. buildExport() enforces the same
         * rule for any other caller.
         */
        $gate = Payplex_commission_payout::canExport($batch);
        if (!$gate['allowed']) {
            $this->cmodel->payoutEvent((int) $id, null, 'export_refused', null, null,
                get_staff_user_id(), $gate['reason'], array('code' => $gate['code']));
            set_alert('danger', $gate['reason']);
            redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $id));
        }

        $export = $this->cmodel->buildPayoutExport((int) $id);
        if (empty($export['ok'])) {
            set_alert('danger', $export['reason']);
            redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $id));
        }

        $csv = Payplex_commission_payout::toCsv($export);
        $this->cmodel->payoutEvent((int) $id, null, 'export_downloaded', null, null, get_staff_user_id(),
            'Masked CSV export downloaded.', array('rows' => $export['count'], 'net' => $export['total_net']));

        $name = 'payout-' . $batch['reference'] . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($csv));
        echo $csv;
    }

    /** Per-beneficiary payment advice (masked). */
    public function payment_advice($itemId = 0)
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $item = $this->cmodel->payoutItem((int) $itemId);
        if (!$item) { show_404(); }
        $batch = $this->cmodel->payoutBatch((int) $item['batch_id']);

        header('Content-Type: text/plain; charset=UTF-8');
        echo Payplex_commission_payout::paymentAdvice($batch, $item);
    }

    /** Record a real settlement reported by a person (POST). */
    public function record_settlement()
    {
        if (!$this->cap('pay')) { access_denied('payplex_commission'); }

        $itemId = (int) $this->input->post('item_id');
        $res = $this->cmodel->recordSettlement($itemId, array(
            'bank_reference' => $this->input->post('bank_reference'),
            'paid_amount'    => $this->input->post('paid_amount'),
            'paid_at'        => $this->input->post('paid_at'),
        ), get_staff_user_id());

        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Settlement recorded against the bank reference provided.'
            : implode(' ', $res['errors']));
        redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $this->input->post('batch_id')));
    }

    public function record_failure()
    {
        if (!$this->cap('pay')) { access_denied('payplex_commission'); }

        $itemId = (int) $this->input->post('item_id');
        $res = $this->cmodel->recordFailure($itemId, array(
            'failure_reason' => $this->input->post('failure_reason'),
            'notes'          => $this->input->post('notes'),
        ), get_staff_user_id());

        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Failure recorded. The item is not marked paid.' : implode(' ', $res['errors']));
        redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $this->input->post('batch_id')));
    }

    public function retry_payout_item()
    {
        if (!$this->cap('pay')) { access_denied('payplex_commission'); }

        $itemId = (int) $this->input->post('item_id');
        $res = $this->cmodel->retryPayoutItem($itemId, get_staff_user_id());
        set_alert(!empty($res['ok']) ? 'success' : 'danger', !empty($res['ok'])
            ? 'Item queued for another attempt.' : $res['reason']);
        redirect(admin_url('payplex_commission/commission/payout_detail/' . (int) $this->input->post('batch_id')));
    }

    /** Reconcile a batch against pasted bank rows (reference,amount per line). */
    public function reconcile_payout()
    {
        if (!$this->cap('payout')) { access_denied('payplex_commission'); }

        $id  = (int) $this->input->post('id');
        $raw = (string) $this->input->post('bank_rows');

        $rows = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            $parts = str_getcsv($line);
            if (count($parts) < 2) { continue; }
            $rows[] = array('bank_reference' => trim($parts[0]), 'amount' => (float) trim($parts[1]));
        }

        $batch = $this->cmodel->payoutBatch($id);
        if (!$batch) { show_404(); }

        $data['title']  = 'Reconciliation — ' . $batch['reference'];
        $data['batch']  = $batch;
        $data['result'] = $this->cmodel->reconcileBatch($id, $rows);
        $data['raw']    = $raw;
        $this->load->view('payplex_commission/reconcile', $data);
    }

    /* ================= Staging bootstrap (dummy rates) ================= */

    /** Declare this install's environment (POST). Production is the safe default. */
    public function set_environment()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $env = strtolower(trim((string) $this->input->post('environment')));
        $env = $env === 'staging' ? 'staging' : 'production';

        if (function_exists('update_option')) { update_option('payplex_commission_environment', $env); }
        else { add_option('payplex_commission_environment', $env); }

        $this->cmodel->ruleAudit(null, 'environment_set', null, $env, get_staff_user_id(),
            'Install environment declared as ' . strtoupper($env)
            . ($env === 'production' ? '. Test-flagged rules and policies can no longer pay.' : '.'));

        set_alert('success', 'Environment set to ' . strtoupper($env) . '. '
            . ($env === 'staging'
                ? 'Rules flagged TEST can now be used here, and still cannot pay in production.'
                : 'Rules flagged TEST are now refused.'));
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /** Seed the labelled dummy rules and policy (POST). Staging only. */
    public function seed_dummy()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $r = $this->cmodel->seedDummyRules(get_staff_user_id());
        $p = $this->cmodel->seedDummyPolicy(get_staff_user_id());

        $msg = !empty($r['ok'])
            ? 'Seeded ' . (int) $r['created'] . ' DUMMY rule(s), each flagged TEST so they cannot pay in production.'
            : 'Rules: ' . $r['reason'];
        $msg .= !empty($p['ok']) ? ' Seeded the DUMMY source policy.' : ' Policy: ' . (isset($p['reason']) ? $p['reason'] : implode(' ', (array) (isset($p['errors']) ? $p['errors'] : array())));

        set_alert(!empty($r['ok']) ? 'success' : 'warning', $msg);
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /** Staging-only bootstrap approval of a TEST rule (POST). */
    public function bootstrap_approve_rule()
    {
        if (!$this->cap('rules')) { access_denied('payplex_commission'); }

        $id  = (int) $this->input->post('id');
        $res = $this->cmodel->bootstrapApproveRule($id, get_staff_user_id(),
            (string) $this->input->post('reason'));

        set_alert(!empty($res['ok']) ? 'warning' : 'danger', !empty($res['ok'])
            ? 'TEST rule #' . $id . ' approved via STAGING BOOTSTRAP. This is recorded as a bootstrap, '
              . 'not a real approval, and the rule cannot pay in production.'
            : $res['reason']);
        redirect(admin_url('payplex_commission/commission/rule_builder'));
    }

    /** Staging-only bootstrap approval of a TEST source policy (POST). */
    public function bootstrap_approve_policy()
    {
        if (!$this->cap('source_policy')) { access_denied('payplex_commission'); }

        $id  = (int) $this->input->post('id');
        $res = $this->cmodel->bootstrapApprovePolicy($id, get_staff_user_id(),
            (string) $this->input->post('reason'));

        set_alert(!empty($res['ok']) ? 'warning' : 'danger', !empty($res['ok'])
            ? 'TEST policy #' . $id . ' approved via STAGING BOOTSTRAP, recorded as such.'
            : $res['reason']);
        redirect(admin_url('payplex_commission/commission/source_policy'));
    }
}
