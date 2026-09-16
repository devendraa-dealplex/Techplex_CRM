<?php

defined('BASEPATH') or defined('PAYPLEX_CV_TEST') or exit('No direct script access allowed');

/**
 * Contract_field_mapper
 *
 * Translating a signature box drawn in a browser into a rectangle on a PDF page.
 *
 * WHY THIS IS ITS OWN LIBRARY AND HEAVILY TESTED
 * ----------------------------------------------
 * This is the part of an e-signature integration that goes wrong quietly. A
 * field twelve points out of place on A4 is invisible while you are testing
 * with a one-page fixture, and lands across a clause on a real contract. There
 * is no error, no log line and no failed request — just a signature in the
 * wrong place on an executed agreement.
 *
 * Four transforms have to be applied, in order, and each of them has a failure
 * mode that looks like success:
 *
 *   1. ORIGIN. PDF space has its origin at the BOTTOM-LEFT with y increasing
 *      upward. Every browser editor is top-left with y increasing downward. The
 *      flip also has to account for the box's own height, because an editor
 *      gives the top edge and PDF wants the bottom edge. Get it wrong and page
 *      one looks obviously broken; get it half wrong — forget the height — and
 *      it looks fine until a tall field is used.
 *
 *   2. PAGE BOX. Use CropBox where present, MediaBox otherwise. They differ on
 *      output from several common generators, and a non-zero box origin offsets
 *      every field on the page by a constant nobody notices until they compare
 *      two documents.
 *
 *   3. ROTATION. /Rotate may be 0, 90, 180 or 270. At 90 and 270 the page's
 *      effective width and height swap, so a field positioned against the
 *      unrotated box is not merely rotated but off the page.
 *
 *   4. SCALE. The editor renders at some display scale; coordinates divide by
 *      it and are expressed in PDF points (1/72"). /UserUnit, where a document
 *      declares one, multiplies again.
 *
 * The output of this class is verified by measuring rendered PDFs, not by
 * asserting the numbers it produced — a test that checks the transform against
 * its own arithmetic proves only that arithmetic is deterministic.
 *
 * Pure: no PDF library, no file access, no I/O. It transforms numbers. The page
 * geometry is supplied by whatever reads the document.
 */
class Contract_field_mapper
{
    /* ---- field types --------------------------------------------------- */

    const F_SIGNATURE = 'signature';
    const F_INITIALS  = 'initials';
    const F_DATE      = 'date';
    const F_NAME      = 'name';
    const F_TEXT      = 'text';
    const F_STAMP     = 'stamp';

    /** A PDF point is 1/72 inch. Every output of this class is in points. */
    const UNIT = 'pt';

    /** Below this, a field cannot be hit on a touchscreen or seen on paper. */
    const MIN_WIDTH_PT  = 18.0;
    const MIN_HEIGHT_PT = 9.0;

    /**
     * The field types this module supports, and whether each is signer-bound.
     *
     * @return array
     */
    public static function fieldTypes()
    {
        return array(
            self::F_SIGNATURE => array('label' => 'Signature', 'signer_bound' => true),
            self::F_INITIALS  => array('label' => 'Initials',  'signer_bound' => true),
            self::F_DATE      => array('label' => 'Date',      'signer_bound' => true),
            self::F_NAME      => array('label' => 'Full name', 'signer_bound' => true),
            self::F_TEXT      => array('label' => 'Text',      'signer_bound' => true),
            self::F_STAMP     => array('label' => 'Stamp',     'signer_bound' => false),
        );
    }

    /**
     * Everything stored for one field. Versioned with the contract, on purpose.
     *
     * A field is meaningless against a different PDF. If the contract is
     * regenerated the fields are invalidated rather than carried across —
     * carrying them across is how a signature block ends up over a clause that
     * moved down half a page.
     *
     * @return array
     */
    public static function storedFields()
    {
        return array(
            'contract_version',   // which PDF these coordinates belong to
            'page_number',        // 1-based, as a person counts pages
            'x', 'y',             // editor space at capture time
            'width', 'height',
            'field_type',
            'signer_reference',   // opaque; never a database id
            'is_required',
            'signing_order',
        );
    }

