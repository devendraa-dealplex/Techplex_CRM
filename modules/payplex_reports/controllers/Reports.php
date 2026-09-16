<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/../libraries/Payplex_report_calc.php';
require_once __DIR__ . '/../libraries/Payplex_report_definitions.php';

/**
 * Read-only analytics. Nothing is written.
 *
 * Three classes of defect were fixed here; each is described at the method that
 * carried it.
 *
 *  1. Conversions were counted by pipeline status, via a fuzzy name match with
 *     a fallback that matched no rows. On this install that made every
 *     conversion figure read zero when six of thirteen leads had converted.
 *  2. "Commission Liability" added money already paid, and money clawed back,
 *     into a single total labelled as owed.
 *  3. Agent Performance showed every colleague's leads, conversions, call
 *     volume and spend to anyone holding the plain 'view' capability.
 */
class Reports extends AdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    private function guard()
    {
        if (!(is_admin() || staff_can('view', 'payplex_reports'))) {
            access_denied('payplex_reports');
        }
    }

    /** May this viewer see other people's rows? */
    private function canViewAll()
    {
        return is_admin() || staff_can('view_all', 'payplex_reports');
    }

    /* ---------------- Lead funnel ---------------- */
    public function funnel()
    {
        $this->guard();
        $p = db_prefix();
        $L = $p . 'leads';
        list($from, $to) = $this->dateRange();

        /*
         * Every count below is bound by the period the page prints. It was not:
         * see DEFECT 3 in Payplex_report_definitions. On this install a
         * September filter showed "13 leads, 1 converted" when 7 leads were
         * created in September.
         */
        $narrowed = Payplex_report_definitions::windowIsNarrowed($from, $to);
        $rate     = Payplex_report_definitions::rateComparability($narrowed);
        $vol      = Payplex_report_definitions::leadVolumePredicate($p);
        $b        = array($from, $to);

        $total     = (int) $this->db->query("SELECT COUNT(*) c FROM {$L} WHERE {$vol}", $b)->row()->c;
        $contacted = (int) $this->db->query("SELECT COUNT(*) c FROM {$L} WHERE lastcontact IS NOT NULL AND {$vol}", $b)->row()->c;
        $lost      = (int) $this->db->query("SELECT COUNT(*) c FROM {$L} WHERE lost = 1 AND {$vol}", $b)->row()->c;

        $conv = $this->convertedCount($from, $to);

        $stages = array(
            array('label' => 'Total leads', 'count' => $total),
            array('label' => 'Contacted',   'count' => $contacted),
        );
        /*
         * Over a narrowed period the converted count is a different cohort from
         * the two above, so it is reported beside the funnel rather than as a
         * bar whose percentage would be a ratio across two different sets.
         */
        if ($conv['available'] && $rate['comparable']) {
            $stages[] = array('label' => 'Converted', 'count' => $conv['count']);
        }

        $data = array(
            'title'  => 'Lead Funnel',
            'report' => 'funnel',
            'stages' => Payplex_report_calc::funnel($stages),
            'lost'   => $lost,
            'conversion' => $conv,
            'rate'   => $rate,
            'converted_aside' => ($conv['available'] && !$rate['comparable']) ? (int) $conv['count'] : null,
            'export_query' => Payplex_report_definitions::exportQuery($this->input->get()),
            'meta'   => Payplex_report_definitions::metadata(array(
                'date_from'   => $from,
                'date_to'     => $to,
                'filters'     => $narrowed ? 'Leads created between the dates above' : 'None',
                'data_source' => db_prefix() . 'leads, ' . db_prefix() . 'clients',
                'definition'  => Payplex_report_definitions::conversionDefinition(),
                'generated_at'=> date('Y-m-d H:i:s'),
                'row_count'   => count($stages),
            )),
        );
        $this->load->view('payplex_reports/funnel', $data);
    }

    /* ---------------- Source performance ---------------- */
    public function source_performance()
    {
        $this->guard();
        list($from, $to) = $this->dateRange();
        $p = db_prefix();

        $narrowed = Payplex_report_definitions::windowIsNarrowed($from, $to);
        $rate     = Payplex_report_definitions::rateComparability($narrowed);
        $vol      = Payplex_report_definitions::leadVolumePredicate($p);

        $rows = $this->db->query(
            "SELECT COALESCE(s.name,'(unknown)') AS source, COUNT({$p}leads.id) AS leads
             FROM {$p}leads
             LEFT JOIN {$p}leads_sources s ON s.id = {$p}leads.source
             WHERE {$vol}
             GROUP BY {$p}leads.source ORDER BY leads DESC",
            array($from, $to)
        )->result_array();

        $conv = $this->convertedBy('source', $from, $to);
        foreach ($rows as &$r) {
            // A source absent from the counts converted NONE — that is a zero,
            // not missing data. Only an unavailable definition yields null.
            $r['converted'] = self::countFor($conv, $r['source']);
            $r['conv_pct'] = ($r['converted'] === null || !$rate['comparable'])
                ? null : Payplex_report_calc::pct($r['converted'], $r['leads']);
        }
        unset($r);

        $this->maybeExport('source_performance', array('Source', 'Leads', 'Converted', 'Conv %'),
            array_map(function ($r) {
                return array($r['source'], $r['leads'],
                    $r['converted'] === null ? 'unavailable' : $r['converted'],
                    Payplex_report_calc::pctLabel($r['conv_pct']));
            }, $rows));

        $this->load->view('payplex_reports/source_performance', array(
            'title' => 'Source Performance', 'rows' => $rows, 'report' => 'source_performance',
            'conversion' => $conv,
            'rate' => $rate,
            'export_query' => Payplex_report_definitions::exportQuery($this->input->get()),
            'meta' => Payplex_report_definitions::metadata(array(
                'date_from' => $from, 'date_to' => $to,
                'filters' => $narrowed ? 'Leads created between the dates above' : 'None',
                'data_source' => $p . 'leads, ' . $p . 'leads_sources, ' . $p . 'clients',
                'definition' => Payplex_report_definitions::conversionDefinition(),
                'generated_at' => date('Y-m-d H:i:s'), 'row_count' => count($rows),
            )),
        ));
    }

    /* ---------------- Agent performance ---------------- */
    public function agent_performance()
    {
        $this->guard();
        list($from, $to) = $this->dateRange();
        $p = db_prefix();

        /*
         * §5, no cross-role data leakage. This report is an employee ranking:
         * leads, conversions, call volume and spend, per colleague. It used to
         * be visible to anyone holding the plain 'view' capability, so a
         * salesperson could read the whole team's numbers. Rows are now scoped
         * to the viewer unless they hold 'view_all'.
         */
        $narrowed = Payplex_report_definitions::windowIsNarrowed($from, $to);
        $rate     = Payplex_report_definitions::rateComparability($narrowed);
        $joinVol  = Payplex_report_definitions::leadVolumePredicateFor('l');

        /*
         * The calls columns read a table that belongs to the AI calling module.
         * Commission Liability already checks for its table before querying it;
         * this did not, so on an install without that module the whole report
         * died instead of saying which column it could not fill.
         */
        $hasCalls = $this->db->table_exists($p . 'payplex_calls');
        if ($hasCalls) {
            $callCols = "(SELECT COUNT(*) FROM {$p}payplex_calls c
                           WHERE c.staff_id = st.staffid AND DATE(c.created_at) BETWEEN ? AND ?) AS calls,
                         (SELECT COALESCE(SUM(cost),0) FROM {$p}payplex_calls c
                           WHERE c.staff_id = st.staffid AND DATE(c.created_at) BETWEEN ? AND ?) AS call_cost,";
            $binds = array($from, $to, $from, $to, $from, $to);
        } else {
            $callCols = "NULL AS calls, NULL AS call_cost,";
            $binds = array($from, $to);
        }

        $rows = $this->db->query(
            "SELECT st.staffid, CONCAT(st.firstname,' ',st.lastname) AS name,
                    {$callCols}
                    COUNT(DISTINCT l.id) AS leads
             FROM {$p}staff st
             LEFT JOIN {$p}leads l ON l.assigned = st.staffid AND {$joinVol}
             WHERE st.active = 1
             GROUP BY st.staffid ORDER BY leads DESC",
            $binds
        )->result_array();

        $canAll = $this->canViewAll();
        $rows = Payplex_report_definitions::scopeRows($rows, get_staff_user_id(), $canAll, 'staffid');

        $conv = $this->convertedBy('assigned', $from, $to);
        foreach ($rows as &$r) {
            $r['converted'] = self::countFor($conv, $r['staffid']);
            $r['conv_pct'] = ($r['converted'] === null || !$rate['comparable'])
                ? null : Payplex_report_calc::pct($r['converted'], $r['leads']);
        }
        unset($r);

        $this->maybeExport('agent_performance',
            array('Agent', 'Leads', 'Converted', 'Conv %', 'Calls', 'Call cost'),
            array_map(function ($r) {
                return array($r['name'], (int) $r['leads'],
                    $r['converted'] === null ? 'unavailable' : $r['converted'],
                    Payplex_report_calc::pctLabel($r['conv_pct']),
                    $r['calls'] === null ? 'unavailable' : (int) $r['calls'],
                    $r['call_cost'] === null ? 'unavailable' : (float) $r['call_cost']);
            }, $rows));

        $this->load->view('payplex_reports/agent_performance', array(
            'title' => 'Agent Performance', 'rows' => $rows, 'report' => 'agent_performance',
            'conversion' => $conv,
            'rate' => $rate,
            'has_calls' => $hasCalls,
            'export_query' => Payplex_report_definitions::exportQuery($this->input->get()),
            'scoped_to_self' => !$canAll,
            'currency' => $this->callCurrency(),
            'meta' => Payplex_report_definitions::metadata(array(
                'date_from' => $from, 'date_to' => $to,
                'filters' => ($canAll ? 'All active staff' : 'Your own row only')
                           . ($narrowed ? '; leads and calls within the dates above' : ''),
                'data_source' => $p . 'staff, ' . $p . 'leads, ' . $p . 'clients, ' . $p . 'payplex_calls',
                'definition' => Payplex_report_definitions::conversionDefinition(),
                'generated_at' => date('Y-m-d H:i:s'), 'row_count' => count($rows),
            )),
        ));
    }

    /* ---------------- AI call cost ---------------- */
    public function call_cost()
    {
        $this->guard();
        $p = db_prefix();

        $hasCalls = $this->db->table_exists($p . 'payplex_calls');
        $rows = array();
        if ($hasCalls) {
            // Spend is per-staff data; without view_all a viewer sees only their own.
            $this->db->select("DATE_FORMAT(created_at,'%Y-%m-%d') AS d, COUNT(*) AS calls,
                               SUM(status='completed') AS completed, COALESCE(SUM(cost),0) AS cost", false);
            if (!$this->canViewAll()) {
                $this->db->where('staff_id', get_staff_user_id());
            }
            $rows = $this->db->group_by('d')->order_by('d', 'DESC')->limit(30)
                ->get($p . 'payplex_calls')->result_array();
        }

        $this->maybeExport('call_cost', array('Date', 'Calls', 'Completed', 'Cost'),
            array_map(function ($r) {
                return array($r['d'], (int) $r['calls'], (int) $r['completed'], (float) $r['cost']);
            }, $rows));

        $this->load->view('payplex_reports/call_cost', array(
            'title' => 'AI Call Cost', 'rows' => $rows, 'report' => 'call_cost',
            'currency' => $this->callCurrency(),
            'has_calls' => $hasCalls,
            'export_query' => Payplex_report_definitions::exportQuery($this->input->get()),
            'scoped_to_self' => !$this->canViewAll(),
            'meta' => Payplex_report_definitions::metadata(array(
                'filters' => $hasCalls
                    ? ($this->canViewAll() ? 'Last 30 days with calls' : 'Your own calls only')
                    : 'The AI calling module is not installed on this system',
                'data_source' => $p . 'payplex_calls',
                'definition' => 'Cost is the amount reported by the calling backend per call. '
                              . 'It is spend, not revenue, and is never netted against any income figure.',
                'generated_at' => date('Y-m-d H:i:s'), 'row_count' => count($rows),
            )),
        ));
    }

    /* ---------------- Commission: payable, paid and clawed back ---------------- */
    public function commission_liability()
    {
        $this->guard();
        $t = db_prefix() . 'payplex_commission_statements';
        $hasModule = $this->db->table_exists($t);
        $rows = array();

        if ($hasModule) {
            /*
             * This used to be one SELECT summing net_amount across every status
             * except 'superseded', displayed under a single column called "Net
             * amount" on a page titled "Commission Liability". Commission that
             * has already been PAID is not a liability, and commission REVERSED
             * by a clawback is not one either. §6 requires commission payable,
             * commission paid and clawbacks to be separate figures. They are now
             * bucketed, and only the payable bucket is totalled as liability.
             */
            $rows = $this->db->query(
                "SELECT status, COUNT(*) AS cnt, COALESCE(SUM(net_amount),0) AS net
                 FROM {$t} GROUP BY status"
            )->result_array();
        }

        $summary = Payplex_report_definitions::commissionSummary($rows);

        $exportRows = array();
        foreach ($summary['buckets'] as $bucket => $b) {
            if ($b['count'] === 0) { continue; }
            $exportRows[] = array(
                Payplex_report_definitions::bucketLabels()[$bucket],
                implode(', ', array_unique($b['statuses'])),
                $b['count'], round($b['amount'], 2),
            );
        }
        $this->maybeExport('commission_liability',
            array('Category', 'Statuses included', 'Count', 'Amount'), $exportRows);

        $this->load->view('payplex_reports/commission_liability', array(
            'title' => 'Commission: Payable, Paid and Clawed Back',
            'report' => 'commission_liability',
            'summary' => $summary,
            'export_query' => Payplex_report_definitions::exportQuery($this->input->get()),
            'labels' => Payplex_report_definitions::bucketLabels(),
            'money_definitions' => Payplex_report_definitions::moneyDefinitions(),
            'has_module' => $hasModule,
            'currency' => get_option('payplex_commission_currency') ?: $this->baseCurrency(),
            'meta' => Payplex_report_definitions::metadata(array(
                'filters' => 'All statements, grouped by what the money actually is',
                'data_source' => $t,
                'definition' => 'Liability counts only statements still owed. Paid and clawed-back '
                              . 'amounts are shown beside it and are never added to it.',
                'generated_at' => date('Y-m-d H:i:s'), 'row_count' => count($rows),
            )),
        ));
    }

    public function index()
    {
        $this->funnel();
    }

    /* ---------------- conversion counting ---------------- */

    /**
     * Conversions for one group key.
     *
     * The distinction this exists to keep straight, and which the two callers
     * originally got right and wrong respectively: a key MISSING from the
     * counts converted zero, which is a number. Only an unavailable definition
     * means "we cannot say", which is null and renders as a dash. Reporting a
     * genuine zero as "no data" hides a real result; reporting "no data" as a
     * zero invents one.
     */
    private static function countFor($conv, $key)
    {
        if (empty($conv['available'])) { return null; }
        return isset($conv['counts'][$key]) ? (int) $conv['counts'][$key] : 0;
    }

    /**
     * Where the canonical definition lives. Loaded rather than copied: this
     * exact rule was re-implemented here once already, and got it wrong.
     */
    private function conversionLibrary()
    {
        static $loaded = null;
        if ($loaded !== null) { return $loaded; }

        $path = Payplex_report_definitions::conversionLibraryPath(FCPATH . 'modules');
        if (file_exists($path)) {
            require_once $path;
            $loaded = class_exists('Kpi_lead_conversion');
        } else {
            $loaded = false;
        }
        return $loaded;
    }

    /** Columns actually present on tblleads, so an exclusion is never assumed. */
    private function leadColumns()
    {
        static $cols = null;
        if ($cols === null) { $cols = $this->db->list_fields(db_prefix() . 'leads'); }
        return $cols;
    }

    /**
     * Total converted leads in the window, or an explanation of why not.
     */
    private function convertedCount($from, $to)
    {
        $avail = Payplex_report_definitions::conversionAvailability($this->conversionLibrary());
        if (!$avail['available']) {
            return array('available' => false, 'count' => null, 'reason' => $avail['reason']);
        }

        $p = db_prefix();
        $pred = Payplex_report_definitions::convertedLeadPredicate($p, $this->leadColumns());
        $sql = "SELECT COUNT(DISTINCT {$p}leads.id) AS c FROM {$p}leads WHERE " . $pred['where'];

        $row = $this->db->query($sql, array($from, $to))->row_array();
        return array('available' => true, 'count' => (int) $row['c'], 'reason' => '',
                     'notes' => $pred['notes']);
    }

    /**
     * Converted leads grouped by a column (source name, or assigned staff id).
     */
    private function convertedBy($by, $from, $to)
    {
        $avail = Payplex_report_definitions::conversionAvailability($this->conversionLibrary());
        if (!$avail['available']) {
            return array('available' => false, 'counts' => array(), 'reason' => $avail['reason']);
        }

        $p = db_prefix();
        $pred = Payplex_report_definitions::convertedLeadPredicate($p, $this->leadColumns());

        if ($by === 'source') {
            $sql = "SELECT COALESCE(s.name,'(unknown)') AS k, COUNT(DISTINCT {$p}leads.id) AS c
                    FROM {$p}leads
                    LEFT JOIN {$p}leads_sources s ON s.id = {$p}leads.source
                    WHERE " . $pred['where'] . " GROUP BY k";
        } else {
            // Attribution matches the Sales Targets library: the assignee owns
            // the conversion, falling back to the creator when unassigned.
            $sql = "SELECT CASE WHEN {$p}leads.assigned IS NULL OR {$p}leads.assigned = 0
                                THEN {$p}leads.addedfrom ELSE {$p}leads.assigned END AS k,
                           COUNT(DISTINCT {$p}leads.id) AS c
                    FROM {$p}leads WHERE " . $pred['where'] . " GROUP BY k";
        }

        $counts = array();
        foreach ($this->db->query($sql, array($from, $to))->result_array() as $r) {
            $counts[$r['k']] = (int) $r['c'];
        }
        return array('available' => true, 'counts' => $counts, 'reason' => '',
                     'notes' => $pred['notes']);
    }

    /* ---------------- helpers ---------------- */

    /**
     * §6 requires every report to state its date range. Defaults to a window
     * wide enough to mean "all time" while keeping the query bounded.
     */
    private function dateRange()
    {
        $from = trim((string) $this->input->get('date_from'));
        $to   = trim((string) $this->input->get('date_to'));

        $valid = function ($d) { return $d !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
        if (!$valid($from)) { $from = '1970-01-01'; }
        if (!$valid($to))   { $to   = date('Y-m-d'); }
        if ($from > $to)    { $tmp = $from; $from = $to; $to = $tmp; }

        return array($from, $to);
    }

    /** Perfex's configured base currency, rather than a guess. */
    private function baseCurrency()
    {
        $row = $this->db->where('isdefault', 1)->get(db_prefix() . 'currencies')->row();
        return $row ? $row->name : '';
    }

    /**
     * Currency for call spend.
     *
     * This defaulted to 'USD', so an install billing in INR displayed its call
     * spend labelled as dollars. There is no such option registered anywhere in
     * the calling module, so the default was what every install actually saw.
     */
    private function callCurrency()
    {
        $c = trim((string) get_option('payplex_aicalling_currency'));
        return $c !== '' ? $c : $this->baseCurrency();
    }

    private function maybeExport($name, array $header, array $rows)
    {
        if ($this->input->get('export') !== 'csv') {
            return;
        }
        if (!(is_admin() || staff_can('export', 'payplex_reports'))) {
            access_denied('payplex_reports');
        }
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="payplex_' . $name . '_' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, $header);
        foreach ($rows as $r) {
            fputcsv($out, array_map(array($this, 'csvSafe'), $r));
        }
        fclose($out);
        exit;
    }

    /**
     * Neutralise spreadsheet formula injection. A lead source or staff name
     * beginning =, +, - or @ is executed as a formula when the CSV is opened.
     */
    private function csvSafe($v)
    {
        $s = (string) $v;
        return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
    }
}
