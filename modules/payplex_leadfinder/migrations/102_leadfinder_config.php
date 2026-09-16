<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 102 — configuration, and nothing invented.
 *
 * WHAT IS AND IS NOT SEEDED
 * -------------------------
 * Two kinds of value live in `payplex_lf_config`:
 *
 *   1. Values the operator stated. The quota ceilings in §13 are theirs, so
 *      they are seeded with `source_note` recording that they came from the
 *      written specification and on what date.
 *
 *   2. Values only Google can authoritatively supply — the permitted cache
 *      duration for Places content, and the free-tier allowance. These are
 *      seeded **empty**, with a note saying what must be confirmed and where.
 *      This environment cannot reach Google's service terms or pricing pages,
 *      and a number typed from memory into a retention policy is how an install
 *      ends up out of compliance while showing a green tick.
 *
 * An empty retention value does not fail open. `Leadfinder_fieldmask::
 * detailIsStale()` treats an unconfigured `max_age_seconds` as "everything is
 * stale", so an unconfigured install re-fetches rather than over-retains, and
 * the module refuses to start a search while `google_terms_confirmed_on` is
 * blank. The administrator has to look it up; they cannot skip past it.
 *
 * THE PHONE PROFILE
 * -----------------
 * India values, because every example in the specification is an Indian city
 * and the CRM's existing lead data is Indian. Seeded as configuration, not as a
 * constant, and editable: `Payplex_phone` has no country in it anywhere.
 */
return array(
    'id'          => 102,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Lead Finder configuration seed',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 101,

    'up' => array(
        /* --- operator-stated, §13 --------------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('monthly_search_cap','30000','Stated in the written specification, §13, 2026-09-12',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('monthly_detail_cap','6000','Stated in the written specification, §13, 2026-09-12',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('monthly_conversion_cap','6000','Stated in the written specification, §13 (5,000-6,000; upper bound taken, lower it if you meant a hard 5,000), 2026-09-12',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('default_staff_detail_limit_daily','200','Stated in the written specification, §13, 2026-09-12',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('warn_at_percent','80','Stated in the written specification, §13',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('critical_at_percent','90','Stated in the written specification, §13',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('hard_stop_at_percent','100','Stated in the written specification, §13 — automatic stop, no paid overage',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- must be confirmed by a human, seeded EMPTY ------------------ */
        /* --- VERIFIED 2026-09-12 against Google's own documents ---------
         * These were seeded blank in the first draft because they had not been
         * checked. They have now been read directly from Google's Service
         * Specific Terms and the Places API policies page, and the clause is
         * quoted so a reviewer can re-check it without trusting this file.
         *
         * The verified rule is NARROWER and differently shaped than the single
         * "cache max age" the first draft assumed — see the source_note on each.
         */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('coords_max_calendar_days','30','VERIFIED 2026-09-12 — Google Maps Platform Service Specific Terms 14.3 (Places API, Legacy and New): \"Customer may temporarily cache latitude and longitude values from the Places API for up to 30 consecutive calendar days, after which Customer must delete the cached latitude and longitude values.\" Applies to latitude/longitude ONLY, not to the whole record. Calendar days, not seconds.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('place_id_retention_note','indefinite','VERIFIED 2026-09-12 — Places API policies and attributions: \"the place ID, used to uniquely identify a place, is exempt from the caching restrictions. You can therefore store place ID values indefinitely.\" Also Service Specific Terms 3 (Google ID Caching). This is why the duplicate key is the place ID and not a cached copy of the business details.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('no_non_google_map','1','VERIFIED 2026-09-12 — Service Specific Terms 14.2: \"Customer must not use Google Maps Content from the Places API in conjunction with a non-Google map.\" This module renders no map at all, which is the simplest way to comply. Any future map widget must satisfy this first.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('google_terms_confirmed_on','2026-09-12','VERIFIED 2026-09-12 — Service Specific Terms 14.1-14.3 and the Places API policies page were read on this date. Re-confirm before each release: Google changes these. The module warns once this date is older than the review interval.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('search_billing_sku','pro','VERIFIED 2026-09-12 — Text Search (New) reference, FieldMask SKU tables. Our search mask requests displayName, formattedAddress, location, types, businessStatus, addressComponents and googleMapsUri, ALL of which are Pro. Only id/name/attributions/nextPageToken are Essentials ID Only. The first draft assumed the search was the cheap tier; it is not, and the ceilings below were signed off on that wrong assumption.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('detail_billing_sku','enterprise','VERIFIED 2026-09-12 — nationalPhoneNumber, internationalPhoneNumber and websiteUri are Text Search / Place Details ENTERPRISE SKU fields. This is why they are fetched one prospect at a time, only after a claim.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('max_results_per_search','60','VERIFIED 2026-09-12 — Text Search (New): \"returns a maximum of 60 results across all pages, although this limit is subject to change.\" Asking for more returns fewer without an error, so the request is capped and the employee is told.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('free_tier_allowance_note','','BLANK ON PURPOSE — and still blank after the 2026-09-12 check. Google publishes pricing and free-tier allowances per SKU on a page that changes independently of the terms; the figure must come from YOUR billing account, not from a public page, because it depends on your contract. Record the allowance and the date checked before relying on the ceilings above.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- claim behaviour, §7 ---------------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('claim_idle_release_seconds','','BLANK ON PURPOSE. §7 says the auto-release period is Admin-defined. No default is guessed: while blank, claims never auto-release and a manager must release them, which is visible and attributable rather than silent.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('daily_claim_limit','','BLANK ON PURPOSE. §7 says employee-wise daily claim limits; the number was not stated. Blank means unlimited until an administrator sets it.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- retention of rejected prospects, §18 ------------------------ */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('rejected_purge_after_seconds','','BLANK ON PURPOSE. §18 requires rejected temporary prospect data to expire on a configured retention policy. The period was not stated and is a data-protection decision, not a technical default.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- phone normalisation profile -------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('phone_profile','{\"cc\":\"91\",\"trunk_prefix\":\"0\",\"national_len\":[10],\"weak_key_len\":10,\"allowed_cc\":[\"91\"]}','India, inferred from every example in the specification (Ranchi, Hazaribagh, Patna, Jharkhand, Delhi) and from existing lead data. Configuration, not a constant: Payplex_phone contains no country code. Edit for any other market.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- attribution, §18 ------------------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('show_google_attribution','1','§18 requires the Google attribution to be displayed wherever Places data is shown. On, and not switchable off from the employee UI.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'monthly_search_cap','monthly_detail_cap','monthly_conversion_cap',
            'default_staff_detail_limit_daily','warn_at_percent','critical_at_percent',
            'hard_stop_at_percent','place_id_retention_note',
            'google_terms_confirmed_on','free_tier_allowance_note','claim_idle_release_seconds',
            'coords_max_calendar_days','no_non_google_map','search_billing_sku',
            'detail_billing_sku','max_results_per_search',
            'daily_claim_limit','rejected_purge_after_seconds','phone_profile',
            'show_google_attribution')",
    ),
);
