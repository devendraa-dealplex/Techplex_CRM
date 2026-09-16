<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); ?>

<div id="wrapper">
  <div class="content">
    <div class="row"><div class="col-md-9">
      <div class="panel_s"><div class="panel-body">
        <h4 class="no-margin"><?php echo pm_lang('pm_settings'); ?></h4>
        <hr class="hr-panel-heading">

        <?php echo form_open(admin_url('payplex_meetings/meetings/settings')); ?>

          <h5><?php echo pm_lang('pm_sect_scheduling'); ?></h5>
          <div class="row">
            <div class="col-md-4 form-group">
              <label for="default_timezone"><?php echo pm_lang('pm_company_timezone'); ?></label>
              <select class="form-control" name="default_timezone" id="default_timezone">
                <?php foreach (timezone_identifiers_list() as $tz) { ?>
                  <option value="<?php echo html_escape($tz); ?>"
                    <?php echo pm_setting('default_timezone') === $tz ? 'selected' : ''; ?>>
                    <?php echo html_escape($tz); ?></option>
                <?php } ?>
              </select>
            </div>
            <div class="col-md-2 form-group">
              <label for="default_duration"><?php echo pm_lang('pm_default_duration'); ?></label>
              <input type="number" min="5" max="480" class="form-control" name="default_duration"
                     id="default_duration" value="<?php echo html_escape(pm_setting('default_duration', 30)); ?>">
            </div>
            <div class="col-md-3 form-group">
              <label for="working_hours_start"><?php echo pm_lang('pm_working_from'); ?></label>
              <input type="time" class="form-control" name="working_hours_start" id="working_hours_start"
                     value="<?php echo html_escape(pm_setting('working_hours_start', '09:00')); ?>">
            </div>
            <div class="col-md-3 form-group">
              <label for="working_hours_end"><?php echo pm_lang('pm_working_to'); ?></label>
              <input type="time" class="form-control" name="working_hours_end" id="working_hours_end"
                     value="<?php echo html_escape(pm_setting('working_hours_end', '20:00')); ?>">
            </div>
          </div>

          <div class="form-group">
            <label for="working_days"><?php echo pm_lang('pm_working_days'); ?></label>
            <input type="text" class="form-control" name="working_days" id="working_days"
                   value="<?php echo html_escape(pm_setting('working_days', '1,2,3,4,5,6')); ?>">
            <small class="pm-muted"><?php echo pm_lang('pm_working_days_help'); ?></small>
          </div>

          <div class="form-group">
            <label for="holidays"><?php echo pm_lang('pm_holidays'); ?></label>
            <textarea class="form-control" name="holidays" id="holidays" rows="2"
              placeholder="2026-10-02, 2026-11-01"><?php echo html_escape(pm_setting('holidays', '')); ?></textarea>
          </div>

          <h5><?php echo pm_lang('pm_sect_reminders'); ?></h5>
          <div class="row">
            <div class="col-md-4 form-group">
              <label for="reminder_offsets"><?php echo pm_lang('pm_reminder_offsets'); ?></label>
              <input type="text" class="form-control" name="reminder_offsets" id="reminder_offsets"
                     value="<?php echo html_escape(pm_setting('reminder_offsets', '1440,120,30')); ?>">
              <small class="pm-muted"><?php echo pm_lang('pm_reminder_offsets_help'); ?></small>
            </div>
            <div class="col-md-3 form-group">
              <label for="reminder_channels"><?php echo pm_lang('pm_reminder_channels'); ?></label>
              <input type="text" class="form-control" name="reminder_channels" id="reminder_channels"
                     value="<?php echo html_escape(pm_setting('reminder_channels', 'email,crm')); ?>">
            </div>
            <div class="col-md-2 form-group">
              <label for="retry_max"><?php echo pm_lang('pm_retry_max'); ?></label>
              <input type="number" min="1" max="10" class="form-control" name="retry_max" id="retry_max"
                     value="<?php echo html_escape(pm_setting('retry_max', 3)); ?>">
            </div>
            <div class="col-md-3 form-group">
              <label for="missed_window_minutes"><?php echo pm_lang('pm_missed_window'); ?></label>
              <input type="number" min="1" max="240" class="form-control" name="missed_window_minutes"
                     id="missed_window_minutes"
                     value="<?php echo html_escape(pm_setting('missed_window_minutes', 15)); ?>">
              <small class="pm-muted"><?php echo pm_lang('pm_missed_window_help'); ?></small>
            </div>
          </div>

          <h5><?php echo pm_lang('pm_sect_rules'); ?></h5>
          <?php
          $toggles = [
              'require_link_online'   => 'pm_require_link_online',
              'allow_link_after'      => 'pm_allow_link_after',
              'conflict_block'        => 'pm_conflict_block',
              'block_outside_hours'   => 'pm_block_outside_hours',
              'block_holidays'        => 'pm_block_holidays',
              'calendar_feed_enabled' => 'pm_calendar_feed_enabled',
          ];
          foreach ($toggles as $key => $label) { ?>
            <div class="checkbox">
              <input type="hidden" name="<?php echo $key; ?>" value="0">
              <input type="checkbox" id="<?php echo $key; ?>" name="<?php echo $key; ?>" value="1"
                <?php echo pm_setting($key, '0') === '1' ? 'checked' : ''; ?>>
              <label for="<?php echo $key; ?>"><?php echo pm_lang($label); ?></label>
            </div>
          <?php } ?>

          <h5><?php echo pm_lang('pm_sect_cron'); ?></h5>
          <p class="pm-muted"><?php echo pm_lang('pm_cron_help'); ?></p>
          <pre class="pm-code">*/5 * * * * /usr/bin/php <?php echo FCPATH; ?>index.php payplex_meetings/meetings_cron/run <?php echo html_escape(pm_setting('cron_token', '')); ?></pre>
          <p class="pm-muted"><?php echo pm_lang('pm_cron_status_url'); ?><br>
            <code class="pm-code"><?php echo site_url('payplex_meetings/meetings_cron/status/' . pm_setting('cron_token', '')); ?></code>
          </p>
          <p class="pm-muted"><?php echo pm_lang('pm_cron_token_note'); ?></p>

          <hr class="hr-panel-heading">
          <button type="submit" class="btn btn-primary"><?php echo pm_lang('pm_save'); ?></button>

        <?php echo form_close(); ?>
      </div></div>
    </div></div>
  </div>
</div>

<?php init_tail(); ?>
</body>
</html>
