<?php
defined('BASEPATH') or defined('WF_TEST') or exit('No direct script access allowed');

/**
 * Every decision this module makes, as pure functions.
 *
 * Four defects found in Phase 1 all lived in the same place — a controller that
 * decided things inline and a model that trusted whatever it was handed:
 *
 *  1. AN INVALID VALUE WAS SILENTLY TURNED INTO NULL.
 *     The controller did:
 *         if (!in_array($post['employment_type'], $allowed)) { $post['employment_type'] = null; }
 *     then saved, then reported success. Posting an unrecognised value ERASED
 *     an existing classification and told the user it had worked. Silent data
 *     loss dressed as a save.
 *
 *  2. ANY staff_id WAS ACCEPTED, REAL OR NOT.
 *     /staff_classification/edit/99999 rendered a complete working form with a
 *     blank name, and the model's save() had no existence check — it inserted a
 *     row for whatever id it was given. Perfex core gets this right
 *     (/admin/staff/member/9999 redirects to not_found); this module did not.
 *
 *  3. INACTIVE STAFF WERE HIDDEN FROM THE LIST BUT EDITABLE BY URL.
 *     get_all() filtered active = 1. edit() filtered nothing.
 *
 *  4. DEPARTMENT, BRANCH, TERRITORY AND SHIFT WERE FREE TEXT.
 *     This install has exactly one real department, and the two modules that
 *     store a department for staffid 1 disagree with it and with each other:
 *     "Engineering" here, "Sales" in the Payplex profile, "General Support" in
 *     the only table with a foreign key. Free text cannot be reported on and
 *     cannot be reconciled.
 *
 * The rules are here, framework-free, so they can be tested directly and so the
 * controller cannot quietly grow a second opinion.
 *
 * EMPLOYMENT TYPE AND WORK CATEGORY ARE SEPARATE, deliberately. A freelancer
 * can work remote, hybrid or in the field; a full-time employee can be field
 * sales. Collapsing them — as the previous list did, offering "Field" as a work
 * mode and "Commission-Based" as an employment type — is what makes a workforce
 * unreportable.
 */
class Workforce_classification
{
    /** Maximum stored length of each free-text column, from the live schema. */
    const MAX_TEXT = 100;

    /**
     * Employment type: how the person is engaged.
     * Legacy values are accepted on read so existing rows keep working.
     */
    public static function employmentTypes()
    {
        return array(
            'full_time'        => 'Full-Time',
            'part_time'        => 'Part-Time',
            'freelancer'       => 'Freelancer',
            'consultant'       => 'Consultant',
            'commission_based' => 'Commission-Based',
        );
    }

    /** Work category: where and how the work happens. */
    public static function workCategories()
    {
        return array(
            'office'      => 'Office',
            'remote'      => 'Remote',
            'hybrid'      => 'Hybrid',
            'field_sales' => 'Field Sales',
        );
    }

    /**
     * The old work_mode vocabulary, mapped forward. 'field' became a work
     * category; it was never an employment type, though the old UI implied it.
     */
    public static function legacyWorkMode($value)
    {
        $map = array('in_house' => 'office', 'field' => 'field_sales');
        $v = strtolower(trim((string) $value));
        return isset($map[$v]) ? $map[$v] : '';
    }

    public static function label($set, $key, $fallback = 'Not set')
    {
        $k = (string) $key;
        return isset($set[$k]) ? $set[$k] : ($k === '' ? $fallback : $k . ' (unrecognised)');
    }

    /* ==================================================================== *
     * Is this staff id something we may edit at all?
     * ==================================================================== */

    /**
     * @param mixed      $staffId  raw URL segment
     * @param array|null $row      the staff row, or null if not found
     * @return array ok, code, message
     */
    public static function staffGate($staffId, $row)
    {
        $id = trim((string) $staffId);
        if ($id === '' || !ctype_digit($id) || (int) $id <= 0) {
            return array('ok' => false, 'code' => 'missing_id',
                         'message' => 'No staff member was specified.');
        }
        if (!is_array($row) || empty($row)) {
            return array('ok' => false, 'code' => 'not_found',
                         'message' => 'Staff member #' . (int) $id . ' does not exist. '
                                    . 'Nothing was opened and nothing was saved.');
        }
        if (isset($row['active']) && (int) $row['active'] === 0) {
            return array('ok' => true, 'code' => 'inactive',
                         'message' => 'This staff member is deactivated. Their classification can be '
                                    . 'corrected, but they cannot sign in and will not appear in the '
                                    . 'default list.');
        }
        return array('ok' => true, 'code' => 'ok', 'message' => '');
    }

    /* ==================================================================== *
     * Validation
     * ==================================================================== */

