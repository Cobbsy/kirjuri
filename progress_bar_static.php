<?php
require_once './include_functions.php';
ksess_verify(2); // View only or higher
$query = $kirjuri_database->prepare('SELECT device_action FROM exam_requests where id=:id AND parent_id != id');
$query->execute(array(':id' => $_GET['uid']));
$device_action = $query->fetch(PDO::FETCH_ASSOC);
if ($device_action === false) {
    die;
}
echo $twig->render('progress_bar.twig', array('device_action' => $device_action['device_action'], 'settings' => $prefs['settings']));
?>
