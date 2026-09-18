<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>
<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-12"><div class="panel_s"><div class="panel-body">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px">
        <h4 class="no-margin" style="color:#12507F;font-weight:600">Knowledge Base <span class="text-muted" style="font-size:13px">(<?php echo count($entries); ?>)</span></h4>
        <div>
          <a href="<?php echo admin_url('payplex_ai_agents/knowledge/create'); ?>" class="btn btn-primary btn-sm">+ Add Knowledge</a>
          <a href="<?php echo admin_url('payplex_ai_agents/agents'); ?>" class="btn btn-default btn-sm">Back to Agents</a>
        </div>
      </div>
      <p class="text-muted" style="font-size:12px">Agents answer <strong>only</strong> from active + indexed entries that are permitted for them. A query with no confident permitted match is escalated to the Review Queue.</p>

      <div class="panel_s" style="background:#f4f8fb"><div class="panel-body">
        <strong>Test: answer from knowledge</strong>
        <?php echo form_open(admin_url('payplex_ai_agents/knowledge/ask'), array('id' => 'kb-ask-form', 'class' => 'form-inline', 'style' => 'margin-top:6px')); ?>
          <div class="form-group"><input class="form-control input-sm" name="agent_id" type="number" min="1" step="1" placeholder="Agent id" required style="width:90px"></div>
          <div class="form-group"><input class="form-control input-sm" id="kb-ask-query" name="query" placeholder="Customer question..." maxlength="<?php echo (int) Knowledge::ASK_QUERY_MAX_LENGTH; ?>" required style="width:340px"></div>
          <button class="btn btn-info btn-sm" type="submit">Ask</button>
          <span class="text-muted" style="font-size:11px;margin-left:6px" id="kb-ask-counter">0 / <?php echo (int) Knowledge::ASK_QUERY_MAX_LENGTH; ?></span>
        <?php echo form_close(); ?>
        <div id="kb-ask-result" class="panel_s" style="display:none;margin-top:10px;border-left:4px solid #ddd">
          <div class="panel-body" id="kb-ask-result-body"></div>
        </div>
      </div></div>

      <div class="table-responsive"><table class="table table-striped table-bordered">
        <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Scope</th><th>Indexing</th><th>Active</th><th>Ver</th><th>Updated</th><th></th></tr></thead>
        <tbody>
        <?php if (empty($entries)): ?>
          <tr><td colspan="9" class="text-muted">No knowledge yet. Add product info, FAQs, pricing, policies or scripts.</td></tr>
        <?php else: foreach ($entries as $e): ?>
          <tr>
            <td><?php echo (int) $e->id; ?></td>
            <td><a href="<?php echo admin_url('payplex_ai_agents/knowledge/edit/' . (int) $e->id); ?>"><?php echo html_escape($e->title); ?></a></td>
            <td><span class="label label-default"><?php echo html_escape((string) $e->category); ?></span></td>
            <td><?php echo ($e->scope === 'all' || $e->scope === '' || $e->scope === null) ? '<span class="text-muted">all agents</span>' : html_escape((string) $e->scope); ?></td>
            <td><?php echo $e->indexing_status === 'indexed' ? '<span class="label label-success">indexed</span>' : '<span class="label label-warning">' . html_escape((string) $e->indexing_status) . '</span>'; ?></td>
            <td><?php echo (int) $e->is_active === 1 ? '<span class="label label-success">yes</span>' : '<span class="label label-default">no</span>'; ?></td>
            <td><?php echo (int) $e->version; ?></td>
            <td style="font-size:12px"><?php echo html_escape((string) $e->lastupdated); ?></td>
            <td style="white-space:nowrap">
              <a href="<?php echo admin_url('payplex_ai_agents/knowledge/edit/' . (int) $e->id); ?>" class="btn btn-default btn-xs">Edit</a>
              <a href="<?php echo admin_url('payplex_ai_agents/knowledge/toggle/' . (int) $e->id); ?>" class="btn btn-default btn-xs"><?php echo (int) $e->is_active === 1 ? 'Disable' : 'Enable'; ?></a>
              <a href="<?php echo admin_url('payplex_ai_agents/knowledge/destroy/' . (int) $e->id); ?>" class="btn btn-danger btn-xs" onclick="return confirm('Delete this knowledge entry? This cannot be undone.');">Delete</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table></div>
    </div></div></div></div>
  </div>
</div>
<?php init_tail(); ?>
<script>
    $(function() {
        var $form    = $('#kb-ask-form');
        var $result  = $('#kb-ask-result');
        var $body    = $('#kb-ask-result-body');
        var $query   = $('#kb-ask-query');
        var $counter = $('#kb-ask-counter');
        var maxLen   = parseInt($query.attr('maxlength'), 10) || 500;

        function updateCounter() {
            var len = $query.val().length;
            $counter.text(len + ' / ' + maxLen);
            $counter.css('color', len > maxLen ? '#a94442' : '');
        }
        $query.on('input', updateCounter);
        updateCounter();

        $form.on('submit', function(e) {
            e.preventDefault();
            var $btn = $form.find('button[type="submit"]');
            $btn.prop('disabled', true).text('Asking...');
            $body.empty();
            $result.hide();

            $.post($form.attr('action'), $form.serialize(), function(res) {
                $body.empty();
                if (res.error) {
                    $result.css('border-left-color', '#a94442').show();
                    $body.append($('<span>').addClass('label label-danger').text('Invalid request'));
                    $body.append(
                        $('<p>').addClass('text-muted').css({'font-size': '12px', 'margin-top': '6px'}).text(res.message || 'Please check the form and try again.')
                    );
                } else if (res.hit) {
                    $result.css('border-left-color', '#3c763d').show();
                    $body.append(
                        $('<span>').addClass('label label-success').text('Answered'),
                        ' ',
                        $('<span>').addClass('text-muted').css('font-size', '12px').text('score ' + res.score)
                    );
                    $body.append($('<h5>').css({margin: '8px 0 4px'}).text(res.title || ''));
                    if (res.category) {
                        $body.append($('<span>').addClass('label label-default').text(res.category));
                    }
                    $body.append($('<div>').css({'white-space': 'pre-wrap', 'font-size': '13px', 'margin-top': '6px'}).text(res.content || ''));
                } else {
                    $result.css('border-left-color', '#8a6d3b').show();
                    $body.append($('<span>').addClass('label label-warning').text('No confident permitted answer'));
                    $body.append(
                        $('<p>').addClass('text-muted').css({'font-size': '12px', 'margin-top': '6px'})
                            .text('Score ' + res.score + ' · reason: ' + (res.reason || 'n/a') + '. Escalated to the Review Queue.')
                    );
                }
            }, 'json').fail(function() {
                $body.empty().append($('<span>').addClass('label label-danger').text('Error')).append(
                    $('<p>').addClass('text-muted').css({'font-size': '12px', 'margin-top': '6px'}).text('Could not reach the server. Please try again.')
                );
                $result.css('border-left-color', '#a94442').show();
            }).always(function() {
                $btn.prop('disabled', false).text('Ask');
            });
        });
    });
</script>
</body>
</html>
