<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Get contract short_url
 * @since  Version 2.7.3
 * @param  object $contract
 * @return string Url
 */
function get_contract_shortlink($contract)
{
    $long_url = site_url("contract/{$contract->id}/{$contract->hash}");
    if (!get_option('bitly_access_token')) {
        return $long_url;
    }

    // Check if contract has short link, if yes return short link
    if (!empty($contract->short_link)) {
        return $contract->short_link;
    }

    // Create short link and return the newly created short link
    $short_link = app_generate_short_link([
        'long_url' => $long_url,
        'title'    => 'Contract #' . $contract->id,
    ]);

    if ($short_link) {
        $CI = &get_instance();
        $CI->db->where('id', $contract->id);
        $CI->db->update(db_prefix() . 'contracts', [
            'short_link' => $short_link,
        ]);

        return $short_link;
    }

    return $long_url;
}

/**
 * Check the contract view restrictions
 *
 * @param  int $id
 * @param  string $hash
 *
 * @return void
 */
function check_contract_restrictions($id, $hash)
{
    $CI = &get_instance();
    $CI->load->model('contracts_model');

    if (!$hash || !$id) {
        show_404();
    }

    if (!is_client_logged_in() && !is_staff_logged_in()) {
        if (get_option('view_contract_only_logged_in') == 1) {
            redirect_after_login_to_current_url();
            redirect(site_url('authentication/login'));
        }
    }

    $contract = $CI->contracts_model->get($id);

    if (!$contract || ($contract->hash != $hash)) {
        show_404();
    }

    // Do one more check
    if (!is_staff_logged_in()) {
        if (get_option('view_contract_only_logged_in') == 1) {
            if ($contract->client != get_client_user_id()) {
                show_404();
            }
        }
    }
}

/**
 * Function that will search possible contracts templates in applicaion/views/admin/contracts/templates
 * Will return any found files and user will be able to add new template
 *
 * @return array
 */
function get_contract_templates()
{
    $contract_templates = [];
    if (is_dir(VIEWPATH . 'admin/contracts/templates')) {
        foreach (list_files(VIEWPATH . 'admin/contracts/templates') as $template) {
            $contract_templates[] = $template;
        }
    }

    return $contract_templates;
}

/**
 * Send contract signed notification to staff members
 *
 * @param  int $contract_id
 *
 * @return void
 */
function send_contract_signed_notification_to_staff($contract_id)
{
    $CI = &get_instance();
    $CI->db->where('id', $contract_id);
    $contract = $CI->db->get(db_prefix() . 'contracts')->row();

    if (!$contract) {
        return false;
    }

    // Get creator
    $CI->db->select('staffid, email');
    $CI->db->where('staffid', $contract->addedfrom);
    $staff_contract = $CI->db->get(db_prefix() . 'staff')->result_array();

    $notifiedUsers = [];

    foreach ($staff_contract as $member) {
        $notified = add_notification([
            'description'     => 'not_contract_signed',
            'touserid'        => $member['staffid'],
            'fromcompany'     => 1,
            'fromuserid'      => 0,
            'link'            => 'contracts/contract/' . $contract->id,
            'additional_data' => serialize([
                '<b>' . $contract->subject . '</b>',
            ]),
        ]);

        if ($notified) {
            array_push($notifiedUsers, $member['staffid']);
        }

        send_mail_template('contract_signed_to_staff', $contract, $member);
    }

    pusher_trigger_notification($notifiedUsers);
}

/**
 * Get the recently created contracts in the given days
 *
 * @param  integer $days
 * @param  integer|null $staffId
 *
 * @return integer
 */
function count_recently_created_contracts($days = 7, $staffId = null)
{
    $diff1     = date('Y-m-d', strtotime('-' . $days . ' days'));
    $diff2     = date('Y-m-d', strtotime('+' . $days . ' days'));
    $staffId   = is_null($staffId) ? get_staff_user_id() : $staffId;
    $where_own = [];

    if (staff_cant('view', 'contracts')) {
        $where_own = ['addedfrom' => $staffId];
    }

    return total_rows(db_prefix() . 'contracts', 'dateadded BETWEEN "' . $diff1 . '" AND "' . $diff2 . '" AND trash=0' . (count($where_own) > 0 ? ' AND addedfrom=' . $staffId : ''));
}

