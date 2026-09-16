<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_status.php';

/**
 * Leadfinder_verification — what an employee must record after a call, and who
 * may turn that into a CRM lead.
 *
 * WHY THE REQUIRED FIELDS DEPEND ON THE OUTCOME
 * ---------------------------------------------
 * A single mandatory list is either too strict or useless. Demanding a contact
 * person's designation for a call that rang out teaches employees to type "n/a"
 * into every box, and once they are typing "n/a" the fields stop meaning
 * anything — including on the calls where they mattered. Demanding nothing
 * means a prospect can reach the conversion gate with a disposition and no
 * evidence at all.
 *
 * So the list is a function of the disposition. "No answer" needs a callback
 * time and nothing else. "Contacted and interested" needs the person, their
 * designation, a verified phone, and what they actually want. "Wrong number"
 * needs a reason and explicitly must NOT demand a contact person, because there
 * was nobody to speak to.
 *
 * THE MAKER-CHECKER RULE IS ONE LINE AND IT IS THE POINT
 * ------------------------------------------------------
 * `approverMayApprove()` refuses when the approver is the submitter. Everything
 * else in this class is bookkeeping around that sentence. It is separate from
 * the capability check on purpose: holding `leadfinder_approve_conversion` says
 * a person may approve conversions, and says nothing about whether they may
 * approve *this* one. An administrator who also verifies prospects holds both
 * capabilities and still may not approve their own work.
 *
 * No I/O. The model does the writing; this decides.
 */
class Leadfinder_verification
{
    /* Call dispositions. These are what happened on the phone. */
    const D_CONNECTED       = 'connected';
    const D_NO_ANSWER       = 'no_answer';
    const D_BUSY            = 'busy';
    const D_WRONG_NUMBER    = 'wrong_number';
    const D_CALLBACK        = 'callback_requested';
    const D_NOT_INTERESTED  = 'not_interested';
    const D_DO_NOT_CONTACT  = 'do_not_contact_requested';
    const D_BUSINESS_CLOSED = 'business_closed';

    /* Interest, recorded only when somebody actually answered. */
    const I_HIGH   = 'high';
    const I_MEDIUM = 'medium';
    const I_LOW    = 'low';
    const I_NONE   = 'none';

    /* Conversion states. */
    const C_PENDING  = 'pending';
    const C_APPROVED = 'approved';
    const C_REJECTED = 'rejected';

    public static function dispositions()
    {
        return array(
            self::D_CONNECTED       => 'Spoke to someone',
            self::D_NO_ANSWER       => 'No answer',
            self::D_BUSY            => 'Line busy',
            self::D_WRONG_NUMBER    => 'Wrong number',
            self::D_CALLBACK        => 'Callback requested',
            self::D_NOT_INTERESTED  => 'Not interested',
            self::D_DO_NOT_CONTACT  => 'Asked not to be contacted again',
            self::D_BUSINESS_CLOSED => 'Business has closed',
        );
    }

    public static function interestLevels()
    {
        return array(self::I_HIGH => 'High', self::I_MEDIUM => 'Medium',
                     self::I_LOW => 'Low', self::I_NONE => 'None');
    }

    /**
     * Every field the verification form can carry.
     *
     * Listed so the save path can reject anything not on it. A verification form
     * that accepts whatever is posted is a form that can write `claimed_by`.
     */
    public static function writableFields()
    {
        return array(
            'call_disposition', 'verified_contact_name', 'verified_designation',
            'verified_email', 'phone_verified', 'email_verified', 'requirement',
            'interest_level', 'verification_notes', 'next_followup_at',
            'rejection_reason',
        );
    }

    /**
     * What this disposition obliges the employee to record.
     *
     * Returns field => human sentence, so the form can say what is missing
     * rather than reporting "invalid".
     */
    public static function requiredFor($disposition)
    {
        $person = array(
            'verified_contact_name' => 'the name of the person you spoke to',
            'verified_designation'  => 'their role or designation',
        );

        switch ($disposition) {
            case self::D_CONNECTED:
                return array_merge($person, array(
                    'phone_verified'  => 'confirmation that the phone number is correct',
                    'requirement'     => 'what the business actually needs',
                    'interest_level'  => 'how interested they are',
                ));

            case self::D_NOT_INTERESTED:
                return array_merge($person, array(
                    'verification_notes' => 'a note on why they are not interested',
                ));

            case self::D_CALLBACK:
                return array_merge($person, array(
                    'next_followup_at' => 'the date and time they asked you to call back',
                ));

            case self::D_NO_ANSWER:
            case self::D_BUSY:
                /*
                 * Nobody answered, so there is nobody to name. Demanding a
                 * contact person here is how "n/a" gets typed into every field
                 * in the form, including the ones that matter on other calls.
                 */
                return array();

            case self::D_WRONG_NUMBER:
            case self::D_BUSINESS_CLOSED:
                return array('rejection_reason' => 'what you found out');

            case self::D_DO_NOT_CONTACT:
                return array('rejection_reason' => 'what they asked for, in their words');
        }

        return array();
    }

