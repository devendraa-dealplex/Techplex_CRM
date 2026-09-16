<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_fieldmask.php';
require_once __DIR__ . '/Payplex_phone.php';

/**
 * Leadfinder_places — the Places API request and response, with no I/O.
 *
 * WHY THE HTTP IS SOMEWHERE ELSE
 * ------------------------------
 * Everything here is a pure function of its arguments: build a request body,
 * build headers, map a response to prospect rows. The socket work lives in the
 * model, which is three lines of cURL around these.
 *
 * That split is what makes the mapping testable. The response mapper is where
 * the real defects live — a missing field becoming the string "Array", a
 * `businessStatus` that silently defaults to operational, coordinates arriving
 * as strings and being compared as floats — and none of those need a network.
 *
 * VERIFIED AGAINST GOOGLE'S REFERENCE, 2026-09-12
 * -----------------------------------------------
 *   POST https://places.googleapis.com/v1/places:searchText
 *   headers: Content-Type: application/json
 *            X-Goog-Api-Key: <key>
 *            X-Goog-FieldMask: <mask, no spaces anywhere>
 *   A missing FieldMask is an error. `*` is refused by our own policy.
 *   At most 60 results across all pages.
 */
class Leadfinder_places
{
    const SEARCH_URL  = 'https://places.googleapis.com/v1/places:searchText';
    const NEARBY_URL  = 'https://places.googleapis.com/v1/places:searchNearby';
    const DETAILS_URL = 'https://places.googleapis.com/v1/places/';

    /**
     * Google's documented ceiling across all pages of one Text Search.
     *
     * Enforced by us as well as by Google, because the page loop is ours: a
     * loop that trusts `nextPageToken` to stop would keep paging as long as
     * Google keeps offering one, and every page is a billable request.
     */
    const MAX_RESULTS_ALL_PAGES = 60;

    /** Results per page, Google's maximum. */
    const PAGE_SIZE = 20;

    /**
     * Build the text query from the employee's form.
     *
     * Google's own guidance is that Text Search is not for queries with many
     * concepts stacked together, so the parts are joined into one natural
     * phrase — "Schools in Ranchi, Jharkhand" — rather than every field being
     * crammed in. Empty fields contribute nothing instead of an empty fragment.
     */
    public static function buildTextQuery(array $f)
    {
        $what = trim((string) (isset($f['keyword']) ? $f['keyword'] : ''));
        if ($what === '') { $what = trim((string) (isset($f['category']) ? $f['category'] : '')); }

        $where = array();
        foreach (array('city', 'state') as $k) {
            $v = trim((string) (isset($f[$k]) ? $f[$k] : ''));
            if ($v !== '') { $where[] = $v; }
        }
        $pin = trim((string) (isset($f['pin_code']) ? $f['pin_code'] : ''));
        if ($pin !== '') { $where[] = $pin; }

        if ($what === '' && !$where) { return ''; }
        if (!$where) { return $what; }
        return $what === '' ? implode(', ', $where) : $what . ' in ' . implode(', ', $where);
    }

    /**
     * The request body. `maxResultCount` is capped at Google's documented 60.
     *
     * @return array body, capped (bool), cap_reason
     */
    public static function searchBody(array $f)
    {
        $cap = Leadfinder_fieldmask::capResults(isset($f['max_results']) ? $f['max_results'] : 20);

        $body = array(
            'textQuery'      => self::buildTextQuery($f),
            'maxResultCount' => $cap['value'],
        );

        $lang = trim((string) (isset($f['language']) ? $f['language'] : ''));
        if ($lang !== '') { $body['languageCode'] = $lang; }

        /* A region code narrows results and is not guessed: it is only sent if
           the caller supplied one. */
        $region = trim((string) (isset($f['region_code']) ? $f['region_code'] : ''));
        if ($region !== '') { $body['regionCode'] = $region; }

        /*
         * A page token replaces the query, it does not accompany it.
         *
         * Google's Text Search (New) reference: when `pageToken` is supplied,
         * every other parameter must match the original request or the token is
         * rejected. Building the body the same way for every page and adding
         * only the token is what guarantees that, rather than a comment asking
         * the next person to remember.
         */
        $token = trim((string) (isset($f['page_token']) ? $f['page_token'] : ''));
        if ($token !== '') { $body['pageToken'] = $token; }

        return array('body' => $body, 'capped' => $cap['capped'], 'cap_reason' => $cap['reason']);
    }

