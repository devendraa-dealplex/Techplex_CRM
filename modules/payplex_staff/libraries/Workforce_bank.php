<?php
defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Beneficiary bank details — the store the payout engine has been asking for
 * since it was written.
 *
 * WHAT WAS MEASURED ON STAGING, 2026-09-11
 * ----------------------------------------
 * Payplex_commission_payout::validateBankDetails() correctly refuses to pay
 * anybody without a verified account reference, an IFSC and a beneficiary name.
 * Then I searched every column in all 220 tables matching %bank%, %ifsc%,
 * %account% or %upi%. Outside the payout-items table itself the entire database
 * contains exactly two:
 *
 *   tblpayplex_staff_profiles.bank_enc        mediumtext  — NULL on all 18 staff
 *   tblpayplex_staff_profiles.bank_verified   tinyint     — 0    on all 18 staff
 *
 * No IFSC field. No account number. No beneficiary name. No bank name. So
 * beneficiaryDetails() hands back an empty IFSC for every person alive,
 * validateBankDetails() answers `no_ifsc`, blockingIssues() blocks, and NO batch
 * could ever be approved or exported even if somebody held the capability.
 *
 * That is the mirror image of the defect this project keeps finding. The usual
 * shape is a control that never fires. This is a control that ALWAYS fires,
 * because the data it guards does not exist. Both look like safety and neither
 * is one.
 *
 * TWO RULES WORTH STATING BEFORE THE CODE
 * ---------------------------------------
 * 1. Changing an account number un-verifies it. A verified account whose digits
 *    were edited afterwards is an unverified account, and treating it otherwise
 *    is how money reaches the wrong person with a green tick next to it.
 * 2. Nobody verifies their own bank details. The same rule as document
 *    verification and commission approval, for the same reason.
 */
class Workforce_bank
{
    const UNVERIFIED = 'unverified';
    const VERIFIED   = 'verified';
    const REJECTED   = 'rejected';

    public static function states()
    {
        return array(
            self::UNVERIFIED => 'Not verified',
            self::VERIFIED   => 'Verified',
            self::REJECTED   => 'Rejected',
        );
    }

    public static function accountTypes()
    {
        return array('savings' => 'Savings', 'current' => 'Current');
    }

    /* ==================================================================== *
     * Validation
     * ==================================================================== */

    /** Indian IFSC: four letters, then 0, then six alphanumerics. */
    public static function validIfsc($ifsc)
    {
        return (bool) preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', strtoupper(trim((string) $ifsc)));
    }

    /**
     * Account numbers in India run roughly 9–18 digits depending on the bank.
     * Digits only — a value with letters or punctuation in it is somebody's
     * note, not an account number, and paying against a note is not a thing
     * this system is going to do.
     */
    public static function validAccountNumber($acc)
    {
        $a = preg_replace('/\s+/', '', (string) $acc);
        return (bool) preg_match('/^\d{9,18}$/', $a);
    }

    /**
     * Mask, leaving the last four. Identical in behaviour to the payout
     * engine's maskAccount() — deliberately, because two masking functions that
     * drift apart produce one screen that leaks and one that does not.
     */
    public static function mask($account)
    {
        $a = preg_replace('/\s+/', '', (string) $account);
        $len = strlen($a);
        if ($len === 0) { return ''; }
        if ($len <= 4)  { return str_repeat('X', $len); }
        return str_repeat('X', $len - 4) . substr($a, -4);
    }

    /** Does this value still look like a bare account number? */
    public static function looksUnmasked($value)
    {
        $v = preg_replace('/\s+/', '', (string) $value);
        return $v !== '' && (bool) preg_match('/^\d{6,}$/', $v);
    }

