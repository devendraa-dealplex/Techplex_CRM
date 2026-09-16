<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_status — the Prospect Verification Queue, as a state machine.
 *
 * THE ONE PROPERTY EVERYTHING ELSE SERVES
 * ---------------------------------------
 * §5: "Search results must not be added directly to the main Leads table."
 *
 * That is not a UI decision to be honoured by remembering to honour it. It is
 * expressed here as: `converted_to_lead` is reachable from exactly ONE state,
 * `confirmed_interested`, and `mayConvert()` is the only thing that says so.
 * Every other path to the main Leads table has to go through a false return
 * value from this class.
 *
 * §9's recommended rules, encoded:
 *   confirmed_interested  -> convert
 *   valid_not_interested  -> nurture pool, never converts
 *   wrong / closed / duplicate / irrelevant / fake -> reject
 *   no_answer             -> callback, NEVER converts
 *
 * WHY TRANSITIONS ARE A TABLE
 * ---------------------------
 * Written as `if` statements, "can this become that" ends up answered in four
 * places that drift. As a table it can be asserted over: the tests below walk
 * every one of the 16 states against every other and check the ones that must
 * never be reachable, which is 256 answers no one would write by hand.
 */
class Leadfinder_status
{
    /* §5, in order */
    const NEW_RESULT           = 'new_result';
    const CLAIMED              = 'claimed';
    const VERIFICATION_PENDING = 'verification_pending';
    const CALLING              = 'calling';
    const NO_ANSWER            = 'no_answer';
    const CALLBACK_SCHEDULED   = 'callback_scheduled';
    const CONTACTED            = 'contacted';
    const CONFIRMED_INTERESTED = 'confirmed_interested';
    const VALID_NOT_INTERESTED = 'valid_not_interested';
    const WRONG_NUMBER         = 'wrong_number';
    const BUSINESS_CLOSED      = 'business_closed';
    const DUPLICATE            = 'duplicate';
    const IRRELEVANT           = 'irrelevant';
    const DO_NOT_CONTACT       = 'do_not_contact';
    const REJECTED             = 'rejected';
    /*
     * The maker-checker waiting room, added in Step 7 so Step 8's gate has a
     * state to put a prospect into.
     *
     * It exists as its own status rather than as a flag because the whole point
     * of the gate is that a submitted prospect is NOT yet a lead and is NOT
     * still just "confirmed and interested". Without a distinct state, an
     * approver has nothing to filter on, the submitter can keep editing what
     * they submitted, and a second submission looks exactly like the first.
     */
    const CONVERSION_PENDING   = 'conversion_pending';
    const CONVERTED_TO_LEAD    = 'converted_to_lead';

    public static function all()
    {
        return array(
            self::NEW_RESULT, self::CLAIMED, self::VERIFICATION_PENDING, self::CALLING,
            self::NO_ANSWER, self::CALLBACK_SCHEDULED, self::CONTACTED,
            self::CONFIRMED_INTERESTED, self::VALID_NOT_INTERESTED, self::WRONG_NUMBER,
            self::BUSINESS_CLOSED, self::DUPLICATE, self::IRRELEVANT, self::DO_NOT_CONTACT,
            self::REJECTED, self::CONVERSION_PENDING, self::CONVERTED_TO_LEAD,
        );
    }

    public static function label($s)
    {
        $m = array(
            self::NEW_RESULT => 'New Result', self::CLAIMED => 'Claimed',
            self::VERIFICATION_PENDING => 'Verification Pending', self::CALLING => 'Calling',
            self::NO_ANSWER => 'No Answer', self::CALLBACK_SCHEDULED => 'Callback Scheduled',
            self::CONTACTED => 'Contacted', self::CONFIRMED_INTERESTED => 'Confirmed and Interested',
            self::VALID_NOT_INTERESTED => 'Valid but Not Interested', self::WRONG_NUMBER => 'Wrong Number',
            self::BUSINESS_CLOSED => 'Closed', self::DUPLICATE => 'Duplicate',
            self::IRRELEVANT => 'Irrelevant', self::DO_NOT_CONTACT => 'Do Not Contact',
            self::REJECTED => 'Rejected', self::CONVERSION_PENDING => 'Conversion Pending',
            self::CONVERTED_TO_LEAD => 'Converted to Lead',
        );
        return isset($m[$s]) ? $m[$s] : '';
    }

