<?php

/**
 * Kirjuri and the software it ships with, for the licences page. Each entry has a name, a version
 * ('' when unknown), the licence name and the file under $root that holds the licence text.
 *
 * The Composer packages come from vendor/composer/installed.json, so an update shows up here
 * without editing this file. The browser libraries under views/ are listed by hand;
 * tests/Unit/LicensesTest.php checks that every one of them is listed.
 */
function kirjuri_licensed_components($root) {
    $components = array(
        array('name' => 'Kirjuri', 'version' => trim((string) @file_get_contents($root . '/conf/RELEASE')), 'license' => 'MIT', 'file' => 'LICENSE'),
        array('name' => 'jQuery', 'version' => '3.7.1', 'license' => 'MIT', 'file' => 'views/js/jquery-3.7.1-LICENSE.txt'),
        array('name' => 'jQuery UI', 'version' => '1.13.3', 'license' => 'MIT', 'file' => 'views/jquery-ui/LICENSE.txt'),
        array('name' => 'Font Awesome', 'version' => '4.6.3', 'license' => 'SIL OFL 1.1, MIT', 'file' => 'views/font-awesome-4.6.3/LICENSE.txt'),
        array('name' => 'Chart.js', 'version' => '1.0.2', 'license' => 'MIT', 'file' => 'views/js/Chart.js-LICENSE.txt'),
        array('name' => 'FullCalendar', 'version' => '3.10.5', 'license' => 'MIT', 'file' => 'views/js/fullcalendar-3.10.5/LICENSE.txt'),
        array('name' => 'Moment.js', 'version' => '2.30.1', 'license' => 'MIT', 'file' => 'views/js/fullcalendar-3.10.5/lib/moment-LICENSE.txt'),
        array('name' => 'vis.js', 'version' => '4.18.0', 'license' => 'MIT, Apache 2.0', 'file' => 'views/js/vis-4.18.0/LICENSE-MIT'),
        array('name' => 'Freepik device icons (flaticon.com)', 'version' => '', 'license' => 'CC BY 3.0', 'file' => 'views/img/svg/CREDITS'),
    );

    $installed = json_decode((string) @file_get_contents($root . '/vendor/composer/installed.json'), true);
    $packages = isset($installed['packages']) ? $installed['packages'] : (is_array($installed) ? $installed : array());
    foreach ($packages as $package) {
        if (!isset($package['name'])) {
            continue;
        }
        $dir = 'vendor/' . $package['name'];
        // Not glob() with GLOB_BRACE, which some platforms lack.
        $files = is_dir($root . '/' . $dir) ? preg_grep('/^(licen[cs]e|copying)(\.\w+)?$/i', scandir($root . '/' . $dir)) : array();
        $components[] = array(
            'name' => $package['name'],
            'version' => isset($package['version']) ? ltrim($package['version'], 'v') : '',
            'license' => isset($package['license']) ? implode(', ', (array) $package['license']) : '',
            'file' => $files ? $dir . '/' . reset($files) : '',
        );
    }

    foreach ($components as $i => $component) {
        $path = $root . '/' . $component['file'];
        $components[$i]['text'] = ($component['file'] !== '' && is_file($path)) ? (string) file_get_contents($path) : '';
    }
    return $components;
}