    /* ---- page geometry ------------------------------------------------- */

    /**
     * Normalise a page's geometry into the four numbers the transform needs.
     *
     * CropBox wins where present. Both boxes are [x0, y0, x1, y1] and are NOT
     * guaranteed to start at the origin, nor to be ordered — a box may be given
     * with its corners the other way round, which is legal and which a naive
     * subtraction turns into a negative width.
     *
     * @param  array $page {media_box, crop_box, rotate, user_unit}
     * @return array {ok, x0, y0, width, height, rotate, user_unit, reason}
     */
    public static function pageGeometry(array $page)
    {
        $box = isset($page['crop_box']) && self::isBox($page['crop_box'])
             ? $page['crop_box']
             : (isset($page['media_box']) && self::isBox($page['media_box']) ? $page['media_box'] : null);

        if ($box === null) {
            return self::geomBad('no_usable_page_box');
        }

        /* Corners may arrive in either order. Normalise rather than assume. */
        $x0 = min((float) $box[0], (float) $box[2]);
        $y0 = min((float) $box[1], (float) $box[3]);
        $x1 = max((float) $box[0], (float) $box[2]);
        $y1 = max((float) $box[1], (float) $box[3]);

        $w = $x1 - $x0;
        $h = $y1 - $y0;

        if ($w <= 0 || $h <= 0) {
            return self::geomBad('degenerate_page_box');
        }

        $rotate = isset($page['rotate']) ? (int) $page['rotate'] : 0;

        /* /Rotate is a multiple of 90 and may legally be negative or > 360. */
        $rotate = ((($rotate % 360) + 360) % 360);

        if (!in_array($rotate, array(0, 90, 180, 270), true)) {
            return self::geomBad('rotation_not_a_right_angle');
        }

        $userUnit = isset($page['user_unit']) ? (float) $page['user_unit'] : 1.0;

        if (!($userUnit > 0)) {
            return self::geomBad('invalid_user_unit');
        }

        return array('ok' => true, 'reason' => null,
                     'x0' => $x0, 'y0' => $y0, 'width' => $w, 'height' => $h,
                     'rotate' => $rotate, 'user_unit' => $userUnit);
    }

    /**
     * The page size a VIEWER shows, which is what the editor drew against.
     *
     * At 90 and 270 degrees width and height swap. This is the number the
     * editor's canvas matched, so it is the number the inverse transform must
     * start from.
     *
     * @param  array $geom result of pageGeometry()
     * @return array {width, height}
     */
    public static function displaySize(array $geom)
    {
        $swap = ($geom['rotate'] === 90 || $geom['rotate'] === 270);

        return array(
            'width'  => $swap ? $geom['height'] : $geom['width'],
            'height' => $swap ? $geom['width']  : $geom['height'],
        );
    }

    /* ---- the transform ------------------------------------------------- */

