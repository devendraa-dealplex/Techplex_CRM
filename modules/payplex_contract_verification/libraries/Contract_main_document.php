<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/fpdi/autoload.php';

/**
 * The PDF a staff member uploaded in the contract's upload-document field,
 * treated as THE contract: signers see its real pages, and each signature is
 * stamped onto the page where its field was placed.
 */
class Contract_main_document
{
    /**
     * Absolute path of the uploaded PDF that is this contract's main document,
     * or null. The upload helpers store a download link to the newest upload
     * in the contract's description (or content), so that link identifies it.
     *
     * @param  object|array $contract row with id, description, content
     * @return string|null
     */
    public static function path($contract)
    {
        $contract = (object) $contract;

        if (empty($contract->id)) { return null; }

        if (!isset($contract->description) && !isset($contract->content)) {
            $row = get_instance()->db->select('description, content')->where('id', (int) $contract->id)
                                     ->get(db_prefix() . 'contracts')->row();

            if ($row) { $contract->description = $row->description; $contract->content = $row->content; }
        }

        $haystack = (string) (isset($contract->description) ? $contract->description : '') . ' '
                  . (string) (isset($contract->content) ? $contract->content : '');

        if (!preg_match_all('#download/file/contract/([A-Za-z0-9]+)#', $haystack, $m) || empty($m[1])) {
            return null;
        }

        $ci = &get_instance();

        foreach (array_reverse($m[1]) as $key) {
            $file = $ci->db->where('attachment_key', $key)
                           ->where('rel_type', 'contract')
                           ->where('rel_id', (int) $contract->id)
                           ->get(db_prefix() . 'files')->row();

            if (!$file) { continue; }

            $isPdf = strtolower(pathinfo((string) $file->file_name, PATHINFO_EXTENSION)) === 'pdf';
            $path  = get_upload_path_by_type('contract') . (int) $contract->id . '/' . $file->file_name;

            if ($isPdf && is_file($path)) { return $path; }
        }

        return null;
    }

    /**
     * Stamp signatures onto the uploaded PDF.
     *
     * @param  string $path    the uploaded PDF
     * @param  array  $signers completed signers: id, reference, full_name, role, completed_at, image (abs path)
     * @param  array  $fields  approved fields: page_number, x, y, width, height, editor_scale, field_type, signer_reference
     * @return \setasign\Fpdi\Tcpdf\Fpdi|null  null if the PDF cannot be read
     */
    public static function render($path, array $signers, array $fields)
    {
        try {
            $pdf = new \setasign\Fpdi\Tcpdf\Fpdi('P', 'pt');
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(0, 0, 0);
            $pdf->SetAutoPageBreak(false, 0);

            $byRef = array();
            foreach ($signers as $s) { $byRef[(string) $s['reference']] = $s; }

            $signatureRefs = array();
            $count         = $pdf->setSourceFile($path);

            for ($p = 1; $p <= $count; $p++) {
                $tpl  = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);

                $pdf->AddPage($size['orientation'], array($size['width'], $size['height']));
                $pdf->useTemplate($tpl);

                foreach ($fields as $f) {
                    if ((int) $f['page_number'] !== $p) { continue; }

                    $ref = (string) $f['signer_reference'];
                    if ($ref === '' || !isset($byRef[$ref])) { continue; }

                    $s     = $byRef[$ref];
                    $scale = (float) $f['editor_scale'] > 0 ? (float) $f['editor_scale'] : 1.0;
                    $x = (float) $f['x'] / $scale;
                    $y = (float) $f['y'] / $scale;
                    $w = (float) $f['width'] / $scale;
                    $h = (float) $f['height'] / $scale;

                    switch ((string) $f['field_type']) {
                        case 'signature':
                            $pdf->Image($s['image'], $x, $y, $w, $h, 'PNG', '', '', false, 300, '', false, false, 0, 'CM');
                            $signatureRefs[$ref] = true;
                            break;
                        case 'date':
                            self::text($pdf, date('d M Y', (int) $s['completed_at']), $x, $y, $w, $h);
                            break;
                        case 'name':
                            self::text($pdf, (string) $s['full_name'], $x, $y, $w, $h);
                            break;
                        case 'initials':
                            $ini = '';
                            foreach (preg_split('/\s+/', trim((string) $s['full_name'])) as $part) {
                                $ini .= mb_strtoupper(mb_substr($part, 0, 1));
                            }
                            self::text($pdf, $ini, $x, $y, $w, $h);
                            break;
                    }
                }
            }

            $unplaced = array();
            foreach ($signers as $s) {
                if (empty($signatureRefs[(string) $s['reference']])) { $unplaced[] = $s; }
            }

            if ($unplaced) {
                $pdf->AddPage('P', 'A4');
                $pdf->SetFont('helvetica', 'B', 14);
                $pdf->SetXY(40, 40);
                $pdf->Cell(0, 20, 'Signatures', 0, 1);

                $y = 80;
                foreach ($unplaced as $s) {
                    $pdf->SetFont('helvetica', '', 10);
                    $pdf->SetXY(40, $y);
                    $pdf->Cell(0, 14, (string) $s['full_name'] . ' (' . ucfirst((string) $s['role']) . ') - '
                        . date('d M Y H:i', (int) $s['completed_at']), 0, 1);
                    $pdf->Image($s['image'], 40, $y + 16, 160, 60, 'PNG', '', '', false, 300, '', false, false, 0, 'LM');
                    $y += 100;
                }
            }

            return $pdf;
        } catch (\Throwable $e) {
            log_activity('Contract PDF stamping failed, falling back to the standard document: ' . $e->getMessage());

            return null;
        }
    }

    private static function text($pdf, $text, $x, $y, $w, $h)
    {
        $pdf->SetFont('helvetica', '', max(6, min(12, $h * 0.6)));
        $pdf->SetXY($x, $y);
        $pdf->Cell($w, $h, $text, 0, 0, 'L', false, '', 1, false, 'T', 'M');
    }
}
