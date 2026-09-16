<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Payplex_staff_types.php';

/**
 * Payplex_staff_profile
 *
 * Validation, normalisation and eligibility resolution for a staff profile,
 * plus the classification-required backfill logic for existing staff. Pure +
 * dependency-free (no DB, no crypto — the model does encryption/storage).
 *
 * Eligibility resolution: start from the employment type's defaults, then let
 * any explicitly-set flag on the profile override it. A "custom" type has no
 * defaults, so every flag must be set explicitly.
 */
class Payplex_staff_profile
{
    /** Fields that must be encrypted + permission-gated (never in plain logs/exports). */
    private static $sensitive = array(
        'bank_account', 'bank_ifsc', 'bank_name', 'bank_holder',
        'pan_number', 'tax_id', 'aadhaar', 'emergency_contact_phone',
    );

    private static $eligKeys = array('salary', 'commission', 'expense', 'tada', 'attendance');

    public static function isSensitiveField($field)
    {
        return in_array($field, self::$sensitive, true);
    }

    public static function sensitiveFields()
    {
        return self::$sensitive;
    }

    /** Is this profile missing a determinable classification? */
    public static function isClassificationRequired($record)
    {
        $t = isset($record['employment_type']) ? trim((string) $record['employment_type']) : '';
        if ($t === '') { return true; }
        return !Payplex_staff_types::isValid($t);
    }

    private static function toBool($v)
    {
        if ($v === true || $v === 1 || $v === '1' || $v === 'yes' || $v === 'true') { return true; }
        if ($v === false || $v === 0 || $v === '0' || $v === 'no' || $v === 'false') { return false; }
        return null;
    }

    /**
     * Validate + normalise a profile payload.
     * @return array ok, errors[], warnings[], entry{}
     */
    public static function validate($data)
    {
        $errors = array(); $warnings = array();
        $name = isset($data['full_name']) ? trim((string) $data['full_name']) : '';
        if ($name === '') { $errors[] = 'full_name_required'; }

        $typeRaw = isset($data['employment_type']) ? (string) $data['employment_type'] : '';
        $classificationRequired = self::isClassificationRequired(array('employment_type' => $typeRaw)) ? 1 : 0;
        $type = Payplex_staff_types::normalize($typeRaw);

        // resolve eligibility: type defaults, overridden by explicit flags
        $defaults = Payplex_staff_types::defaultEligibility($type);
        $elig = array();
        foreach (self::$eligKeys as $k) {
            $explicit = isset($data[$k . '_eligibility']) ? self::toBool($data[$k . '_eligibility']) : null;
            if ($explicit !== null) {
                $elig[$k] = $explicit;
            } elseif ($defaults[$k] !== null) {
                $elig[$k] = $defaults[$k];
            } else {
                $elig[$k] = false; // custom type with no explicit value defaults to NOT eligible (safe)
                $warnings[] = $k . '_eligibility_unset_defaulted_false';
            }
        }

        $email = isset($data['official_email']) ? trim((string) $data['official_email']) : '';
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'official_email_invalid'; }

