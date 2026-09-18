<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Payplex_call_limits — frequency, suppression and cost controls (spec §4.4).
 *
 * Pure and framework-independent, like Payplex_call_gates, and composed into
 * that same entry point so there is one place a call is permitted or refused.
 *
 * The spec's instruction is absolute: "Never call opted-out / DND / invalid /
 * out-of-hours / attempt-exceeded / human-handled / no-consent leads." Consent,
 * DND, hours and ownership live in Payplex_call_gates. The rest live here.
 *
 * ---------------------------------------------------------------------------
 * WHAT AN UNCONFIGURED LIMIT MEANS
 * ---------------------------------------------------------------------------
 * This distinction is deliberate and is the one judgement call in this file.
 *
 * A missing FREQUENCY limit gets a conservative default. A cap of three calls
 * a day is strictly safer than the current behaviour, which is unlimited, so
 * defaulting cannot make things worse than not having the control at all. The
 * default is reported as a default wherever it is shown, so nobody mistakes it
 * for a decision somebody made.
 *
 * A missing BUDGET refuses. A budget is not a safety limit, it is permission to
 * spend the company's money, and picking a number here would be inventing a
 * business decision — the same reason this project refuses to invent commission
 * rates rather than falling back to a plausible-looking percentage. So an
 * unconfigured budget does not mean "unlimited", it means "nobody has authorised
 * spending yet", and calls refuse until finance sets one.
 *
 * A missing RECORDING DISCLOSURE refuses, because recording someone without the
 * disclosure they are legally owed is not a thing to default your way into.
 */
class Payplex_call_limits
{
    /* Conservative defaults — safety limits, not business decisions. */
    const DEFAULT_MAX_PER_DAY     = 3;
    const DEFAULT_MAX_PER_WEEK    = 10;
    const DEFAULT_COOLDOWN_MIN    = 240;   // 4 hours between attempts on one lead
    const DEFAULT_MAX_DURATION_S  = 600;   // 10 minutes

    /* ---------------- classification lists ---------------- */

    /*
     * These three lists decide whether a call is suppressed. They were PHP
     * constants, which meant they only worked if the telephony backend happened
     * to use the same words this file guessed. A backend sending "no_route"
     * rather than "unallocated" would have had its invalid numbers dialled
     * again forever, with nothing to show anything was wrong — the same silent
     * no-op this module has produced four times already.
     *
     * They are now configurable, with one deliberate restriction: an empty list
     * restores the defaults rather than matching nothing. There is no way to
     * switch a suppression off by clearing a box, because "I cleared the field
     * and calls quietly stopped being suppressed" is precisely the failure this
     * change exists to prevent. Emptying is how you ask for the defaults back.
     */

    public static function defaultInFlightStatuses()
    {
        return array('pending', 'queued', 'scheduled', 'ringing', 'in_progress', 'connected');
    }

    public static function defaultInvalidNumberDispositions()
    {
        return array('invalid_number', 'number_invalid', 'unallocated', 'disconnected', 'wrong_number');
    }

    public static function defaultHumanHandledDispositions()
    {
        return array('human_transfer', 'handed_off', 'human_handled', 'escalated_to_human');
    }

