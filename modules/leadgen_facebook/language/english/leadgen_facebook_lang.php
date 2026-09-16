<?php
defined('BASEPATH') or exit('No direct script access allowed');

$lang['leadgen_facebook_settings'] = 'Facebook Lead Settings';
$lang['leadgen_facebook_perm_view'] = 'View';
$lang['leadgen_facebook_perm_edit'] = 'Edit';

/* ---- credentials ---- */
$lang['facebook_section_credentials'] = 'Meta app credentials';
$lang['facebook_page_id'] = 'Facebook Page ID';
$lang['facebook_page_access_token'] = 'Page Access Token';
$lang['facebook_app_secret'] = 'App Secret';
$lang['facebook_verify_token'] = 'Webhook Verify Token';
$lang['facebook_stored_value'] = 'Stored value';
$lang['facebook_secret_blank_hint'] = 'Leave blank to keep the stored value. Credentials are never displayed back on this page.';
$lang['facebook_clear_stored_value'] = 'Remove the stored value';

/* ---- routing ---- */
$lang['facebook_section_routing'] = 'Where new leads land';
$lang['facebook_lead_source_name'] = 'Lead source name';
$lang['facebook_lead_status_name'] = 'Lead status name to match';
$lang['facebook_default_lead_status'] = 'Fallback: specific lead status';
$lang['facebook_status_override_hint'] = 'Used only when no status carries the name above. The name wins, deliberately: status IDs mean different things on different installs — ID 38 is "New Lead" on one and "Cold" on another — so a copied ID would silently file every lead in the wrong column. Change the name to change where leads land.';

/* ---- assignment ---- */
$lang['facebook_section_assignment'] = 'Assignment';
$lang['facebook_assignment_mode'] = 'Assignment mode';
$lang['facebook_mode_unassigned'] = 'Leave unassigned (review queue)';
$lang['facebook_mode_fixed'] = 'Always one employee';
$lang['facebook_mode_round_robin'] = 'Rotate across a pool';
$lang['facebook_default_assignee'] = 'Default assignee';
$lang['facebook_round_robin_pool'] = 'Round-robin pool (staff IDs)';
$lang['facebook_round_robin_hint'] = 'Comma-separated staff IDs. Entries that are not whole numbers are dropped and listed, never rounded to a nearby ID.';
$lang['facebook_pool_dropped'] = 'These pool entries were not valid staff IDs and were ignored';

/* ---- other ---- */
$lang['facebook_section_other'] = 'Other channels';
$lang['facebook_messenger_enabled'] = 'Create leads from Messenger messages';
$lang['facebook_messenger_hint'] = 'Off by default. When off, Messenger events are still recorded in the delivery log but no lead is created — Messenger capture creates a lead from anybody who messages the page, which is much broader than Lead Ads.';

/* ---- webhook ---- */
$lang['facebook_webhook_url'] = 'Webhook callback URL';
$lang['facebook_webhook_hint'] = 'Paste this into the Meta App Dashboard under Webhooks, subscribe the Page to the leadgen field, and use the Verify Token above when prompted. The handshake must return the challenge; a mismatch returns HTTP 403 and is recorded in the delivery log.';

/* ---- current behaviour panel ---- */
$lang['facebook_current_behaviour'] = 'What a delivery arriving now would do';
$lang['facebook_resolved_status'] = 'Status it would get';
$lang['facebook_resolved_assignment'] = 'Who it would go to';
$lang['facebook_will_be_created'] = 'will be created on first delivery';
$lang['facebook_review_queue'] = 'review queue';
$lang['facebook_view_deliveries'] = 'Inbound delivery log';
$lang['facebook_view_review_queue'] = 'Review queue';
$lang['facebook_delivery_summary'] = 'Deliveries by outcome';

