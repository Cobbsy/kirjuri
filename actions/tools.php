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
    // Check for CSRF token, no POST token present if removing a reservation
    if (isset($_POST['token'])) {
        ksess_validate($_POST['token']);
    } elseif (isset($_GET['token'])) {
        ksess_validate($_GET['token']);
    } else {
        // Y U no token? U die!!
        die;
    }
    // Concatenate time and date to one string.
    $res_start = $_POST['reserve_start_date'] . " " . $_POST['reserve_start_time'];
    $res_end   = $_POST['reserve_end_date'] . " " . $_POST['reserve_end_time'];
    // If tool ID is set in POST, then this is a reservation, check for empty vars.
    if (isset($_POST['tool_id'])) {
        $returnid = filter_numbers($_POST['tool_id']);
        if ((empty($res_start)) || (empty($res_end)) || (empty($returnid))) {
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
    // Get tool reservations stored as a JSON value in attr_4.
    $query = $kirjuri_database->prepare('SELECT attr_4 FROM tools WHERE id = :tool_id');
    $query->execute(array(
            ':tool_id' => $returnid
        ));
    $res_json = $query->fetch(PDO::FETCH_ASSOC);
    $res_json = $res_json['attr_4'];
    // Decode the array if it is populated, set an empty array if not.
    if (!empty($res_json)) {
        $res_arr = json_decode($res_json, true);
    } else {
        $res_arr = array();
    }
    // Compare the proposed reservation date with existing dates if POST data present.
    // Convert the string to epoch for easier comparison.
    if (isset($_POST['tool_id'])) {
        $c = strtotime($res_start);
        $d = strtotime($res_end);
        foreach ($res_arr as $key => $compare) {
            $a = strtotime($compare['reserve_start']);
            $b = strtotime($compare['reserve_end']);
            // If new reservation overlaps old reservations, exit with error and highlight first conflict.
            if ( ($a <= $c) && ($c < $b) || ($a < $d) && ($d <= $b) || ($c < $a) && ($d > $b)) {
                message('error', $_SESSION['lang']['calendar_conflict'] . ": " . $compare['reserve_start'] . ' -> ' . $compare['reserve_end'] . ": " . $compare['reserved_for']);
                header('Location: tools.php?populate=' . $returnid . '&highlight=' . $key);
                die;
            }
        }
    }
    // If dropping a reservation, check that the user is admin or the reservation is for them.
    // Using real names here, so changing user's real name will prevent removing old reservations.
    if (isset($_GET['drop'])) {
        if (isset($res_arr[$_GET['drop']]) && (($_SESSION['user']['access'] === "0") || ($res_arr[$_GET['drop']]['reserved_for'] === $_SESSION['user']['name']))) {
            event_log_write('0', "Calendar", "Removed tool ID " . $returnid . " reservation: " . $res_arr[$_GET['drop']]['reserve_start'] . " -> " . $res_arr[$_GET['drop']]['reserve_end'] . " for " . $res_arr[$_GET['drop']]['reserved_for']);
            // Remove the reservation from the reservations array.
            unset($res_arr[$_GET['drop']]);
        }
    } else {
        event_log_write('0', "Calendar", "Reserved tool ID " . $returnid . ": " . $res_start . " -> " . $res_end . " for " . $_POST['reserved_for']);
        // Count from zero and find the next free array number.
        $i = 0;
        while (isset($res_arr[$i]) === true) {
            $i++;
        }
        // Populate the free number.
        $res_arr[$i]['reserved_for']  = $_POST['reserved_for'];
        $res_arr[$i]['reserve_start'] = $res_start;
        $res_arr[$i]['reserve_end']   = $res_end;
        // Do not allow oversized comments. 500 characters should be enough.
        $res_arr[$i]['comment']       = substr(isset($_POST['comment']) ? $_POST['comment'] : '', 0, 500);
    }
    // Declare a function for sorting the array by start date
    function date_compare($a, $b) {
        $t1 = strtotime($a['reserve_start']);
        $t2 = strtotime($b['reserve_start']);
        return $t1 - $t2;
    }


    // Sort the array
    usort($res_arr, 'date_compare');
    // Encode the reservations array back to JSON
    $res_json = json_encode($res_arr);
    // Write the JSON string back to the database.
    $query    = $kirjuri_database->prepare('UPDATE tools SET attr_4 = :attr_4 WHERE id = :tool_id');
    $query->execute(array(
            ':tool_id' => $returnid,
            ':attr_4' => $res_json
        ));
    // Done, return to tools.
    header('Location: tools.php?populate=' . $returnid);
    die;

case 'add_tool':
    ksess_verify(0);
    ksess_validate($_POST['token']);
    if (!empty($_POST['product_name'])) {
        $query = $kirjuri_database->prepare('INSERT INTO tools (product_name, hw_version, sw_version, serialno, flags, attr_1, attr_2, attr_3, attr_4, attr_5, attr_6, attr_7, attr_8) VALUES (
    :product_name, :hw_version, :sw_version, :serialno, :flags, NOW(), "", :comment, NULL, NULL, NULL, NULL, NULL);');
        $query->execute(array(
                ':product_name' => trim(substr($_POST['product_name'], 0, 128)),
                ':hw_version' => trim(substr($_POST['hw_version'], 0, 64)),
                ':sw_version' => trim(substr($_POST['sw_version'], 0, 64)),
                ':serialno' => $_POST['serialno'],
                ':comment' => $_POST['comment'],
                ':flags' => $_POST['flag1'] . $_POST['flag2']
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
    if ($_POST['drop_tool'] === "yes") {
        $query = $kirjuri_database->prepare('DELETE FROM tools WHERE id = :tool_id');
        $query->execute(array(
                ':tool_id' => $_POST['tool_id']
            ));
        event_log_write('0', 'Remove', 'tool ID ' . $_POST['tool_id'] . ' removed: ' . trim(substr($_POST['product_name'], 0, 128)));
        message('info', $_SESSION['lang']['tool_removed'] . ": " . trim(substr($_POST['product_name'], 0, 128)));
    } else {
        $query = $kirjuri_database->prepare('UPDATE tools SET hw_version = :hw_version, sw_version = :sw_version, attr_3 = :comment, flags = :flags,
        attr_2 = CONCAT(NOW(),";", :hw_version_old, " -> ", :hw_version, ";", :sw_version_old, " -> ", :sw_version, ";", :flags, ";", ", ", IFNULL(attr_2,"")) WHERE id = :tool_id');
        $query->execute(array(
                ':tool_id' => $_POST['tool_id'],
                ':hw_version' => trim(substr($_POST['hw_version'], 0, 64)),
                ':sw_version' => trim(substr($_POST['sw_version'], 0, 64)),
                ':hw_version_old' => trim(substr($_POST['hw_version_old'], 0, 64)),
                ':sw_version_old' => trim(substr($_POST['sw_version_old'], 0, 64)),
                ':comment' => $_POST['comment'],
                ':flags' => $_POST['flag1']
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
