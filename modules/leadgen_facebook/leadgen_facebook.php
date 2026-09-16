<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Lead Generation - Facebook
Description: Capture leads from Facebook Lead Ads and Messenger via the Meta Graph API, with signed inbound verification, an append-only delivery log, idempotent transactional lead creation and configurable assignment.
Version: 2.3.0
Requires at least: 2.3.*
Author: TechPlex Solutions Private Limited
*/

define('LEADGEN_FACEBOOK_MODULE_NAME', 'leadgen_facebook');

require_once __DIR__ . '/libraries/Facebook_settings.php';
require_once __DIR__ . '/libraries/Facebook_redactor.php';
require_once __DIR__ . '/libraries/Facebook_delivery.php';
require_once __DIR__ . '/libraries/Facebook_status.php';
require_once __DIR__ . '/libraries/Facebook_assignment.php';
require_once __DIR__ . '/libraries/Facebook_guard.php';
require_once __DIR__ . '/libraries/Facebook_health.php';
require_once __DIR__ . '/libraries/Facebook_tagging.php';
require_once __DIR__ . '/libraries/Facebook_identity.php';

register_language_files(LEADGEN_FACEBOOK_MODULE_NAME, [LEADGEN_FACEBOOK_MODULE_NAME]);
register_activation_hook(LEADGEN_FACEBOOK_MODULE_NAME, 'leadgen_facebook_activation_hook');

/**
 * Activation.
 *
 * Seeds nothing and creates nothing directly any more — it defers to the
 * migration runner, so there is exactly one code path that changes this
 * module's schema and it is made of reviewable, numbered, reversible files.
 *
 * The previous version put a `CREATE TABLE IF NOT EXISTS` and five
 * `add_option()` calls inside this function AND hooked the same function to
 * `admin_init`, so the schema was re-asserted on every single admin page load
 * by code that lived inside a function rather than in a migration. That works
 * until the day a statement in it needs to change, at which point there is no
 * record of what ran, when, or against which install.
 */
function leadgen_facebook_activation_hook()
{
    leadgen_facebook_run_migrations();
}

hooks()->add_filter('module_leadgen_facebook_action_links', 'leadgen_facebook_action_links');
function leadgen_facebook_action_links($actions)
{
    $actions[] = '<a href="' . admin_url('leadgen_facebook') . '">'
        . _l('leadgen_facebook_settings') . '</a>';

    return $actions;
}

/* ===================================================================== */
/* Migrations                                                            */
/* ===================================================================== */

/**
 * Apply every versioned migration the database has not reached.
 *
 * Same runner shape as leadgen_followup, which has applied its migrations on
 * staging without incident: numbered files in `migrations/`, each statement
 * guarded against the real schema so a re-run is a no-op, each failure logged
 * and the run stopped rather than continuing into statements that assume the
 * failed one worked.
 *
 * Hooked on `admin_init` rather than on a cron so the schema is in place before
 * any page reads it, and because the public webhook route must never be the
 * thing that runs a migration: an unauthenticated request is not an acceptable
 * trigger for an ALTER TABLE.
 */
hooks()->add_action('admin_init', 'leadgen_facebook_run_migrations');
function leadgen_facebook_run_migrations()
{
    $CI = &get_instance();
    $current = (int) get_option('facebook_schema_version');

    $files = glob(__DIR__ . '/migrations/[0-9][0-9][0-9]_*.php');

    if (!is_array($files)) {
        return;
    }

    sort($files);

    foreach ($files as $file) {
        $m = include $file;

        if (!is_array($m) || empty($m['version'])) {
            continue;
        }

        if ((int) $m['version'] <= $current) {
            continue;
        }

        if (!leadgen_facebook_apply_migration($CI, $m)) {
            return;
        }

        update_option('facebook_schema_version', (string) (int) $m['version']);
        $current = (int) $m['version'];
    }
}

/* ===================================================================== */
/* Uncached schema reads                                                 */
/* ===================================================================== */