/**
 * Get total number of active contracts
 *
 * @param integer|null $staffId
 *
 * @return integer
 */
function count_active_contracts($staffId = null)
{
    $where_own = [];
    $staffId   = is_null($staffId) ? get_staff_user_id() : $staffId;

    if (staff_cant('view', 'contracts')) {
        $where_own = ['addedfrom' => $staffId];
    }

    return total_rows(db_prefix() . 'contracts', '(DATE(dateend) >"' . date('Y-m-d') . '" AND trash=0' . (count($where_own) > 0 ? ' AND addedfrom=' . $staffId : '') . ') OR (DATE(dateend) IS NULL AND trash=0' . (count($where_own) > 0 ? ' AND addedfrom=' . $staffId : '') . ')');
}

/**
 * Get total number of expired contracts
 *
 * @param integer|null $staffId
 *
 * @return integer
 */
function count_expired_contracts($staffId = null)
{
    $where_own = [];
    $staffId   = is_null($staffId) ? get_staff_user_id() : $staffId;

    if (staff_cant('view', 'contracts')) {
        $where_own = ['addedfrom' => $staffId];
    }

    return total_rows(db_prefix() . 'contracts', array_merge(['DATE(dateend) <' => date('Y-m-d'), 'trash' => 0], $where_own));
}

/**
 * Get total number of trash contracts
 *
 * @param integer|null $staffId
 *
 * @return integer
 */
function count_trash_contracts($staffId = null)
{
    $where_own = [];
    $staffId   = is_null($staffId) ? get_staff_user_id() : $staffId;

    if (staff_cant('view', 'contracts')) {
        $where_own = ['addedfrom' => $staffId];
    }

    return total_rows(db_prefix() . 'contracts', array_merge(['trash' => 1], $where_own));
}

/**
 * Format the contract internal reference number
 * Available as soon as the contract is created, since it's derived
 * from the auto-increment id rather than a separate running counter
 *
 * @param  integer|object $id Contract id, or the contract row itself
 *
 * @return string
 */
function format_contract_number($id)
{
    $id = is_object($id) ? $id->id : $id;

    return get_option('contract_number_prefix') . str_pad($id, get_option('number_padding_prefixes'), '0', STR_PAD_LEFT);
}

/**
 * Upload a document from $_FILES and attach it to a contract
 *
 * @param  integer $id         Contract id
 * @param  string  $index_name $_FILES index to read the uploaded file from
 *
 * @return object|false The newly created file row, or false when nothing was uploaded/upload failed
 */
function handle_contract_document_field($id, $index_name)
{
    if (!isset($_FILES[$index_name]) || $_FILES[$index_name]['name'] == '') {
        return false;
    }

    if (_perfex_upload_error($_FILES[$index_name]['error'])) {
        return false;
    }

    $tmpFilePath = $_FILES[$index_name]['tmp_name'];
    if (empty($tmpFilePath)) {
        return false;
    }

    $path = get_upload_path_by_type('contract') . $id . '/';
    _maybe_create_upload_path($path);
    $filename    = unique_filename($path, $_FILES[$index_name]['name']);
    $newFilePath = $path . $filename;

    if (!move_uploaded_file($tmpFilePath, $newFilePath)) {
        return false;
    }

    $CI      = & get_instance();
    $file_id = $CI->misc_model->add_attachment_to_database($id, 'contract', [[
        'file_name' => $filename,
        'filetype'  => $_FILES[$index_name]['type'],
    ]]);

    return $file_id ? $CI->misc_model->get_file($file_id) : false;
}

/**
 * Build the HTML link used to reference an uploaded document
 * from a contract field (description/content) that would
 * otherwise contain manually typed text
 *
 * @param  object $file Row from the files table
 *
 * @return string
 */
function contract_document_field_link($file)
{
    return '<a href="' . site_url('download/file/contract/' . $file->attachment_key) . '" target="_blank">'
        . '<i class="fa-solid fa-paperclip"></i> ' . e($file->file_name)
        . '</a>';
}