    /**
     * Nearby Search body.
     *
     * Nearby is a different question from Text Search: "what is within this
     * circle", not "what matches these words". It takes a centre and a radius
     * and no free text at all, so a caller who has a latitude and longitude gets
     * results bounded by distance rather than by Google's interpretation of a
     * phrase.
     *
     * The radius is clamped to Google's documented 0–50,000 m. An out-of-range
     * radius is an error response — a billable one — so it is caught here where
     * it costs nothing.
     *
     * @return array body, ok, error
     */
    public static function nearbyBody(array $f)
    {
        $lat = isset($f['latitude'])  ? $f['latitude']  : null;
        $lng = isset($f['longitude']) ? $f['longitude'] : null;

        if (!is_numeric($lat) || !is_numeric($lng)) {
            return array('ok' => false, 'error' => 'nearby_requires_coordinates', 'body' => array());
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return array('ok' => false, 'error' => 'coordinates_out_of_range', 'body' => array());
        }

        $radius = (int) (isset($f['radius_m']) ? $f['radius_m'] : 0);

        if ($radius <= 0) {
            return array('ok' => false, 'error' => 'nearby_requires_a_radius', 'body' => array());
        }

        if ($radius > 50000) { $radius = 50000; }

        $cap = Leadfinder_fieldmask::capResults(isset($f['max_results']) ? $f['max_results'] : 20);

        $body = array(
            'maxResultCount' => $cap['value'],
            'locationRestriction' => array(
                'circle' => array(
                    'center' => array('latitude' => $lat, 'longitude' => $lng),
                    'radius' => (float) $radius,
                ),
            ),
        );

        /*
         * `includedTypes` is sent only when the caller named one. Guessing a
         * type would silently narrow somebody's search to a category they never
         * asked for, and they would read the empty result as "there is nothing
         * there".
         */
        $type = trim((string) (isset($f['included_type']) ? $f['included_type'] : ''));
        if ($type !== '') { $body['includedTypes'] = array($type); }

        $lang = trim((string) (isset($f['language']) ? $f['language'] : ''));
        if ($lang !== '') { $body['languageCode'] = $lang; }

        return array('ok' => true, 'error' => null, 'body' => $body,
                     'capped' => $cap['capped'], 'cap_reason' => $cap['reason'],
                     'radius_clamped' => $radius !== (int) (isset($f['radius_m']) ? $f['radius_m'] : 0));
    }

    /**
     * Validate a Nearby request before it costs anything.
     *
     * Nearby carries the same FieldMask rules as Text Search — the mask decides
     * the SKU either way — so the same validator runs on it.
     */
    public static function validateNearby(array $f, $fieldMask)
    {
        $b = self::nearbyBody($f);

        if (empty($b['ok'])) {
            return array('ok' => false, 'error' => $b['error']);
        }

        return Leadfinder_fieldmask::validate($fieldMask, Leadfinder_fieldmask::CLASS_SEARCH);
    }

    /**
     * How many more results this search may fetch, and whether to page at all.
     *
     * `$wanted` is what the employee asked for, `$alreadyHave` what previous
     * pages returned. Paging stops at whichever comes first: the request, our
     * cap, or Google running out of pages.
     *
     * @return array continue (bool), remaining, reason
     */
    public static function pagePlan($wanted, $alreadyHave, $nextPageToken)
    {
        $wanted = min(self::MAX_RESULTS_ALL_PAGES, max(1, (int) $wanted));
        $have   = max(0, (int) $alreadyHave);
        $token  = is_string($nextPageToken) ? trim($nextPageToken) : '';

        /*
         * Google's cap is tested FIRST, and the order is the point.
         *
         * `$wanted` is already clamped to 60 above, so with the cheaper test
         * first the 60 branch could never be reached: a request for 500 became
         * a request for 60, and stopping at 60 was reported as "you asked for
         * this many". The operator reading that would conclude their limit was
         * honoured, when in fact Google's was. Dead code in a reporting path is
         * a wrong explanation, not an unused one.
         */
        if ($have >= self::MAX_RESULTS_ALL_PAGES) {
            return array('continue' => false, 'remaining' => 0, 'reason' => 'google_maximum_of_60_reached');
        }

        if ($have >= $wanted) {
            return array('continue' => false, 'remaining' => 0, 'reason' => 'requested_count_reached');
        }

        if ($token === '') {
            return array('continue' => false, 'remaining' => $wanted - $have,
                         'reason' => 'google_offered_no_further_page');
        }

        return array('continue' => true, 'remaining' => $wanted - $have, 'reason' => 'more_pages_available');
    }

    /** The continuation token Google returned, or '' when there is none. */
    public static function nextPageToken($decoded)
    {
        if (!is_array($decoded) || !isset($decoded['nextPageToken'])) { return ''; }
        if (is_array($decoded['nextPageToken'])) { return ''; }

        return trim((string) $decoded['nextPageToken']);
    }

