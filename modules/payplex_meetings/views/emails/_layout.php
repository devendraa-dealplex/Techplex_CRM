<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Shared email shell.
 *
 * $p is whatever build_payload() produced for THIS audience. For a client recipient the
 * internal keys are not merely hidden here -- they were never placed in the array.
 * Templates therefore cannot leak them even if edited carelessly.
 */
?>
<div style="font-family:Helvetica,Arial,sans-serif;font-size:14px;color:#22262c;line-height:1.55;max-width:620px">
  <p style="margin:0 0 14px"><?php echo pm_lang('pm_email_hi'); ?> <?php echo html_escape($name); ?>,</p>

  <?php echo $intro; ?>

  <table cellpadding="0" cellspacing="0" border="0"
         style="width:100%;border:1px solid #e0e4ea;border-radius:4px;margin:16px 0">
    <tr><td style="padding:14px 16px">
      <div style="font-size:16px;font-weight:600;margin-bottom:10px">
        <?php echo html_escape($p['subject']); ?>
      </div>

      <table cellpadding="0" cellspacing="0" border="0" style="font-size:13px">
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280"><?php echo pm_lang('pm_when'); ?></td>
          <td style="padding:3px 0"><?php echo html_escape($p['date_local']); ?>,
              <?php echo html_escape($p['start_local']); ?>&ndash;<?php echo html_escape($p['end_local']); ?>
              (<?php echo html_escape($p['timezone']); ?>)</td>
        </tr>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280"><?php echo pm_lang('pm_duration'); ?></td>
          <td style="padding:3px 0"><?php echo (int) $p['duration']; ?> <?php echo pm_lang('pm_minutes'); ?></td>
        </tr>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280"><?php echo pm_lang('pm_type'); ?></td>
          <td style="padding:3px 0"><?php echo html_escape($p['meeting_type']); ?></td>
        </tr>
        <?php if (!empty($p['agenda'])) { ?>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280;vertical-align:top"><?php echo pm_lang('pm_agenda'); ?></td>
          <td style="padding:3px 0"><?php echo nl2br(html_escape($p['agenda'])); ?></td>
        </tr>
        <?php } ?>
        <?php if (!empty($p['meeting_link'])) { ?>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280"><?php echo pm_lang('pm_join_link'); ?></td>
          <td style="padding:3px 0"><a href="<?php echo html_escape($p['meeting_link']); ?>"
             style="color:#b4552f"><?php echo html_escape($p['meeting_link']); ?></a></td>
        </tr>
        <?php } elseif (!empty($p['address'])) { ?>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280;vertical-align:top"><?php echo pm_lang('pm_address'); ?></td>
          <td style="padding:3px 0"><?php echo nl2br(html_escape($p['address'])); ?></td>
        </tr>
        <?php } ?>
        <tr>
          <td style="padding:3px 14px 3px 0;color:#6b7280"><?php echo pm_lang('pm_organizer'); ?></td>
          <td style="padding:3px 0"><?php echo html_escape($p['organizer_name']); ?>
              &lt;<?php echo html_escape($p['organizer_email']); ?>&gt;</td>
        </tr>
      </table>
    </td></tr>
  </table>

  <?php if ($audience === 'internal' && !empty($p['participants'])) { ?>
    <p style="margin:0 0 6px;font-size:13px;color:#6b7280"><?php echo pm_lang('pm_participants'); ?>:</p>
    <ul style="margin:0 0 14px;padding-left:18px;font-size:13px">
      <?php foreach ($p['participants'] as $part) { ?>
        <li><?php echo html_escape($part->name ?: $part->email); ?>
            (<?php echo html_escape($part->role); ?>)</li>
      <?php } ?>
    </ul>
  <?php } ?>

  <?php if (!empty($outro)) { echo $outro; } ?>

  <p style="margin:18px 0 0;font-size:12px;color:#8a919b">
    <?php echo pm_lang('pm_reference'); ?>: <?php echo html_escape($p['reference_no']); ?>
  </p>
</div>
