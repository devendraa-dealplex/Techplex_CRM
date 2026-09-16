<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 class="pp-title">Consent &amp; DND</h4>
    <p class="text-muted">Latest consent/DND state per subject. A call is allowed only when state = <b>granted</b> and DND = <b>off</b> (enforced fail-closed at call time and again on the AI backend).</p>
    <div class="table-responsive">
      <table class="table pp-table">
        <thead><tr><th>Subject</th><th>Channel</th><th>State</th><th>DND</th><th>Source</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="pp-empty">No consent records yet.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr data-type="<?php echo html_escape($r->subject_type); ?>" data-id="<?php echo (int)$r->subject_id; ?>" data-channel="<?php echo html_escape($r->channel); ?>"
              data-state="<?php echo html_escape($r->state); ?>" data-dnd="<?php echo (int)$r->dnd; ?>">
            <td><?php echo html_escape(ucfirst($r->subject_type)); ?> #<?php echo (int)$r->subject_id; ?></td>
            <td><?php echo html_escape($r->channel); ?></td>
            <td><span class="pp-badge pp-<?php echo $r->state==='granted'?'completed':'failed'; ?>"><?php echo html_escape($r->state); ?></span></td>
            <td><?php echo ((int)$r->dnd===1)?'<span class="pp-badge pp-failed">DND on</span>':'off'; ?></td>
            <td class="mini"><?php echo html_escape($r->source ?: '—'); ?></td>
            <td class="mini"><?php echo _dt($r->created_at); ?></td>
            <td>
              <?php if (is_admin() || staff_can('consent_manage','payplex_aicalling')): ?>
                <button class="btn btn-xs btn-success pp-consent-grant">Grant</button>
                <button class="btn btn-xs btn-default pp-consent-withdraw">Withdraw</button>
                <button class="btn btn-xs btn-danger pp-consent-dnd">Toggle DND</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?>
<script>
window.PP_CONSENT_SET = "<?php echo admin_url('payplex_aicalling/consent/set'); ?>";
window.PP_CSRF = {name:"<?php echo $this->security->get_csrf_token_name(); ?>", hash:"<?php echo $this->security->get_csrf_hash(); ?>"};
</script>
</body></html>
