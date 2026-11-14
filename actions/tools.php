<?php
// Forensic tools and their reservations.
// Included by submit.php, which prepares $_POST and $action; never requested directly.

if (!defined('KIRJURI_ACTION')) {
    http_response_code(404);
    exit;
}

switch ($action) {

case 'reserve_tool':
    // Set variables that might not be present.
    $_POST['reserve_start_date'] = isset($_POST['reserve_start_date']) ? $_POST['reserve_start_date'] : '';
    $_POST['reserve_start_time'] = isset($_POST['reserve_start_time']) ? $_POST['reserve_start_time'] : '';
    $_POST['reserve_end_date']   = isset($_POST['reserve_end_date']) ? $_POST['reserve_end_date'] : '';
    $_POST['reserve_end_time']   = isset($_POST['reserve_end_time']) ? $_POST['reserve_end_time'] : '';
    ksess_verify(1);
    ksess_validate(posted_token());
    // Concatenate time and date to one string.
    $res_start = $_POST['reserve_start_date'] . " " . $_POST['reserve_start_time'];
    $res_end   = $_POST['reserve_end_date'] . " " . $_POST['reserve_end_time'];
    // If tool ID is set in POST, then this is a reservation, check for empty vars.
    $reserved_for = null;
    if (isset($_POST['tool_id'])) {
        $returnid = filter_numbers($_POST['tool_id']);
        // The reservation is for a user, chosen by username: display names change.
        foreach ($_SESSION['all_users'] as $user) {
            if (isset($_POST['reserved_for']) && $user['username'] === $_POST['reserved_for']) {
                $reserved_for = $user;
            }
        }
        // Check the submitted fields: the concatenated strings above always contain at least a space.
        if (empty($_POST['reserve_start_date']) || empty($_POST['reserve_start_time']) || empty($_POST['reserve_end_date'])
            || empty($_POST['reserve_end_time']) || empty($returnid) || $reserved_for === null
            || strtotime($res_start) === false || strtotime($res_end) === false) {
            message('error', $_SESSION['lang']['missing_form_field']);
            header('Location: tools.php?populate=' . $returnid);
            die;
        }
    } else {
        // Get tool id from GET variable if dropping a reservation.
        $returnid = filter_numbers($_GET['returnid']);
    }
    // If start of reservation is after end, swap values.
    if (strtotime($res_start) > strtotime($res_end)) {
        // If end is before start, swap values.
        $tmp       = $res_end;
        $res_end   = $res_start;
        $res_start = $tmp;
        unset($tmp);
    }
    // A reservation that has already ended is a mistake, most likely a wrong date. One that has started is fine.
    if (isset($_POST['tool_id']) && strtotime($res_end) <= time()) {
        message('error', $_SESSION['lang']['reservation_in_past']);
        header('Location: tools.php?populate=' . $returnid);
        die;
    }
    $res_arr = kirjuri_tool_reservations($kirjuri_database, $returnid);
    // A new reservation must not collide with existing ones; highlight the first conflict.
    if (isset($_POST['tool_id'])) {
        $conflict = kirjuri_find_reservation_conflict($res_arr, $res_start, $res_end);
        if ($conflict !== null) {
            $compare = kirjuri_present_reservations(array($res_arr[$conflict]), $_SESSION['all_users'], $_SESSION['user'])[0];
            message('error', $_SESSION['lang']['calendar_conflict'] . ": " . $compare['reserve_start'] . ' -> ' . $compare['reserve_end'] . ": " . $compare['name']);
            header('Location: tools.php?populate=' . $returnid . '&highlight=' . $conflict);
            die;
        }
    }
    // If dropping a reservation, check that the user is admin or the reservation is for them.
    if (isset($_GET['drop'])) {
        if (isset($res_arr[$_GET['drop']]) && is_array($res_arr[$_GET['drop']])
            && (($_SESSION['user']['access'] === "0") || kirjuri_reservation_belongs_to($res_arr[$_GET['drop']], $_SESSION['user']))) {
            event_log_write('0', "Calendar", "Removed tool ID " . $returnid . " reservation: " . $res_arr[$_GET['drop']]['reserve_start'] . " -> " . $res_arr[$_GET['drop']]['reserve_end'] . " for " . $res_arr[$_GET['drop']]['reserved_for']);
            // Remove the reservation from the reservations array.
            unset($res_arr[$_GET['drop']]);
        }
    } elseif ($reserved_for !== null) {
        event_log_write('0', "Calendar", "Reserved tool ID " . $returnid . ": " . $res_start . " -> " . $res_end . " for " . $reserved_for['name'] . " (" . $reserved_for['username'] . ")");
        // Count from zero and find the next free array number.
        $i = 0;
        while (isset($res_arr[$i]) === true) {
            $i++;
        }
        // Populate the free number.
        $res_arr[$i]['username']      = $reserved_for['username'];
        $res_arr[$i]['reserved_for']  = $reserved_for['name'];
        $res_arr[$i]['reserve_start'] = $res_start;
        $res_arr[$i]['reserve_end']   = $res_end;
        // Do not allow oversized comments. 500 characters should be enough.
        $res_arr[$i]['comment']       = substr(isset($_POST['comment']) ? $_POST['comment'] : '', 0, 500);
    }
    $res_arr = kirjuri_sort_reservations($res_arr);
    kirjuri_save_tool_reservations($kirjuri_database, $returnid, $res_arr);
    // Done, return to tools.
    header('Location: tools.php?populate=' . $returnid);
    die;

case 'add_tool':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    kirjuri_default_checkboxes(array('flag1', 'flag2'));
    if (!empty($_POST['product_name'])) {
        kirjuri_add_tool($kirjuri_database, array(
                'product_name' => $_POST['product_name'],
                'hw_version' => $_POST['hw_version'],
                'sw_version' => $_POST['sw_version'],
                'serialno' => $_POST['serialno'],
                'comment' => $_POST['comment'],
                'flags' => $_POST['flag1'] . $_POST['flag2'],
            ));
        event_log_write('0', 'Add', 'tool created: ' . trim(substr($_POST['product_name'], 0, 64)));
        message('info', $_SESSION['lang']['tool_added'] . ": " . trim(substr($_POST['product_name'], 0, 128)));
        header('Location: tools.php');
        die;
    }
    else {
        header('Location: tools.php');
        die;
    }

case 'update_tool':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    kirjuri_default_checkboxes(array('flag1', 'flag2'));
    if ($_POST['drop_tool'] === "yes") {
        kirjuri_delete_tool($kirjuri_database, $_POST['tool_id']);
        event_log_write('0', 'Remove', 'tool ID ' . $_POST['tool_id'] . ' removed: ' . trim(substr($_POST['product_name'], 0, 128)));
        message('info', $_SESSION['lang']['tool_removed'] . ": " . trim(substr($_POST['product_name'], 0, 128)));
    } else {
        kirjuri_update_tool($kirjuri_database, $_POST['tool_id'], array(
                'hw_version' => $_POST['hw_version'],
                'sw_version' => $_POST['sw_version'],
                'hw_version_old' => $_POST['hw_version_old'],
                'sw_version_old' => $_POST['sw_version_old'],
                'comment' => $_POST['comment'],
                'flags' => $_POST['flag1'],
            ));
        event_log_write('0', 'Update', 'tool updated: ' . trim(substr($_POST['product_name'], 0, 128)) . ", HW version: " .
            $_POST['hw_version_old'] . " -> " . $_POST['hw_version'] . ", SW version: " .
            $_POST['sw_version_old'] . " -> " . $_POST['sw_version'] . ", Comment: " .
            $_POST['comment_old'] . " -> " . $_POST['comment']);
        message('info', $_SESSION['lang']['tool_updated'] . ": " . trim(substr($_POST['product_name'], 0, 128)));
    }
    header('Location: tools.php?populate=' . $_POST['tool_id']);
    die;
}
