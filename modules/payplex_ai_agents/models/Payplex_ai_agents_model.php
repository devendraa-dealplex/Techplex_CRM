<?php

defined('BASEPATH') or exit('No direct script access allowed');

// Pure-logic libraries. Use __DIR__ (NOT module_dir_path at include time) to
// avoid a fatal on PHP 8.3 when the module path helper isn't ready yet.
require_once __DIR__ . '/../libraries/Payplex_agent_lifecycle.php';
require_once __DIR__ . '/../libraries/Payplex_agent_safety.php';
require_once __DIR__ . '/../libraries/Payplex_agent_templates.php';
require_once __DIR__ . '/../libraries/Payplex_agent_audit.php';
require_once __DIR__ . '/../libraries/Payplex_agent_sandbox.php';
require_once __DIR__ . '/../libraries/Payplex_agent_knowledge.php';
require_once __DIR__ . '/../libraries/Payplex_agent_calling.php';
require_once __DIR__ . '/../libraries/Payplex_agent_pipeline.php';
require_once __DIR__ . '/../libraries/Payplex_agent_exec_templates.php';
require_once __DIR__ . '/../libraries/Payplex_agent_custom_template.php';
require_once __DIR__ . '/../libraries/Payplex_agent_approval_matrix.php';
require_once __DIR__ . '/../libraries/Payplex_agent_decision.php';
require_once __DIR__ . '/../libraries/Payplex_agent_council.php';
require_once __DIR__ . '/../libraries/Payplex_agent_rbac.php';
require_once __DIR__ . '/../libraries/Payplex_agent_exec_knowledge.php';
require_once __DIR__ . '/../libraries/Payplex_agent_memory.php';
require_once __DIR__ . '/../libraries/Payplex_agent_goals.php';
require_once __DIR__ . '/../libraries/Payplex_agent_scorecard.php';
require_once __DIR__ . '/../libraries/Payplex_agent_comms.php';
require_once __DIR__ . '/../libraries/Payplex_agent_vote.php';

class Payplex_ai_agents_model extends App_Model
{
    /** JSON-typed columns (stored encoded, returned decoded). */
    private $jsonFields = array(
        'knowledge_sources', 'products_services', 'lead_sources', 'crm_permissions',
        'allowed_tools', 'triggers', 'conditions', 'actions', 'prohibited_actions',
        'approval_required_actions',
    );

    public function __construct()
    {
        parent::__construct();
    }

    private function table()
    {
        return db_prefix() . 'payplex_ai_agents';
    }

    /* ---------------- Read ---------------- */

    public function get($id = null, $onlyTemplates = null)
    {
        if ($id !== null) {
            $this->db->where('id', (int) $id);
            $row = $this->db->get($this->table())->row();
            return $row ? $this->decode($row) : null;
        }
        if ($onlyTemplates === true) {
            $this->db->where('is_template', 1);
        } elseif ($onlyTemplates === false) {
            $this->db->where('is_template', 0);
        }
        $this->db->order_by('id', 'DESC');
        $rows = $this->db->get($this->table())->result();
        return array_map(array($this, 'decode'), $rows);
    }

    public function agents()
    {
        return $this->get(null, false);
    }

    public function templates()
    {
        return $this->get(null, true);
    }

    private function decode($row)
    {
        foreach ($this->jsonFields as $f) {
            if (isset($row->$f) && is_string($row->$f) && $row->$f !== '') {
                $dec = json_decode($row->$f, true);
                $row->$f = is_array($dec) ? $dec : array();
            } elseif (isset($row->$f)) {
                $row->$f = array();
            }
        }
        return $row;
    }

    private function encodeForSave($data)
    {
        foreach ($this->jsonFields as $f) {
            if (isset($data[$f]) && is_array($data[$f])) {
                $data[$f] = json_encode(array_values($data[$f]));
            }
        }
        return $data;
    }

    /* ---------------- Create / update ---------------- */

    public function create($data, $actorId = 0)
    {
        $data = $this->encodeForSave($data);
        $data['status']      = Payplex_agent_lifecycle::initialStatus();
        $data['mode']        = Payplex_agent_lifecycle::MODE_SANDBOX; // new agents ALWAYS sandbox
        $data['version']     = 1;
        $data['created_by']  = (int) $actorId;
        $data['owner_id']    = isset($data['owner_id']) && $data['owner_id'] ? (int) $data['owner_id'] : (int) $actorId;
        $data['datecreated'] = date('Y-m-d H:i:s');
        $data['lastupdated'] = date('Y-m-d H:i:s');
        unset($data['id'], $data['approved_by'], $data['submitted_by']);

        $this->db->insert($this->table(), $data);
        $id = $this->db->insert_id();
        $this->snapshotVersion($id, 'created', $actorId);
        $this->audit($id, 'config_change', 'Agent created', array('name' => isset($data['name']) ? $data['name'] : ''), $actorId);
        return $id;
    }

    public function update($id, $data, $actorId = 0)
    {
        $id = (int) $id;
        $current = $this->get($id);
        if (!$current) {
            return false;
        }
        // Config edits are only allowed in non-locked statuses.
        $editable = array(Payplex_agent_lifecycle::DRAFT, Payplex_agent_lifecycle::SANDBOX, Payplex_agent_lifecycle::TESTING);
        if (!in_array($current->status, $editable, true)) {
            return 'locked';
        }
        $data = $this->encodeForSave($data);
        unset($data['id'], $data['status'], $data['mode'], $data['created_by'], $data['approved_by'], $data['datecreated']);
        $data['lastupdated'] = date('Y-m-d H:i:s');
        $this->db->where('id', $id)->update($this->table(), $data);
        $this->audit($id, 'config_change', 'Agent configuration updated', array('fields' => array_keys($data)), $actorId);
        return true;
    }

    /* ---------------- Clone ---------------- */

    public function cloneAgent($id, $actorId = 0)
    {
        $src = $this->get($id);
        if (!$src) {
            return false;
        }
        $arr = (array) $src;
        $arr['name'] = $arr['name'] . ' (copy)';
        $arr['is_template'] = 0;
        unset($arr['id'], $arr['datecreated'], $arr['lastupdated'], $arr['approved_by'], $arr['submitted_by']);
        $newId = $this->create($arr, $actorId);
        $this->audit($newId, 'config_change', 'Cloned from agent #' . (int) $id, array('source' => (int) $id), $actorId);
        return $newId;
    }

    /**
     * Create an agent from ANY template source — built-in (13), executive (18)
     * or an admin-created custom template. Optional overrides let the admin set
     * a custom display name / agent id at creation without changing the role.
     */
    public function createFromTemplate($slug, $actorId = 0, $overrides = array())
    {
        $tpl = $this->resolveTemplate($slug);
        if (!$tpl) {
            return false;
        }
        $agent = $this->mapTemplateToAgent($tpl, $overrides);
        $id = $this->create($agent, $actorId);
        $this->audit($id, 'config_change', 'Created from template "' . $slug . '"',
            array('template' => $slug, 'executive' => !empty($agent['is_executive'])), $actorId);
        return $id;
    }

    /** Resolve a slug to a template config from any of the three sources. */
    public function resolveTemplate($slug)
    {
        $tpl = Payplex_agent_templates::bySlug($slug);
        if ($tpl) { return $tpl; }
        $tpl = Payplex_agent_exec_templates::bySlug($slug);
        if ($tpl) { return $tpl; }
        $row = $this->customTemplateBySlug($slug);
        if ($row) { return $this->customTemplateToConfig($row); }
        return null;
    }

    /** Map a template config array onto valid agent columns (+ overrides). */
    private function mapTemplateToAgent($tpl, $overrides = array())
    {
        $g = function ($k, $d = null) use ($tpl) { return isset($tpl[$k]) ? $tpl[$k] : $d; };
        $role = $g('system_role', $g('name'));
        $name = isset($overrides['name']) && $overrides['name'] !== '' ? $overrides['name'] : $g('name');
        $ref  = isset($overrides['agent_ref']) && $overrides['agent_ref'] !== ''
            ? $overrides['agent_ref']
            : ($g('agent_ref_prefix') ? $g('agent_ref_prefix') . '-001' : null);
        $display = isset($overrides['display_name']) && $overrides['display_name'] !== ''
            ? $overrides['display_name'] : $name;

        return array(
            'name'                  => $name,
            'system_role'           => $role,
            'display_name'          => $display,
            'short_name'            => $g('short_name', isset($overrides['short_name']) ? $overrides['short_name'] : ''),
            'agent_ref'             => $ref,
            'department'            => $g('department'),
            'company'               => isset($overrides['company']) ? $overrides['company'] : $g('company'),
            'purpose'               => $g('purpose'),
            'description'           => $g('description', $g('purpose')),
            'persona'               => $g('persona'),
            'communication_style'   => $g('communication_style'),
            'primary_language'      => $g('primary_language'),
            'extra_languages'       => $g('extra_languages'),
            'tone'                  => $g('tone'),
            'expertise_tags'        => is_array($g('expertise_tags')) ? implode(',', $g('expertise_tags')) : $g('expertise_tags'),
            'template_slug'         => $g('template_slug'),
            'system_prompt'         => $g('system_prompt'),
            'ai_provider'           => $g('ai_provider', 'openai'),
            'ai_model'              => $g('ai_model', 'gpt-4o-mini'),
            'fallback_model'        => $g('fallback_model'),
            'allowed_tools'         => $g('allowed_tools', array()),
            'triggers'              => $g('triggers', array()),
            'prohibited_actions'    => $g('prohibited_actions', array()),
            'approval_required_actions' => $g('approval_required_actions', array()),
            'approval_tier'         => $g('approval_tier', 'chairman'),
            'confidence_threshold'  => $g('confidence_threshold', 0.75),
            'human_escalation'      => $g('human_escalation', 1),
            'token_limit'           => $g('token_limit', 100000),
            'daily_budget'          => $g('daily_budget', 5.0),
            'monthly_budget'        => $g('monthly_budget', 100.0),
            'is_executive'          => (int) $g('is_executive', 0),
            'is_template'           => 0,
        );
    }

    /* ---------------- Executive + custom templates ---------------- */

    /** All 18 executive templates (pure library). */
    public function execTemplates()
    {
        return Payplex_agent_exec_templates::all();
    }

    /** Generic (invented) name suggestions per executive role. */
    public function execNameSuggestions()
    {
        return Payplex_agent_exec_templates::nameSuggestions();
    }

    private function customTable()
    {
        return db_prefix() . 'payplex_ai_agent_custom_templates';
    }

    public function customTemplates()
    {
        if (!$this->db->table_exists($this->customTable())) { return array(); }
        return $this->db->order_by('id', 'DESC')->get($this->customTable())->result();
    }

    public function customTemplate($id)
    {
        return $this->db->where('id', (int) $id)->get($this->customTable())->row();
    }

    public function customTemplateBySlug($slug)
    {
        if (!$this->db->table_exists($this->customTable())) { return null; }
        return $this->db->where('template_slug', $slug)->get($this->customTable())->row();
    }

    /** Convert a stored custom-template row to a template config array. */
    private function customTemplateToConfig($row)
    {
        $arr = (array) $row;
        foreach (array('allowed_tools', 'triggers', 'prohibited_actions', 'approval_required_actions') as $f) {
            if (isset($arr[$f]) && is_string($arr[$f]) && $arr[$f] !== '') {
                $dec = json_decode($arr[$f], true);
                $arr[$f] = is_array($dec) ? $dec : array();
            } else {
                $arr[$f] = array();
            }
        }
        $arr['is_template'] = 1;
        return $arr;
    }

    /**
     * Create/update a custom template from validated form data.
     * $data must already be normalized by Payplex_agent_custom_template::validate().
     */
    public function saveCustomTemplate($data, $actorId = 0, $id = 0)
    {
        $now = date('Y-m-d H:i:s');
        if ((int) $id > 0) {
            $data['lastupdated'] = $now;
            unset($data['template_slug']); // slug is immutable once created
            $this->db->where('id', (int) $id)->update($this->customTable(), $data);
            $this->audit(null, 'config_change', 'Custom template updated: ' . (isset($data['name']) ? $data['name'] : ('#' . $id)), array('template_id' => (int) $id), $actorId);
            return (int) $id;
        }
        // ensure unique slug
        $base = $data['template_slug'];
        $slug = $base;
        $n = 2;
        while ($this->customTemplateBySlug($slug)) {
            $slug = $base . '_' . $n;
            $n++;
        }
        $data['template_slug'] = $slug;
        $data['created_by']    = (int) $actorId;
        $data['datecreated']   = $now;
        $data['lastupdated']   = $now;
        $this->db->insert($this->customTable(), $data);
        $newId = $this->db->insert_id();
        $this->audit(null, 'config_change', 'Custom template created: ' . $data['name'], array('template_id' => $newId, 'slug' => $slug), $actorId);
        return $newId;
    }

    public function deleteCustomTemplate($id, $actorId = 0)
    {
        $row = $this->customTemplate($id);
        if (!$row) { return false; }
        $this->db->where('id', (int) $id)->delete($this->customTable());
        $this->audit(null, 'config_change', 'Custom template deleted: ' . $row->name, array('template_id' => (int) $id), $actorId);
        return true;
    }

