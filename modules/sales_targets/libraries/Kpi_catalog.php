<?php

defined('BASEPATH') or defined('SALES_TARGETS_TEST') or exit('No direct script access allowed');

/**
 * Kpi_catalog — the KPIs a target may be built from (spec §3.2).
 *
 * Pure and framework-independent.
 *
 * The governing rule, carried over from the Phase 3 decision and from every
 * other part of this project: NEVER FABRICATE A MEASUREMENT. Several KPIs the
 * spec lists have no native source in this Perfex install — there is no calls
 * table, no demo record, no renewal entity. A calculator for those would return
 * 0, and a 0 is indistinguishable from "genuinely achieved nothing". So each KPI
 * declares honestly what it is:
 *
 *   implemented       the engine calculates it from a real source, today
 *   source_pending    a native source exists, but no calculator is written yet
 *   no_native_source  nothing in this system records it; it cannot be computed
 *   manual            a human enters the figure; there is nothing to compute
 *
 * Only 'implemented' and 'manual' KPIs can produce a number. The rest are
 * reported as UNMEASURABLE, and a target containing them has an achievement
 * figure that is explicitly incomplete rather than quietly understated.
 *
 * Declared source tables are checked at runtime by the engine rather than
 * assumed here, so an install missing a table degrades to source_missing instead
 * of throwing.
 */
class Kpi_catalog
{
    const IMPLEMENTED      = 'implemented';
    const SOURCE_PENDING   = 'source_pending';
    const NO_NATIVE_SOURCE = 'no_native_source';
    const MANUAL           = 'manual';
    const SOURCE_MISSING   = 'source_missing';

    /**
     * key => [label, status, source_table (null when none), unit, direction]
     *
     * unit: count | currency
     * direction: up means more is better (all of these); kept explicit so a
     * future cost-style KPI cannot silently invert the achievement maths.
     */
    private static function map()
    {
        return array(
            // --- calculated today ---
            'converted_leads' => array(
                'Leads converted to customers', self::IMPLEMENTED, 'leads', 'count', 'up'),
            'gross_billed_revenue' => array(
                'Gross billed revenue', self::IMPLEMENTED, 'invoices', 'currency', 'up'),
            'amount_collected' => array(
                'Amount collected', self::IMPLEMENTED, 'invoicepaymentrecords', 'currency', 'up'),

            // --- a real source exists, calculator not yet written ---
            'leads_assigned' => array(
                'Leads assigned', self::SOURCE_PENDING, 'leads', 'count', 'up'),
            'leads_contacted' => array(
                'Leads contacted', self::SOURCE_PENDING, 'leads', 'count', 'up'),
            'proposals_sent' => array(
                'Proposals sent', self::SOURCE_PENDING, 'proposals', 'count', 'up'),
            'net_billed_revenue' => array(
                'Net billed revenue', self::SOURCE_PENDING, 'invoices', 'currency', 'up'),
            'product_wise_sales' => array(
                'Product-wise sales', self::SOURCE_PENDING, 'itemable', 'currency', 'up'),

            // --- nothing in this system records these ---
            'leads_qualified' => array(
                'Leads qualified', self::NO_NATIVE_SOURCE, null, 'count', 'up'),
            'demos_delivered' => array(
                'Demos delivered', self::NO_NATIVE_SOURCE, null, 'count', 'up'),
            'renewals_collected' => array(
                'Renewals collected', self::NO_NATIVE_SOURCE, null, 'currency', 'up'),

            // --- entered by a person ---
            'custom_kpi' => array(
                'Approved custom KPI', self::MANUAL, null, 'count', 'up'),
        );
    }

    public static function keys()
    {
        return array_keys(self::map());
    }

    public static function exists($key)
    {
        return isset(self::map()[(string) $key]);
    }

    public static function get($key)
    {
        $m = self::map();
        $k = (string) $key;
        if (!isset($m[$k])) { return null; }
        return array(
            'key'          => $k,
            'label'        => $m[$k][0],
            'status'       => $m[$k][1],
            'source_table' => $m[$k][2],
            'unit'         => $m[$k][3],
            'direction'    => $m[$k][4],
        );
    }

    /** Every KPI, optionally re-stated against which source tables actually exist. */
    public static function all($existingTables = null)
    {
        $out = array();
        foreach (self::keys() as $k) {
            $out[$k] = $existingTables === null ? self::get($k) : self::resolve($k, $existingTables);
        }
        return $out;
    }

    /**
     * Downgrade a KPI whose declared source table is absent on this install.
     * Declaring a source is a claim; this is where the claim is checked.
     */
    public static function resolve($key, $existingTables)
    {
        $kpi = self::get($key);
        if ($kpi === null) { return null; }

        if ($kpi['source_table'] !== null) {
            $have = array_map('strtolower', (array) $existingTables);
            if (!in_array(strtolower($kpi['source_table']), $have, true)) {
                $kpi['status'] = self::SOURCE_MISSING;
                $kpi['note']   = 'Declared source table "' . $kpi['source_table']
                               . '" does not exist on this install.';
            }
        }
        return $kpi;
    }

    /** Can this KPI produce a number at all? */
    public static function isMeasurable($key, $existingTables = null)
    {
        $kpi = $existingTables === null ? self::get($key) : self::resolve($key, $existingTables);
        if ($kpi === null) { return false; }
        return in_array($kpi['status'], array(self::IMPLEMENTED, self::MANUAL), true);
    }

    /** Why a KPI cannot be measured, in words a manager can act on. */
    public static function unmeasurableReason($key, $existingTables = null)
    {
        $kpi = $existingTables === null ? self::get($key) : self::resolve($key, $existingTables);
        if ($kpi === null) { return 'Unknown KPI "' . (string) $key . '".'; }

        switch ($kpi['status']) {
            case self::SOURCE_PENDING:
                return $kpi['label'] . ' has a data source (' . $kpi['source_table']
                     . ') but no calculator has been written yet, so it cannot be scored.';
            case self::NO_NATIVE_SOURCE:
                return $kpi['label'] . ' is not recorded anywhere in this system, so it cannot be '
                     . 'calculated. Track it manually or integrate a source first.';
            case self::SOURCE_MISSING:
                return isset($kpi['note']) ? $kpi['note'] : ($kpi['label'] . ' has no available source.');
            default:
                return '';
        }
    }

    public static function isCurrency($key)
    {
        $kpi = self::get($key);
        return $kpi !== null && $kpi['unit'] === 'currency';
    }

    /** KPIs safe to offer as the default choices in a target form. */
    public static function measurableKeys($existingTables = null)
    {
        $out = array();
        foreach (self::keys() as $k) {
            if (self::isMeasurable($k, $existingTables)) { $out[] = $k; }
        }
        return $out;
    }

    public static function statusClass($status)
    {
        switch ((string) $status) {
            case self::IMPLEMENTED:      return 'success';
            case self::MANUAL:           return 'info';
            case self::SOURCE_PENDING:   return 'warning';
            case self::NO_NATIVE_SOURCE:
            case self::SOURCE_MISSING:   return 'danger';
            default:                     return 'default';
        }
    }
}
