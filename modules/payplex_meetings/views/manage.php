<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
  <div class="content">
    <div class="row">
      <div class="col-md-12">
        <div class="panel_s">
          <div class="panel-body">
            <h4 class="no-margin"><?php echo html_escape($title); ?></h4>

            <?php if (!empty($pending)) { ?>
              <p class="pm-muted"><?php echo pm_lang('pm_pending_completion_help'); ?></p>
            <?php } ?>

            <hr class="hr-panel-heading">

            <?php if (empty($meetings)) { ?>
              <p class="pm-empty"><?php echo pm_lang('pm_no_meetings'); ?></p>
            <?php } else { ?>
              <div class="table-responsive">
                <table class="table table-striped pm-table">
                  <thead>
                    <tr>
                      <th><?php echo pm_lang('pm_reference'); ?></th>
                      <th><?php echo pm_lang('pm_subject'); ?></th>
                      <th><?php echo pm_lang('pm_when'); ?></th>
                      <th><?php echo pm_lang('pm_type'); ?></th>
                      <th><?php echo pm_lang('pm_status'); ?></th>
                      <th><?php echo pm_lang('pm_outcome'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($meetings as $m) {
                      $s = isset($statuses[$m->status]) ? $statuses[$m->status] : null; ?>
                    <tr>
                      <td><a href="<?php echo admin_url('payplex_meetings/meetings/view/' . (int) $m->id); ?>">
                        <?php echo html_escape($m->reference_no); ?></a></td>
                      <td><?php echo html_escape($m->subject); ?></td>
                      <td><?php echo pm_from_utc($m->start_utc, $m->timezone, 'd M Y, g:i A'); ?>
                          <small class="pm-muted"><?php echo html_escape($m->timezone); ?></small></td>
                      <td><?php echo pm_lang('pm_type_' . $m->meeting_type); ?></td>
                      <td><span class="pm-badge" style="border-color:<?php echo $s ? html_escape($s['color']) : '#999'; ?>">
                        <?php echo $s ? pm_lang($s['label']) : html_escape($m->status); ?></span></td>
                      <td><?php echo $m->outcome ? html_escape($m->outcome) : '<span class="pm-muted">&mdash;</span>'; ?></td>
                    </tr>
                  <?php } ?>
                  </tbody>
                </table>
              </div>
            <?php } ?>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php init_tail(); ?>
</body>
</html>