    /**
     * Save an existing agent's configuration as a reusable custom template.
     */
    public function saveAgentAsTemplate($agentId, $actorId = 0)
    {
        $a = $this->get((int) $agentId);
        if (!$a) { return false; }
        $toStr = function ($v) { return is_array($v) ? implode(',', $v) : (string) $v; };
        $input = array(
            'name'                => $a->name . ' Template',
            'system_role'         => isset($a->system_role) && $a->system_role ? $a->system_role : $a->name,
            'short_name'          => isset($a->short_name) ? $a->short_name : '',
            'agent_ref_prefix'    => isset($a->agent_ref) ? preg_replace('/-\d+$/', '', (string) $a->agent_ref) : 'AI-AGT',
            'department'          => $a->department,
            'company'             => isset($a->company) ? $a->company : '',
            'persona'             => isset($a->persona) ? $a->persona : '',
            'communication_style' => isset($a->communication_style) ? $a->communication_style : 'executive-brief',
            'primary_language'    => isset($a->primary_language) ? $a->primary_language : 'en',
            'extra_languages'     => isset($a->extra_languages) ? $a->extra_languages : '',
            'tone'                => isset($a->tone) ? $a->tone : 'professional',
            'expertise_tags'      => isset($a->expertise_tags) ? $a->expertise_tags : '',
            'purpose'             => $a->purpose,
            'system_prompt'       => $a->system_prompt,
            'ai_provider'         => $a->ai_provider,
            'ai_model'            => $a->ai_model,
            'fallback_model'      => isset($a->fallback_model) ? $a->fallback_model : 'gpt-4o-mini',
            'allowed_tools'       => $toStr(is_array($a->allowed_tools) ? $a->allowed_tools : array()),
            'triggers'            => $toStr(is_array($a->triggers) ? $a->triggers : array()),
            'approval_tier'       => isset($a->approval_tier) ? $a->approval_tier : 'chairman',
            'confidence_threshold' => $a->confidence_threshold,
            'token_limit'         => $a->token_limit,
            'daily_budget'        => $a->daily_budget,
            'monthly_budget'      => $a->monthly_budget,
            /* absence means not executive, and says so explicitly rather than
             * relying on isset() to imply it */
            'is_executive'        => property_exists($a, 'is_executive') ? (int) $a->is_executive : 0,
        );
        $res = Payplex_agent_custom_template::validate($input);
        if (empty($res['ok'])) { return false; }
        return $this->saveCustomTemplate($res['data'], $actorId, 0);
    }

    /* ---------------- Lifecycle ---------------- */

    public function transition($id, $action, $actorId = 0, $note = '')
    {
        $id = (int) $id;
        $agent = $this->get($id);
        if (!$agent) {
            return array('ok' => false, 'error' => 'not_found');
        }

        $ctx = array(
            'actor_id'     => (int) $actorId,
            'created_by'   => (int) $agent->created_by,
            'submitted_by' => (int) $agent->submitted_by,
        );
        $res = Payplex_agent_lifecycle::apply($action, $agent->status, $ctx);
        if (empty($res['ok'])) {
            $this->audit($id, 'error', 'Transition "' . $action . '" rejected: ' . $res['error'], array('from' => $agent->status), $actorId);
            return $res;
        }

        $update = array('status' => $res['to'], 'lastupdated' => date('Y-m-d H:i:s'));

        // Side-effects per action.
        if ($action === 'submit') {
            $update['submitted_by'] = (int) $actorId;
        } elseif ($action === 'approve') {
            $update['approved_by'] = (int) $actorId;
            $update['approver_id'] = (int) $actorId;
            $this->snapshotVersion($id, 'approved v' . (int) $agent->version, $actorId);
        } elseif ($action === 'reject') {
            $update['submitted_by'] = null;
            $update['approved_by']  = null;
        } elseif ($action === 'new_version') {
            $update['version']      = (int) $agent->version + 1;
            $update['approved_by']  = null;
            $update['submitted_by'] = null;
            $this->snapshotVersion($id, 'new version ' . ((int) $agent->version + 1), $actorId);
        }

        $this->db->where('id', $id)->update($this->table(), $update);

        $evt = in_array($action, array('approve', 'reject'), true) ? ($action === 'approve' ? 'approval' : 'rejection') : 'lifecycle';
        $this->audit($id, $evt, 'Agent ' . $action . ': ' . $agent->status . ' -> ' . $res['to'] . ($note ? ' (' . $note . ')' : ''), array('action' => $action, 'from' => $agent->status, 'to' => $res['to']), $actorId);
        return array('ok' => true, 'to' => $res['to']);
    }

    /**
     * Activate to PRODUCTION mode. Guarded: only an APPROVED agent whose approver
     * differs from creator/submitter may go live. Everything else stays sandbox.
     */
    public function activateProduction($id, $actorId = 0)
    {
        $agent = $this->get((int) $id);
        if (!$agent) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if ((int) $agent->approved_by <= 0) {
            return array('ok' => false, 'error' => 'not_approved');
        }

        /*
         * Refuse while nothing can actually run an agent in production.
         *
         * Every gate below this line is real and works. What does not exist is
         * anything that would execute the agent afterwards: the only runner is
         * the sandbox one, and it hard-codes sandbox mode. Writing
         * mode = production here would mark the agent live and change nothing
         * else, which is a claim the install cannot honour — and the most
         * expensive kind of wrong, because it looks like success.
         *
         * Fail closed, and say exactly why. Payplex_agent_safety holds the one
         * statement of whether a runner exists.
         */
        $exec = Payplex_agent_safety::productionExecutionAvailable();
        if (empty($exec['available'])) {
            $this->audit((int) $id, 'error',
                'Production activation refused: ' . $exec['reason']
                . ' — no production runner exists, so the agent would be marked live '
                . 'without ever executing.',
                array('reason' => $exec['reason']), $actorId);
            return array('ok' => false, 'error' => $exec['reason'],
                         'message' => Payplex_agent_safety::productionUnavailableMessage());
        }

        // Move status to active first (guarded by state machine).
        $t = $this->transition($id, 'activate', $actorId);
        if (empty($t['ok'])) {
            return $t;
        }
        $this->db->where('id', (int) $id)->update($this->table(), array('mode' => Payplex_agent_lifecycle::MODE_PRODUCTION));
        $this->audit((int) $id, 'lifecycle', 'Activated in PRODUCTION mode', array(), $actorId);
        return array('ok' => true, 'to' => 'active', 'mode' => 'production');
    }

    /* ---------------- Kill switch ---------------- */

    public function setAgentKill($id, $on, $actorId = 0)
    {
        $on = $on ? 1 : 0;
        $this->db->where('id', (int) $id)->update($this->table(), array('agent_kill' => $on));
        if ($on) {
            // Emergency stop also pauses an active agent.
            $agent = $this->get((int) $id);
            if ($agent && in_array($agent->status, array('active', 'scheduled'), true)) {
                $this->db->where('id', (int) $id)->update($this->table(), array('status' => 'paused'));
            }
        }
        $this->audit((int) $id, 'kill_switch', 'Agent kill switch ' . ($on ? 'ENGAGED' : 'released'), array(), $actorId);
        return true;
    }

    /* ---------------- Runs ---------------- */

    public function sandboxTest($id, array $input, $actorId = 0)
    {
        $agent = $this->get((int) $id);
        if (!$agent) {
            return array('ok' => false, 'error' => 'not_found');
        }
        if (!Payplex_agent_lifecycle::canRunSandbox($agent->status)) {
            return array('ok' => false, 'error' => 'not_runnable');
        }

        $ctx = array(
            'global_kill' => (int) $this->getSetting('global_kill_switch', 0) === 1,
            'agent_kill'  => (int) $agent->agent_kill === 1,
            'budget'      => array(
                'token_limit' => (int) $agent->token_limit,
                'daily_limit' => (int) $agent->daily_execution_limit,
                'daily_runs'  => (int) $this->countRunsToday((int) $id),
            ),
        );

        $agentArr = (array) $agent;
        $run = Payplex_agent_sandbox::run($agentArr, $input, $ctx);

        $this->db->insert(db_prefix() . 'payplex_ai_agent_runs', array(
            'agent_id'          => (int) $id,
            'mode'              => 'sandbox',
            'status'            => $run['status'],
            'model'             => $run['model'],
            'tokens'            => (int) $run['tokens'],
            'cost'              => $run['estimated_cost'],
            'confidence'        => $run['confidence'],
            'simulated_actions' => (int) $run['simulated_actions'],
            'blocked_actions'   => (int) $run['blocked_actions'],
            'escalations'       => (int) $run['escalations'],
            'transcript_json'   => json_encode($run['transcript']),
            'triggered_by'      => (int) $actorId,
            'datecreated'       => date('Y-m-d H:i:s'),
        ));
        $runId = $this->db->insert_id();

        $this->audit((int) $id, 'output', 'Sandbox test run #' . $runId . ' ' . $run['status']
            . ' (' . $run['tokens'] . ' tokens, $' . number_format($run['estimated_cost'], 6) . ', '
            . $run['escalations'] . ' escalation(s))', array('run_id' => $runId), $actorId);
        $this->audit((int) $id, 'cost', 'Estimated cost $' . number_format($run['estimated_cost'], 6), array('tokens' => $run['tokens']), $actorId);

        // Route escalations from the run into the human-review queue.
        if ((int) $run['escalations'] > 0) {
            $this->escalate((int) $id, $runId, 'low_confidence',
                $run['escalations'] . ' action(s) in run #' . $runId . ' fell below the confidence threshold and would require human review in production.', $actorId);
        }

        $run['ok'] = true;
        $run['run_id'] = $runId;
        return $run;
    }

    public function runs($agentId, $limit = 50)
    {
        $this->db->where('agent_id', (int) $agentId)->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get(db_prefix() . 'payplex_ai_agent_runs')->result();
    }

    private function countRunsToday($agentId)
    {
        $this->db->where('agent_id', (int) $agentId);
        $this->db->where('DATE(datecreated)', date('Y-m-d'));
        return $this->db->count_all_results(db_prefix() . 'payplex_ai_agent_runs');
    }

    /* ---------------- Versions ---------------- */