    /**
     * Editor rectangle to PDF rectangle.
     *
     * Input is the editor's top-left origin, y down, at whatever scale the
     * editor was rendering. Output is PDF user space: bottom-left origin, y up,
     * in points, relative to the page box, with rotation undone.
     *
     * Returns the rectangle as both a bottom-left+size pair and the four
     * absolute coordinates, because providers ask for it both ways and doing
     * the arithmetic twice at two call sites is how they drift apart.
     *
     * @param  array $field {x, y, width, height}  editor space
     * @param  array $geom  result of pageGeometry()
     * @param  float $scale editor display scale (1.0 = 72dpi points)
     * @return array {ok, reason, x, y, width, height, x0, y0, x1, y1, unit}
     */
    public static function toPdfRect(array $field, array $geom, $scale = 1.0)
    {
        if (empty($geom['ok'])) {
            return self::rectBad(isset($geom['reason']) ? $geom['reason'] : 'bad_geometry');
        }

        foreach (array('x', 'y', 'width', 'height') as $k) {
            if (!isset($field[$k]) || !is_numeric($field[$k])) {
                return self::rectBad('field_missing_' . $k);
            }
        }

        $scale = (float) $scale;

        if (!($scale > 0)) {
            return self::rectBad('invalid_scale');
        }

        /* 4. SCALE — editor pixels to points, then the document's own unit. */
        $u  = (float) $geom['user_unit'];
        $ex = ((float) $field['x']) / $scale / $u;
        $ey = ((float) $field['y']) / $scale / $u;
        $ew = ((float) $field['width']) / $scale / $u;
        $eh = ((float) $field['height']) / $scale / $u;

        if ($ew < self::MIN_WIDTH_PT || $eh < self::MIN_HEIGHT_PT) {
            return self::rectBad('field_too_small_to_be_usable');
        }

        $disp = self::displaySize($geom);

        /* 1. ORIGIN — flip y, accounting for the box's own height. The editor
              gives the TOP edge; PDF wants the BOTTOM edge. */
        $dx = $ex;
        $dy = $disp['height'] - $ey - $eh;

        /* 3. ROTATION — undo it, mapping display space back to unrotated page
              space. Worked out per angle rather than with a matrix, because
              four explicit cases are checkable by hand and a matrix is not. */
        switch ($geom['rotate']) {
            /*
             * The sense of the rotation is the thing that is easy to get
             * backwards, and getting it backwards puts every signature on a
             * rotated page in the diagonally opposite corner — which renders
             * without complaint and is only visible by looking at the output.
             *
             * /Rotate is the number of degrees the page is turned CLOCKWISE
             * when displayed. Turn a portrait sheet clockwise and its top edge
             * swings to the RIGHT and its left edge to the TOP, so the forward
             * transform at 90 is X = y, Y = width - x (display H wide, W tall).
             * Inverting that pair gives x = width - Y, y = X: the display's
             * top-left corner is the page's BOTTOM-left.
             *
             * These two branches were transposed when first written. The proof
             * matrix in ContractFieldMapperTest derives the forward transform
             * from that one sentence and inverts it independently, so the
             * arithmetic below is checked against something other than itself.
             */
            case 90:
                /* Display Y runs leftward across the page; display X runs up it. */
                $px = $geom['width'] - $dy - $eh;
                $py = $dx;
                $pw = $eh;
                $ph = $ew;
                break;

            case 180:
                $px = $geom['width']  - $dx - $ew;
                $py = $geom['height'] - $dy - $eh;
                $pw = $ew;
                $ph = $eh;
                break;

            case 270:
                /* The mirror of 90: display Y runs rightward, display X down. */
                $px = $dy;
                $py = $geom['height'] - $dx - $ew;
                $pw = $eh;
                $ph = $ew;
                break;

            default: /* 0 */
                $px = $dx;
                $py = $dy;
                $pw = $ew;
                $ph = $eh;
                break;
        }

        /* 2. PAGE BOX — everything above is relative to the box; shift to
              absolute user space. */
        $px += (float) $geom['x0'];
        $py += (float) $geom['y0'];

        return array(
            'ok'     => true,
            'reason' => null,
            'x'      => self::pt($px),
            'y'      => self::pt($py),
            'width'  => self::pt($pw),
            'height' => self::pt($ph),
            'x0'     => self::pt($px),
            'y0'     => self::pt($py),
            'x1'     => self::pt($px + $pw),
            'y1'     => self::pt($py + $ph),
            'unit'   => self::UNIT,
        );
    }

