<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content"><div class="row"><div class="col-md-12">
  <div class="panel_s"><div class="panel-body">
    <h4 style="color:#12507F;font-weight:600">My Commission</h4>
    <p class="text-muted" style="font-size:13px">
      A statement marked <b style="color:#B42318">DRAFT</b> has not been approved yet. The figure
      may still change, and it is not a document to rely on or pass on.
    </p>
    <div class="table-responsive">
      <table class="table">
        <thead><tr><th>Period</th><th>Gross</th><th>Clawback</th><th>Net</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($statements)): ?><tr><td colspan="6" style="text-align:center;color:#667085;padding:22px">No commission yet.</td></tr>
        <?php else: foreach ($statements as $s): ?>
          <tr>
            <td><?php echo html_escape($s->period); ?></td>
            <td><?php echo app_format_money($s->gross_amount, $s->currency); ?></td>
            <td><?php echo app_format_money($s->clawback_amount, $s->currency); ?></td>
            <td><b><?php echo app_format_money($s->net_amount, $s->currency); ?></b></td>
            <?php $issue = Payplex_commission_workflow::canIssue($s); ?>
            <td>
              <?php if ($issue['label'] === 'FINAL'): ?>
                <span class="label label-success">FINAL</span>
              <?php else: ?>
                <span class="label label-danger"><?php echo html_escape($issue['label']); ?></span>
              <?php endif; ?>
              <br><small class="text-muted"><?php
                echo html_escape(Payplex_commission_workflow::label(
                    Payplex_commission_workflow::stateOf($s))); ?></small>
            </td>
            <td>
              <?php /* A draft is not a thing to dispute — it is not final yet. */ ?>
              <?php if ($issue['allowed']): ?>
                <button class="btn btn-xs btn-default" onclick="document.getElementById('d<?php echo $s->id; ?>').style.display='block'">Dispute</button>
                <div id="d<?php echo $s->id; ?>" style="display:none;margin-top:6px">
                  <?php echo form_open(admin_url('payplex_commission/my_commission/dispute/'.$s->id)); ?>
                    <input class="form-control input-sm" name="reason" placeholder="Reason">
                    <button class="btn btn-xs btn-danger" type="submit" style="margin-top:4px">Submit dispute</button>
                  <?php echo form_close(); ?>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div></div></div></div>
<?php init_tail(); ?></body></html>
