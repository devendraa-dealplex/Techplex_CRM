<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<?php echo payplex_cv_responsive_table_assets(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">

            <h4 class="no-mtop">
              Signers <small>&mdash; <?php echo html_escape($contract['subject']); ?></small>
            </h4>

            <?php if (empty($roster_ready)) { ?>
              <div class="alert alert-warning">
                <strong>The signer roster table is not installed.</strong>
                Migration <code>205_contract_signer_roster.php</code> has not been applied. Every
                other screen on this module continues to work; only this one is unavailable.
              </div>
            <?php } else { ?>

            <?php
              /* Readiness is stated before the form, because the most useful thing
                 this screen can tell an operator is whether the contract could
                 actually be sent -- not merely whether rows exist. */
            ?>
            <?php if (!empty($readiness['ok'])) { ?>
              <div class="alert alert-success">
                <strong>Roster is ready.</strong>
                <?php echo (int) $readiness['signers']; ?> signer(s),
                <?php echo (int) $readiness['mandatory']; ?> mandatory,
                Signing order is contiguous.
              </div>
            <?php } else { ?>
              <div class="alert alert-warning">
                <strong>Roster is not ready to send.</strong>
                <ul class="mtop10 mbot0">
                  <?php foreach ($readiness['reasons'] as $why) { ?>
                    <li><code><?php echo html_escape($why); ?></code></li>
                  <?php } ?>
                </ul>
              </div>
            <?php } ?>

            <?php if (!empty($has_request)) { ?>
              <div class="alert alert-info">
                A signing request already exists for this contract, so signers can no longer be
                removed &mdash; only <strong>replaced</strong>. A replacement keeps the original row,
                its slot and the reason, because the person a contract was previously addressed to is
                part of its record.
              </div>
            <?php } ?>

            <?php if (!empty($roster_ready)) { ?>
            <?php echo form_open(admin_url('payplex_contract_verification/signing/toggle_self_signer/'
                                           . (int) $contract['id']), array('class' => 'mbot15')); ?>
              <?php if (!empty($self_signed)) { ?>
                <input type="hidden" name="enable" value="0">
                <button type="submit" class="btn btn-default">
                  <i class="fa-solid fa-signature"></i> You are a signer on this document &mdash; remove me
                </button>
              <?php } else { ?>
                <input type="hidden" name="enable" value="1">
                <button type="submit" class="btn btn-default">
                  <i class="fa-regular fa-square"></i> I want to sign this document
                </button>
              <?php } ?>
            <?php echo form_close(); ?>
            <?php } ?>

            <div class="row">
              <div class="col-md-5">
                <h5>Add or edit a signer</h5>

                <?php echo form_open(admin_url('payplex_contract_verification/signing/signer_save/'
                                               . (int) $contract['id']), array('id' => 'signerform')); ?>
                  <input type="hidden" name="id" id="s_id" value="">

                  <div class="form-group">
                    <label class="control-label">Signing order (slot)</label>
                    <input type="number" min="1" max="50" class="form-control"
                           name="signing_order" id="s_order" value="1" required>
                    <p class="text-muted small">
                      The placement editor stores this same number. Slot 1 is the first signer.
                    </p>
                  </div>

                  <div class="form-group">
                    <label class="control-label">Full name</label>
                    <input type="text" maxlength="190" class="form-control"
                           name="full_name" id="s_name" required>
                  </div>

                  <div class="form-group">
                    <label class="control-label">Email</label>
                    <input type="email" maxlength="190" class="form-control"
                           name="email" id="s_email" required>
                    <p class="text-muted small">
                      One address per contract. The provider delivers here.
                    </p>
                  </div>

                  <div class="form-group">
                    <label class="control-label">Mobile (E.164)</label>
                    <input type="text" maxlength="20" class="form-control"
                           name="mobile_e164" id="s_mobile" placeholder="+919876543210"
                           pattern="\+[1-9][0-9]{7,14}">
                    <p class="text-muted small">
                      Must start <code>+</code> and country code. Required if Mobile OTP is chosen.
                    </p>
                  </div>

                  <div class="form-group">
                    <label class="control-label">Invitee type</label>
                    <select name="role" id="s_role" class="form-control">
                      <?php foreach ($invitee_types as $k => $label) { ?>
                        <option value="<?php echo html_escape($k); ?>"
                          <?php echo $k === 'signer' ? 'selected' : ''; ?>><?php echo html_escape($label); ?></option>
                      <?php } ?>
                    </select>
                    <p class="text-muted small">
                      A reviewer can see the document but is never sent a signing invitation and can
                      never be a mandatory signer.
                    </p>
                  </div>

                  <div class="form-group">
                    <label class="control-label">Signature type</label>
                    <select name="auth_method" id="s_auth" class="form-control">
                      <option value="">&mdash; not set &mdash;</option>
                      <?php foreach ($auth_methods as $k => $label) { ?>
                        <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($label); ?></option>
                      <?php } ?>
                    </select>
                    <p class="text-muted small">
                      <code>virtual</code> uses the CRM's own drawn/typed signature.
                      <code>email_otp</code>/<code>mobile_otp</code> require a code sent by this CRM
                      before signing. <code>aadhaar</code> asks for a self-declared Aadhaar number
                      &mdash; it is stored but <strong>never verified against any government
                      database</strong>.
                    </p>
                  </div>

                  <p>
                    <a href="#invitee-level-options" data-toggle="collapse" class="text-muted">
                      Invitee level options <i class="fa-solid fa-chevron-down"></i>
                    </a>
                  </p>
                  <div class="collapse" id="invitee-level-options">
                    <div class="form-group">
                      <label class="control-label">Designation</label>
                      <input type="text" maxlength="120" class="form-control"
                             name="designation" id="s_desig">
                    </div>

                    <div class="form-group">
                      <label class="control-label">Party</label>
                      <select name="party" id="s_party" class="form-control">
                        <?php foreach ($parties as $k => $label) { ?>
                          <option value="<?php echo html_escape($k); ?>"><?php echo html_escape($label); ?></option>
                        <?php } ?>
                      </select>
                    </div>

                    <div class="form-group">
                      <label class="control-label">Reminder interval (hours, 0 = none)</label>
                      <input type="number" min="0" max="8760" class="form-control"
                             name="reminder_interval_hours" id="s_rem" value="0">
                    </div>

                    <div class="checkbox">
                      <label><input type="checkbox" name="is_mandatory" id="s_mand" value="1" checked>
                        Mandatory signer</label>
                    </div>
                    <div class="checkbox">
                      <label><input type="checkbox" name="is_authorised_signatory" id="s_auth_sig" value="1">
                        Authorised signatory</label>
                    </div>
                  </div>

                  <button type="submit" class="btn btn-primary">Save signer</button>
                  <button type="button" class="btn btn-default" id="resetform">Clear</button>
                <?php echo form_close(); ?>
              </div>

              <div class="col-md-7">
                <h5>Roster <span class="label label-default"><?php echo count($signers); ?></span></h5>

                <?php if (!$signers) { ?>
                  <p class="text-muted">
                    No signers yet. A contract with no signers cannot be sent, and signature fields
                    placed against a slot will have nobody to resolve to.
                  </p>
                <?php } else { ?>
                  <p class="text-muted small">
                    Drag <i class="fa-solid fa-grip-vertical"></i> to reorder. Order is saved as soon
                    as you drop.
                  </p>
                  <div class="cv-tablewrap"><div class="table-responsive">
                  <table class="table table-condensed" id="signers-roster-table">
                    <thead><tr>
                      <th></th><th>#</th><th>Name</th><th>Email</th><th>Party</th><th>Type</th>
                      <th>Auth</th><th>Req.</th><th></th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($signers as $s) {
                            $replaced = (isset($s['replaced_at']) && $s['replaced_at'] !== null
                                         && (int) $s['replaced_at'] > 0); ?>
                      <tr<?php echo $replaced ? ' class="text-muted" style="opacity:.6"'
                                              : ' class="sortable-signer"'; ?>
                          data-id="<?php echo (int) $s['id']; ?>">
                        <td class="dragger">
                          <?php if (!$replaced) { ?><i class="fa-solid fa-grip-vertical"></i><?php } ?>
                        </td>
                        <td><?php echo (int) $s['signing_order']; ?></td>
                        <td>
                          <?php echo html_escape($s['full_name']); ?>
                          <?php if ($replaced) { ?>
                            <br><span class="label label-warning">replaced</span>
                            <?php if (!empty($s['replacement_reason'])) { ?>
                              <br><small><?php echo html_escape($s['replacement_reason']); ?></small>
                            <?php } ?>
                          <?php } ?>
                          <?php if (!empty($s['is_authorised_signatory'])) { ?>
                            <br><span class="label label-info">authorised signatory</span>
                          <?php } ?>
                        </td>
                        <td><small><?php echo html_escape($s['email']); ?></small></td>
                        <td><?php echo html_escape($s['party']); ?></td>
                        <td>
                          <?php echo (string) $s['role'] === 'reviewer'
                                ? '<span class="label label-default">reviewer</span>'
                                : '<span class="label label-primary">signer</span>'; ?>
                        </td>
                        <td><small><?php echo html_escape((string) $s['auth_method']); ?></small></td>
                        <td><?php echo empty($s['is_mandatory']) ? 'optional' : 'yes'; ?></td>
                        <td>
                          <?php if (!$replaced) { ?>
                            <button type="button" class="btn btn-xs btn-default s-edit"
                              data-id="<?php echo (int) $s['id']; ?>"
                              data-order="<?php echo (int) $s['signing_order']; ?>"
                              data-name="<?php echo html_escape($s['full_name']); ?>"
                              data-email="<?php echo html_escape($s['email']); ?>"
                              data-mobile="<?php echo html_escape((string) $s['mobile_e164']); ?>"
                              data-desig="<?php echo html_escape((string) $s['designation']); ?>"
                              data-party="<?php echo html_escape($s['party']); ?>"
                              data-role="<?php echo html_escape((string) $s['role']); ?>"
                              data-auth="<?php echo html_escape((string) $s['auth_method']); ?>"
                              data-rem="<?php echo (int) $s['reminder_interval_hours']; ?>"
                              data-mand="<?php echo (int) $s['is_mandatory']; ?>"
                              data-authsig="<?php echo (int) $s['is_authorised_signatory']; ?>">Edit</button>

                            <?php if (empty($has_request)) { ?>
                              <?php echo form_open(admin_url('payplex_contract_verification/signing/signer_delete/'
                                             . (int) $contract['id']), array('style' => 'display:inline')); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                <button type="submit" class="btn btn-xs btn-danger">Remove</button>
                              <?php echo form_close(); ?>
                            <?php } ?>
                          <?php } ?>
                        </td>
                      </tr>
                    <?php } ?>
                    </tbody>
                  </table>
                  </div><p class="cv-swipe">Swipe to view more</p></div>
                <?php } ?>

                <hr>
                <h5>Replace a signer</h5>
                <p class="text-muted small">
                  Use this when the contract must go to a different person. The original row is kept
                  and marked, the replacement inherits the slot, and the reason is recorded.
                </p>
                <?php echo form_open(admin_url('payplex_contract_verification/signing/signer_replace/'
                                               . (int) $contract['id'])); ?>
                  <div class="form-group">
                    <label class="control-label">Signer being replaced</label>
                    <select name="id" class="form-control" required>
                      <option value="">&mdash; choose &mdash;</option>
                      <?php foreach ($signers as $s) {
                              if (isset($s['replaced_at']) && $s['replaced_at'] !== null
                                  && (int) $s['replaced_at'] > 0) { continue; } ?>
                        <option value="<?php echo (int) $s['id']; ?>">
                          <?php echo (int) $s['signing_order']; ?> &mdash;
                          <?php echo html_escape($s['full_name']); ?>
                        </option>
                      <?php } ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="control-label">Replacement name</label>
                    <input type="text" maxlength="190" class="form-control" name="full_name" required>
                  </div>
                  <div class="form-group">
                    <label class="control-label">Replacement email</label>
                    <input type="email" maxlength="190" class="form-control" name="email" required>
                  </div>
                  <div class="form-group">
                    <label class="control-label">Replacement mobile (E.164, optional)</label>
                    <input type="text" maxlength="20" class="form-control" name="mobile_e164"
                           pattern="\+[1-9][0-9]{7,14}">
                  </div>
                  <input type="hidden" name="party" value="customer">
                  <input type="hidden" name="is_mandatory" value="1">
                  <div class="form-group">
                    <label class="control-label">Reason for replacement</label>
                    <textarea name="replacement_reason" class="form-control" rows="2" required
                      maxlength="500" placeholder="Why is this contract going to a different person?"></textarea>
                  </div>
                  <button type="submit" class="btn btn-warning">Replace signer</button>
                <?php echo form_close(); ?>
              </div>
            </div>

            <?php } /* roster_ready */ ?>

            <hr>
            <a class="btn btn-default"
               href="<?php echo admin_url('payplex_contract_verification/signing/contract/'
                                          . (int) $contract['id']); ?>">Back to contract signing</a>
            <a class="btn btn-default"
               href="<?php echo admin_url('payplex_contract_verification/signing/fields_editor/'
                                          . (int) $contract['id']); ?>">Place signature fields</a>
            <a class="btn btn-primary pull-right"
               href="<?php echo admin_url('payplex_contract_verification/signing/finalize/'
                                          . (int) $contract['id']); ?>">Finalize &amp; send &raquo;</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  /* Edit loads the row into the same form the add path uses. One form, one
     server-side validator, one set of rules -- rather than a second "edit"
     screen that drifts away from the first. */
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.s-edit') : null;
    if (!b) { return; }
    var g = function (k) { return b.getAttribute('data-' + k) || ''; };
    var set = function (id, v) { var el = document.getElementById(id); if (el) { el.value = v; } };
    var chk = function (id, v) { var el = document.getElementById(id); if (el) { el.checked = v === '1'; } };
    set('s_id', g('id'));      set('s_order', g('order'));  set('s_name', g('name'));
    set('s_email', g('email')); set('s_mobile', g('mobile')); set('s_desig', g('desig'));
    set('s_party', g('party')); set('s_role', g('role') || 'signer');
    set('s_auth', g('auth'));    set('s_rem', g('rem'));
    chk('s_mand', g('mand'));   chk('s_auth_sig', g('authsig'));
    syncMandatoryToRole();
    window.scrollTo(0, 0);
  });

  var r = document.getElementById('resetform');
  if (r) {
    r.addEventListener('click', function () {
      var f = document.getElementById('signerform');
      if (f) { f.reset(); }
      var id = document.getElementById('s_id');
      if (id) { id.value = ''; }
      syncMandatoryToRole();
    });
  }

  /* A reviewer never signs, so "mandatory signer" makes no sense for one --
     the server enforces this regardless (saveContractSigner() forces
     is_mandatory off for a reviewer), this just keeps the form honest about
     what saving will actually do. */
  function syncMandatoryToRole() {
    var role = document.getElementById('s_role');
    var mand = document.getElementById('s_mand');
    if (!role || !mand) { return; }
    var isReviewer = role.value === 'reviewer';
    mand.disabled = isReviewer;
    if (isReviewer) { mand.checked = false; }
  }

  var roleSelect = document.getElementById('s_role');
  if (roleSelect) { roleSelect.addEventListener('change', syncMandatoryToRole); }
  syncMandatoryToRole();
})();

