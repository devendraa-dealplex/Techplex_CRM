<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>

      <?php
      /*
       * Alerts first, because the reason an administrator opens this screen is
       * usually that something is already wrong. Critical before warning, and
       * each one says what to do rather than only what happened.
       */
      if (!empty($alerts)) { ?>
        <?php foreach ($alerts as $a) { ?>
          <div class="alert alert-<?php echo $a['level'] === 'critical' ? 'danger' : 'warning'; ?>">
            <strong><?php echo html_escape(ucfirst(str_replace('_', ' ', $a['kind']))); ?>:</strong>
            <?php echo html_escape($a['message']); ?>
          </div>
        <?php } ?>
      <?php } ?>

      <div class="row mbot15">
        <div class="col-md-12">
          <?php
          $grouped = array();
          foreach ($definitions as $rid => $def) { $grouped[$def['group']][$rid] = $def; }
          foreach ($grouped as $group => $items) { ?>
            <p class="text-muted mbot5"><strong><?php echo html_escape($group); ?></strong></p>
            <p>
              <?php foreach ($items as $rid => $def) { ?>
                <a class="btn btn-<?php echo $selected === $rid ? 'info' : 'default'; ?> btn-xs mbot5"
                   href="<?php echo admin_url('payplex_leadfinder/finder/reports?report=' . rawurlencode($rid)); ?>">
                  <?php echo html_escape($def['title']); ?>
                </a>
              <?php } ?>
            </p>
          <?php } ?>
        </div>
      </div>

      <?php if ($selected !== '') { ?>
        <form method="get" class="mbot15">
          <input type="hidden" name="report" value="<?php echo html_escape($selected); ?>">
          <input type="date" name="from" class="form-control" style="max-width:180px;display:inline-block;"
                 value="<?php echo html_escape($filters['from']); ?>">
          <input type="date" name="to" class="form-control" style="max-width:180px;display:inline-block;"
                 value="<?php echo html_escape($filters['to']); ?>">
          <input type="number" name="staff_id" class="form-control" style="max-width:120px;display:inline-block;"
                 placeholder="Staff id" min="0"
                 value="<?php echo (int) $filters['staff_id'] > 0 ? (int) $filters['staff_id'] : ''; ?>">
          <input type="text" name="source" class="form-control" style="max-width:160px;display:inline-block;"
                 placeholder="Source" value="<?php echo html_escape($filters['source']); ?>">
          <button type="submit" class="btn btn-default">Run</button>

          <?php if (Leadfinder_reports::isExportable($selected)) { ?>
            <a class="btn btn-default"
               href="<?php echo admin_url('payplex_leadfinder/finder/report_export?report='
                     . rawurlencode($selected) . '&from=' . rawurlencode($filters['from'])
                     . '&to=' . rawurlencode($filters['to'])
                     . '&staff_id=' . (int) $filters['staff_id']); ?>">
              Export CSV
            </a>
          <?php } else { ?>
            <span class="text-muted small">
              This report cannot be exported: it lists who tried to reach what they
              could not, which is not material to carry out of the system.
            </span>
          <?php } ?>
        </form>

        <?php if (!empty($result) && empty($result['ok'])) { ?>
          <div class="alert alert-warning">
            <?php
            /*
             * "Not installed" and "bad filters" are different answers and must
             * read differently. An empty grid for a report whose tables do not
             * exist would say "there is no activity", which is a claim about the
             * business rather than about the database.
             */
            if (isset($result['reason']) && $result['reason'] === 'not_installed') {
                echo 'This report is not available on this database yet: it needs a '
                   . 'migration that has not been applied.';
            } elseif (!empty($result['errors'])) {
                echo html_escape('Those filters are not usable: ' . implode('; ', $result['errors']) . '.');
            } else {
                echo 'That report could not be run.';
            } ?>
          </div>
        <?php } elseif (!empty($result) && empty($result['rows'])) { ?>
          <div class="alert alert-info">
            No rows for that period. That means no activity was recorded, not that
            the report failed.
          </div>
        <?php } elseif (!empty($result)) { ?>
          <?php if (empty($may_see_contact)) { ?>
            <p class="text-muted small">
              Contact details are masked. You are seeing counts and outcomes, not
              phone numbers or addresses.
            </p>
          <?php } ?>
          <div class="table-responsive">
          <table class="table table-striped">
            <thead><tr>
              <?php foreach ($result['columns'] as $col) {
                  if (in_array($col, Leadfinder_reports::forbiddenColumns(), true)) { continue; } ?>
                <th><?php echo html_escape(ucfirst(str_replace('_', ' ', $col))); ?></th>
              <?php } ?>
            </tr></thead>
            <tbody>
            <?php foreach ($result['rows'] as $row) {
                $safe = Leadfinder_reports::redactRow($row, !empty($may_see_contact)); ?>
              <tr>
                <?php foreach ($result['columns'] as $col) {
                    if (in_array($col, Leadfinder_reports::forbiddenColumns(), true)) { continue; } ?>
                  <td><?php echo html_escape((string) (isset($safe[$col]) ? $safe[$col] : '')); ?></td>
                <?php } ?>
              </tr>
            <?php } ?>
            </tbody>
          </table>
          </div>
          <p class="text-muted small">
            <?php echo count($result['rows']); ?> rows.
            Figures exclude nothing unless a filter above says so; simulated
            records are counted separately where the report has a column for them.
          </p>
        <?php } ?>
      <?php } ?>

      <p class="text-muted" style="font-size:12px;font-weight:400;" translate="no">Google Maps</p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
