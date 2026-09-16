<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Keyguard_consumers
 *
 * The registry of code that genuinely reads the Perfex core `google_api_key`.
 *
 * Every entry was found by scanning the codebase, not by recalling it. The
 * first version of this work named one consumer (the calendar) and was wrong by
 * four; the list below is what a full scan of the PHP and JS trees actually
 * turned up, and each entry carries the file that proves it.
 *
 * A request receives the key only if it matches an entry here AND that entry's
 * feature is switched on AND the staff member holds the capability the entry
 * names. No route outside this list receives it, whatever area it is in.
 *
 * This class is pure data and pure matching. It reads no options and no
 * session: the bootstrap resolves `feature` and `capability` into booleans and
 * passes them in, so the matching logic can be tested without a CRM.
 */
class Keyguard_consumers
{
    /** Match modes. */
    const M_EXACT      = 'exact';       // this route and nothing below it
    const M_PREFIX     = 'prefix';      // this route and everything below it
    const M_ADMIN_AREA = 'admin_area';  // the whole admin area (see the picker note)

    /**
     * @return array
     */
    public static function all()
    {
        return array(

            /*
             * Customer map tab. Clients::client($id) enqueues map.js and the
             * Google Maps script — with the key in its URL — when the profile
             * is opened on its `map` group.
             *
             * The route is `clients/client/<id>`, NOT `clients`. The first
             * version of this registry had it exactly backwards: it allowed the
             * customers LIST, which has no map and therefore no consumer, and
             * denied the PROFILE, which is where the map actually lives. Live
             * checking caught it — `/admin/clients/client/65?group=map` carried
             * a map tab link and no Google Maps script at all. The cause was
             * reading line 188 and assuming the enclosing function instead of
             * reading it; the enclosing function is `client($id = '')`, at line
             * 74. Prefix match, because the id and the group follow.
             */
            array(
                'id'         => 'customers_map',
                'why'        => 'Google Maps script tag in Clients::client(), application/controllers/admin/Clients.php:188-195',
                'routes'     => array('clients/client'),
                'match'      => self::M_PREFIX,
                'feature'    => null,
                'capability' => array('feature' => 'customers', 'capability' => 'view'),
                'emits_html' => true,
            ),

            /*
             * Server-side geocoding. Misc.php reads the option and calls Google
             * from PHP, then returns JSON. The key is used, never printed — the
             * controller answers MISSING_API_KEY if it is blank, which is how a
             * careless narrowing would have broken it silently.
             */
            array(
                'id'         => 'geocoding',
                'why'        => 'server-side lookup, application/controllers/admin/Misc.php:29',
                'routes'     => array('misc'),
                'match'      => self::M_PREFIX,
                'feature'    => null,
                'capability' => null,
                'emits_html' => false,
            ),

            /*
             * The settings screen that edits this very option.
             *
             * This entry is not a convenience. If the key were withheld here,
             * the field would render empty and an administrator pressing Save
             * would write the empty string over the stored credential. A read
             * filter can destroy a secret by this exact route.
             */
            array(
                'id'         => 'google_settings_field',
                'why'        => 'Setup > Settings > Google renders the option into the field that edits it',
                'routes'     => array('settings'),
                'match'      => self::M_PREFIX,
                'feature'    => null,
                'capability' => array('feature' => 'settings', 'capability' => 'view'),
                'emits_html' => true,
            ),

            /*
             * Google Calendar event source. Gated on calendar ids being
             * configured, because main.js only reaches for the key when
             * app.calendarIDs is non-empty. No ids configured on this install,
             * so this consumer is currently closed and its routes get nothing.
             */
            array(
                'id'         => 'google_calendar',
                'why'        => 'Google Calendar event source, assets/js/main.js:1465-1482',
                'routes'     => array('utilities/calendar', 'dashboard', 'calendar'),
                'match'      => self::M_EXACT,
                'feature'    => 'google_calendar_ids',
                'capability' => null,
                'emits_html' => true,
            ),

            /*
             * Google Picker.
             *
             * The picker attaches itself to upload widgets, and those appear on
             * a great many admin screens — tickets, projects, tasks, leads,
             * customers, estimates, proposals, contracts, and anything a module
             * adds later. Enumerating them would be a list that is wrong the
             * day a module ships a new upload field, and being wrong here means
             * an upload button that silently stops working.
             *
             * So while the picker is ON, this entry deliberately matches the
             * admin area as a whole, and the narrowing does not apply. While it
             * is OFF, this entry never matches and the three consumers above
             * are the only ones left.
             *
             * The honest reading: turning the picker off is what makes the
             * narrowing meaningful. That is a configuration decision, and the
             * module reports its effect rather than pretending the route list
             * does the work.
             */
            array(
                'id'         => 'google_picker',
                'why'        => 'Picker developerKey, assets/js/main.js:20-22 and the client-portal clients.js:27-29',
                'routes'     => array(),
                'match'      => self::M_ADMIN_AREA,
                'feature'    => 'enable_google_picker',
                'capability' => null,
                'emits_html' => true,
            ),
        );
    }

    /**
     * Entry ids, in registry order.
     *
     * @return array
     */
    public static function ids()
    {
        $ids = array();
        foreach (self::all() as $c) {
            $ids[] = $c['id'];
        }

        return $ids;
    }

    /**
     * One entry by id, or null.
     *
     * @param  string $id
     * @return array|null
     */
    public static function byId($id)
    {
        foreach (self::all() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Every option name the registry gates on. The bootstrap resolves exactly
     * these and no others, so adding a consumer cannot quietly widen what the
     * module reads.
     *
     * @return array
     */
    public static function featureOptions()
    {
        $out = array();
        foreach (self::all() as $c) {
            if (!empty($c['feature'])) {
                $out[$c['feature']] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Every capability the registry checks, as "feature.capability" keys.
     *
     * @return array
     */
    public static function capabilityKeys()
    {
        $out = array();
        foreach (self::all() as $c) {
            if (!empty($c['capability'])) {
                $out[self::capabilityKey($c['capability'])] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param  array $cap
     * @return string
     */
    public static function capabilityKey(array $cap)
    {
        return $cap['feature'] . '.' . $cap['capability'];
    }

    /**
     * Does this consumer's route list cover this URI?
     *
     * Routes are stored RELATIVE TO THE ADMIN PREFIX — 'clients', not
     * 'admin/clients' — because a Perfex install can rename its admin folder
     * and a literal 'admin/' would stop matching the day someone did.
     *
     * @param  array  $consumer
     * @param  string $uri          admin-relative, normalised, no leading slash
     * @param  bool   $inAdminArea
     * @return bool
     */
    public static function matchesRoute(array $consumer, $uri, $inAdminArea)
    {
        $mode = isset($consumer['match']) ? $consumer['match'] : self::M_EXACT;

        if ($mode === self::M_ADMIN_AREA) {
            return (bool) $inAdminArea;
        }

        $routes = isset($consumer['routes']) ? $consumer['routes'] : array();

        foreach ($routes as $route) {
            $route = trim((string) $route, '/');

            if ($route === '') {
                continue;
            }

            if ($uri === $route) {
                return true;
            }

            if ($mode === self::M_PREFIX && strpos($uri, $route . '/') === 0) {
                return true;
            }
        }

        return false;
    }
}
