<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <p class="text-muted">
        Approving creates a CRM lead. You cannot approve a prospect you submitted
        yourself — that is the point of this screen, not a limitation of it.
      </p>

      <?php if (empty($rows)) { ?>
        <div class="alert alert-info">
          Nothing is waiting for approval.
        </div>
      <?php } else { ?>
      <div class="table-responsive">
      <table class="table table-striped">
        <thead><tr>
          <th>Business</th><th>City</th><th>Phone</th><th>Requirement</th>
          <th>Submitted by</th><th>Verified by</th><th>Waiting since</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r) {
            $isMine = (int) $r['submitted_by'] === (int) $actor; ?>
          <tr>
            <td><?php echo html_escape((string) $r['business_name']); ?></td>
            <td><?php echo html_escape((string) $r['city']); ?></td>
            <td>
              <?php echo !empty($r['phone_e164'])
                    ? html_escape(Payplex_phone::mask($r['phone_e164'])) : '&mdash;'; ?>
            </td>
            <td><?php echo html_escape((string) $r['requirement']); ?></td>
            <td><?php echo html_escape(get_staff_full_name($r['submitted_by'])); ?></td>
            <td><?php echo ((int) $r['verified_by'] > 0)
                  ? html_escape(get_staff_full_name($r['verified_by'])) : '&mdash;'; ?></td>
            <td><?php echo html_escape(date('Y-m-d H:i', (int) $r['submitted_at'])); ?></td>
            <td>
              <?php
              /*
               * The controls are hidden for your own submission and the model
               * refuses it as well. Hiding a button is a courtesy; the refusal
               * is the control.
               */
              if ($isMine) { ?>
                <span class="text-muted small">You submitted this. Somebody else has to decide it.</span>
              <?php } else { ?>
                <?php echo form_open(admin_url('payplex_leadfinder/finder/approve_conversion/' . (int) $r['prospect_id'])); ?>
                  <input type="text" name="reason" class="form-control input-sm mbot10"
                         placeholder="Reason (required to reject or send back)">
                  <button type="submit" name="decision" value="approve" class="btn btn-default btn-xs"
                          onclick="return confirm('Approve this prospect? A CRM lead will be created.');">
                    Approve
                  </button>
                  <button type="submit" name="decision" value="send_back" class="btn btn-default btn-xs">
                    Send back
                  </button>
                  <button type="submit" name="decision" value="reject" class="btn btn-default btn-xs"
                          onclick="return confirm('Reject this prospect? Your reason is recorded.');">
                    Reject
                  </button>
                <?php echo form_close(); ?>
              <?php } ?>
            </td>
          </tr>
        <?php } ?>
        </tbody>
      </table>
      </div>
      <?php } ?>

      <p class="text-muted" style="font-size:12px;font-weight:400;" translate="no">Google Maps</p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
