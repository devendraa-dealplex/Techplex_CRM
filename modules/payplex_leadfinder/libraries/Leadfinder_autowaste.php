<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leadfinder_autowaste
 *
 * When the system may drop a prospect on its own, and when it must ask.
 *
 * The rule is a line, not a threshold: a prospect leaves the active queue
 * automatically only when a DETERMINISTIC fact says it is not workable — the
 * same Place ID we already hold, a business Google reports as permanently
 * closed, a do-not-contact match. Every one of those is checkable and has a
 * right answer.
 *
 * Everything else — a low score, a name that looks like a directory listing, a
 * category that seems off — produces "Review Required — Possible Waste" and
 * waits for a person. A score is a guess with a decimal point on it, and a
 * guess that silently deletes a lead is a guess nobody can audit.
 *
 * Pure: no database, no model, no clock of its own.
 */
class Leadfinder_autowaste
{
    /* ---- deterministic rules ------------------------------------------- */

    const D_DUPLICATE_SOURCE_REF = 'exact_duplicate_source_ref';
    const D_DUPLICATE_PLACE_ID   = 'exact_duplicate_place_id';
    const D_PERMANENTLY_CLOSED   = 'permanently_closed';
    const D_DNC_MATCH            = 'explicit_dnc_match';
    const D_SIMULATED_OUTSIDE_UAT = 'simulated_fixture_outside_uat';
    const D_SUPPRESSED           = 'already_rejected_within_suppression_period';

    /** The verdicts this class can return. */
    const V_AUTO_REMOVE    = 'auto_remove';
    const V_REVIEW         = 'review_required';
    const V_KEEP           = 'keep';

    /** The label shown for the middle verdict. Fixed wording; the UI does not improvise. */
    const REVIEW_LABEL = 'Review Required — Possible Waste';

    /**
     * The complete set of rules that may remove a prospect without a human.
     *
     * If a rule is not on this list it cannot auto-remove, whatever confidence
     * it reports. Adding to this list is a deliberate act and the test suite
     * asserts the list's contents so an addition cannot be accidental.
     *
     * @return array
     */
    public static function deterministicRules()
    {
        return array(
            self::D_DUPLICATE_SOURCE_REF => array(
                'label'  => 'Exact duplicate source reference',
                'why'    => 'the same listing from the same source is already held; keeping a second copy is noise, not a lead',
                'status' => 'duplicate',
            ),
            self::D_DUPLICATE_PLACE_ID => array(
                'label'  => 'Exact duplicate Google Place ID',
                'why'    => 'a Place ID identifies one listing; two rows with the same one are the same business',
                'status' => 'duplicate',
            ),
            self::D_PERMANENTLY_CLOSED => array(
                'label'  => 'Permanently closed',
                'why'    => 'Google reports the business as permanently closed; there is nobody to call',
                'status' => 'business_closed',
            ),
            self::D_DNC_MATCH => array(
                'label'  => 'Do not contact',
                'why'    => 'this business asked not to be contacted; the match is on a stored suppression key',
                'status' => 'do_not_contact',
            ),
            self::D_SIMULATED_OUTSIDE_UAT => array(
                'label'  => 'Simulated fixture outside UAT',
                'why'    => 'a test fixture reached a live queue; it is not a real business and must not be worked',
                'status' => 'rejected',
            ),
            self::D_SUPPRESSED => array(
                'label'  => 'Rejected within the suppression period',
                'why'    => 'this result was already judged and the suppression period has not expired',
                'status' => 'rejected',
            ),
        );
    }

    /**
     * @return array rule keys
     */
    public static function deterministicKeys()
    {
        return array_keys(self::deterministicRules());
    }

    /**
     * @param  string $rule
     * @return bool
     */
    public static function isDeterministic($rule)
    {
        return is_string($rule) && array_key_exists($rule, self::deterministicRules());
    }

    /* ---- the decision -------------------------------------------------- */

