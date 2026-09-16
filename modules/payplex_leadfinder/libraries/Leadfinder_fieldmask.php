<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_fieldmask — what we ask Google for, and what we deliberately don't.
 *
 * WHY A WHOLE CLASS FOR A COMMA-SEPARATED STRING
 * ----------------------------------------------
 * On Places API (New) the FieldMask is not a formatting detail — it selects the
 * billing SKU. Ask for a contact field and the whole request is billed at the
 * contact tier, whether or not anybody reads the number. So §4's rule, "fetch
 * phone number and website only when an employee selects or claims a prospect",
 * is enforced here or it is not enforced at all: a search that quietly includes
 * `websiteUri` costs the higher rate on every one of its results and nothing in
 * the UI would ever show it.
 *
 * The invariant this class exists to hold:
 *
 *     the search mask NEVER contains a contact field.
 *
 * A test asserts it over the actual generated string, not over the source list,
 * because the two drift.
 *
 * A wildcard mask (`*`) is rejected outright. It is the most expensive possible
 * request and it pulls fields the caching rules in §18 then oblige us to throw
 * away — paying top rate for data we are not allowed to keep.
 *
 * NO PRICES IN THIS FILE
 * ----------------------
 * Google's SKU names and rates change, and this environment cannot reach
 * Google's pricing documentation to verify them. Nothing here encodes a price
 * or a free-tier figure. It encodes only which fields belong to which REQUEST
 * CLASS, which is a property of our own workflow: fields we need to list a
 * prospect, versus fields we need to contact one. Ceilings and costs live in
 * configuration, sourced and dated by an administrator.
 */
class Leadfinder_fieldmask
{
    const CLASS_SEARCH  = 'search';
    const CLASS_CONTACT = 'contact';

    /*
     * VERIFIED 2026-09-12 against Google's Text Search (New) reference.
     *
     * Phase 1 modelled billing as a binary search/contact split. That was right
     * about the expensive boundary and wrong about where the cheap one sits:
     * `displayName`, `formattedAddress`, `location`, `types`, `businessStatus`,
     * `addressComponents` and `googleMapsUri` are all **Pro**, not Essentials.
     * Only `id`, `name`, `attributions` and `nextPageToken` are Essentials ID
     * Only.
     *
     * So our search request is a PRO request and always was. Calling it
     * "essentials" anywhere would have understated the bill to whoever signs
     * off the quota ceilings.
     */
    const SKU_ESSENTIALS = 'essentials_id_only';
    const SKU_PRO        = 'pro';
    const SKU_ENTERPRISE = 'enterprise';

    /** Verified 2026-09-12: "Text Search (New) returns a maximum of 60 results
     *  across all pages." Asking for more is not an error — it is a silently
     *  short answer, which is worse. Capped here, and the caller is told. */
    const MAX_RESULTS = 60;

    /** Verified field → SKU, for the fields this module may request. */
    public static function skuOf($field)
    {
        $f = strpos($field, 'places.') === 0 ? substr($field, 7) : $field;
        $essentials = array('id', 'name', 'attributions', 'nextPageToken');
        $enterprise = array('nationalPhoneNumber', 'internationalPhoneNumber',
                            'websiteUri', 'rating', 'userRatingCount', 'priceLevel',
                            'regularOpeningHours', 'currentOpeningHours');
        if (in_array($f, $essentials, true)) { return self::SKU_ESSENTIALS; }
        if (in_array($f, $enterprise, true)) { return self::SKU_ENTERPRISE; }
        return self::SKU_PRO;
    }

    /** The highest SKU any field in the mask triggers — that is what bills. */
    public static function billingSku($mask)
    {
        $rank = array(self::SKU_ESSENTIALS => 0, self::SKU_PRO => 1, self::SKU_ENTERPRISE => 2);
        $top  = self::SKU_ESSENTIALS;
        foreach (self::parse($mask) as $f) {
            if ($rank[self::skuOf($f)] > $rank[$top]) { $top = self::skuOf($f); }
        }
        return $top;
    }

    public static function capResults($requested)
    {
        $n = (int) $requested;
        if ($n <= 0) {
            return array('value' => 1, 'capped' => true, 'reason' => 'below_minimum');
        }
        if ($n > self::MAX_RESULTS) {
            return array('value' => self::MAX_RESULTS, 'capped' => true,
                         'reason' => 'google_returns_at_most_60_across_all_pages');
        }
        return array('value' => $n, 'capped' => false, 'reason' => '');
    }

    /**
     * §4: the initial search asks for these and nothing else.
     * Unprefixed names; prefixing is per-endpoint and done below.
     */
    public static function searchFields()
    {
        return array(
            'id',                 // Place ID — the durable duplicate key (§10.1)
            'displayName',        // business name
            'primaryTypeDisplayName',
            'types',              // category (§6)
            'businessStatus',     // operational / closed — the conversion gate reads this
            'formattedAddress',
            'addressComponents',  // city / state / PIN without a second Geocoding call
            'location',           // lat / lng
            'googleMapsUri',      // the attribution link §18 requires
        );
    }