    /**
     * Is the rectangle actually on the page?
     *
     * A field partly off the page is not a rendering curiosity — it is a
     * signature that will be cropped or rejected by the provider, discovered
     * after the document has gone to a customer.
     *
     * @param  array $rect result of toPdfRect()
     * @param  array $geom result of pageGeometry()
     * @param  float $tolerance points of slack at the edge
     * @return array {ok, reason}
     */
    public static function withinPage(array $rect, array $geom, $tolerance = 0.5)
    {
        if (empty($rect['ok']) || empty($geom['ok'])) {
            return array('ok' => false, 'reason' => 'bad_input');
        }

        $t    = (float) $tolerance;
        $minX = (float) $geom['x0'] - $t;
        $minY = (float) $geom['y0'] - $t;
        $maxX = (float) $geom['x0'] + (float) $geom['width']  + $t;
        $maxY = (float) $geom['y0'] + (float) $geom['height'] + $t;

        if ($rect['x0'] < $minX || $rect['y0'] < $minY) {
            return array('ok' => false, 'reason' => 'field_starts_off_page');
        }

        if ($rect['x1'] > $maxX || $rect['y1'] > $maxY) {
            return array('ok' => false, 'reason' => 'field_extends_past_page_edge');
        }

        return array('ok' => true, 'reason' => null);
    }

    /* ---- validation of a whole field set ------------------------------- */

    /**
     * Validate one stored field before it is mapped.
     *
     * @param  array $f
     * @param  int   $pageCount
     * @param  array $signerRefs
     * @return array {ok, reason}
     */
    public static function validateField(array $f, $pageCount, array $signerRefs)
    {
        $type = isset($f['field_type']) ? (string) $f['field_type'] : '';

        if (!isset(self::fieldTypes()[$type])) {
            return array('ok' => false, 'reason' => 'unknown_field_type');
        }

        $page = isset($f['page_number']) ? (int) $f['page_number'] : 0;

        /* 1-based, because that is how a person counts pages and how every
           review conversation refers to them. Converting to 0-based happens
           once, at the provider boundary, if that provider wants it. */
        if ($page < 1 || $page > (int) $pageCount) {
            return array('ok' => false, 'reason' => 'page_out_of_range');
        }

        if (self::fieldTypes()[$type]['signer_bound']) {
            $ref = isset($f['signer_reference']) ? (string) $f['signer_reference'] : '';

            if ($ref === '' || !in_array($ref, $signerRefs, true)) {
                return array('ok' => false, 'reason' => 'field_not_assigned_to_a_known_signer');
            }
        }

        if (!isset($f['contract_version']) || (string) $f['contract_version'] === '') {
            return array('ok' => false, 'reason' => 'field_not_bound_to_a_contract_version');
        }

        return array('ok' => true, 'reason' => null);
    }

    /**
     * Are these fields still valid for this version of the contract?
     *
     * @param  array  $fields
     * @param  string $currentVersion
     * @return array {ok, reason, stale}
     */
    public static function fieldsMatchVersion(array $fields, $currentVersion)
    {
        $stale = array();

        foreach ($fields as $i => $f) {
            $v = isset($f['contract_version']) ? (string) $f['contract_version'] : '';

            if ($v !== (string) $currentVersion) { $stale[] = $i; }
        }

        if ($stale) {
            return array('ok' => false, 'reason' => 'fields_belong_to_a_previous_contract_version',
                         'stale' => $stale);
        }

        return array('ok' => true, 'reason' => null, 'stale' => array());
    }

