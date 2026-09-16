<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="pm-lead-tab">
    <div class="pm-lead-tab-head">
        <h5><?php echo pm_lang('pm_meetings_and_followups'); ?></h5>
        <?php if (pm_can('create')) { ?>
            <button type="button" class="btn btn-primary btn-xs pm-action"
                    data-pm-action="book" data-pm-lead="<?php echo (int) $lead_id; ?>">
                <?php echo pm_lang('pm_book_meeting'); ?>
            </button>
        <?php } ?>
    </div>

    <?php if (empty($meetings)) { ?>
        <p class="pm-empty"><?php echo pm_lang('pm_no_meetings_for_lead'); ?></p>
    <?php } else { ?>
        <ul class="pm-timeline">
            <?php foreach ($meetings as $m) {
                $s = isset($statuses[$m->status]) ? $statuses[$m->status] : null; ?>
                <li class="pm-timeline-item">
                    <span class="pm-timeline-dot" style="background:<?php echo $s ? html_escape($s['color']) : '#999'; ?>"></span>
                    <div class="pm-timeline-body">
                        <a href="<?php echo admin_url('payplex_meetings/meetings/view/' . (int) $m->id); ?>">
                            <?php echo html_escape($m->subject); ?>
                        </a>
                        <span class="pm-badge" style="border-color:<?php echo $s ? html_escape($s['color']) : '#999'; ?>">
                            <?php echo $s ? pm_lang($s['label']) : html_escape($m->status); ?>
                        </span>
                        <div class="pm-muted">
                            <?php echo pm_from_utc($m->start_utc, $m->timezone, 'd M Y, g:i A'); ?>
                            &ndash; <?php echo pm_from_utc($m->end_utc, $m->timezone, 'g:i A'); ?>
                            (<?php echo html_escape($m->timezone); ?>)
                            &middot; <?php echo html_escape($m->reference_no); ?>
                        </div>
                        <?php if (!empty($m->outcome)) { ?>
                            <div class="pm-muted"><?php echo pm_lang('pm_outcome'); ?>:
                                <?php echo html_escape($m->outcome); ?></div>
                        <?php } ?>
                    </div>
                </li>
            <?php } ?>
        </ul>
    <?php } ?>
</div>