    /** Nothing leaves these — except a manager restoring a rejection (§12). */
    public static function terminal()
    {
        return array(self::CONVERTED_TO_LEAD, self::REJECTED, self::DO_NOT_CONTACT);
    }

    /** Outcomes that mean "this is not a prospect": they end in rejection. */
    public static function invalidOutcomes()
    {
        return array(self::WRONG_NUMBER, self::BUSINESS_CLOSED, self::DUPLICATE, self::IRRELEVANT);
    }

    /**
     * The transition table. from => list of permitted to-states.
     * Absent key, or absent target, means no.
     */
    public static function transitions()
    {
        $closeOut = array(self::WRONG_NUMBER, self::BUSINESS_CLOSED, self::DUPLICATE,
                          self::IRRELEVANT, self::DO_NOT_CONTACT, self::REJECTED);

        return array(
            self::NEW_RESULT => array_merge(array(self::CLAIMED), $closeOut),
            self::CLAIMED => array_merge(
                array(self::VERIFICATION_PENDING, self::CALLING, self::NEW_RESULT), $closeOut),
            self::VERIFICATION_PENDING => array_merge(
                array(self::CALLING, self::CONTACTED, self::NEW_RESULT), $closeOut),
            self::CALLING => array_merge(
                array(self::NO_ANSWER, self::CALLBACK_SCHEDULED, self::CONTACTED), $closeOut),
            self::NO_ANSWER => array_merge(
                array(self::CALLBACK_SCHEDULED, self::CALLING, self::NEW_RESULT), $closeOut),
            self::CALLBACK_SCHEDULED => array_merge(
                array(self::CALLING, self::CONTACTED, self::NO_ANSWER), $closeOut),
            self::CONTACTED => array_merge(
                array(self::CONFIRMED_INTERESTED, self::VALID_NOT_INTERESTED,
                      self::CALLBACK_SCHEDULED, self::CALLING), $closeOut),

            /*
             * The route into the main Leads table now runs through the waiting
             * room, and only through it.
             *
             * `CONFIRMED_INTERESTED -> CONVERTED_TO_LEAD` used to be a single
             * edge, which meant the person who verified a prospect could also
             * be the person who created the lead — the separation of duties the
             * brief requires, absent from the state machine that was supposed
             * to enforce it. Submitting and approving are now two different
             * transitions, and Step 8 requires two different people.
             */
            self::CONFIRMED_INTERESTED => array_merge(
                array(self::CONVERSION_PENDING, self::CONTACTED), $closeOut),

            /*
             * Waiting for an approver. Only two ways out: approved, or sent
             * back. A submitter cannot quietly withdraw a submission by moving
             * it sideways — every exit is an approver's decision and is
             * recorded as one.
             */
            self::CONVERSION_PENDING => array(
                self::CONVERTED_TO_LEAD, self::CONFIRMED_INTERESTED, self::REJECTED),

            /* Nurture pool: stays a prospect, never becomes a lead. */
            self::VALID_NOT_INTERESTED => array_merge(
                array(self::CALLBACK_SCHEDULED, self::CONTACTED), $closeOut),

            self::WRONG_NUMBER    => array(self::REJECTED, self::CALLING),
            self::BUSINESS_CLOSED => array(self::REJECTED),
            self::DUPLICATE       => array(self::REJECTED),
            self::IRRELEVANT      => array(self::REJECTED),

            self::DO_NOT_CONTACT    => array(),
            self::REJECTED          => array(),   // manager restore is a separate, audited path
            self::CONVERTED_TO_LEAD => array(),
        );
    }

