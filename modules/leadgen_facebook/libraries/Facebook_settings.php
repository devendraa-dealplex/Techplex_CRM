<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Which options this module's settings form is allowed to write, and how a
 * secret is described without being shown.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * The settings controller ran this:
 *
 *     foreach ($this->input->post('settings') as $key => $value) {
 *         update_option($key, $value);
 *     }
 *
 * The *names* came from the request. Anyone who could reach that form could
 * post `settings[smtp_password]`, `settings[default_timezone]` or
 * `settings[allow_registration]` and Perfex would store it, because
 * update_option() writes whatever name it is handed. The form showing five
 * fields is a property of the HTML, not of the endpoint.
 *
 * So the allowlist lives here, as a literal map, and the controller writes only
 * names that appear in it. A new setting is a visible edit to this file.
 *
 * THE SECOND DEFECT THIS FILE FIXES
 * ---------------------------------
 * The form rendered the stored app secret and page access token into the HTML:
 *
 *     render_input('settings[facebook_app_secret]', ..., get_option(...), 'password')
 *
 * `type="password"` hides characters from the screen. It does not hide them
 * from View Source, from a page cache, from a screenshot tool that reads the
 * DOM, or from anything that saves the page. The secret was in the markup in
 * plaintext on every visit to the settings page.
 *
 * Secrets are therefore never rendered back. The field posts empty, an empty
 * post means "keep what is stored", and the page shows describe() — set or not,
 * how long, and a short fingerprint — which is enough to tell two values apart
 * and to confirm a paste landed, and is not enough to use.
 */
class Facebook_settings
{
    /** Marker for a value that is stored but must never be rendered. */
    const SECRET = 'secret';
    /** Marker for a value that is safe to show in full. */
    const PLAIN = 'plain';

    /**
     * The complete set of options this module's form may write.
     *
     * Anything not in this map is rejected and recorded. The value says whether
     * the option holds a credential, which decides both whether it is rendered
     * back and whether a blank submission means "clear it" or "leave it".
     */
    const ALLOWED = array(
        'facebook_page_id'                  => self::PLAIN,
        'facebook_page_access_token'        => self::SECRET,
        'facebook_app_secret'               => self::SECRET,
        'facebook_verify_token'             => self::SECRET,
        'facebook_default_lead_status'      => self::PLAIN,
        'facebook_lead_status_name'         => self::PLAIN,
        'facebook_lead_source_name'         => self::PLAIN,
        'facebook_assignment_mode'          => self::PLAIN,
        'facebook_default_assignee'         => self::PLAIN,
        'facebook_round_robin_pool'         => self::PLAIN,
        'facebook_messenger_enabled'        => self::PLAIN,
        'facebook_rate_limit_per_minute'    => self::PLAIN,
        'facebook_health_window_hours'      => self::PLAIN,
        'facebook_business_unit'            => self::PLAIN,
        'facebook_page_name'                => self::PLAIN,
        'facebook_tagging_enabled'          => self::PLAIN,
        'facebook_shared_numbers'           => self::PLAIN,
        'facebook_shared_number_threshold'  => self::PLAIN,
        'facebook_duplicate_detection'      => self::PLAIN,
    );

    /*
     * NOT in the allowlist, deliberately, and worth saying why here rather
     * than leaving its absence to be rediscovered:
     *
     *   facebook_log_retention_days  was drafted and removed. Nothing read it,
     *     and a setting the form can write that no code consults is a control
     *     that appears to work and does nothing — the same defect shape as a
     *     menu item wider than its route. Pruning the delivery log means
     *     scheduled deletion of audit rows, which is a separate change needing
     *     its own approval, not something to slip in under a hotfix. The table
     *     grows by one row per Meta delivery.
     *   facebook_schema_version      migration bookkeeping; form-writable it
     *     would let a caller replay or skip migrations.
     *   facebook_round_robin_last    the rotation cursor; form-writable it
     *     would let a caller steer every inbound lead to one person.
     *   facebook_ip_salt             the rate limiter's salt. Form-writable, a
     *     caller could reset it and thereby reset every source's request
     *     count, which is the whole limiter defeated in one POST.
     */

