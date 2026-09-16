<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_comms
 *
 * Pure, dependency-free rules for the internal agent-to-agent message bus.
 *
 * This bus is STRICTLY INTERNAL: agents coordinate with each other (requests,
 * hand-offs, escalations, responses) inside the system. It never sends anything
 * to a customer or the outside world. If a message asks for a real external
 * action — email/SMS/WhatsApp to a customer, a call, a payment, a refund, a
 * public post, a deletion — that is detected, flagged, routed to a human, and
 * must go through a Decision Packet (M5). An "internal message" can never be a
 * side-channel around the approval controls.
 *
 * Routing honours the M7 company boundary: an agent can message an agent in a
 * company it can see; cross-company only with an explicit grant. Group-level
 * agents (no company) are reachable by anyone with access.
 */
class Payplex_agent_comms
{
    /* message types */
    const REQUEST    = 'request';     // "please do X / provide Y"
    const HANDOFF    = 'handoff';     // "you own this now"
    const ESCALATION = 'escalation';  // "a human / higher authority must look"
    const INFO       = 'info';        // FYI / note
    const RESPONSE   = 'response';     // reply to a request

    /* thread status */
    const OPEN      = 'open';
    const AWAITING  = 'awaiting';     // waiting on the recipient
    const RESOLVED  = 'resolved';
    const ESCALATED = 'escalated';
    const CLOSED    = 'closed';

    /* message delivery status */
    const SENT         = 'sent';
    const READ         = 'read';
    const ACKNOWLEDGED = 'acknowledged';
    const RESPONDED    = 'responded';

    public static function messageTypes()
    {
        return array(self::REQUEST, self::HANDOFF, self::ESCALATION, self::INFO, self::RESPONSE);
    }

    public static function priorities()
    {
        return array('low', 'normal', 'high', 'urgent');
    }

    public static function normalizeType($t)
    {
        $t = strtolower(trim((string) $t));
        return in_array($t, self::messageTypes(), true) ? $t : self::INFO;
    }

    public static function normalizePriority($p)
    {
        $p = strtolower(trim((string) $p));
        return in_array($p, self::priorities(), true) ? $p : 'normal';
    }

    /**
     * External-action detector. Agent messages are internal; any phrasing that
     * asks for a real-world side effect is flagged so it cannot slip past the
     * Decision Packet controls. Deterministic keyword match — returns the list
     * of matched phrases (empty = purely internal).
     */
    public static function classifyExternal($text)
    {
        $t = ' ' . strtolower((string) $text) . ' ';
        $needles = array(
            'send email', 'email the customer', 'send whatsapp', 'send sms', 'send a text',
            'text the customer', 'message the customer', 'call the customer', 'phone the customer',
            'make a call', 'place a call', 'send the customer', 'notify the customer',
            'make payment', 'make a payment', 'pay out', 'payout', 'disburse', 'refund',
            'transfer funds', 'wire ', 'send money', 'charge the card', 'publish', 'post to',
            'go live', 'delete ', 'remove the record', 'deploy', 'push to production',
        );
        $hits = array();
        foreach ($needles as $n) {
            if (strpos($t, ' ' . $n) !== false || strpos($t, $n) !== false) {
                $hits[] = trim($n);
            }
        }
        return array_values(array_unique($hits));
    }

    /**
     * Validate + normalize a message for saving.
     * $ctx: from_company, to_company, cross_allowed (bool), is_new_thread (bool)
     */
    public static function validateMessage(array $in, array $ctx = array())
    {
        $errors   = array();
        $warnings = array();

        $from = isset($in['from_agent_id']) ? (int) $in['from_agent_id'] : 0;
        $to   = isset($in['to_agent_id']) ? (int) $in['to_agent_id'] : 0;
        $body = trim((string) (isset($in['body']) ? $in['body'] : ''));
        $type = self::normalizeType(isset($in['type']) ? $in['type'] : self::INFO);
        $prio = self::normalizePriority(isset($in['priority']) ? $in['priority'] : 'normal');

        if ($from <= 0) { $errors[] = 'from_agent_required'; }
        if ($to <= 0)   { $errors[] = 'to_agent_required'; }
        if ($body === '') { $errors[] = 'body_required'; }
        if (mb_strlen($body) > 5000) { $errors[] = 'body_too_long'; }

        // can't hand off / request / escalate to yourself
        if ($from > 0 && $from === $to && in_array($type, array(self::REQUEST, self::HANDOFF, self::ESCALATION), true)) {
            $errors[] = 'cannot_route_to_self';
        }

        // routing across companies
        $fromCo = isset($ctx['from_company']) ? $ctx['from_company'] : '';
        $toCo   = isset($ctx['to_company']) ? $ctx['to_company'] : '';
        $cross  = !empty($ctx['cross_allowed']);
        if ($from > 0 && $to > 0 && !self::canRoute($fromCo, $toCo, $cross)) {
            $errors[] = 'cross_company_not_allowed';
        }

        // internal-only safety
        $external = self::classifyExternal($body);
        $requiresHuman = self::requiresHuman(array('type' => $type, 'priority' => $prio, 'body' => $body));
        if (!empty($external)) { $warnings[] = 'external_action_flagged'; }

        $entry = array(
            'from_agent_id'  => $from,
            'to_agent_id'    => $to,
            'body'           => $body,
            'msg_type'       => $type,
            'priority'       => $prio,
            'requires_human' => $requiresHuman ? 1 : 0,
            'external_flags' => implode(',', $external),
        );
        return array('ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings,
                     'entry' => $entry, 'external' => $external);
    }

