<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Lead-level AI Calling panel (injected via lead_profile_tabs hook).
 * $lead is provided by the Perfex lead modal context.
 * Buttons render only if the staff member holds the capability; the server
 * re-checks on every action (menu visibility is not authorization).
 */
$leadId   = isset($lead) ? $lead->id : (int) ($this->input->get('id') ?? 0);
$canCall  = is_admin() || staff_can('create', 'payplex_aicalling');
$canConsentView   = is_admin() || staff_can('consent_view', 'payplex_aicalling');
$canConsentManage = is_admin() || staff_can('consent_manage', 'payplex_aicalling');
$consent = null;
if ($canConsentView && $leadId) {
    $this->load->model('payplex_aicalling/payplex_consent_model');
    $consent = $this->payplex_consent_model->current('lead', $leadId, 'call');
}
?>
<div class="pp-lead-panel" data-lead="<?php echo (int) $leadId; ?>">
  <?php if (!$canCall): ?>
    <p class="text-muted">You do not have permission to place AI calls.</p>
  <?php else: ?>
  <?php if ($canConsentView): ?>
    <div class="pp-consent-quick" data-type="lead" data-id="<?php echo (int) $leadId; ?>" data-channel="call"
         data-state="<?php echo html_escape($consent->state ?? 'missing'); ?>"
         data-dnd="<?php echo (int) ($consent->dnd ?? 0); ?>" style="margin-bottom:10px;">
      Consent: <span class="pp-badge pp-<?php echo ($consent && $consent->state === 'granted') ? 'completed' : 'failed'; ?>"><?php echo html_escape($consent ? $consent->state : 'not set'); ?></span>
      &nbsp;DND: <?php echo ($consent && (int) $consent->dnd === 1) ? '<span class="pp-badge pp-failed">on</span>' : 'off'; ?>
      <?php if ($canConsentManage): ?>
        <button class="btn btn-xs btn-success pp-consent-grant">Grant</button>
        <button class="btn btn-xs btn-default pp-consent-withdraw">Withdraw</button>
        <button class="btn btn-xs btn-danger pp-consent-dnd">Toggle DND</button>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="pp-actionbar">
    <button class="btn btn-success btn-sm pp-call-now"><i class="fa fa-phone"></i> Call Now</button>
    <button class="btn btn-default btn-sm pp-call-schedule"><i class="fa fa-clock-o"></i> Schedule Call</button>
  </div>

  <div class="pp-call-form" style="display:none;">
    <div class="row">
      <div class="col-sm-4 form-group">
        <label>AI Agent</label>
        <select class="form-control pp-agent">
          <option value="voice_agent_en_sales">Sales — English</option>
          <option value="voice_agent_hi_sales">Sales — Hindi</option>
        </select>
      </div>
      <div class="col-sm-4 form-group">
        <label>Language</label>
        <select class="form-control pp-language">
          <option value="en-IN">English (IN)</option>
          <option value="hi-IN">Hindi</option>
        </select>
      </div>
      <div class="col-sm-4 form-group pp-schedule-at-wrap" style="display:none;">
        <label>Schedule at</label>
        <input type="datetime-local" class="form-control pp-schedule-at">
      </div>
    </div>
    <div class="form-group">
      <label>Objective</label>
      <input type="text" class="form-control pp-objective" placeholder="e.g. Follow up on Pro plan pricing">
    </div>
    <button class="btn btn-primary btn-sm pp-call-submit">Place call</button>
    <button class="btn btn-link btn-sm pp-call-cancel">Cancel</button>
    <div class="pp-call-result"></div>
  </div>

  <h5 class="pp-sub">Call history for this lead</h5>
  <div class="pp-lead-history"><em class="text-muted">Loading…</em></div>
  <?php endif; ?>
</div>
<script>
  window.PP_START_URL = "<?php echo admin_url('payplex_aicalling/aicalling/start_call'); ?>";
  window.PP_LEAD_HISTORY_URL = "<?php echo admin_url('payplex_aicalling/aicalling/history'); ?>";
  window.PP_CONSENT_SET = "<?php echo admin_url('payplex_aicalling/consent/set'); ?>";
  window.PP_CSRF = {name:"<?php echo $this->security->get_csrf_token_name(); ?>", hash:"<?php echo $this->security->get_csrf_hash(); ?>"};
</script>
