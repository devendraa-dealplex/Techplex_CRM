<?php defined('BASEPATH') or exit('No direct script access allowed');
$o = function ($k) { return html_escape((string) get_option('payplex_videokyc_' . $k)); };
$wa = get_option('payplex_videokyc_whatsapp_provider') === 'meta' ? 'meta' : 'twilio';
init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-10 col-md-offset-1">
      <h4 class="kyc-title"><i class="fa fa-cog"></i> Video KYC — Settings &amp; Templates</h4>

      <!-- ============================================================ Templates -->
      <div class="panel_s"><div class="panel-body">
        <div class="kyc-toolbar">
          <h5 class="no-margin">Script templates</h5>
          <button class="btn btn-primary btn-sm" id="tpl-new"><i class="fa fa-plus"></i> New template</button>
        </div>
        <p class="text-muted">The customer reads this aloud on camera. Placeholders: <code>{customer_name}</code> <code>{company_name}</code> <code>{date}</code>. Each template can have English, Hindi and Marathi text;
          a language left blank uses the standard consent statement in that language. Each request stores a rendered <em>copy</em>, so editing a template never changes what an earlier customer was asked to say.</p>
        <table class="table table-condensed" id="tpl-table">
          <thead><tr><th>Name</th><th>Script</th><th>Status</th><th class="text-right"></th></tr></thead>
          <tbody>
          <?php foreach ($templates as $t): ?>
            <tr data-id="<?php echo (int) $t->id; ?>" data-name="<?php echo html_escape($t->name); ?>" data-body="<?php echo html_escape($t->body); ?>"
                data-body-hi="<?php echo html_escape((string) $t->body_hi); ?>" data-body-mr="<?php echo html_escape((string) $t->body_mr); ?>"
                data-active="<?php echo (int) $t->active; ?>" data-default="<?php echo (int) $t->is_default; ?>">
              <td><strong><?php echo html_escape($t->name); ?></strong> <?php echo $t->is_default ? '<span class="label label-info">default</span>' : ''; ?></td>
              <td class="text-muted"><?php echo html_escape(mb_strimwidth($t->body, 0, 110, '…')); ?>
                <br><small>
                  <span class="label label-success">EN</span>
                  <span class="label label-<?php echo trim((string) $t->body_hi) !== '' ? 'success' : 'default'; ?>" title="<?php echo trim((string) $t->body_hi) !== '' ? 'Custom Hindi text' : 'Uses the standard Hindi statement'; ?>">हिंदी</span>
                  <span class="label label-<?php echo trim((string) $t->body_mr) !== '' ? 'success' : 'default'; ?>" title="<?php echo trim((string) $t->body_mr) !== '' ? 'Custom Marathi text' : 'Uses the standard Marathi statement'; ?>">मराठी</span>
                </small></td>
              <td><?php echo $t->active ? '<span class="label label-success">active</span>' : '<span class="label label-default">inactive</span>'; ?></td>
              <td class="text-right">
                <button class="btn btn-default btn-xs tpl-edit">Edit</button>
                <button class="btn btn-danger btn-xs tpl-del">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div></div>

      <!-- ============================================================= Settings -->
      <?php echo form_open(admin_url('video-kyc/settings')); ?>

      <div class="panel_s"><div class="panel-body">
        <h5 class="kyc-sec">Link &amp; recording rules</h5>
        <div class="row">
          <div class="col-sm-4 form-group"><label>Default link lifetime (hours)</label>
            <input type="number" name="link_ttl_hours" min="1" max="336" class="form-control" value="<?php echo $o('link_ttl_hours'); ?>"></div>
          <div class="col-sm-4 form-group"><label>Upload attempts per link</label>
            <input type="number" name="max_attempts" min="1" max="10" class="form-control" value="<?php echo $o('max_attempts'); ?>">
            <small class="text-muted">A rejected file (wrong format / too large) does not use up an attempt.</small></div>
          <div class="col-sm-4 form-group"><label>Max video size (MB)</label>
            <input type="number" name="max_upload_mb" min="1" max="200" class="form-control" value="<?php echo $o('max_upload_mb'); ?>">
            <small class="text-muted">Your server currently allows at most <strong><?php echo (int) $php_limit_mb; ?> MB</strong>
              (php.ini <code>upload_max_filesize</code> / <code>post_max_size</code>) — the lower value wins.</small></div>
          <div class="col-sm-4 form-group"><label>Minimum recording (seconds)</label>
            <input type="number" name="min_record_sec" min="1" max="60" class="form-control" value="<?php echo $o('min_record_sec'); ?>"></div>
          <div class="col-sm-4 form-group"><label>Maximum recording (seconds)</label>
            <input type="number" name="max_record_sec" min="10" max="300" class="form-control" value="<?php echo $o('max_record_sec'); ?>"></div>
        </div>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <h5 class="kyc-sec">Message text</h5>
        <div class="form-group"><label>SMS / WhatsApp (non-template) body</label>
          <textarea name="msg_sms" rows="2" class="form-control" placeholder="Hi {customer_name}, please complete your video KYC for {company}: {link} (valid till {expiry})."><?php echo $o('msg_sms'); ?></textarea>
          <small class="text-muted">Placeholders: <code>{customer_name}</code> <code>{company}</code> <code>{link}</code> <code>{expiry}</code>.
            This custom text is used for <strong>English</strong> recipients only; Hindi and Marathi recipients always get the built-in Hindi/Marathi message.
            Email uses Perfex SMTP (Setup → Settings → Email).</small></div>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <h5 class="kyc-sec">Twilio (SMS, and WhatsApp if selected)</h5>
        <div class="row">
          <div class="col-sm-6 form-group"><label>Account SID</label><input name="twilio_sid" class="form-control" value="<?php echo $o('twilio_sid'); ?>" autocomplete="off"></div>
          <div class="col-sm-6 form-group"><label>Auth token <?php echo $secrets['twilio_token'] ? '<span class="label label-success">configured</span>' : '<span class="label label-default">not set</span>'; ?></label>
            <input type="password" name="twilio_token" class="form-control" placeholder="<?php echo $secrets['twilio_token'] ? 'Leave blank to keep the saved token' : ''; ?>" autocomplete="new-password"></div>
          <div class="col-sm-6 form-group"><label>SMS "From" number</label><input name="twilio_sms_from" class="form-control" placeholder="+1…" value="<?php echo $o('twilio_sms_from'); ?>"></div>
          <div class="col-sm-6 form-group"><label>…or Messaging Service SID</label><input name="twilio_messaging_service" class="form-control" placeholder="MG…" value="<?php echo $o('twilio_messaging_service'); ?>">
            <small class="text-muted">If set, it is used instead of the From number.</small></div>
          <div class="col-sm-6 form-group"><label>WhatsApp sender</label><input name="twilio_wa_from" class="form-control" placeholder="+14155238886" value="<?php echo $o('twilio_wa_from'); ?>"></div>
          <div class="col-sm-6 form-group"><label>WhatsApp template Content SID</label><input name="twilio_wa_content_sid" class="form-control" placeholder="HX… (variables: 1 name, 2 company, 3 link)" value="<?php echo $o('twilio_wa_content_sid'); ?>">
            <small class="text-muted">Required for business-initiated WhatsApp outside the sandbox.</small></div>
          <div class="col-sm-6 form-group"><label>Hindi template Content SID <small class="text-muted">(optional)</small></label>
            <input name="twilio_wa_content_sid_hi" class="form-control" placeholder="HX… approved in Hindi" value="<?php echo $o('twilio_wa_content_sid_hi'); ?>"></div>
          <div class="col-sm-6 form-group"><label>Marathi template Content SID <small class="text-muted">(optional)</small></label>
            <input name="twilio_wa_content_sid_mr" class="form-control" placeholder="HX… approved in Marathi" value="<?php echo $o('twilio_wa_content_sid_mr'); ?>">
            <small class="text-muted">WhatsApp templates have fixed wording in one language. If a Hindi/Marathi ID is empty, the default template above is used for those customers.</small></div>
        </div>
      </div></div>

      <div class="panel_s"><div class="panel-body">
        <h5 class="kyc-sec">WhatsApp provider</h5>
        <div class="form-group">
          <label class="radio-inline"><input type="radio" name="whatsapp_provider" value="twilio" <?php echo $wa === 'twilio' ? 'checked' : ''; ?>> Twilio</label>
          <label class="radio-inline"><input type="radio" name="whatsapp_provider" value="meta" <?php echo $wa === 'meta' ? 'checked' : ''; ?>> Meta Cloud API</label>
        </div>
        <div class="row">
          <div class="col-sm-6 form-group"><label>Phone number ID</label><input name="meta_phone_id" class="form-control" value="<?php echo $o('meta_phone_id'); ?>"></div>
          <div class="col-sm-6 form-group"><label>Permanent access token <?php echo $secrets['meta_token'] ? '<span class="label label-success">configured</span>' : '<span class="label label-default">not set</span>'; ?></label>
            <input type="password" name="meta_token" class="form-control" placeholder="<?php echo $secrets['meta_token'] ? 'Leave blank to keep the saved token' : ''; ?>" autocomplete="new-password"></div>
          <div class="col-sm-6 form-group"><label>Approved template name</label><input name="meta_template" class="form-control" value="<?php echo $o('meta_template'); ?>">
            <small class="text-muted">Body variables: {{1}} name, {{2}} company, {{3}} link.</small></div>
          <div class="col-sm-6 form-group"><label>Template language</label><input name="meta_template_lang" class="form-control" placeholder="en" value="<?php echo $o('meta_template_lang'); ?>"></div>
          <div class="col-sm-6 form-group"><label>Hindi template name <small class="text-muted">(optional, language code <code>hi</code>)</small></label>
            <input name="meta_template_hi" class="form-control" value="<?php echo $o('meta_template_hi'); ?>"></div>
          <div class="col-sm-6 form-group"><label>Marathi template name <small class="text-muted">(optional, language code <code>mr</code>)</small></label>
            <input name="meta_template_mr" class="form-control" value="<?php echo $o('meta_template_mr'); ?>">
            <small class="text-muted">Empty = the default template above is used for that language.</small></div>
        </div>
      </div></div>

      <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save settings</button>
      <?php echo form_close(); ?>
    </div></div>
  </div>