/* ---- delivery log ---- */
$lang['facebook_delivery_log_intro'] = 'Every inbound request Meta makes is recorded here, whether it was accepted or refused. Tokens, signatures and contact details are redacted; the lead record holds the real details. Append-only.';
$lang['facebook_no_deliveries'] = 'No inbound requests have been recorded yet. If Meta shows delivery attempts and this list is empty, the callback URL is not reaching this CRM.';
$lang['facebook_all'] = 'All';
$lang['facebook_received'] = 'Received';
$lang['facebook_request_id'] = 'Request';
$lang['facebook_event'] = 'Method / event';
$lang['facebook_outcome'] = 'Outcome';
$lang['facebook_refs'] = 'References';
$lang['facebook_lead'] = 'Lead';
$lang['facebook_signature'] = 'Signature';
$lang['facebook_reason'] = 'Reason';
$lang['facebook_count'] = 'Count';
$lang['facebook_last_seen'] = 'Last seen';
$lang['facebook_retryable'] = 'retryable';
$lang['facebook_absent'] = 'absent';
$lang['facebook_payload_redacted_note'] = 'Redacted payload — credentials removed, contact details masked.';

/* ---- review queue ---- */
$lang['facebook_review_intro'] = 'Facebook leads with no owner. A lead leaves this list as soon as anybody is assigned to it on the normal Leads screen — this is a view of the leads themselves, not a separate list that has to be kept in step.';
$lang['facebook_review_empty'] = 'Nothing is waiting. Every Facebook lead has an owner.';
$lang['facebook_review_count'] = 'Facebook lead(s) have no owner.';
$lang['facebook_source_missing'] = 'No lead source exists yet with this name; it is created on the first delivery.';
$lang['facebook_open_lead'] = 'Open';

/* ---- reports and monitoring ---- */
$lang['facebook_reports_title'] = 'Facebook delivery reports';
$lang['facebook_reports_intro'] = 'Accepted, rejected, failed and duplicate deliveries, with an interpretation of what the counts mean. Read-only: there is no edit, delete or manual retry, because an audit trail a user can tidy is not one and Meta already retries by itself.';
$lang['facebook_reports_window'] = 'Window';
$lang['facebook_reports_no_alerts'] = 'Nothing needs attention. No failure streak, no throttling, no unowned backlog, and no unexplained silence since the last accepted delivery.';
$lang['facebook_reports_total'] = 'Requests in window';
$lang['facebook_reports_accepted'] = 'Accepted';
$lang['facebook_reports_not_accepted'] = 'Refused or failed';
$lang['facebook_reports_unknown_outcome'] = 'Outcomes this build does not recognise (counted as failures rather than dropped)';
$lang['facebook_bucket_created'] = 'Leads created';
$lang['facebook_bucket_rejected'] = 'Rejected';
$lang['facebook_bucket_failed'] = 'Failed / unconfigured';
$lang['facebook_bucket_duplicate'] = 'Duplicates blocked';
$lang['facebook_bucket_matched'] = 'Matched an existing lead';
$lang['facebook_bucket_review'] = 'Created into review queue';
$lang['facebook_bucket_no_action'] = 'Accepted, nothing to do';
$lang['facebook_bucket_verify'] = 'Handshakes verified';
$lang['facebook_by_outcome'] = 'Every outcome in the window';
$lang['facebook_retry_pending'] = 'Deliveries Meta should retry';
$lang['facebook_retry_monitor'] = 'Retry and error monitor';
$lang['facebook_retry_monitor_hint'] = 'Requests answered with a retryable status — 429, 500 or 503. Meta will send these again. One entry is a blip; the same reference repeating is a fault.';
$lang['facebook_retry_none'] = 'Nothing is awaiting a retry.';
$lang['facebook_unowned_leads'] = 'Facebook leads with no owner';
$lang['facebook_last_accepted'] = 'Last accepted delivery';
$lang['facebook_never'] = 'never';
$lang['facebook_yes_retry'] = 'retry expected';
$lang['facebook_credential_history'] = 'Credential status and change history';
$lang['facebook_credential_history_hint'] = 'Fingerprints and lengths only — no credential is stored here, and the fingerprint columns are eight characters wide so they could not hold one. A length that halves is a truncated paste, which is the most common credential failure and is otherwise invisible.';
$lang['facebook_credential_history_empty'] = 'No credential has been set, changed or cleared yet.';
$lang['facebook_when'] = 'When';
$lang['facebook_credential'] = 'Credential';
$lang['facebook_action'] = 'Action';
$lang['facebook_fingerprint_change'] = 'Fingerprint';
$lang['facebook_length_change'] = 'Length';
$lang['facebook_by'] = 'Changed by';
$lang['facebook_much_shorter'] = 'much shorter — check for a truncated paste';
$lang['facebook_view_reports'] = 'Delivery reports';
$lang['facebook_health_window_hours'] = 'Report window (hours)';
$lang['facebook_rate_limit_per_minute'] = 'Rate limit (requests per source per minute)';

