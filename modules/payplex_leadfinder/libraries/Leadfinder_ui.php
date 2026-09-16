<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Leadfinder_caps.php';

/**
 * Leadfinder_ui
 *
 * The results table, the row actions, and the states a screen can be in.
 *
 * This is the single source the view, the controller and the tests all read, so
 * a button cannot exist in the markup without a capability behind it, and a
 * capability cannot be renamed without the view failing a test.
 *
 * HIDING A BUTTON IS NOT A PERMISSION
 *
 * Every entry in actions() names the capability the SERVER must check. The view
 * uses it to decide what to draw; the controller uses the same entry to decide
 * what to allow. Drawing is a courtesy — the check in the controller is the
 * control. That is why `capability` and `post` live in the same row: a reviewer
 * can see, in one place, that every destructive action is a POST and that every
 * POST names a capability.
 *
 * Pure: no database, no session, no output.
 */
class Leadfinder_ui
{
    /* ---- table columns --------------------------------------------------- */

    /**
     * Columns, in default order.
     *
     * `sortable` is a server-side promise: a column marked sortable must have an
     * index behind it, and the model whitelists these names — a sort parameter
     * is never interpolated into SQL.
     *
     * `pii` marks columns that carry contact detail. They are the columns the
     * export redactor and the purge both care about, and they are the ones a
     * staff member without contact access does not receive at all.
     *
     * @return array
     */
    public static function columns()
    {
        return array(
            'select'      => array('label' => '',                  'sortable' => false, 'pii' => false, 'default' => true,  'mobile' => true,  'width' => '36px'),
            'name'        => array('label' => 'Business',          'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => true,  'width' => 'auto'),
            'category'    => array('label' => 'Category',          'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => false, 'width' => '140px'),
            'address'     => array('label' => 'Address',           'sortable' => false, 'pii' => true,  'default' => true,  'mobile' => false, 'width' => '220px'),
            'distance'    => array('label' => 'Distance',          'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => true,  'width' => '90px'),
            'phone'       => array('label' => 'Phone',             'sortable' => false, 'pii' => true,  'default' => true,  'mobile' => false, 'width' => '130px'),
            'website'     => array('label' => 'Website',           'sortable' => false, 'pii' => true,  'default' => true,  'mobile' => false, 'width' => '140px'),
            'email_state' => array('label' => 'Email',             'sortable' => false, 'pii' => true,  'default' => true,  'mobile' => false, 'width' => '120px'),
            'source'      => array('label' => 'Source',            'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => false, 'width' => '110px'),
            'dupe'        => array('label' => 'Duplicate',         'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => true,  'width' => '120px'),
            'assigned'    => array('label' => 'Assigned',          'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => false, 'width' => '130px'),
            'status'      => array('label' => 'Verification',      'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => true,  'width' => '140px'),
            'last_action' => array('label' => 'Last action',       'sortable' => true,  'pii' => false, 'default' => true,  'mobile' => false, 'width' => '150px'),
            'actions'     => array('label' => '',                  'sortable' => false, 'pii' => false, 'default' => true,  'mobile' => true,  'width' => '60px'),
        );
    }

    /**
     * @return array column keys
     */
    public static function columnKeys()
    {
        return array_keys(self::columns());
    }

    /**
     * Columns that may be sorted on. The model accepts nothing else.
     *
     * @return array
     */
    public static function sortableColumns()
    {
        $out = array();

        foreach (self::columns() as $k => $c) {
            if ($c['sortable']) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /**
     * Is this a sort the server will honour?
     *
     * Returns a normalised pair rather than a bool, so the caller never has to
     * build one from raw input.
     *
     * @param  mixed $column
     * @param  mixed $direction
     * @return array {column, direction}
     */
    public static function normaliseSort($column, $direction)
    {
        $column = is_string($column) ? $column : '';

        if (!in_array($column, self::sortableColumns(), true)) {
            $column = 'distance';
        }

        $direction = (is_string($direction) && strtolower($direction) === 'desc') ? 'desc' : 'asc';

        return array('column' => $column, 'direction' => $direction);
    }

    /**
     * Columns a viewer actually receives.
     *
     * A staff member without contact access does not get the PII columns —
     * not hidden by CSS, not blanked in the browser: absent from the payload.
     *
     * @param  array $selected   the viewer's column choice, may be empty
     * @param  bool  $maySeePii
     * @return array
     */
    public static function visibleColumns($selected, $maySeePii)
    {
        $all = self::columns();
        $out = array();

        foreach ($all as $k => $c) {
            if ($c['pii'] && !$maySeePii) {
                continue;
            }

            // select and actions are structural: always present.
            if ($k === 'select' || $k === 'actions') {
                $out[] = $k;
                continue;
            }

            if (is_array($selected) && $selected) {
                if (in_array($k, $selected, true)) {
                    $out[] = $k;
                }
                continue;
            }

            if ($c['default']) {
                $out[] = $k;
            }
        }

        return $out;
    }

    /* ---- row actions ------------------------------------------------------ */

    const A_VIEW        = 'view_details';
    const A_FETCH       = 'fetch_contact_details';
    const A_CLAIM       = 'claim';
    const A_RELEASE     = 'release';
    const A_SAVE        = 'save_to_queue';
    const A_VERIFY      = 'verify';
    const A_CALL_RESULT = 'call_result';
    const A_CALLBACK    = 'schedule_callback';
    const A_SUBMIT      = 'submit_for_conversion';
    const A_WASTE       = 'mark_waste';
    const A_DUPLICATE   = 'mark_duplicate';
    const A_DNC         = 'mark_dnc';
    const A_DELETE      = 'delete';

    /**
     * Every row action, with the capability the server checks, whether it needs
     * ownership, whether it is a POST, and whether it asks before acting.
     *
     * `confirm` is set for everything that is hard to undo. `reason` marks the
     * two that cannot proceed without one.
     *
     * @return array
     */
    public static function actions()
    {
        return array(
            self::A_VIEW => array(
                'label' => 'View details', 'icon' => 'eye',
                'capability' => 'leadfinder_view',
                'owner_required' => false, 'post' => false,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_FETCH => array(
                'label' => 'Fetch contact details', 'icon' => 'download',
                'capability' => 'leadfinder_fetch_details',
                'owner_required' => true, 'post' => true,
                /* Spends a paid Place Details call, so it asks first and says
                   what it will cost. Never fired by opening the drawer. */
                'confirm' => true, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => true,
            ),
            self::A_CLAIM => array(
                'label' => 'Claim', 'icon' => 'hand',
                'capability' => 'leadfinder_claim',
                'owner_required' => false, 'post' => true,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_RELEASE => array(
                'label' => 'Release', 'icon' => 'undo',
                'capability' => 'leadfinder_claim',
                'owner_required' => true, 'post' => true,
                'confirm' => true, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_SAVE => array(
                'label' => 'Save to my queue', 'icon' => 'bookmark',
                'capability' => 'leadfinder_view',
                'owner_required' => false, 'post' => true,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_VERIFY => array(
                'label' => 'Verify', 'icon' => 'check-circle',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_CALL_RESULT => array(
                'label' => 'Record call result', 'icon' => 'phone',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_CALLBACK => array(
                'label' => 'Schedule callback', 'icon' => 'clock',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                'confirm' => false, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_SUBMIT => array(
                'label' => 'Submit for conversion', 'icon' => 'send',
                'capability' => 'leadfinder_submit_conversion',
                'owner_required' => true, 'post' => true,
                'confirm' => true, 'reason' => false, 'destructive' => false,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_WASTE => array(
                'label' => 'Mark as waste', 'icon' => 'trash',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                'confirm' => true, 'reason' => true, 'destructive' => true,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_DUPLICATE => array(
                'label' => 'Mark duplicate', 'icon' => 'copy',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                'confirm' => true, 'reason' => false, 'destructive' => true,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_DNC => array(
                'label' => 'Do not contact', 'icon' => 'ban',
                'capability' => 'leadfinder_verify',
                'owner_required' => true, 'post' => true,
                /* Permanent by design: the suppression key outlives the record
                   and is never purged as ordinary waste. */
                'confirm' => true, 'reason' => true, 'destructive' => true,
                'admin_only' => false, 'costs_quota' => false,
            ),
            self::A_DELETE => array(
                'label' => 'Delete', 'icon' => 'x-octagon',
                'capability' => 'leadfinder_manage_profiles',
                'owner_required' => false, 'post' => true,
                'confirm' => true, 'reason' => true, 'destructive' => true,
                'admin_only' => true, 'costs_quota' => false,
            ),
        );
    }

    /**
     * @return array action keys
     */
    public static function actionKeys()
    {
        return array_keys(self::actions());
    }

    /**
     * @param  string $action
     * @return array|null
     */
    public static function action($action)
    {
        $a = self::actions();

        return isset($a[$action]) ? $a[$action] : null;
    }

    /**
     * May this viewer perform this action on this row?
     *
     * The same function answers for the view and for the controller. Anything
     * it refuses must be refused server-side too — which is why the controller
     * calls it rather than re-deriving the rules.
     *
     * Fails closed on an unknown action, a missing capability, and a row in a
     * terminal state.
     *
     * @param  string $action
     * @param  array  $row
     * @param  array  $viewer {staff_id, is_admin, capabilities[]}
     * @return array {allowed: bool, reason: string}
     */
    public static function may($action, array $row, array $viewer)
    {
        $spec = self::action($action);

        if ($spec === null) {
            return self::no('unknown_action');
        }

        $isAdmin = !empty($viewer['is_admin']);
        $caps    = isset($viewer['capabilities']) && is_array($viewer['capabilities'])
            ? $viewer['capabilities'] : array();

        if ($spec['admin_only'] && !$isAdmin) {
            return self::no('admin_only');
        }

        if (!$isAdmin && !in_array($spec['capability'], $caps, true)) {
            return self::no('missing_capability:' . $spec['capability']);
        }

        /*
         * A wasted, duplicate or DNC row is finished. Every action that could
         * work it, contact it or convert it is refused here as well as in the
         * model — the list comes from Leadfinder_waste so the two cannot drift.
         */
        $status = isset($row['status']) ? (string) $row['status'] : '';

        if (self::isTerminal($status) && !self::allowedOnTerminal($action)) {
            return self::no('prospect_is_closed:' . $status);
        }

        if ($spec['owner_required']) {
            $owner = isset($row['claimed_by']) ? (int) $row['claimed_by'] : 0;
            $me    = isset($viewer['staff_id']) ? (int) $viewer['staff_id'] : 0;

            if ($owner === 0) {
                return self::no('not_claimed');
            }

            /*
             * An administrator may act on any row, but a manager holding
             * leadfinder_view_all may only LOOK at other people's rows. Seeing
             * every prospect is not the same as working someone else's.
             */
            if ($owner !== $me && !$isAdmin) {
                return self::no('not_the_owner');
            }
        }

        if ($action === self::A_CLAIM && !empty($row['claimed_by'])) {
            return self::no('already_claimed');
        }

        return array('allowed' => true, 'reason' => 'ok');
    }

    /**
     * Terminal statuses: the row is done being worked.
     *
     * @return array
     */
    public static function terminalStatuses()
    {
        return array('rejected', 'duplicate', 'irrelevant', 'business_closed',
                     'wrong_number', 'do_not_contact', 'valid_not_interested',
                     'converted_to_lead');
    }

    /**
     * @param  string $status
     * @return bool
     */
    public static function isTerminal($status)
    {
        return in_array($status, self::terminalStatuses(), true);
    }

    /**
     * The only actions that still make sense on a closed row: looking at it,
     * and (for an administrator) removing it.
     *
     * @param  string $action
     * @return bool
     */
    public static function allowedOnTerminal($action)
    {
        return in_array($action, array(self::A_VIEW, self::A_DELETE), true);
    }

    /**
     * The actions a viewer may take on a row, for rendering.
     *
     * @param  array $row
     * @param  array $viewer
     * @return array
     */
    public static function availableActions(array $row, array $viewer)
    {
        $out = array();

        foreach (self::actionKeys() as $a) {
            if (self::may($a, $row, $viewer)['allowed']) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /* ---- bulk ------------------------------------------------------------- */

    /**
     * Actions offered in bulk, and the cap on how many rows at once.
     *
     * Fetch is deliberately absent: it spends quota per row, and a bulk button
     * that quietly bills for two hundred Place Details calls is a trap.
     *
     * @return array
     */
    public static function bulkActions()
    {
        return array(self::A_SAVE, self::A_CLAIM, self::A_RELEASE,
                     self::A_WASTE, self::A_DUPLICATE);
    }

    /** One page of selections at most, so a bulk action is always reviewable. */
    const BULK_MAX = 100;

    /**
     * @param  string $action
     * @param  array  $ids
     * @return array {ok, ids, error}
     */
    public static function normaliseBulk($action, $ids)
    {
        if (!in_array($action, self::bulkActions(), true)) {
            return array('ok' => false, 'ids' => array(), 'error' => 'That action cannot be applied in bulk.');
        }

        if (!is_array($ids) || !$ids) {
            return array('ok' => false, 'ids' => array(), 'error' => 'Nothing selected.');
        }

        $clean = array();

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !in_array($id, $clean, true)) {
                $clean[] = $id;
            }
        }

        if (!$clean) {
            return array('ok' => false, 'ids' => array(), 'error' => 'Nothing selected.');
        }

        if (count($clean) > self::BULK_MAX) {
            return array('ok' => false, 'ids' => array(),
                         'error' => 'Select at most ' . self::BULK_MAX . ' rows at a time.');
        }

        return array('ok' => true, 'ids' => $clean, 'error' => null);
    }

    /* ---- filters ----------------------------------------------------------- */

    /**
     * Filters the queue accepts. Anything not here is dropped rather than passed
     * to the model.
     *
     * @return array
     */
    public static function filters()
    {
        return array(
            'q'            => array('type' => 'text',   'max' => 120),
            'status'       => array('type' => 'enum',   'values' => 'status'),
            'source'       => array('type' => 'enum',   'values' => array('google_places', 'manual', 'import')),
            'assigned'     => array('type' => 'int'),
            'dupe'         => array('type' => 'enum',   'values' => array('none', 'possible', 'exact')),
            'has_phone'    => array('type' => 'bool'),
            'has_website'  => array('type' => 'bool'),
            'has_email'    => array('type' => 'bool'),
            'saved_only'   => array('type' => 'bool'),
            'city'         => array('type' => 'text',   'max' => 80),
            'state'        => array('type' => 'text',   'max' => 80),
            'from'         => array('type' => 'date'),
            'to'           => array('type' => 'date'),
        );
    }

    /**
     * @return array
     */
    public static function filterKeys()
    {
        return array_keys(self::filters());
    }

    /**
     * Drop anything the filter list does not declare, and coerce what remains.
     *
     * @param  array $input
     * @return array
     */
    public static function normaliseFilters($input)
    {
        $spec = self::filters();
        $out  = array();

        if (!is_array($input)) {
            return $out;
        }

        foreach ($spec as $key => $s) {
            if (!array_key_exists($key, $input)) {
                continue;
            }

            $v = $input[$key];

            switch ($s['type']) {
                case 'bool':
                    if ($v === '' || $v === null) { break; }
                    $out[$key] = (int) (bool) $v;
                    break;

                case 'int':
                    if (!is_numeric($v)) { break; }
                    $out[$key] = (int) $v;
                    break;

                case 'date':
                    if (self::validDate($v)) { $out[$key] = $v; }
                    break;

                case 'enum':
                    if (is_array($s['values']) && in_array($v, $s['values'], true)) {
                        $out[$key] = $v;
                    } elseif ($s['values'] === 'status' && is_string($v) && $v !== '') {
                        $out[$key] = $v;   // validated against the status machine downstream
                    }
                    break;

                default:
                    if (!is_string($v)) { break; }
                    $v = trim($v);
                    if ($v === '') { break; }
                    $out[$key] = function_exists('mb_substr')
                        ? mb_substr($v, 0, $s['max'], 'UTF-8')
                        : substr($v, 0, $s['max']);
            }
        }

        return $out;
    }

    /**
     * A real calendar date, not just the right shape. 2026-02-31 matches any
     * reasonable regex and is not a date.
     *
     * @param  mixed $s
     * @return bool
     */
    public static function validDate($s)
    {
        if (!is_string($s) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /* ---- saved filters ------------------------------------------------------ */

    /** A saved filter belongs to the staff member who saved it. */
    const SAVED_MAX_PER_STAFF = 20;
    const SAVED_NAME_MAX      = 60;

    /**
     * @param  mixed $name
     * @param  array $filters
     * @param  int   $existingCount
     * @return array {ok, name, filters, error}
     */
    public static function validateSavedFilter($name, $filters, $existingCount)
    {
        $name = is_string($name) ? trim($name) : '';

        if ($name === '') {
            return array('ok' => false, 'error' => 'Give the saved filter a name.');
        }

        $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);

        if ($len > self::SAVED_NAME_MAX) {
            return array('ok' => false, 'error' => 'Names are limited to ' . self::SAVED_NAME_MAX . ' characters.');
        }

        if ((int) $existingCount >= self::SAVED_MAX_PER_STAFF) {
            return array('ok' => false,
                         'error' => 'You already have ' . self::SAVED_MAX_PER_STAFF . ' saved filters. Delete one first.');
        }

        $clean = self::normaliseFilters($filters);

        if (!$clean) {
            return array('ok' => false, 'error' => 'There is nothing to save — set a filter first.');
        }

        return array('ok' => true, 'name' => $name, 'filters' => $clean, 'error' => null);
    }

    /* ---- paging -------------------------------------------------------------- */

    const PAGE_SIZES   = array(25, 50, 100);
    const PAGE_DEFAULT = 25;
    const PAGE_MAX     = 100;

    /**
     * @param  mixed $page
     * @param  mixed $perPage
     * @return array {page, per_page, offset}
     */
    public static function normalisePaging($page, $perPage)
    {
        $page = (is_numeric($page) && (int) $page > 0) ? (int) $page : 1;

        $per = (is_numeric($perPage) && in_array((int) $perPage, self::PAGE_SIZES, true))
            ? (int) $perPage
            : self::PAGE_DEFAULT;

        if ($per > self::PAGE_MAX) {
            $per = self::PAGE_MAX;
        }

        return array('page' => $page, 'per_page' => $per, 'offset' => ($page - 1) * $per);
    }

    /* ---- view modes and screen states ----------------------------------------- */

    const MODE_TABLE = 'table';
    const MODE_CARDS = 'cards';

    /**
     * @return array
     */
    public static function viewModes()
    {
        return array(self::MODE_TABLE, self::MODE_CARDS);
    }

    /**
     * Cards below this width: a fourteen-column table on a phone is a horizontal
     * scroll nobody uses.
     *
     * @param  int    $viewportWidth
     * @param  string $requested
     * @return string
     */
    public static function defaultMode($viewportWidth, $requested = null)
    {
        if ($requested !== null && in_array($requested, self::viewModes(), true)) {
            return $requested;
        }

        return ((int) $viewportWidth < 640) ? self::MODE_CARDS : self::MODE_TABLE;
    }

    /**
     * The states the results area can be in, and what each one says.
     *
     * Separated because "no results" and "the search failed" look identical if
     * you only ever render an empty table, and the difference is the whole
     * message.
     *
     * @return array
     */
    public static function screenStates()
    {
        return array(
            'idle' => array(
                'title' => 'Search for businesses',
                'body'  => 'Enter a category and a place, then search. Results are held in the '
                         . 'Prospect Verification Queue — nothing becomes a CRM lead without an approved conversion.',
                'retry' => false,
            ),
            'loading' => array(
                'title' => 'Searching…',
                'body'  => 'Asking Google for matching businesses.',
                'retry' => false,
            ),
            'empty' => array(
                'title' => 'No businesses matched',
                'body'  => 'Nothing came back for that search. Try a broader category, a larger radius, '
                         . 'or a nearby town.',
                'retry' => false,
            ),
            'all_suppressed' => array(
                'title' => 'Everything here has been seen before',
                'body'  => 'Every result was already rejected or is on the do-not-contact list, so none of '
                         . 'them were added to your queue.',
                'retry' => false,
            ),
            'no_key' => array(
                'title' => 'No Places key configured',
                'body'  => 'Lead Finder has no server-side Places key, so no search was sent and nothing '
                         . 'was billed.',
                'retry' => false,
            ),
            'quota' => array(
                'title' => 'Search allowance reached',
                'body'  => 'The allowance for this period is used up. Nothing was sent to Google.',
                'retry' => false,
            ),
            'error' => array(
                'title' => 'The search did not complete',
                'body'  => 'Google returned an error. Nothing was saved and no quota was spent on the '
                         . 'failed attempt.',
                'retry' => true,
            ),
            'not_migrated' => array(
                'title' => 'Lead Finder is not fully installed',
                'body'  => 'Some Lead Finder tables are missing, so this screen cannot run. '
                         . 'An administrator needs to apply the outstanding migrations.',
                'retry' => false,
            ),
        );
    }

    /**
     * @param  string $state
     * @return array|null
     */
    public static function screenState($state)
    {
        $s = self::screenStates();

        return isset($s[$state]) ? $s[$state] : null;
    }

    /* ---- badges ---------------------------------------------------------------- */

    /**
     * Source and duplicate badges. Text as well as colour, for the same reason
     * the map markers carry glyphs.
     *
     * @return array
     */
    public static function badges()
    {
        return array(
            'source_google'   => array('label' => 'Google', 'tone' => 'info'),
            'source_manual'   => array('label' => 'Manual', 'tone' => 'muted'),
            'source_import'   => array('label' => 'Import', 'tone' => 'muted'),
            'dupe_none'       => array('label' => 'New', 'tone' => 'ok'),
            'dupe_possible'   => array('label' => 'Possible match', 'tone' => 'warn'),
            'dupe_exact'      => array('label' => 'Duplicate', 'tone' => 'bad'),
            'saved'           => array('label' => 'Saved', 'tone' => 'ok'),
            'waste'           => array('label' => 'Waste', 'tone' => 'muted'),
            'dnc'             => array('label' => 'Do not contact', 'tone' => 'bad'),
            'simulated'       => array('label' => 'SIMULATED', 'tone' => 'warn'),
            'undo_available'  => array('label' => 'Undo available', 'tone' => 'info'),
            'review_required' => array('label' => 'Review Required — Possible Waste', 'tone' => 'warn'),
        );
    }

    /**
     * The hint shown above a table that scrolls sideways on a narrow screen.
     *
     * @return string
     */
    public static function swipeHint()
    {
        return 'Swipe to view more columns';
    }

    private static function no($reason)
    {
        return array('allowed' => false, 'reason' => $reason);
    }
}