    /**
     * Check a submitted verification.
     *
     * @return array ok, missing (field => sentence), errors (field => sentence)
     */
    public static function validate(array $d, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        $disposition = isset($d['call_disposition']) ? trim((string) $d['call_disposition']) : '';

        if ($disposition === '') {
            return array('ok' => false, 'missing' => array(
                'call_disposition' => 'what happened on the call'), 'errors' => array());
        }

        if (!array_key_exists($disposition, self::dispositions())) {
            return array('ok' => false, 'missing' => array(), 'errors' => array(
                'call_disposition' => 'that is not one of the recorded call outcomes'));
        }

        $missing = array();
        $errors  = array();

        foreach (self::requiredFor($disposition) as $field => $why) {
            $v = isset($d[$field]) ? $d[$field] : null;

            if ($v === null || (is_string($v) && trim($v) === '') || $v === 0 || $v === '0') {
                $missing[$field] = $why;
            }
        }

        /* Interest, when given, must be one of the four. */
        if (isset($d['interest_level']) && trim((string) $d['interest_level']) !== ''
            && !array_key_exists($d['interest_level'], self::interestLevels())) {
            $errors['interest_level'] = 'that is not one of the recorded interest levels';
        }

        /*
         * A callback in the past is not a callback.
         *
         * It is the kind of value that gets typed when a date field defaults to
         * today and the employee changes only the time, and it produces a
         * follow-up queue that is permanently overdue on its first day.
         */
        if (isset($d['next_followup_at']) && (int) $d['next_followup_at'] > 0
            && (int) $d['next_followup_at'] < $now) {
            $errors['next_followup_at'] = 'that callback time has already passed';
        }

        /*
         * An email is recorded by a person, so it is checked like one typed by a
         * person — but only rejected when it is clearly not an address. A
         * stricter rule here would reject valid addresses and teach employees to
         * leave the field empty.
         */
        if (isset($d['verified_email']) && trim((string) $d['verified_email']) !== ''
            && !filter_var(trim((string) $d['verified_email']), FILTER_VALIDATE_EMAIL)) {
            $errors['verified_email'] = 'that does not look like an email address';
        }

        return array('ok' => !$missing && !$errors, 'missing' => $missing, 'errors' => $errors);
    }

    /**
     * The prospect status this disposition implies.
     *
     * Returns null when the disposition does not by itself decide a status —
     * "connected" could be interested or not, and that is the interest level's
     * business.
     */
    public static function statusFor($disposition, $interest = '')
    {
        switch ($disposition) {
            case self::D_NO_ANSWER:
            case self::D_BUSY:
                return Leadfinder_status::NO_ANSWER;
            case self::D_CALLBACK:
                return Leadfinder_status::CALLBACK_SCHEDULED;
            case self::D_WRONG_NUMBER:
                return Leadfinder_status::WRONG_NUMBER;
            case self::D_BUSINESS_CLOSED:
                return Leadfinder_status::BUSINESS_CLOSED;
            case self::D_DO_NOT_CONTACT:
                return Leadfinder_status::DO_NOT_CONTACT;
            case self::D_NOT_INTERESTED:
                return Leadfinder_status::VALID_NOT_INTERESTED;
            case self::D_CONNECTED:
                if ($interest === self::I_NONE) {
                    return Leadfinder_status::VALID_NOT_INTERESTED;
                }
                return $interest === '' ? Leadfinder_status::CONTACTED
                                        : Leadfinder_status::CONFIRMED_INTERESTED;
        }

        return null;
    }

    /**
     * Evidence for the conversion gate, read from the stored prospect.
     *
     * Deliberately computed from the ROW, not from the form that was just
     * submitted. The gate has to be answerable at approval time, by somebody
     * who was not there for the call, from what is recorded — and if it cannot
     * be, the recording is the thing that is wrong.
     */
    public static function evidenceFrom(array $p)
    {
        $status = isset($p['status']) ? (string) $p['status'] : '';

        return array(
            'status_confirmed' => $status === Leadfinder_status::CONFIRMED_INTERESTED
                                || $status === Leadfinder_status::CONVERSION_PENDING,
            /* Absent means unknown, and unknown is not operational. */
            'business_operational' => isset($p['business_status'])
                                   && strtoupper((string) $p['business_status']) === 'OPERATIONAL',
            'contacted'        => !empty($p['verified_at']),
            'phone_valid'      => !empty($p['phone_e164']),
            'relevant'         => !empty($p['requirement']),
            'not_duplicate'    => isset($p['dupe_state'])
                                && (string) $p['dupe_state'] === 'new',
            'fields_complete'  => !empty($p['verified_contact_name'])
                                && !empty($p['verified_designation']),
            'outcome_recorded' => !empty($p['call_disposition']),
        );
    }