</div>

<!-- Template editor modal -->
<div class="modal fade" id="tpl-modal" tabindex="-1" role="dialog">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><button type="button" class="close" data-dismiss="modal"><span>&times;</span></button><h4 class="modal-title">Script template</h4></div>
    <div class="modal-body">
      <input type="hidden" id="tpl-id">
      <div class="form-group"><label>Name</label><input id="tpl-name" class="form-control" maxlength="120"></div>
      <div class="form-group"><label>Script — English</label><textarea id="tpl-body" rows="4" class="form-control" maxlength="1000"></textarea>
        <small class="text-muted">Keep it short enough to read aloud in one breath. Placeholders: {customer_name} {company_name} {date}</small></div>
      <div class="form-group"><label>Script — Hindi (हिंदी) <small class="text-muted">optional</small></label>
        <textarea id="tpl-body-hi" rows="4" class="form-control" maxlength="1000" lang="hi" placeholder="Leave blank to use the standard Hindi statement"></textarea></div>
      <div class="form-group"><label>Script — Marathi (मराठी) <small class="text-muted">optional</small></label>
        <textarea id="tpl-body-mr" rows="4" class="form-control" maxlength="1000" lang="mr" placeholder="Leave blank to use the standard Marathi statement"></textarea></div>
      <label class="checkbox-inline"><input type="checkbox" id="tpl-active" checked> Active</label>
      <label class="checkbox-inline"><input type="checkbox" id="tpl-default"> Default</label>
      <div id="tpl-msg" class="mtop10"></div>
    </div>
    <div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">Cancel</button><button class="btn btn-primary" id="tpl-save">Save template</button></div>
  </div></div>
