<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Module Name: Payplex Video KYC
 * Description: Send customers a secure, expiring link; they record a short video reading a
 *              generated statement in their browser; staff review and approve/reject. Links
 *              go out by Email, SMS and WhatsApp. No Perfex core files are modified.
 * Version: 1.0.0
 * Requires at least: 2.3.*
 * Author: Payplex
 */

define('PAYPLEX_VIDEOKYC_MODULE', 'payplex_videokyc');

register_activation_hook(PAYPLEX_VIDEOKYC_MODULE, 'payplex_videokyc_activate');
function payplex_videokyc_activate()
{
    require_once __DIR__ . '/install.php';
}

/* -------------------------------------------------------------------------
 * Schema upgrades. install.php only runs on activation, so a module that is
 * already active would never get columns added in a later version. install.php
 * is idempotent (every step is guarded), so it doubles as the migration.
 *   v2: multi-language scripts (templates.body_hi/body_mr, requests.script_language)
 *   v3: identity documents table (payplex_vkyc_documents)
 *   v4: Video KYC capabilities granted to the stock sales roles
 *   v5: ...and copied into staff_permissions, which is what staff_can() reads
 *   v6: 'resubmit' status (Ask again)
 *   v7: branded onboarding email (set password + Video KYC) replaces the set-password template
 *   v8: employee KYC (rel_type 'staff') and its HR permissions
 *   v9: Super Admin / CRM Admin roles get every Video KYC capability
 *   v10: payplex_videokyc_lock_cutoff — the boundary date for the mandatory-
 *        KYC access lock (accounts created before it are never hard-locked)
 * ---------------------------------------------------------------------- */
define('PAYPLEX_VIDEOKYC_SCHEMA_VERSION', 10);

hooks()->add_action('admin_init', 'payplex_videokyc_migrate');
function payplex_videokyc_migrate()
{
    if ((int) get_option('payplex_videokyc_schema_version') >= PAYPLEX_VIDEOKYC_SCHEMA_VERSION) {
        return;
    }
    require __DIR__ . '/install.php';
    update_option('payplex_videokyc_schema_version', (string) PAYPLEX_VIDEOKYC_SCHEMA_VERSION);
}

/* -------------------------------------------------------------------------
 * Capabilities. Hiding a menu item is NOT authorization — every controller
 * action re-checks the capability it needs.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_permissions');
function payplex_videokyc_permissions()
{
    register_staff_capabilities(PAYPLEX_VIDEOKYC_MODULE, ['capabilities' => [
        'view'         => 'View Video KYC dashboard and requests (own customers only unless "View all" is also given)',
        'view_all'     => 'View all customers\' and leads\' KYC, not only those assigned to the staff member',
        'generate'     => 'Generate / resend KYC links',
        'review'       => 'Approve or reject submissions',
        'video_access' => 'Watch recorded KYC videos',
        'documents'    => 'Upload and view customer identity documents (Step 1 of KYC)',
        'settings'     => 'Manage KYC settings, templates and providers',
        'employee_all'  => 'Employee KYC: see and review ALL employees (HR). Managers review their own reports without this.',
        'employee_send' => 'Employee KYC: send Video KYC links to employees (HR)',
    ]], 'Payplex Video KYC');
}

/* -------------------------------------------------------------------------
 * No sidebar entry. Video KYC is its own tab on the customer profile:
 * Customer > "Video KYC" (?group=payplex_vkyc). It holds the customer's document
 * and video progress, their requests and the review modal, gated on the
 * capabilities above. The core Contracts tab is left alone. Staff without 'view'
 * do not get the tab. The /admin/video-kyc/* JSON endpoints stay: the tab uses them.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_customer_tab');
function payplex_videokyc_customer_tab()
{
    if (!function_exists('get_instance') || !(is_admin() || staff_can('view', PAYPLEX_VIDEOKYC_MODULE))) {
        return;
    }
    $CI = &get_instance();
    if (!isset($CI->app_tabs) || !method_exists($CI->app_tabs, 'add_customer_profile_tab')) {
        return;
    }
    $CI->app_tabs->add_customer_profile_tab('payplex_vkyc', [
        'name'     => 'Video KYC',
        'icon'     => 'fa fa-video-camera',
        'view'     => 'payplex_videokyc/client_kyc_tab',
        'position' => 62,
    ]);
}

/* -------------------------------------------------------------------------
 * Customer portal menu: "Video KYC" for logged-in customers.
 * ---------------------------------------------------------------------- */