    /**
     * @return array ok, errors (field => message), normalised
     */
    public static function validate($post)
    {
        $p = (array) $post;
        $e = array();

        $name = trim((string) (isset($p['beneficiary_name']) ? $p['beneficiary_name'] : ''));
        if ($name === '') {
            $e['beneficiary_name'] = 'The beneficiary name is required, exactly as the bank holds it.';
        } elseif (mb_strlen($name) > 150) {
            $e['beneficiary_name'] = 'That name is too long for a bank record.';
        }

        $acc  = preg_replace('/\s+/', '', (string) (isset($p['account_number']) ? $p['account_number'] : ''));
        $acc2 = preg_replace('/\s+/', '', (string) (isset($p['account_number_confirm']) ? $p['account_number_confirm'] : ''));
        if ($acc === '') {
            $e['account_number'] = 'The account number is required.';
        } elseif (!self::validAccountNumber($acc)) {
            $e['account_number'] = 'An account number is 9 to 18 digits, with nothing else in it.';
        } elseif ($acc2 !== '' && $acc !== $acc2) {
            /*
             * Typed twice on purpose. A mistyped account number is the single
             * most expensive typo in this whole system, and it is not caught by
             * any format check because the wrong number is usually still valid.
             */
            $e['account_number_confirm'] = 'The two account numbers do not match.';
        }

        $ifsc = strtoupper(trim((string) (isset($p['ifsc']) ? $p['ifsc'] : '')));
        if ($ifsc === '') {
            $e['ifsc'] = 'The IFSC is required.';
        } elseif (!self::validIfsc($ifsc)) {
            $e['ifsc'] = 'IFSC "' . $ifsc . '" is not a valid format (4 letters, 0, then 6 characters).';
        }

        $bank = trim((string) (isset($p['bank_name']) ? $p['bank_name'] : ''));
        if ($bank === '') { $e['bank_name'] = 'The bank name is required.'; }

        $type = (string) (isset($p['account_type']) ? $p['account_type'] : '');
        if (!array_key_exists($type, self::accountTypes())) {
            $e['account_type'] = 'Choose savings or current.';
        }

        return array(
            'ok'     => empty($e),
            'errors' => $e,
            'normalised' => array(
                'beneficiary_name' => $name,
                'account_number'   => $acc,
                'masked_account'   => self::mask($acc),
                'ifsc'             => $ifsc,
                'bank_name'        => $bank,
                'account_type'     => $type,
            ),
        );
    }

    /* ==================================================================== *
     * Verification
     * ==================================================================== */

