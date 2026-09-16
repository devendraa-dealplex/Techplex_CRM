<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $sc=Payplex_staff_lifecycle::statusClass($p->status); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-9">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo html_escape($p->full_name); ?> <span class="text-muted" style="font-size:13px">#<?php echo (int)$p->staff_id; ?></span></h4>
      <div>
        <?php if ($canManage): ?><a href="<?php echo admin_url('payplex_staff/staff/form/'.(int)$p->staff_id); ?>" class="btn btn-default btn-sm">Edit</a><?php endif; ?>
        <a href="<?php echo admin_url('payplex_staff/staff'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
    </div>

    <div class="panel_s"><div class="panel-body">
      <p>
        <span class="label label-<?php echo $sc; ?>"><?php echo html_escape($p->status); ?></span>
        <span class="label label-default"><?php echo html_escape(Payplex_staff_types::label($p->employment_type)); ?></span>
        <span class="label label-default">v<?php echo (int)$p->version; ?></span>
        <?php echo (int)$p->classification_required?'<span class="label label-warning">Classification Required</span>':''; ?>
        <span class="label label-<?php echo $p->kyc_status==='verified'?'success':'warning'; ?>">KYC: <?php echo html_escape($p->kyc_status); ?></span>
        <span class="label label-<?php echo (int)$p->bank_verified?'success':'default'; ?>">Bank: <?php echo (int)$p->bank_verified?'verified':'not verified'; ?></span>
      </p>
      <?php if ($financialBlocked): ?><div class="alert alert-warning" style="font-size:12px"><i class="fa fa-lock"></i> <strong>Financial payout blocked.</strong> KYC and bank verification are required before any commission/expense payout — ordinary non-financial work is unaffected.</div><?php endif; ?>
      <div class="row" style="font-size:13px">
        <div class="col-md-4"><strong>Department:</strong> <?php echo html_escape($p->department?:'—'); ?><br><strong>Designation:</strong> <?php echo html_escape($p->designation?:'—'); ?><br><strong>Branch:</strong> <?php echo html_escape($p->branch?:'—'); ?><br><strong>Territory:</strong> <?php echo html_escape($p->territory?:'—'); ?></div>
        <div class="col-md-4"><strong>Email:</strong> <?php echo html_escape($p->official_email?:'—'); ?><br><strong>Mobile:</strong> <?php echo html_escape($p->official_mobile?:'—'); ?><br><strong>Joining:</strong> <?php echo html_escape($p->joining_date?:'—'); ?><br><strong>Payout:</strong> <?php echo html_escape($p->payout_frequency); ?></div>
        <div class="col-md-4"><strong>Eligibility:</strong><br>Salary <?php echo (int)$p->salary_eligibility?'✔':'✗'; ?> · Commission <?php echo (int)$p->commission_eligibility?'✔':'✗'; ?><br>Expense <?php echo (int)$p->expense_eligibility?'✔':'✗'; ?> · TA/DA <?php echo (int)$p->tada_eligibility?'✔':'✗'; ?><br>Attendance <?php echo (int)$p->attendance_required?'required':'not required'; ?></div>
      </div>
    </div></div>

    <?php
      // available lifecycle actions from current status
      $actions=array();
      switch($p->status){
        case 'draft': $actions=array('submit_docs'=>'Move to Documentation Pending','submit'=>'Submit'); break;
        case 'documentation_pending': $actions=array('submit'=>'Submit'); break;
        case 'submitted': $actions=array('verify'=>'Verify','return'=>'Return'); break;
        case 'verified': $actions=array('approve'=>'Approve','return'=>'Return'); break;
        case 'approved': $actions=array('activate'=>'Activate'); break;
        case 'active': $actions=array('suspend'=>'Suspend','issue_notice'=>'Issue Notice','exit'=>'Exit'); break;
        case 'suspended': $actions=array('reinstate'=>'Reinstate','exit'=>'Exit'); break;
        case 'notice_period': $actions=array('exit'=>'Exit'); break;
        case 'exited': $actions=array('archive'=>'Archive'); break;
      }
    ?>
    <?php if (!empty($actions)): ?>
    <div class="panel_s"><div class="panel-body">
      <h6 style="font-weight:600;margin-top:0">Lifecycle</h6>
      <div style="display:flex;gap:6px;flex-wrap:wrap">
        <?php foreach($actions as $a=>$lbl): $cls=$a==='approve'?'success':($a==='exit'||$a==='suspend'?'danger':'default');
          echo form_open(admin_url('payplex_staff/staff/act/'.(int)$p->staff_id.'/'.$a),array('style'=>'display:inline'));
          echo '<button class="btn btn-'.$cls.' btn-sm" type="submit">'.$lbl.'</button>'; echo form_close(); endforeach; ?>
      </div>
      <p class="text-muted" style="font-size:11px;margin-top:6px">Approval requires the approve capability and cannot be done by the person who created the record (maker≠approver).</p>
    </div></div>
    <?php endif; ?>

    <div class="panel_s"><div class="panel-body">
      <h6 style="font-weight:600;margin-top:0">Version history</h6>
      <table class="table table-bordered" style="font-size:12px"><thead><tr><th>v</th><th>Type</th><th>Status</th><th>Effective</th><th>Current</th></tr></thead><tbody>
      <?php foreach($versions as $vv): ?><tr><td><?php echo (int)$vv->version; ?></td><td><?php echo html_escape(Payplex_staff_types::label($vv->employment_type)); ?></td><td><?php echo html_escape($vv->status); ?></td><td><?php echo html_escape($vv->effective_from); ?></td><td><?php echo (int)$vv->is_current?'✔':''; ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
