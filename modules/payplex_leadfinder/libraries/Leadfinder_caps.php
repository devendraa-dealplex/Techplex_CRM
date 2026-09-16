<?php
defined('BASEPATH') or defined('PAYPLEX_LF_TEST') or exit('No direct script access allowed');

/**
 * Leadfinder_caps — which permissions exist, and which are real yet.
 *
 * THE NAMESPACE CHANGED, AND THIS WAS THE MOMENT TO DO IT
 * ------------------------------------------------------
 * The deployed build used `lf_*`. The agreed final namespace is `leadfinder_*`.
 * Renaming a registered capability silently strips it from every staff member
 * who holds it, because `tblstaff_permissions` stores the literal string — so
 * the only safe moment to rename is while nothing holds it.
 *
 * Verified on staging before the rename:
 *
 *     SELECT COUNT(*) FROM tblstaff_permissions WHERE feature = 'payplex_leadfinder';
 *     → 0
 *
 * Zero grants. Nothing to break. Doing it later, after Staff 33 and the others
 * are granted, would have meant a silent revocation that looks exactly like a
 * permissions bug.
 *
 * WHY ONLY FIVE OF THE TEN ARE REGISTERED
 * ---------------------------------------
 * The brief says to register all ten, and also says: *"Do not register a
 * capability unless the complete working feature exists."* Those two
 * instructions cannot both be followed today, because five of the ten have no
 * endpoint yet.
 *
 * This file follows the second, because it is the one that protects the
 * administrator. A permission on the Roles screen that nothing reads is worse
 * than an absent one: unticking "Approve a conversion" for an employee changes
 * nothing, while telling the person who unticked it that they have separated a
 * duty. CI rejected exactly that when the first draft registered all twelve of
 * §17's names.
 *
 * Each planned capability moves into `enforced()` in the same change that adds
 * the endpoint reading it — Tasks 6, 7 and 8.
 *
 * WHAT CHANGED BESIDES THE NAMES
 * ------------------------------
 * `lf_search` gated both reading the queue and running a Google search. Those
 * are different privileges: one shows work already paid for, the other spends
 * money against a billing account. They are now `leadfinder_view` and
 * `leadfinder_search`, and the split is enforced inside a single controller
 * action rather than being decorative.
 *
 * `lf_reassign` is gone. The final list has no reassignment capability, so
 * releasing another employee's claim now requires `leadfinder_view_all` or
 * administrator. If you want reassignment separated from visibility, that is an
 * eleventh name and a deliberate decision, not something to infer.
 */
class Leadfinder_caps
{
    /** The feature key these capabilities are registered under. */
    const FEATURE = 'payplex_leadfinder';

    /** Registered on the Roles screen. Every one is checked in Finder.php. */
    public static function enforced()
    {
        return array(
            'leadfinder_view'            => 'Open Lead Finder and view the prospect queue',
            'leadfinder_search'          => 'Run a business search against the Places API',
            'leadfinder_claim'           => 'Claim and release a prospect',
            'leadfinder_view_all'        => 'View every prospect, not only your own',
            'leadfinder_manage_profiles' => 'Configure API connection profiles and run the retention job',
            /* Step 6. Registered in the same change that adds the endpoint
               enforcing it — a detail call bills at a higher SKU than a search,
               so it is separable from `leadfinder_search` on purpose. */
            'leadfinder_fetch_details'   => 'Fetch phone and website for a prospect you have claimed',
            /* Step 8. Three separate names because they are three separate
               duties: recording a call, asking for it to become a lead, and
               deciding that it may. The last two must not be held usefully by
               one person on the same prospect — the model refuses it even when
               they are. */
            'leadfinder_verify'             => 'Record call results and verification on a prospect you hold',
            'leadfinder_submit_conversion'  => 'Submit a verified prospect for conversion approval',
            'leadfinder_approve_conversion' => 'Approve or reject a conversion submitted by somebody else',
            /* Step 9. Reports carry aggregates across everybody's work, so they
               are their own permission rather than something `leadfinder_view`
               implies: seeing your own queue and seeing the whole team's
               conversion rates are different things to be allowed. */
            'leadfinder_reports'            => 'View Lead Finder reports and export them',
        );
    }

    /**
     * Named in the final model, endpoints not built. NOT registered.
     * Each moves into enforced() in the same change as its endpoint.
     */
    public static function planned()
    {
        return array(
        );
    }

    /** The complete agreed model — enforced plus planned. Exactly ten. */
    public static function all()
    {
        return array_merge(self::enforced(), self::planned());
    }

    /**
     * The capability that gates reading the queue.
     *
     * Named once, here, so the sidebar and the controller cannot consult
     * different strings. That is the defect that was just found and fixed in
     * leadgen_followup: its menu asked `leads: view` while its page asked
     * `leadgen_followup: view`, so staff were shown a link that denied them.
     * CapabilityNamespaceTest asserts both call sites resolve to this constant.
     */
    const CAP_VIEW            = 'leadfinder_view';
    const CAP_SEARCH          = 'leadfinder_search';
    const CAP_CLAIM           = 'leadfinder_claim';
    const CAP_VIEW_ALL        = 'leadfinder_view_all';
    const CAP_MANAGE_PROFILES = 'leadfinder_manage_profiles';
    const CAP_FETCH_DETAILS   = 'leadfinder_fetch_details';
    const CAP_VERIFY          = 'leadfinder_verify';
    const CAP_SUBMIT          = 'leadfinder_submit_conversion';
    const CAP_APPROVE         = 'leadfinder_approve_conversion';
    const CAP_REPORTS         = 'leadfinder_reports';

    /** Old names, kept only so a test can assert none of them survives. */
    public static function retiredNames()
    {
        return array('lf_search', 'lf_claim', 'lf_view_team', 'lf_reassign', 'lf_api_manage',
                     'lf_verify', 'lf_convert', 'lf_approve', 'lf_quality_review',
                     'lf_quota_manage', 'lf_restore', 'lf_audit_view');
    }

    /**
     * No capability in this module reveals an API key.
     *
     * Stated as code so a test can assert it: there is no `leadfinder_api_view`,
     * nothing that could be granted to make a key visible, because no endpoint
     * exists that would serve one. The requirement is met by the absence of a
     * code path, not by withholding a permission.
     */
    public static function keyRevealingCapabilities()
    {
        return array();
    }
}
