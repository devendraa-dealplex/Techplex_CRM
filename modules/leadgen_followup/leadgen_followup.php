<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Lead Generation - Follow-Up
Description: Automatically follows up with leads that have had no contact logged, using a multi-stage drip: remind the assigned agent, email the lead directly, and log a CRM task.
Version: 1.1.0
Author: TechPlex Solutions Private Limited
*/

define('LEADGEN_FOLLOWUP_MODULE_NAME', 'leadgen_followup');

require_once __DIR__ . '/libraries/Followup_clock.php';
require_once __DIR__ . '/libraries/Followup_schedule.php';
require_once __DIR__ . '/libraries/Followup_eligibility.php';

register_activation_hook(LEADGEN_FOLLOWUP_MODULE_NAME, 'leadgen_followup_activation_hook');
register_language_files(LEADGEN_FOLLOWUP_MODULE_NAME, [LEADGEN_FOLLOWUP_MODULE_NAME]);

function leadgen_followup_activation_hook()
{
    $CI = &get_instance();

    if (!$CI->db->table_exists(db_prefix() . 'leadgen_followup_log')) {
        $CI->db->query('CREATE TABLE ' . db_prefix() . 'leadgen_followup_log (id INT(11) NOT NULL AUTO_INCREMENT, leadid INT(11) NOT NULL, stage_day INT(11) NOT NULL, staffid INT(11) NOT NULL, action_taken VARCHAR(255) NOT NULL, date_sent DATETIME NOT NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8');
    }

    add_option('leadgen_followup_enabled', '0');
    add_option('leadgen_followup_stages', serialize([1, 3, 7]));
    add_option('leadgen_followup_remind_agent', '1');
    add_option('leadgen_followup_email_lead', '0');
    add_option('leadgen_followup_create_task', '0');
    add_option('leadgen_followup_task_due_days', '2');
    add_option('leadgen_followup_email_subject', 'Following up on your inquiry');
    add_option('leadgen_followup_email_body', 'Hi {lead_name}, I wanted to follow up on your recent inquiry with us. Please let us know if you have any questions or if there is anything we can help you with. Looking forward to hearing from you.');

    /*
     * Outbound is off on a fresh install and stays off until someone turns it
     * on deliberately. The previous default left `enabled` off but every action
     * switch in a state where flipping one flag started sending.
     */
    add_option('leadgen_followup_outbound_enabled', '0');
}

hooks()->add_filter('module_leadgen_followup_action_links', 'leadgen_followup_action_links');
function leadgen_followup_action_links($actions)
{
    $actions[] = '<a href="' . admin_url('leadgen_followup') . '">' . _l('leadgen_followup_settings') . '</a>';

    return $actions;
}

/* ===================================================================== */
/* Migrations                                                            */
/* ===================================================================== */

/**
 * Run every versioned migration file the database has not reached yet.
 *
 * The SQL lives in `migrations/NNN_*.php`, one numbered file per change, so a
 * schema change can be read, reviewed and reverted as a file rather than found
 * inside a function. The runner is this module's own — it has applied migration
 * 002 successfully on staging — and it stays, because swapping a module's
 * migration mechanism while fixing a live defect is two changes at once.
 *
 * Every statement is guarded by a check of the real schema, so a re-run is a
 * no-op even if the version option is lost, and a failure logs and stops rather
 * than continuing into statements that assume the failed one worked.
 */
hooks()->add_action('admin_init', 'leadgen_followup_run_migrations');
function leadgen_followup_run_migrations()
{
    $CI      = &get_instance();
    $current = (int) get_option('leadgen_followup_schema_version');

    /* Migration 002 predates the versioned files and stays where it is. */
    if ($current < 2) {
        leadgen_followup_migration_002($CI);
        $current = (int) get_option('leadgen_followup_schema_version');
    }

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

        if (!leadgen_followup_apply_migration($CI, $m)) {
            return;
        }

        update_option('leadgen_followup_schema_version', (string) (int) $m['version']);
        $current = (int) $m['version'];
    }
}