/**
 * Does this table exist, right now?
 *
 * NOT `$CI->db->table_exists()`. That is the whole point of this function.
 *
 * WHAT WENT WRONG ON PRODUCTION, MEASURED
 * ---------------------------------------
 * CodeIgniter caches the table list in `$db->data_cache['table_names']` on
 * first use, and `table_exists()` reads that cache. `field_exists()` caches a
 * field list per table the same way. Both caches are populated at the start of
 * a request and are never invalidated by DDL issued through `$db->query()`.
 *
 * All three migrations run in one request — 001 creates the tables, 002 and 003
 * alter them. So when 002's `add_column` guard asked "does
 * tblleadgen_facebook_messages exist?", it asked a cache built before 001 had
 * created it, got **false**, and did `continue`. Every one of the eight columns
 * was skipped. Then 003's `source_key` column was skipped for the same reason.
 *
 * And the runner recorded success: `facebook_schema_version` was set to 3, the
 * activity log said "migration applied", and the real schema had none of it.
 *
 * Measured on production immediately after activation:
 *
 *   facebook_schema_version                = 3
 *   tblleadgen_facebook_messages columns   = 7   (expected 15)
 *   tblleadgen_facebook_deliveries.source_key = absent
 *
 * That is the defect shape this programme keeps finding: a control that
 * reports success and cannot report failure. It would have broken every
 * delivery — `claimAndCreateLead()` inserts `page_id`, `form_id`,
 * `campaign_id`, `request_id`, `assignment_mode`, `assigned_to` and
 * `needs_review` into columns that do not exist, so every genuine Meta lead
 * would have hit an SQL error, rolled back, answered 500, and been retried
 * forever.
 *
 * So the guards read the live schema with `SHOW`, uncached, every time.
 */
function leadgen_facebook_table_exists($CI, $table)
{
    $rows = $CI->db->query('SHOW TABLES LIKE ' . $CI->db->escape($table))->result_array();

    return !empty($rows);
}

function leadgen_facebook_column_exists($CI, $table, $column)
{
    if (!leadgen_facebook_table_exists($CI, $table)) {
        return false;
    }

    $rows = $CI->db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $CI->db->escape($column))->result_array();

    return !empty($rows);
}

function leadgen_facebook_index_exists($CI, $table, $index)
{
    if (!leadgen_facebook_table_exists($CI, $table)) {
        return false;
    }

    $rows = $CI->db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $CI->db->escape($index))->result_array();

    return !empty($rows);
}

/**
 * Did the step actually take effect?
 *
 * Checked after the statement runs, against the live schema. This is the half
 * that turns a silent skip into a loud failure: previously a step could be
 * skipped by a stale guard and the migration would still be marked applied.
 * Now a step that was supposed to create something, and did not, stops the run
 * and leaves the version where it was, so the next admin page load tries again
 * instead of pretending the schema is current.
 */
function leadgen_facebook_step_satisfied($CI, $table, array $step)
{
    switch ($step['kind']) {
        case 'create_table':
            return leadgen_facebook_table_exists($CI, $table);
        case 'add_column':
            return leadgen_facebook_column_exists($CI, $table, $step['column']);
        case 'add_index':
        case 'add_unique_index':
            return leadgen_facebook_index_exists($CI, $table, $step['index']);
        case 'insert_row_if_absent':
            return leadgen_facebook_row_exists($CI, $table, $step['unique']);
    }

    return true;
}

/**
 * Does a row matching this uniqueness key already exist?
 *
 * The guard for `insert_row_if_absent`. Read live like every other guard — the
 * cached-schema defect that produced a schema_version of 4 over a 7-column
 * table was a guard reading a stale cache, and a row guard reading a stale
 * result set would reintroduce exactly that shape.
 *
 * The key columns are escaped individually rather than interpolated: this
 * builds a WHERE clause, and a migration file is reviewed code rather than
 * untrusted input, but "it is only ever called with our own literals" is the
 * assumption that stops being true the first time somebody parameterises it.
 */
/**
 * The column names an index step covers, whatever shape they were declared in.
 *
 * FOUND ON STAGING, BY THE MIGRATION FAILING
 * ------------------------------------------
 * Migrations 001-003 declared `columns` as an array; 006 and 007 declared it as
 * a backticked string, because that reads closer to the SQL beside it. The
 * duplicate pre-count below imploded it, and on PHP 8 `implode()` given a string
 * throws — so migration 007 died on its first UNIQUE index with
 * "Argument #2 must be of type array", leaving the table created, the index
 * absent, and the version correctly NOT advanced.
 *
 * The runner tolerating both shapes is the smaller half of the fix; the
 * discipline test now also requires the declared columns to match the SQL, so a
 * declaration that drifts from the statement it describes fails the build rather
 * than the migration.
 */
