<?php

require_once './include_functions.php';
ksess_verify(0); // Admin only

$langfile_default = json_decode(file_get_contents('conf/lang_EN.JSON'), true); // Parse language file

$langfiles = array();
$conffiles = scandir('conf/');
foreach ($conffiles as $file) {
    if (substr($file, 0, 5) === "lang_") {
        $langfile = substr(substr($file, 5), 0, -5);
        $langfiles[] = $langfile;
    }
}
$langfiles = array_unique($langfiles);
$diff = array_diff_key($langfile_default, $_SESSION['lang']);
ksort($_SESSION['lang']);
ksort($diff);

$_SESSION['message_set'] = false;
echo kirjuri_render('lang_editor.twig', array(
        'langfiles' => $langfiles,
        'langfile' => $_SESSION['lang'],
        'diff' => $diff,
        'langfile_name' => $prefs['settings']['lang']
    ));
