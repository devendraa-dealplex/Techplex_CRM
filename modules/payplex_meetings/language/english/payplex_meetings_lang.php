<?php
defined('BASEPATH') or exit('No direct script access allowed');

# Module
$lang['pm_meetings']              = 'Meetings';
$lang['pm_meeting']               = 'Meeting';
$lang['pm_all_meetings']          = 'All Meetings';
$lang['pm_my_meetings']           = 'My Meetings';
$lang['pm_pending_completion']    = 'Pending Completion';
$lang['pm_pending_completion_help'] = 'These meetings have ended but no outcome has been recorded yet.';
$lang['pm_settings']              = 'Settings';
$lang['pm_meetings_and_followups'] = 'Meetings & Follow-ups';
$lang['pm_engagement']            = 'Engagement';
$lang['pm_no_meeting_yet']        = 'No meeting yet';
$lang['pm_followup_overdue']      = 'Follow-up overdue';
$lang['pm_quick_actions']         = 'Quick Actions';
$lang['pm_book_meeting']          = 'Book meeting';
$lang['pm_loading']               = 'Loading…';
$lang['pm_close']                 = 'Close';
$lang['pm_cancel']                = 'Cancel';
$lang['pm_save']                  = 'Save';
$lang['pm_add']                   = 'Add';
$lang['pm_book_and_send']         = 'Book & send invites';

# Fields
$lang['pm_lead']                  = 'Lead';
$lang['pm_lead_name']             = 'Lead name';
$lang['pm_company']               = 'Company';
$lang['pm_email']                 = 'Email';
$lang['pm_phone']                 = 'Phone';
$lang['pm_prefilled']             = 'prefilled from lead';
$lang['pm_subject']               = 'Subject';
$lang['pm_agenda']                = 'Agenda';
$lang['pm_date']                  = 'Date';
$lang['pm_start_time']            = 'Start time';
$lang['pm_end_time']              = 'End time';
$lang['pm_timezone']              = 'Time zone';
$lang['pm_duration']              = 'Duration';
$lang['pm_minutes']               = 'minutes';
$lang['pm_hour']                  = 'hour';
$lang['pm_hours']                 = 'hours';
$lang['pm_priority']              = 'Priority';
$lang['pm_low']                   = 'Low';
$lang['pm_medium']                = 'Medium';
$lang['pm_high']                  = 'High';
$lang['pm_meeting_type']          = 'Meeting type';
$lang['pm_type']                  = 'Type';
$lang['pm_location_type']         = 'Location';
$lang['pm_platform']              = 'Platform';
$lang['pm_meeting_link']          = 'Meeting link';
$lang['pm_join_link']             = 'Join link';
$lang['pm_add_link_later']        = 'Add the link after booking';
$lang['pm_address']               = 'Address';
$lang['pm_participants']          = 'Participants';
$lang['pm_organizer']             = 'Organizer';
$lang['pm_required']              = 'Required';
$lang['pm_optional']              = 'Optional';
$lang['pm_cc']                    = 'CC';
$lang['pm_remind']                = 'Remind';
$lang['pm_when']                  = 'When';
$lang['pm_status']                = 'Status';
$lang['pm_outcome']               = 'Outcome';
$lang['pm_reference']             = 'Reference';
$lang['pm_internal_private']      = 'Internal / private meeting';
$lang['pm_add_internal_participant'] = 'Add internal participant…';
$lang['pm_external_email']        = 'External participant email';
$lang['pm_lead_has_no_email']     = 'This lead has no email address, so the client cannot be invited.';
$lang['pm_download_ics']          = 'Download calendar file (.ics)';
$lang['pm_no_meetings']           = 'No meetings yet.';
$lang['pm_no_meetings_for_lead']  = 'No meetings booked for this lead yet.';
$lang['pm_delivery_log']          = 'Delivery log';
$lang['pm_reminder_schedule']     = 'Reminder schedule';
$lang['pm_no_reminders']          = 'No reminders scheduled — every configured offset was already in the past when this meeting was booked.';
$lang['pm_fires_at']              = 'Fires at';
$lang['pm_offset']                = 'Offset';
$lang['pm_channel']               = 'Channel';
$lang['pm_attempts']              = 'Attempts';
$lang['pm_cron_status_url']       = 'Health check URL for your monitoring (overdue > 0 means the dispatcher is not running):';
$lang['pm_no_deliveries']         = 'Nothing sent yet.';
$lang['pm_recipient']             = 'Recipient';
$lang['pm_template']              = 'Template';
$lang['pm_version']               = 'Version';
$lang['pm_sent']                  = 'Sent';
$lang['pm_audit_trail']           = 'Audit trail';
$lang['pm_internal_only']         = 'Internal only';
$lang['pm_override_reason']       = 'Conflict override reason';
$lang['pm_reschedule_reason']     = 'Reschedule reason';
$lang['pm_cancel_reason']         = 'Cancellation reason';