        $entry = array(
            'staff_id'             => isset($data['staff_id']) ? (int) $data['staff_id'] : 0,
            'full_name'            => $name,
            'employee_code'        => isset($data['employee_code']) ? substr(trim((string) $data['employee_code']), 0, 60) : '',
            'official_email'       => $email,
            'official_mobile'      => isset($data['official_mobile']) ? substr(trim((string) $data['official_mobile']), 0, 40) : '',
            'department'           => isset($data['department']) ? substr(trim((string) $data['department']), 0, 100) : '',
            'designation'          => isset($data['designation']) ? substr(trim((string) $data['designation']), 0, 100) : '',
            'reporting_manager_id' => isset($data['reporting_manager_id']) && $data['reporting_manager_id'] !== '' ? (int) $data['reporting_manager_id'] : null,
            'branch'               => isset($data['branch']) ? substr(trim((string) $data['branch']), 0, 100) : '',
            'territory'            => isset($data['territory']) ? substr(trim((string) $data['territory']), 0, 100) : '',
            'employment_type'      => $type,
            'joining_date'         => self::date($data, 'joining_date'),
            'probation_end_date'   => self::date($data, 'probation_end_date'),
            'salary_eligibility'     => $elig['salary'] ? 1 : 0,
            'commission_eligibility' => $elig['commission'] ? 1 : 0,
            'expense_eligibility'    => $elig['expense'] ? 1 : 0,
            'tada_eligibility'       => $elig['tada'] ? 1 : 0,
            'attendance_required'    => $elig['attendance'] ? 1 : 0,
            'target_plan'          => isset($data['target_plan']) ? substr(trim((string) $data['target_plan']), 0, 100) : '',
            'commission_plan'      => isset($data['commission_plan']) ? substr(trim((string) $data['commission_plan']), 0, 100) : '',
            'payout_frequency'     => isset($data['payout_frequency']) ? substr(trim((string) $data['payout_frequency']), 0, 40) : 'monthly',
            'bank_verified'        => isset($data['bank_verified']) ? (int) $data['bank_verified'] : 0,
            'pan_status'           => isset($data['pan_status']) ? substr(trim((string) $data['pan_status']), 0, 30) : 'pending',
            'kyc_status'           => isset($data['kyc_status']) ? substr(trim((string) $data['kyc_status']), 0, 30) : 'pending',
            'staff_status'         => isset($data['staff_status']) ? substr(trim((string) $data['staff_status']), 0, 30) : 'draft',
            'exit_date'            => self::date($data, 'exit_date'),
            'exit_reason'          => isset($data['exit_reason']) ? substr(trim((string) $data['exit_reason']), 0, 255) : '',
            'classification_required' => $classificationRequired,
        );

        // booleans returned as PHP bool too (tests read true/false)
        $entry['commission_eligibility'] = (bool) $entry['commission_eligibility'];
        $entry['salary_eligibility']     = (bool) $entry['salary_eligibility'];
        $entry['expense_eligibility']    = (bool) $entry['expense_eligibility'];
        $entry['tada_eligibility']       = (bool) $entry['tada_eligibility'];
        $entry['attendance_required']    = (bool) $entry['attendance_required'];

        return array('ok' => empty($errors), 'errors' => $errors, 'warnings' => $warnings, 'entry' => $entry);
    }

    private static function date($data, $key)
    {
        $v = isset($data[$key]) ? trim((string) $data[$key]) : '';
        if ($v === '') { return null; }
        $t = strtotime($v);
        return $t === false ? null : date('Y-m-d', $t);
    }

    /**
     * Build a safe backfill profile for an existing (legacy) staff row whose
     * classification cannot be determined. Preserves the person as ACTIVE (their
     * login/work is unaffected) but flags them classification_required so admin
     * completes it, and leaves all financial eligibility OFF until classified.
     */
    public static function backfillProfile($staff)
    {
        return array(
            'staff_id'   => isset($staff['staff_id']) ? (int) $staff['staff_id'] : 0,
            'full_name'  => isset($staff['full_name']) ? (string) $staff['full_name'] : '',
            'official_email' => isset($staff['official_email']) ? (string) $staff['official_email'] : '',
            'employment_type' => 'custom',
            'salary_eligibility' => 0, 'commission_eligibility' => 0,
            'expense_eligibility' => 0, 'tada_eligibility' => 0, 'attendance_required' => 0,
            'kyc_status' => 'pending', 'pan_status' => 'pending', 'bank_verified' => 0,
            'status'       => 'active',           // legacy staff keep working
            'classification_required' => 1,       // but must be classified
            'payout_frequency' => 'monthly',
        );
    }

    /** Financial actions blocked while KYC or bank is not complete. */
    public static function financialBlocked($record)
    {
        $kyc  = isset($record['kyc_status']) ? $record['kyc_status'] : '';
        $bank = isset($record['bank_verified']) ? (int) $record['bank_verified'] : 0;
        return !($kyc === 'verified' && $bank === 1);
    }
}
