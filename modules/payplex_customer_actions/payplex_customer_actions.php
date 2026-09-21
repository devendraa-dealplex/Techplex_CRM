<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Customer Actions
Description: Adds an Actions column to the Customers list (Open, Email, Call, WhatsApp, meeting status with quick-schedule, and a quick-actions menu), matching the All Leads table.
Version: 1.0.0
Requires at least: 2.3.*
Author: Payplex
*/

define('PAYPLEX_CUSTOMER_ACTIONS_MODULE', 'payplex_customer_actions');

/*
 * No core file is modified. The customers table already exposes the three filters
 * used here (application/views/admin/tables/clients.php, admin/clients/manage.php):
 *
 *   customers_table_columns      the <th> list           -> we append "Actions"
 *   customers_table_sql_columns  the SELECT column list  -> we append the contact's phone
 *   customers_table_row_data     one row of cells        -> we append the actions cell
 *
 * A header without a matching row cell (or the reverse) makes DataTables abort every
 * draw with "Requested unknown parameter", which blanks the whole table. The header
 * and the cell are therefore added by the same module under the SAME condition
 * (pca_enabled()), so they cannot get out of step.
 */
hooks()->add_filter('customers_table_columns', 'pca_table_column');
hooks()->add_filter('customers_table_sql_columns', 'pca_sql_columns');
hooks()->add_filter('customers_table_row_data', 'pca_row_data', 10, 2);
hooks()->add_action('app_admin_head', 'pca_head');
hooks()->add_action('app_admin_footer', 'pca_footer');

/** Only on the customers list itself, for staff who can see customers at all. */
function pca_enabled()
{
    return function_exists('is_staff_member') && is_staff_member();
}

function pca_is_customers_list()
{
    $CI = &get_instance();

    return $CI->uri->segment(1) === 'admin' && $CI->uri->segment(2) === 'clients'
        && ($CI->uri->segment(3) === null || $CI->uri->segment(3) === '' || $CI->uri->segment(3) === 'index');
}

function pca_table_column($columns)
{
    if (!pca_enabled() || !is_array($columns)) {
        return $columns;
    }

    $columns[] = [
        'name'     => 'Actions',
        // Not sortable/searchable/exportable: the cell is buttons, not data. The
        // server has no column to order or search by at this position.
        'th_attrs' => [
            'class'           => 'not-export pca-actions-col',
            'data-orderable'  => 'false',
            'data-searchable' => 'false',
            'style'           => 'min-width:230px',
        ],
    ];

    return $columns;
}

/**
 * The contact's own phone number. The table's existing "phonenumber" column is the
 * COMPANY phone; a call or WhatsApp button should reach the primary contact, so
 * that number is selected too and preferred, with the company number as fallback.
 */
function pca_sql_columns($columns)
{
    if (!pca_enabled() || !is_array($columns)) {
        return $columns;
    }

    $columns[] = db_prefix() . 'contacts.phonenumber as pca_contact_phone';

    return $columns;
}

/**
 * Digits for wa.me, which wants the number in international format with no "+",
 * spaces or leading zeros. A bare 10-digit Indian mobile (starts 6-9) or one with a
 * leading 0 gets 91; anything already carrying a country code is left as it is.
 *
 * @return string digits, or '' when there is nothing dialable
 */
function pca_wa_number($phone)
{
    $raw    = trim((string) $phone);
    $digits = preg_replace('/\D+/', '', $raw);

    if ($digits === '') {
        return '';
    }
    if (strpos($raw, '+') === 0) {
        return strlen($digits) >= 8 ? $digits : '';
    }
    $digits = preg_replace('/^00/', '', $digits);
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10 && preg_match('/^[6-9]/', $digits)) {
        $digits = '91' . $digits;
    }

    // A number still starting with 0 is a local landline: no country code to build a wa.me link from.
    if ($digits[0] === '0') {
        return '';
    }

    return strlen($digits) >= 8 ? $digits : '';
}