$(function () {
  var contract_id = <?php echo (int) $contract['id']; ?>;

  /* Same drag-handle + jQuery UI sortable convention already used for
     invoice/estimate line items (init_items_sortable() in main.js) -- reused
     here rather than adding a second drag-and-drop library for one screen. */
  var $table = $('#signers-roster-table tbody');

  if ($table.length && $.fn.sortable) {
    $table.sortable({
      handle: '.dragger',
      items: 'tr.sortable-signer',
      axis: 'y',
      update: function () {
        var order = [];
        $table.find('tr.sortable-signer').each(function () {
          order.push($(this).data('id'));
        });

        $.post(admin_url + 'payplex_contract_verification/signing/reorder_signers/' + contract_id, {
          order: order
        }).done(function (response) {
          var data = typeof response === 'string' ? JSON.parse(response) : response;
          if (data.success) {
            alert_float('success', data.message);
            $table.find('tr.sortable-signer').each(function (i) {
              $(this).find('td').eq(1).text(i + 1);
            });
          } else {
            alert_float('danger', data.message);
            window.location.reload();
          }
        }).fail(function () {
          alert_float('danger', 'Could not save the new order. Reloading.');
          window.location.reload();
        });
      }
    });
  }

  /* Flip the chevron on the "Invitee level options" toggle -- cosmetic only,
     Bootstrap's own collapse handles the show/hide. */
  $('[href="#invitee-level-options"]').on('click', function () {
    $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
  });
});
</script>
<?php init_tail(); ?>
