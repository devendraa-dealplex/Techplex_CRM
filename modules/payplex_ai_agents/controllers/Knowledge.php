<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Knowledge Base admin controller. Admins add product/FAQ/pricing/policy/script/
 * document/webpage/objection content that agents may answer from. Entries are
 * versioned, permission-scoped (per agent or all), and carry an indexing status.
 */
class Knowledge extends AdminController
{
    /** Max characters accepted for a test question in "Ask" - keeps the UI/API bounded. */
    const ASK_QUERY_MAX_LENGTH = 500;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_ai_agents/payplex_ai_agents_model', 'm');
    }

    private function guard($cap)
    {
        if (!payplex_ai_agents_can($cap)) {
            access_denied('payplex_ai_agents');
        }
    }

    private function actor()
    {
        return function_exists('get_staff_user_id') ? (int) get_staff_user_id() : 0;
    }

    public function index()
    {
        $this->guard('knowledge');
        $data['title']   = 'AI Agents - Knowledge Base';
        $data['entries'] = $this->m->kbList(false);
        $this->load->view('payplex_ai_agents/kb_list', $data);
    }

    public function create()
    {
        $this->guard('knowledge');
        if ($this->input->post()) {
            $id = $this->m->kbCreate($this->collect(), $this->actor());
            set_alert('success', 'Knowledge entry added and indexed.');
            redirect(admin_url('payplex_ai_agents/knowledge'));
        }
        $data['title'] = 'Add Knowledge';
        $data['entry'] = null;
        $data['categories'] = Payplex_agent_knowledge::categories();
        $this->load->view('payplex_ai_agents/kb_form', $data);
    }

    public function edit($id)
    {
        $this->guard('knowledge');
        $entry = $this->m->kbGet((int) $id);
        if (!$entry) {
            show_404();
        }
        if ($this->input->post()) {
            $this->m->kbUpdate((int) $id, $this->collect(), $this->actor());
            set_alert('success', 'Knowledge entry updated (new version) and re-indexed.');
            redirect(admin_url('payplex_ai_agents/knowledge'));
        }
        $data['title'] = 'Edit Knowledge';
        $data['entry'] = $entry;
        $data['categories'] = Payplex_agent_knowledge::categories();
        $data['versions'] = $this->m->kbVersions((int) $id);
        $this->load->view('payplex_ai_agents/kb_form', $data);
    }

    public function toggle($id)
    {
        $this->guard('knowledge');
        $this->m->kbToggleActive((int) $id, $this->actor());
        set_alert('success', 'Knowledge entry visibility toggled.');
        redirect(admin_url('payplex_ai_agents/knowledge'));
    }

    public function destroy($id)
    {
        $this->guard('knowledge');
        if ($this->input->method() !== 'post') { // a GET link would bypass Perfex's CSRF protection
            set_alert('warning', 'Invalid request.');
            redirect(admin_url('payplex_ai_agents/knowledge'));
        }
        $deleted = $this->m->kbDelete((int) $id, $this->actor());
        if ($deleted) {
            set_alert('success', 'Knowledge entry deleted.');
        } else {
            set_alert('warning', 'Knowledge entry not found.');
        }
        redirect(admin_url('payplex_ai_agents/knowledge'));
    }

    public function reindex($id)
    {
        $this->guard('knowledge');
        $this->m->kbReindex((int) $id, $this->actor());
        set_alert('success', 'Knowledge entry re-indexed.');
        redirect(admin_url('payplex_ai_agents/knowledge'));
    }

    /** Test the "answer only from permitted knowledge" behaviour for an agent. */
    public function ask()
    {
        $this->guard('knowledge');
        $agentIdRaw = trim((string) $this->input->post('agent_id'));
        $query      = trim((string) $this->input->post('query'));

        // Validate BEFORE ever calling knowledgeAnswer() - an invalid request
        // must not run a query or create a review-queue escalation for it.
        $error = $this->validateAsk($agentIdRaw, $query);
        if ($error) {
            if ($this->input->is_ajax_request()) {
                echo json_encode(array('error' => $error['code'], 'message' => $error['message']));
                return;
            }
            set_alert('warning', $error['message']);
            redirect(admin_url('payplex_ai_agents/knowledge'));
        }

        $agentId = (int) $agentIdRaw;
        $res = $this->m->knowledgeAnswer($agentId, $query, true, $this->actor());

        if ($this->input->is_ajax_request()) {
            $out = array(
                'hit'   => !empty($res['hit']),
                'score' => isset($res['score']) ? $res['score'] : 0,
            );
            if (!empty($res['hit'])) {
                $out['title']    = (string) $res['entry']['title'];
                $out['category'] = (string) $res['entry']['category'];
                $out['content']  = (string) $res['entry']['content'];
            } else {
                $out['reason'] = isset($res['reason']) ? $res['reason'] : 'no_confident_match';
            }
            echo json_encode($out);
            return;
        }

        // Non-AJAX fallback (e.g. JS disabled): same result via a flash message.
        if (!empty($res['hit'])) {
            // Wrap the title in single quotes (not double) and strip any literal double
            // quotes from it - app_js_alerts() embeds this message unescaped inside a
            // double-quoted JS string, so a bare " here breaks that <script> block and
            // the toast silently never renders.
            $safeTitle = str_replace('"', "'", (string) $res['entry']['title']);
            set_alert('success', "Answered from KB: '" . html_escape($safeTitle) . "' (score " . $res['score'] . ').');
        } else {
            set_alert('warning', 'No confident permitted answer (score ' . $res['score'] . ') - escalated to the review queue.');
        }
        redirect(admin_url('payplex_ai_agents/knowledge'));
    }

    /**
     * Validate an "Ask" test request. Returns ['code'=>..., 'message'=>...] on
     * failure, or null when the request is good to run.
     */
    private function validateAsk($agentIdRaw, $query)
    {
        if ($agentIdRaw === '' || !ctype_digit($agentIdRaw) || (int) $agentIdRaw <= 0) {
            return array('code' => 'agent_id_required', 'message' => 'Enter a valid Agent id to test.');
        }
        if (!$this->m->get((int) $agentIdRaw)) {
            return array('code' => 'agent_not_found', 'message' => 'No agent exists with id ' . $agentIdRaw . '.');
        }
        if ($query === '') {
            return array('code' => 'query_required', 'message' => 'Enter a question to test.');
        }
        if (mb_strlen($query) > self::ASK_QUERY_MAX_LENGTH) {
            return array('code' => 'query_too_long', 'message' => 'Question is too long (max ' . self::ASK_QUERY_MAX_LENGTH . ' characters).');
        }
        return null;
    }

    private function collect()
    {
        // scope: blank or "all" => all agents; otherwise comma/space list of agent ids.
        $scopeRaw = trim((string) $this->input->post('scope'));
        $scope = 'all';
        if ($scopeRaw !== '' && strtolower($scopeRaw) !== 'all') {
            $ids = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $scopeRaw)), 'is_numeric'));
            $scope = json_encode($ids);
        }
        return array(
            'title'      => (string) $this->input->post('title'),
            'category'   => (string) $this->input->post('category'),
            'content'    => (string) $this->input->post('content'),
            'keywords'   => (string) $this->input->post('keywords'),
            'scope'      => $scope,
            'permission' => 'knowledge',
        );
    }
}
