<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="pm-drawer-header">
    <div>
        <h4><?php echo pm_lang('pm_book_meeting'); ?></h4>
        <span class="pm-muted">
            <?php echo (isset($rel_type) && $rel_type === 'customer') ? 'Customer' : pm_lang('pm_lead'); ?> #<?php echo (int) $lead->id; ?> ·
            <?php echo pm_lang('pm_prefilled'); ?>
        </span>
    </div>
    <button type="button" class="close" data-pm-close aria-label="<?php echo pm_lang('pm_close'); ?>">
        <span aria-hidden="true">&times;</span>
    </button>
</div>

<div class="pm-prefill">
    <div><b><?php echo html_escape($lead->name); ?></b><?php echo pm_lang('pm_lead_name'); ?></div>
    <div><b><?php echo html_escape($lead->company ?: '—'); ?></b><?php echo pm_lang('pm_company'); ?></div>
    <div><b><?php echo html_escape($lead->email ?: '—'); ?></b><?php echo pm_lang('pm_email'); ?></div>
    <div><b><?php echo html_escape($lead->phonenumber ?: '—'); ?></b><?php echo pm_lang('pm_phone'); ?></div>
    <div><b><?php echo html_escape($tz); ?></b><?php echo pm_lang('pm_timezone'); ?></div>
</div>

