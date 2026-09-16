<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); $e = $entry; $isEdit = (bool) $e;
$val = function ($k, $d = '') use ($e) { return $e && isset($e->$k) ? html_escape($e->$k) : $d; }; ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-9">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600"><?php echo $isEdit ? 'Edit Executive Knowledge #' . (int) $e->id : 'New Executive Knowledge'; ?></h4>
        <a href="<?php echo admin_url('payplex_ai_agents/execknowledge'); ?>" class="btn btn-default btn-sm">Back</a>
      </div>
      <div class="panel_s"><div class="panel-body">
        <?php echo form_open($isEdit ? admin_url('payplex_ai_agents/execknowledge/edit/' . (int) $e->id) : admin_url('payplex_ai_agents/execknowledge/store')); ?>
          <div class="form-group"><label>Title <span class="text-danger">*</span></label>
            <input class="form-control" name="title" maxlength="200" required value="<?php echo $val('title'); ?>"></div>
          <div class="form-group"><label>Body / fact <span class="text-danger">*</span></label>
            <textarea class="form-control" name="body" rows="6" required><?php echo $e ? html_escape($e->body) : ''; ?></textarea></div>
          <div class="row">
            <div class="col-md-4 form-group"><label>Category</label>
              <select class="form-control" name="category">
                <?php foreach ($categories as $c): ?><option value="<?php echo $c; ?>" <?php echo ($e && $e->category===$c)?'selected':''; ?>><?php echo ucfirst($c); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4 form-group"><label>Company (blank = group-level)</label>
              <select class="form-control" name="company">
                <option value="">— group-level —</option>
                <?php foreach ($companies as $co): ?><option value="<?php echo html_escape($co->code); ?>" <?php echo ($e && $e->company===$co->code)?'selected':''; ?>><?php echo html_escape($co->name); ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-4 form-group"><label>Confidence (0–1)</label>
              <input class="form-control" name="confidence" type="number" step="0.05" min="0" max="1" value="<?php echo $e ? html_escape($e->confidence) : '0.5'; ?>"></div>
          </div>
          <div class="row">
            <div class="col-md-6 form-group"><label>Source (provenance)</label>
              <input class="form-control" name="source" maxlength="255" placeholder="e.g. Board memo 2026-Q2" value="<?php echo $val('source'); ?>"></div>
            <div class="col-md-6 form-group"><label>Source URL (optional)</label>
              <input class="form-control" name="url" maxlength="500" placeholder="https://..." value="<?php echo $val('url'); ?>"></div>
          </div>
          <div class="row">
            <div class="col-md-4 form-group"><label>Effective from</label>
              <input class="form-control" name="effective_from" type="date" value="<?php echo $val('effective_from'); ?>"></div>
            <div class="col-md-4 form-group"><label>Effective to</label>
              <input class="form-control" name="effective_to" type="date" value="<?php echo $val('effective_to'); ?>"></div>
            <div class="col-md-4 form-group"><label>Tags (comma-separated)</label>
              <input class="form-control" name="tags" value="<?php echo $val('tags'); ?>"></div>
          </div>
          <p class="text-muted" style="font-size:12px">Saved as a <strong>draft</strong>. Submit it for review from the entry page; a different person must approve it before agents can cite it.</p>
          <button class="btn btn-info" type="submit"><?php echo $isEdit ? 'Save changes' : 'Create draft'; ?></button>
        <?php echo form_close(); ?>
      </div></div>
    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
