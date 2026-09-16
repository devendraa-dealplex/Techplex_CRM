<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reminder dispatcher.
 *
 * Deliberately NOT an AdminController: it runs from the system crontab with no session.
 * Access is gated by a per-install token generated at activation and stored in options
 * (pm_cron_token), compared with hash_equals so the check is not timing-sensitive.
 *
 * Install (see README):
 *   *_/5 * * * * /usr/bin/php /path/to/crm/index.php payplex_meetings/meetings_cron/run TOKEN
 * or, if CLI routing is unavailable:
 *   *_/5 * * * * curl -fsS "https://crm.example.com/payplex_meetings/meetings_cron/run/TOKEN"
 */
class Meetings_cron extends CI_Controller
{
    /** Set when construction fails, so the method bodies can report instead of running. */
    protected $boot_error = null;

    public function __construct()
    {
        parent::__construct();

        /*
         * Every request to this controller answers HTTP 500 with an empty body —
         * a valid token, a wrong token and no token alike, so the gate below
         * never gets to reply. Routing is fine: a missing method on this same
         * controller answers 404. So something here or in the method body
         * fatals, and there is no way to see what, because this install has
         * display_errors off and CI's log_threshold resolves to 0 (the newest
         * file in application/logs is weeks old and the docroot error_log holds
         * nothing for any of these requests).
         *
         * A blank 500 with no trace is how a dead scheduler stays dead. Six
         * reminders for the one meeting on this install are still 'pending',
         * two of them due about twenty hours ago, with attempts 0 and no error
         * recorded — so nothing has even tried.
         *
         * Rather than guess at the cause a third time, this records it. Throwable
         * and not Exception: a loader failure or a TypeError is an Error, and
         * `catch (Exception)` — which is what this module used everywhere — does
         * not catch those. That alone would explain an invisible failure.
         */
        try {
            $this->load->model('payplex_meetings/payplex_meetings_model', 'pm');
            $this->load->model('payplex_meetings/payplex_meeting_reminders_model', 'pm_reminders');
        } catch (Throwable $e) {
            $this->boot_error = get_class($e) . ': ' . $e->getMessage()
                              . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
            $this->record_boot_error();
        }
    }

    /**
     * Write the construction failure where it can actually be read.
     *
     * log_message() goes to a log this install does not write. The module owns
     * tblpayplex_meeting_activity_logs and that table is readable, so the error
     * goes there. Deliberately defensive: if even the database is unreachable,
     * this must not itself fatal and hide the thing it exists to reveal.
     */
    protected function record_boot_error()
    {
        try {
            if (!function_exists('db_prefix')) { return; }
            /*
             * Columns read from the table, not assumed. There is no 'detail'
             * column here — the message goes in 'reason', which is the text
             * field this table already uses for why something happened. Two
             * queries earlier in this audit failed against invented column
             * names; a diagnostic that cannot write is worse than none.
             */
            $this->db->insert(db_prefix() . 'payplex_meeting_activity_logs', array(
                'meeting_id'   => 0,
                'rel_type'     => 'cron',
                'rel_id'       => 0,
                'staff_id'     => 0,
                'action'       => 'cron.boot_failed',
                'reason'       => substr((string) $this->boot_error, 0, 2000),
                'date_created' => date('Y-m-d H:i:s'),
            ));
        } catch (Throwable $ignored) {
            // nothing further can be done, and throwing here would hide the cause
        }
    }