hooks()->add_action('clients_init', 'payplex_videokyc_portal_menu');
function payplex_videokyc_portal_menu()
{
    if (!is_client_logged_in()) {
        return;
    }
    add_theme_menu_item('payplex-videokyc', [
        'name'     => 'Video KYC',
        'href'     => site_url('clients/video-kyc'),
        'position' => 40,
    ]);
}

/* -------------------------------------------------------------------------
 * Assets — loaded only on Video KYC admin pages. The version token busts
 * browser caches on deploy (Perfex does not version module asset URLs).
 * ---------------------------------------------------------------------- */
function payplex_videokyc_is_kyc_page()
{
    $CI = &get_instance();
    if ($CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'video-kyc') {
        return true;
    }
    // Customer profile > "Video KYC" tab hosts the progress, requests and review.
    return $CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'clients'
        && $CI->input->get('group') === 'payplex_vkyc';
}

hooks()->add_action('app_admin_head', 'payplex_videokyc_head');
function payplex_videokyc_head()
{
    if (!payplex_videokyc_is_kyc_page()) {
        return;
    }
    echo '<link href="' . module_dir_url(PAYPLEX_VIDEOKYC_MODULE, 'assets/css/videokyc.css')
       . '?v=' . filemtime(__DIR__ . '/assets/css/videokyc.css') . '" rel="stylesheet">';
}

hooks()->add_action('app_admin_footer', 'payplex_videokyc_footer');
function payplex_videokyc_footer()
{
    if (!payplex_videokyc_is_kyc_page()) {
        return;
    }
    echo '<script src="' . module_dir_url(PAYPLEX_VIDEOKYC_MODULE, 'assets/js/videokyc.js')
       . '?v=' . filemtime(__DIR__ . '/assets/js/videokyc.js') . '"></script>';
}

/* -------------------------------------------------------------------------
 * Templates / providers / limits live under Setup, for the `settings` capability
 * only (the sidebar entry is gone). Sales never sees this.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_setup_menu');
function payplex_videokyc_setup_menu()
{
    if (!(is_admin() || staff_can('settings', PAYPLEX_VIDEOKYC_MODULE))) {
        return;
    }
    get_instance()->app_menu->add_setup_menu_item('payplex-videokyc-settings', [
        'name'     => 'Video KYC Settings',
        'href'     => admin_url('video-kyc/settings'),
        'position' => 37,
    ]);
}

/* -------------------------------------------------------------------------
 * {video_kyc_url} for the customer's welcome / set-password emails. It is the
 * customer portal's KYC page: a logged-out customer is sent to log in first (after
 * setting their password) and lands back on it, so the link works straight from
 * the email. The video link itself is issued there, once Step 1 is done.
 * ---------------------------------------------------------------------- */
hooks()->add_filter('client_contact_merge_fields', 'payplex_videokyc_merge_fields');
function payplex_videokyc_merge_fields($fields)
{
    $fields['{video_kyc_url}'] = site_url('clients/video-kyc');
    return $fields;
}

/* -------------------------------------------------------------------------
 * A KYC request is opened the moment a lead is converted, so staff see the
 * customer's Video KYC as "Pending" straight away (customer > Video KYC tab),
 * even before the customer has logged in. The customer's email links to the
 * portal page; when they press "Start Video KYC" this same request is re-issued
 * with a fresh recording link (flowState: pending = resumable), so there is one
 * request per onboarding, not two.
 *
 * created_by is the converting staff member, so it also falls inside that
 * person's own scope. Never throws: a failure here must not break the conversion.
 * ---------------------------------------------------------------------- */