function pca_row_data($row, $aRow)
{
    if (!pca_enabled()) {
        return $row;
    }

    $id    = (int) $aRow['userid'];
    $email = trim((string) $aRow['email']);
    $phone = trim((string) (!empty($aRow['pca_contact_phone']) ? $aRow['pca_contact_phone'] : $aRow['phonenumber']));
    $wa    = pca_wa_number($phone);
    $tel   = preg_replace('/[^0-9+]/', '', $phone);

    // Per-customer permission: staff without "view customers" only see the customers
    // they administer, so the list is already scoped; editing needs the edit right.
    $canEdit   = staff_can('edit', 'customers') || is_customer_admin($id);
    $canDelete = staff_can('delete', 'customers');
    $canTask   = staff_can('create', 'tasks');

    $h = '<div class="pca-actions">';

    $h .= '<a href="' . admin_url('clients/client/' . $id) . '" class="btn btn-default btn-xs" title="Open customer">Open</a>';

    if ($email !== '') {
        $h .= ' <a href="mailto:' . e($email) . '" class="btn btn-default btn-xs" title="Email">&#9993;</a>';
    }
    if ($tel !== '') {
        $h .= ' <a href="tel:' . e($tel) . '" class="btn btn-default btn-xs" title="Call">&#9742;</a>';
    }
    if ($wa !== '') {
        $h .= ' <a href="https://wa.me/' . e($wa) . '" target="_blank" rel="noopener" class="btn btn-default btn-xs" title="WhatsApp">WA</a>';
    }

    // Meeting status + quick schedule: filled in by payplex_meetings.js after each draw
    // with ONE request for the whole page. Only when that module is active and the
    // staff member may see meetings.
    if (function_exists('pm_can') && pm_can('view_own')) {
        $h .= '<div class="pca-meeting" data-pca-customer="' . $id . '"><span class="text-muted">&hellip;</span></div>';
    }

    $items = '';
    if ($canEdit) {
        $items .= '<li><a href="' . admin_url('clients/client/' . $id . '?group=profile') . '">' . _l('edit') . '</a></li>';
        $items .= '<li><a href="#" class="pca-assign-group" data-id="' . $id . '">' . _l('customer_groups') . '</a></li>';
    }
    if ($canTask) {
        $items .= '<li><a href="#" class="pca-create-task" data-id="' . $id . '">' . _l('new_task') . '</a></li>';
    }
    if ($canDelete) {
        $items .= '<li role="separator" class="divider"></li>';
        $items .= '<li><a href="' . admin_url('clients/delete/' . $id) . '" class="_delete text-danger">' . _l('delete') . '</a></li>';
    }

    if ($items !== '') {
        $h .= '<div class="btn-group pca-more">'
            . '<button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">'
            . 'Quick Actions <span class="caret"></span></button>'
            . '<ul class="dropdown-menu dropdown-menu-right">' . $items . '</ul></div>';
    }

    $h .= '</div>';

    $row[] = $h;

    return $row;
}

function pca_head()
{
    if (!pca_is_customers_list()) {
        return;
    }
    echo '<style>'
        . '.pca-actions{display:flex;flex-wrap:wrap;gap:4px;align-items:center}'
        . '.pca-actions .btn-xs{margin:0}'
        . '.pca-meeting{flex-basis:100%;font-size:12px}'
        . '.pca-meeting .pm-chip{margin-right:4px}'
        . '</style>';
}

/**
 * Menu handlers. "Assign group" reuses the table's own bulk-action modal with just
 * this row ticked, so the server-side group assignment and its permission check are
 * exactly the ones the bulk action already has. "New task" opens Perfex's task modal
 * pre-related to the customer.
 */
function pca_footer()
{
    if (!pca_is_customers_list()) {
        return;
    }
    ?>
<script>
$(function () {
  $('body').on('click', '.pca-assign-group', function (e) {
    e.preventDefault();
    var id = String($(this).data('id'));
    var $modal = $('#customers_bulk_action');
    if (!$modal.length) { return; }
    var $boxes = $('.table-clients tbody input[type="checkbox"]');
    $boxes.prop('checked', false);
    $boxes.filter(function () { return String(this.value) === id; }).prop('checked', true);
    $modal.find('#mass_delete').prop('checked', false).trigger('change');
    $modal.modal('show');
  });
  $('body').on('click', '.pca-create-task', function (e) {
    e.preventDefault();
    if (typeof new_task_from_relation === 'function') {
      new_task_from_relation(undefined, 'customer', $(this).data('id'));
    }
  });
});
</script>
    <?php
}