/* ------------------------------------------------------------------ */
/* Lead tagging and reference fields                                  */
/* ------------------------------------------------------------------ */
$lang['facebook_section_tagging'] = 'Lead tagging and references';
$lang['facebook_tagging_section_hint'] = 'One CRM can receive leads from several Facebook Pages belonging to several businesses. These values are written onto each lead so the person who opens it can see which business and which Page it came from. They are configuration, not code — nothing here is built into the module.';
$lang['facebook_business_unit'] = 'Business unit';
$lang['facebook_business_unit_hint'] = 'Applied to every incoming lead as a tag, so the Leads list can be filtered by business. Leave empty if this install serves one business only; an empty value adds no tag rather than a blank one.';
$lang['facebook_page_name'] = 'Facebook Page name';
$lang['facebook_page_name_hint'] = 'The human-readable Page name, used as a tag and written to the "Facebook Page" field on the lead. The Page ID above is what Meta sends; this is what a salesperson recognises.';
$lang['facebook_tagging_enabled'] = 'Tag incoming leads';
$lang['facebook_tagging_enabled_hint'] = 'When off, leads are still created and still carry their source — they just receive no tags and no reference fields. Turning this off never affects whether a lead is created.';
$lang['facebook_tags_preview'] = 'Tags a lead arriving now would receive';
$lang['facebook_tags_none'] = 'none — no business unit, source name or Page name is configured';
$lang['facebook_field_facebook_page'] = 'Facebook Page';
$lang['facebook_field_facebook_page_id'] = 'Facebook Page ID';
$lang['facebook_field_facebook_form_id'] = 'Facebook Form ID';
$lang['facebook_field_facebook_campaign_id'] = 'Facebook Campaign ID';
$lang['facebook_field_facebook_lead_id'] = 'Facebook Lead ID';

/* ------------------------------------------------------------------ */
/* Metadata failures: recorded, retryable, never silent               */
/* ------------------------------------------------------------------ */
$lang['facebook_enrichment_title'] = 'Leads missing tags or references';
$lang['facebook_enrichment_hint'] = 'Tagging is best-effort on purpose: a tag table problem must never destroy a lead that was otherwise saved correctly. This is where those failures are recorded so "best-effort" does not quietly mean "not done". The leads below exist and are callable — what is missing is a tag or a Facebook reference field.';
$lang['facebook_enrichment_none'] = 'Every lead has its tags and reference fields.';
$lang['facebook_enrichment_pending'] = 'lead(s) are missing a tag or a reference field.';
$lang['facebook_enrichment_exhausted'] = 'of them have used every automatic retry and now need a person. The usual cause is a custom-field definition deleted after the module was installed.';
$lang['facebook_enrichment_retry'] = 'Retry the pending items now';
$lang['facebook_enrichment_retry_done'] = 'Retry finished (attempted / resolved / still failing)';
$lang['facebook_enrichment_lead'] = 'Lead';
$lang['facebook_enrichment_missing'] = 'What failed';
$lang['facebook_enrichment_attempts'] = 'Attempts';
$lang['facebook_enrichment_status'] = 'Status';
$lang['facebook_enrichment_resolved'] = 'repaired';
$lang['facebook_enrichment_stopped'] = 'retries exhausted';
$lang['facebook_enrichment_waiting'] = 'awaiting retry';
$lang['facebook_enrichment_empty'] = 'Nothing has failed yet.';
$lang['facebook_view_enrichment'] = 'Leads missing tags';

