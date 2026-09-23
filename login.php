<?php
require_once 'include_functions.php';
if (isset($_SESSION['user']['username'])) {
    header('Location: index.php');
    die;
}

$_SESSION['message_set'] = false;
echo kirjuri_render('login.twig', array(
    ));