    /**
     * Fetched only for a claimed prospect, one place at a time.
     *
     * Note what is NOT here: no reviews, no photos, no opening hours, no
     * editorial summary. Each is a further billing tier and none of them is
     * needed to ring a school and ask whether they buy what we sell.
     */
    public static function contactFields()
    {
        return array(
            'id',
            'nationalPhoneNumber',
            'internationalPhoneNumber',
            'websiteUri',
            'businessStatus',     // re-read at contact time: it may have closed since the search
        );
    }

    /** The fields that make a request a contact-class request. */
    public static function contactOnlyFields()
    {
        return array_values(array_diff(self::contactFields(), self::searchFields()));
    }

    /**
     * Build the header value for a search (Text Search / Nearby Search).
     * Those endpoints return a list, so every field is prefixed `places.`.
     */
    public static function forSearch()
    {
        return self::join(self::prefix(self::searchFields(), 'places.'));
    }

    /** Build the header value for Place Details, which returns one place. */
    public static function forDetails()
    {
        return self::join(self::contactFields());
    }

    /**
     * Which request class does an arbitrary mask fall into?
     * Used to decide which quota counter a call spends, so a mask that drifts
     * cannot quietly spend the cheap budget.
     */
    public static function classify($mask)
    {
        foreach (self::parse($mask) as $f) {
            if (in_array(self::unprefix($f), self::contactOnlyFields(), true)) {
                return self::CLASS_CONTACT;
            }
        }
        return self::CLASS_SEARCH;
    }

    /**
     * Reject a mask before it is sent.
     *
     * @return array ok, error
     */
    public static function validate($mask, $expectedClass)
    {
        if (!is_string($mask) || trim($mask) === '') {
            return self::bad('field_mask_empty');
        }
        if (strpos($mask, '*') !== false) {
            /* Google permits `*` and discourages it in production. We refuse it
               outright: it bills at the highest tier and returns fields the
               Service Specific Terms then oblige us to delete. Our policy,
               stricter than Google's requirement — said so rather than
               presented as a Google rule. */
            return self::bad('wildcard_field_mask_refused_by_policy');
        }
        /* VERIFIED 2026-09-12, Text Search (New) reference: "Spaces are not
           allowed anywhere in the field list." Google rejects such a mask, so
           it is rejected here, where the error is legible and free. */
        if (preg_match('/\s/', $mask)) {
            return self::bad('field_mask_contains_whitespace');
        }
        $fields = self::parse($mask);
        if (!$fields) { return self::bad('field_mask_empty'); }

        $known = array_merge(self::searchFields(), self::contactFields());
        foreach ($fields as $f) {
            if (!in_array(self::unprefix($f), $known, true)) {
                return self::bad('field_not_on_the_approved_list:' . self::unprefix($f));
            }
        }
        if ($expectedClass === self::CLASS_SEARCH
            && self::classify($mask) === self::CLASS_CONTACT) {
            return self::bad('contact_field_in_a_search_request');
        }
        return array('ok' => true, 'error' => null);
    }

    /**
     * Does this prospect already hold everything a detail call would return?
     * §13: "Prevent repeated detail calls." Asking twice costs twice and
     * returns the same answer.
     */
    public static function detailsAlreadyHeld(array $prospect)
    {
        /*
         * The fetch TIMESTAMP decides this, not whether a phone number came
         * back. A business with genuinely no listed phone and no website would
         * otherwise be re-queried on every visit for ever — which is precisely
         * the repeated detail spend this is here to stop, arriving through the
         * check meant to stop it.
         *
         * The first draft of this method also tested the phone and website
         * values. Since it already required a timestamp, those tests could
         * never change the answer: a condition that reads like a guard and
         * decides nothing. Removed rather than left in place looking load-bearing.
         */
        $fetched = isset($prospect['details_fetched_at']) ? (int) $prospect['details_fetched_at'] : 0;
        return $fetched > 0;
    }

    /**
     * Has a cached detail passed the age at which §18 requires it to go?
     *
     * The permitted duration is configuration, not a constant in this file:
     * this environment cannot reach Google's service terms to verify the
     * current figure, and inventing one would be worse than requiring it to be
     * set. `max_age_seconds` of 0 means "not configured" and every record is
     * treated as stale, so an unconfigured install re-fetches rather than
     * silently over-retaining.
     */
    public static function detailIsStale(array $prospect, $now, array $cfg)
    {
        $fetched = isset($prospect['details_fetched_at']) ? (int) $prospect['details_fetched_at'] : 0;
        if ($fetched <= 0) { return true; }
        $maxAge = isset($cfg['max_age_seconds']) ? (int) $cfg['max_age_seconds'] : 0;
        if ($maxAge <= 0)  { return true; }
        return ((int) $now - $fetched) >= $maxAge;
    }

    /* ------------------------------------------------------------------ */

    private static function prefix(array $fields, $p)
    {
        $out = array();
        foreach ($fields as $f) { $out[] = $p . $f; }
        return $out;
    }

    private static function unprefix($f)
    {
        return strpos($f, 'places.') === 0 ? substr($f, 7) : $f;
    }

    private static function parse($mask)
    {
        $out = array();
        foreach (explode(',', (string) $mask) as $f) {
            $f = trim($f);
            if ($f !== '') { $out[] = $f; }
        }
        return $out;
    }

    private static function join(array $fields)
    {
        return implode(',', $fields);
    }

    private static function bad($e)
    {
        return array('ok' => false, 'error' => $e);
    }
}