/* ------------------------------------------------------------------ */
/* Contact quality: quarantine and possible duplicates                */
/* ------------------------------------------------------------------ */
$lang['facebook_quarantine_title'] = 'No Contact Identifier';
$lang['facebook_quarantine_hint'] = 'These deliveries arrived with neither an email address nor a phone number, so no CRM lead was created for them. Nothing was discarded: the Page, Form, Campaign and Facebook Lead ID are all kept below. They stay off the Leads list until somebody adds a way to contact the person, because a lead nobody can reach is a row that teaches the sales team to distrust the list.';
$lang['facebook_quarantine_none'] = 'Nothing is waiting. Every delivery so far carried at least one contact identifier.';
$lang['facebook_quarantine_open'] = 'delivery(ies) are held with no way to contact anybody.';
$lang['facebook_quarantine_ref'] = 'Facebook Lead ID';
$lang['facebook_quarantine_name'] = 'Name given';
$lang['facebook_quarantine_reason'] = 'Reason held';
$lang['facebook_quarantine_references'] = 'References';
$lang['facebook_quarantine_action'] = 'Release as a lead';
$lang['facebook_quarantine_release'] = 'Add identifier and create lead';
$lang['facebook_quarantine_released'] = 'Released as lead';
$lang['facebook_quarantine_released_as'] = 'Released as lead';
$lang['facebook_quarantine_release_failed'] = 'Not released';
$lang['facebook_quarantine_email_placeholder'] = 'email address';
$lang['facebook_quarantine_phone_placeholder'] = 'phone number';
$lang['facebook_quarantine_payload'] = 'Fields received (redacted)';
$lang['facebook_quarantine_empty'] = 'Nothing has been quarantined.';

$lang['facebook_duplicates_title'] = 'Possible duplicates';
$lang['facebook_duplicates_hint'] = 'Each row is a lead whose email address or phone number matches a lead already in the CRM. Both leads exist and both are workable.';
$lang['facebook_duplicates_no_merge_notice'] = 'Nothing here has been merged, moved or deleted. Two colleagues filling the same form from one company number are two real leads — deciding otherwise is a judgement, not a rule a machine should apply. Record a decision here with your reason; it is written to the audit log, and the records themselves are then merged in the CRM as usual. Shared and switchboard numbers are excluded automatically and never appear in this queue.';
$lang['facebook_duplicates_none'] = 'Nothing is awaiting review.';
$lang['facebook_duplicates_lead'] = 'New lead';
$lang['facebook_duplicates_other'] = 'Existing lead';
$lang['facebook_duplicates_matched_on'] = 'Matched on';
$lang['facebook_duplicates_value_hidden'] = 'value not stored here — open either lead to see it';
$lang['facebook_duplicates_decision'] = 'Decision';
$lang['facebook_duplicates_confirm'] = 'Same person — duplicate';
$lang['facebook_duplicates_reject'] = 'Different people — not a duplicate';
$lang['facebook_duplicates_record'] = 'Record decision';
$lang['facebook_duplicates_reason_placeholder'] = 'reason (required, recorded against your name)';
$lang['facebook_duplicates_recorded'] = 'Decision recorded and written to the audit log.';
$lang['facebook_duplicates_not_recorded'] = 'Decision not recorded';
$lang['facebook_duplicates_empty'] = 'No possible duplicates have been found.';

$lang['facebook_shared_numbers'] = 'Shared / switchboard numbers';
$lang['facebook_shared_numbers_hint'] = 'Numbers that belong to a company rather than a person — a reception desk, a hunting group. Leads sharing one of these are never marked as possible duplicates. Separate with commas or spaces; any format is accepted.';
$lang['facebook_shared_number_threshold'] = 'Treat a number as shared after this many leads';
$lang['facebook_shared_number_threshold_hint'] = 'The automatic half of the rule, because no list of switchboard numbers is ever complete. A number already on this many leads is treated as a company number, not as evidence of duplication.';
$lang['facebook_duplicate_detection'] = 'Flag possible duplicates';
$lang['facebook_duplicate_detection_hint'] = 'When off, leads are still created exactly as before — only the review markers stop. It never affects whether a lead is created.';
$lang['facebook_view_quarantine'] = 'No Contact Identifier';
$lang['facebook_view_duplicates'] = 'Possible duplicates';
$lang['facebook_section_duplicates'] = 'Contact quality and duplicates';
$lang['facebook_section_duplicates_hint'] = 'A delivery with neither an email nor a phone number is held for review instead of becoming a lead. A delivery whose details match an existing lead still becomes its own lead, and the resemblance is flagged for a person to judge — nothing is ever merged automatically.';
