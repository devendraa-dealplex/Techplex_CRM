<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$csrf = function () { return array('name' => $this->security->get_csrf_token_name(), 'hash' => $this->security->get_csrf_hash()); };
$tok = $csrf();
$card = function ($slug, $role, $name, $dept, $desc, $badge, $suggest = '', $ref = '', $actions = '') use ($tok) {
    ob_start(); ?>
    <div class="col-md-4" style="margin-bottom:14px">
      <div class="panel_s" style="height:100%"><div class="panel-body">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:6px">
          <strong style="color:#12507F"><?php echo html_escape($name); ?></strong>
          <?php echo $badge; ?>
        </div>
        <?php if ($role && $role !== $name): ?><div class="text-muted" style="font-size:11px"><?php echo html_escape($role); ?></div><?php endif; ?>
        <div class="text-muted" style="font-size:11px;margin-bottom:6px"><?php echo html_escape($dept); ?></div>
        <p style="font-size:12px;min-height:48px"><?php echo html_escape(mb_substr((string) $desc, 0, 130)); ?><?php echo mb_strlen((string) $desc) > 130 ? '…' : ''; ?></p>
        <form method="post" action="<?php echo admin_url('payplex_ai_agents/templates/use_template/' . rawurlencode($slug)); ?>">
          <input type="hidden" name="<?php echo $tok['name']; ?>" value="<?php echo $tok['hash']; ?>">
          <?php if ($suggest): ?>
            <div class="form-group" style="margin-bottom:6px">
              <input class="form-control input-sm" name="custom_name" value="<?php echo html_escape($suggest); ?>" placeholder="Custom name (optional)">
            </div>
            <div class="form-group" style="margin-bottom:6px">
              <input class="form-control input-sm" name="agent_ref" value="<?php echo html_escape($ref); ?>" placeholder="Agent ID (optional)">
            </div>
          <?php endif; ?>
          <button class="btn btn-primary btn-sm btn-block" type="submit">Create Agent</button>
        </form>
        <?php if ($actions): ?><div style="margin-top:6px;display:flex;gap:6px"><?php echo $actions; ?></div><?php endif; ?>
      </div></div>
    </div>
    <?php return ob_get_clean();
};
?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">AI Agent Templates</h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
          <a href="<?php echo admin_url('payplex_ai_agents/templates/create'); ?>" class="btn btn-info btn-sm"><i class="fa fa-plus"></i> Create Template</a>
        </div>
      </div>

      <p class="text-muted" style="font-size:12px">Create an AI agent/employee from any template. Every agent starts in <strong>Sandbox</strong> as a draft and needs a different admin's approval before going live. Executive templates are role patterns only — no real individual is impersonated; names shown are generic suggestions you can change.</p>

      <ul class="nav nav-tabs" style="margin-bottom:14px">
        <li class="<?php echo $view === 'all' ? 'active' : ''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/templates'); ?>">All</a></li>
        <li class="<?php echo $view === 'executive' ? 'active' : ''; ?>"><a href="<?php echo admin_url('payplex_ai_agents/templates?view=executive'); ?>">Executive Council (18)</a></li>
      </ul>

      <!-- Executive templates -->
      <h5 style="font-weight:600">Executive (C-suite) Templates <span class="label label-primary"><?php echo count($executive); ?></span></h5>
      <div class="row">
        <?php foreach ($executive as $tpl):
          $slug = $tpl['template_slug'];
          $sug = isset($suggestions[$slug]) ? $suggestions[$slug] : array('suggested' => '', 'ref' => '');
          $badge = '<span class="label label-primary">' . html_escape($tpl['approval_tier']) . '</span>';
          echo $card($slug, $tpl['system_role'], $tpl['system_role'], $tpl['department'], $tpl['purpose'], $badge, $sug['suggested'], $sug['ref']);
        endforeach; ?>
      </div>

      <?php if ($view === 'all'): ?>
      <!-- Custom templates -->
      <h5 style="font-weight:600;margin-top:10px">Custom Templates <span class="label label-info"><?php echo count($custom); ?></span></h5>
      <?php if (empty($custom)): ?>
        <p class="text-muted" style="font-size:12px">No custom templates yet. Click <strong>Create Template</strong> above to define your own reusable AI agent blueprint.</p>
      <?php else: ?>
      <div class="row">
        <?php foreach ($custom as $c):
          $badge = '<span class="label label-primary">' . html_escape($c->approval_tier) . '</span>';
          $actions = '<a class="btn btn-default btn-xs" href="' . admin_url('payplex_ai_agents/templates/edit/' . (int) $c->id) . '">Edit</a>'
                   . form_open(admin_url('payplex_ai_agents/templates/destroy/' . (int) $c->id), array('style' => 'display:inline-block;margin:0', 'onsubmit' => "return confirm('Delete this template?');"))
                   . '<button class="btn btn-danger btn-xs" type="submit">Delete</button>' . form_close();
          echo $card($c->template_slug, $c->system_role, $c->name, (string) $c->department, (string) $c->purpose, $badge, '', '', $actions);
        endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Built-in operational templates -->
      <h5 style="font-weight:600;margin-top:10px">Built-in Operational Templates <span class="label label-default"><?php echo count($builtin); ?></span></h5>
      <div class="row">
        <?php foreach ($builtin as $tpl):
          $badge = '<span class="label label-default">sandbox</span>';
          echo $card($tpl['template_slug'], '', $tpl['name'], $tpl['department'], $tpl['purpose'], $badge);
        endforeach; ?>
      </div>
      <?php endif; ?>

    </div></div>
  </div>
</div>
<?php init_tail(); ?>
</body>
</html>