hooks()->add_action('lead_converted_to_customer', 'payplex_videokyc_open_request_on_conversion');
function payplex_videokyc_open_request_on_conversion($data)
{
    try {
        $customerId = isset($data['customer_id']) ? (int) $data['customer_id'] : 0;
        if ($customerId <= 0) {
            return;
        }
        require_once __DIR__ . '/libraries/Payplex_kyc_scripts.php';
        $CI = &get_instance();
        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_conv');
        $m = $CI->kyc_conv;

        $subject = $m->subject('customer', $customerId);
        $tpl     = $m->defaultTemplate();
        if (!$subject || !$tpl || !$tpl->active) {
            return;
        }
        $state = $m->flowState($customerId);
        if ($state['request_id'] && in_array($state['request_status'], ['pending', 'in_progress', 'submitted', 'approved'], true)) {
            return;   // already has a live request
        }

        list(, $hash) = $m->newToken();   // raw token deliberately dropped: the customer gets a fresh one at Start
        $lang = Payplex_kyc_scripts::DEFAULT_LANG;
        $m->createRequest([
            'token_hash'      => $hash,
            'rel_type'        => 'customer',
            'rel_id'          => $customerId,
            'customer_name'   => $subject['name'],
            'customer_email'  => $subject['email'],
            'customer_phone'  => $subject['phone'],
            'template_id'     => $tpl->id,
            'dynamic_script'  => Videokyc_model::renderScript($tpl, $subject['name'], $lang),
            'script_language' => $lang,
            'expires_at'      => date('Y-m-d H:i:s', time() + 14 * 24 * 3600),
            'created_by'      => (int) get_staff_user_id(),
        ]);
        log_activity('Video KYC request opened on lead conversion [customer #' . $customerId . ']');
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC: could not open request on conversion: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * Customer portal gate. Until a logged-in customer has SUBMITTED their Video KYC
 * (their latest request is "submitted" or "approved"), every portal page shows a
 * full-screen popup that cannot be dismissed: the only ways forward are "Complete
 * Video KYC" and "Log out". If staff reject the video or ask again, or the request
 * expires, the popup returns.
 *
 * It is not shown on the KYC page itself or on the public recording page. It is
 * a screen overlay rather than a server-side block, on purpose: a bug here must
 * never lock customers out of logging out or finishing the KYC.
 *
 * Off switch: option payplex_videokyc_portal_gate = 0.
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * MANDATORY KYC LOCK — customer side. Applies ONLY to a customer whose login
 * (contact) was created AFTER payplex_videokyc_lock_cutoff (see install.php
 * step 13) — i.e. someone who joined after this feature existed. An existing
 * customer is never affected by this; they only ever see the reminder popup
 * above.
 *
 * A locked customer can reach exactly two places: the portal home (dashboard)
 * and /clients/video-kyc. Every other portal page redirects there instead.
 * Runs on 'clients_init', which fires for every clients-area request — before
 * any output, so a redirect here is safe. Login/register/forgot-password are
 * untouched because is_client_logged_in() is false while on them.
 *
 * Off switch: option payplex_videokyc_customer_lock = 0.
 * ---------------------------------------------------------------------- */
hooks()->add_action('clients_init', 'payplex_videokyc_customer_lock');
function payplex_videokyc_customer_lock()
{
    try {
        if (get_option('payplex_videokyc_customer_lock') === '0' || !is_client_logged_in()) {
            return;
        }
        $CI = &get_instance();
        // The portal home (dashboard) and the whole /clients/video-kyc area are
        // always reachable — nothing else needs checking if we're already there.
        $seg2 = $CI->uri->segment(2);
        if ($seg2 === null || $seg2 === '' || $seg2 === 'video-kyc') {
            return;
        }

        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_lock');
        if (!$CI->kyc_lock->isNewCustomerContact((int) get_contact_user_id())) {
            return;   // existing customer: never hard-locked
        }
        $state = $CI->kyc_lock->flowState((int) get_client_user_id());
        if ($state['request_status'] === 'approved') {
            return;
        }

        redirect(site_url('clients/video-kyc'));
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC customer lock failed: ' . $e->getMessage());
    }
}

hooks()->add_action('app_customers_footer', 'payplex_videokyc_portal_gate');
function payplex_videokyc_portal_gate()
{
    try {
        if (get_option('payplex_videokyc_portal_gate') === '0' || !is_client_logged_in()) {
            return;
        }
        $CI = &get_instance();
        if ($CI->uri->segment(1) === 'clients' && $CI->uri->segment(2) === 'video-kyc') {
            return;
        }

        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_gate');
        $state = $CI->kyc_gate->flowState((int) get_client_user_id());
        if ($state['request_status'] === 'approved') {
            return;
        }

        $again    = in_array($state['request_status'], ['rejected', 'resubmit'], true);
        $awaiting = $state['request_status'] === 'submitted';
        $title    = $again ? 'Please redo your Video KYC' : ($awaiting ? 'Video KYC awaiting review' : 'Complete your Video KYC');
        $text     = $again
            ? 'Your last Video KYC could not be accepted. Please record it again to continue using your account.'
            : ($awaiting
                ? 'Your video has been submitted and is awaiting review. Full access is unlocked once it is approved.'
                : 'To continue using your account, please complete your Video KYC verification. It takes about a minute.');
        $note     = ($again && $state['review_note'] !== '') ? $state['review_note'] : '';
        $go    = site_url('clients/video-kyc');
        $out   = site_url('authentication/logout');
        ?>
<div id="pvk-gate" role="dialog" aria-modal="true" aria-labelledby="pvk-gate-title" style="position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.72);display:flex;align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;max-width:440px;width:100%;padding:28px;box-shadow:0 20px 50px rgba(0,0,0,.35);font-family:inherit;text-align:center;">
    <div style="font-size:38px;line-height:1;color:#1673d1;margin-bottom:10px;"><i class="fa fa-video-camera" aria-hidden="true"></i></div>
    <h3 id="pvk-gate-title" style="margin:0 0 10px 0;font-size:20px;color:#173a72;"><?php echo html_escape($title); ?></h3>
    <p style="margin:0 0 14px 0;color:#4b5563;font-size:14px;line-height:21px;"><?php echo html_escape($text); ?></p>
    <?php if ($note !== '') { ?>
    <p style="margin:0 0 14px 0;padding:10px 12px;background:#fff7e6;border-radius:6px;color:#7a5200;font-size:13px;text-align:left;"><strong>Note from our team:</strong> <?php echo nl2br(html_escape($note)); ?></p>
    <?php } ?>
    <a href="<?php echo html_escape($go); ?>" style="display:block;background:#1673d1;color:#fff;text-decoration:none;font-weight:600;padding:12px 16px;border-radius:8px;">Complete Video KYC</a>
    <a href="<?php echo html_escape($out); ?>" style="display:inline-block;margin-top:12px;color:#6b7280;font-size:13px;">Log out</a>
  </div>
</div>
<script>document.body.style.overflow = 'hidden';</script>
        <?php
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC portal gate failed: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * "My Video KYC" sidebar entry — every staff member can check their OWN
 * status, exactly as a customer can check theirs. No capability gate, same as
 * Payplex Commission's "My Commission" entry: everyone with a staff account
 * sees this one.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_my_menu');
function payplex_videokyc_my_menu()
{
    get_instance()->app_menu->add_sidebar_menu_item('payplex-my-videokyc', [
        'name'     => 'My Video KYC',
        'href'     => admin_url('video-kyc/my'),
        'icon'     => 'fa fa-video-camera',
        'position' => 33,
    ]);
}

/**
 * Is this staff member exempt from the mandatory-KYC lock and its reminder?
 * Admins, and HR (employee_all / employee_send — the two capabilities that
 * let someone send and review EVERY employee's KYC). Exempting HR too, not
 * only admins, means a brand-new HR hire can still review and approve
 * everyone else's KYC — including another new admin's or HR colleague's —
 * without first needing their own approved, which would otherwise deadlock.
 */
function payplex_videokyc_kyc_exempt()
{
    return is_admin() || staff_can('employee_all', PAYPLEX_VIDEOKYC_MODULE) || staff_can('employee_send', PAYPLEX_VIDEOKYC_MODULE);
}

/* -------------------------------------------------------------------------
 * MANDATORY KYC LOCK — employee side. Applies ONLY to a staff member whose
 * account was created AFTER payplex_videokyc_lock_cutoff (install.php step 13)
 * — someone who joined after this feature existed — and who is not admin/HR
 * (payplex_videokyc_kyc_exempt()). An existing employee is never hard-locked;
 * see the reminder banner further below instead.
 *
 * A locked employee can reach exactly two places: the admin Dashboard and
 * /admin/video-kyc/my (+ its POST continue action). Every other admin page
 * redirects to /admin/video-kyc/my. Runs on 'admin_init', which fires for
 * every page built on AdminController — before output, so a redirect here is
 * safe — and is skipped for AJAX requests, the same way core itself skips its
 * own admin_init checks on AJAX (AdminController.php).
 *
 * Off switch: option payplex_videokyc_employee_lock = 0.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_employee_lock');
function payplex_videokyc_employee_lock()
{
    try {
        $CI = &get_instance();
        if (get_option('payplex_videokyc_employee_lock') === '0' || $CI->input->is_ajax_request()) {
            return;
        }
        $sid = (int) get_staff_user_id();
        if ($sid <= 0 || payplex_videokyc_kyc_exempt()) {
            return;
        }

        $seg2 = $CI->uri->segment(2);
        $seg3 = $CI->uri->segment(3);
        if ($seg2 === null || $seg2 === '' || $seg2 === 'dashboard') {
            return;   // the admin Dashboard
        }
        if ($seg2 === 'video-kyc' && in_array($seg3, ['my', 'my_continue'], true)) {
            return;   // their own KYC status/continue
        }

        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_emp_lock');
        if (!$CI->kyc_emp_lock->isNewStaffMember($sid)) {
            return;   // existing employee: never hard-locked
        }
        $req = $CI->kyc_emp_lock->myEmployeeRequest($sid);
        if ($req && $req->status === 'approved') {
            return;
        }

        redirect(admin_url('video-kyc/my'));
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC employee lock failed: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * Reminder for an EXISTING employee (pre-cutoff) whose KYC is not approved —
 * the "notification" counterpart to the hard lock above. Non-blocking: a
 * slim banner, dismissible for the rest of the browser session (sessionStorage,
 * so it returns on the next login), never a full-page block. Skipped for
 * admin/HR (payplex_videokyc_kyc_exempt()) and on the KYC page itself.
 * ---------------------------------------------------------------------- */
hooks()->add_action('app_admin_footer', 'payplex_videokyc_employee_reminder');
function payplex_videokyc_employee_reminder()
{
    try {
        $CI = &get_instance();
        $sid = (int) get_staff_user_id();
        if ($sid <= 0 || payplex_videokyc_kyc_exempt()) {
            return;
        }
        if ($CI->uri->segment(2) === 'video-kyc' && $CI->uri->segment(3) === 'my') {
            return;
        }
        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_emp_note');
        if ($CI->kyc_emp_note->isNewStaffMember($sid)) {
            return;   // new employees get the hard lock instead, not this
        }
        $req = $CI->kyc_emp_note->myEmployeeRequest($sid);
        if ($req && $req->status === 'approved') {
            return;
        }
        $text = ($req && in_array($req->status, ['rejected', 'resubmit'], true))
            ? 'Your Video KYC needs to be redone. Please check "My Video KYC".'
            : 'Please complete your Video KYC.';
        ?>
<div id="pvk-emp-note" style="display:none;position:sticky;top:0;z-index:1000;background:#fff7e6;color:#7a5200;
  border-bottom:1px solid #f4d99a;padding:8px 16px;font-size:13px;text-align:center;">
  <?php echo html_escape($text); ?>
  <a href="<?php echo html_escape(admin_url('video-kyc/my')); ?>" style="color:#7a5200;font-weight:600;text-decoration:underline;">My Video KYC</a>
  &nbsp;·&nbsp;<a href="#" id="pvk-emp-note-dismiss" style="color:#7a5200;">Dismiss</a>
</div>
<script>
(function () {
  try {
    if (sessionStorage.getItem('pvk_emp_note_dismissed') === '1') { return; }
  } catch (e) {}
  var el = document.getElementById('pvk-emp-note');
  if (!el) { return; }
  el.style.display = 'block';
  var d = document.getElementById('pvk-emp-note-dismiss');
  if (d) {
    d.addEventListener('click', function (e) {
      e.preventDefault();
      el.style.display = 'none';
      try { sessionStorage.setItem('pvk_emp_note_dismissed', '1'); } catch (e2) {}
    });
  }
})();
</script>
        <?php
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC employee reminder failed: ' . $e->getMessage());
    }
}

/* -------------------------------------------------------------------------
 * "Employee KYC" sidebar entry: admins, HR (employee_all / employee_send) and any
 * staff member who has employees reporting to them (payplex_staff reporting
 * manager). Everyone else sees nothing.
 * ---------------------------------------------------------------------- */
hooks()->add_action('admin_init', 'payplex_videokyc_employee_menu');
function payplex_videokyc_employee_menu()
{
    $ok = is_admin() || staff_can('employee_all', PAYPLEX_VIDEOKYC_MODULE) || staff_can('employee_send', PAYPLEX_VIDEOKYC_MODULE);
    if (!$ok) {
        $CI = &get_instance();
        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_menu');
        $ok = $CI->kyc_menu->managesAnyone((int) get_staff_user_id());
    }
    if (!$ok) {
        return;
    }
    get_instance()->app_menu->add_sidebar_menu_item('payplex-employee-kyc', [
        'name'     => 'Employee KYC',
        'href'     => admin_url('video-kyc/employees'),
        'icon'     => 'fa fa-id-badge',
        'position' => 32,
    ]);
}

/* -------------------------------------------------------------------------
 * A new staff member ALWAYS gets their "here is your login and password"
 * welcome email (new-staff-created), regardless of whether the "Send welcome
 * email" checkbox on the New Staff form was left ticked. Perfex generates the
 * password either way (auto-generate button or typed in) — without this, an
 * admin who forgets, or unchecks, that one box creates an account nobody was
 * ever told how to log in to. Uses Perfex's own `before_create_staff_member`
 * filter (application/models/Staff_model.php), so no core file is touched.
 *
 * This is the password email only. The separate Video KYC email is handled
 * by payplex_videokyc_on_staff_created() below, on the actual insert.
 * ---------------------------------------------------------------------- */
hooks()->add_filter('before_create_staff_member', 'payplex_videokyc_force_staff_welcome_email');
function payplex_videokyc_force_staff_welcome_email($data)
{
    $data['send_welcome_email'] = 'on';
    return $data;
}

/* -------------------------------------------------------------------------
 * A brand-new staff member is sent their Video KYC link automatically, the
 * moment their account is created (application/models/Staff_model.php fires
 * 'staff_member_created' right after the insert, passing just the new
 * staffid). HR/admin no longer has to remember to visit Employee KYC and send
 * it by hand for every new hire.
 *
 * Skipped for is_not_staff rows (not a real employee) and inactive ones, and
 * refuses quietly — same as a manual send — if there is no active script
 * template. A failure here must never break staff creation itself.
 *
 * Off switch: option payplex_videokyc_auto_send_on_staff_created = 0.
 * ---------------------------------------------------------------------- */
hooks()->add_action('staff_member_created', 'payplex_videokyc_on_staff_created');
function payplex_videokyc_on_staff_created($staffId)
{
    try {
        $staffId = (int) $staffId;
        if ($staffId <= 0 || get_option('payplex_videokyc_auto_send_on_staff_created') === '0') {
            return;
        }
        require_once __DIR__ . '/libraries/Payplex_kyc_notifier.php';
        $CI = &get_instance();
        $CI->load->model('payplex_videokyc/videokyc_model', 'kyc_onboard');
        $m = $CI->kyc_onboard;

        $row = $CI->db->where('staffid', $staffId)->where('active', 1)->where('is_not_staff', 0)
            ->get(db_prefix() . 'staff')->row();
        if (!$row) {
            return;
        }
        $tpl = $m->defaultTemplate();
        if (!$tpl || !$tpl->active) {
            log_message('error', 'Video KYC: no active script template, could not auto-send on staff creation [staff #' . $staffId . ']');
            return;
        }
        // Never sends twice — a role/permission update firing this hook again,
        // or any other re-trigger, is a no-op once a request already exists.
        if ($m->latestFor('staff', $staffId)) {
            return;
        }

        $name  = trim($row->firstname . ' ' . $row->lastname);
        $lang  = Payplex_kyc_scripts::DEFAULT_LANG;
        $hours = (int) get_option('payplex_videokyc_link_ttl_hours') ?: 48;

        list($raw, $hash) = $m->newToken();
        $rid = $m->createRequest([
            'token_hash'      => $hash,
            'rel_type'        => 'staff',
            'rel_id'          => $staffId,
            'customer_name'   => $name,
            'customer_email'  => $row->email,
            'customer_phone'  => $row->phonenumber,
            'template_id'     => $tpl->id,
            'dynamic_script'  => Videokyc_model::renderScript($tpl, $name, $lang),
            'script_language' => $lang,
            'expires_at'      => date('Y-m-d H:i:s', time() + $hours * 3600),
            'created_by'      => 0,   // 0 = issued automatically by the system, not by a staff member
        ]);

        $req = $m->getUnscoped($rid);
        (new Payplex_kyc_notifier($m))->dispatch($req, $raw, Payplex_kyc_notifier::CHANNELS);
        log_activity('Video KYC link auto-sent on staff creation [staff #' . $staffId . ', request #' . $rid . ']');
    } catch (\Throwable $e) {
        log_message('error', 'Video KYC: could not auto-send on staff creation: ' . $e->getMessage());
    }
}
