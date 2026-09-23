<?php
// Configuration files: database credentials, settings and language strings. Paths are relative to
// the Kirjuri folder, which is the working directory for web requests and for bin/kirjuri.

function kirjuri_mysql_config() {
    // The credentials array written by install.php, or null before installation.
    return file_exists('conf/mysql_credentials.php') ? include 'conf/mysql_credentials.php' : null;
}


function kirjuri_settings_file() {
    // settings.local holds the settings saved from the settings page; settings.conf has the defaults.
    if (file_exists('conf/settings.local')) {
        return 'conf/settings.local';
    }
    return file_exists('conf/settings.conf') ? 'conf/settings.conf' : null;
}


function kirjuri_load_settings($settings_file) {
    $prefs = parse_ini_file($settings_file, true);
    if ($prefs === false) {
        throw new RuntimeException('Can not parse the settings file ' . $settings_file . '.');
    }
    $prefs['settings']['release'] = file_get_contents('conf/RELEASE');
    return $prefs;
}


function kirjuri_language_file($prefs) {
    // Language files are JSON; older installations may still point at an .conf ini file.
    $name = basename($prefs['settings']['lang'], '.conf');
    return file_exists('conf/' . $name . '.JSON') ? 'conf/' . $name . '.JSON' : 'conf/' . $name . '.conf';
}


function kirjuri_load_language($prefs) {
    $file = kirjuri_language_file($prefs);
    $lang = (substr($file, -5) === '.JSON') ? json_decode(file_get_contents($file), true) : parse_ini_file($file, true);
    if (!is_array($lang)) {
        throw new RuntimeException('Can not read the language file ' . $file . '.');
    }
    return $lang;
}