<form id="pm-book-form" autocomplete="off">
    <input type="hidden" name="lead_id" value="<?php echo (int) $lead->id; ?>">
    <input type="hidden" name="rel_type" value="<?php echo html_escape(isset($rel_type) ? $rel_type : 'lead'); ?>">
    <input type="hidden" name="timezone" value="<?php echo html_escape($tz); ?>">

    <div class="pm-conflicts" style="display:none"></div>

    <div class="pm-grid">
        <div class="form-group pm-span-2">
            <label for="pm_subject"><?php echo pm_lang('pm_subject'); ?> *</label>
            <input type="text" class="form-control" id="pm_subject" name="subject" required maxlength="255">
        </div>

        <div class="form-group">
            <label for="pm_type"><?php echo pm_lang('pm_meeting_type'); ?></label>
            <select class="form-control" id="pm_type" name="meeting_type">
                <?php foreach ($types as $t) { ?>
                    <option value="<?php echo html_escape($t); ?>"><?php echo pm_lang('pm_type_' . $t); ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group">
            <label for="pm_priority"><?php echo pm_lang('pm_priority'); ?></label>
            <select class="form-control" id="pm_priority" name="priority">
                <option value="low"><?php echo pm_lang('pm_low'); ?></option>
                <option value="medium" selected><?php echo pm_lang('pm_medium'); ?></option>
                <option value="high"><?php echo pm_lang('pm_high'); ?></option>
            </select>
        </div>

        <div class="form-group">
            <label for="pm_date"><?php echo pm_lang('pm_date'); ?> *</label>
            <input type="date" class="form-control" id="pm_date" name="date" required
                   value="<?php echo date('Y-m-d'); ?>">
        </div>

        <div class="form-group">
            <label for="pm_start"><?php echo pm_lang('pm_start_time'); ?> *</label>
            <input type="time" class="form-control" id="pm_start" name="start_time" required>
        </div>

        <div class="form-group">
            <label for="pm_end"><?php echo pm_lang('pm_end_time'); ?> *</label>
            <input type="time" class="form-control" id="pm_end" name="end_time" required>
        </div>

        <div class="form-group">
            <label for="pm_location_type"><?php echo pm_lang('pm_location_type'); ?></label>
            <select class="form-control" id="pm_location_type" name="location_type">
                <option value="online"><?php echo pm_lang('pm_loc_online'); ?></option>
                <option value="phone"><?php echo pm_lang('pm_loc_phone'); ?></option>
                <option value="office"><?php echo pm_lang('pm_loc_office'); ?></option>
                <option value="client_site"><?php echo pm_lang('pm_loc_client_site'); ?></option>
            </select>
        </div>

        <div class="form-group pm-online-only">
            <label for="pm_platform"><?php echo pm_lang('pm_platform'); ?></label>
            <select class="form-control" id="pm_platform" name="platform">
                <?php foreach ($platforms as $p) { ?>
                    <option value="<?php echo html_escape($p); ?>"><?php echo pm_lang('pm_platform_' . $p); ?></option>
                <?php } ?>
            </select>
        </div>

        <div class="form-group pm-span-2 pm-online-only">
            <label for="pm_link"><?php echo pm_lang('pm_meeting_link'); ?> *</label>
            <input type="url" class="form-control" id="pm_link" name="meeting_link"
                   placeholder="https://meet.google.com/…">
            <?php if (pm_setting('allow_link_after', '0') === '1') { ?>
                <label class="pm-inline-check">
                    <input type="checkbox" name="link_later" value="1">
                    <?php echo pm_lang('pm_add_link_later'); ?>
                </label>
            <?php } ?>
        </div>

        <div class="form-group pm-span-2 pm-address-only" style="display:none">
            <label for="pm_address"><?php echo pm_lang('pm_address'); ?> *</label>
            <textarea class="form-control" id="pm_address" name="location_address" rows="2"></textarea>
        </div>

        <div class="form-group pm-span-2">
            <label><?php echo pm_lang('pm_participants'); ?></label>
            <div class="pm-participants">
                <?php if (!empty($lead->email)) { ?>
                    <div class="pm-participant" data-party-type="contact"
                         data-email="<?php echo html_escape($lead->email); ?>"
                         data-name="<?php echo html_escape($lead->name); ?>">
                        <span class="pm-p-name"><?php echo html_escape($lead->name); ?>
                            <small><?php echo html_escape($lead->email); ?></small></span>
                        <select class="pm-role input-sm">
                            <option value="required"><?php echo pm_lang('pm_required'); ?></option>
                            <option value="optional"><?php echo pm_lang('pm_optional'); ?></option>
                            <option value="cc"><?php echo pm_lang('pm_cc'); ?></option>
                        </select>
                        <label><input type="checkbox" class="pm-remind" checked> <?php echo pm_lang('pm_remind'); ?></label>
                        <button type="button" class="btn btn-xs btn-link pm-remove-participant">&times;</button>
                    </div>
                <?php } else { ?>
                    <p class="pm-muted"><?php echo pm_lang('pm_lead_has_no_email'); ?></p>
                <?php } ?>

                <?php $me = get_staff_user_id(); ?>
                <?php foreach ($staff as $s) { if ((int) $s->staffid !== (int) $me) { continue; } ?>
                    <div class="pm-participant" data-party-type="staff"
                         data-staff-id="<?php echo (int) $s->staffid; ?>"
                         data-email="<?php echo html_escape($s->email); ?>"
                         data-name="<?php echo html_escape($s->firstname . ' ' . $s->lastname); ?>">
                        <span class="pm-p-name"><?php echo html_escape($s->firstname . ' ' . $s->lastname); ?>
                            <small><?php echo pm_lang('pm_organizer'); ?></small></span>
                        <select class="pm-role input-sm">
                            <option value="required"><?php echo pm_lang('pm_required'); ?></option>
                        </select>
                        <label><input type="checkbox" class="pm-remind" checked> <?php echo pm_lang('pm_remind'); ?></label>
                    </div>
                <?php } ?>
            </div>

            <select class="form-control input-sm pm-add-staff">
                <option value=""><?php echo pm_lang('pm_add_internal_participant'); ?></option>
                <?php foreach ($staff as $s) { ?>
                    <option value="<?php echo (int) $s->staffid; ?>"
                            data-email="<?php echo html_escape($s->email); ?>"
                            data-name="<?php echo html_escape($s->firstname . ' ' . $s->lastname); ?>">
                        <?php echo html_escape($s->firstname . ' ' . $s->lastname); ?>
                    </option>
                <?php } ?>
            </select>

            <div class="pm-add-external">
                <input type="email" class="form-control input-sm pm-ext-email"
                       placeholder="<?php echo pm_lang('pm_external_email'); ?>">
                <button type="button" class="btn btn-xs btn-default pm-add-ext"><?php echo pm_lang('pm_add'); ?></button>
            </div>
        </div>

        <div class="form-group pm-span-2">
            <label for="pm_agenda"><?php echo pm_lang('pm_agenda'); ?></label>
            <textarea class="form-control" id="pm_agenda" name="agenda" rows="3"></textarea>
        </div>
    </div>

    <div class="pm-drawer-footer">
        <label class="pm-inline-check">
            <input type="checkbox" name="is_private" value="1"> <?php echo pm_lang('pm_internal_private'); ?>
        </label>
        <div>
            <button type="button" class="btn btn-default" data-pm-close><?php echo pm_lang('pm_cancel'); ?></button>
            <button type="submit" class="btn btn-primary"><?php echo pm_lang('pm_book_and_send'); ?></button>
        </div>
    </div>
</form>

<script>
(function ($) {
    'use strict';

    var $form = $('#pm-book-form');

    // Auto-fill the end time from the configured default duration.
    $form.on('change', '#pm_start', function () {
        if ($('#pm_end').val()) { return; }
        var parts = ($(this).val() || '').split(':');
        if (parts.length < 2) { return; }
        var d = new Date();
        d.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10) + <?php echo (int) $duration; ?>, 0, 0);
        $('#pm_end').val(('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2));
    });

    // Show only the fields the chosen location type actually needs.
    $form.on('change', '#pm_location_type', function () {
        var v = $(this).val();
        $form.find('.pm-online-only').toggle(v === 'online');
        $form.find('.pm-address-only').toggle(v === 'office' || v === 'client_site');
        $('#pm_link').prop('required', v === 'online');
    }).find('#pm_location_type').trigger('change');

    $form.on('click', '.pm-remove-participant', function () {
        $(this).closest('.pm-participant').remove();
    });

    $form.on('change', '.pm-add-staff', function () {
        var $opt = $(this).find('option:selected');
        var id = $opt.val();
        if (!id) { return; }
        if ($form.find('.pm-participant[data-staff-id="' + id + '"]').length) { $(this).val(''); return; }

        $form.find('.pm-participants').append(
            '<div class="pm-participant" data-party-type="staff" data-staff-id="' + id +
            '" data-email="' + $opt.data('email') + '" data-name="' + $opt.data('name') + '">' +
            '<span class="pm-p-name">' + $opt.data('name') + '</span>' +
            '<select class="pm-role input-sm"><option value="required">Required</option>' +
            '<option value="optional">Optional</option><option value="cc">CC</option></select>' +
            '<label><input type="checkbox" class="pm-remind" checked> Remind</label>' +
            '<button type="button" class="btn btn-xs btn-link pm-remove-participant">&times;</button></div>'
        );
        $(this).val('');
    });

    $form.on('click', '.pm-add-ext', function () {
        var $input = $form.find('.pm-ext-email');
        var email = ($input.val() || '').trim();
        if (!email || email.indexOf('@') === -1) { return; }

        $form.find('.pm-participants').append(
            '<div class="pm-participant" data-party-type="external" data-email="' + email +
            '" data-name="' + email + '">' +
            '<span class="pm-p-name">' + email + '</span>' +
            '<select class="pm-role input-sm"><option value="required">Required</option>' +
            '<option value="optional">Optional</option><option value="cc">CC</option></select>' +
            '<label><input type="checkbox" class="pm-remind" checked> Remind</label>' +
            '<button type="button" class="btn btn-xs btn-link pm-remove-participant">&times;</button></div>'
        );
        $input.val('');
    });

    // Pick a suggested slot straight from the conflict warning.
    $form.on('click', '.pm-slot', function () {
        var local = $(this).text().trim();
        $form.find('.pm-conflicts').prepend('<div class="pm-muted">Selected ' + local + '</div>');
    });
}(window.jQuery));
</script>
