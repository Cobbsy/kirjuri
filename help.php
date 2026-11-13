<?php
require_once './include_functions.php';
ksess_verify(3);
$_SESSION['message_set'] = false;
echo kirjuri_render('help.twig', array(
        'release_version' => trim((string) file_get_contents('conf/RELEASE')),
    ));
