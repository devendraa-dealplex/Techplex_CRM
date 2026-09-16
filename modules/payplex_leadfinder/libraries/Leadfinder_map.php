<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Leadfinder_map
 *
 * Everything the map view decides, kept out of the view.
 *
 * Marker states, clustering, viewport and radius validation, the rules about
 * where the browser map key may be rendered, and the error states for when it
 * is missing or restricted. Pure: no I/O, no CI, no key values.
 *
 * TWO KEYS, AND WHY
 *
 * Google will not let one credential do both jobs safely. The Places calls run
 * from PHP and want an IP restriction; the map loader runs in the visitor's
 * browser and wants an HTTP-referrer restriction. A key restricted for one is
 * wrong for the other, and a key restricted for neither is a key anyone can
 * lift and spend.
 *
 * So there are two slots:
 *   - the SERVER PLACES key, encrypted at rest, never rendered into HTML;
 *   - the BROWSER MAP key, which the Maps JavaScript loader requires in the
 *     page by design.
 *
 * That second point is worth stating plainly rather than pretending otherwise:
 * the browser key IS in the page source. There is no loader that hides it. The
 * protections are that it is a different credential from the server key and
 * from the Perfex core key, that it is referrer-restricted to this host, that
 * it is limited to the Maps JavaScript API, that it carries its own budget, and
 * that it is emitted only on the pages that actually draw a map.
 */
class Leadfinder_map
{
    /* ---- marker states -------------------------------------------------- */

    const M_NEW       = 'new';
    const M_SELECTED  = 'selected';
    const M_CLAIMED   = 'claimed';
    const M_POSSIBLE  = 'possible_duplicate';
    const M_SAVED     = 'saved';
    const M_WASTE     = 'waste';

    /**
     * Marker states, with a colour and a shape.
     *
     * Colour alone is not enough. Roughly one man in twelve has some red-green
     * colour deficiency, and "new" against "waste" as green against red is
     * exactly the pair they cannot separate — so every state also carries a
     * distinct glyph and a label, and the legend shows all three.
     *
     * @return array
     */
    public static function markerStates()
    {
        return array(
            self::M_NEW => array(
                'label' => 'New',
                'color' => '#2563eb',   // blue
                'glyph' => 'circle',
                'z'     => 10,
            ),
            self::M_SELECTED => array(
                'label' => 'Selected',
                'color' => '#0f172a',   // near-black, always on top
                'glyph' => 'ring',
                'z'     => 60,
            ),
            self::M_CLAIMED => array(
                'label' => 'Claimed',
                'color' => '#7c3aed',   // violet
                'glyph' => 'person',
                'z'     => 30,
            ),
            self::M_POSSIBLE => array(
                'label' => 'Possible duplicate',
                'color' => '#d97706',   // amber
                'glyph' => 'warning',
                'z'     => 40,
            ),
            self::M_SAVED => array(
                'label' => 'Saved to queue',
                'color' => '#059669',   // green
                'glyph' => 'check',
                'z'     => 50,
            ),
            self::M_WASTE => array(
                'label' => 'Waste / rejected',
                'color' => '#6b7280',   // grey, deliberately recessive
                'glyph' => 'cross',
                'z'     => 5,
            ),
        );
    }

    /**
     * @return array
     */
    public static function markerStateKeys()
    {
        return array_keys(self::markerStates());
    }

    /**
     * The marker state for a prospect row.
     *
     * Order matters: selection is a view state and wins over everything, then
     * the terminal states, then the working states.
     *
     * @param  array $row
     * @param  bool  $isSelected
     * @return string
     */
    public static function stateFor(array $row, $isSelected = false)
    {
        if ($isSelected) {
            return self::M_SELECTED;
        }

        $status = isset($row['status']) ? (string) $row['status'] : '';

        $waste = array('rejected', 'duplicate', 'irrelevant', 'business_closed',
                       'wrong_number', 'do_not_contact', 'valid_not_interested');

        if (in_array($status, $waste, true)) {
            return self::M_WASTE;
        }

        if (!empty($row['dupe_possible'])) {
            return self::M_POSSIBLE;
        }

        if (!empty($row['saved_to_queue'])) {
            return self::M_SAVED;
        }

        if (!empty($row['claimed_by'])) {
            return self::M_CLAIMED;
        }

        return self::M_NEW;
    }

    /* ---- clustering ------------------------------------------------------ */

    /**
     * Clustering thresholds.
     *
     * Below the minimum there is nothing to cluster and clustering only hides
     * individual pins; above the zoom cut-off the user has asked to see detail
     * and merging pins fights them.
     *
     * @return array
     */
    public static function clustering()
    {
        return array(
            'enabled'          => true,
            'min_markers'      => 8,
            'max_zoom'         => 15,   // above this, always show individual pins
            'grid_size'        => 56,
            'cluster_selected' => false, // a selected marker is never absorbed
        );
    }

    /**
     * @param  int $markerCount
     * @param  int $zoom
     * @return bool
     */
    public static function shouldCluster($markerCount, $zoom)
    {
        $c = self::clustering();

        if (!$c['enabled']) {
            return false;
        }

        if ((int) $markerCount < $c['min_markers']) {
            return false;
        }

        return (int) $zoom <= $c['max_zoom'];
    }

