<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-10 col-md-offset-1">
  <div class="panel_s"><div class="panel-body">
    <?php $this->load->view('payplex_commission/_statement_stamp', ['issue' => $issue]); ?>
    <h4 style="color:#12507F;font-weight:600">Statement #<?php echo (int)$s->id; ?>
      — <?php echo $s->staff_id ? get_staff_full_name($s->staff_id) : '—'; ?> · <?php echo html_escape($s->period); ?></h4>
    <p>
      Status: <b><?php echo html_escape(Payplex_commission_workflow::label($state)); ?></b> ·
      Net: <b><?php echo app_format_money($s->net_amount, $s->currency); ?></b>
      <?php if ($s->snapshot_hash): ?>
        · Integrity:
        <?php if ($integrity === true): ?><span style="color:#067647">✓ intact</span>
        <?php elseif ($integrity === false): ?><span style="color:#B42318">✗ TAMPERED</span>
        <?php endif; ?>
      <?php endif; ?>
    </p>
    <?php if ($s->supersedes_id): ?><p class="text-muted">Supersedes statement #<?php echo (int)$s->supersedes_id; ?></p><?php endif; ?>

    <table class="table">
      <thead><tr><th>Source</th><th>Base</th><th>Commission</th><th>Breakdown</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?php echo html_escape($it->source_type); ?> #<?php echo (int)$it->source_id; ?></td>
          <td><?php echo app_format_money($it->base_amount, $s->currency); ?></td>
          <td><?php echo app_format_money($it->commission_amount, $s->currency); ?></td>
          <td class="mini" style="color:#667085"><?php echo html_escape($it->breakdown); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?php if ($state === Payplex_commission_workflow::UNDER_REVIEW && $can_approve): ?>
      <?php echo form_open(admin_url('payplex_commission/commission/approve/'.$s->id), ['style'=>'display:inline']); ?>
        <button class="btn btn-success" type="submit">Approve &amp; lock</button>
      <?php echo form_close(); ?>
      <span class="text-muted">(You cannot approve a statement you generated.)</span>
    <?php elseif ($state === Payplex_commission_workflow::APPROVED && $can_pay): ?>
      <?php echo form_open(admin_url('payplex_commission/commission/pay/'.$s->id), ['style'=>'display:inline']); ?>
        <button class="btn btn-primary" type="submit">Mark paid</button>
      <?php echo form_close(); ?>
    <?php endif; ?>
    <a class="btn btn-link" href="<?php echo admin_url('payplex_commission/commission'); ?>">Back</a>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