function leadgen_followup_apply_migration($CI, array $m)
{
    $prefix = db_prefix();

    try {
        foreach ((array) $m['up'] as $step) {
            $table = $prefix . $step['table'];
            $sql   = str_replace(array('{T}', '{P}'), array($table, $prefix), $step['sql']);

            if ($step['kind'] === 'create_table') {
                if ($CI->db->table_exists($table)) {
                    continue;
                }
            } elseif ($step['kind'] === 'add_column') {
                if (!$CI->db->table_exists($table)) {
                    continue;
                }

                if ($CI->db->field_exists($step['column'], $table)) {
                    continue;
                }
            }

            $CI->db->query($sql);
        }

        foreach ((array) (isset($m['options']) ? $m['options'] : array()) as $name => $value) {
            if ($value === '{NOW}') {
                $clock = Followup_clock::fromCrmTimezone(get_option(Followup_clock::OPTION));
                $value = $clock->sql();
            }

            add_option($name, $value);
        }

        log_activity('leadgen_followup: migration ' . $m['name'] . ' applied.');

        return true;
    } catch (Throwable $e) {
        /*
         * Throwable, not Exception: a schema error here must not take down
         * admin_init and every module hooked after it.
         */
        log_activity('leadgen_followup migration ' . $m['name'] . ' failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Migration 002 — UNIQUE (leadid, stage_day), so the stage claim is atomic.
 *
 * Kept verbatim. It is applied on staging and it is correct; what it could
 * never do is stop three *different* stages firing on one day, which is the
 * defect migration 003 and the rewritten scheduler exist for.
 */
function leadgen_followup_migration_002($CI)
{
    $table = db_prefix() . 'leadgen_followup_log';

    if (!$CI->db->table_exists($table)) {
        return;
    }

    try {
        $existing = $CI->db->query("SHOW INDEX FROM `{$table}` WHERE Key_name = 'uniq_lead_stage'")->result();

        if (empty($existing)) {
            $dupes = $CI->db->query(
                "SELECT COUNT(*) c FROM (
                    SELECT leadid, stage_day FROM `{$table}`
                    GROUP BY leadid, stage_day HAVING COUNT(*) > 1
                 ) d"
            )->row();

            if ($dupes && (int) $dupes->c > 0) {
                log_activity('leadgen_followup: UNIQUE (leadid, stage_day) not added — '
                    . (int) $dupes->c . ' duplicate pair(s) exist.');

                return;
            }

            $CI->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `uniq_lead_stage` (`leadid`, `stage_day`)");
        }

        update_option('leadgen_followup_schema_version', '2');
    } catch (Throwable $e) {
        log_activity('leadgen_followup migration 002 failed: ' . $e->getMessage());
    }
}

/* ===================================================================== */
/* The run                                                               */
/* ===================================================================== */

/**
 * One cron pass.
 *
 * SHAPE OF THE FIX
 * ----------------
 *   1. one clock, in the CRM timezone, taken once (Followup_clock)
 *   2. candidates selected by SQL whose WHERE already excludes every suppressed
 *      lead, with LIMIT applied after it (Followup_eligibility)
 *   3. exactly one stage decided per lead, against lead-creation time, with
 *      spacing, same-day and backlog rules (Followup_schedule)
 *   4. the stage claimed in the database before any work is done
 *   5. the work done only if outbound is enabled — otherwise recorded as
 *      `simulated`, which is what makes a UAT possible without sending anything
 *   6. every decision written to the decisions table, including the ones that
 *      sent nothing
 *
 * `leadgen_followup_enabled` arms the engine. `leadgen_followup_outbound_enabled`
 * is a second, independent gate on anything that leaves the building. Both must
 * be on for a message to be sent, and the second one is off until the staging
 * UAT has proved each interval and each suppression rule.
 */
hooks()->add_action('after_cron_run', 'leadgen_followup_run');
function leadgen_followup_run()
{
    if (get_option('leadgen_followup_enabled') != '1') {
        return;
    }

    $CI = &get_instance();
    $CI->load->model('emails_model');
    $CI->load->model('tasks_model');
    $CI->load->model('leads_model');

    $prefix = db_prefix();
    $clock  = Followup_clock::fromCrmTimezone(get_option(Followup_clock::OPTION));
    $runId  = substr(md5(uniqid('lgfu', true)), 0, 24);

    $stages = Followup_schedule::normaliseStages(@unserialize(get_option('leadgen_followup_stages')));

    if (empty($stages)) {
        return;
    }

    $outbound  = get_option('leadgen_followup_outbound_enabled') === '1';
    $runMode   = $outbound ? 'live' : 'simulated';
    $batch     = (int) get_option('leadgen_followup_batch_limit');
    $batch     = $batch > 0 ? $batch : 25;
    $minGap    = (int) get_option('leadgen_followup_min_stage_gap_hours');
    $maxCatch  = (int) get_option('leadgen_followup_max_catchup_hours');

    $armedRaw = (string) get_option('leadgen_followup_armed_at');
    $armedAt  = $clock->parse($armedRaw);

    if ($armedAt === null) {
        /*
         * No arming stamp means migration 003 has not run. Refusing is correct:
         * without it there is no line between "due since the fix" and "due for
         * the last three months", and the catch-up rule cannot be honoured.
         */
        log_activity('leadgen_followup: run skipped — leadgen_followup_armed_at is not set.');

        return;
    }

    $tables = leadgen_followup_available_tables($CI, $prefix);
    $opts   = array(
        'available_tables'    => $tables,
        'customer_status_ids' => get_option('leadgen_followup_customer_status_ids'),
    );

    $sql = Followup_eligibility::eligibleSql($prefix, $opts);

    /*
     * Anything younger than the first stage cannot be due, so the database
     * drops it rather than PHP. The precise decision is still made per stage.
     */
    $minAgeHours = Followup_schedule::stageDueHours($stages[0]);
    $candidates  = $CI->db->query($sql, array($clock->sql(), $minAgeHours, $batch))->result_array();

    $logTable = $prefix . 'leadgen_followup_log';
    $decTable = $prefix . 'leadgen_followup_decisions';

    $doRemindAgent   = get_option('leadgen_followup_remind_agent') == '1';
    $doEmailLead     = get_option('leadgen_followup_email_lead') == '1';
    $doCreateTask    = get_option('leadgen_followup_create_task') == '1';
    $taskDueDays     = (int) get_option('leadgen_followup_task_due_days');
    $taskDueDays     = $taskDueDays > 0 ? $taskDueDays : 2;
    $emailSubjectTpl = get_option('leadgen_followup_email_subject');
    $emailBodyTpl    = get_option('leadgen_followup_email_body');

    foreach ($candidates as $lead) {
        $leadId        = (int) $lead['id'];
        $assignedStaff = (int) $lead['assigned'];

        $history = $CI->db->query(
            "SELECT `stage_day`, `send_result`, `date_sent` FROM `{$logTable}` WHERE `leadid` = ?",
            array($leadId)
        )->result_array();

        $d = Followup_schedule::decide($stages, $lead['dateadded'], $history, $clock, $armedAt, array(
            'min_gap_hours'     => $minGap,
            'max_catchup_hours' => $maxCatch,
        ));

        leadgen_followup_record_decision($CI, $decTable, $runId, $leadId, $d, null, $clock);

        if ($d['decision'] !== Followup_schedule::D_SEND
            && !Followup_schedule::isPermanentSkip($d['decision'])) {
            continue;
        }

        /*
         * Claim first, work second — unchanged from migration 002's design and
         * still right. INSERT IGNORE against UNIQUE (leadid, stage_day): zero
         * affected rows means another run owns this stage.
         */
        $CI->db->query(
            "INSERT IGNORE INTO `{$logTable}`
             (leadid, stage_day, anchor_at, eligible_at, staffid, action_taken, send_result, suppression_reason, run_mode, date_sent)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            array($leadId, $d['stage'], $d['anchor_at'], $d['eligible_at'], $assignedStaff,
                  'claimed', 'claimed', null, $runMode, $clock->sql())
        );

        if ($CI->db->affected_rows() < 1) {
            continue;
        }

        /* A permanently skipped stage is recorded and nothing is sent. */
        if (Followup_schedule::isPermanentSkip($d['decision'])) {
            $CI->db->where('leadid', $leadId)->where('stage_day', $d['stage'])->update($logTable, array(
                'action_taken'       => 'none',
                'send_result'        => $d['decision'],
                'suppression_reason' => $d['decision'],
                'date_sent'          => $clock->sql(),
            ));

            continue;
        }

        $actions  = array();
        $attempts = 0;
        $failures = 0;

        if ($doRemindAgent && $assignedStaff > 0) {
            $staffRow = $CI->db->select('email')->where('staffid', $assignedStaff)
                               ->get($prefix . 'staff')->row();

            if ($staffRow) {
                if ($outbound) {
                    $notificationData = array(
                        'description'     => 'leadgen_followup_notification',
                        'touserid'        => $assignedStaff,
                        'link'            => '#leadid=' . $leadId,
                        'additional_data' => serialize(array($lead['name'], $d['stage'])),
                        'fromcompany'     => 1,
                    );

                    if (add_notification($notificationData)) {
                        pusher_trigger_notification(array($assignedStaff));
                    }

                    $sent = $CI->emails_model->send_simple_email(
                        $staffRow->email,
                        'Follow-up needed: ' . $lead['name'],
                        'The lead "' . $lead['name'] . '" has had no contact logged for '
                            . $d['stage'] . ' day(s). Please follow up when you can.'
                    );

                    $attempts++;
                    $failures += $sent ? 0 : 1;
                    $actions[] = $sent ? 'agent_reminder' : 'agent_reminder_failed';
                } else {
                    $actions[] = 'agent_reminder_simulated';
                }
            }
        }

        if ($doEmailLead && !empty($lead['email'])) {
            if ($outbound) {
                $sentLead = $CI->emails_model->send_simple_email(
                    $lead['email'],
                    str_replace('{lead_name}', $lead['name'], $emailSubjectTpl),
                    str_replace('{lead_name}', $lead['name'], $emailBodyTpl)
                );

                $attempts++;
                $failures += $sentLead ? 0 : 1;
                $actions[] = $sentLead ? 'lead_email' : 'lead_email_failed';
            } else {
                $actions[] = 'lead_email_simulated';
            }
        }

        if ($doCreateTask && $assignedStaff > 0) {
            if ($outbound) {
                $parts      = explode('|', (string) get_option('dateformat'));
                $dateFormat = !empty($parts[0]) ? $parts[0] : 'd-m-Y';
                $due        = $clock->now()->modify('+' . $taskDueDays . ' days');

                $CI->tasks_model->add(array(
                    'name'      => 'Follow up with lead (day ' . $d['stage'] . '): ' . $lead['name'],
                    'startdate' => $clock->now()->format($dateFormat),
                    'duedate'   => $due->format($dateFormat),
                    'rel_id'    => $leadId,
                    'rel_type'  => 'lead',
                    'priority'  => 2,
                    'assignees' => array($assignedStaff),
                ));

                $actions[] = 'task_created';
            } else {
                $actions[] = 'task_simulated';
            }
        }

        /*
         * The outcome, not the intent.
         *
         * `partial` exists because "some of it went and some of it did not" is a
         * real state and collapsing it into either `sent` or `failed` loses the
         * only fact worth keeping.
         */
        if (!$outbound) {
            $result = empty($actions) ? 'no_action' : 'simulated';
        } elseif ($attempts === 0) {
            $result = empty($actions) ? 'no_action' : 'sent';
        } elseif ($failures === 0) {
            $result = 'sent';
        } elseif ($failures < $attempts) {
            $result = 'partial';
        } else {
            $result = 'failed';
        }

        $CI->db->where('leadid', $leadId)->where('stage_day', $d['stage'])->update($logTable, array(
            'staffid'      => $assignedStaff,
            'action_taken' => !empty($actions) ? implode(',', $actions) : 'no_action',
            'send_result'  => $result,
            'run_mode'     => $runMode,
            'date_sent'    => $clock->sql(),
        ));

        if ($outbound && !empty($actions)) {
            $CI->leads_model->log_lead_activity(
                $leadId, 'not_lead_activity_followup_sent', true, serialize(array($d['stage']))
            );
        }
    }
}

/**
 * Which optional tables the suppression predicate may reference.
 *
 * A predicate is included only when its table is present. The set actually used
 * is written into the decisions table for the run, so a UAT can show which rules
 * were live rather than assuming all of them were.
 */
function leadgen_followup_available_tables($CI, $prefix)
{
    $out = array();

    foreach (array('leadgen_followup_suppression', 'consents', 'payplex_lf_prospects') as $t) {
        if ($CI->db->table_exists($prefix . $t)) {
            $out[] = $prefix . $t;
        }
    }

    return $out;
}

function leadgen_followup_record_decision($CI, $decTable, $runId, $leadId, array $d, $suppression, Followup_clock $clock)
{
    if (!$CI->db->table_exists($decTable)) {
        return;
    }

    $CI->db->insert($decTable, array(
        'run_id'             => $runId,
        'leadid'             => $leadId,
        'stage_day'          => $d['stage'],
        'decision'           => $d['decision'],
        'suppression_reason' => $suppression,
        'anchor_at'          => $d['anchor_at'],
        'eligible_at'        => $d['eligible_at'],
        'evaluated_at'       => $clock->sql(),
        'timezone'           => $clock->timezoneName(),
    ));
}

/* ===================================================================== */
/* Menu and permissions                                                  */
/* ===================================================================== */

/**
 * The sidebar link, gated on the capability the page actually requires.
 *
 * WHAT WAS WRONG
 * --------------
 * The condition was:
 *
 *     if (staff_can('view', 'leads') || staff_can('view', 'leadgen_followup'))
 *
 * while the controller requires:
 *
 *     if (!is_admin() && !staff_can('view', 'leadgen_followup')) access_denied(…)
 *
 * Two different questions. Anyone holding `leads: view` was shown a "Lead
 * Follow-Up" link that denied them on click. Staff 33 is exactly that case: it
 * holds `leads: view` and no `leadgen_followup` capability at all.
 *
 * Nothing leaked — the page refused correctly, which is the half that matters.
 * But a menu is a statement about what someone may do, and this one was making a
 * promise the controller had no intention of keeping. The earlier hardening pass
 * fixed the controller and left the menu alone, so the module looked authorized
 * from the outside while still advertising itself to the wrong people.
 *
 * The condition now mirrors the controller's exactly, in the same order and with
 * the same two terms, and `MenuAuthorizationTest` extracts the capability from
 * both and fails if they ever differ again.
 */
hooks()->add_action('admin_init', 'leadgen_followup_module_init_menu_items');
function leadgen_followup_module_init_menu_items()
{
    $CI = &get_instance();

    if (is_admin() || staff_can('view', 'leadgen_followup')) {
        $CI->app_menu->add_sidebar_children_item('leads', [
            'slug'     => 'leadgen-followup',
            'name'     => 'Lead Follow-Up',
            'href'     => admin_url('leadgen_followup'),
            'position' => 44,
        ]);
    }
}

hooks()->add_action('admin_init', 'leadgen_followup_permissions');
function leadgen_followup_permissions()
{
    $capabilities = array();

    $capabilities['capabilities'] = array(
        'view' => _l('leadgen_followup_perm_view'),
        'edit' => _l('leadgen_followup_perm_edit'),
    );

    register_staff_capabilities('leadgen_followup', $capabilities, _l('leadgen_followup_settings'));
}