    /* ---- viewport and radius --------------------------------------------- */

    /** Google's Nearby Search caps radius at 50 km. */
    const RADIUS_MAX_M = 50000;
    const RADIUS_MIN_M = 100;

    /** A "search this area" viewport wider than this is not a search, it is a crawl. */
    const AREA_MAX_KM = 200;

    /**
     * Validate a radius in metres.
     *
     * @param  mixed $metres
     * @return array {ok, value, error}
     */
    public static function validateRadius($metres)
    {
        if ($metres === null || $metres === '' || !is_numeric($metres)) {
            return array('ok' => false, 'value' => null, 'error' => 'A radius is required.');
        }

        $m = (int) $metres;

        if ($m < self::RADIUS_MIN_M) {
            return array('ok' => false, 'value' => null,
                         'error' => 'The smallest radius is ' . self::RADIUS_MIN_M . ' m.');
        }

        if ($m > self::RADIUS_MAX_M) {
            return array('ok' => false, 'value' => null,
                         'error' => 'Google caps radius search at ' . (self::RADIUS_MAX_M / 1000) . ' km.');
        }

        return array('ok' => true, 'value' => $m, 'error' => null);
    }

    /**
     * Validate a latitude/longitude pair.
     *
     * @param  mixed $lat
     * @param  mixed $lng
     * @return bool
     */
    public static function validLatLng($lat, $lng)
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Validate a "search this area" viewport.
     *
     * Rejects an inverted or absurd box, and refuses a viewport so large that
     * the search stops being a search. The 180th-meridian case is rejected
     * rather than silently mis-handled: a box that wraps would otherwise be
     * read as a box spanning almost the whole globe.
     *
     * @param  array $b  {north, south, east, west}
     * @return array {ok, error, span_km}
     */
    public static function validateBounds($b)
    {
        foreach (array('north', 'south', 'east', 'west') as $k) {
            if (!isset($b[$k]) || !is_numeric($b[$k])) {
                return self::boundsErr('The map area is incomplete.');
            }
        }

        $n = (float) $b['north']; $s = (float) $b['south'];
        $e = (float) $b['east'];  $w = (float) $b['west'];

        if ($n < -90 || $n > 90 || $s < -90 || $s > 90) {
            return self::boundsErr('The map area is outside the world.');
        }

        if ($e < -180 || $e > 180 || $w < -180 || $w > 180) {
            return self::boundsErr('The map area is outside the world.');
        }

        if ($s >= $n) {
            return self::boundsErr('The map area is inverted.');
        }

        if ($w >= $e) {
            // A viewport crossing the antimeridian arrives this way. We do not
            // pretend to handle it; we say so.
            return self::boundsErr('The map area crosses the date line, which this search cannot handle. '
                                 . 'Pan slightly and try again.');
        }

        $span = self::spanKm($n, $s, $e, $w);

        if ($span > self::AREA_MAX_KM) {
            return array('ok' => false, 'span_km' => $span,
                         'error' => 'Zoom in — this area is about ' . round($span)
                                  . ' km across and the limit is ' . self::AREA_MAX_KM . ' km.');
        }

        return array('ok' => true, 'error' => null, 'span_km' => $span);
    }

    /**
     * Rough diagonal span of a viewport in kilometres. Equirectangular, which is
     * accurate enough to enforce a sanity limit and is not used for distance
     * shown to a user.
     *
     * @return float
     */
    public static function spanKm($n, $s, $e, $w)
    {
        $latKm = ($n - $s) * 111.32;
        $midLat = deg2rad(($n + $s) / 2);
        $lngKm = ($e - $w) * 111.32 * cos($midLat);

        return sqrt(($latKm * $latKm) + ($lngKm * $lngKm));
    }

    /**
     * The centre and radius that a viewport implies, for a radius search.
     *
     * @param  array $b
     * @return array|null
     */
    public static function boundsToCircle($b)
    {
        $v = self::validateBounds($b);

        if (!$v['ok']) {
            return null;
        }

        $lat = ((float) $b['north'] + (float) $b['south']) / 2;
        $lng = ((float) $b['east'] + (float) $b['west']) / 2;
        $r   = (int) round(($v['span_km'] * 1000) / 2);

        if ($r < self::RADIUS_MIN_M) { $r = self::RADIUS_MIN_M; }
        if ($r > self::RADIUS_MAX_M) { $r = self::RADIUS_MAX_M; }

        return array('lat' => $lat, 'lng' => $lng, 'radius_m' => $r);
    }

    /* ---- where the browser key may appear -------------------------------- */

    /**
     * The only routes that may carry the browser map key.
     *
     * Admin-relative and matched EXACTLY. Everything else — the
     * customer portal, the public pages, unrelated admin screens, and every
     * Lead Finder screen that does not draw a map — gets nothing.
     *
     * @return array
     */
    public static function keyedRoutes()
    {
        return array(
            'payplex_leadfinder/finder',
            'payplex_leadfinder/finder/index',
            'payplex_leadfinder/finder/queue',
        );
    }

