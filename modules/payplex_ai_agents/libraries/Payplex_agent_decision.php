<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_decision
 *
 * Pure, dependency-free rules for Decision Packets submitted to the Chairman.
 *  - completeness(): the Chairman must never receive a blind, evidence-less
 *    one-click approval. A packet is only "ready" when the decision-critical
 *    fields are present.
 *  - transition(): status machine with maker != approver and tier enforcement.
 *  - delegation is allowed only within a configured amount limit.
 */
class Payplex_agent_decision
{
    const DRAFT     = 'draft';
    const SUBMITTED = 'submitted';   // awaiting Chairman
    const APPROVED  = 'approved';
    const REJECTED  = 'rejected';
    const RETURNED  = 'returned';    // returned for revision
    const DELEGATED = 'delegated';   // approved by a delegate within limit
    const EXECUTED  = 'executed';    // marked executed after approval (still human-gated action)

    /** Fields a complete packet must carry before it can be submitted/approved. */
    public static function requiredFields()
    {
        return array(
            'title', 'requesting_agent_id', 'company', 'objective',
            'recommended_action', 'reason', 'evidence', 'confidence',
            'risk_rating', 'reversibility', 'rollback_method', 'execution_owner',
        );
    }

    /**
     * Is a packet complete enough to go to the Chairman?
     * @return array ['ok'=>bool,'missing'=>[...]]
     */
    public static function completeness(array $p)
    {
        $missing = array();
        foreach (self::requiredFields() as $f) {
            $v = isset($p[$f]) ? $p[$f] : null;
            if ($v === null || (is_string($v) && trim($v) === '') || (is_array($v) && empty($v))) {
                $missing[] = $f;
            }
        }
        // confidence must be a real number 0..1
        if (isset($p['confidence']) && $p['confidence'] !== '' && !is_numeric($p['confidence'])) {
            $missing[] = 'confidence';
        }
        return array('ok' => empty($missing), 'missing' => array_values(array_unique($missing)));
    }

    /** Allowed status transitions. */
    public static function allowedActions($status)
    {
        switch ($status) {
            case self::DRAFT:     return array('submit');
            case self::SUBMITTED: return array('approve', 'reject', 'return', 'delegate');
            case self::RETURNED:  return array('submit');   // revise & resubmit
            case self::APPROVED:  return array('execute');
            case self::DELEGATED: return array('execute');
            default:              return array();
        }
    }

    /**
     * Apply a status transition with governance checks.
     *
     * @param string $action  submit|approve|reject|return|delegate|execute
     * @param array  $packet  current packet (status, created_by, requesting_agent_id, tier, amount, ...)
     * @param array  $ctx     actor_id, actor_tier, is_admin, delegate_limit, complete(bool)
     * @return array ['ok'=>bool,'error'=>?,'status'=>newStatus]
     */
    public static function transition($action, array $packet, array $ctx = array())
    {
        $status  = isset($packet['status']) ? $packet['status'] : self::DRAFT;
        $actor   = isset($ctx['actor_id']) ? (int) $ctx['actor_id'] : 0;
        $maker   = isset($packet['created_by']) ? (int) $packet['created_by'] : 0;
        $reqTier = isset($packet['required_tier']) ? $packet['required_tier'] : Payplex_agent_approval_matrix::CHAIRMAN;
        $actorTier = isset($ctx['actor_tier']) ? $ctx['actor_tier'] : Payplex_agent_approval_matrix::AUTO;

        if (!in_array($action, self::allowedActions($status), true)) {
            return array('ok' => false, 'error' => 'action_not_allowed_from_' . $status);
        }

        switch ($action) {
            case 'submit':
                if (empty($ctx['complete'])) {
                    return array('ok' => false, 'error' => 'packet_incomplete');
                }
                return array('ok' => true, 'status' => self::SUBMITTED);

            case 'return':
                // maker cannot review their own packet
                if ($actor === $maker && empty($ctx['is_admin_override'])) {
                    return array('ok' => false, 'error' => 'maker_cannot_review');
                }
                return array('ok' => true, 'status' => self::RETURNED);

            case 'reject':
                if ($actor === $maker) {
                    return array('ok' => false, 'error' => 'maker_cannot_review');
                }
                if (!Payplex_agent_approval_matrix::canApprove($actorTier, $reqTier)) {
                    return array('ok' => false, 'error' => 'insufficient_authority');
                }
                return array('ok' => true, 'status' => self::REJECTED);

            case 'approve':
                // maker != approver, always
                if ($actor === $maker) {
                    return array('ok' => false, 'error' => 'maker_cannot_approve');
                }
                // completeness is mandatory — no blind approvals
                if (empty($ctx['complete'])) {
                    return array('ok' => false, 'error' => 'cannot_approve_incomplete_packet');
                }
                if (!Payplex_agent_approval_matrix::canApprove($actorTier, $reqTier)) {
                    return array('ok' => false, 'error' => 'insufficient_authority');
                }
                return array('ok' => true, 'status' => self::APPROVED);

            case 'delegate':
                // a delegate may approve only manager-tier (or below) items within a limit,
                // and never a chairman-tier item.
                if ($actor === $maker) {
                    return array('ok' => false, 'error' => 'maker_cannot_approve');
                }
                if ($reqTier === Payplex_agent_approval_matrix::CHAIRMAN) {
                    return array('ok' => false, 'error' => 'chairman_tier_not_delegable');
                }
                $amount = isset($packet['amount']) ? (float) $packet['amount'] : 0.0;
                $limit  = isset($ctx['delegate_limit']) ? (float) $ctx['delegate_limit'] : 0.0;
                if ($limit <= 0 || $amount > $limit) {
                    return array('ok' => false, 'error' => 'over_delegation_limit');
                }
                if (empty($ctx['complete'])) {
                    return array('ok' => false, 'error' => 'cannot_approve_incomplete_packet');
                }
                return array('ok' => true, 'status' => self::DELEGATED);

            case 'execute':
                // marking executed does not itself perform the action; it records that
                // the (human-performed) action happened after approval.
                return array('ok' => true, 'status' => self::EXECUTED);
        }

        return array('ok' => false, 'error' => 'unknown_action');
    }
}
