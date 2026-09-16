<?php
defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Key Guard
Description: Delivers the Perfex core Google API key only to the named admin routes that genuinely consume it, gated on the feature being enabled and the staff capability being held. Public, customer-facing, Lead Finder and unrelated admin pages never receive it.
Version: 1.1.0
Requires at least: 2.3.0
*/

define('PAYPLEX_KEYGUARD_MODULE', 'payplex_keyguard');

/*
 * WHAT THIS MODULE DOES
 *
 * Perfex core reads the Google API key through App::get_option(), which ends in:
 *
 *     return hooks()->apply_filters('get_option', $val, $name);
 *
 * That filter is the whole mechanism. This module registers on it and returns an
 * empty string for `google_api_key` on any request that has no consumer for it.
 * The value never enters the HTTP response body, so there is nothing in the page
 * source to find — this is not a CSS or JavaScript concealment.
 *
 * v1.1.0 NARROWS v1.0.0. The first version allowed the admin area as a whole.
 * This version allows only the consumers registered in Keyguard_consumers, each
 * matched by route name, gated on the option that switches the feature on, and
 * gated again on the staff capability that screen requires. An admin route with
 * no consumer now gets exactly what a public route gets: nothing.
 *
 * NO CORE FILE IS MODIFIED. `admin_helper.php`, `themes_helper.php` and
 * `App.php` are all untouched, so a Perfex upgrade neither overwrites this fix
 * nor silently reopens the exposure. Deactivating the module restores the
 * previous behaviour exactly.
 *
 * THE STORED OPTION IS NOT CHANGED. This filter runs on read. `tbloptions` is
 * never written, and Setup > Settings > Google still shows and saves the real
 * value, because that screen is a registered consumer. That registration is not
 * a convenience: if the filter denied the settings screen, an administrator
 * opening the form and pressing Save would write the empty string back over the
 * key. A read filter can destroy a secret by exactly that route.
 *
 * LEAD FINDER IS NOT AFFECTED AND GAINS NO FALLBACK. Lead Finder reads its own
 * encrypted key from its own table and never calls get_option() for a key. Its
 * namespace is denied here before the consumer registry is consulted, so no
 * future registry entry can accidentally cover it.
 *
 * WHAT THE NARROWING DEPENDS ON
 *
 * With the Google Picker enabled, the picker consumer matches the admin area as
 * a whole — see the note in Keyguard_consumers for why enumerating upload
 * screens would be a list that breaks the day a module adds an upload field.
 * Switching the picker off is therefore what makes the narrowing real, and it
 * costs the Drive attachment button in both the admin and the client portal.
 * The module reports that dependency rather than hiding it.
 */

require_once __DIR__ . '/libraries/Keyguard_consumers.php';
require_once __DIR__ . '/libraries/Keyguard_policy.php';

hooks()->add_filter('get_option', 'payplex_keyguard_filter_option', 9999, 2);

/**
 * The filter itself. Deliberately tiny: it gathers context and delegates.
 *
 * Fails OPEN on the guarded-option test (any option that is not
 * `google_api_key` is returned untouched before anything else happens) and
 * fails CLOSED on context (a missing context key is falsy, and falsy means
 * "no consumer proven", which means deny).
 *
 * @param  mixed  $value
 * @param  string $name
 * @return mixed
 */
function payplex_keyguard_filter_option($value, $name = null)
{
    // Cheapest possible early exit. get_option() is called hundreds of times per
    // request for other options and none of them should pay for this.
    if ($name !== Keyguard_policy::GUARDED_OPTION) {
        return $value;
    }

    try {
        return Keyguard_policy::apply($value, payplex_keyguard_context($name, $value));
    } catch (Throwable $e) {
        // Deny, and say nothing about why. A blank key degrades a map; a leaked
        // key is a billable credential on a page. The exception is swallowed
        // rather than rethrown precisely so that no handler, debug bar or error
        // page further up can render a message that quotes the value.
        return '';
    }
}

/**
 * Assemble the request context for the policy.
 *
 * @param  string $name
 * @param  mixed  $value
 * @return array
 */
