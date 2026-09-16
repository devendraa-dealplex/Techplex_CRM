<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
  <div class="content">
    <div class="row">

      <div class="col-md-8">
        <div class="panel_s"><div class="panel-body">
          <?php $s = isset($statuses[$meeting->status]) ? $statuses[$meeting->status] : null; ?>
          <h4 class="no-margin"><?php echo html_escape($meeting->subject); ?></h4>
          <p class="pm-muted">
            <?php echo html_escape($meeting->reference_no); ?>
            <span class="pm-badge" style="border-color:<?php echo $s ? html_escape($s['color']) : '#999'; ?>">
              <?php echo $s ? pm_lang($s['label']) : html_escape($meeting->status); ?></span>
          </p>

          <hr class="hr-panel-heading">

          <dl class="pm-dl">
            <dt><?php echo pm_lang('pm_when'); ?></dt>
            <dd><?php echo pm_from_utc($meeting->start_utc, $meeting->timezone, 'd M Y, g:i A'); ?>
                &ndash; <?php echo pm_from_utc($meeting->end_utc, $meeting->timezone, 'g:i A'); ?>
                (<?php echo html_escape($meeting->timezone); ?>,
                <?php echo (int) $meeting->duration_minutes; ?> <?php echo pm_lang('pm_minutes'); ?>)</dd>

            <dt><?php echo pm_lang('pm_type'); ?></dt>
            <dd><?php echo pm_lang('pm_type_' . $meeting->meeting_type); ?></dd>

            <?php if ($meeting->location_type === 'online' && $meeting->meeting_link) { ?>
              <dt><?php echo pm_lang('pm_join_link'); ?></dt>
              <dd><a href="<?php echo html_escape($meeting->meeting_link); ?>"
                     target="_blank" rel="noopener noreferrer">
                  <?php echo html_escape($meeting->meeting_link); ?></a></dd>
            <?php } elseif ($meeting->location_address) { ?>
              <dt><?php echo pm_lang('pm_address'); ?></dt>
              <dd><?php echo nl2br(html_escape($meeting->location_address)); ?></dd>
            <?php } ?>

            <?php if ($meeting->agenda) { ?>
              <dt><?php echo pm_lang('pm_agenda'); ?></dt>
              <dd><?php echo nl2br(html_escape($meeting->agenda)); ?></dd>
            <?php } ?>
          </dl>

          <a class="btn btn-default btn-sm"
             href="<?php echo admin_url('payplex_meetings/meetings/ics/' . (int) $meeting->id); ?>">
            <?php echo pm_lang('pm_download_ics'); ?></a>

          <?php /* Confidential fields are gated by a distinct capability, not by any
                   view permission. A user who may see the meeting still may not see these. */ ?>
          <?php if ($can_confidential && ($meeting->conflict_override_reason || $meeting->cancel_reason || $meeting->reschedule_reason)) { ?>
            <div class="pm-confidential">
              <h5><?php echo pm_lang('pm_internal_only'); ?></h5>
              <?php if ($meeting->conflict_override_reason) { ?>
                <p><b><?php echo pm_lang('pm_override_reason'); ?>:</b>
                   <?php echo html_escape($meeting->conflict_override_reason); ?></p>
              <?php } ?>
              <?php if ($meeting->reschedule_reason) { ?>
                <p><b><?php echo pm_lang('pm_reschedule_reason'); ?>:</b>
                   <?php echo html_escape($meeting->reschedule_reason); ?></p>
              <?php } ?>
              <?php if ($meeting->cancel_reason) { ?>
                <p><b><?php echo pm_lang('pm_cancel_reason'); ?>:</b>
                   <?php echo html_escape($meeting->cancel_reason); ?></p>
              <?php } ?>
            </div>
          <?php } ?>
        </div></div>

        <div class="panel_s"><div class="panel-body">
          <h5><?php echo pm_lang('pm_reminder_schedule'); ?></h5>
          <?php if (empty($reminders)) { ?>
            <p class="pm-muted"><?php echo pm_lang('pm_no_reminders'); ?></p>
          <?php } else { ?>
            <div class="table-responsive"><table class="table table-condensed pm-table">
              <thead><tr>
                <th><?php echo pm_lang('pm_fires_at'); ?></th>
                <th><?php echo pm_lang('pm_offset'); ?></th>
                <th><?php echo pm_lang('pm_channel'); ?></th>
                <th><?php echo pm_lang('pm_status'); ?></th>
                <th><?php echo pm_lang('pm_attempts'); ?></th>
              </tr></thead>
              <tbody>
              <?php foreach ($reminders as $rm) { ?>
                <tr>
                  <td><?php echo pm_from_utc($rm->scheduled_utc, $meeting->timezone, 'd M, g:i A'); ?></td>
                  <td><?php echo (int) $rm->offset_minutes >= 60
                        ? round($rm->offset_minutes / 60, 1) . ' h'
                        : (int) $rm->offset_minutes . ' min'; ?> before</td>
                  <td><?php echo html_escape($rm->channel); ?></td>
                  <td><?php echo html_escape($rm->status); ?>
                      <?php if ($rm->last_error) { ?>
                        <small class="pm-muted"><?php echo html_escape(substr($rm->last_error, 0, 120)); ?></small>
                      <?php } ?></td>
                  <td><?php echo (int) $rm->attempts; ?></td>
                </tr>
              <?php } ?>
              </tbody>
            </table></div>
          <?php } ?>
        </div></div>

        <div class="panel_s"><div class="panel-body">
          <h5><?php echo pm_lang('pm_delivery_log'); ?></h5>
          <?php if (empty($deliveries)) { ?>
            <p class="pm-muted"><?php echo pm_lang('pm_no_deliveries'); ?></p>
          <?php } else { ?>
            <div class="table-responsive"><table class="table table-condensed pm-table">
              <thead><tr>
                <th><?php echo pm_lang('pm_recipient'); ?></th>
                <th><?php echo pm_lang('pm_template'); ?></th>
                <th><?php echo pm_lang('pm_version'); ?></th>
                <th><?php echo pm_lang('pm_sent'); ?></th>
                <th><?php echo pm_lang('pm_status'); ?></th>
              </tr></thead>
              <tbody>
              <?php foreach ($deliveries as $d) { ?>
                <tr>
                  <td><?php echo html_escape($d->recipient_email); ?></td>
                  <td><?php echo html_escape($d->template_slug); ?></td>
                  <td><?php echo html_escape($d->version_sent); ?></td>
                  <td><?php echo $d->sent_utc ? pm_from_utc($d->sent_utc, $meeting->timezone, 'd M, g:i A') : '&mdash;'; ?></td>
                  <td><?php echo html_escape($d->delivery_status); ?>
                      <?php if ($d->failure_reason) { ?>
                        <small class="pm-muted"><?php echo html_escape($d->failure_reason); ?></small>
                      <?php } ?></td>
                </tr>
              <?php } ?>
              </tbody>
            </table></div>
          <?php } ?>
        </div></div>
      </div>

      <div class="col-md-4">
        <div class="panel_s"><div class="panel-body">
          <h5><?php echo pm_lang('pm_participants'); ?></h5>
          <ul class="pm-plain-list">
            <?php foreach ($participants as $p) { ?>
              <li>
                <b><?php echo html_escape($p->name ?: $p->email); ?></b>
                <small class="pm-muted"><?php echo html_escape($p->party_type); ?> ·
                  <?php echo html_escape($p->role); ?></small>
              </li>
            <?php } ?>
          </ul>
        </div></div>

        <div class="panel_s"><div class="panel-body">
          <h5><?php echo pm_lang('pm_audit_trail'); ?></h5>
          <ul class="pm-plain-list pm-audit">
            <?php foreach ($activity as $a) { ?>
              <li>
                <code><?php echo html_escape($a->action); ?></code>
                <small class="pm-muted"><?php echo html_escape($a->date_created); ?>
                  <?php if ($a->ip_address) { ?>· <?php echo html_escape($a->ip_address); ?><?php } ?></small>
                <?php if ($a->reason) { ?>
                  <div class="pm-muted"><?php echo html_escape($a->reason); ?></div>
                <?php } ?>
              </li>
            <?php } ?>
          </ul>
        </div></div>
      </div>

    </div>
  </div>
</div>

<?php init_tail(); ?>
</body>
</html>
