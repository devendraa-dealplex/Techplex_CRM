<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Reconciliation cron — the safety net (Architecture §7).
 * Hooked into Perfex's cron so it runs on the existing schedule; no new daemon.
 *
 * DEFECT FIXED 2026-09-10 (spec §4.1)
 * ----------------------------------
 * This was registered on the hook name 'app_cron', which Perfex does not have.
 * Verified by scanning all 7,722 PHP files under application/: 'app_cron'
 * appears ZERO times, while 'after_cron_run' is fired by
 * application/models/Cron_model.php:112 and is the name Perfex's own Goals
 * module and the Sales Targets module both register on.
 *
 * The consequence was total and silent: this function had never run once. The
 * outbox was never drained, no health snapshot was ever recorded automatically,
 * and no stuck call was ever reconciled. Nothing errored, because registering a
 * callback on a hook nobody fires is not an error — it simply never happens.
 * That is why the scheduled sandbox call left no trace anywhere.
 *
 * Responsibilities:
 *   - drain the outbox: resend requests that failed transiently (idempotent)
 *   - refresh health snapshot
 *   - reconcile "in progress" calls older than a threshold via GET /calls/{id}
 *
 * NOTE ON SIDE EFFECTS: draining the outbox calls the AI backend. That is only
 * ever a RESEND of a call a human already initiated and which failed
 * transiently — this job never originates a call. The module's own
 * payplex_aicalling_enabled flag remains the kill switch, and is checked first.
 */
hooks()->add_action('after_cron_run', 'payplex_aicalling_cron');
function payplex_aicalling_cron()
{
    if (get_option('payplex_aicalling_enabled') !== '1') {
        return;
    }

    // Leave a trace of every run. The whole reason this defect went unnoticed
    // for so long is that a job which never runs looks exactly like a job with
    // nothing to do.
    if (function_exists('log_activity')) {
        @log_activity('Payplex AI Calling reconciler: run started.');
    }

    $CI = &get_instance();
    $CI->load->model('payplex_aicalling/aicalling_model');
    require_once __DIR__ . '/../libraries/Payplex_api_client.php';
    require_once __DIR__ . '/../libraries/Payplex_retry.php';

    $client = new Payplex_api_client();
    $retry  = new Payplex_retry(6, 1000, 60000);

    /**
     * The failure reason, whatever shape the client reported it in.
     *
     * This was written as $res['error']['code'] ?? 'error'. The client's error
     * envelope carries a STRING, so indexing it by 'code' yields nothing and
     * the ?? substituted the literal word "error" — every outbox failure
     * recorded the same meaningless reason, and an operator looking at
     * last_error could never tell a timeout from a rejected payload.
     */
    $reasonOf = function ($res) {
        if (isset($res['code']) && is_string($res['code']) && $res['code'] !== '') {
            return $res['code'];
        }
        if (isset($res['error'])) {
            if (is_array($res['error'])) {
                return isset($res['error']['code']) ? (string) $res['error']['code'] : 'error';
            }
            if (is_string($res['error']) && $res['error'] !== '') {
                return substr($res['error'], 0, 120);
            }
        }
        return 'http_' . (int) (isset($res['status']) ? $res['status'] : 0);
    };

    // 1. Drain outbox (bounded batch).
    foreach ($CI->aicalling_model->pendingOutbox(50) as $row) {
        $payload = json_decode($row->payload_json, true) ?: [];
        $res = $client->createCall($payload, $row->idempotency_key, $row->correlation_id);

        if ($res['ok']) {
            $CI->aicalling_model->markOutbox($row->id, 'reconciled');

            /*
             * Only update a mirror row when the backend actually named a call.
             * This passed $res['data']['call_id'] ?? '' straight through, and
             * updateBySonivoId('') matches every row whose sonivo_call_id is
             * the empty string — so one malformed success response could have
             * stamped a status across unrelated calls.
             */
            $callId = isset($res['data']['call_id']) ? trim((string) $res['data']['call_id']) : '';
            if ($callId !== '') {
                $CI->aicalling_model->updateBySonivoId($callId,
                    ['status' => $res['data']['status'] ?? 'queued']);
            } elseif (function_exists('log_activity')) {
                @log_activity('Payplex AI Calling reconciler: outbox item #' . (int) $row->id
                    . ' was accepted but the response named no call_id, so no call row was updated.');
            }
            continue;
        }

        $attempt = (int) $row->attempts + 1;
        $reason  = $reasonOf($res);

        /*
         * Honour the retry policy's answer instead of discarding it. This read
         *
         *     shouldRetry(...) ? 'failed' : 'failed'
         *
         * so a permanently-failed item — a 4xx, or one past the attempt ceiling
         * — was written back as 'failed', which pendingOutbox() selects again
         * on the next pass. The policy said stop and the code retried for ever.
         */
        if ($retry->shouldRetry($res['status'], $attempt)) {
            $next = date('Y-m-d H:i:s', time() + (int) ($retry->backoffCeilingMs($attempt) / 1000));
            $CI->aicalling_model->markOutbox($row->id, 'failed', $reason, $next);
        } else {
            $CI->aicalling_model->markOutbox($row->id, 'abandoned', $reason, null);
            if (function_exists('log_activity')) {
                @log_activity('Payplex AI Calling reconciler: outbox item #' . (int) $row->id
                    . ' abandoned after ' . $attempt . ' attempt(s) — ' . $reason
                    . '. It will not be retried; a human decides what happens next.');
            }
        }
    }

    /*
     * 2. Refresh health snapshot.
     *
     * A client with no request secret cannot sign, so it answers not_configured
     * without touching the network. Recording that as "unreachable" would send
     * an operator looking for a network fault that does not exist, so the
     * reason is written into the snapshot alongside the flag.
     */
    $h = $client->health();
    $unconfigured = (isset($h['code']) && $h['code'] === 'not_configured');
    $CI->db->insert(db_prefix() . 'payplex_integration_health', [
        'checked_at'       => date('Y-m-d H:i:s'),
        'sonivo_reachable' => $h['ok'] ? 1 : 0,
        'last_error'       => $h['ok'] ? null
            : ($unconfigured ? 'not_configured: request secret missing' : $reasonOf($h)),
        'providers_json'   => isset($h['data']['providers']) ? json_encode($h['data']['providers']) : null,
        'balance_amount'   => $h['data']['balance']['amount'] ?? null,
        'balance_currency' => $h['data']['balance']['currency'] ?? null,
    ]);

    // 3. Reconcile stuck "in progress" calls (>15 min, no terminal status).
    $stuck = $CI->db->where_in('status', ['queued', 'ringing', 'answered', 'pending'])
        ->where('created_at <', date('Y-m-d H:i:s', time() - 900))
        ->limit(50)->get(db_prefix() . 'payplex_calls')->result();
    foreach ($stuck as $c) {
        if (!$c->sonivo_call_id) { continue; }
        $res = $client->getCall($c->sonivo_call_id);
        if ($res['ok'] && !empty($res['data']['status'])) {
            $CI->aicalling_model->updateById($c->id, [
                'status'      => $res['data']['status'],
                'disposition' => $res['data']['disposition'] ?? $c->disposition,
                'duration_sec'=> $res['data']['duration_sec'] ?? $c->duration_sec,
                'cost'        => $res['data']['cost'] ?? $c->cost,
            ]);
        }
    }
}
