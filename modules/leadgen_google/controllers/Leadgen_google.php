<?php defined('BASEPATH') or exit('No direct script access allowed');

class Leadgen_google extends AdminController
{
    /**
     * The options this screen owns. Nothing else may be written through it.
     *
     * _save_settings() previously looped over the posted array and called
     * update_option($key, $value) for whatever it contained. The keys came from
     * the request. The form shows five fields; the endpoint accepted every
     * option in the application — mail settings, module flags, anything. That is
     * a configuration console, not a settings page, and it was reachable by any
     * staff member because this controller had no authorization check of any
     * kind.
     */
    private $allowed = array(
        'google_ads_webhook_key',
        'google_website_form_api_key',
        'google_ads_default_source',
        'google_website_default_source',
        'google_default_lead_status',
    );

    /** The two values that must never be printed back into the page. */
    private $secretKeys = array(
        'google_ads_webhook_key',
        'google_website_form_api_key',
    );

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Reading the settings needs 'view'; saving them needs 'edit'.
     *
     * Both capabilities have been registered by this module all along and
     * neither was ever checked — the bootstrap says so in its own comment.
     * Splitting read from write is what makes them mean something: a person who
     * may see which lead source is configured does not thereby get to rewrite
     * the webhook key that authenticates inbound leads.
     */
    public function index()
    {
        if (!is_admin() && !staff_can('view', 'leadgen_google')) {
            access_denied('leadgen_google');
        }

        if ($this->input->post()) {
            if (!is_admin() && !staff_can('edit', 'leadgen_google')) {
                access_denied('leadgen_google');
            }
            $this->_save_settings();
        }

        $data['lead_statuses'] = $this->db->get('leads_status')->result_array();
        $data['title']         = _l('leadgen_google_settings');
        $this->load->view('leadgen_google/settings', $data);
    }

    private function _save_settings()
    {
        $settings = $this->input->post('settings');

        if (!is_array($settings)) {
            redirect(admin_url('leadgen_google'));
            return;
        }

        $written = array();
        $refused = array();

        foreach ($settings as $key => $value) {
            if (!in_array($key, $this->allowed, true)) {
                $refused[] = (string) $key;
                continue;
            }

            /*
             * A blank submission for a key field means "leave it alone", not
             * "erase it". The two key fields are rendered empty on purpose so
             * the current value never reaches the page; without this rule,
             * opening the form and pressing Save would wipe both webhook keys
             * and silently break inbound lead capture.
             */
            if (in_array($key, $this->secretKeys, true) && trim((string) $value) === '') {
                continue;
            }

            update_option($key, $value);
            $written[] = $key;
        }

        log_activity('Leadgen Google settings updated. Written: '
            . (implode(', ', $written) ?: 'none')
            . '. Refused (not owned by this screen): '
            . (implode(', ', array_slice($refused, 0, 20)) ?: 'none'));

        set_alert('success', _l('settings_updated'));
        redirect(admin_url('leadgen_google'));
    }
}