</div>

<script>
var KYC_SETTINGS_BASE = <?php echo json_encode(admin_url('video-kyc')); ?>;
(function () {
  function ready(fn) { (window.jQuery ? fn : function () { setTimeout(function () { ready(fn); }, 50); })(window.jQuery); }
  ready(function ($) {
    var base = KYC_SETTINGS_BASE;
    function open(tr) {
      $('#tpl-id').val(tr ? tr.data('id') : '');
      $('#tpl-name').val(tr ? tr.data('name') : '');
      $('#tpl-body').val(tr ? tr.data('body') : '');
      $('#tpl-body-hi').val(tr ? tr.data('body-hi') : '');
      $('#tpl-body-mr').val(tr ? tr.data('body-mr') : '');
      $('#tpl-active').prop('checked', tr ? !!tr.data('active') : true);
      $('#tpl-default').prop('checked', tr ? !!tr.data('default') : false);
      $('#tpl-msg').empty(); $('#tpl-modal').modal('show');
    }
    $('#tpl-new').on('click', function () { open(null); });
    $('#tpl-table').on('click', '.tpl-edit', function () { open($(this).closest('tr')); });
    $('#tpl-table').on('click', '.tpl-del', function () {
      var tr = $(this).closest('tr');
      if (!confirm('Delete "' + tr.data('name') + '"? If past requests used it, it is deactivated instead.')) { return; }
      $.post(base + '/template_delete/' + tr.data('id'), {}, null, 'json').done(function () { location.reload(); });
    });
    $('#tpl-save').on('click', function () {
      $.post(base + '/template_save', { id: $('#tpl-id').val(), name: $('#tpl-name').val(), body: $('#tpl-body').val(), body_hi: $('#tpl-body-hi').val(), body_mr: $('#tpl-body-mr').val(),
        active: $('#tpl-active').is(':checked') ? 1 : '', is_default: $('#tpl-default').is(':checked') ? 1 : '' }, null, 'json')
        .done(function () { location.reload(); })
        .fail(function (x) { var m = 'Could not save.'; try { m = JSON.parse(x.responseText).message; } catch (e) {} $('#tpl-msg').html('<div class="alert alert-danger">' + $('<i>').text(m).html() + '</div>'); });
    });
  });
})();
</script>
<?php init_tail(); ?>