    /**
     * Decide what happens to an incoming prospect.
     *
     * $signals is a flat description of facts already established by the
     * caller. Nothing here infers, scores or guesses; it reads facts and
     * applies the line.
     *
     * Recognised keys:
     *   duplicate_source_ref  bool   an exact source-reference match exists
     *   duplicate_place_id    bool   an exact Place ID match exists
     *   permanently_closed    bool   Google's business_status says so
     *   dnc_key_match         bool   an exact DNC suppression key matched
     *   simulated             bool   the record carries the simulated prefix
     *   uat_mode              bool   the install is in UAT mode
     *   suppressed_exact      bool   exact-key tombstone match inside the period
     *   suppressed_possible   bool   weaker tombstone match, e.g. shared phone
     *   possible_duplicate    bool   the duplicate checker said POSSIBLE
     *   shared_switchboard    bool   the phone is a known multi-business number
     *   ai_waste_score        float  advisory only; never removes anything
     *
     * @param  array $signals
     * @return array {verdict, rule, label, status, why}
     */
    public static function decide(array $signals)
    {
        // --- deterministic removals, in order of certainty ---------------

        if (!empty($signals['dnc_key_match'])) {
            return self::hit(self::D_DNC_MATCH);
        }

        if (!empty($signals['duplicate_place_id'])) {
            return self::hit(self::D_DUPLICATE_PLACE_ID);
        }

        if (!empty($signals['duplicate_source_ref'])) {
            return self::hit(self::D_DUPLICATE_SOURCE_REF);
        }

        if (!empty($signals['permanently_closed'])) {
            return self::hit(self::D_PERMANENTLY_CLOSED);
        }

        // A simulated fixture is only a problem once it is outside UAT. During
        // UAT the fixtures are the point.
        if (!empty($signals['simulated']) && empty($signals['uat_mode'])) {
            return self::hit(self::D_SIMULATED_OUTSIDE_UAT);
        }

        if (!empty($signals['suppressed_exact'])) {
            return self::hit(self::D_SUPPRESSED);
        }

        // --- everything uncertain waits for a person ---------------------

        if (!empty($signals['suppressed_possible'])) {
            return self::review('a weaker suppression match — the same phone number, not the same listing');
        }

        if (!empty($signals['possible_duplicate'])) {
            return self::review('the duplicate checker reported a possible match');
        }

        if (!empty($signals['shared_switchboard'])) {
            return self::review('the phone number is shared by several businesses');
        }

        // An AI score never removes and never, on its own, holds a record back
        // from the queue — it can only annotate. It is read last and given no
        // authority, which is the whole point of reading it here at all.
        if (isset($signals['ai_waste_score']) && is_numeric($signals['ai_waste_score'])
            && (float) $signals['ai_waste_score'] >= 0.5) {
            return self::review('an automated score suggests this may be waste; a score is not a decision');
        }

        return array(
            'verdict' => self::V_KEEP,
            'rule'    => null,
            'label'   => null,
            'status'  => null,
            'why'     => null,
        );
    }

    /**
     * Could this input ever cause an automatic removal? Used by the test suite
     * to prove no scoring path reaches V_AUTO_REMOVE.
     *
     * @param  array $signals
     * @return bool
     */
    public static function removesAutomatically(array $signals)
    {
        $d = self::decide($signals);

        return $d['verdict'] === self::V_AUTO_REMOVE;
    }

    /**
     * Signals that are advisory only and must never, alone, remove a prospect.
     *
     * @return array
     */
    public static function advisoryOnlySignals()
    {
        return array('ai_waste_score', 'possible_duplicate', 'shared_switchboard',
                     'suppressed_possible');
    }

    private static function hit($rule)
    {
        $r = self::deterministicRules();

        return array(
            'verdict' => self::V_AUTO_REMOVE,
            'rule'    => $rule,
            'label'   => $r[$rule]['label'],
            'status'  => $r[$rule]['status'],
            'why'     => $r[$rule]['why'],
        );
    }

    private static function review($why)
    {
        return array(
            'verdict' => self::V_REVIEW,
            'rule'    => null,
            'label'   => self::REVIEW_LABEL,
            'status'  => null,
            'why'     => $why,
        );
    }
}
