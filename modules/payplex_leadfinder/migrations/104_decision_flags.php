<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');
/**
 * Lead Finder migration 104 — the three approved decisions, as configuration.
 *
 * Each flag here is the switch a person was promised. Every one defaults to the
 * safe side, and the seed records which approval it came from, so an operator
 * changing one can see what they are overriding.
 */
return array(
    'id'          => 104,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Decision flags: legacy hook off, drip excluded, duplicate policy',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 102,

    'up' => array(
        /* --- DECISION 2: the legacy pipeline stays off ------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('legacy_lead_created_hook','','DECISION 2, 2026-09-12: do NOT fire the existing lead_created hook yet. Admin-controlled, default OFF. The ONLY value that enables it is the exact string enabled_after_uat — 1, true, yes and on are all read as OFF, because an administrator typing off into a truthiness test would switch the pipeline ON.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('legacy_hook_uat_passed_on','','DECISION 2: activate the existing AI pipeline only after separate staging UAT. The flag above is not sufficient on its own — a YYYY-MM-DD date must be recorded here, so the switch cannot be flipped without evidence the UAT happened.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('lf_conversion_event','payplex_lf_lead_converted','DECISION 2: the dedicated Lead Finder conversion event, created and tested first. Nothing in the CRM listens to it today, which is the point — the first release can be observed without changing what any existing listener does.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- DECISION 3: no automatic drip ------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('follow_up_enabled','','DECISION 3, 2026-09-12: exclude all Lead Finder prospects and leads from automatic drip BY DEFAULT. Blank means off. Enabling this still does not mail anybody without verified interest, recorded consent, or an approved staff action.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('drip_hard_suppression','converted_customer,do_not_contact,invalid,unsubscribed','DECISION 3: these four can NEVER receive the lead drip. This row is documentation and audit — the rule itself is in Leadfinder_suppression::hardSuppressed(), which takes only the record and has no override parameter. Editing this string does not weaken the code.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('lf_lead_source_ids','','Set to the tblleads_sources id(s) used for Lead Finder conversions once they exist. The drip exclusion predicate needs them. While blank the module reports the exclusion as UNRESOLVED rather than silently excluding everything (which would stop your existing campaigns) or nothing (which would mail our prospects).',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",

        /* --- DECISION 1: duplicates -------------------------------------- */
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('phone_backfill_policy','none','DECISION 1, 2026-09-12: do not automatically delete, merge or overwrite existing leads. phone_match_key is added NULL and is never backfilled by migration. NULL means not-yet-normalised and the duplicate checker reads it as UNKNOWN, never as no-match. Any backfill is a separate, reported, reversible operation.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('dupe_shared_hosts','wixsite.com,blogspot.com,weebly.com,business.site','Domains on which a shared host means nothing: a domain match on one of these is downgraded from Exact to Possible. Regional and changeable, so configuration rather than a constant. Extend for your market.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
        "INSERT INTO `{P}payplex_lf_config` (ckey,cvalue,source_note,effective_from,set_by,set_at)
         VALUES ('webhook_idempotency','transactional','DECISION 1: make webhook creation transactional/idempotent. Requires the UNIQUE key added by migration 103 — the in-tree pattern at leadgen_followup.php:189-216 (UNIQUE + INSERT IGNORE) rather than read-then-insert, which is what currently allows two concurrent identical deliveries both to miss and both to insert.',UNIX_TIMESTAMP(),0,UNIX_TIMESTAMP())",
    ),

    'down' => array(
        "DELETE FROM `{P}payplex_lf_config` WHERE ckey IN (
            'legacy_lead_created_hook','legacy_hook_uat_passed_on','lf_conversion_event',
            'follow_up_enabled','drip_hard_suppression','lf_lead_source_ids',
            'phone_backfill_policy','dupe_shared_hosts','webhook_idempotency')",
    ),
);