    /**
     * Normalise an admin-entered list.
     *
     * Accepts commas, newlines or both. Lowercases, trims, drops blanks and
     * duplicates, so "Invalid_Number, invalid_number" is one entry and a
     * trailing comma does not create an empty string that matches an empty
     * disposition.
     *
     * @return array
     */
    public static function parseList($raw)
    {
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\r\n,]+/', (string) $raw);
        }
        $out = array();
        foreach ((array) $parts as $p) {
            $v = strtolower(trim((string) $p));
            if ($v === '') { continue; }
            if (!in_array($v, $out, true)) { $out[] = $v; }
        }
        return $out;
    }

    /**
     * A configured list, or the defaults when nothing usable is configured.
     *
     * @return array list, is_default
     */
    public static function resolveList($configured, array $default)
    {
        $parsed = self::parseList($configured);
        if (!$parsed) {
            return array('list' => $default, 'is_default' => true);
        }
        return array('list' => $parsed, 'is_default' => false);
    }

    public static function inFlightStatuses($configured = null)
    {
        return self::resolveList($configured, self::defaultInFlightStatuses())['list'];
    }

    public static function invalidNumberDispositions($configured = null)
    {
        return self::resolveList($configured, self::defaultInvalidNumberDispositions())['list'];
    }

    public static function humanHandledDispositions($configured = null)
    {
        return self::resolveList($configured, self::defaultHumanHandledDispositions())['list'];
    }

    /**
     * Which values seen in the call history match none of the three lists.
     *
     * Without this, an admin configuring these lists is guessing at strings the
     * backend might send. This reports what it HAS sent and what nothing
     * classifies, which is the difference between a text box and a control.
     *
     * @param array $observed rows of value => count
     * @param array $cfg      the configured lists
     */
    public static function unclassifiedValues($observedStatuses, $observedDispositions, $cfg = array())
    {
        $inFlight = self::inFlightStatuses(isset($cfg['in_flight_statuses']) ? $cfg['in_flight_statuses'] : null);
        $invalid  = self::invalidNumberDispositions(isset($cfg['invalid_dispositions']) ? $cfg['invalid_dispositions'] : null);
        $human    = self::humanHandledDispositions(isset($cfg['human_dispositions']) ? $cfg['human_dispositions'] : null);

        // Terminal statuses are expected to match nothing: a finished call is
        // neither in flight nor a suppression signal. Flagging them would bury
        // the values that genuinely need attention.
        $terminal = array('completed', 'failed', 'cancelled', 'canceled', 'no_answer', 'busy', 'expired');

        $out = array('statuses' => array(), 'dispositions' => array());

        foreach ((array) $observedStatuses as $value => $count) {
            $v = strtolower(trim((string) $value));
            if ($v === '') { continue; }
            if (in_array($v, $inFlight, true) || in_array($v, $terminal, true)) { continue; }
            $out['statuses'][$v] = (int) $count;
        }
        foreach ((array) $observedDispositions as $value => $count) {
            $v = strtolower(trim((string) $value));
            if ($v === '') { continue; }
            if (in_array($v, $invalid, true) || in_array($v, $human, true)) { continue; }
            $out['dispositions'][$v] = (int) $count;
        }
        return $out;
    }

    public static function reasons()
    {
        return array(
            'attempts_exceeded_day'   => 'This lead has already been called the maximum number of times today.',
            'attempts_exceeded_week'  => 'This lead has reached the maximum number of calls for this week.',
            'cooldown_active'         => 'This lead was called recently; the cooldown period has not elapsed.',
            'call_in_flight'          => 'A call to this lead is already in progress or queued.',
            'invalid_number'          => 'This number was reported invalid on a previous attempt.',
            'no_number'               => 'This lead has no usable phone number.',
            'not_indian_mobile'       => 'This lead\'s phone number is not a valid Indian mobile number '
                                        . '(10 digits, starting with 6-9, optionally prefixed with +91).',
            'human_handled'           => 'A person has taken this lead over, so it will not be auto-called.',
            'budget_not_configured'   => 'No calling budget has been set, so calls cannot be authorised.',
            'budget_exhausted_agent'  => 'This agent has reached its calling budget for the period.',
            'budget_exhausted_account'=> 'The account calling budget for the period has been reached.',
            'disclosure_not_confirmed'=> 'The recording disclosure has not been configured and confirmed.',
            'limits_unknown'          => 'The calling limits could not be determined, so the call is refused.',
        );
    }

    public static function message($code)
    {
        $m = self::reasons();
        return isset($m[(string) $code]) ? $m[(string) $code] : 'This call is not permitted.';
    }

    /* ---------------- helpers ---------------- */

    private static function ts($v)
    {
        if ($v === null || $v === '' || $v === false) { return null; }
        if (is_numeric($v)) { return (int) $v; }
        $t = strtotime((string) $v);
        return $t === false ? null : $t;
    }

    /**
     * A limit value from configuration, or the documented default.
     * Returns array(value, is_default). A value that is present but unusable
     * falls back to the default rather than being trusted.
     */
    public static function limit($configured, $default)
    {
        if ($configured === null || $configured === '' || !is_numeric($configured)) {
            return array('value' => $default, 'is_default' => true);
        }
        $v = (int) $configured;
        if ($v <= 0) { return array('value' => $default, 'is_default' => true); }
        return array('value' => $v, 'is_default' => false);
    }

    /* ---------------- frequency ---------------- */

    /**
     * Attempts and cooldown for one lead.
     *
     * @param array $history prior calls to THIS lead: created_at, status, disposition
     * @param array $cfg     max_per_day, max_per_week, cooldown_minutes
     * @param int   $now     unix time
     */
    public static function frequency($history, $cfg = array(), $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        $day  = self::limit(isset($cfg['max_per_day']) ? $cfg['max_per_day'] : null, self::DEFAULT_MAX_PER_DAY);
        $week = self::limit(isset($cfg['max_per_week']) ? $cfg['max_per_week'] : null, self::DEFAULT_MAX_PER_WEEK);
        $cool = self::limit(isset($cfg['cooldown_minutes']) ? $cfg['cooldown_minutes'] : null, self::DEFAULT_COOLDOWN_MIN);

        $countDay = 0; $countWeek = 0; $last = null;

        foreach ((array) $history as $row) {
            $r  = (array) $row;
            $at = self::ts(isset($r['created_at']) ? $r['created_at'] : null);

            // A prior attempt with no usable timestamp cannot be placed in a
            // window. Ignoring it would let an unlimited number of undated rows
            // slip past the cap, so it counts against both windows.
            if ($at === null) { $countDay++; $countWeek++; continue; }

            if ($at > $now) { continue; }   // future-dated rows are not past attempts
            if ($at >= $now - 86400)     { $countDay++; }
            if ($at >= $now - (7 * 86400)) { $countWeek++; }
            if ($last === null || $at > $last) { $last = $at; }
        }

        $out = array(
            'allowed'          => true,
            'code'             => 'ok',
            'calls_today'      => $countDay,
            'calls_this_week'  => $countWeek,
            'max_per_day'      => $day['value'],
            'max_per_week'     => $week['value'],
            'cooldown_minutes' => $cool['value'],
            'using_defaults'   => array_values(array_filter(array(
                $day['is_default']  ? 'max_per_day'      : null,
                $week['is_default'] ? 'max_per_week'     : null,
                $cool['is_default'] ? 'cooldown_minutes' : null,
            ))),
            'last_attempt_at'  => $last,
            'next_allowed_at'  => $last === null ? null : $last + ($cool['value'] * 60),
        );

        if ($countDay >= $day['value'])   { $out['allowed'] = false; $out['code'] = 'attempts_exceeded_day';  return $out; }
        if ($countWeek >= $week['value']) { $out['allowed'] = false; $out['code'] = 'attempts_exceeded_week'; return $out; }
        if ($last !== null && $now < $out['next_allowed_at']) {
            $out['allowed'] = false; $out['code'] = 'cooldown_active'; return $out;
        }
        return $out;
    }

    /* ---------------- suppression ---------------- */

    /**
     * Duplicate-call prevention: is a call to this lead already under way?
     */
    public static function inFlight($history, $configured = null)
    {
        $list = self::inFlightStatuses($configured);
        foreach ((array) $history as $row) {
            $r = (array) $row;
            $s = strtolower(trim((string) (isset($r['status']) ? $r['status'] : '')));
            if ($s !== '' && in_array($s, $list, true)) { return true; }
        }
        return false;
    }

    /**
     * Has a previous attempt reported the number unusable?
     */
    public static function numberReportedInvalid($history, $configured = null)
    {
        $list = self::invalidNumberDispositions($configured);
        foreach ((array) $history as $row) {
            $r = (array) $row;
            foreach (array('disposition', 'failure_reason') as $f) {
                $v = strtolower(trim((string) (isset($r[$f]) ? $r[$f] : '')));
                if ($v !== '' && in_array($v, $list, true)) { return true; }
            }
        }
        return false;
    }

    /**
     * Has a person taken this lead over?
     */
    public static function humanHandled($history, $configured = null)
    {
        $list = self::humanHandledDispositions($configured);
        foreach ((array) $history as $row) {
            $r = (array) $row;
            $v = strtolower(trim((string) (isset($r['disposition']) ? $r['disposition'] : '')));
            if ($v !== '' && in_array($v, $list, true)) { return true; }
        }
        return false;
    }

    /**
     * Is there a number worth dialling at all?
     *
     * Deliberately permissive about FORMAT and strict about ABSENCE: this is a
     * CRM with hand-entered numbers in many shapes, and refusing an oddly
     * formatted but working number is its own failure. What it will not accept
     * is nothing, or something with too few digits to be a phone number.
     */
    public static function hasUsableNumber($lead)
    {
        $l = (array) $lead;
        foreach (array('phonenumber', 'phone', 'mobile') as $f) {
            $v = isset($l[$f]) ? trim((string) $l[$f]) : '';
            if ($v === '') { continue; }
            $digits = preg_replace('/\D+/', '', $v);
            if (strlen($digits) >= 7) { return true; }
        }
        return false;
    }

    /**
     * Is this a valid Indian mobile number?
     *
     * Strict on FORMAT, unlike hasUsableNumber() above: 10 digits, starting
     * with 6-9, after stripping spaces/hyphens/parens and an optional
     * leading 0, +91 or 91 trunk prefix. Called only once a number is known
     * to exist at all (see evaluate()), so this is purely a format check.
     */
    public static function isIndianMobile($value)
    {
        $digits = preg_replace('/\D+/', '', (string) $value);
        $digits = preg_replace('/^(?:0|91)(?=\d{10}$)/', '', $digits);
        return (bool) preg_match('/^[6-9]\d{9}$/', $digits);
    }

    /**
     * Does the lead's usable number (see hasUsableNumber()) pass the Indian
     * mobile format check? Checked against the same fields, in the same
     * priority order.
     */
    public static function hasValidIndianMobile($lead)
    {
        $l = (array) $lead;
        foreach (array('phonenumber', 'phone', 'mobile') as $f) {
            $v = isset($l[$f]) ? trim((string) $l[$f]) : '';
            if ($v === '') { continue; }
            return self::isIndianMobile($v);
        }
        return false;
    }

    /* ---------------- cost ---------------- */

    /**
     * Budget check. Returns allowed/code plus what was spent and against what.
     *
     * An unconfigured budget REFUSES. See the note at the top of this file:
     * a budget is authorisation to spend, and inventing one is inventing a
     * business decision.
     *
     * @param float|null $agentSpend   spend by this agent in the period
     * @param float|null $accountSpend total account spend in the period
     * @param array      $cfg          agent_budget, account_budget
     */
    public static function budget($agentSpend, $accountSpend, $cfg = array())
    {
        $agentCap   = isset($cfg['agent_budget'])   ? $cfg['agent_budget']   : null;
        $accountCap = isset($cfg['account_budget']) ? $cfg['account_budget'] : null;

        $usable = function ($v) {
            return $v !== null && $v !== '' && is_numeric($v) && (float) $v > 0;
        };

        /*
         * EITHER budget authorises calling; at least one must be set.
         *
         * This originally demanded an ACCOUNT cap and treated the per-agent one
         * as optional. The approved figure is "Rs 5,000 per agent per month",
         * with no account-wide number given — so insisting on one would have
         * meant either blocking every call against an authorisation that had
         * been granted, or inventing an account figure by multiplying by a
         * headcount nobody approved. Neither is acceptable: the first ignores a
         * decision that was made, the second fabricates one that was not.
         *
         * So a per-agent cap alone is sufficient authorisation and is enforced
         * on its own. What it does NOT do is bound total spend, which is then
         * per-agent times however many agents call — the settings screen states
         * that exposure plainly rather than leaving it to be discovered.
         */
        if (!$usable($accountCap) && !$usable($agentCap)) {
            return array('allowed' => false, 'code' => 'budget_not_configured',
                         'agent_spend' => $agentSpend, 'account_spend' => $accountSpend);
        }

        if ($usable($accountCap)) {
            // spend that cannot be read is not treated as zero
            if (!is_numeric($accountSpend)) {
                return array('allowed' => false, 'code' => 'limits_unknown',
                             'agent_spend' => $agentSpend, 'account_spend' => $accountSpend);
            }
            if ((float) $accountSpend >= (float) $accountCap) {
                return array('allowed' => false, 'code' => 'budget_exhausted_account',
                             'agent_spend' => $agentSpend, 'account_spend' => $accountSpend,
                             'account_budget' => (float) $accountCap);
            }
        }

        if ($usable($agentCap)) {
            if (!is_numeric($agentSpend)) {
                return array('allowed' => false, 'code' => 'limits_unknown',
                             'agent_spend' => $agentSpend, 'account_spend' => $accountSpend);
            }
            if ((float) $agentSpend >= (float) $agentCap) {
                return array('allowed' => false, 'code' => 'budget_exhausted_agent',
                             'agent_spend' => $agentSpend, 'account_spend' => $accountSpend,
                             'agent_budget' => (float) $agentCap);
            }
        }

        return array('allowed' => true, 'code' => 'ok',
                     'agent_spend' => $agentSpend, 'account_spend' => $accountSpend,
                     'account_budget' => $usable($accountCap) ? (float) $accountCap : null,
                     'agent_budget'   => $usable($agentCap) ? (float) $agentCap : null);
    }

    /**
     * The duration cap sent to the backend with every call.
     * This is a ceiling, not a refusal — an unset or unusable value falls back
     * to the documented default rather than leaving the call uncapped.
     */
    public static function maxDurationSeconds($configured)
    {
        $l = self::limit($configured, self::DEFAULT_MAX_DURATION_S);
        return $l['value'];
    }

    /* ---------------- disclosure ---------------- */

    /**
     * Recording disclosure must be written AND confirmed before any call.
     * Either one missing refuses.
     */
    public static function disclosureReady($text, $confirmed)
    {
        $t = trim((string) $text);
        return $t !== '' && (string) $confirmed === '1';
    }

    /* ---------------- everything ---------------- */

    /**
     * All of §4.4 in the order that refuses soonest.
     *
     * @param array      $lead
     * @param array      $history prior calls to this lead
     * @param array      $ctx     cfg, agent_spend, account_spend, now
     * @return array allowed, code, message, detail
     */
    public static function evaluate($lead, $history, $ctx = array())
    {
        $cfg = isset($ctx['cfg']) ? (array) $ctx['cfg'] : array();
        $now = isset($ctx['now']) ? $ctx['now'] : null;

        $deny = function ($code, $detail = array()) {
            return array('allowed' => false, 'code' => $code,
                         'message' => self::message($code), 'detail' => $detail);
        };

        // legal first: never record anyone without the disclosure they are owed
        if (!self::disclosureReady(
                isset($cfg['recording_disclosure']) ? $cfg['recording_disclosure'] : '',
                isset($cfg['disclosure_confirmed']) ? $cfg['disclosure_confirmed'] : '0')) {
            return $deny('disclosure_not_confirmed');
        }

        $g = function ($k) use ($cfg) { return isset($cfg[$k]) ? $cfg[$k] : null; };

        if (!self::hasUsableNumber($lead)) { return $deny('no_number'); }
        if (!self::hasValidIndianMobile($lead)) { return $deny('not_indian_mobile'); }
        if (self::numberReportedInvalid($history, $g('invalid_dispositions'))) { return $deny('invalid_number'); }
        if (self::humanHandled($history, $g('human_dispositions')))            { return $deny('human_handled'); }
        if (self::inFlight($history, $g('in_flight_statuses')))                { return $deny('call_in_flight'); }

        $freq = self::frequency($history, $cfg, $now);
        if (!$freq['allowed']) { return $deny($freq['code'], $freq); }

        $budget = self::budget(
            isset($ctx['agent_spend']) ? $ctx['agent_spend'] : null,
            isset($ctx['account_spend']) ? $ctx['account_spend'] : null,
            $cfg
        );
        if (!$budget['allowed']) { return $deny($budget['code'], $budget); }

        return array('allowed' => true, 'code' => 'ok', 'message' => '',
                     'detail' => array('frequency' => $freq, 'budget' => $budget,
                                       'max_duration_sec' => self::maxDurationSeconds(
                                           isset($cfg['max_duration_sec']) ? $cfg['max_duration_sec'] : null)));
    }
}
