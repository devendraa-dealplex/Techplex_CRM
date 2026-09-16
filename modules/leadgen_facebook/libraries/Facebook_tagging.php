<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * What gets written onto the lead record itself.
 *
 * WHY THIS IS NOT ALREADY DONE BY THE CLAIM ROW
 * ---------------------------------------------
 * The module already stores `page_id`, `form_id`, `campaign_id` and the Facebook
 * lead reference on its own claim row in `tblleadgen_facebook_messages`. That is
 * enough to reconcile a delivery and to prove idempotency, and it is useless to
 * the person whose job is to call the lead: they open the lead in the CRM and
 * see a name, a phone number and a source. Nothing tells them this came from the
 * DealPlex campaign rather than one of the other businesses sharing this install.
 *
 * With one CRM receiving leads from several Facebook Pages across several
 * businesses, "which business unit is this" stops being metadata and becomes the
 * first question asked about every lead. So it goes on the lead:
 *
 *   - **tags** for the things people filter and segment by — the business unit
 *     and the source. Perfex tags are indexed, filterable on the Leads list and
 *     already understood by the reports.
 *   - **custom fields** for the identifiers nobody reads casually but somebody
 *     needs when a lead is disputed — Page, Page ID, Form ID, Campaign ID and
 *     the Facebook lead id.
 *
 * Tags for what you search by; fields for what you look up. Putting the ids in
 * tags would flood the tag list with thousands of single-use entries, and
 * putting the business unit only in a custom field would make it unfilterable,
 * which is the one thing it must be.
 *
 * NOTHING HERE IS HARD-CODED TO ONE BUSINESS
 * ------------------------------------------
 * The business unit and Page name are configuration, read from options, seeded
 * EMPTY. Writing "DealPlex" into this file would be the same mistake as the
 * hard-coded status id this module was opened to fix: it works on one install
 * and silently mislabels every lead on the next.
 *
 * Pure functions. Names in, validated names out.
 */
class Facebook_tagging
{
    /** Longest tag Perfex's column will take without truncating. */
    const MAX_TAG_LENGTH = 50;

    /** Longest reference value stored in a custom field. */
    const MAX_VALUE_LENGTH = 191;

    /**
     * The custom-field slugs this module writes, and their labels.
     *
     * A literal map, because the migration seeds the definitions from it and the
     * writer looks them up from it — one list, so a field that is seeded but
     * never written, or written but never seeded, is impossible rather than
     * merely unlikely.
     */
    const FIELDS = array(
        'facebook_page'        => 'Facebook Page',
        'facebook_page_id'     => 'Facebook Page ID',
        'facebook_form_id'     => 'Facebook Form ID',
        'facebook_campaign_id' => 'Facebook Campaign ID',
        'facebook_lead_id'     => 'Facebook Lead ID',
    );

    /**
     * The tags a lead should carry.
     *
     * Business unit first, then source, then Page name — most general to most
     * specific, which is the order they read in on the lead screen.
     *
     * An empty or unconfigured value contributes no tag rather than an empty
     * one. A blank tag is worse than a missing tag: it appears in the tag list,
     * it can be filtered on, and it means nothing.
     */
    public static function tagsFor($businessUnit, $sourceName, $pageName = '')
    {
        $out = array();

        foreach (array($businessUnit, $sourceName, $pageName) as $candidate) {
            $tag = self::cleanTag($candidate);

            if ($tag === '') {
                continue;
            }

            /* De-duplicated on a normalised key, so "DealPlex" and "dealplex"
               do not become two tags meaning the same thing. */
            $dupe = false;

            foreach ($out as $existing) {
                if (self::key($existing) === self::key($tag)) {
                    $dupe = true;
                    break;
                }
            }

            if (!$dupe) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /**
     * Reduce a candidate to something safe to store as a tag.
     *
     * Commas are removed rather than escaped: Perfex stores tag input as a
     * comma-separated string in several places, so a tag containing a comma
     * becomes two tags, one of which is a fragment. That is a silent data defect
     * and it is cheaper to refuse the character.
     */
    public static function cleanTag($value)
    {
        $v = trim((string) $value);

        if ($v === '') {
            return '';
        }

        /* Strip anything that would be markup, then collapse whitespace. */
        $v = strip_tags($v);
        $v = str_replace(array(',', '|', "\r", "\n", "\t"), ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        $v = trim((string) $v);

        if ($v === '') {
            return '';
        }

        return mb_substr($v, 0, self::MAX_TAG_LENGTH);
    }

    /** Comparison key for a human-typed tag: lowercase, whitespace collapsed. */
    public static function key($name)
    {
        $n = strtolower(trim((string) $name));
        $n = preg_replace('/\s+/', ' ', $n);

        return $n === null ? '' : $n;
    }

    /**
     * The reference values to write as custom fields.
     *
     * Only the ones actually present. A custom field holding an empty string is
     * indistinguishable on screen from one that was never written, but it is a
     * row in the database and it makes "this lead has no campaign id" and "this
     * lead's campaign id was not sent" look identical — so an absent value
     * writes no row.
     *
     * @param array $ctx page_name, page_id, form_id, campaign_id, leadgen_id
     */
    public static function referenceFields(array $ctx)
    {
        $map = array(
            'facebook_page'        => isset($ctx['page_name']) ? $ctx['page_name'] : '',
            'facebook_page_id'     => isset($ctx['page_id']) ? $ctx['page_id'] : '',
            'facebook_form_id'     => isset($ctx['form_id']) ? $ctx['form_id'] : '',
            'facebook_campaign_id' => isset($ctx['campaign_id']) ? $ctx['campaign_id'] : '',
            'facebook_lead_id'     => isset($ctx['leadgen_id']) ? $ctx['leadgen_id'] : '',
        );

        $out = array();

        foreach ($map as $slug => $value) {
            $value = self::cleanValue($value);

            if ($value !== '') {
                $out[$slug] = $value;
            }
        }

        return $out;
    }

    /**
     * A reference value, safe to store.
     *
     * These arrive from an unauthenticated request body. They are Meta's
     * numeric identifiers in every legitimate case, but the endpoint cannot
     * assume that, and this value is rendered on the lead screen.
     */
    public static function cleanValue($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $v = trim((string) $value);

        if ($v === '') {
            return '';
        }

        $v = strip_tags($v);
        $v = preg_replace('/[\x00-\x1F\x7F]+/', '', $v);
        $v = trim((string) $v);

        return mb_substr($v, 0, self::MAX_VALUE_LENGTH);
    }

    /** Is this one of the slugs this module owns? */
    public static function isOwnedField($slug)
    {
        return array_key_exists((string) $slug, self::FIELDS);
    }

    public static function labelFor($slug)
    {
        return isset(self::FIELDS[$slug]) ? self::FIELDS[$slug] : '';
    }

    public static function slugs()
    {
        return array_keys(self::FIELDS);
    }
}
