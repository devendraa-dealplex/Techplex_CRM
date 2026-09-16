<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$E = isset($entry) ? $entry : null;
$isEdit = $E !== null;
$url = $isEdit ? admin_url('payplex_ai_agents/knowledge/edit/' . (int) $E->id) : admin_url('payplex_ai_agents/knowledge/create');
function kv($E, $f, $d = '') { return $E && isset($E->$f) ? $E->$f : $d; }
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <?php echo form_open($url); ?>
    <div class="row">
      <div class="col-md-8"><div class="panel_s"><div class="panel-body">
        <h4 style="margin-top:0;color:#12507F"><?php echo $isEdit ? 'Edit Knowledge' : 'Add Knowledge'; ?></h4>
        <div class="form-group"><label>Title *</label><input class="form-control" name="title" required value="<?php echo html_escape(kv($E,'title')); ?>"></div>
        <div class="row">
          <div class="col-md-6"><div class="form-group"><label>Category</label>
            <select class="form-control" name="category">
              <?php foreach ($categories as $c): ?>
                <option value="<?php echo html_escape($c); ?>" <?php echo kv($E,'category') === $c ? 'selected' : ''; ?>><?php echo ucfirst($c); ?></option>
              <?php endforeach; ?>
            </select>
          </div></div>
          <div class="col-md-6"><div class="form-group"><label>Scope (agent ids, comma-separated; blank = all)</label>
            <input class="form-control" name="scope" placeholder="all" value="<?php echo html_escape(($E && $E->scope !== 'all') ? (string) $E->scope : ''); ?>"></div></div>
        </div>
        <div class="form-group"><label>Content *</label><textarea class="form-control" name="content" rows="8" required><?php echo html_escape(kv($E,'content')); ?></textarea></div>
        <div class="form-group"><label>Keywords (comma or space separated - improves matching)</label><input class="form-control" name="keywords" value="<?php echo html_escape(kv($E,'keywords')); ?>"></div>
      </div></div></div>
      <div class="col-md-4">
        <div class="panel_s"><div class="panel-body">
          <button class="btn btn-primary btn-block" type="submit"><?php echo $isEdit ? 'Save (new version)' : 'Add & index'; ?></button>
          <a href="<?php echo admin_url('payplex_ai_agents/knowledge'); ?>" class="btn btn-default btn-block">Cancel</a>
          <?php if ($isEdit): ?>
            <hr>
            <p><strong>Indexing:</strong> <?php echo html_escape((string) $E->indexing_status); ?> · <strong>v</strong><?php echo (int) $E->version; ?></p>
            <a href="<?php echo admin_url('payplex_ai_agents/knowledge/reindex/' . (int) $E->id); ?>" class="btn btn-default btn-sm btn-block">Re-index</a>
          <?php endif; ?>
        </div></div>
        <?php if ($isEdit && !empty($versions)): ?>
          <div class="panel_s"><div class="panel-body">
            <h5 style="margin-top:0">Version history</h5>
            <table class="table" style="font-size:12px">
              <thead><tr><th>Ver</th><th>Note</th><th>When</th></tr></thead>
              <tbody>
                <?php foreach ($versions as $v): ?>
                  <tr><td><?php echo (int) $v->version; ?></td><td><?php echo html_escape((string) $v->note); ?></td><td><?php echo html_escape((string) $v->datecreated); ?></td></tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php echo form_close(); ?>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