    /**
     * Does nothing, on purpose.
     *
     * The boot_error try/catch added alongside this could not see the failure,
     * and it never will: CI's loader answers a missing class with show_error(),
     * which echoes and calls exit() rather than throwing. Nothing catchable
     * happens, so a try/catch is the wrong instrument and saying otherwise
     * would be the third guess in a row.
     *
     * This is the right instrument. It reaches no model, no library, no helper
     * and no setting — only the constructor runs before it. If this answers,
     * construction is fine and the fatal is inside run()/status(). If this
     * answers 500 too, the fault is in the constructor or earlier, and the
     * parent::__construct() of a plain CI_Controller in a Perfex install is
     * then the thing to look at.
     *
     * Unauthenticated by design: it reveals nothing but the word pong, and
     * gating it behind the token would defeat its purpose, since the token
     * check is one of the things under suspicion.
     */
    public function ping()
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'pong'         => true,
            'boot_error'   => $this->boot_error,
            'has_model'    => isset($this->pm_reminders),
            'helper_loaded'=> function_exists('pm_setting'),
            'php'          => PHP_VERSION,
        ));
        exit;
    }

    public function run($token = null)
    {
        if ($this->boot_error !== null) {
            $this->respond(['success' => false, 'message' => 'boot_failed', 'error' => $this->boot_error], 500);
        }

        if (!$this->authorised($token)) {
            $this->respond(['success' => false, 'message' => 'unauthorised'], 403);
        }

        $started = microtime(true);

        // 1. Retire reminders whose window passed while the dispatcher was down.
        //    These are recorded as failed with reason 'window_missed', never sent late.
        $expired = $this->pm_reminders->expire_missed();

        // 2. Claim and send.
        /* Same package-path problem as the hook version; see payplex_meetings.php. */
        $this->load->add_package_path(dirname(__DIR__));
        $this->load->library('payplex_meeting_mailer');

        $sent   = 0;
        $failed = 0;
        $this->ensure_helper();
        $batch  = (int) pm_setting('dispatch_batch', 50);
        $rows   = $this->pm_reminders->claim_due($batch);

        foreach ($rows as $reminder) {
            try {
                $result = $this->payplex_meeting_mailer->send_reminder($reminder);

                if (!empty($result['success'])) {
                    $this->pm_reminders->mark_sent($reminder->id);
                    $sent++;
                } else {
                    $this->pm_reminders->mark_failed($reminder->id, $result['error'] ?? 'unknown');
                    $failed++;
                }
            } catch (Throwable $e) {
                $this->pm_reminders->mark_failed($reminder->id, $e->getMessage());
                $failed++;
            }
        }

        // 3. Move meetings whose start time has passed into in_progress, and past-end
        //    meetings into the pending-completion queue (status stays, outcome empty).
        $transitioned = $this->transition_started();

        $this->respond([
            'success'      => true,
            'claimed'      => count($rows),
            'sent'         => $sent,
            'failed'       => $failed,
            'expired'      => $expired,
            'transitioned' => $transitioned,
            'duration_ms'  => (int) ((microtime(true) - $started) * 1000),
            'ran_at_utc'   => gmdate('c'),
        ]);
    }

    /**
     * Health endpoint for monitoring: reports whether the dispatcher is actually running,
     * because a silent scheduler is the failure mode that costs the most.
     */
    public function status($token = null)
    {
        if ($this->boot_error !== null) {
            $this->respond(['success' => false, 'message' => 'boot_failed', 'error' => $this->boot_error], 500);
        }

        if (!$this->authorised($token)) {
            $this->respond(['success' => false, 'message' => 'unauthorised'], 403);
        }

        $this->ensure_helper();
        $table = pm_table('reminders');

        $pending = $this->db->query("SELECT COUNT(*) c FROM `{$table}` WHERE status = 'pending'")->row()->c;
        $overdue = $this->db->query(
            "SELECT COUNT(*) c FROM `{$table}` WHERE status = 'pending' AND scheduled_utc < UTC_TIMESTAMP()"
        )->row()->c;
        $lastSent = $this->db->query("SELECT MAX(sent_utc) s FROM `{$table}`")->row()->s;
        $failed24 = $this->db->query(
            "SELECT COUNT(*) c FROM `{$table}` WHERE status = 'failed' AND date_created > (UTC_TIMESTAMP() - INTERVAL 1 DAY)"
        )->row()->c;

        $this->respond([
            'success'        => true,
            'pending'        => (int) $pending,
            'overdue'        => (int) $overdue,
            'failed_last_24h'=> (int) $failed24,
            'last_sent_utc'  => $lastSent,
            'healthy'        => ((int) $overdue === 0),
        ]);
    }

    /* =============================================================== internals */

    /**
     * Load the module helper if this request did not already have it.
     *
     * Proven with a method that does nothing but answer: on a front-end route
     * this controller constructs cleanly, models load, and
     * function_exists('pm_setting') is FALSE. Perfex loads a module's bootstrap
     * — which is what require_once's this helper — in the admin context, not
     * for a public route like this one. So authorised() called an undefined
     * function on the very first line and PHP fataled before any token was
     * compared.
     *
     * That is why every request here answered a blank HTTP 500: a valid token,
     * a wrong token and no token at all alike. The gate was never reached, so
     * it could never refuse, and whoever installed the cron had no way to tell
     * a bad token from a broken endpoint.
     */
    protected function ensure_helper()
    {
        if (function_exists('pm_setting')) { return; }

        $helper = dirname(__DIR__) . '/helpers/payplex_meetings_helper.php';
        if (is_file($helper)) { require_once $helper; }
    }

    protected function authorised($token)
    {
        $this->ensure_helper();

        if (!function_exists('pm_setting')) {
            return false;   // cannot read the token, so cannot authorise
        }

        $expected = (string) pm_setting('cron_token', '');

        if ($expected === '' || $token === null) {
            return false;
        }

        return hash_equals($expected, (string) $token);
    }

    protected function transition_started()
    {
        $t     = pm_meetings_table();
        $now   = gmdate('Y-m-d H:i:s');
        $count = 0;

        $rows = $this->db->query(
            "SELECT id FROM `{$t}`
              WHERE status IN ('scheduled','confirmed','rescheduled')
                AND start_utc <= ? AND end_utc > ?",
            [$now, $now]
        )->result();

        foreach ($rows as $r) {
            $this->db->where('id', (int) $r->id)->update($t, [
                'status'       => 'in_progress',
                'last_updated' => date('Y-m-d H:i:s'),
            ]);
            $count++;
        }

        return $count;
    }

    protected function respond($payload, $code = 200)
    {
        if (is_cli()) {
            echo json_encode($payload) . PHP_EOL;
            exit($code === 200 ? 0 : 1);
        }

        // exit() skips CI's output flush, so write the body directly.
        $this->output->set_status_header($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload);
        exit;
    }
}
