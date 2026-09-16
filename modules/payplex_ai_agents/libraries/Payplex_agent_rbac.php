<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_rbac
 *
 * Pure, dependency-free multi-company access control for the Plex Group AI
 * Executive Leadership System.
 *
 * Rule: no agent, decision or template belonging to one company is visible to
 * a staff member who was not granted access to that company — unless they hold
 * a cross-company grant, or are an admin (Chairman-level, sees the whole group).
 *
 * Records with an EMPTY company are "group-level" and are visible to anyone who
 * has any company access (they belong to the group, not a single company).
 */
class Payplex_agent_rbac
{
    /** The default Plex Group companies (seed set). */
    public static function defaultCompanies()
    {
        return array(
            array('code' => 'techplex',   'name' => 'TechPlex'),
            array('code' => 'payplex',    'name' => 'Payplex'),
            array('code' => 'indiplex',   'name' => 'Indiplex'),
            array('code' => 'dealplex',   'name' => 'DealPlex'),
            array('code' => 'shopdealplex','name' => 'ShopDealPlex'),
            array('code' => 'nityaplex',  'name' => 'Nityaplex Infrastructure'),
        );
    }

    /** Normalise a free-text company name/code to a comparable code. */
    public static function normalizeCode($text)
    {
        $s = strtolower(trim((string) $text));
        $s = preg_replace('/[^a-z0-9]+/', '', $s); // "Nityaplex Infrastructure" -> "nityaplexinfrastructure"
        return $s;
    }

    /**
     * The set of company codes an actor may access.
     *
     * @param array $grants   staff's granted company codes (already normalised)
     * @param bool  $isAdmin  admin sees the whole group
     * @param array $allCodes all known company codes (normalised)
     * @return array normalised company codes the actor can access
     */
    public static function allowedCompanies(array $grants, $isAdmin, array $allCodes)
    {
        if ($isAdmin) {
            return array_values(array_unique(array_map(array(__CLASS__, 'normalizeCode'), $allCodes)));
        }
        $out = array();
        foreach ($grants as $g) {
            $c = self::normalizeCode($g);
            if ($c !== '') { $out[] = $c; }
        }
        return array_values(array_unique($out));
    }

    /**
     * Can an actor access a record belonging to $recordCompany?
     *
     * @param string $recordCompany   the record's company (raw)
     * @param array  $allowedCodes    normalised codes the actor may access
     * @param bool   $isAdmin         admin bypasses scoping
     * @param bool   $crossCompany    actor holds a cross-company grant
     * @return bool
     */
    public static function canAccess($recordCompany, array $allowedCodes, $isAdmin = false, $crossCompany = false)
    {
        if ($isAdmin || $crossCompany) {
            return true;
        }
        $code = self::normalizeCode($recordCompany);
        // group-level record (no company) -> visible to anyone with some access
        if ($code === '') {
            return !empty($allowedCodes);
        }
        return in_array($code, array_map(array(__CLASS__, 'normalizeCode'), $allowedCodes), true);
    }

    /**
     * Filter a list of records to those the actor may access.
     *
     * @param array  $records      list of arrays/objects
     * @param array  $allowedCodes normalised allowed codes
     * @param bool   $isAdmin
     * @param bool   $crossCompany
     * @param string $companyKey   the field holding the record's company
     * @return array filtered records (original order)
     */
    public static function scope(array $records, array $allowedCodes, $isAdmin = false, $crossCompany = false, $companyKey = 'company')
    {
        if ($isAdmin || $crossCompany) {
            return $records;
        }
        $out = array();
        foreach ($records as $r) {
            $company = '';
            if (is_array($r) && isset($r[$companyKey]))       { $company = $r[$companyKey]; }
            elseif (is_object($r) && isset($r->$companyKey))  { $company = $r->$companyKey; }
            if (self::canAccess($company, $allowedCodes, false, false)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * When an actor requests a specific company "view", intersect it with what
     * they are allowed to see. Returns the effective list of company codes to
     * query (empty array = the actor may see nothing of that request).
     *
     * @param string $requested   requested company code, or '' / 'group' for all-allowed
     * @param array  $allowedCodes normalised allowed codes
     * @param bool   $isAdmin
     */
    public static function effectiveView($requested, array $allowedCodes, $isAdmin = false)
    {
        $req = self::normalizeCode($requested);
        $allowed = array_map(array(__CLASS__, 'normalizeCode'), $allowedCodes);
        if ($req === '' || $req === 'group' || $req === 'all') {
            return array_values(array_unique($allowed));
        }
        if ($isAdmin || in_array($req, $allowed, true)) {
            return array($req);
        }
        return array(); // requested a company they cannot see
    }
}