    /**
     * May this person approve this conversion?
     *
     * THE RULE THE WHOLE STEP EXISTS FOR.
     *
     * Separate from the capability check because they answer different
     * questions. `leadfinder_approve_conversion` says a person may approve
     * conversions. This says whether they may approve *this* one — and the
     * answer is no when they are the person who submitted it, however many
     * capabilities they hold, and whether or not they are an administrator.
     *
     * An administrator is not an exception. On a small team the administrator
     * is often also the person making calls, and "admin can do anything" is
     * precisely how a two-person control becomes a one-person control.
     */
    public static function approverMayApprove($submitterId, $approverId, $isAdmin = false)
    {
        $submitterId = (int) $submitterId;
        $approverId  = (int) $approverId;

        if ($approverId <= 0) {
            return self::no('no_authenticated_approver');
        }

        if ($submitterId <= 0) {
            return self::no('submission_has_no_recorded_submitter');
        }

        if ($submitterId === $approverId) {
            return self::no('submitter_cannot_approve_their_own_submission');
        }

        return self::yes('independent_approver');
    }

    /**
     * May this person submit this prospect for conversion?
     *
     * The submitter must hold the claim. Somebody else's prospect is somebody
     * else's call, and their notes are the evidence the approver reads.
     */
    public static function submitterMaySubmit(array $p, $actorId)
    {
        $actorId = (int) $actorId;

        if ($actorId <= 0) { return self::no('no_authenticated_actor'); }

        if ((int) $p['claimed_by'] !== $actorId) {
            return self::no('you_do_not_hold_this_prospect');
        }

        if (!empty($p['is_simulated'])) {
            /*
             * A fixture is not a business. Converting one would put an invented
             * record into the CRM's Leads table, where nothing afterwards marks
             * it as invented.
             */
            return self::no('simulated_prospects_cannot_be_converted');
        }

        if ((string) $p['status'] !== Leadfinder_status::CONFIRMED_INTERESTED) {
            return self::no('only_a_confirmed_interested_prospect_may_be_submitted');
        }

        return self::yes('may_submit');
    }

    /**
     * The reason a rejection needs. Bounded, and never empty.
     */
    public static function rejectionReasonOk($reason)
    {
        $r = trim((string) $reason);

        return $r !== '' && strlen($r) <= 500;
    }

    /**
     * The idempotency key for one conversion.
     *
     * Built from the prospect, not from the request or the clock. Two clicks on
     * Approve, a browser retry, a re-submitted form — all resolve to the same
     * key, so the second one finds the conversion already decided and creates no
     * second lead.
     */
    public static function conversionKey($prospectId)
    {
        return hash('sha256', 'lf-conversion|' . (int) $prospectId);
    }

    /** The human sentence for a refusal. Never leaks an id or a field name. */
    public static function message($reason)
    {
        $m = array(
            'no_authenticated_actor' => 'You are not signed in.',
            'no_authenticated_approver' => 'You are not signed in.',
            'you_do_not_hold_this_prospect' => 'You can only submit a prospect you have claimed.',
            'simulated_prospects_cannot_be_converted' => 'This is a simulated record, not a real business. It cannot be converted.',
            'only_a_confirmed_interested_prospect_may_be_submitted' => 'Only a prospect marked Confirmed and Interested can be submitted for conversion.',
            'submitter_cannot_approve_their_own_submission' => 'You submitted this prospect, so somebody else has to approve it.',
            'submission_has_no_recorded_submitter' => 'This submission has no recorded submitter and cannot be approved.',
            'already_decided' => 'This conversion has already been decided. Nothing was changed.',
            'already_converted' => 'This prospect is already a CRM lead. No second lead was created.',
            'missing_evidence' => 'Some required verification details are missing.',
            'rejection_reason_required' => 'A rejection needs a reason.',
            'not_pending' => 'This prospect is not awaiting approval.',
        );

        return isset($m[$reason]) ? $m[$reason] : 'That action was refused.';
    }

    private static function yes($r) { return array('allowed' => true,  'reason' => $r); }
    private static function no($r)  { return array('allowed' => false, 'reason' => $r); }
}