    /**
     * Does Places return an email address? No.
     *
     * This is a method rather than a comment because it is asserted by a test
     * and read by the view. The Places API returns a phone number and a website
     * and no email field of any kind, and a screen that leaves an "Email" column
     * blank invites the reading "Google had none for this business" — which
     * would be a claim about the business rather than about the API.
     *
     * `verified_email` on a prospect is filled by the employee during call
     * verification in Step 8. It never comes from Google.
     */
    public static function emailIsNeverReturnedByGoogle()
    {
        return true;
    }

    /** The sentence the UI shows where an email would otherwise appear. */
    public static function emailSourceNote()
    {
        return 'The Places API does not return email addresses. Any email here was '
             . 'recorded by the employee during call verification.';
    }

    /**
     * Headers. The key is passed in by the caller inside
     * Leadfinder_secret::useFor(), and never stored on this object.
     */
    public static function headers($apiKey, $fieldMask)
    {
        return array(
            'Content-Type: application/json',
            'X-Goog-Api-Key: ' . $apiKey,
            'X-Goog-FieldMask: ' . $fieldMask,
        );
    }

    /**
     * Validate a request before it costs anything.
     * @return array ok, error
     */
    public static function validateSearch(array $f, $fieldMask)
    {
        if (self::buildTextQuery($f) === '') {
            return array('ok' => false, 'error' => 'no_search_terms');
        }
        return Leadfinder_fieldmask::validate($fieldMask, Leadfinder_fieldmask::CLASS_SEARCH);
    }

    /**
     * Map one Google place to a prospect row.
     *
     * Every field is read defensively and every absent field becomes NULL, not
     * an empty string and never a default. `businessStatus` in particular:
     * absent means we do not know, and "we do not know" must not be recorded as
     * OPERATIONAL, because the conversion gate reads that field to decide
     * whether a business is trading.
     *
     * @param array $place   one element of the `places` array
     * @param array $ctx     search_id, profile_id, keyword, location, now, phone_profile
     */
    public static function mapPlace(array $place, array $ctx)
    {
        $id = self::str($place, 'id');
        if ($id === null) {
            /* No place id, no row. It is the unique key and the one field with
               an indefinite retention permission; a prospect without it cannot
               be deduplicated or lawfully kept. */
            return null;
        }

        $name = self::path($place, array('displayName', 'text'));
        if ($name === null) { $name = self::str($place, 'name'); }

        $loc = isset($place['location']) && is_array($place['location']) ? $place['location'] : array();
        $lat = isset($loc['latitude'])  && is_numeric($loc['latitude'])  ? (string) $loc['latitude']  : null;
        $lng = isset($loc['longitude']) && is_numeric($loc['longitude']) ? (string) $loc['longitude'] : null;

        $addr = self::addressParts($place);

        $row = array(
            'google_place_id'  => $id,
            'business_name'    => $name !== null ? $name : '(unnamed)',
            'category'         => self::category($place),
            'address'          => self::str($place, 'formattedAddress'),
            'city'             => $addr['city'],
            'state'            => $addr['state'],
            'pin_code'         => $addr['pin'],
            'latitude'         => $lat,
            'longitude'        => $lng,
            'google_maps_uri'  => self::str($place, 'googleMapsUri'),
            'business_status'  => self::str($place, 'businessStatus'),
            'status'           => 'new_result',
            'assigned_staff'   => 0,
            'claimed_by'       => 0,
            'search_id'        => isset($ctx['search_id']) ? (int) $ctx['search_id'] : null,
            'search_keyword'   => isset($ctx['keyword']) ? (string) $ctx['keyword'] : null,
            'search_location'  => isset($ctx['location']) ? (string) $ctx['location'] : null,
            'profile_id'       => isset($ctx['profile_id']) ? (int) $ctx['profile_id'] : null,
            'created_at'       => isset($ctx['now']) ? (int) $ctx['now'] : time(),
            /* When Google gave us this. The §14.3 coordinate clock starts here,
               not at row creation — they are the same instant today but will not
               be once a row is refreshed. */
            'google_fetched_at'=> isset($ctx['now']) ? (int) $ctx['now'] : time(),
        );

        return $row;
    }