# Statuses
$lang['pm_status_scheduled']   = 'Scheduled';
$lang['pm_status_confirmed']   = 'Confirmed';
$lang['pm_status_rescheduled'] = 'Rescheduled';
$lang['pm_status_in_progress'] = 'In progress';
$lang['pm_status_completed']   = 'Completed';
$lang['pm_status_cancelled']   = 'Cancelled';
$lang['pm_status_no_show']     = 'No-show';

# Meeting types
$lang['pm_type_online']           = 'Online meeting';
$lang['pm_type_phone_call']       = 'Phone call';
$lang['pm_type_office']           = 'Office meeting';
$lang['pm_type_client_site']      = 'Client-location meeting';
$lang['pm_type_demo']             = 'Product demonstration';
$lang['pm_type_sales']            = 'Sales discussion';
$lang['pm_type_technical']        = 'Technical discussion';
$lang['pm_type_onboarding']       = 'Onboarding meeting';
$lang['pm_type_support']          = 'Support meeting';
$lang['pm_type_payment_followup'] = 'Payment follow-up';
$lang['pm_type_partnership']      = 'Partnership discussion';
$lang['pm_type_interview']        = 'Interview';
$lang['pm_type_other']            = 'Other';

# Locations & platforms
$lang['pm_loc_online']        = 'Online meeting';
$lang['pm_loc_phone']         = 'Phone call';
$lang['pm_loc_office']        = 'Office meeting';
$lang['pm_loc_client_site']   = 'Client location';
$lang['pm_platform_google_meet'] = 'Google Meet';
$lang['pm_platform_ms_teams']    = 'Microsoft Teams';
$lang['pm_platform_zoom']        = 'Zoom';
$lang['pm_platform_custom_link'] = 'Custom link';
$lang['pm_platform_phone']       = 'Phone';
$lang['pm_platform_physical']    = 'Physical';

# Validation
$lang['pm_err_subject_required']  = 'A meeting subject is required.';
$lang['pm_err_lead_required']     = 'A lead must be selected.';
$lang['pm_err_time_required']     = 'A date, start time and end time are required.';
$lang['pm_err_time_invalid']      = 'That date or time could not be read.';
$lang['pm_err_end_before_start']  = 'The end time must be after the start time.';
$lang['pm_err_past']              = 'A meeting cannot be booked in the past.';
$lang['pm_err_link_required']     = 'An online meeting needs a join link before it can be saved.';
$lang['pm_err_link_invalid']      = 'That meeting link is not a valid http or https URL.';
$lang['pm_err_address_required']  = 'An address is required for this meeting type.';
$lang['pm_err_timezone_invalid']  = 'That time zone is not recognised.';
$lang['pm_err_outside_hours']     = 'That time falls outside the permitted working hours.';
$lang['pm_err_holiday']           = 'That date is a holiday.';
$lang['pm_conflict_detected']     = 'This time clashes with an existing meeting.';
$lang['pm_override_not_permitted'] = 'You do not have permission to override a scheduling conflict.';

