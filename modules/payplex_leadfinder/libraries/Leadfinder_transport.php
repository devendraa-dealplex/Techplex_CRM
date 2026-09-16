<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_fieldmask.php';

/**
 * Leadfinder_transport — where a Places request actually goes.
 *
 * WHY A SEAM AT ALL
 * -----------------
 * The administrator has not entered a Google API key yet, and will not until the
 * rest of this module is verified. Without a seam there are two ways to develop
 * against that: hold the whole search workflow untested until a key exists, or
 * test it by pointing it at Google with a key that must not be used. Both are
 * bad, and the second is the one that gets chosen under time pressure.
 *
 * So `mock` returns a fixed, obviously-synthetic response shaped exactly like
 * Google's, and the pipeline — quota reservation, FieldMask validation,
 * pagination, mapping, duplicate classification, queue insertion — runs end to
 * end against it.
 *
 * SIMULATED DATA IS MARKED, NOT DISGUISED
 * ---------------------------------------
 * A mock prospect that is indistinguishable from a real one is worse than no
 * mock at all: it sits in the verification queue looking like a business, and
 * somebody rings it. Every row this transport produces carries a name that says
 * so, a place id in a reserved namespace, and a phone number in the UK's
 * reserved-for-drama 07700 900xxx range — numbers Ofcom guarantees are not
 * allocated to anyone, so dialling one cannot reach a real person. The model
 * additionally flags the row and the search, and the view says so in red.
 *
 * The brief's standing rule is "do not contact any real third-party business
 * during technical UAT". A fixture that cannot be a real business is how that
 * rule is kept by construction rather than by everyone remembering it.
 *
 * MOCK MODE IS OPT-IN AND LOUD
 * ----------------------------
 * It is selected by a configuration row, defaults to off, and the search screen
 * carries a banner whenever it is on. A simulation that can be left switched on
 * quietly is a data-integrity incident waiting for a busy week.
 */
class Leadfinder_transport
{
    const MODE_LIVE = 'live';
    const MODE_MOCK = 'mock';

    /** The reserved place-id prefix. Nothing from Google ever starts with this. */
    const MOCK_PLACE_PREFIX = 'SIMULATED-NOT-A-REAL-PLACE-';

    /**
     * Ofcom's reserved drama range: never allocated to a subscriber, so a
     * fixture number cannot ring a real person even if one is dialled by hand.
     */
    const MOCK_PHONE_PREFIX = '+4477009009';

    public static function isMock($mode)
    {
        return (string) $mode === self::MODE_MOCK;
    }

    /**
     * A synthetic Text Search / Nearby response.
     *
     * Shaped exactly like Google's so the mapper is exercised for real: nested
     * `displayName.text`, `addressComponents` with type arrays, a `location`
     * object, and — deliberately — one place missing its `id`, one with an
     * absent `businessStatus`, and one whose `displayName` is missing. Those
     * three are the cases the mapper is most likely to get wrong, and a fixture
     * of five perfect records would prove nothing about any of them.
     */
    public static function mockSearch($textQuery, $maxResults, $pageToken = '')
    {
        $page  = $pageToken === '' ? 1 : 2;
        $want  = max(1, min(Leadfinder_places::MAX_RESULTS_ALL_PAGES, (int) $maxResults));
        $count = min(Leadfinder_places::PAGE_SIZE, $want);

        $places = array();

        for ($i = 1; $i <= $count; $i++) {
            $n = (($page - 1) * Leadfinder_places::PAGE_SIZE) + $i;

            $place = array(
                'id'               => self::MOCK_PLACE_PREFIX . sprintf('%04d', $n),
                'displayName'      => array('text' => 'SIMULATED Test Record ' . $n
                                                    . ' (not a real business)',
                                            'languageCode' => 'en'),
                'formattedAddress' => $n . ' Example Road, Test Area',
                'googleMapsUri'    => 'https://example.invalid/simulated/' . $n,
                'businessStatus'   => 'OPERATIONAL',
                'primaryTypeDisplayName' => array('text' => 'Simulated Category'),
                'location'         => array('latitude' => 23.3441 + ($n / 10000),
                                            'longitude' => 85.3096 + ($n / 10000)),
                'addressComponents' => array(
                    array('longText' => 'Ranchi', 'shortText' => 'Ranchi', 'types' => array('locality')),
                    array('longText' => 'Jharkhand', 'shortText' => 'JH', 'types' => array('administrative_area_level_1')),
                    array('longText' => '834001', 'shortText' => '834001', 'types' => array('postal_code')),
                ),
            );

            /* The three awkward shapes, seeded on purpose. */
            if ($n % 7 === 0) { unset($place['id']); }
            if ($n % 5 === 0) { unset($place['businessStatus']); }
            if ($n % 6 === 0) { unset($place['displayName']); }

            $places[] = $place;
        }

        $out = array('places' => $places);

        /* A second page exists only when one was asked for and page 1 filled. */
        if ($page === 1 && $want > Leadfinder_places::PAGE_SIZE) {
            $out['nextPageToken'] = 'SIMULATED-PAGE-TOKEN-2';
        }

        return array('ok' => true, 'http_status' => 200, 'data' => $out, 'error' => null,
                     'simulated' => true);
    }

    /**
     * A synthetic Place Details response.
     *
     * Half the fixtures have no website and one in three has no phone, because
     * "Google returned nothing for this field" is the common case that the
     * details path must handle without re-querying for ever. There is no email
     * field, because Places has none — see
     * `Leadfinder_places::emailIsNeverReturnedByGoogle()`.
     */
    public static function mockDetails($placeId)
    {
        $n = (int) preg_replace('/\D/', '', (string) $placeId);

        $out = array(
            'id'             => (string) $placeId,
            'businessStatus' => 'OPERATIONAL',
        );

        if ($n % 3 !== 0) {
            $out['nationalPhoneNumber']      = '07700 9009' . sprintf('%02d', $n % 100);
            $out['internationalPhoneNumber'] = self::MOCK_PHONE_PREFIX . sprintf('%02d', $n % 100);
        }

        if ($n % 2 === 0) {
            $out['websiteUri'] = 'https://simulated-' . $n . '.example.invalid/';
        }

        return array('ok' => true, 'http_status' => 200, 'data' => $out, 'error' => null,
                     'simulated' => true);
    }

    /**
     * Is this row synthetic?
     *
     * Read from the place id rather than from a flag column alone, so a row
     * whose flag was lost in an export is still recognisable. Both are checked
     * by the model; either one being true is enough to treat the row as
     * simulated.
     */
    public static function looksSimulated($placeId)
    {
        return strpos((string) $placeId, self::MOCK_PLACE_PREFIX) === 0;
    }

    /** The banner shown on every screen while mock mode is on. */
    public static function bannerText()
    {
        return 'SIMULATED MODE: results are generated locally and are not from Google. '
             . 'No request is sent, no quota is spent against Google, and every record '
             . 'produced is marked as simulated. Do not call any number shown here.';
    }
}