function payplex_keyguard_context($name, $value)
{
    $CI = &get_instance();

    $uri = '';
    if (isset($CI->uri) && is_object($CI->uri)) {
        $uri = (string) $CI->uri->uri_string();
    }

    return array(
        'option_name'      => $name,
        'value_is_empty'   => ($value === null || $value === ''),
        'is_cli'           => (function_exists('is_cli') && is_cli()),
        'is_cron_request'  => payplex_keyguard_is_cron($uri),
        'staff_logged_in'  => (function_exists('is_staff_logged_in') && is_staff_logged_in()),
        'client_logged_in' => (function_exists('is_client_logged_in') && is_client_logged_in()),
        'uri'              => $uri,
        'admin_prefix'     => payplex_keyguard_admin_prefix(),
        'features'         => payplex_keyguard_features(),
        'capabilities'     => payplex_keyguard_capabilities(),
    );
}

/**
 * Resolve the options the consumer registry gates on.
 *
 * Only the names the registry declares are read, so adding a consumer cannot
 * quietly widen what this module looks at. Each is read through get_option(),
 * which re-enters this filter — harmlessly, because the filter exits on the
 * first line for any name other than the guarded one.
 *
 * Memoised per request: these do not change mid-request, and get_option() is
 * called for the guarded key many times per page.
 *
 * @return array
 */
function payplex_keyguard_features()
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $resolved = array();

    foreach (Keyguard_consumers::featureOptions() as $option) {
        $v = function_exists('get_option') ? get_option($option) : '';

        // An option that is absent, empty, '0' or 'false' is off. Anything else
        // is on. Perfex stores checkboxes as '1'/'0' and id lists as strings,
        // and both have to read correctly here.
        $resolved[$option] = !($v === null || $v === '' || $v === '0' || $v === 0
            || $v === false || strtolower((string) $v) === 'false');
    }

    return $resolved;
}

/**
 * Resolve the capabilities the consumer registry checks.
 *
 * An administrator holds everything; otherwise the staff member must hold the
 * named capability on the named feature. Being inside the admin area is not the
 * same as being allowed to see the screen the key belongs to.
 *
 * @return array
 */
function payplex_keyguard_capabilities()
{
    static $resolved = null;

    if ($resolved !== null) {
        return $resolved;
    }

    $resolved = array();
    $isAdmin  = function_exists('is_admin') && is_admin();

    foreach (Keyguard_consumers::all() as $consumer) {
        if (empty($consumer['capability'])) {
            continue;
        }

        $cap = $consumer['capability'];
        $key = Keyguard_consumers::capabilityKey($cap);

        if (isset($resolved[$key])) {
            continue;
        }

        if ($isAdmin) {
            $resolved[$key] = true;
            continue;
        }

        $resolved[$key] = function_exists('has_permission')
            ? (bool) has_permission($cap['feature'], '', $cap['capability'])
            : false;   // no capability system reachable: fail closed
    }

    return $resolved;
}

/**
 * First segment of the admin area, derived at runtime.
 *
 * Hard-coding 'admin' would break on an install that renames it, and would fail
 * open — every admin page would be treated as customer-facing and the map view
 * would stop working. Derived from admin_url() so it follows the install.
 *
 * @return string
 */
function payplex_keyguard_admin_prefix()
{
    static $prefix = null;

    if ($prefix !== null) {
        return $prefix;
    }

    $prefix = 'admin';

    if (function_exists('admin_url') && function_exists('base_url')) {
        $admin = (string) admin_url();
        $base  = (string) base_url();

        if ($base !== '' && strpos($admin, $base) === 0) {
            $rest = trim(substr($admin, strlen($base)), '/');
            if ($rest !== '') {
                $parts  = explode('/', $rest);
                $prefix = $parts[0];
            }
        }
    }

    return $prefix;
}

/**
 * Is this the cron endpoint?
 *
 * Cron runs server-side work that may legitimately need the key and produces no
 * page for it to leak into.
 *
 * @param  string $uri
 * @return bool
 */
function payplex_keyguard_is_cron($uri)
{
    $uri = Keyguard_policy::normaliseUri($uri);

    if ($uri === 'cron' || strpos($uri, 'cron/') === 0) {
        return true;
    }

    $prefix = payplex_keyguard_admin_prefix();

    return $uri === $prefix . '/cron' || strpos($uri, $prefix . '/cron/') === 0;
}