# Results
$lang['pm_created']       = 'Meeting booked and invitations sent.';
$lang['pm_create_failed'] = 'The meeting could not be booked. Nothing was saved.';
$lang['pm_update_failed'] = 'The change could not be saved.';
$lang['pm_rescheduled']   = 'Meeting rescheduled. Updated invitations sent.';
$lang['pm_cancelled']     = 'Meeting cancelled and participants notified.';
$lang['pm_updated']       = 'Meeting updated.';
$lang['pm_not_found']     = 'Meeting not found.';
$lang['pm_reason_required'] = 'A reason is required and will be recorded in the audit log.';
$lang['pm_access_denied'] = 'You do not have permission to do that.';
$lang['pm_settings_saved'] = 'Settings saved.';

# Emails
$lang['pm_email_hi']                 = 'Hi';
$lang['pm_at']                       = 'at';
$lang['pm_email_confirm_subject']    = 'Meeting confirmed';
$lang['pm_email_reminder_subject']   = 'Reminder';
$lang['pm_email_reschedule_subject'] = 'Meeting rescheduled';
$lang['pm_email_cancel_subject']     = 'Meeting cancelled';
$lang['pm_email_confirm_intro']      = 'Your meeting is confirmed. The details are below.';
$lang['pm_email_reminder_intro']     = 'This is a reminder that your meeting starts in %s.';
$lang['pm_email_reschedule_intro']   = 'This meeting has been moved. Please note the new time below.';
$lang['pm_email_cancel_intro']       = 'This meeting has been cancelled. No action is needed from you.';
$lang['pm_email_ics_note']           = 'A calendar file is attached — open it to add this meeting to your calendar.';
$lang['pm_notification_upcoming']    = 'Upcoming meeting: %s';

# Permissions
$lang['pm_perm_view']            = 'View all meetings';
$lang['pm_perm_view_own']        = 'View own meetings';
$lang['pm_perm_create']          = 'Create meetings';
$lang['pm_perm_edit']            = 'Edit / reschedule meetings';
$lang['pm_perm_cancel']          = 'Cancel meetings';
$lang['pm_perm_override']        = 'Override scheduling conflicts';
$lang['pm_perm_confidential']    = 'View confidential notes';
$lang['pm_perm_approve_summary'] = 'Approve meeting summaries';
$lang['pm_perm_share_client']    = 'Share client summary';
$lang['pm_perm_config']          = 'Manage settings and templates';
$lang['pm_perm_export']          = 'Export meeting reports';

# Settings page
$lang['pm_sect_scheduling']       = 'Scheduling';
$lang['pm_sect_reminders']        = 'Reminders';
$lang['pm_sect_rules']            = 'Rules';
$lang['pm_sect_cron']             = 'Scheduler';
$lang['pm_company_timezone']      = 'Company time zone';
$lang['pm_default_duration']      = 'Default duration (min)';
$lang['pm_working_from']          = 'Working hours from';
$lang['pm_working_to']            = 'Working hours to';
$lang['pm_working_days']          = 'Working days';
$lang['pm_working_days_help']     = 'ISO day numbers, comma separated. 1 = Monday … 7 = Sunday.';
$lang['pm_holidays']              = 'Holidays';
$lang['pm_reminder_offsets']      = 'Reminder offsets (minutes before)';
$lang['pm_reminder_offsets_help'] = '1440 = 24h, 720 = 12h, 120 = 2h, 30 = 30 min.';
$lang['pm_reminder_channels']     = 'Reminder channels';
$lang['pm_retry_max']             = 'Max retries';
$lang['pm_missed_window']         = 'Missed-window grace (min)';
$lang['pm_missed_window_help']    = 'A reminder later than this is skipped and logged, never sent late.';
$lang['pm_require_link_online']   = 'Require a meeting link for online meetings';
$lang['pm_allow_link_after']      = 'Allow the link to be added after booking';
$lang['pm_conflict_block']        = 'Block booking when a conflict is detected';
$lang['pm_block_outside_hours']   = 'Block booking outside working hours';
$lang['pm_block_holidays']        = 'Block booking on holidays';
$lang['pm_calendar_feed_enabled'] = 'Show meetings on the CRM calendar';
$lang['pm_cron_help']             = 'Reminders need a scheduler running at least every 5 minutes. Add this crontab entry:';
$lang['pm_cron_token_note']       = 'The token is generated per installation and stored in options as pm_cron_token. Never commit it to source control.';