function leadgen_facebook_index_columns(array $step)
{
    $raw = isset($step['columns']) ? $step['columns'] : array();

    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = explode(',', (string) $raw);
    }

    $out = array();

    foreach ($parts as $part) {
        $name = trim(str_replace('`', '', (string) $part));

        if ($name !== '' && preg_match('/\A[a-z_][a-z0-9_]*\z/i', $name) === 1) {
            $out[] = $name;
        }
    }

    return $out;
}

function leadgen_facebook_row_exists($CI, $table, array $unique)
{
    if (!leadgen_facebook_table_exists($CI, $table)) {
        return false;
    }

    $where = array();

    foreach ($unique as $column => $value) {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/i', (string) $column) !== 1) {
            return false;   /* refuse an unexpected column name outright */
        }

        $where[] = '`' . $column . '` = ' . $CI->db->escape($value);
    }

    if (empty($where)) {
        return false;
    }

    $rows = $CI->db->query(
        "SELECT 1 FROM `{$table}` WHERE " . implode(' AND ', $where) . ' LIMIT 1'
    )->result_array();

    return !empty($rows);
}

function leadgen_facebook_apply_migration($CI, array $m)
{
    $prefix = db_prefix();

    try {
        foreach ((array) $m['up'] as $step) {
                $table = $prefix . $step['table'];
            $sql = isset($step['sql'])
                ? str_replace(array('{T}', '{P}'), array($table, $prefix), $step['sql'])
                : '';

            /*
             * Already satisfied? Then this is a re-run and there is nothing to
             * do. One check covers every kind, read live, so "skip because it
             * is already there" and "skip because a cache said the table was
             * missing" can no longer be confused for each other.
             */
            if (leadgen_facebook_step_satisfied($CI, $table, $step)) {
                continue;
            }

            if ($step['kind'] === 'add_column' || $step['kind'] === 'add_index') {
                /*
                 * These need their table. If it is genuinely absent something
                 * earlier failed, so stop rather than skip — skipping is what
                 * produced a schema_version of 3 over a 7-column table.
                 */
                if (!leadgen_facebook_table_exists($CI, $table)) {
                    log_activity('leadgen_facebook: migration ' . $m['name'] . ' stopped — table '
                        . $table . ' does not exist.');

                    return false;
                }
            }

            if ($step['kind'] === 'add_unique_index') {
                if (!leadgen_facebook_table_exists($CI, $table)) {
                    log_activity('leadgen_facebook: migration ' . $m['name'] . ' stopped — table '
                        . $table . ' does not exist.');

                    return false;
                }

                /*
                 * A UNIQUE key cannot be added over data that already violates
                 * it, and the failure would come back as a mid-ALTER error with
                 * the migration half applied. Count the offenders first and
                 * refuse with a message a human can act on.
                 *
                 * This is the one case where stopping is right and skipping is
                 * wrong for a different reason: the data needs a human.
                 */
                $cols = implode('`, `', leadgen_facebook_index_columns($step));
                $dupes = $CI->db->query(
                    "SELECT COUNT(*) c FROM (
                        SELECT 1 FROM `{$table}` GROUP BY `{$cols}` HAVING COUNT(*) > 1
                     ) d"
                )->row();

                if ($dupes && (int) $dupes->c > 0) {
                    log_activity('leadgen_facebook: UNIQUE ' . $step['index'] . ' not added — '
                        . (int) $dupes->c . ' duplicate group(s) exist. Resolve them first.');

                    return false;
                }
            }

            if ($step['kind'] === 'insert_row_if_absent') {
                /*
                 * A row seed rather than a statement. Built through the query
                 * builder so every value is escaped, and guarded above so a
                 * re-run inserts nothing.
                 */
                if (!leadgen_facebook_table_exists($CI, $table)) {
                    log_activity('leadgen_facebook: migration ' . $m['name'] . ' stopped — table '
                        . $table . ' does not exist.');

                    return false;
                }

                /*
                 * The row is narrowed to the columns this install actually has.
                 *
                 * WHY THIS IS NOT SLOPPINESS
                 * --------------------------
                 * The only row seed so far writes a CUSTOM FIELD DEFINITION
                 * into a core Perfex table, and the display flags on that table
                 * have moved between Perfex releases — a column present on one
                 * 2.3.x install is absent on another. Sending a column that
                 * does not exist makes the insert throw, which this runner
                 * handles correctly (it stops and does not advance the version)
                 * and which leaves the field missing on a perfectly healthy
                 * install for a reason nobody can see from the CRM.
                 *
                 * So the columns that carry MEANING — the ones in the
                 * uniqueness key — are required, and a missing one is a hard
                 * stop below. The rest are presentation, and an install that
                 * lacks one simply gets Perfex's own default for it.
                 */
                $row = array();
                $dropped = array();

                foreach ($step['row'] as $column => $value) {
                    if (leadgen_facebook_column_exists($CI, $table, $column)) {
                        $row[$column] = $value;
                    } else {
                        $dropped[] = $column;
                    }
                }

                foreach (array_keys($step['unique']) as $keyColumn) {
                    if (!array_key_exists($keyColumn, $row)) {
                        log_activity('leadgen_facebook: migration ' . $m['name'] . ' stopped — '
                            . $table . ' has no column ' . $keyColumn
                            . ', which the row guard matches on.');

                        return false;
                    }
                }

                if (!empty($dropped)) {
                    log_activity('leadgen_facebook: migration ' . $m['name'] . ' seeded a row into '
                        . $table . ' without column(s) ' . implode(', ', $dropped)
                        . ' — this Perfex install does not have them.');
                }

                $CI->db->insert($table, $row);
            } else {
                $CI->db->query($sql);
            }

            /*
             * And verify it landed. A statement that ran without throwing but
             * changed nothing is the failure that has to be caught here,
             * because nothing downstream will notice until a delivery arrives.
             */
            if (!leadgen_facebook_step_satisfied($CI, $table, $step)) {
                log_activity('leadgen_facebook: migration ' . $m['name'] . ' step for '
                    . $table . ' ran but did not take effect; version not advanced.');

                return false;
            }
        }

        foreach ((array) (isset($m['options']) ? $m['options'] : array()) as $name => $value) {
            add_option($name, $value);
        }

        log_activity('leadgen_facebook: migration ' . $m['name'] . ' applied and verified.');

        return true;
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception. A schema error must not take down
         * admin_init and every module hooked after this one.
         */
        log_activity('leadgen_facebook migration ' . $m['name'] . ' failed: ' . $e->getMessage());

        return false;
    }
}