    /**
     * Every mandatory signer must have at least one signature or initials field.
     *
     * Without this, a contract can be sent in which a signer has nothing to
     * sign — the provider accepts it, the signer opens it, and there is nothing
     * to do. The failure appears as a confused customer, not as an error.
     *
     * @param  array $fields
     * @param  array $signers  each {reference, is_mandatory}
     * @return array {ok, reason, missing}
     */
    public static function everySignerHasAField(array $fields, array $signers)
    {
        $has = array();

        foreach ($fields as $f) {
            $type = isset($f['field_type']) ? (string) $f['field_type'] : '';

            if ($type !== self::F_SIGNATURE && $type !== self::F_INITIALS) { continue; }

            $ref = isset($f['signer_reference']) ? (string) $f['signer_reference'] : '';

            if ($ref !== '') { $has[$ref] = true; }
        }

        $missing = array();

        foreach ($signers as $s) {
            if (empty($s['is_mandatory'])) { continue; }

            $ref = isset($s['reference']) ? (string) $s['reference'] : '';

            if ($ref === '' || !isset($has[$ref])) { $missing[] = $ref; }
        }

        if ($missing) {
            return array('ok' => false, 'reason' => 'mandatory_signer_has_no_signature_field',
                         'missing' => $missing);
        }

        return array('ok' => true, 'reason' => null, 'missing' => array());
    }

    /**
     * Map a whole field set, refusing the batch if any single field fails.
     *
     * All or nothing on purpose: a partially mapped contract is worse than a
     * refused one, because it looks like it worked.
     *
     * @param  array $fields
     * @param  array $pages  page number (1-based) => geometry input
     * @param  array $signerRefs
     * @param  float $scale
     * @return array {ok, reason, mapped, failed}
     */
    public static function mapAll(array $fields, array $pages, array $signerRefs, $scale = 1.0)
    {
        $mapped = array();
        $failed = array();
        $count  = count($pages);

        foreach ($fields as $i => $f) {
            $v = self::validateField($f, $count, $signerRefs);

            if (empty($v['ok'])) {
                $failed[] = array('index' => $i, 'reason' => $v['reason']);
                continue;
            }

            $page = (int) $f['page_number'];

            if (!isset($pages[$page])) {
                $failed[] = array('index' => $i, 'reason' => 'no_geometry_for_page');
                continue;
            }

            $geom = self::pageGeometry($pages[$page]);
            $rect = self::toPdfRect($f, $geom, $scale);

            if (empty($rect['ok'])) {
                $failed[] = array('index' => $i, 'reason' => $rect['reason']);
                continue;
            }

            $on = self::withinPage($rect, $geom);

            if (empty($on['ok'])) {
                $failed[] = array('index' => $i, 'reason' => $on['reason']);
                continue;
            }

            $mapped[] = array(
                'page_number'      => $page,
                'field_type'       => (string) $f['field_type'],
                'signer_reference' => isset($f['signer_reference']) ? (string) $f['signer_reference'] : '',
                'is_required'      => !empty($f['is_required']),
                'signing_order'    => isset($f['signing_order']) ? (int) $f['signing_order'] : 0,
                'rect'             => $rect,
            );
        }

        if ($failed) {
            return array('ok' => false, 'reason' => 'one_or_more_fields_could_not_be_mapped',
                         'mapped' => array(), 'failed' => $failed);
        }

        return array('ok' => true, 'reason' => null, 'mapped' => $mapped, 'failed' => array());
    }

    /* ---- helpers ------------------------------------------------------- */

    /** Rounded to a thousandth of a point: far finer than any renderer, and
     *  stable enough that two runs produce byte-identical output. */
    private static function pt($v)
    {
        return round((float) $v, 3);
    }

    private static function isBox($b)
    {
        return is_array($b) && count($b) === 4
            && is_numeric($b[0]) && is_numeric($b[1]) && is_numeric($b[2]) && is_numeric($b[3]);
    }

    private static function geomBad($reason)
    {
        return array('ok' => false, 'reason' => $reason, 'x0' => 0, 'y0' => 0,
                     'width' => 0, 'height' => 0, 'rotate' => 0, 'user_unit' => 1.0);
    }

    private static function rectBad($reason)
    {
        return array('ok' => false, 'reason' => $reason, 'x' => 0, 'y' => 0,
                     'width' => 0, 'height' => 0, 'x0' => 0, 'y0' => 0, 'x1' => 0, 'y1' => 0,
                     'unit' => self::UNIT);
    }
}
