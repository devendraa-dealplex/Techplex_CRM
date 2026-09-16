<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * The provenance block §6 requires on every report: what period it covers,
 * what filters are applied, where the numbers came from, how they are
 * calculated, and when they were produced. A figure without these is a number
 * with no way to check it.
 */
?>
<div style="background:#F7F9FC;border:1px solid #E4E9F0;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:12px;color:#4A5568">
  <div style="display:flex;flex-wrap:wrap;gap:18px">
    <span><b>Period:</b> <?php echo html_escape($meta['date_range']); ?></span>
    <span><b>Filters:</b> <?php echo html_escape($meta['filters']); ?></span>
    <span><b>Rows:</b> <?php echo (int) $meta['row_count']; ?></span>
    <?php if (!empty($meta['generated_at'])): ?>
      <span><b>Generated:</b> <?php echo html_escape($meta['generated_at']); ?></span>
    <?php endif; ?>
  </div>
  <div style="margin-top:6px"><b>Source:</b> <code><?php echo html_escape($meta['data_source']); ?></code></div>
  <div style="margin-top:4px"><b>Calculation:</b> <?php echo html_escape($meta['definition']); ?></div>
</div>
<?php if (!empty($conversion) && empty($conversion['available'])): ?>
  <div class="alert alert-warning" style="font-size:13px">
    <b>Conversions are not shown.</b> <?php echo html_escape($conversion['reason']); ?>
  </div>
<?php endif; ?>
<?php if (!empty($rate) && empty($rate['comparable'])): ?>
  <div class="alert alert-warning" style="font-size:13px">
    <b>Conversion % is withheld for this period.</b> <?php echo html_escape($rate['reason']); ?>
  </div>
<?php endif; ?>
<?php if (isset($has_calls) && !$has_calls): ?>
  <div class="alert alert-warning" style="font-size:13px">
    <b>Call figures are not available.</b> The AI calling module is not installed on this
    system, so call volume and spend are shown as unavailable rather than as zero.
  </div>
<?php endif; ?>
<?php if (!empty($scoped_to_self)): ?>
  <div class="alert alert-info" style="font-size:13px">
    You are seeing your own figures only. Team-wide figures need the
    &ldquo;View team-wide figures&rdquo; permission.
  </div>
<?php endif; ?>
