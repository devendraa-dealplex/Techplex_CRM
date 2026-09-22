<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Payplex Ticket Auto-Assign
Description: A support ticket a customer opens is assigned automatically, and evenly, to the active staff of the department the customer picked.
Version: 1.0.0
Requires at least: 2.3.*
Author: Payplex
*/

define('PAYPLEX_TICKET_ASSIGN_MODULE', 'payplex_ticket_assign');

/*
 * HOW IT WORKS
 * ------------
 * The customer already chooses a department when they open a ticket (core). Perfex
 * then runs the `before_ticket_created` filter with the row it is about to insert.
 * If the ticket has no assignee, this module fills `assigned` in that row, so the
 * core code that follows does everything else exactly as for a manual assignment:
 * the assignee gets the "ticket assigned to you" email and notification, and the
 * ticket shows under their name. No core file is modified.
 *
 * "EQUALLY"
 * ---------
 * Among the department's active staff, the ticket goes to whoever currently holds
 * the fewest OPEN tickets of that department; ties go to whoever was given a ticket
 * least recently, then the lowest staff id. So with five staff and five new tickets
 * each gets one, and if the team was already uneven the newcomers are used to even
 * it out, instead of a blind rotation that would keep the imbalance.
 *
 * Off switch: set the option payplex_ticket_auto_assign to 0. Default is on.
 */

hooks()->add_filter('before_ticket_created', 'payplex_ta_assign_ticket', 10, 2);
hooks()->add_action('ticket_created', 'payplex_ta_log_assignment');

/** Closed tickets do not count as load. Perfex's stock "Closed" status has id 5. */
const PAYPLEX_TA_CLOSED_STATUS = 5;

/**
 * Pure selection rule, kept free of the database so it can be tested on its own.
 *
 * @param array $candidates each: ['staffid' => int, 'open_count' => int, 'last_assigned' => string|null]
 *
 * @return int staff id, or 0 if there are no candidates
 */
function payplex_ta_pick(array $candidates)
{
    if (!$candidates) {
        return 0;
    }
    usort($candidates, function ($a, $b) {
        return [(int) $a['open_count'], (string) $a['last_assigned'], (int) $a['staffid']]
           <=> [(int) $b['open_count'], (string) $b['last_assigned'], (int) $b['staffid']];
    });

    return (int) $candidates[0]['staffid'];
}

/**
 * Active staff of a department with their current load in it.
 *
 * @return array rows for payplex_ta_pick()
 */
function payplex_ta_candidates($departmentId)
{
    $CI = &get_instance();
    $p  = db_prefix();

    $sql = "SELECT s.staffid,
                   (SELECT COUNT(*) FROM {$p}tickets t
                     WHERE t.assigned = s.staffid AND t.department = ? AND t.status <> ?) AS open_count,
                   (SELECT MAX(t2.date) FROM {$p}tickets t2
                     WHERE t2.assigned = s.staffid AND t2.department = ?) AS last_assigned
              FROM {$p}staff s
              JOIN {$p}staff_departments sd ON sd.staffid = s.staffid AND sd.departmentid = ?
             WHERE s.active = 1 AND s.is_not_staff = 0";

    return $CI->db->query($sql, [$departmentId, PAYPLEX_TA_CLOSED_STATUS, $departmentId, $departmentId])->result_array();
}

/** The ticket that was just auto-assigned in this request, for the activity log. */
function payplex_ta_remember($staffId = null, $departmentId = null)
{
    static $last = null;
    if ($staffId !== null) {
        $last = ['staff' => (int) $staffId, 'department' => (int) $departmentId];
    }

    return $last;
}

/**
 * Filter: before_ticket_created. Only tickets opened by a customer (or arriving by
 * email piping), never ones a staff member opens in the admin area, and never one
 * that already has an assignee.
 */
function payplex_ta_assign_ticket($data, $admin = null)
{
    try {
        if (get_option('payplex_ticket_auto_assign') === '0') {
            return $data;
        }
        if ($admin !== null && $admin !== false && $admin !== '') {
            return $data;   // opened by staff from the admin area: they choose the assignee
        }
        if (!empty($data['assigned']) || empty($data['department'])) {
            return $data;
        }

        $staffId = payplex_ta_pick(payplex_ta_candidates((int) $data['department']));
        if ($staffId > 0) {
            $data['assigned'] = $staffId;
            payplex_ta_remember($staffId, (int) $data['department']);
        }
    } catch (\Throwable $e) {
        // Never block a customer from opening a ticket because assignment failed.
        log_message('error', 'Ticket auto-assign failed: ' . $e->getMessage());
    }

    return $data;
}

/** Action: ticket_created. Leaves a trace in Utilities > Activity Log. */
function payplex_ta_log_assignment($ticketId)
{
    $last = payplex_ta_remember();
    if ($last) {
        log_activity('Ticket #' . (int) $ticketId . ' auto-assigned to staff #' . $last['staff']
            . ' (department #' . $last['department'] . ')');
    }
}
