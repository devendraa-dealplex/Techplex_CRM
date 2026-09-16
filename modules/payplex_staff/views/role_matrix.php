<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head();
$actions=array('view_own'=>'View own','view_any'=>'View any','create'=>'Create','edit'=>'Edit','verify'=>'Verify','approve'=>'Approve','run'=>'Export'); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <h4 style="color:#12507F;font-weight:600">Role Permission Matrix</h4>
    <div class="alert alert-info" style="font-size:12px">Server-side authority for the 10 roles across every domain. Maker≠approver is structural: finance makers/checkers cannot approve payouts; only finance approvers can. Auditors are read-only.</div>
    <div class="table-responsive"><table class="table table-bordered" style="font-size:11px">
      <thead><tr><th>Domain</th><?php foreach($roles as $r): ?><th style="writing-mode:vertical-rl;transform:rotate(180deg);white-space:nowrap"><?php echo html_escape($r); ?></th><?php endforeach; ?></tr></thead>
      <tbody>
      <?php foreach($domains as $d): ?>
        <tr><td><strong><?php echo html_escape($d); ?></strong></td>
        <?php foreach($roles as $r):
          $caps=array(); foreach($actions as $ak=>$al){ if(Payplex_staff_perms::can($r,$d,$ak)) $caps[]=substr($al,0,1); }
          $txt=implode('',$caps); ?>
          <td style="text-align:center;background:<?php echo $txt?'#eaf7ea':'#faf0f0'; ?>"><?php echo $txt?:'·'; ?></td>
        <?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <p class="text-muted" style="font-size:11px">Legend: V=View own · V=View any · C=Create · E=Edit · V=Verify · A=Approve · E=Export (first letter per granted action).</p>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