/* ===================================================================== */
/* Menu and capabilities                                                 */
/* ===================================================================== */

/**
 * The sidebar item.
 *
 * THE GATE THAT WAS WRONG
 * -----------------------
 *     if (staff_can('view', 'leads') || staff_can('view', 'leadgen_facebook'))
 *
 * The first half of that OR is satisfied by anyone who can see the Leads
 * module, which on this install is most of the sales team. They were shown a
 * "Facebook Leads" link and then refused at the controller, because the
 * controller checks a different permission from the menu. A link that everyone
 * can see and few can follow is worse than no link: it reads as a broken CRM
 * rather than as a permission boundary.
 *
 * This is the same defect that was found and fixed in leadgen_followup, from
 * the same copied line. The menu and the route now require the same capability,
 * and a test asserts they do by extracting the capability set from both.
 */
hooks()->add_action('admin_init', 'leadgen_facebook_module_init_menu_items');
function leadgen_facebook_module_init_menu_items()
{
    $CI = &get_instance();

    if (is_admin() || staff_can('view', 'leadgen_facebook')) {
        $CI->app_menu->add_sidebar_children_item('leads', [
            'slug'     => 'leadgen-facebook',
            'name'     => 'Facebook Leads',
            'href'     => admin_url('leadgen_facebook'),
            'position' => 41,
        ]);
    }
}

/**
 * This module's own named capabilities.
 *
 * `view` opens the settings page, the delivery log and the review queue.
 * `edit` is required to save settings, and is separate because reading the
 * delivery log is a diagnostic activity while changing the app secret is not.
 */
hooks()->add_action('admin_init', 'leadgen_facebook_permissions');
function leadgen_facebook_permissions()
{
    $capabilities = array();

    $capabilities['capabilities'] = array(
        'view' => _l('leadgen_facebook_perm_view'),
        'edit' => _l('leadgen_facebook_perm_edit'),
    );

    register_staff_capabilities('leadgen_facebook', $capabilities, _l('leadgen_facebook_settings'));
}