    /**
     * Can an agent in $fromCo message an agent in $toCo?
     * Same company always; different company only with a cross grant; a
     * group-level agent (empty company) is reachable by anyone.
     */
    public static function canRoute($fromCo, $toCo, $crossAllowed = false)
    {
        $f = self::code($fromCo);
        $t = self::code($toCo);
        if ($t === '' || $f === '') { return true; }   // group-level either side
        if ($f === $t) { return true; }
        return (bool) $crossAllowed;
    }

    private static function code($s)
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $s));
    }

    /**
     * Does this message require a human before anything acts on it?
     * Yes for escalations, urgent priority, or any external-action phrasing.
     */
    public static function requiresHuman($msg)
    {
        $m    = (array) $msg;
        $type = self::normalizeType(isset($m['type']) ? $m['type'] : (isset($m['msg_type']) ? $m['msg_type'] : ''));
        $prio = self::normalizePriority(isset($m['priority']) ? $m['priority'] : 'normal');
        $body = isset($m['body']) ? $m['body'] : '';
        if ($type === self::ESCALATION) { return true; }
        if ($prio === 'urgent') { return true; }
        if (!empty(self::classifyExternal($body))) { return true; }
        return false;
    }

    /** Thread status machine. Returns the new status (unchanged if event invalid). */
    public static function threadStatusAfter($current, $event)
    {
        $current = $current ? $current : self::OPEN;
        switch ($event) {
            case 'request':   // a request/handoff was posted -> awaiting recipient
            case 'handoff':
                return in_array($current, array(self::OPEN, self::AWAITING, self::RESOLVED), true) ? self::AWAITING : $current;
            case 'respond':
                return $current === self::AWAITING ? self::OPEN : $current;
            case 'resolve':
                return in_array($current, array(self::OPEN, self::AWAITING), true) ? self::RESOLVED : $current;
            case 'escalate':
                return in_array($current, array(self::OPEN, self::AWAITING), true) ? self::ESCALATED : $current;
            case 'close':
                return $current !== self::CLOSED ? self::CLOSED : $current;
            case 'reopen':
                return in_array($current, array(self::RESOLVED, self::CLOSED, self::ESCALATED), true) ? self::OPEN : $current;
        }
        return $current;
    }

    /** Message delivery status machine. */
    public static function messageStatusAfter($current, $event)
    {
        $current = $current ? $current : self::SENT;
        switch ($event) {
            case 'read':
                return $current === self::SENT ? self::READ : $current;
            case 'acknowledge':
                return in_array($current, array(self::SENT, self::READ), true) ? self::ACKNOWLEDGED : $current;
            case 'respond':
                return in_array($current, array(self::SENT, self::READ, self::ACKNOWLEDGED), true) ? self::RESPONDED : $current;
        }
        return $current;
    }

    /** Deterministic dedupe key so an agent can't spam the same message. */
    public static function dedupeKey($msg)
    {
        $m = (array) $msg;
        $body = strtolower(trim(preg_replace('/\s+/', ' ', (string) (isset($m['body']) ? $m['body'] : ''))));
        return (int) (isset($m['from_agent_id']) ? $m['from_agent_id'] : 0) . ':' .
               (int) (isset($m['to_agent_id']) ? $m['to_agent_id'] : 0) . ':' .
               self::normalizeType(isset($m['msg_type']) ? $m['msg_type'] : (isset($m['type']) ? $m['type'] : '')) . ':' .
               sha1($body);
    }

    /** Is $msg a duplicate of something already in $recent (same dedupe key)? */
    public static function isDuplicate($msg, $recent)
    {
        $key = self::dedupeKey($msg);
        foreach ((array) $recent as $r) {
            if (self::dedupeKey($r) === $key) { return true; }
        }
        return false;
    }

    /** Summarise a thread's messages for a list/inbox view. */
    public static function summarizeThread($messages)
    {
        $msgs = array_values((array) $messages);
        $out = array('count' => count($msgs), 'unread' => 0, 'awaiting_response' => false,
                     'requires_human' => false, 'last_type' => null);
        foreach ($msgs as $m) {
            $m = (array) $m;
            if ((isset($m['status']) ? $m['status'] : self::SENT) === self::SENT) { $out['unread']++; }
            if (!empty($m['requires_human'])) { $out['requires_human'] = true; }
            $out['last_type'] = self::normalizeType(isset($m['msg_type']) ? $m['msg_type'] : '');
        }
        if (!empty($msgs)) {
            $last = (array) $msgs[count($msgs) - 1];
            $lt = self::normalizeType(isset($last['msg_type']) ? $last['msg_type'] : '');
            $out['awaiting_response'] = in_array($lt, array(self::REQUEST, self::HANDOFF), true);
        }
        return $out;
    }

    public static function priorityClass($p)
    {
        switch (self::normalizePriority($p)) {
            case 'urgent': return 'danger';
            case 'high':   return 'warning';
            case 'low':    return 'default';
            default:       return 'info';
        }
    }

    public static function typeClass($t)
    {
        switch (self::normalizeType($t)) {
            case self::ESCALATION: return 'danger';
            case self::REQUEST:    return 'primary';
            case self::HANDOFF:    return 'warning';
            case self::RESPONSE:   return 'success';
            default:               return 'default';
        }
    }
}