    /**
     * The verify token is a shared secret even though Meta's UI shows it as a
     * plain field, so it is marked SECRET above. Listed here as well because
     * "which of these is a credential" is the question a reviewer asks, and the
     * answer should be findable in one place rather than derived from a map.
     */
    const SECRET_NAMES = array(
        'facebook_page_access_token',
        'facebook_app_secret',
        'facebook_verify_token',
    );

    public static function isAllowed($name)
    {
        return array_key_exists((string) $name, self::ALLOWED);
    }

    public static function isSecret($name)
    {
        return isset(self::ALLOWED[(string) $name])
            && self::ALLOWED[(string) $name] === self::SECRET;
    }

    /**
     * Reduce a posted settings array to the writes that are actually permitted.
     *
     * Returns three lists rather than one, because "we ignored these" is
     * information the administrator should see and the activity log should
     * record. A silently dropped field looks exactly like a field that saved.
     *
     *   write    name => value, ready for update_option()
     *   rejected names that are not in the allowlist
     *   kept     secret names left alone because the field was submitted empty
     */
    public static function filter($posted)
    {
        $write = array();
        $rejected = array();
        $kept = array();

        if (!is_array($posted)) {
            return array('write' => $write, 'rejected' => $rejected, 'kept' => $kept);
        }

        foreach ($posted as $name => $value) {
            $name = (string) $name;

            if (!self::isAllowed($name)) {
                $rejected[] = $name;
                continue;
            }

            if (is_array($value)) {
                /* No allowlisted option is an array. Refuse rather than serialise. */
                $rejected[] = $name;
                continue;
            }

            $value = (string) $value;

            if (self::isSecret($name) && trim($value) === '') {
                /*
                 * An empty secret field is "I did not retype it", not "delete
                 * the credential". Clearing a secret is done deliberately
                 * through clearSecret(), not by saving a form.
                 */
                $kept[] = $name;
                continue;
            }

            $write[$name] = self::isSecret($name) ? trim($value) : $value;
        }

        return array('write' => $write, 'rejected' => $rejected, 'kept' => $kept);
    }

    /**
     * Describe a stored value without disclosing it.
     *
     * `fp` is the first eight hex characters of the SHA-256 of the value. It
     * tells you whether the string you just pasted is the one now stored, and
     * whether staging and production hold the same credential, without either
     * being printable. Eight characters is not a useful target for a preimage
     * search against a high-entropy Meta token, and the length is shown because
     * a truncated paste is the most common failure and is otherwise invisible.
     */
    public static function describe($value)
    {
        $value = $value === null ? '' : (string) $value;

        if (trim($value) === '') {
            return array('set' => false, 'length' => 0, 'fp' => '');
        }

        return array(
            'set'    => true,
            'length' => strlen($value),
            'fp'     => substr(hash('sha256', $value), 0, 8),
        );
    }

    /**
     * A one-line rendering of describe(), for the settings page and reports.
     * Never includes any part of the value itself.
     */
    public static function describeLine($value)
    {
        $d = self::describe($value);

        if (!$d['set']) {
            return 'not set';
        }

        return 'set · ' . $d['length'] . ' chars · fp ' . $d['fp'];
    }

    /**
     * The assignment modes the settings form may store. Anything else is
     * refused, so a typo in a posted value cannot leave the module in a state
     * no branch handles.
     */
    const ASSIGNMENT_MODES = array('unassigned', 'fixed', 'round_robin');

    public static function isValidAssignmentMode($mode)
    {
        return in_array((string) $mode, self::ASSIGNMENT_MODES, true);
    }

    /**
     * Parse a comma-separated staff-id pool into validated positive integers.
     *
     * Validated, not cast. `(int) '7abc'` is 7 in PHP, and this project has
     * already shipped one defect where a malformed id was cast and silently
     * granted access to the staff member it truncated to. A pool entry that is
     * not entirely digits is dropped and reported, not rounded into somebody.
     */
    public static function parsePool($raw)
    {
        $ids = array();
        $dropped = array();

        foreach (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            if (preg_match('/\A[1-9][0-9]*\z/', $part) === 1) {
                $id = (int) $part;
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            } else {
                $dropped[] = $part;
            }
        }

        sort($ids);

        return array('ids' => $ids, 'dropped' => $dropped);
    }
}