    private function snapshotVersion($agentId, $note, $actorId)
    {
        $agent = $this->get((int) $agentId);
        if (!$agent) {
            return;
        }
        $this->db->insert(db_prefix() . 'payplex_ai_agent_versions', array(
            'agent_id'    => (int) $agentId,
            'version'     => (int) $agent->version,
            'config_json' => json_encode($agent),
            'note'        => substr((string) $note, 0, 255),
            'created_by'  => (int) $actorId,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }

    public function versions($agentId)
    {
        $this->db->where('agent_id', (int) $agentId)->order_by('id', 'DESC');
        return $this->db->get(db_prefix() . 'payplex_ai_agent_versions')->result();
    }

    public function rollback($agentId, $versionRowId, $actorId = 0)
    {
        $this->db->where('id', (int) $versionRowId)->where('agent_id', (int) $agentId);
        $ver = $this->db->get(db_prefix() . 'payplex_ai_agent_versions')->row();
        if (!$ver) {
            return array('ok' => false, 'error' => 'version_not_found');
        }
        $cfg = json_decode($ver->config_json, true);
        if (!is_array($cfg)) {
            return array('ok' => false, 'error' => 'bad_snapshot');
        }
        // Restore config fields only; force back to sandbox/draft for re-approval.
        unset($cfg['id'], $cfg['status'], $cfg['mode'], $cfg['approved_by'], $cfg['submitted_by'], $cfg['datecreated']);
        $cfg = $this->encodeForSave($cfg);
        $cfg['status'] = Payplex_agent_lifecycle::DRAFT;
        $cfg['mode']   = Payplex_agent_lifecycle::MODE_SANDBOX;
        $cfg['approved_by'] = null;
        $cfg['submitted_by'] = null;
        $cfg['lastupdated'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $agentId)->update($this->table(), $cfg);
        $this->audit((int) $agentId, 'config_change', 'Rolled back to version snapshot #' . (int) $versionRowId . ' (reset to draft/sandbox for re-approval)', array(), $actorId);
        return array('ok' => true);
    }

    /* ---------------- Audit ---------------- */

    public function audit($agentId, $eventType, $message, $data = array(), $actorId = 0)
    {
        $ip = '';
        if (function_exists('get_instance')) {
            $CI = &get_instance();
            if (isset($CI->input)) {
                $ip = $CI->input->ip_address();
            }
        }
        $this->db->insert(db_prefix() . 'payplex_ai_agent_audit', array(
            'agent_id'    => $agentId ? (int) $agentId : null,
            'event_type'  => $eventType,
            'actor_id'    => (int) $actorId,
            'message'     => substr((string) $message, 0, 500),
            'data_json'   => Payplex_agent_audit::encode($data),  // secrets masked here
            'ip'          => $ip,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }

    public function auditLog($agentId = null, $limit = 200)
    {
        if ($agentId !== null) {
            $this->db->where('agent_id', (int) $agentId);
        }
        $this->db->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get(db_prefix() . 'payplex_ai_agent_audit')->result();
    }

    /* ---------------- Settings ---------------- */

    public function getSetting($key, $default = null)
    {
        $this->db->where('skey', $key);
        $row = $this->db->get(db_prefix() . 'payplex_ai_agent_settings')->row();
        return $row ? $row->svalue : $default;
    }

    public function setSetting($key, $value, $actorId = 0)
    {
        $exists = $this->getSetting($key, null) !== null;
        if ($exists) {
            $this->db->where('skey', $key)->update(db_prefix() . 'payplex_ai_agent_settings', array('svalue' => (string) $value));
        } else {
            $this->db->insert(db_prefix() . 'payplex_ai_agent_settings', array('skey' => $key, 'svalue' => (string) $value));
        }
        if ($key === 'global_kill_switch') {
            $this->audit(null, 'kill_switch', 'GLOBAL kill switch ' . ($value ? 'ENGAGED' : 'released'), array(), $actorId);
        }
        return true;
    }

    /* ---------------- Template seeding ---------------- */

    public function seedTemplates()
    {
        $count = 0;
        foreach (Payplex_agent_templates::all() as $tpl) {
            $this->db->where('template_slug', $tpl['template_slug'])->where('is_template', 1);
            if ($this->db->count_all_results($this->table()) > 0) {
                continue; // already seeded
            }
            $row = $this->encodeForSave($tpl);
            $row['is_template'] = 1;
            $row['status']      = 'draft';
            $row['mode']        = 'sandbox';
            $row['version']     = 1;
            $row['datecreated'] = date('Y-m-d H:i:s');
            $row['lastupdated'] = date('Y-m-d H:i:s');
            $this->db->insert($this->table(), $row);
            $count++;
        }
        return $count;
    }

    /* ---------------- Dashboard aggregates ---------------- */

    public function costSummary()
    {
        $row = $this->db->query('SELECT COUNT(*) AS runs, COALESCE(SUM(tokens),0) AS tokens, COALESCE(SUM(cost),0) AS cost
            FROM ' . db_prefix() . 'payplex_ai_agent_runs')->row();
        return $row;
    }

    /* ================= M2: Knowledge base ================= */

    private function kbTable()
    {
        return db_prefix() . 'payplex_ai_agent_kb';
    }

    public function kbList($onlyActive = false)
    {
        if ($onlyActive) {
            $this->db->where('is_active', 1);
        }
        $this->db->order_by('id', 'DESC');
        return $this->db->get($this->kbTable())->result();
    }

    public function kbGet($id)
    {
        $this->db->where('id', (int) $id);
        return $this->db->get($this->kbTable())->row();
    }

    public function kbCreate($data, $actorId = 0)
    {
        $row = array(
            'title'           => trim((string) (isset($data['title']) ? $data['title'] : '')),
            'category'        => isset($data['category']) ? $data['category'] : 'faq',
            'content'         => isset($data['content']) ? $data['content'] : '',
            'keywords'        => isset($data['keywords']) ? $data['keywords'] : '',
            'scope'           => isset($data['scope']) && $data['scope'] !== '' ? $data['scope'] : 'all',
            'permission'      => isset($data['permission']) ? $data['permission'] : 'knowledge',
            'indexing_status' => 'indexed',   // simple synchronous "index"
            'version'         => 1,
            'is_active'       => 1,
            'created_by'      => (int) $actorId,
            'datecreated'     => date('Y-m-d H:i:s'),
            'lastupdated'     => date('Y-m-d H:i:s'),
        );
        $this->db->insert($this->kbTable(), $row);
        $id = $this->db->insert_id();
        $this->kbSnapshot($id, 'created', $actorId);
        $this->audit(null, 'config_change', 'KB entry #' . $id . ' created: ' . $row['title'], array('kb_id' => $id), $actorId);
        return $id;
    }

    public function kbUpdate($id, $data, $actorId = 0)
    {
        $cur = $this->kbGet($id);
        if (!$cur) {
            return false;
        }
        $row = array(
            'title'      => trim((string) (isset($data['title']) ? $data['title'] : $cur->title)),
            'category'   => isset($data['category']) ? $data['category'] : $cur->category,
            'content'    => isset($data['content']) ? $data['content'] : $cur->content,
            'keywords'   => isset($data['keywords']) ? $data['keywords'] : $cur->keywords,
            'scope'      => isset($data['scope']) && $data['scope'] !== '' ? $data['scope'] : 'all',
            'permission' => isset($data['permission']) ? $data['permission'] : $cur->permission,
            'version'    => (int) $cur->version + 1,
            'indexing_status' => 'indexed',  // re-index on edit
            'lastupdated' => date('Y-m-d H:i:s'),
        );
        $this->db->where('id', (int) $id)->update($this->kbTable(), $row);
        $this->kbSnapshot($id, 'edited -> v' . $row['version'], $actorId);
        $this->audit(null, 'config_change', 'KB entry #' . $id . ' updated (v' . $row['version'] . ')', array('kb_id' => (int) $id), $actorId);
        return true;
    }

    public function kbToggleActive($id, $actorId = 0)
    {
        $cur = $this->kbGet($id);
        if (!$cur) {
            return false;
        }
        $new = (int) $cur->is_active === 1 ? 0 : 1;
        $this->db->where('id', (int) $id)->update($this->kbTable(), array('is_active' => $new));
        $this->audit(null, 'config_change', 'KB entry #' . $id . ' ' . ($new ? 'activated' : 'deactivated'), array('kb_id' => (int) $id), $actorId);
        return true;
    }

    public function kbReindex($id, $actorId = 0)
    {
        $this->db->where('id', (int) $id)->update($this->kbTable(), array('indexing_status' => 'indexed', 'lastupdated' => date('Y-m-d H:i:s')));
        $this->audit(null, 'config_change', 'KB entry #' . $id . ' re-indexed', array('kb_id' => (int) $id), $actorId);
        return true;
    }

    private function kbSnapshot($kbId, $note, $actorId)
    {
        $e = $this->kbGet($kbId);
        if (!$e) {
            return;
        }
        $this->db->insert(db_prefix() . 'payplex_ai_agent_kb_versions', array(
            'kb_id'       => (int) $kbId,
            'version'     => (int) $e->version,
            'content_json' => json_encode($e),
            'note'        => substr((string) $note, 0, 255),
            'created_by'  => (int) $actorId,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
    }

    public function kbVersions($kbId)
    {
        $this->db->where('kb_id', (int) $kbId)->order_by('id', 'DESC');
        return $this->db->get(db_prefix() . 'payplex_ai_agent_kb_versions')->result();
    }

    /** KB entries as arrays for the retrieval library. */
    public function kbEntriesArray()
    {
        $out = array();
        foreach ($this->kbList(false) as $r) {
            $out[] = (array) $r;
        }
        return $out;
    }

    /**
     * Answer a query for an agent from permitted knowledge only. Escalates (and
     * enqueues a review item) when there is no confident, permitted match.
     */
    public function knowledgeAnswer($agentId, $query, $enqueue = true, $actorId = 0)
    {
        $res = Payplex_agent_knowledge::answer($query, $this->kbEntriesArray(), (int) $agentId, 0.34);
        if (empty($res['hit']) && $enqueue) {
            $this->escalate((int) $agentId, null, 'kb_' . (isset($res['reason']) ? $res['reason'] : 'miss'),
                'Knowledge query could not be answered from permitted KB: "' . substr($query, 0, 180) . '"', $actorId);
        }
        return $res;
    }

    /* ================= M2: Escalation / review queue ================= */

    public function escalate($agentId, $runId, $reason, $detail = '', $actorId = 0)
    {
        $this->db->insert(db_prefix() . 'payplex_ai_agent_escalations', array(
            'agent_id'    => (int) $agentId,
            'run_id'      => $runId ? (int) $runId : null,
            'reason'      => substr((string) $reason, 0, 100),
            'detail'      => substr((string) $detail, 0, 1000),
            'status'      => 'open',
            'datecreated' => date('Y-m-d H:i:s'),
        ));
        $eid = $this->db->insert_id();
        $this->audit((int) $agentId, 'human_intervention', 'Escalated to review queue (#' . $eid . '): ' . $reason, array('escalation_id' => $eid), $actorId);
        return $eid;
    }

    public function escalations($status = null, $limit = 200)
    {
        if ($status) {
            $this->db->where('status', $status);
        }
        $this->db->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get(db_prefix() . 'payplex_ai_agent_escalations')->result();
    }

    public function escalationResolve($id, $decision, $note = '', $actorId = 0)
    {
        $allowed = array('approved', 'rejected', 'resolved');
        if (!in_array($decision, $allowed, true)) {
            return false;
        }
        $esc = $this->db->where('id', (int) $id)->get(db_prefix() . 'payplex_ai_agent_escalations')->row();
        if (!$esc || $esc->status !== 'open') {
            return false;
        }
        $this->db->where('id', (int) $id)->update(db_prefix() . 'payplex_ai_agent_escalations', array(
            'status'          => $decision,
            'resolved_by'     => (int) $actorId,
            'resolution_note' => substr((string) $note, 0, 500),
            'resolved_at'     => date('Y-m-d H:i:s'),
        ));
        $this->audit((int) $esc->agent_id, 'human_intervention', 'Review item #' . (int) $id . ' ' . $decision . ($note ? ': ' . $note : ''), array('escalation_id' => (int) $id), $actorId);
        return true;
    }

    public function openEscalationCount()
    {
        return (int) $this->db->where('status', 'open')->count_all_results(db_prefix() . 'payplex_ai_agent_escalations');
    }

    /* ================= M2: Cost dashboard + auto-pause ================= */

    public function costByAgent()
    {
        $p = db_prefix();
        return $this->db->query('SELECT r.agent_id, a.name, a.mode, a.status, a.token_limit, a.daily_budget, a.monthly_budget,
                COUNT(*) AS runs, COALESCE(SUM(r.tokens),0) AS tokens, COALESCE(SUM(r.cost),0) AS cost,
                COALESCE(SUM(r.escalations),0) AS escalations
            FROM ' . $p . 'payplex_ai_agent_runs r
            JOIN ' . $p . 'payplex_ai_agents a ON a.id = r.agent_id
            GROUP BY r.agent_id, a.name, a.mode, a.status, a.token_limit, a.daily_budget, a.monthly_budget
            ORDER BY cost DESC')->result();
    }

    public function costByModel()
    {
        return $this->db->query('SELECT model, COUNT(*) AS runs, COALESCE(SUM(tokens),0) AS tokens, COALESCE(SUM(cost),0) AS cost
            FROM ' . db_prefix() . 'payplex_ai_agent_runs GROUP BY model ORDER BY cost DESC')->result();
    }

    /** Month-to-date tokens + cost for one agent. */
    public function agentMonthUsage($agentId)
    {
        $p = db_prefix();
        $start = date('Y-m-01 00:00:00');
        $row = $this->db->query('SELECT COALESCE(SUM(tokens),0) AS tokens, COALESCE(SUM(cost),0) AS cost
            FROM ' . $p . 'payplex_ai_agent_runs WHERE agent_id = ? AND datecreated >= ?',
            array((int) $agentId, $start))->row();
        return $row;
    }

    /**
     * Enforce hard budget/token limits: any active/scheduled agent whose
     * month-to-date usage has breached its configured limit is auto-paused and
     * an escalation is queued. Returns the number of agents paused.
     */
    public function enforceBudgets($actorId = 0)
    {
        $paused = 0;
        $this->db->where_in('status', array('active', 'scheduled'));
        $agents = $this->db->get($this->table())->result();
        foreach ($agents as $a) {
            $u = $this->agentMonthUsage((int) $a->id);
            $status = Payplex_agent_safety::budgetStatus(array(
                'spent'       => (float) $u->cost,
                'budget'      => (float) $a->monthly_budget,
                'tokens_used' => (float) $u->tokens,
                'token_limit' => (float) $a->token_limit,
            ));
            if (!empty($status['over'])) {
                $this->db->where('id', (int) $a->id)->update($this->table(), array('status' => 'paused', 'lastupdated' => date('Y-m-d H:i:s')));
                $this->audit((int) $a->id, 'budget', 'Auto-paused: budget/token hard limit exceeded (usage ' . round($status['pct'] * 100) . '%)', array(), $actorId);
                $this->escalate((int) $a->id, null, 'budget_exceeded', 'Agent auto-paused after breaching its hard limit (' . round($status['pct'] * 100) . '% of limit).', $actorId);
                $paused++;
            }
        }
        return $paused;
    }

    /** Per-agent budget status list for the dashboard (uses month-to-date usage). */
    public function budgetStatuses()
    {
        $out = array();
        foreach ($this->get(null, false) as $a) {
            $u = $this->agentMonthUsage((int) $a->id);
            $st = Payplex_agent_safety::budgetStatus(array(
                'spent'       => (float) $u->cost,
                'budget'      => (float) $a->monthly_budget,
                'tokens_used' => (float) $u->tokens,
                'token_limit' => (float) $a->token_limit,
            ));
            $out[] = array('agent' => $a, 'usage' => $u, 'status' => $st);
        }
        return $out;
    }

    /* ================= M3: AI Calling bridge ================= */

    /** Is the separately-owned Payplex AI Calling module physically present? */
    public function aiCallingModulePresent()
    {
        return is_file(FCPATH . 'modules/payplex_ai_calling/payplex_ai_calling.php');
    }

    /**
     * Attempt a call for an agent against a lead. In sandbox this SIMULATES.
     * A real call is only ever attempted when every safety gate passes AND the
     * global allow_real_calls master switch is ON (default OFF) — and even then
     * this build records the intent rather than dialing, because real calls
     * require written production approval. Always logs to the call log + audit.
     */
    public function runCallingAgent($agentId, array $lead, $actorId = 0, $actionApproved = false)
    {
        $agent = $this->get((int) $agentId);
        if (!$agent) {
            return array('ok' => false, 'error' => 'not_found');
        }

        $ctx = array(
            'mode'              => $agent->mode,
            'approved_by'       => (int) $agent->approved_by,
            'action_approved'   => (bool) $actionApproved,
            'allow_real_global' => (int) $this->getSetting('allow_real_calls', 0) === 1,
            'global_kill'       => (int) $this->getSetting('global_kill_switch', 0) === 1,
            'agent_kill'        => (int) $agent->agent_kill === 1,
        );
        $decision = Payplex_agent_calling::decide($ctx);

        $outcome = null; $duration = 0; $cost = 0.0; $transcript = array();
        if ($decision['action'] === 'simulate') {
            $sim = Payplex_agent_calling::simulate($lead, isset($lead['script']) ? $lead['script'] : '');
            $outcome = $sim['outcome']; $duration = $sim['duration']; $cost = $sim['cost']; $transcript = $sim['transcript'];
        } elseif ($decision['action'] === 'place_real') {
            // All gates passed. This build still does NOT dial automatically;
            // it records the approved intent for a human/operator to execute,
            // honouring the "real calls need written approval" rule.
            $outcome = 'approved_pending_dispatch';
            $transcript[] = array('type' => 'gate', 'message' => 'All safety gates passed; real dispatch is queued for operator execution (not auto-dialed).', 'state' => 'ok');
            $this->escalate((int) $agentId, null, 'real_call_dispatch', 'A production, fully-approved call is queued for operator dispatch to lead #' . (isset($lead['id']) ? (int) $lead['id'] : 0) . '.', $actorId);
        } else { // blocked
            $outcome = 'blocked';
            $transcript[] = array('type' => 'gate', 'message' => 'Call blocked: ' . $decision['reason'], 'state' => 'blocked');
            $this->escalate((int) $agentId, null, 'call_' . $decision['reason'], 'A call was blocked by the safety gate (' . $decision['reason'] . ') and needs human review.', $actorId);
        }

        $this->db->insert(db_prefix() . 'payplex_ai_agent_calls', array(
            'agent_id'        => (int) $agentId,
            'lead_id'         => isset($lead['id']) ? (int) $lead['id'] : null,
            'mode'            => $agent->mode,
            'decision'        => $decision['action'],
            'reason'          => $decision['reason'],
            'outcome'         => $outcome,
            'duration'        => (int) $duration,
            'cost'            => $cost,
            'transcript_json' => json_encode($transcript),
            'triggered_by'    => (int) $actorId,
            'datecreated'     => date('Y-m-d H:i:s'),
        ));
        $callId = $this->db->insert_id();
        $this->audit((int) $agentId, 'tool_call', 'AI call #' . $callId . ' decision=' . $decision['action'] . ' (' . $decision['reason'] . '), outcome=' . $outcome, array('call_id' => $callId), $actorId);

        return array('ok' => true, 'call_id' => $callId, 'decision' => $decision['action'], 'reason' => $decision['reason'], 'outcome' => $outcome, 'cost' => $cost, 'transcript' => $transcript);
    }

    public function calls($limit = 200)
    {
        $this->db->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get(db_prefix() . 'payplex_ai_agent_calls')->result();
    }

    public function callSummary()
    {
        return $this->db->query('SELECT decision, COUNT(*) AS n, COALESCE(SUM(cost),0) AS cost
            FROM ' . db_prefix() . 'payplex_ai_agent_calls GROUP BY decision')->result();
    }

    /* ================= M3: Lead pipeline ================= */

    /** Read a lead into the pipeline's expected shape. */
    public function leadForPipeline($leadId)
    {
        $p = db_prefix();
        $row = $this->db->query('SELECT l.id, l.name, l.email, l.phonenumber AS phone, l.company, l.assigned,
                lsrc.name AS source
            FROM ' . $p . 'leads l LEFT JOIN ' . $p . 'leads_sources lsrc ON lsrc.id = l.source
            WHERE l.id = ?', array((int) $leadId))->row();
        return $row ? (array) $row : null;
    }

    /** A small set of existing leads that could be duplicates of this one. */
    private function dedupeCandidates(array $lead)
    {
        $p = db_prefix();
        $email = isset($lead['email']) ? trim((string) $lead['email']) : '';
        $phone = preg_replace('/[^0-9]/', '', (string) (isset($lead['phone']) ? $lead['phone'] : ''));
        $selfId = isset($lead['id']) ? (int) $lead['id'] : 0;

        $this->db->select('id, email, phonenumber AS phone')->from($p . 'leads')->where('id !=', $selfId);
        $this->db->group_start();
        $has = false;
        if ($email !== '') { $this->db->where('email', $email); $has = true; }
        if ($phone !== '') { if ($has) { $this->db->or_where('phonenumber', $phone); } else { $this->db->where('phonenumber', $phone); } $has = true; }
        $this->db->group_end();
        if (!$has) { return array(); }
        $this->db->limit(20);
        $rows = $this->db->get()->result_array();
        return $rows;
    }

    /** Staff workloads (assigned open lead counts) for assignment suggestion. */
    private function staffWorkloads()
    {
        $p = db_prefix();
        $rows = $this->db->query('SELECT s.staffid AS id,
                (SELECT COUNT(*) FROM ' . $p . 'leads l WHERE l.assigned = s.staffid) AS `load`
            FROM ' . $p . 'staff s WHERE s.active = 1')->result_array();
        foreach ($rows as &$r) {
            $r['available'] = true;
            $r['territory'] = '';
        }
        return $rows;
    }

    /**
     * Run the lead pipeline in SANDBOX for a lead. Produces recommendations only
     * (validate/dedupe/score/assign/follow-up), logs a pipeline event and an
     * escalation if the lead is invalid or a duplicate. NEVER mutates the lead.
     */
    public function runPipelineForLead($leadId, $eventType = 'manual', $actorId = 0)
    {
        $lead = $this->leadForPipeline((int) $leadId);
        if (!$lead) {
            return array('ok' => false, 'error' => 'lead_not_found');
        }
        $config = array(
            'existing'       => $this->dedupeCandidates($lead),
            'staff'          => $this->staffWorkloads(),
            'source_quality' => array(),
        );
        $res = Payplex_agent_pipeline::run($lead, $config);

        $this->db->insert(db_prefix() . 'payplex_ai_agent_pipeline_events', array(
            'agent_id'    => null,
            'lead_id'     => (int) $leadId,
            'event_type'  => $eventType,
            'score'       => (int) $res['score'],
            'valid'       => $res['validation']['valid'] ? 1 : 0,
            'duplicates'  => empty($res['duplicates']) ? null : implode(',', $res['duplicates']),
            'assign_staff' => $res['assignment']['staff_id'] !== null ? (int) $res['assignment']['staff_id'] : null,
            'escalated'   => $res['escalate'] ? 1 : 0,
            'result_json' => json_encode($res),
            'mode'        => 'sandbox',
            'datecreated' => date('Y-m-d H:i:s'),
        ));
        $eid = $this->db->insert_id();

        if ($res['escalate']) {
            $why = !$res['validation']['valid'] ? 'invalid_lead (' . implode(',', $res['validation']['issues']) . ')' : 'duplicate_of #' . implode(',#', $res['duplicates']);
            $this->escalate(null, null, 'pipeline_' . (!$res['validation']['valid'] ? 'invalid' : 'duplicate'),
                'Lead #' . (int) $leadId . ' pipeline flagged: ' . $why . ' — recommendation only, no changes made.', $actorId);
        }
        $this->audit(null, 'trigger', 'Pipeline (sandbox) ran for lead #' . (int) $leadId . ' [' . $eventType . '] score=' . $res['score'] . ($res['escalate'] ? ' — escalated' : ''), array('event_id' => $eid), $actorId);

        $res['ok'] = true;
        $res['event_id'] = $eid;
        return $res;
    }

    public function pipelineEvents($limit = 200)
    {
        $this->db->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get(db_prefix() . 'payplex_ai_agent_pipeline_events')->result();
    }

    /* ==================== M5: Chairman Command Centre ==================== */

    private function decisionsTable() { return db_prefix() . 'payplex_ai_agent_decisions'; }
    private function matrixTable()    { return db_prefix() . 'payplex_ai_agent_approval_matrix'; }

    /** Configurable approval matrix as an action=>row map. */
    public function approvalMatrix()
    {
        if (!$this->db->table_exists($this->matrixTable())) { return array(); }
        $rows = $this->db->order_by('tier', 'DESC')->get($this->matrixTable())->result();
        return $rows;
    }

    /** action => tier map, for the classifier's custom_rules. */
    public function matrixRules()
    {
        $out = array('rules' => array(), 'limits' => array());
        foreach ($this->approvalMatrix() as $r) {
            $out['rules'][$r->action]  = $r->tier;
            $out['limits'][$r->action] = (float) $r->manager_limit;
        }
        return $out;
    }

    public function saveMatrixRow($action, $tier, $managerLimit = 0, $actorId = 0)
    {
        $action = strtolower(trim((string) $action));
        if ($action === '') { return false; }
        $tier = in_array($tier, array('auto', 'manager', 'chairman'), true) ? $tier : 'chairman';
        $existing = $this->db->where('action', $action)->get($this->matrixTable())->row();
        if ($existing) {
            $this->db->where('id', (int) $existing->id)->update($this->matrixTable(), array('tier' => $tier, 'manager_limit' => (float) $managerLimit));
        } else {
            $this->db->insert($this->matrixTable(), array('action' => $action, 'tier' => $tier, 'manager_limit' => (float) $managerLimit, 'note' => 'custom', 'datecreated' => date('Y-m-d H:i:s')));
        }
        $this->audit(null, 'config_change', 'Approval matrix set: ' . $action . ' -> ' . $tier, array('manager_limit' => (float) $managerLimit), $actorId);
        return true;
    }

    /** Classify an action to its required tier using the configured matrix. */
    public function classifyAction($actionKey, $amount = null, $reversible = null)
    {
        $rules = $this->matrixRules();
        $ctx = array('custom_rules' => $rules['rules']);
        if ($amount !== null)     { $ctx['amount'] = (float) $amount; }
        if ($reversible !== null) { $ctx['reversible'] = (bool) $reversible; }
        if (isset($rules['limits'][$actionKey]) && $rules['limits'][$actionKey] > 0) {
            $ctx['manager_limit'] = $rules['limits'][$actionKey];
        }
        return Payplex_agent_approval_matrix::classify($actionKey, $ctx);
    }

    /**
     * Create a decision packet (as a draft). $data is raw packet fields.
     * The required tier is computed from the action + amount, never trusted from input.
     */
    public function createDecision($data, $actorId = 0)
    {
        $actionKey = isset($data['action_key']) ? $data['action_key'] : (isset($data['recommended_action']) ? $data['recommended_action'] : '');
        $amount    = isset($data['amount']) && $data['amount'] !== '' ? (float) $data['amount'] : null;
        $rev       = isset($data['reversibility']) ? (stripos((string) $data['reversibility'], 'irrevers') === false) : null;
        $cls       = $this->classifyAction($actionKey, $amount, $rev);

        $row = array(
            'title'               => substr((string) (isset($data['title']) ? $data['title'] : ''), 0, 191),
            'requesting_agent_id' => isset($data['requesting_agent_id']) && $data['requesting_agent_id'] !== '' ? (int) $data['requesting_agent_id'] : null,
            'company'             => substr((string) (isset($data['company']) ? $data['company'] : ''), 0, 100),
            'objective'           => (string) (isset($data['objective']) ? $data['objective'] : ''),
            'recommended_action'  => substr((string) (isset($data['recommended_action']) ? $data['recommended_action'] : ''), 0, 150),
            'action_key'          => substr((string) $actionKey, 0, 100),
            'reason'              => (string) (isset($data['reason']) ? $data['reason'] : ''),
            'evidence'            => (string) (isset($data['evidence']) ? $data['evidence'] : ''),
            'source_links'        => (string) (isset($data['source_links']) ? $data['source_links'] : ''),
            'assumptions'         => (string) (isset($data['assumptions']) ? $data['assumptions'] : ''),
            'confidence'          => isset($data['confidence']) && $data['confidence'] !== '' ? (float) $data['confidence'] : null,
            'alternatives'        => (string) (isset($data['alternatives']) ? $data['alternatives'] : ''),
            'financial_impact'    => substr((string) (isset($data['financial_impact']) ? $data['financial_impact'] : ''), 0, 255),
            'expected_revenue'    => isset($data['expected_revenue']) && $data['expected_revenue'] !== '' ? (float) $data['expected_revenue'] : null,
            'expected_cost'       => isset($data['expected_cost']) && $data['expected_cost'] !== '' ? (float) $data['expected_cost'] : null,
            'roi'                 => substr((string) (isset($data['roi']) ? $data['roi'] : ''), 0, 100),
            'amount'              => $amount,
            'legal_impact'        => (string) (isset($data['legal_impact']) ? $data['legal_impact'] : ''),
            'security_impact'     => (string) (isset($data['security_impact']) ? $data['security_impact'] : ''),
            'people_impact'       => (string) (isset($data['people_impact']) ? $data['people_impact'] : ''),
            'risk_rating'         => substr((string) (isset($data['risk_rating']) ? $data['risk_rating'] : ''), 0, 20),
            'reversibility'       => substr((string) (isset($data['reversibility']) ? $data['reversibility'] : ''), 0, 50),
            'rollback_method'     => (string) (isset($data['rollback_method']) ? $data['rollback_method'] : ''),
            'execution_owner'     => substr((string) (isset($data['execution_owner']) ? $data['execution_owner'] : ''), 0, 150),
            'deadline'            => !empty($data['deadline']) ? $data['deadline'] : null,
            'other_opinions'      => (string) (isset($data['other_opinions']) ? $data['other_opinions'] : ''),
            'audit_verification'  => (string) (isset($data['audit_verification']) ? $data['audit_verification'] : ''),
            'required_tier'       => $cls['tier'],
            'tier_reason'         => $cls['reason'],
            'status'              => Payplex_agent_decision::DRAFT,
            'created_by'          => (int) $actorId,
            'datecreated'         => date('Y-m-d H:i:s'),
            'lastupdated'         => date('Y-m-d H:i:s'),
        );
        $this->db->insert($this->decisionsTable(), $row);
        $id = $this->db->insert_id();
        $this->audit($row['requesting_agent_id'], 'trigger', 'Decision packet #' . $id . ' created: ' . $row['title'] . ' [tier=' . $cls['tier'] . ']', array('decision_id' => $id, 'tier' => $cls['tier']), $actorId);
        return $id;
    }

    public function decisions($status = null, $limit = 200)
    {
        if ($status) { $this->db->where('status', $status); }
        $this->db->order_by('id', 'DESC')->limit((int) $limit);
        return $this->db->get($this->decisionsTable())->result();
    }

    public function decision($id)
    {
        return $this->db->where('id', (int) $id)->get($this->decisionsTable())->row();
    }

    /** Packet as an array for the completeness check. */
    private function decisionArray($d)
    {
        return is_object($d) ? (array) $d : (array) $d;
    }

    /**
     * Apply a governance transition to a decision. Enforces maker!=approver,
     * completeness (no blind approvals) and tier authority. Returns array.
     */
    public function decideOn($id, $action, $actorId, $actorTier, $note = '', $isAdmin = false)
    {
        $d = $this->decision($id);
        if (!$d) { return array('ok' => false, 'error' => 'not_found'); }
        $packet = $this->decisionArray($d);
        $complete = Payplex_agent_decision::completeness($packet);
        $ctx = array(
            'actor_id'       => (int) $actorId,
            'actor_tier'     => $actorTier,
            'complete'       => $complete['ok'],
            'is_admin_override' => (bool) $isAdmin,
            'delegate_limit' => (float) $this->getSetting('delegate_limit', 0),
        );
        $res = Payplex_agent_decision::transition($action, $packet, $ctx);
        if (empty($res['ok'])) {
            $this->audit($d->requesting_agent_id, 'error', 'Decision #' . $id . ' "' . $action . '" rejected: ' . $res['error'], array('missing' => $complete['missing']), $actorId);
            $res['missing'] = $complete['missing'];
            return $res;
        }
        $upd = array('status' => $res['status'], 'lastupdated' => date('Y-m-d H:i:s'));
        if (in_array($res['status'], array('approved', 'rejected', 'returned', 'delegated'), true)) {
            $upd['decided_by'] = (int) $actorId;
            $upd['decision_note'] = substr((string) $note, 0, 1000);
            $upd['decided_at'] = date('Y-m-d H:i:s');
        }
        $this->db->where('id', (int) $id)->update($this->decisionsTable(), $upd);
        $this->audit($d->requesting_agent_id, 'human_intervention', 'Decision #' . $id . ' -> ' . $res['status'] . ' by staff #' . (int) $actorId, array('action' => $action, 'note' => $note), $actorId);
        return $res;
    }

    /** Chairman Command Centre summary numbers. */
    public function commandCentreSummary()
    {
        $t = $this->decisionsTable();
        $out = array(
            'pending'   => 0, 'approved' => 0, 'rejected' => 0, 'returned' => 0,
            'delegated' => 0, 'chairman_pending' => 0, 'by_company' => array(), 'recent' => array(),
        );
        if (!$this->db->table_exists($t)) { return $out; }
        $out['pending']   = (int) $this->db->where('status', 'submitted')->count_all_results($t);
        $out['approved']  = (int) $this->db->where('status', 'approved')->count_all_results($t);
        $out['rejected']  = (int) $this->db->where('status', 'rejected')->count_all_results($t);
        $out['returned']  = (int) $this->db->where('status', 'returned')->count_all_results($t);
        $out['delegated'] = (int) $this->db->where('status', 'delegated')->count_all_results($t);
        $this->db->where('status', 'submitted')->where('required_tier', 'chairman');
        $out['chairman_pending'] = (int) $this->db->count_all_results($t);
        $rows = $this->db->select('company, COUNT(*) AS n')->where('status', 'submitted')->group_by('company')->get($t)->result();
        foreach ($rows as $r) { $out['by_company'][$r->company ? $r->company : 'Unassigned'] = (int) $r->n; }
        $out['recent'] = $this->db->order_by('id', 'DESC')->limit(8)->get($t)->result();
        return $out;
    }

    /* ==================== M6: Executive Council ==================== */

    private function councilTable()        { return db_prefix() . 'payplex_ai_agent_councils'; }
    private function councilReviewsTable() { return db_prefix() . 'payplex_ai_agent_council_reviews'; }

    /** The council session for a decision, or null. */
    public function councilFor($decisionId)
    {
        return $this->db->where('decision_id', (int) $decisionId)->get($this->councilTable())->row();
    }

    /** Convene (create) a council session for a decision if not already open. */
    public function conveneCouncil($decisionId, $actorId = 0)
    {
        $decisionId = (int) $decisionId;
        if (!$this->decision($decisionId)) { return false; }
        $existing = $this->councilFor($decisionId);
        if ($existing) { return (int) $existing->id; }
        $this->db->insert($this->councilTable(), array(
            'decision_id' => $decisionId,
            'status'      => 'open',
            'conflict'    => 0,
            'convened_by' => (int) $actorId,
            'datecreated' => date('Y-m-d H:i:s'),
        ));
        $id = $this->db->insert_id();
        $this->audit(null, 'trigger', 'Executive Council convened for decision #' . $decisionId, array('council_id' => $id), $actorId);
        return $id;
    }

    public function councilReviews($decisionId)
    {
        return $this->db->where('decision_id', (int) $decisionId)->order_by('id', 'ASC')->get($this->councilReviewsTable())->result();
    }

    /** The reviewer roles still awaiting a submission for this decision. */
    public function councilMissingRoles($decisionId)
    {
        $done = array();
        foreach ($this->councilReviews($decisionId) as $r) { $done[] = $r->reviewer_role; }
        $missing = array();
        foreach (Payplex_agent_council::reviewerRoles() as $role) {
            if (!in_array($role, $done, true)) { $missing[] = $role; }
        }
        return $missing;
    }

    /**
     * Submit / update one reviewer's council review. Stance + confidence + comments.
     */
    public function submitCouncilReview($decisionId, $role, $stance, $confidence, $comments, $impact = '', $reviewerAgentId = 0, $actorId = 0)
    {
        $decisionId = (int) $decisionId;
        $council = $this->councilFor($decisionId);
        if (!$council) { return false; }
        if (!in_array($role, Payplex_agent_council::reviewerRoles(), true)) { return false; }
        if (!in_array($stance, Payplex_agent_council::validStances(), true)) { $stance = Payplex_agent_council::ABSTAIN; }
        $conf = $confidence === '' || $confidence === null ? null : max(0, min(1, (float) $confidence));
        $isDissent = in_array($stance, array(Payplex_agent_council::OPPOSE, Payplex_agent_council::CONCERNS), true) ? 1 : 0;

        $row = array(
            'council_id'        => (int) $council->id,
            'decision_id'       => $decisionId,
            'reviewer_role'     => $role,
            'reviewer_agent_id' => (int) $reviewerAgentId ?: null,
            'stance'            => $stance,
            'confidence'        => $conf,
            'dimension_impact'  => (string) $impact,
            'comments'          => (string) $comments,
            'is_dissent'        => $isDissent,
            'actor_id'          => (int) $actorId,
            'datecreated'       => date('Y-m-d H:i:s'),
        );
        $existing = $this->db->where('council_id', (int) $council->id)->where('reviewer_role', $role)->get($this->councilReviewsTable())->row();
        if ($existing) {
            unset($row['datecreated']);
            $this->db->where('id', (int) $existing->id)->update($this->councilReviewsTable(), $row);
        } else {
            $this->db->insert($this->councilReviewsTable(), $row);
        }
        $this->audit(null, 'human_intervention', 'Council review (' . $role . ' -> ' . $stance . ') on decision #' . $decisionId, array('stance' => $stance), $actorId);
        return true;
    }

    /** Build the reviews array the pure consolidator expects. */
    private function councilReviewInputs($decisionId)
    {
        $out = array();
        foreach ($this->councilReviews($decisionId) as $r) {
            $out[] = array(
                'role'       => $r->reviewer_role,
                'stance'     => $r->stance,
                'confidence' => $r->confidence,
                'comments'   => $r->comments,
            );
        }
        return $out;
    }

    /** Live (unsaved) consolidation preview. */
    public function councilPreview($decisionId)
    {
        return Payplex_agent_council::consolidate($this->councilReviewInputs($decisionId));
    }

    /**
     * Group CEO consolidation: run the pure consolidator, persist the brief and
     * verdict on the council, and record it on the decision packet. Preserves
     * dissent; never forces consensus. Chairman still decides.
     */
    public function consolidateCouncil($decisionId, $actorId = 0)
    {
        $decisionId = (int) $decisionId;
        $council = $this->councilFor($decisionId);
        if (!$council) { return array('ok' => false, 'error' => 'no_council'); }
        $res = Payplex_agent_council::consolidate($this->councilReviewInputs($decisionId));

        $brief = $res['summary'];
        if (!empty($res['dissents'])) {
            $parts = array();
            foreach ($res['dissents'] as $d) {
                $parts[] = $d['label'] . ' (' . $d['stance'] . ')' . ($d['comments'] !== '' ? ': ' . $d['comments'] : '');
            }
            $brief .= "\nDissent preserved — " . implode('; ', $parts);
        }

        $this->db->where('id', (int) $council->id)->update($this->councilTable(), array(
            'status'             => 'consolidated',
            'recommendation'     => $res['recommendation'],
            'conflict'           => $res['conflict'] ? 1 : 0,
            'council_confidence' => $res['council_confidence'],
            'avg_confidence'     => $res['avg_confidence'],
            'brief'              => $brief,
            'result_json'        => json_encode($res),
            'consolidated_by'    => (int) $actorId,
            'consolidated_at'    => date('Y-m-d H:i:s'),
        ));

        // Record the council outcome onto the decision packet (other agents' opinions
        // + audit verification), so the Chairman sees it inline.
        $this->db->where('id', $decisionId)->update($this->decisionsTable(), array(
            'other_opinions'     => $brief,
            'audit_verification' => 'Council: ' . $res['recommendation'] . ' | conflict=' . ($res['conflict'] ? 'yes' : 'no') . ' | council-confidence=' . ($res['council_confidence'] !== null ? round($res['council_confidence'] * 100) . '%' : 'n/a'),
            'lastupdated'        => date('Y-m-d H:i:s'),
        ));

        $this->audit(null, 'config_change', 'Council consolidated for decision #' . $decisionId . ': ' . $res['recommendation'], array('conflict' => $res['conflict']), $actorId);
        $res['ok'] = true;
        return $res;
    }

    /* ==================== M7: Multi-company + RBAC ==================== */

    private function companiesTable()    { return db_prefix() . 'payplex_ai_agent_companies'; }
    private function companyAccessTable() { return db_prefix() . 'payplex_ai_agent_company_access'; }

    public function companies($activeOnly = false)
    {
        if (!$this->db->table_exists($this->companiesTable())) { return array(); }
        if ($activeOnly) { $this->db->where('active', 1); }
        return $this->db->order_by('name', 'ASC')->get($this->companiesTable())->result();
    }

    public function companyByCode($code)
    {
        return $this->db->where('code', Payplex_agent_rbac::normalizeCode($code))->get($this->companiesTable())->row();
    }

    public function saveCompany($name, $code = '', $actorId = 0)
    {
        $name = trim((string) $name);
        if ($name === '') { return false; }
        $code = Payplex_agent_rbac::normalizeCode($code !== '' ? $code : $name);
        if ($code === '') { return false; }
        $existing = $this->companyByCode($code);
        if ($existing) {
            $this->db->where('id', (int) $existing->id)->update($this->companiesTable(), array('name' => $name));
        } else {
            $this->db->insert($this->companiesTable(), array('code' => $code, 'name' => $name, 'active' => 1, 'datecreated' => date('Y-m-d H:i:s')));
        }
        $this->audit(null, 'config_change', 'Company saved: ' . $name . ' [' . $code . ']', array(), $actorId);
        return true;
    }

    public function toggleCompany($id, $actorId = 0)
    {
        $c = $this->db->where('id', (int) $id)->get($this->companiesTable())->row();
        if (!$c) { return false; }
        $this->db->where('id', (int) $id)->update($this->companiesTable(), array('active' => $c->active ? 0 : 1));
        $this->audit(null, 'config_change', 'Company ' . $c->name . ' ' . ($c->active ? 'deactivated' : 'activated'), array(), $actorId);
        return true;
    }

    /** All access grant rows for a staff member. */
    public function companyAccessFor($staffId)
    {
        if (!$this->db->table_exists($this->companyAccessTable())) { return array(); }
        return $this->db->where('staff_id', (int) $staffId)->get($this->companyAccessTable())->result();
    }

    public function grantCompany($staffId, $code, $canCross = 0, $actorId = 0)
    {
        $code = Payplex_agent_rbac::normalizeCode($code);
        if ($code === '' || (int) $staffId <= 0) { return false; }
        $existing = $this->db->where('staff_id', (int) $staffId)->where('company_code', $code)->get($this->companyAccessTable())->row();
        if ($existing) {
            $this->db->where('id', (int) $existing->id)->update($this->companyAccessTable(), array('can_cross' => $canCross ? 1 : 0));
        } else {
            $this->db->insert($this->companyAccessTable(), array('staff_id' => (int) $staffId, 'company_code' => $code, 'can_cross' => $canCross ? 1 : 0, 'datecreated' => date('Y-m-d H:i:s')));
        }
        $this->audit(null, 'config_change', 'Company access granted: staff #' . (int) $staffId . ' -> ' . $code . ($canCross ? ' (cross)' : ''), array(), $actorId);
        return true;
    }

    public function revokeCompany($staffId, $code, $actorId = 0)
    {
        $code = Payplex_agent_rbac::normalizeCode($code);
        $this->db->where('staff_id', (int) $staffId)->where('company_code', $code)->delete($this->companyAccessTable());
        $this->audit(null, 'config_change', 'Company access revoked: staff #' . (int) $staffId . ' -> ' . $code, array(), $actorId);
        return true;
    }

    /** Does this staff hold any cross-company grant? */
    public function hasCrossCompany($staffId, $isAdmin = false)
    {
        if ($isAdmin) { return true; }
        foreach ($this->companyAccessFor($staffId) as $g) {
            if ((int) $g->can_cross === 1) { return true; }
        }
        return false;
    }

    /** Normalised company codes an actor may access (admin -> all active). */
    public function allowedCompaniesForActor($staffId, $isAdmin = false)
    {
        $allCodes = array();
        foreach ($this->companies(false) as $c) { $allCodes[] = $c->code; }
        $grants = array();
        foreach ($this->companyAccessFor($staffId) as $g) { $grants[] = $g->company_code; }
        return Payplex_agent_rbac::allowedCompanies($grants, (bool) $isAdmin, $allCodes);
    }

    /** Decisions scoped to what the actor may see (+ optional company view). */
    public function decisionsScoped($status, $staffId, $isAdmin, $companyView = '', $limit = 200)
    {
        $rows    = $this->decisions($status, $limit);
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /** Agents scoped to what the actor may see. */
    public function agentsScoped($staffId, $isAdmin, $companyView = '')
    {
        $rows    = $this->get();
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /** Command-centre summary computed only from decisions the actor may see. */
    public function commandCentreSummaryScoped($staffId, $isAdmin, $companyView = '')
    {
        $all = $this->decisionsScoped(null, $staffId, $isAdmin, $companyView, 1000);
        $out = array('pending'=>0,'approved'=>0,'rejected'=>0,'returned'=>0,'delegated'=>0,'chairman_pending'=>0,'by_company'=>array(),'recent'=>array());
        foreach ($all as $d) {
            $st = $d->status;
            if (isset($out[$st])) { $out[$st]++; }
            if ($st === 'submitted') {
                if ($d->required_tier === 'chairman') { $out['chairman_pending']++; }
                $co = $d->company ? $d->company : 'Unassigned';
                $out['by_company'][$co] = (isset($out['by_company'][$co]) ? $out['by_company'][$co] : 0) + 1;
            }
        }
        $out['recent'] = array_slice($all, 0, 8);
        return $out;
    }

    /* ================= M8: Executive Knowledge & Memory ================= */

    private function ekTable() { return db_prefix() . 'payplex_ai_agent_exec_knowledge'; }
    private function emTable() { return db_prefix() . 'payplex_ai_agent_exec_memory'; }

    /** Raw list of executive-knowledge entries, optional status/company filter. */
    public function ekList($status = null, $company = null, $limit = 500)
    {
        if (!$this->db->table_exists($this->ekTable())) { return array(); }
        if ($status !== null && $status !== '') { $this->db->where('status', $status); }
        if ($company !== null && $company !== '') { $this->db->where('company', $company); }
        return $this->db->order_by('dateupdated', 'DESC')->limit((int) $limit)->get($this->ekTable())->result();
    }

    public function ekGet($id)
    {
        if (!$this->db->table_exists($this->ekTable())) { return null; }
        return $this->db->where('id', (int) $id)->get($this->ekTable())->row();
    }

    /** Company-scoped list for an actor (admin sees all; staff only granted). */
    public function ekScoped($staffId, $isAdmin, $companyView = '', $status = null)
    {
        $rows    = $this->ekList($status);
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /**
     * Create a new executive-knowledge entry (always starts as DRAFT).
     * Returns array('ok'=>bool,'id'=>?,'errors'=>[],'warnings'=>[]).
     */
    public function ekCreate($data, $actorId)
    {
        $v = Payplex_agent_exec_knowledge::validate((array) $data);
        if (!$v['ok']) { return array('ok' => false, 'id' => null, 'errors' => $v['errors'], 'warnings' => $v['warnings']); }
        $row = $v['entry'];
        $row['status']      = Payplex_agent_exec_knowledge::DRAFT;
        $row['active']      = 1;
        $row['created_by']  = (int) $actorId;
        $row['updated_by']  = (int) $actorId;
        $row['datecreated'] = date('Y-m-d H:i:s');
        $row['dateupdated'] = date('Y-m-d H:i:s');
        $this->db->insert($this->ekTable(), $row);
        $id = $this->db->insert_id();
        $this->audit(null, 'config_change', 'Exec knowledge #' . $id . ' created: ' . $row['title'], array('ek_id' => $id), $actorId);
        return array('ok' => true, 'id' => $id, 'errors' => array(), 'warnings' => $v['warnings']);
    }

    /** Edit an entry — only while it is a draft or was returned. Editing bumps updated_by. */
    public function ekUpdate($id, $data, $actorId)
    {
        $cur = $this->ekGet((int) $id);
        if (!$cur) { return array('ok' => false, 'errors' => array('not_found')); }
        if (!in_array($cur->status, array(Payplex_agent_exec_knowledge::DRAFT, Payplex_agent_exec_knowledge::RETURNED), true)) {
            return array('ok' => false, 'errors' => array('only_draft_or_returned_editable'));
        }
        $v = Payplex_agent_exec_knowledge::validate((array) $data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']); }
        $row = $v['entry'];
        $row['updated_by']  = (int) $actorId;
        $row['dateupdated'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update($this->ekTable(), $row);
        $this->audit(null, 'config_change', 'Exec knowledge #' . (int) $id . ' updated', array('ek_id' => (int) $id), $actorId);
        return array('ok' => true, 'errors' => array(), 'warnings' => $v['warnings']);
    }

    /**
     * Governance transition (submit/approve/return/archive/reopen).
     * Enforces maker != approver: the approver may not be the entry's creator
     * or last editor.
     */
    public function ekTransition($id, $action, $actorId, $canApprove)
    {
        $cur = $this->ekGet((int) $id);
        if (!$cur) { return array('ok' => false, 'error' => 'not_found'); }
        $isMaker = ((int) $cur->created_by === (int) $actorId) || ((int) $cur->updated_by === (int) $actorId);
        $res = Payplex_agent_exec_knowledge::transition((array) $cur, $action, $isMaker, (bool) $canApprove);
        if (!$res['ok']) { return $res; }
        $upd = array('status' => $res['status'], 'dateupdated' => date('Y-m-d H:i:s'));
        if ($res['status'] === Payplex_agent_exec_knowledge::APPROVED) { $upd['approved_by'] = (int) $actorId; }
        if ($action === 'reopen') { $upd['approved_by'] = null; }
        $this->db->where('id', (int) $id)->update($this->ekTable(), $upd);
        $this->audit(null, 'config_change', 'Exec knowledge #' . (int) $id . ' ' . $action . ' -> ' . $res['status'], array('ek_id' => (int) $id), $actorId);
        return $res;
    }

    /** Approved + citable entries visible to the actor (the corpus an agent may ground on). */
    public function ekCitableForActor($staffId, $isAdmin, $companyView = '')
    {
        $rows = $this->ekScoped($staffId, $isAdmin, $companyView, Payplex_agent_exec_knowledge::APPROVED);
        return array_values(array_filter($rows, function ($r) {
            return Payplex_agent_exec_knowledge::isCitable($r);
        }));
    }

    /** Deterministic, grounded retrieval for an actor over the citable corpus. */
    public function ekRetrieve($query, $staffId, $isAdmin, $companyView = '', $opts = array())
    {
        $corpus = $this->ekCitableForActor($staffId, $isAdmin, $companyView);
        return Payplex_agent_memory::retrieve($corpus, (string) $query, (array) $opts);
    }

    /* ---- Executive Memory ---- */

    public function emScoped($staffId, $isAdmin, $companyView = '', $kind = null, $limit = 300)
    {
        if (!$this->db->table_exists($this->emTable())) { return array(); }
        if ($kind !== null && $kind !== '') { $this->db->where('kind', $kind); }
        $rows    = $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->emTable())->result();
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /** Record an executive-memory item (decision outcome, lesson, ruling, note). */
    public function emCapture($data, $actorId)
    {
        if (!$this->db->table_exists($this->emTable())) { return array('ok' => false, 'error' => 'no_table'); }
        $title = trim((string) (isset($data['title']) ? $data['title'] : ''));
        $body  = trim((string) (isset($data['body']) ? $data['body'] : ''));
        if ($title === '' || $body === '') { return array('ok' => false, 'error' => 'title_and_body_required'); }
        $kinds = array('decision_outcome', 'lesson', 'council_ruling', 'note');
        $kind  = isset($data['kind']) && in_array($data['kind'], $kinds, true) ? $data['kind'] : 'note';
        $conf  = isset($data['confidence']) && $data['confidence'] !== '' ? (float) $data['confidence'] : 0.5;
        if ($conf < 0) { $conf = 0.0; } if ($conf > 1) { $conf = 1.0; }
        $row = array(
            'company'     => strtolower(trim((string) (isset($data['company']) ? $data['company'] : ''))),
            'agent_id'    => !empty($data['agent_id']) ? (int) $data['agent_id'] : null,
            'decision_id' => !empty($data['decision_id']) ? (int) $data['decision_id'] : null,
            'kind'        => $kind,
            'title'       => substr($title, 0, 200),
            'body'        => $body,
            'tags'        => implode(',', Payplex_agent_exec_knowledge::normalizeTags(isset($data['tags']) ? $data['tags'] : '')),
            'confidence'  => round($conf, 3),
            'source_ref'  => substr((string) (isset($data['source_ref']) ? $data['source_ref'] : ''), 0, 120),
            'created_by'  => (int) $actorId,
            'datecreated' => date('Y-m-d H:i:s'),
        );
        $this->db->insert($this->emTable(), $row);
        $id = $this->db->insert_id();
        $this->audit(null, 'config_change', 'Exec memory #' . $id . ' captured (' . $kind . '): ' . $row['title'], array('em_id' => $id), $actorId);
        return array('ok' => true, 'id' => $id);
    }

    public function emForDecision($decisionId)
    {
        if (!$this->db->table_exists($this->emTable())) { return array(); }
        return $this->db->where('decision_id', (int) $decisionId)->order_by('id', 'DESC')->get($this->emTable())->result();
    }


    /* ================= M9: Goals / OKRs / KPIs + performance ================= */

    private function objTable() { return db_prefix() . 'payplex_ai_agent_objectives'; }
    private function krTable()  { return db_prefix() . 'payplex_ai_agent_keyresults'; }

    public function objList($company = null, $period = null, $status = null, $limit = 500)
    {
        if (!$this->db->table_exists($this->objTable())) { return array(); }
        if ($company !== null && $company !== '') { $this->db->where('company', $company); }
        if ($period !== null && $period !== '')   { $this->db->where('period', $period); }
        if ($status !== null && $status !== '')   { $this->db->where('status', $status); }
        return $this->db->order_by('id', 'DESC')->limit((int) $limit)->get($this->objTable())->result();
    }

    public function objGet($id)
    {
        if (!$this->db->table_exists($this->objTable())) { return null; }
        return $this->db->where('id', (int) $id)->get($this->objTable())->row();
    }

    /** Company-scoped objective list for an actor (admin sees all). */
    public function objScoped($staffId, $isAdmin, $companyView = '', $period = null, $status = null)
    {
        $rows    = $this->objList(null, $period, $status);
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    public function objKeyResults($objId)
    {
        if (!$this->db->table_exists($this->krTable())) { return array(); }
        return $this->db->where('objective_id', (int) $objId)->order_by('id', 'ASC')->get($this->krTable())->result();
    }

    /** Objective progress (0..1) computed from its key results. */
    public function objProgress($objId)
    {
        return Payplex_agent_goals::objectiveProgress($this->objKeyResults($objId));
    }

    public function objCreate($data, $actorId)
    {
        $v = Payplex_agent_goals::validateObjective((array) $data);
        if (!$v['ok']) { return array('ok' => false, 'id' => null, 'errors' => $v['errors']); }
        $row = $v['entry'];
        $row['status']       = Payplex_agent_goals::DRAFT;
        $row['owner_agent_id'] = !empty($data['owner_agent_id']) ? (int) $data['owner_agent_id'] : null;
        $row['owner_staff']  = !empty($data['owner_staff']) ? (int) $data['owner_staff'] : (int) $actorId;
        $row['created_by']   = (int) $actorId;
        $row['datecreated']  = date('Y-m-d H:i:s');
        $row['dateupdated']  = date('Y-m-d H:i:s');
        $this->db->insert($this->objTable(), $row);
        $id = $this->db->insert_id();
        $this->audit(null, 'config_change', 'Objective #' . $id . ' created: ' . $row['title'], array('objective_id' => $id), $actorId);
        return array('ok' => true, 'id' => $id, 'errors' => array());
    }

    public function objTransition($id, $action, $actorId)
    {
        $cur = $this->objGet((int) $id);
        if (!$cur) { return array('ok' => false, 'error' => 'not_found'); }
        $new = Payplex_agent_goals::transitionStatus($cur->status, $action);
        if ($new === $cur->status) { return array('ok' => false, 'error' => 'invalid_transition', 'status' => $cur->status); }
        $this->db->where('id', (int) $id)->update($this->objTable(), array('status' => $new, 'dateupdated' => date('Y-m-d H:i:s')));
        $this->audit(null, 'config_change', 'Objective #' . (int) $id . ' ' . $action . ' -> ' . $new, array('objective_id' => (int) $id), $actorId);
        return array('ok' => true, 'status' => $new);
    }

    public function krAdd($objId, $data, $actorId)
    {
        if (!$this->objGet((int) $objId)) { return array('ok' => false, 'errors' => array('objective_not_found')); }
        $v = Payplex_agent_goals::validateKeyResult((array) $data);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors']); }
        $row = $v['entry'];
        $row['objective_id'] = (int) $objId;
        $row['created_by']   = (int) $actorId;
        $row['datecreated']  = date('Y-m-d H:i:s');
        $row['dateupdated']  = date('Y-m-d H:i:s');
        $this->db->insert($this->krTable(), $row);
        $id = $this->db->insert_id();
        $this->audit(null, 'config_change', 'Key result #' . $id . ' added to objective #' . (int) $objId, array('kr_id' => $id), $actorId);
        return array('ok' => true, 'id' => $id, 'errors' => array());
    }

    /** Update the current measured value of a key result (progress tracking). */
    public function krUpdateCurrent($krId, $current, $actorId)
    {
        if (!$this->db->table_exists($this->krTable())) { return array('ok' => false, 'error' => 'no_table'); }
        $kr = $this->db->where('id', (int) $krId)->get($this->krTable())->row();
        if (!$kr) { return array('ok' => false, 'error' => 'not_found'); }
        if (!is_numeric($current)) { return array('ok' => false, 'error' => 'current_not_numeric'); }
        $this->db->where('id', (int) $krId)->update($this->krTable(), array('current' => (float) $current, 'dateupdated' => date('Y-m-d H:i:s')));
        $this->audit(null, 'config_change', 'Key result #' . (int) $krId . ' current -> ' . (float) $current, array('kr_id' => (int) $krId), $actorId);
        return array('ok' => true);
    }

    /* ---- Agent performance (live aggregation, deterministic scoring) ---- */

    /**
     * Per-agent performance scorecards, scoped by company. Aggregates real
     * activity from decision packets (by requesting agent) and council reviews
     * (by reviewer agent), then scores each agent deterministically.
     */
    public function agentPerformance($staffId, $isAdmin, $companyView = '')
    {
        $agents = $this->get();
        if (empty($agents)) { return array(); }

        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $view    = ($companyView !== '' && $companyView !== 'group') ? Payplex_agent_rbac::normalizeCode($companyView) : '';

        // company predicate for a decision row
        $companyOk = function ($co) use ($allowed, $isAdmin, $cross, $view) {
            if ($view !== '' && Payplex_agent_rbac::normalizeCode((string) $co) !== $view) { return false; }
            return Payplex_agent_rbac::canAccess((string) $co, $allowed, $isAdmin, $cross);
        };

        // Aggregate decisions by requesting agent
        $dec = array();
        if ($this->db->table_exists(db_prefix() . 'payplex_ai_agent_decisions')) {
            $rows = $this->db->select('requesting_agent_id, company, status, confidence')
                ->where('status !=', 'draft')
                ->get(db_prefix() . 'payplex_ai_agent_decisions')->result();
            foreach ($rows as $r) {
                $aid = (int) $r->requesting_agent_id;
                if ($aid <= 0 || !$companyOk($r->company)) { continue; }
                if (!isset($dec[$aid])) { $dec[$aid] = array('submitted'=>0,'approved'=>0,'rejected'=>0,'returned'=>0,'conf_sum'=>0.0,'conf_n'=>0); }
                $dec[$aid]['submitted']++;
                if ($r->status === 'approved' || $r->status === 'executed') { $dec[$aid]['approved']++; }
                elseif ($r->status === 'rejected') { $dec[$aid]['rejected']++; }
                elseif ($r->status === 'returned') { $dec[$aid]['returned']++; }
                if ($r->confidence !== null && $r->confidence !== '') { $dec[$aid]['conf_sum'] += (float) $r->confidence; $dec[$aid]['conf_n']++; }
            }
        }

        // Aggregate council reviews by reviewer agent
        $rev = array();
        if ($this->db->table_exists(db_prefix() . 'payplex_ai_agent_council_reviews')) {
            $rows = $this->db->select('reviewer_agent_id')
                ->where('reviewer_agent_id >', 0)
                ->get(db_prefix() . 'payplex_ai_agent_council_reviews')->result();
            foreach ($rows as $r) { $aid = (int) $r->reviewer_agent_id; $rev[$aid] = (isset($rev[$aid]) ? $rev[$aid] : 0) + 1; }
        }

        // M11: council-vote participation counts toward the same contribution signal
        if ($this->db->table_exists(db_prefix() . 'payplex_ai_agent_motion_votes')) {
            $rows = $this->db->select('voter_agent_id')
                ->where('voter_agent_id >', 0)
                ->get(db_prefix() . 'payplex_ai_agent_motion_votes')->result();
            foreach ($rows as $r) { $aid = (int) $r->voter_agent_id; $rev[$aid] = (isset($rev[$aid]) ? $rev[$aid] : 0) + 1; }
        }

        $out = array();
        foreach ($agents as $a) {
            $aid = (int) $a->id;
            // scope the agent itself by its own company when it has one
            $agentCo = isset($a->company) ? $a->company : '';
            if (!$companyOk($agentCo) && empty($dec[$aid]) && empty($rev[$aid])) { continue; }
            $d = isset($dec[$aid]) ? $dec[$aid] : array('submitted'=>0,'approved'=>0,'rejected'=>0,'returned'=>0,'conf_sum'=>0.0,'conf_n'=>0);
            $stats = array(
                'submitted'          => $d['submitted'],
                'approved'           => $d['approved'],
                'rejected'           => $d['rejected'],
                'returned'           => $d['returned'],
                'reviews'            => isset($rev[$aid]) ? $rev[$aid] : 0,
                'knowledge_approved' => 0,
                'avg_confidence'     => $d['conf_n'] > 0 ? round($d['conf_sum'] / $d['conf_n'], 3) : 0.0,
            );
            $card = Payplex_agent_scorecard::score($stats);
            $out[] = array(
                'agent_id'   => $aid,
                'agent_name' => isset($a->display_name) && $a->display_name ? $a->display_name : (isset($a->name) ? $a->name : ('Agent #' . $aid)),
                'company'    => $agentCo,
                'stats'      => $stats,
                'score'      => $card['score'],
                'rating'     => $card['rating'],
                'components' => $card['components'],
            );
        }
        // rank by score desc
        usort($out, function ($x, $y) { return $y['score'] <=> $x['score']; });
        return $out;
    }


    /* ================= M10: Agent-to-Agent Communication ================= */

    private function threadTable() { return db_prefix() . 'payplex_ai_agent_threads'; }
    private function msgTable()    { return db_prefix() . 'payplex_ai_agent_messages'; }

    /** An agent's company (for routing checks). */
    public function agentCompany($agentId)
    {
        $a = $this->get((int) $agentId);
        return $a && isset($a->company) ? (string) $a->company : '';
    }

    public function threadGet($id)
    {
        if (!$this->db->table_exists($this->threadTable())) { return null; }
        return $this->db->where('id', (int) $id)->get($this->threadTable())->row();
    }

    public function threadMessages($threadId)
    {
        if (!$this->db->table_exists($this->msgTable())) { return array(); }
        return $this->db->where('thread_id', (int) $threadId)->order_by('id', 'ASC')->get($this->msgTable())->result();
    }

    /** Company-scoped thread list for an actor. */
    public function threadsScoped($staffId, $isAdmin, $companyView = '', $status = null, $limit = 300)
    {
        if (!$this->db->table_exists($this->threadTable())) { return array(); }
        if ($status !== null && $status !== '') { $this->db->where('status', $status); }
        $rows    = $this->db->order_by('dateupdated', 'DESC')->limit((int) $limit)->get($this->threadTable())->result();
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /** Recent messages between two agents (for dedupe). */
    private function recentBetween($from, $to, $limit = 20)
    {
        if (!$this->db->table_exists($this->msgTable())) { return array(); }
        return $this->db->where('from_agent_id', (int) $from)->where('to_agent_id', (int) $to)
            ->order_by('id', 'DESC')->limit((int) $limit)->get($this->msgTable())->result();
    }

    private function commsCrossAllowed($actorId, $isAdmin)
    {
        return (bool) ($isAdmin || $this->hasCrossCompany($actorId, $isAdmin));
    }

    /**
     * Open a new thread with its first message. Internal only; the message is
     * validated + routed + dedupe-checked by the pure library.
     * Returns array('ok','thread_id'|null,'message_id'|null,'errors','warnings').
     */
    public function openThread($data, $actorId, $isAdmin = false)
    {
        if ((int) $this->getSetting('global_kill_switch', 0) === 1) {
            return array('ok' => false, 'errors' => array('global_kill_switch_engaged'));
        }
        $subject = trim((string) (isset($data['subject']) ? $data['subject'] : ''));
        if ($subject === '') { return array('ok' => false, 'errors' => array('subject_required')); }

        $from = (int) (isset($data['from_agent_id']) ? $data['from_agent_id'] : 0);
        $to   = (int) (isset($data['to_agent_id']) ? $data['to_agent_id'] : 0);
        $ctx  = array(
            'from_company'  => $this->agentCompany($from),
            'to_company'    => $this->agentCompany($to),
            'cross_allowed' => $this->commsCrossAllowed($actorId, $isAdmin),
        );
        $v = Payplex_agent_comms::validateMessage((array) $data, $ctx);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']); }
        if (Payplex_agent_comms::isDuplicate($v['entry'], $this->recentBetween($from, $to))) {
            return array('ok' => false, 'errors' => array('duplicate_message_suppressed'));
        }

        $now  = date('Y-m-d H:i:s');
        $kind = $v['entry']['msg_type'];
        $status = Payplex_agent_comms::threadStatusAfter(Payplex_agent_comms::OPEN, $kind);
        $this->db->insert($this->threadTable(), array(
            'subject'         => substr($subject, 0, 200),
            'company'         => $ctx['from_company'] !== '' ? $ctx['from_company'] : $ctx['to_company'],
            'kind'            => $kind,
            'status'          => $status,
            'opened_by_agent' => $from,
            'requires_human'  => $v['entry']['requires_human'],
            'created_by'      => (int) $actorId,
            'datecreated'     => $now,
            'dateupdated'     => $now,
        ));
        $tid = $this->db->insert_id();
        $mid = $this->insertMessage($tid, $v['entry'], $actorId, $now);
        $this->audit(null, 'config_change', 'Agent thread #' . $tid . ' opened (' . $kind . '): ' . $subject, array('thread_id' => $tid), $actorId);
        return array('ok' => true, 'thread_id' => $tid, 'message_id' => $mid, 'errors' => array(), 'warnings' => $v['warnings']);
    }

    /** Post a message into an existing thread. */
    public function postMessage($threadId, $data, $actorId, $isAdmin = false)
    {
        if ((int) $this->getSetting('global_kill_switch', 0) === 1) {
            return array('ok' => false, 'errors' => array('global_kill_switch_engaged'));
        }
        $t = $this->threadGet((int) $threadId);
        if (!$t) { return array('ok' => false, 'errors' => array('thread_not_found')); }
        if ($t->status === Payplex_agent_comms::CLOSED) { return array('ok' => false, 'errors' => array('thread_closed')); }

        $from = (int) (isset($data['from_agent_id']) ? $data['from_agent_id'] : 0);
        $to   = (int) (isset($data['to_agent_id']) ? $data['to_agent_id'] : 0);
        $ctx  = array(
            'from_company'  => $this->agentCompany($from),
            'to_company'    => $this->agentCompany($to),
            'cross_allowed' => $this->commsCrossAllowed($actorId, $isAdmin),
        );
        $v = Payplex_agent_comms::validateMessage((array) $data, $ctx);
        if (!$v['ok']) { return array('ok' => false, 'errors' => $v['errors'], 'warnings' => $v['warnings']); }
        if (Payplex_agent_comms::isDuplicate($v['entry'], $this->recentBetween($from, $to))) {
            return array('ok' => false, 'errors' => array('duplicate_message_suppressed'));
        }

        $now = date('Y-m-d H:i:s');
        $mid = $this->insertMessage((int) $threadId, $v['entry'], $actorId, $now);

        // advance thread status by the message type; responses re-open awaiting threads
        $event = $v['entry']['msg_type'];
        if ($event === Payplex_agent_comms::RESPONSE) { $event = 'respond'; }
        $newStatus = Payplex_agent_comms::threadStatusAfter($t->status, $event);
        $upd = array('status' => $newStatus, 'dateupdated' => $now);
        if ($v['entry']['requires_human']) { $upd['requires_human'] = 1; }
        $this->db->where('id', (int) $threadId)->update($this->threadTable(), $upd);
        $this->audit(null, 'config_change', 'Message posted to thread #' . (int) $threadId, array('thread_id' => (int) $threadId, 'message_id' => $mid), $actorId);
        return array('ok' => true, 'message_id' => $mid, 'status' => $newStatus, 'errors' => array(), 'warnings' => $v['warnings']);
    }

    private function insertMessage($threadId, $entry, $actorId, $now)
    {
        $this->db->insert($this->msgTable(), array(
            'thread_id'      => (int) $threadId,
            'from_agent_id'  => (int) $entry['from_agent_id'],
            'to_agent_id'    => (int) $entry['to_agent_id'],
            'body'           => (string) $entry['body'],
            'msg_type'       => $entry['msg_type'],
            'priority'       => $entry['priority'],
            'status'         => Payplex_agent_comms::SENT,
            'requires_human' => (int) $entry['requires_human'],
            'external_flags' => (string) $entry['external_flags'],
            'created_by'     => (int) $actorId,
            'datecreated'    => $now,
        ));
        return $this->db->insert_id();
    }

    /** Message delivery transition (read / acknowledge / respond flag). */
    public function messageEvent($msgId, $event, $actorId)
    {
        if (!$this->db->table_exists($this->msgTable())) { return array('ok' => false, 'error' => 'no_table'); }
        $m = $this->db->where('id', (int) $msgId)->get($this->msgTable())->row();
        if (!$m) { return array('ok' => false, 'error' => 'not_found'); }
        $new = Payplex_agent_comms::messageStatusAfter($m->status, $event);
        if ($new === $m->status) { return array('ok' => false, 'error' => 'invalid_transition', 'status' => $m->status); }
        $this->db->where('id', (int) $msgId)->update($this->msgTable(), array('status' => $new));
        return array('ok' => true, 'status' => $new);
    }

    /** Thread-level transition: resolve | escalate | close | reopen. */
    public function threadEvent($threadId, $event, $actorId)
    {
        $t = $this->threadGet((int) $threadId);
        if (!$t) { return array('ok' => false, 'error' => 'not_found'); }
        $new = Payplex_agent_comms::threadStatusAfter($t->status, $event);
        if ($new === $t->status) { return array('ok' => false, 'error' => 'invalid_transition', 'status' => $t->status); }
        $upd = array('status' => $new, 'dateupdated' => date('Y-m-d H:i:s'));
        if ($event === 'escalate') { $upd['requires_human'] = 1; }
        $this->db->where('id', (int) $threadId)->update($this->threadTable(), $upd);
        $this->audit(null, 'config_change', 'Thread #' . (int) $threadId . ' ' . $event . ' -> ' . $new, array('thread_id' => (int) $threadId), $actorId);
        return array('ok' => true, 'status' => $new);
    }

    /** Summary counts for the comms inbox header. */
    public function commsSummary($staffId, $isAdmin, $companyView = '')
    {
        $threads = $this->threadsScoped($staffId, $isAdmin, $companyView, null, 1000);
        $out = array('total' => count($threads), 'awaiting' => 0, 'escalated' => 0, 'needs_human' => 0);
        foreach ($threads as $t) {
            if ($t->status === Payplex_agent_comms::AWAITING) { $out['awaiting']++; }
            if ($t->status === Payplex_agent_comms::ESCALATED) { $out['escalated']++; }
            if ((int) $t->requires_human === 1) { $out['needs_human']++; }
        }
        return $out;
    }


    /* ================= M11: Executive Council Voting ================= */

    private function motionTable() { return db_prefix() . 'payplex_ai_agent_motions'; }
    private function voteTable()   { return db_prefix() . 'payplex_ai_agent_motion_votes'; }

    /**
     * The agents eligible to vote on a motion in $company. Group-level motions
     * (empty company) are open to every non-killed agent; a company motion is
     * restricted to that company's non-killed agents. Deterministic order.
     */
    public function eligibleVoters($company = '')
    {
        $agents = $this->get();
        $co = Payplex_agent_rbac::normalizeCode((string) $company);
        $out = array();
        foreach ($agents as $a) {
            /*
             * A kill switch read with a permissive default is not a kill switch.
             *
             * These were isset($a->agent_kill) ? ... : 0 — so if the column were
             * ever absent, a killed agent would read as live and keep voting.
             * That is the exact shape of the DND gate that answered "not on DND"
             * for every lead because it read a column tblleads does not have.
             *
             * The columns do exist here; the reading is what was wrong. Absence
             * now excludes the agent rather than admitting it, which is the
             * direction a safety control has to fail in.
             */
            if (!property_exists($a, 'agent_kill') || (int) $a->agent_kill === 1) { continue; }
            if (!property_exists($a, 'is_template') || (int) $a->is_template === 1) { continue; }
            if ($co !== '') {
                if (Payplex_agent_rbac::normalizeCode(isset($a->company) ? $a->company : '') !== $co) { continue; }
            }
            $out[] = $a;
        }
        // stable order by id asc
        usort($out, function ($x, $y) { return (int) $x->id <=> (int) $y->id; });
        return $out;
    }

    public function motionGet($id)
    {
        if (!$this->db->table_exists($this->motionTable())) { return null; }
        return $this->db->where('id', (int) $id)->get($this->motionTable())->row();
    }

    public function motionVotes($motionId)
    {
        if (!$this->db->table_exists($this->voteTable())) { return array(); }
        return $this->db->where('motion_id', (int) $motionId)->order_by('id', 'ASC')->get($this->voteTable())->result();
    }

    /** Company-scoped motion list for an actor (M7). */
    public function motionsScoped($staffId, $isAdmin, $companyView = '', $status = null, $limit = 300)
    {
        if (!$this->db->table_exists($this->motionTable())) { return array(); }
        if ($status !== null && $status !== '') { $this->db->where('status', $status); }
        $rows    = $this->db->order_by('dateupdated', 'DESC')->limit((int) $limit)->get($this->motionTable())->result();
        $allowed = $this->allowedCompaniesForActor($staffId, $isAdmin);
        $cross   = $this->hasCrossCompany($staffId, $isAdmin);
        $rows    = Payplex_agent_rbac::scope($rows, $allowed, $isAdmin, $cross, 'company');
        if ($companyView !== '' && $companyView !== 'group') {
            $view = Payplex_agent_rbac::normalizeCode($companyView);
            $rows = array_values(array_filter($rows, function ($r) use ($view) {
                return Payplex_agent_rbac::normalizeCode(isset($r->company) ? $r->company : '') === $view;
            }));
        }
        return $rows;
    }

    /** Compute the live outcome of a motion from its ballots + eligible set. */
    public function motionOutcome($motion)
    {
        if (!$motion) { return null; }
        $votes = array();
        foreach ($this->motionVotes($motion->id) as $v) {
            $votes[] = array(
                'vote'      => $v->vote,
                'weight'    => $v->weight,
                'agent_id'  => (int) $v->voter_agent_id,
                'rationale' => (string) $v->rationale,
            );
        }
        $eligible = count($this->eligibleVoters(isset($motion->company) ? $motion->company : ''));
        return Payplex_agent_vote::outcome($votes, array(
            'eligible' => $eligible,
            'quorum'   => (float) $motion->quorum,
            'rule'     => $motion->threshold_rule,
        ));
    }

    /**
     * Open a motion for the council to vote on. Kill-switch guarded. A motion
     * that authorises a real action (binding) or mentions an external action is
     * flagged "needs human": passing the vote can never execute anything — a
     * human Chairman must ratify and route it through a Decision Packet.
     * Returns array('ok','motion_id'|null,'errors','warnings').
     */
    public function openMotion($data, $actorId, $isAdmin = false)
    {
        if ((int) $this->getSetting('global_kill_switch', 0) === 1) {
            return array('ok' => false, 'errors' => array('global_kill_switch_engaged'), 'warnings' => array());
        }
        $title = trim((string) (isset($data['title']) ? $data['title'] : ''));
        if ($title === '') { return array('ok' => false, 'errors' => array('title_required'), 'warnings' => array()); }

        $type  = Payplex_agent_vote::normalizeType(isset($data['motion_type']) ? $data['motion_type'] : 'advisory');
        $rule  = Payplex_agent_vote::normalizeRule(isset($data['threshold_rule']) ? $data['threshold_rule'] : 'simple_majority');
        $quorum = isset($data['quorum']) && is_numeric($data['quorum']) ? (float) $data['quorum'] : 0.5;
        if ($quorum < 0) { $quorum = 0.0; }
        if ($quorum > 1) { $quorum = 1.0; } // fraction of the eligible council

        $desc  = (string) (isset($data['description']) ? $data['description'] : '');
        $flags = Payplex_agent_comms::classifyExternal($title . ' ' . $desc);
        $requiresHuman = Payplex_agent_vote::requiresRatification($type, $flags) ? 1 : 0;

        $proposer = (int) (isset($data['proposed_by_agent']) ? $data['proposed_by_agent'] : 0);
        $company  = $proposer > 0 ? $this->agentCompany($proposer) : Payplex_agent_rbac::normalizeCode((string) (isset($data['company']) ? $data['company'] : ''));

        $now = date('Y-m-d H:i:s');
        $this->db->insert($this->motionTable(), array(
            'title'             => substr($title, 0, 200),
            'description'       => $desc,
            'company'           => $company !== '' ? $company : null,
            'motion_type'       => $type,
            'threshold_rule'    => $rule,
            'quorum'            => $quorum,
            'status'            => Payplex_agent_vote::OPEN,
            'proposed_by_agent' => $proposer > 0 ? $proposer : null,
            'decision_id'       => isset($data['decision_id']) && $data['decision_id'] !== '' ? (int) $data['decision_id'] : null,
            'requires_human'    => $requiresHuman,
            'external_flags'    => !empty($flags) ? implode(',', $flags) : null,
            'created_by'        => (int) $actorId,
            'datecreated'       => $now,
            'dateupdated'       => $now,
        ));
        $mid = $this->db->insert_id();
        $warn = array();
        if ($requiresHuman) { $warn[] = 'binding/external motion — needs a human Chairman + Decision Packet'; }
        $this->audit(null, 'config_change', 'Council motion #' . $mid . ' opened (' . $type . '): ' . $title, array('motion_id' => $mid, 'type' => $type), $actorId);
        return array('ok' => true, 'motion_id' => $mid, 'errors' => array(), 'warnings' => $warn);
    }

    /**
     * Cast (or replace) one agent's weighted ballot on an open motion. One
     * ballot per voter is enforced; a voter may change their vote while the
     * motion is open. The voter must be eligible for the motion's company scope.
     */
    public function castVote($motionId, $data, $actorId, $isAdmin = false)
    {
        if ((int) $this->getSetting('global_kill_switch', 0) === 1) {
            return array('ok' => false, 'error' => 'global_kill_switch_engaged');
        }
        $m = $this->motionGet((int) $motionId);
        if (!$m) { return array('ok' => false, 'error' => 'motion_not_found'); }
        if ($m->status !== Payplex_agent_vote::OPEN) { return array('ok' => false, 'error' => 'motion_not_open'); }

        $voter = (int) (isset($data['voter_agent_id']) ? $data['voter_agent_id'] : 0);
        if ($voter <= 0) { return array('ok' => false, 'error' => 'voter_required'); }

        $eligibleIds = array_map(function ($a) { return (int) $a->id; }, $this->eligibleVoters(isset($m->company) ? $m->company : ''));
        if (!in_array($voter, $eligibleIds, true)) { return array('ok' => false, 'error' => 'voter_not_eligible'); }

        $vote   = Payplex_agent_vote::normalizeVote(isset($data['vote']) ? $data['vote'] : '');
        $weight = Payplex_agent_vote::normalizeWeight(isset($data['weight']) ? $data['weight'] : 1);
        $now    = date('Y-m-d H:i:s');

        $existing = $this->db->where('motion_id', (int) $motionId)->where('voter_agent_id', $voter)->get($this->voteTable())->row();
        if ($existing) {
            $this->db->where('id', (int) $existing->id)->update($this->voteTable(), array(
                'vote' => $vote, 'weight' => $weight,
                'rationale' => (string) (isset($data['rationale']) ? $data['rationale'] : ''),
                'dateupdated' => $now,
            ));
            $vid = (int) $existing->id;
        } else {
            $this->db->insert($this->voteTable(), array(
                'motion_id'      => (int) $motionId,
                'voter_agent_id' => $voter,
                'vote'           => $vote,
                'weight'         => $weight,
                'rationale'      => (string) (isset($data['rationale']) ? $data['rationale'] : ''),
                'created_by'     => (int) $actorId,
                'datecreated'    => $now,
                'dateupdated'    => $now,
            ));
            $vid = $this->db->insert_id();
        }
        $this->db->where('id', (int) $motionId)->update($this->motionTable(), array('dateupdated' => $now));
        $this->audit(null, 'config_change', 'Vote (' . $vote . ') cast on motion #' . (int) $motionId . ' by agent #' . $voter, array('motion_id' => (int) $motionId), $actorId);
        return array('ok' => true, 'vote_id' => $vid, 'vote' => $vote);
    }

    /**
     * Motion lifecycle transitions.
     *   close   -> tally + freeze the result (passed/failed/tie/no_quorum)
     *   ratify  -> Chairman accepts a passed motion (maker != approver)
     *   veto    -> Chairman blocks the motion
     *   reopen  -> return a closed/failed motion to voting
     */
    public function motionEvent($motionId, $event, $actorId, $isAdmin = false)
    {
        $m = $this->motionGet((int) $motionId);
        if (!$m) { return array('ok' => false, 'error' => 'motion_not_found'); }
        $now = date('Y-m-d H:i:s');

        if ($event === 'close') {
            if (!in_array($m->status, array(Payplex_agent_vote::OPEN), true)) { return array('ok' => false, 'error' => 'not_open'); }
            $o = $this->motionOutcome($m);
            $this->db->where('id', (int) $motionId)->update($this->motionTable(), array(
                'status' => Payplex_agent_vote::CLOSED, 'result' => $o['result'],
                'result_json' => json_encode($o), 'dateupdated' => $now, 'closed_at' => $now,
            ));
            $this->audit(null, 'config_change', 'Motion #' . (int) $motionId . ' closed -> ' . $o['result'], array('motion_id' => (int) $motionId, 'result' => $o['result']), $actorId);
            return array('ok' => true, 'status' => Payplex_agent_vote::CLOSED, 'result' => $o['result']);
        }

        if ($event === 'ratify') {
            if (!Payplex_agent_vote::canRatify($actorId, (int) $m->created_by, $isAdmin)) {
                return array('ok' => false, 'error' => 'not_authorized_or_maker_equals_approver');
            }
            $o = $this->motionOutcome($m);
            if (!$o['passed']) { return array('ok' => false, 'error' => 'motion_not_passed'); }
            $this->db->where('id', (int) $motionId)->update($this->motionTable(), array(
                'status' => Payplex_agent_vote::RATIFIED, 'result' => Payplex_agent_vote::PASSED,
                'result_json' => json_encode($o), 'ratified_by' => (int) $actorId,
                'dateupdated' => $now, 'closed_at' => $now,
            ));
            $this->audit(null, 'human_intervention', 'Motion #' . (int) $motionId . ' RATIFIED by staff #' . (int) $actorId, array('motion_id' => (int) $motionId), $actorId);
            return array('ok' => true, 'status' => Payplex_agent_vote::RATIFIED);
        }

        if ($event === 'veto') {
            if (!Payplex_agent_vote::canRatify($actorId, (int) $m->created_by, $isAdmin)) {
                return array('ok' => false, 'error' => 'not_authorized_or_maker_equals_approver');
            }
            $this->db->where('id', (int) $motionId)->update($this->motionTable(), array(
                'status' => Payplex_agent_vote::VETOED, 'result' => Payplex_agent_vote::VETOED,
                'ratified_by' => (int) $actorId, 'dateupdated' => $now, 'closed_at' => $now,
            ));
            $this->audit(null, 'human_intervention', 'Motion #' . (int) $motionId . ' VETOED by staff #' . (int) $actorId, array('motion_id' => (int) $motionId), $actorId);
            return array('ok' => true, 'status' => Payplex_agent_vote::VETOED);
        }

        if ($event === 'reopen') {
            if (!in_array($m->status, array(Payplex_agent_vote::CLOSED), true)) { return array('ok' => false, 'error' => 'not_reopenable'); }
            $this->db->where('id', (int) $motionId)->update($this->motionTable(), array(
                'status' => Payplex_agent_vote::OPEN, 'result' => null, 'result_json' => null,
                'closed_at' => null, 'dateupdated' => $now,
            ));
            $this->audit(null, 'config_change', 'Motion #' . (int) $motionId . ' reopened for voting', array('motion_id' => (int) $motionId), $actorId);
            return array('ok' => true, 'status' => Payplex_agent_vote::OPEN);
        }

        return array('ok' => false, 'error' => 'unknown_event');
    }

    /** Summary counts for the motions inbox header. */
    public function motionsSummary($staffId, $isAdmin, $companyView = '')
    {
        $motions = $this->motionsScoped($staffId, $isAdmin, $companyView, null, 1000);
        $out = array('total' => count($motions), 'open' => 0, 'ratified' => 0, 'needs_human' => 0);
        foreach ($motions as $m) {
            if ($m->status === Payplex_agent_vote::OPEN) { $out['open']++; }
            if ($m->status === Payplex_agent_vote::RATIFIED) { $out['ratified']++; }
            if ((int) $m->requires_human === 1) { $out['needs_human']++; }
        }
        return $out;
    }

}
