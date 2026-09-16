<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or defined('PAYPLEX_LF_PREFLIGHT') or exit('No direct script access allowed');
/**
 * Lead Finder migration 116 — the approved retention periods.
 *
 * WHAT THIS SETS, AND WHO DECIDED IT
 * ----------------------------------
 * Migration 113 seeded both of these blank, on the grounds that how long a
 * business's rejected contact details are kept is a decision with a legal
 * dimension and is not something a migration invents. That decision has now
 * been made and approved:
 *
 *   waste_pii_retention_days  = 7    contact details purged seven days after
 *                                    the waste decision
 *   waste_suppression_days    = 365  the HMAC suppression tombstone is kept a
 *                                    year, after which ORDINARY WASTE
 *                                    suppression may expire
 *
 * WHAT IT DELIBERATELY DOES NOT TOUCH
 * -----------------------------------
 * Do Not Contact. A DNC suppression does not expire through the ordinary waste
 * policy and is never deleted by the retention sweep; the 365 days above do not
 * apply to it and `Leadfinder_tombstone::mayDeleteTombstone()` refuses a DNC row
 * outright whatever period is configured. Correcting a DNC record is a separate,
 * authorised administrative or legal revocation with its own evidence — not an
 * expiry, not an undo, and not implemented here.
 *
 * It also does not touch the suppression keyring, which is a file outside the
 * document root and outside this database. Nothing in this migration reads or
 * references it.
 *
 * WHY UPDATE AND NOT INSERT
 * -------------------------
 * The two rows already exist, seeded blank by 113. An INSERT would give each key
 * a second row and the effective value would then depend on which one the reader
 * happened to pick — which is the kind of ambiguity that shows up months later as
 * "the purge ran early" and cannot be reconstructed.
 *
 * The UPDATE is scoped by `ckey` to exactly these two rows, and is conditional on
 * the current value still being blank: if an administrator has already set a
 * period through the settings screen, this migration leaves their decision alone
 * rather than silently overwriting it with the figure approved today.
 *
 * SCOPE
 * -----
 * Two configuration rows on a module-owned table. No core table is touched,
 * nothing is created, nothing is dropped, no prospect or lead is read or written.
 */
return array(
    'id'          => 116,
    'module'      => 'payplex_leadfinder',
    'title'       => 'Approved retention periods: 7-day PII purge, 365-day waste suppression',
    'destructive' => false,
    'touches_core_tables' => false,
    'depends_on'  => 115,

    'preflight' => array(
        'both_keys_exist' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM `{P}payplex_lf_config`
                          WHERE ckey IN ('waste_pii_retention_days','waste_suppression_days')",
            'must_be' => 2,
            'if_not'  => 'DO NOT APPLY. Migration 113 has not run, or one of the two keys is missing. '
                       . 'This migration updates rows; it does not create them, because a second row '
                       . 'for the same key makes the effective value arbitrary.',
        ),
        'no_duplicate_keys' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM (
                            SELECT ckey FROM `{P}payplex_lf_config`
                            WHERE ckey IN ('waste_pii_retention_days','waste_suppression_days')
                            GROUP BY ckey HAVING COUNT(*) > 1
                          ) x",
            'must_be' => 0,
            'if_not'  => 'STOP AND REPORT. One of the retention keys already has more than one row, '
                       . 'so the value the module is reading today is not determined. Reconcile by '
                       . 'hand before setting a retention period.',
        ),
        'keyring_untouched_control' => array(
            'sql'     => "SELECT COUNT(*) AS n FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = '{DB}' AND TABLE_NAME = '{P}payplex_lf_config'
                            AND COLUMN_NAME = 'cvalue'",
            'must_be' => 1,
            'if_not'  => 'DO NOT APPLY. The config table is not the shape this migration expects. '
                       . 'This check is also the control: it must return 1, so a predicate that '
                       . 'has stopped matching anything fails the gate instead of passing it.',
        ),
    ),

    'reports' => array(
        'current_values' => "SELECT ckey, IF(cvalue='','(blank)',cvalue) AS current_value, set_by, set_at
                             FROM `{P}payplex_lf_config`
                             WHERE ckey IN ('waste_pii_retention_days','waste_suppression_days')
                             ORDER BY ckey",
        'waste_rows_affected' => "SELECT COUNT(*) AS wasted_rows_that_will_become_purgeable
                                  FROM `{P}payplex_lf_prospects`
                                  WHERE `wasted_at` IS NOT NULL AND `undone_at` IS NULL
                                    AND `pii_purged_at` IS NULL",
    ),

    'up' => array(
        /* Conditional on still being blank: an administrator's own later
           decision is not overwritten by the figure approved today. */
        "UPDATE `{P}payplex_lf_config`
            SET cvalue = '7',
                source_note = 'APPROVED 2026-09-13. Raw waste contact details are purged seven days after the decision. The HMAC suppression tombstone is written BEFORE the purge and survives it; only the contact details go. Audit metadata proving who marked the record and why is never cleared by the purge. Changeable by an administrator through Lead Finder > Waste and suppression, where it is validated as a pair with the suppression period.',
                set_at = UNIX_TIMESTAMP()
          WHERE ckey = 'waste_pii_retention_days' AND cvalue = ''",

        "UPDATE `{P}payplex_lf_config`
            SET cvalue = '365',
                source_note = 'APPROVED 2026-09-13. An ordinary WASTE suppression tombstone is retained for 365 days, after which the suppression may expire according to policy. This value does NOT govern Do Not Contact: a DNC suppression never expires, is never deleted by the sweep, and is corrected only through a separate authorised administrative or legal revocation workflow.',
                set_at = UNIX_TIMESTAMP()
          WHERE ckey = 'waste_suppression_days' AND cvalue = ''",
    ),

    'down' => array(
        /* Back to blank, which fails closed: the purge refuses to run and an
           administrator is alerted. Rolling back does not quietly restore a
           different period. */
        "UPDATE `{P}payplex_lf_config`
            SET cvalue = '', set_at = UNIX_TIMESTAMP()
          WHERE ckey = 'waste_pii_retention_days' AND cvalue = '7'",

        "UPDATE `{P}payplex_lf_config`
            SET cvalue = '', set_at = UNIX_TIMESTAMP()
          WHERE ckey = 'waste_suppression_days' AND cvalue = '365'",
    ),

    'down_warning' => 'Rolling back returns both periods to blank, which stops the contact-detail '
                    . 'purge entirely and raises an administrator alert. That is the safe direction '
                    . 'for an hour, not for a quarter: it means rejected businesses keep their '
                    . 'contact details with no stated basis. No suppression record, no Do Not Contact '
                    . 'entry and no audit row is affected, and the keyring is not touched. Export '
                    . 'payplex_lf_config before rolling back if the current values matter.',
);