    /**
     * Validate a submitted classification.
     *
     * Nothing is silently corrected. A value this function does not recognise
     * is an ERROR, returned against its field, and the caller must not save.
     * Clearing a field is still possible — by submitting it empty, explicitly.
     *
     * @param array $post
     * @param array $ctx  staff_id, department_ids[], manager_ids[]
     * @return array ok, values, errors
     */
    public static function validate(array $post, array $ctx)
    {
        $errors = array();
        $values = array();
        $get    = function ($k) use ($post) { return isset($post[$k]) ? trim((string) $post[$k]) : ''; };

        /* --- employment type --- */
        $et = $get('employment_type');
        if ($et === '') {
            $values['employment_type'] = null;
        } elseif (array_key_exists($et, self::employmentTypes())) {
            $values['employment_type'] = $et;
        } else {
            $errors['employment_type'] = 'Unrecognised employment type. Choose one of: '
                . implode(', ', array_keys(self::employmentTypes())) . ', or leave it blank.';
        }

        /* --- work category --- */
        $wc = $get('work_category');
        if ($wc === '') {
            $values['work_category'] = null;
        } elseif (array_key_exists($wc, self::workCategories())) {
            $values['work_category'] = $wc;
        } else {
            $errors['work_category'] = 'Unrecognised work category. Choose one of: '
                . implode(', ', array_keys(self::workCategories())) . ', or leave it blank.';
        }

        /* --- department: a real id, not a typed string --- */
        $dep = $get('department_id');
        if ($dep === '') {
            $values['department_id'] = null;
        } elseif (!ctype_digit($dep)) {
            $errors['department_id'] = 'Department must be chosen from the list.';
        } elseif (!in_array((int) $dep, array_map('intval', (array) ($ctx['department_ids'] ?? array())), true)) {
            $errors['department_id'] = 'That department does not exist.';
        } else {
            $values['department_id'] = (int) $dep;
        }

        /* --- reporting manager --- */
        $mgr  = $get('manager_id');
        $self = (int) ($ctx['staff_id'] ?? 0);
        if ($mgr === '' || $mgr === '0') {
            $values['manager_id'] = null;
        } elseif (!ctype_digit($mgr)) {
            $errors['manager_id'] = 'Reporting manager must be chosen from the list.';
        } elseif ((int) $mgr === $self) {
            $errors['manager_id'] = 'A staff member cannot report to themselves.';
        } elseif (!in_array((int) $mgr, array_map('intval', (array) ($ctx['manager_ids'] ?? array())), true)) {
            $errors['manager_id'] = 'That manager is not an active staff member.';
        } else {
            $values['manager_id'] = (int) $mgr;
        }

        /* --- free text that stays free text, but bounded and never truncated silently --- */
        foreach (array('branch', 'territory', 'shift') as $f) {
            $v = $get($f);
            if ($v === '') { $values[$f] = null; continue; }
            if (mb_strlen($v) > self::MAX_TEXT) {
                $errors[$f] = ucfirst($f) . ' is longer than ' . self::MAX_TEXT . ' characters. '
                            . 'It was not saved, rather than being cut short without telling you.';
                continue;
            }
            $values[$f] = $v;
        }

        return array('ok' => empty($errors), 'values' => $values, 'errors' => $errors);
    }

    /**
     * Would this manager assignment create a loop?
     *
     * A reports to B, B reports to A is not a hierarchy. The check walks the
     * existing chain rather than looking one step up, because a three-person
     * cycle is just as broken as a two-person one and is harder to spot.
     *
     * @param int   $staffId  the person being edited
     * @param int   $managerId proposed manager
     * @param array $chain    manager_id keyed by staff_id, as currently stored
     */
    public static function createsCycle($staffId, $managerId, array $chain)
    {
        $staffId   = (int) $staffId;
        $managerId = (int) $managerId;
        if ($managerId <= 0) { return false; }
        if ($managerId === $staffId) { return true; }

        $seen = array();
        $at   = $managerId;
        while ($at > 0) {
            if ($at === $staffId) { return true; }
            if (isset($seen[$at])) { return true; } // a pre-existing loop; refuse to extend it
            $seen[$at] = true;
            $at = isset($chain[$at]) ? (int) $chain[$at] : 0;
        }
        return false;
    }

    /* ==================================================================== *
     * Audit
     * ==================================================================== */

    /**
     * Field-level difference, for the audit trail.
     *
     * The old module logged "Staff Classification Updated [StaffID: n]" — that
     * something changed, never what. A classification decides commission
     * eligibility and reporting lines; "something changed" is not an audit.
     */
    public static function diff($before, $after)
    {
        $fields = array('employment_type', 'work_category', 'department_id', 'manager_id',
                        'branch', 'territory', 'shift');
        $out = array();
        foreach ($fields as $f) {
            $b = isset($before[$f]) ? $before[$f] : null;
            $a = isset($after[$f])  ? $after[$f]  : null;
            $bs = ($b === null || $b === '') ? '' : (string) $b;
            $as = ($a === null || $a === '') ? '' : (string) $a;
            if ($bs !== $as) { $out[$f] = array('from' => $bs, 'to' => $as); }
        }
        return $out;
    }
}