    /**
     * May $actorId verify or reject the account belonging to $ownerId?
     *
     * @return array allowed, code, reason
     */
    public static function canVerify($ownerId, $actorId, $hasCapability)
    {
        $owner = (int) $ownerId;
        $actor = (int) $actorId;

        if ($actor <= 0) {
            return array('allowed' => false, 'code' => 'no_actor',
                         'reason' => 'An acting user must be identified.');
        }
        if (!$hasCapability) {
            return array('allowed' => false, 'code' => 'not_permitted',
                         'reason' => 'Verifying bank details needs the bank verification permission.');
        }
        if ($owner === $actor) {
            return array('allowed' => false, 'code' => 'self_verification',
                'reason' => 'You cannot verify your own bank details. Confirming the account that '
                          . 'money will be sent to is exactly the check that must be done by '
                          . 'somebody else — administrators included.');
        }
        return array('allowed' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * What a change to an existing account does to its verification.
     *
     * Anything that alters where the money actually goes resets verification.
     * A change of bank NAME alone does not — banks rename and merge, and the
     * account and IFSC are what the transfer uses.
     *
     * @return array resets, changed (list of fields), reason
     */
    public static function verificationImpact(array $before, array $after)
    {
        $material = array('account_number' => 'account number', 'ifsc' => 'IFSC',
                          'beneficiary_name' => 'beneficiary name');
        $changed = array();
        foreach ($material as $f => $label) {
            $b = strtoupper(preg_replace('/\s+/', '', (string) (isset($before[$f]) ? $before[$f] : '')));
            $a = strtoupper(preg_replace('/\s+/', '', (string) (isset($after[$f])  ? $after[$f]  : '')));
            if ($b !== $a) { $changed[] = $label; }
        }
        if (!$changed) {
            return array('resets' => false, 'changed' => array(), 'reason' => '');
        }
        return array('resets' => true, 'changed' => $changed,
            'reason' => 'The ' . implode(' and ', $changed) . ' changed, so this account is no longer '
                      . 'verified. A verified account whose digits were edited afterwards is an '
                      . 'unverified account.');
    }

    /**
     * Is this account fit to be paid?
     *
     * Deliberately the same shape as the payout engine's validateBankDetails(),
     * and deliberately stricter in one respect: that function trusts whatever
     * `bank_verified` says, and this one also requires the verification to
     * belong to a real second person.
     *
     * @return array ok, code, reason
     */
    public static function payable($account)
    {
        $a = (array) $account;

        if (!$a) {
            return array('ok' => false, 'code' => 'no_account',
                'reason' => 'No bank account is on file for this person.');
        }
        if ((string) (isset($a['verification_state']) ? $a['verification_state'] : '') !== self::VERIFIED) {
            return array('ok' => false, 'code' => 'unverified',
                'reason' => 'These bank details are not verified.');
        }
        if ((int) (isset($a['verified_by']) ? $a['verified_by'] : 0) <= 0) {
            return array('ok' => false, 'code' => 'no_verifier',
                'reason' => 'The account is marked verified with nobody recorded as having verified '
                          . 'it. That is a flag, not a verification.');
        }
        if ((int) (isset($a['verified_by']) ? $a['verified_by'] : 0)
            === (int) (isset($a['staff_id']) ? $a['staff_id'] : -1)) {
            return array('ok' => false, 'code' => 'self_verified',
                'reason' => 'This account was verified by the person it belongs to.');
        }
        if (!self::validIfsc(isset($a['ifsc']) ? $a['ifsc'] : '')) {
            return array('ok' => false, 'code' => 'bad_ifsc', 'reason' => 'The IFSC on file is not valid.');
        }
        if (trim((string) (isset($a['beneficiary_name']) ? $a['beneficiary_name'] : '')) === '') {
            return array('ok' => false, 'code' => 'no_beneficiary', 'reason' => 'No beneficiary name on file.');
        }
        if (self::looksUnmasked(isset($a['masked_account']) ? $a['masked_account'] : '')) {
            return array('ok' => false, 'code' => 'unmasked_on_record',
                'reason' => 'The stored "masked" account is not masked. Refusing rather than '
                          . 'putting a bare account number into an export.');
        }
        if ((int) (isset($a['is_current']) ? $a['is_current'] : 1) !== 1) {
            return array('ok' => false, 'code' => 'superseded',
                'reason' => 'This account has been replaced by a newer one.');
        }
        return array('ok' => true, 'code' => 'ok', 'reason' => '');
    }

    /**
     * The fields that may be shown to somebody who is not the account holder.
     * Everything that reaches a screen, an export or a log goes through here.
     */
    /**
     * The only fields that may leave the model.
     *
     * A whitelist, and a named one. My first version of forDisplay() built this
     * same list and then called unset() on account_enc "for safety" — which was
     * theatre: the key was never in the array, so the unset could be deleted
     * with no effect and a mutation test proved exactly that. A control that
     * cannot fail is not a control, and this project has spent seven modules
     * finding other people's. Naming the list makes it testable: a future edit
     * that adds a sensitive field here fails the suite.
     */
    public static function displayFields()
    {
        return array('beneficiary_name', 'masked_account', 'ifsc', 'bank_name',
                     'account_type', 'verification_state', 'verified_at');
    }

    public static function forDisplay(array $account, $isOwner = false)
    {
        $defaults = array('verification_state' => self::UNVERIFIED, 'verified_at' => null);
        $out = array();
        foreach (self::displayFields() as $f) {
            $out[$f] = array_key_exists($f, $account) ? $account[$f]
                     : (array_key_exists($f, $defaults) ? $defaults[$f] : '');
        }
        /* The owner gets no more than anybody else. There is no screen in this
           system that needs the account number printed back, not even to the
           person it belongs to — they have their passbook. */
        $out['is_owner'] = (bool) $isOwner;
        return $out;
    }
}