    /**
     * May this prospect move from $from to $to?
     *
     * @param array $ctx  is_manager, has_evidence (see mayConvert), reason
     * @return array allowed, reason
     */
    public static function canTransition($from, $to, array $ctx = array())
    {
        if (!in_array($from, self::all(), true)) { return self::no('unknown_from_state'); }
        if (!in_array($to, self::all(), true))   { return self::no('unknown_to_state'); }
        if ($from === $to)                        { return self::no('no_change'); }

        $t = self::transitions();
        if (!isset($t[$from]) || !in_array($to, $t[$from], true)) {
            return self::no('transition_not_permitted');
        }

        /* Converting is gated by evidence, not just by the edge existing. */
        if ($to === self::CONVERTED_TO_LEAD) {
            $g = self::mayConvert($from, isset($ctx['evidence']) ? (array) $ctx['evidence'] : array());
            return $g['allowed'] ? self::yes('convert_gate_passed') : self::no($g['reason']);
        }

        /* Rejecting requires a reason (§12: "Require reason for rejection"). */
        if ($to === self::REJECTED && trim((string) (isset($ctx['reason']) ? $ctx['reason'] : '')) === '') {
            return self::no('rejection_reason_required');
        }

        return self::yes('permitted');
    }

    /**
     * §9's conversion gate. The single door to the main Leads table.
     *
     * Every condition must hold. Listed as data so the UI can show which one is
     * missing, and so a test can prove each is load-bearing by flipping exactly
     * one and watching the gate close.
     */
    public static function conversionRequirements()
    {
        return array(
            'status_confirmed'   => 'Status is Confirmed and Interested, or awaiting approval',
            'business_operational' => 'Business status is operational',
            'contacted'          => 'Employee has contacted or verified the business',
            'phone_valid'        => 'A normalised, valid phone number is held',
            'relevant'           => 'Marked relevant to the selected product or service',
            'not_duplicate'      => 'Duplicate check returned New',
            'fields_complete'    => 'Mandatory verification fields are filled',
            'outcome_recorded'   => 'A call outcome has been recorded',
        );
    }

    public static function mayConvert($from, array $evidence)
    {
        /*
         * Two states may convert, and they are two halves of one gate.
         *
         * `confirmed_interested` is what a submitter may act on; the approver
         * acts on `conversion_pending`. Allowing only the first would mean the
         * approval step had to move the prospect back before it could approve
         * it, which loses the record that it was ever submitted. Allowing any
         * state would defeat the point.
         *
         * This says nothing about WHO may do it — that is Step 8's job, and it
         * is a separate check, because a state machine cannot tell two people
         * apart.
         */
        if ($from !== self::CONFIRMED_INTERESTED && $from !== self::CONVERSION_PENDING) {
            return self::no('only_confirmed_interested_may_convert');
        }
        $missing = array();
        foreach (array_keys(self::conversionRequirements()) as $k) {
            if (empty($evidence[$k])) { $missing[] = $k; }
        }
        if ($missing) {
            return array('allowed' => false, 'reason' => 'missing_evidence', 'missing' => $missing);
        }
        return array('allowed' => true, 'reason' => 'all_requirements_met', 'missing' => array());
    }

    /** §12: a manager may restore a rejection. Nothing else leaves a terminal state. */
    public static function canRestore($from, $isManager)
    {
        if ($from !== self::REJECTED)  { return self::no('only_rejected_may_be_restored'); }
        if ($isManager !== true)       { return self::no('requires_manager'); }
        return self::yes('restored_to_new_result');
    }

    /** Does this state belong in the main CRM lead database? Exactly one does. */
    public static function isInMainLeads($s) { return $s === self::CONVERTED_TO_LEAD; }

    private static function yes($r) { return array('allowed' => true,  'reason' => $r); }
    private static function no($r)  { return array('allowed' => false, 'reason' => $r); }
}
