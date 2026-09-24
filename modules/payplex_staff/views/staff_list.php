<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $s=$summary; ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
      <h4 class="no-margin" style="color:#12507F;font-weight:600">Staff Classification</h4>
      <div>
        <?php if ($canManage): ?>
        <a href="<?php echo admin_url('payplex_staff/staff/backfill'); ?>" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Backfill existing staff</a>
        <a href="<?php echo admin_url('payplex_staff/staff/form'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> Classify Staff</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="alert alert-info" style="font-size:12px">Rich engagement classification with per-type salary / commission / expense / TA-DA / attendance eligibility, a full staff lifecycle and maker≠approver approval. Existing staff are preserved and flagged <strong>Classification Required</strong> until an admin completes them; financial eligibility stays OFF until classified and KYC/bank are verified.</div>

    <div class="row">
      <?php $tiles=array(array('Staff profiles',$s['total'],'#12507F',''),array('Classification required',$s['classification_required'],'#f0ad4e','1'),array('Active',$s['active'],'#5cb85c',''),array('Commission-eligible',$s['commission_eligible'],'#12507F','')); foreach($tiles as $t): ?>
        <div class="col-md-3 col-xs-6" style="margin-bottom:10px">
          <a href="<?php echo admin_url('payplex_staff/staff'.($t[3]?'?needs_class=1':'')); ?>" style="text-decoration:none">
            <div class="panel_s" style="border-top:3px solid <?php echo $t[2]; ?>"><div class="panel-body" style="text-align:center">
              <div style="font-size:24px;font-weight:700;color:<?php echo $t[2]; ?>"><?php echo (int)$t[1]; ?></div>
              <div class="text-muted" style="font-size:11px"><?php echo $t[0]; ?></div>
            </div></div></a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="table-responsive"><table class="table table-bordered" style="font-size:12px">
      <thead><tr><th>Staff</th><th>Type</th><th>Dept / Branch</th><th>Elig (Sal/Com/Exp/TA-DA)</th><th>KYC</th><th>Bank</th><th>Status</th><th>Flag</th><th></th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="9" class="text-muted">No profiles yet.</td></tr>
      <?php else: foreach($rows as $r): $sc=Payplex_staff_lifecycle::statusClass($r->status); ?>
        <tr>
          <td><strong><?php echo html_escape($r->full_name); ?></strong><br><span class="text-muted">#<?php echo (int)$r->staff_id; ?><?php echo $r->employee_code?' · '.html_escape($r->employee_code):''; ?></span></td>
          <td><?php echo html_escape(Payplex_staff_types::label($r->employment_type)); ?><?php echo (int)$r->version>1?' <span class="label label-default">v'.(int)$r->version.'</span>':''; ?></td>
          <td><?php echo html_escape($r->department?:'—'); ?><?php echo $r->branch?' / '.html_escape($r->branch):''; ?></td>
          <td><?php echo ((int)$r->salary_eligibility?'S':'–').' '.((int)$r->commission_eligibility?'C':'–').' '.((int)$r->expense_eligibility?'E':'–').' '.((int)$r->tada_eligibility?'T':'–'); ?></td>
          <td><span class="label label-<?php echo $r->kyc_status==='verified'?'success':'warning'; ?>"><?php echo html_escape($r->kyc_status); ?></span></td>
          <td><?php echo (int)$r->bank_verified?'<span class="label label-success">verified</span>':'<span class="label label-default">no</span>'; ?></td>
          <td><span class="label label-<?php echo $sc; ?>"><?php echo html_escape($r->status); ?></span></td>
          <td><?php echo (int)$r->classification_required?'<span class="label label-warning">Classification Required</span>':''; ?></td>
          <td><a class="btn btn-primary btn-xs" href="<?php echo admin_url('payplex_staff/staff/view/'.(int)$r->staff_id); ?>">Open</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
