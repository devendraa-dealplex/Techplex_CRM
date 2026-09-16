<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper"><div class="content">
  <div class="row"><div class="col-md-8 col-md-offset-2">
    <div class="panel_s"><div class="panel-body">
      <h4 class="no-mtop"><?php echo html_escape($title); ?></h4>
      <p>
        This module does not create its own tables. Its schema is applied by an
        authorised operator from versioned migration files, so seeing this page
        means the migrations have not been applied to this database yet.
      </p>
      <p><strong>Missing tables:</strong></p>
      <ul>
        <?php foreach ($missing as $m) { ?>
          <li><code><?php echo html_escape($m); ?></code></li>
        <?php } ?>
      </ul>
      <p><strong>Apply, in order:</strong></p>
      <ol>
        <?php foreach ($migrations as $f) { ?>
          <li><code><?php echo html_escape($f); ?></code></li>
        <?php } ?>
      </ol>
      <p class="text-muted">
        Migration 103 alters a core table and must not be applied until its
        read-only preflight reports zero duplicate pairs.
      </p>
    </div></div>
  </div></div>
</div></div>
<?php init_tail(); ?>
</body></html>
