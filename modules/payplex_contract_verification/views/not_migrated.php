<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-12">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
      <div class="alert alert-warning">
        <strong>This module's tables are not present.</strong>
        It does not install itself: the migrations are applied deliberately, by a person, after a
        read-only preflight. Nothing has been changed.
      </div>
      <p>Missing tables:</p>
      <ul>
        <?php foreach ($missing as $m) { ?>
          <li><code><?php echo html_escape($m); ?></code></li>
        <?php } ?>
      </ul>
      <p>Apply, in order:</p>
      <ol>
        <?php foreach ($migrations as $m) { ?>
          <li><code><?php echo html_escape($m); ?></code></li>
        <?php } ?>
      </ol>
      <p class="text-muted small">
        Each carries a read-only preflight. Run it first &mdash; every migration has at least one
        check whose required answer is non-zero, so a predicate that matches nothing fails the gate
        rather than passing it.
      </p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