    /** Map a whole response. Returns rows plus anything that could not be mapped. */
    public static function mapResponse($decoded, array $ctx)
    {
        if (!is_array($decoded)) {
            return array('rows' => array(), 'skipped' => 0, 'error' => 'response_not_json');
        }
        if (isset($decoded['error'])) {
            $msg = isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : 'unknown';
            $st  = isset($decoded['error']['status']) ? (string) $decoded['error']['status'] : '';
            return array('rows' => array(), 'skipped' => 0,
                         'error' => 'google_error:' . ($st !== '' ? $st : 'UNKNOWN'),
                         'detail' => $msg);
        }
        if (!isset($decoded['places']) || !is_array($decoded['places'])) {
            /* An empty result is not an error. Google returns no `places` key at
               all when nothing matches, and reporting that as a failure would
               teach employees to ignore real failures. */
            return array('rows' => array(), 'skipped' => 0, 'error' => null, 'empty' => true);
        }

        $rows = array(); $skipped = 0;
        foreach ($decoded['places'] as $p) {
            if (!is_array($p)) { $skipped++; continue; }
            $r = self::mapPlace($p, $ctx);
            if ($r === null) { $skipped++; continue; }
            $rows[] = $r;
        }
        return array('rows' => $rows, 'skipped' => $skipped, 'error' => null,
                     'next_page_token' => self::nextPageToken($decoded));
    }

    /**
     * Map a Place Details response onto the contact fields of a claimed
     * prospect. Returns only the columns that may be written.
     */
    public static function mapDetails($decoded, array $phoneProfile, $now)
    {
        if (!is_array($decoded)) { return array('ok' => false, 'error' => 'response_not_json'); }
        if (isset($decoded['error'])) {
            return array('ok' => false,
                         'error' => 'google_error:' . (isset($decoded['error']['status']) ? $decoded['error']['status'] : 'UNKNOWN'));
        }

        $raw  = self::str($decoded, 'nationalPhoneNumber');
        if ($raw === null) { $raw = self::str($decoded, 'internationalPhoneNumber'); }
        $site = self::str($decoded, 'websiteUri');

        $norm = Payplex_phone::normalise($raw === null ? '' : $raw, $phoneProfile);

        return array('ok' => true, 'error' => null, 'fields' => array(
            'phone_raw'          => $raw,
            'phone_e164'         => $norm['valid'] ? $norm['e164'] : null,
            'phone_weak_key'     => $norm['valid'] ? $norm['weakKey'] : null,
            'website'            => $site,
            'website_domain'     => self::domain($site),
            'business_status'    => self::str($decoded, 'businessStatus'),
            'details_fetched_at' => (int) $now,
            'google_fetched_at'  => (int) $now,
        ));
    }

    /* ------------------------------------------------------------------ */

    private static function str(array $a, $k)
    {
        if (!isset($a[$k])) { return null; }
        if (is_array($a[$k])) { return null; }
        $v = trim((string) $a[$k]);
        return $v === '' ? null : $v;
    }

    private static function path(array $a, array $keys)
    {
        $n = $a;
        foreach ($keys as $k) {
            if (!is_array($n) || !isset($n[$k])) { return null; }
            $n = $n[$k];
        }
        if (is_array($n)) { return null; }
        $v = trim((string) $n);
        return $v === '' ? null : $v;
    }

    private static function category(array $p)
    {
        $t = self::path($p, array('primaryTypeDisplayName', 'text'));
        if ($t !== null) { return $t; }
        if (isset($p['types']) && is_array($p['types']) && $p['types']) {
            $first = reset($p['types']);
            return is_string($first) && $first !== '' ? $first : null;
        }
        return null;
    }

    /**
     * City, state and postcode from addressComponents — which is why that field
     * is in the search mask. Pulling them from the formatted address string
     * would be guesswork, and a second Geocoding call would be a second bill.
     */
    private static function addressParts(array $p)
    {
        $out = array('city' => null, 'state' => null, 'pin' => null);
        if (!isset($p['addressComponents']) || !is_array($p['addressComponents'])) { return $out; }

        foreach ($p['addressComponents'] as $c) {
            if (!is_array($c) || !isset($c['types']) || !is_array($c['types'])) { continue; }
            $name = isset($c['longText']) ? trim((string) $c['longText']) : '';
            if ($name === '' && isset($c['shortText'])) { $name = trim((string) $c['shortText']); }
            if ($name === '') { continue; }

            if ($out['city'] === null && (in_array('locality', $c['types'], true)
                || in_array('postal_town', $c['types'], true))) {
                $out['city'] = $name;
            }
            if ($out['state'] === null && in_array('administrative_area_level_1', $c['types'], true)) {
                $out['state'] = $name;
            }
            if ($out['pin'] === null && in_array('postal_code', $c['types'], true)) {
                $out['pin'] = $name;
            }
        }
        return $out;
    }

    private static function domain($url)
    {
        if (!is_string($url) || trim($url) === '') { return null; }
        $u = trim(strtolower($url));
        if (strpos($u, '//') === false) { $u = 'http://' . $u; }
        $h = parse_url($u, PHP_URL_HOST);
        if (!is_string($h) || $h === '') { return null; }
        return strpos($h, 'www.') === 0 ? substr($h, 4) : $h;
    }
}
