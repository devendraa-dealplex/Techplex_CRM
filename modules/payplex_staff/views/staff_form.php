<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $p=$profile; $val=function($k,$d='') use($p){ return $p&&isset($p->$k)?$p->$k:$d; }; ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-9">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo $p?'Edit Staff Profile':'New Staff Profile'; ?></h4>
      <a href="<?php echo admin_url('payplex_staff/staff'); ?>" class="btn btn-default btn-sm">Back</a>
    </div>
    <div class="panel_s"><div class="panel-body">
      <?php echo form_open(admin_url('payplex_staff/staff/store')); ?>
      <div class="row">
        <div class="col-md-4 form-group"><label>Staff member <span class="text-danger">*</span></label>
          <?php if ($p): ?><input class="form-control" value="<?php echo html_escape($p->full_name); ?> (#<?php echo (int)$p->staff_id; ?>)" disabled><input type="hidden" name="staff_id" value="<?php echo (int)$p->staff_id; ?>"><input type="hidden" name="full_name" value="<?php echo html_escape($p->full_name); ?>">
          <?php else: ?><select class="form-control" name="staff_id" id="staffsel" required><option value="">— select —</option><?php foreach($core as $c): ?><option value="<?php echo (int)$c->staffid; ?>" data-name="<?php echo html_escape(trim($c->firstname.' '.$c->lastname)); ?>"><?php echo html_escape(trim($c->firstname.' '.$c->lastname)); ?> (#<?php echo (int)$c->staffid; ?>)</option><?php endforeach; ?></select><input type="hidden" name="full_name" id="fullname"><?php endif; ?></div>
        <div class="col-md-4 form-group"><label>Employee code</label><input class="form-control" name="employee_code" value="<?php echo html_escape($val('employee_code')); ?>"></div>
        <div class="col-md-4 form-group"><label>Employment type <span class="text-danger">*</span></label>
          <select class="form-control" name="employment_type" required>
            <?php foreach($types as $slug=>$lbl): ?><option value="<?php echo $slug; ?>" <?php echo $val('employment_type')===$slug?'selected':''; ?>><?php echo html_escape($lbl); ?></option><?php endforeach; ?>
          </select><p class="text-muted" style="font-size:11px">Eligibility defaults come from the type; override below. Changing type on an existing profile creates a new effective-dated version.</p></div>
      </div>
      <div class="row">
        <div class="col-md-4 form-group"><label>Official email</label><input class="form-control" name="official_email" value="<?php echo html_escape($val('official_email')); ?>"></div>
        <div class="col-md-4 form-group"><label>Official mobile</label><input class="form-control" name="official_mobile" value="<?php echo html_escape($val('official_mobile')); ?>"></div>
        <div class="col-md-4 form-group"><label>Department</label><input class="form-control" name="department" value="<?php echo html_escape($val('department')); ?>"></div>
      </div>
      <div class="row">
        <div class="col-md-3 form-group"><label>Designation</label><input class="form-control" name="designation" value="<?php echo html_escape($val('designation')); ?>"></div>
        <div class="col-md-3 form-group"><label>Branch</label><input class="form-control" name="branch" value="<?php echo html_escape($val('branch')); ?>"></div>
        <div class="col-md-3 form-group"><label>Territory</label><input class="form-control" name="territory" value="<?php echo html_escape($val('territory')); ?>"></div>
        <div class="col-md-3 form-group"><label>Reporting manager (staff #)</label><input class="form-control" name="reporting_manager_id" value="<?php echo html_escape($val('reporting_manager_id')); ?>"></div>
      </div>
      <div class="row">
        <div class="col-md-3 form-group"><label>Joining date</label><input class="form-control" type="date" name="joining_date" value="<?php echo html_escape($val('joining_date')); ?>"></div>
        <div class="col-md-3 form-group"><label>Probation/contract end</label><input class="form-control" type="date" name="probation_end_date" value="<?php echo html_escape($val('probation_end_date')); ?>"></div>
        <div class="col-md-3 form-group"><label>Payout frequency</label><input class="form-control" name="payout_frequency" value="<?php echo html_escape($val('payout_frequency','monthly')); ?>"></div>
        <div class="col-md-3 form-group"><label>Emergency contact</label><input class="form-control" name="emergency_contact" value="<?php echo html_escape($val('emergency_contact')); ?>"></div>
      </div>
      <hr><h6 style="font-weight:600">Eligibility (override type defaults)</h6>
      <div class="row">
        <?php $flags=array('salary_eligibility'=>'Salary','commission_eligibility'=>'Commission','expense_eligibility'=>'Expense','tada_eligibility'=>'TA/DA','attendance_required'=>'Attendance'); foreach($flags as $k=>$lbl): ?>
        <div class="col-md-2 form-group"><label style="font-size:12px"><?php echo $lbl; ?></label>
          <select class="form-control input-sm" name="<?php echo $k; ?>"><option value="">(type default)</option><option value="1" <?php echo $p&&(int)$val($k)===1?'selected':''; ?>>Yes</option><option value="0" <?php echo $p&&$val($k)!==''&&(int)$val($k)===0?'selected':''; ?>>No</option></select></div>
        <?php endforeach; ?>
      </div>
      <div class="row">
        <div class="col-md-3 form-group"><label>KYC status</label><select class="form-control" name="kyc_status"><?php foreach(array('pending','submitted','verified','rejected') as $o): ?><option value="<?php echo $o; ?>" <?php echo $val('kyc_status','pending')===$o?'selected':''; ?>><?php echo ucfirst($o); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 form-group"><label>PAN/Tax status</label><select class="form-control" name="pan_status"><?php foreach(array('pending','submitted','verified') as $o): ?><option value="<?php echo $o; ?>" <?php echo $val('pan_status','pending')===$o?'selected':''; ?>><?php echo ucfirst($o); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3 form-group"><label>Target/KPI plan</label><input class="form-control" name="target_plan" value="<?php echo html_escape($val('target_plan')); ?>"></div>
        <div class="col-md-3 form-group"><label>Commission plan</label><input class="form-control" name="commission_plan" value="<?php echo html_escape($val('commission_plan')); ?>"></div>
      </div>
      <p class="text-muted" style="font-size:12px">Bank details are captured & encrypted in the Bank-protection batch (maker-checker + cooling period). Financial payout stays blocked until KYC is verified and bank is verified.</p>
      <button class="btn btn-info" type="submit">Save profile</button>
      <?php echo form_close(); ?>
    </div></div>
  </div></div>
</div></div>
<script>
(function(){var s=document.getElementById('staffsel');if(s){s.addEventListener('change',function(){var o=s.options[s.selectedIndex];document.getElementById('fullname').value=o?o.getAttribute('data-name'):'';});}})();
</script>
<?php init_tail(); ?>
</body></html>
