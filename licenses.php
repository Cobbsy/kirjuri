<?php
require_once './include_functions.php';
ksess_verify(3);
$_SESSION['message_set'] = false;
echo kirjuri_render('licenses.twig', array(
        'components' => kirjuri_licensed_components(__DIR__),
    ));