    /**
     * May this request render the browser map key?
     *
     * Four conditions, all required: a staff session, the admin area, a route
     * that draws a map, and a configured key. Anything missing means no key in
     * the page — a map that cannot draw is a visible, explainable failure; a
     * key on a page that does not need it is an invisible one.
     *
     * @param  array $ctx {staff_logged_in, in_admin_area, admin_relative_uri, key_configured}
     * @return array {allowed: bool, reason: string}
     */
    public static function mayRenderBrowserKey(array $ctx)
    {
        if (empty($ctx['staff_logged_in'])) {
            return array('allowed' => false, 'reason' => 'not_staff');
        }

        if (empty($ctx['in_admin_area'])) {
            return array('allowed' => false, 'reason' => 'not_admin_area');
        }

        $uri = isset($ctx['admin_relative_uri']) ? trim((string) $ctx['admin_relative_uri'], '/') : '';

        /*
         * EXACT match, not a prefix.
         *
         * The first version matched prefixes, which handed the browser key to
         * every route below the search screen — reports, approvals and the
         * API-profile screen, none of which draw a map. A route that wants the
         * key says so by name.
         */
        $hit = in_array($uri, self::keyedRoutes(), true);

        if (!$hit) {
            return array('allowed' => false, 'reason' => 'route_draws_no_map');
        }

        if (empty($ctx['key_configured'])) {
            return array('allowed' => false, 'reason' => 'no_browser_key_configured');
        }

        return array('allowed' => true, 'reason' => 'map_route_with_key');
    }

    /* ---- error states ----------------------------------------------------- */

    /**
     * What the map area shows when it cannot draw, in words that say what to do.
     *
     * Each carries whether the rest of the screen still works, because a missing
     * map must not take the results table down with it.
     *
     * @return array
     */
    public static function errorStates()
    {
        return array(
            'no_key' => array(
                'title'        => 'Map unavailable',
                'body'         => 'No browser map key is configured for Lead Finder. '
                                . 'Results are listed below and every action still works; only the map is missing.',
                'action'       => 'An administrator can add one in Lead Finder → Connections.',
                'list_usable'  => true,
            ),
            'referrer_blocked' => array(
                'title'        => 'Map key rejected by Google',
                'body'         => 'Google refused the map key for this site. The usual cause is an HTTP-referrer '
                                . 'restriction that does not include this exact hostname.',
                'action'       => 'Check the key\'s website restrictions in Google Cloud Console.',
                'list_usable'  => true,
            ),
            'api_not_enabled' => array(
                'title'        => 'Maps JavaScript API not enabled',
                'body'         => 'The key is valid but the Maps JavaScript API is not enabled on its project.',
                'action'       => 'Enable Maps JavaScript API for that project.',
                'list_usable'  => true,
            ),
            'quota' => array(
                'title'        => 'Map quota reached',
                'body'         => 'The map key has hit its quota or billing limit for now.',
                'action'       => 'Check the budget on the map key\'s project. Searching still works.',
                'list_usable'  => true,
            ),
            'load_failed' => array(
                'title'        => 'Map failed to load',
                'body'         => 'The Google Maps script did not load. This is usually a network or content-blocker issue.',
                'action'       => 'Reload the page, or continue with the list view.',
                'list_usable'  => true,
            ),
        );
    }

    /**
     * @param  string $state
     * @return array|null
     */
    public static function errorState($state)
    {
        $s = self::errorStates();

        return isset($s[$state]) ? $s[$state] : null;
    }

    /* ---- attribution and layout ------------------------------------------- */

    /**
     * Google's terms require their attribution to remain visible on the map and
     * forbid drawing Places results on anybody else's map. The retention library
     * already declares that this module renders no non-Google map; this is the
     * other half of that promise.
     *
     * @return array
     */
    public static function attribution()
    {
        return array(
            'required'        => true,
            'text'            => 'Map data ©Google',
            'logo_required'   => true,
            'may_obscure'     => false,
            'places_on_other_maps' => false,
        );
    }

    /** View modes for the split. */
    const V_SPLIT     = 'split';
    const V_MAP_FULL  = 'map_full';
    const V_LIST_ONLY = 'list_only';

    /**
     * @return array
     */
    public static function viewModes()
    {
        return array(self::V_SPLIT, self::V_MAP_FULL, self::V_LIST_ONLY);
    }

    /**
     * On a narrow screen a split view gives two unusable halves, so the default
     * becomes list with a toggle to the map.
     *
     * @param  int    $viewportWidth
     * @param  string $requested
     * @return string
     */
    public static function defaultView($viewportWidth, $requested = null)
    {
        if ($requested !== null && in_array($requested, self::viewModes(), true)) {
            return $requested;
        }

        return ((int) $viewportWidth < 768) ? self::V_LIST_ONLY : self::V_SPLIT;
    }

    private static function boundsErr($msg)
    {
        return array('ok' => false, 'error' => $msg, 'span_km' => null);
    }
}
