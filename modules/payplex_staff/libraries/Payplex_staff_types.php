<?php

defined('BASEPATH') or defined('PAYPLEX_STAFF_TEST') or exit('No direct script access allowed');

/**
 * Payplex_staff_types
 *
 * The engagement / employment taxonomy and the per-type DEFAULT eligibility
 * for salary, commission, expense, TA/DA and attendance. Pure + dependency-free
 * so it can be unit-tested and reused by the model, controller and API.
 *
 * Defaults are only defaults — an admin may override any eligibility flag on an
 * individual staff profile (see Payplex_staff_profile::validate). "custom" is the
 * escape hatch: it carries NO default eligibility (all null) so the admin must
 * decide explicitly.
 */
class Payplex_staff_types
{
    /**
     * slug => [label, elig(salary,commission,expense,tada,attendance), field_tracking, category]
     * elig values: true (yes), false (no), null (admin decides).
     */
    public static function types()
    {
        return array(
            'fixed_salary' => array(
                'label' => 'Fixed Salary Employee',
                'elig'  => array('salary'=>true, 'commission'=>false, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'employee',
            ),
            'commission_only' => array(
                'label' => 'Commission-Only Employee',
                'elig'  => array('salary'=>false, 'commission'=>true, 'expense'=>true, 'tada'=>true, 'attendance'=>false),
                'field_tracking' => false, 'category' => 'employee',
            ),
            'salary_commission' => array(
                'label' => 'Salary + Commission Employee',
                'elig'  => array('salary'=>true, 'commission'=>true, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'employee',
            ),
            'field_sales' => array(
                'label' => 'Field Sales Employee',
                'elig'  => array('salary'=>true, 'commission'=>true, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => true, 'category' => 'field',
            ),
            'telecaller' => array(
                'label' => 'Telecaller',
                'elig'  => array('salary'=>true, 'commission'=>true, 'expense'=>false, 'tada'=>false, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'employee',
            ),
            'freelancer' => array(
                'label' => 'Freelancer/Consultant',
                'elig'  => array('salary'=>false, 'commission'=>true, 'expense'=>true, 'tada'=>false, 'attendance'=>false),
                'field_tracking' => false, 'category' => 'external',
            ),
            'intern' => array(
                'label' => 'Intern/Trainee',
                'elig'  => array('salary'=>true, 'commission'=>false, 'expense'=>false, 'tada'=>false, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'employee',
            ),
            'manager' => array(
                'label' => 'Manager',
                'elig'  => array('salary'=>true, 'commission'=>true, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'management',
            ),
            'finance_staff' => array(
                'label' => 'Finance Staff',
                'elig'  => array('salary'=>true, 'commission'=>false, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'finance',
            ),
            'auditor' => array(
                'label' => 'Auditor',
                'elig'  => array('salary'=>true, 'commission'=>false, 'expense'=>true, 'tada'=>true, 'attendance'=>true),
                'field_tracking' => false, 'category' => 'control',
            ),
            'channel_partner' => array(
                'label' => 'Channel Partner/Agent',
                'elig'  => array('salary'=>false, 'commission'=>true, 'expense'=>false, 'tada'=>false, 'attendance'=>false),
                'field_tracking' => false, 'category' => 'external',
            ),
            'custom' => array(
                'label' => 'Custom Type',
                'elig'  => array('salary'=>null, 'commission'=>null, 'expense'=>null, 'tada'=>null, 'attendance'=>null),
                'field_tracking' => false, 'category' => 'custom',
            ),
        );
    }

    public static function slugs()
    {
        return array_keys(self::types());
    }

    /** Normalise input to a known slug; anything unknown becomes 'custom'. */
    public static function normalize($slug)
    {
        $slug = strtolower(trim((string) $slug));
        $slug = str_replace(array(' ', '-'), '_', $slug);
        $t = self::types();
        return isset($t[$slug]) ? $slug : 'custom';
    }

    public static function isValid($slug)
    {
        $slug = strtolower(trim((string) $slug));
        $slug = str_replace(array(' ', '-'), '_', $slug);
        return isset(self::types()[$slug]);
    }

    public static function label($slug)
    {
        $t = self::types();
        $slug = self::normalize($slug);
        return isset($t[$slug]) ? $t[$slug]['label'] : ucfirst($slug);
    }

    /** Copy of the default eligibility map for a type. */
    public static function defaultEligibility($slug)
    {
        $t = self::types();
        $slug = self::normalize($slug);
        return $t[$slug]['elig'];
    }

    public static function requiresFieldTracking($slug)
    {
        $t = self::types();
        $slug = self::normalize($slug);
        return (bool) $t[$slug]['field_tracking'];
    }

    public static function category($slug)
    {
        $t = self::types();
        $slug = self::normalize($slug);
        return $t[$slug]['category'];
    }

    /** Options for a <select> (slug => label), custom last. */
    public static function options()
    {
        $out = array();
        foreach (self::types() as $slug => $def) { $out[$slug] = $def['label']; }
        return $out;
    }
}
