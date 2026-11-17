<?php
/**
 * Build the translation review page: every string written without a native speaker, in groups, with
 * where it shows in Kirjuri and its current text in each language file. Translators confirm or
 * correct each one on the page; their answers are kept by the page, one per key and language.
 *
 * Usage, from the repository root:
 *
 *     php tests/tools/translation_review.php out.html
 *
 * The page is the template tests/tools/translation_review.html with the groups put in. It is published
 * as a claude.ai artifact (see docs/ROADMAP.md, Translations) and needs that artifact's db and user
 * capabilities to share answers. Add new strings to kirjuri_translation_review_groups() below, or to a
 * group that collects them by name, and publish the page again: answers already given are kept.
 */

const KIRJURI_REVIEW_LANGUAGES = array('EN', 'DE', 'ES', 'FI', 'PT_BR');

/**
 * The groups of strings to review, each string as key => array(where, context, only), where `only`
 * limits a string to some languages. A key with a dot ("device_actions.device_action12") is a nested
 * string; device statuses are shown without their "step|" prefix.
 */
function kirjuri_translation_review_groups(string $root): array
{
    $english = kirjuri_translation_review_language($root, 'EN');
    $groups = array(
        array('id' => 'redesign', 'title' => 'Redesign', 'blurb' => 'Short texts added when the pages were redesigned.', 'strings' => array(
            'back_up_database' => array('Settings', 'Button on the Settings page, for administrators. It downloads a backup of the whole database.'),
            'find_a_string' => array('Language editor', 'Label of the search box at the top of the language editor, used to find a text by its name or wording.'),
            'last_activity' => array('User management', 'Under each of a user\'s open sessions, followed by a date and time: “Last activity: 16.10.2026 14:52”.'),
            'missing_strings' => array('Language editor', 'Heading of the card that lists the texts a language file does not have yet, followed by their count: “Missing strings (3)”.'),
            'yes' => array('Settings', 'One of the two choices for each on/off setting, such as whether a case shows its event log.'),
            'no' => array('Settings', 'The other choice for each on/off setting.'),
            'no_tools' => array('Tools', 'Shown in the tools table before any forensic tools (write blockers, imagers and so on) have been added.'),
            'reserve' => array('Tools', 'Button on a tool\'s page that books the tool for the chosen dates and times.'),
            'online' => array('User management', 'Status label on a user\'s session that was active in the last minute.'),
            'offline' => array('User management', 'Status label on a user\'s session that has been idle longer than a minute.'),
            'stalled' => array('Front page', 'Filter button and row label for open examination requests with no update for 120 days (a setting). Sits beside the filters New, Open, Assigned to me and Ready.'),
        )),
        array('id' => 'work', 'title' => 'Search, requests and tools', 'blurb' => 'Hints and messages added with the search, request and tool improvements.', 'strings' => array(
            'search_hint' => array('Front page', 'Explanation under the search box, also shown when the pointer rests on the box and on the help page. It tells people how the search reads what they type.'),
            'search_years' => array('Front page', 'Above the search results, followed by the years the results come from and how many in each: “Found in: 2026 (4), 2025 (1)”.'),
            'search_limited' => array('Front page', 'Warning above the search results when there are too many to show. %d becomes the number shown (500). Keep %d exactly as it is.'),
            'assign_examiner_hint' => array('Request page', 'Hint under the examiner choice on a new request, above the Start investigation button.'),
            'not_saved' => array('Request and device pages', 'Appears next to a device\'s status after it is changed, when the change could not be saved.'),
            'reservation_in_past' => array('Tools', 'Error message when someone books a tool for dates that have already passed.'),
        )),
        array('id' => 'help', 'title' => 'Help and licences pages', 'blurb' => 'The short user guide on the help page. Names in quotes are buttons and tabs, and already match their wording in your language. Check that each paragraph reads naturally.', 'strings' => array(
            'help_intro' => array('Help page', 'The opening paragraph.'),
            'help_requests_title' => array('Help page', 'Heading of the section on adding a request.'),
            'help_requests_text' => array('Help page', 'Section on adding a request. A .krf file is Kirjuri\'s export format.'),
            'help_devices_title' => array('Help page', 'Heading of the section on devices and media.'),
            'help_devices_text' => array('Help page', 'Section on adding devices and media, stickers and each device\'s status.'),
            'help_statuses_title' => array('Help page', 'Heading of the section on request statuses.'),
            'help_statuses_text' => array('Help page', 'Section on how a request moves from new to open to ready. The lightning bolt and moon are icons on the front page.'),
            'help_report_title' => array('Help page', 'Heading of the section on the examination report.'),
            'help_report_text' => array('Help page', 'Section on writing and printing the report, and the case log.'),
            'help_search_title' => array('Help page', 'Heading of the section on searching.'),
            'help_search_text' => array('Help page', 'First sentence of the section on searching. The search hint from the group above follows it.'),
            'help_access_title' => array('Help page', 'Heading of the section on access levels.'),
            'help_access_text' => array('Help page', 'Section on what each access level allows.'),
            'help_messages_title' => array('Help page', 'Heading of the section on messages.'),
            'help_messages_text' => array('Help page', 'Section on messages between users.'),
            'help_licenses_text' => array('Help page', 'Paragraph at the bottom, above the link to the licences page.'),
            'licenses_intro' => array('Licences page', 'The opening paragraph, above the list of bundled software.'),
        )),
    );

    // The settings page: a label and a line of help for each shipped setting, in the order of conf/settings.conf.
    $settings = array('id' => 'settings', 'title' => 'Settings page', 'blurb' => 'For administrators: the name of each setting and the line of help under it. Values such as -120 days, progress-bar or ldaps:// are what Kirjuri reads, so keep them as they are.', 'strings' => array());
    foreach (array_keys(parse_ini_file($root . '/conf/settings.conf', true)['settings']) as $setting) {
        $settings['strings']['setting_' . $setting] = array('Settings page', 'The name of a setting. Its help line says: ' . $english['setting_' . $setting . '_help']);
        $settings['strings']['setting_' . $setting . '_help'] = array('Settings page', 'The help line under the setting "' . $english['setting_' . $setting] . '".');
    }
    $groups[] = $settings;

    // Two-factor sign-in: every two_factor_* string and its help page section, said where each one shows.
    $two_factor = array('id' => 'two-factor', 'title' => 'Two-factor sign-in', 'blurb' => 'Signing in with a password and a six-digit code from an authenticator app on the phone, with recovery codes for a lost phone. Use the words your language\'s authenticator apps use.', 'strings' => array());
    $places = kirjuri_translation_review_places($root);
    foreach (array_keys($english) as $key) {
        if (strpos($key, 'two_factor') === 0 || strpos($key, 'help_two_factor') === 0) {
            $two_factor['strings'][$key] = array($places[$key] ?? 'Two-factor sign-in', kirjuri_translation_review_placeholders($english[$key]));
        }
    }
    $groups[] = $two_factor;

    $groups[] = array('id' => 'statuses', 'title' => 'Device statuses', 'blurb' => 'Choices in the status menu of every device, used all day. These look wrong in your language; confirm the suggestion in the note or write a better one.', 'strings' => array(
        'device_actions.device_action12' => array('Device status menu', 'For a device that does not work. The current word means "interrupted". Suggested: "defekt", the word the help page already uses.', array('DE')),
        'device_actions.device_action7' => array('Device status menu', 'For a device whose examination is finished, before the report. The current word means "re-analyse". Suggested: "Análisis terminado".', array('ES')),
        'device_actions.device_action2' => array('Device status menu', 'For a device examined while running, before it is copied. It is still in English. Suggested: "Exame ao vivo".', array('PT_BR')),
    ));
    return $groups;
}

/** Where a two-factor string shows, from the first page that uses it (strings shared by several pages say so). */
function kirjuri_translation_review_places(string $root): array
{
    $pages = array(
        'views/two_factor_setup.twig' => 'Setting up two-factor sign-in',
        'views/two_factor.twig' => 'Two-factor sign-in page',
        'views/login.twig' => 'Sign-in page, the code step',
        'views/user_fields.twig' => 'User management, for administrators',
        'views/users.twig' => 'User management, for administrators',
        'views/settings.twig' => 'Settings page',
        'views/help.twig' => 'Help page',
        'actions/two_factor.php' => 'Message after a two-factor action',
        'lib/auth.php' => 'Message at sign-in',
    );
    $places = array('two_factor' => 'Menu item and page heading');
    foreach ($pages as $file => $place) {
        preg_match_all('/\b(?:help_)?two_factor[a-z_]*\b/', (string) file_get_contents($root . '/' . $file), $matches);
        foreach ($matches[0] as $key) {
            $places += array($key => $place);
        }
    }
    return $places;
}

/** A context line that names the placeholders a string has, so translators keep them. */
function kirjuri_translation_review_placeholders(string $text): string
{
    preg_match_all('/%[ds]/', $text, $matches);
    if (!$matches[0]) {
        return '';
    }
    $names = array_unique($matches[0]);
    return implode(' and ', $names) . ' ' . (count($names) > 1 ? 'are' : 'is') . ' replaced by a number or a date. Keep ' . implode(' and ', $names) . ' exactly as ' . (count($names) > 1 ? 'they are' : 'it is') . '.';
}

function kirjuri_translation_review_language(string $root, string $code): array
{
    $strings = json_decode((string) file_get_contents($root . '/conf/lang_' . $code . '.JSON'), true);
    if (!is_array($strings)) {
        throw new RuntimeException("conf/lang_$code.JSON is not valid JSON");
    }
    return $strings;
}

/** The groups with each string's text in every language. Throws when a language file lacks a string. */
function kirjuri_translation_review_data(string $root): array
{
    $langs = array();
    foreach (KIRJURI_REVIEW_LANGUAGES as $code) {
        $langs[$code] = kirjuri_translation_review_language($root, $code);
    }
    $data = array();
    foreach (kirjuri_translation_review_groups($root) as $group) {
        $strings = array();
        foreach ($group['strings'] as $key => $info) {
            $path = explode('.', $key);
            $entry = array('key' => end($path), 'where' => $info[0], 'context' => $info[1]);
            if (isset($info[2])) {
                $entry['only'] = $info[2];
            }
            foreach ($langs as $code => $file) {
                $value = $file;
                foreach ($path as $part) {
                    $value = is_array($value) && isset($value[$part]) ? $value[$part] : null;
                }
                if (!is_string($value)) {
                    throw new RuntimeException("conf/lang_$code.JSON has no $key");
                }
                $entry[$code] = count($path) > 1 ? preg_replace('/^\d+\|/', '', $value) : $value;
            }
            $strings[] = $entry;
        }
        $group['strings'] = $strings;
        $data[] = $group;
    }
    return $data;
}

/** The page: the template with the groups in place of its one GROUPS marker. */
function kirjuri_translation_review_page(string $root, string $template): string
{
    if (substr_count($template, '/*GROUPS*/') !== 1) {
        throw new RuntimeException('The template needs exactly one /*GROUPS*/ marker');
    }
    // JSON_HEX_TAG and JSON_HEX_AMP keep a string such as "</script>" from ending the page's script.
    $json = json_encode(kirjuri_translation_review_data($root), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR);
    return str_replace('/*GROUPS*/', $json, $template);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if ($argc !== 2) {
        fwrite(STDERR, "Usage: php tests/tools/translation_review.php out.html\n");
        exit(2);
    }
    $root = dirname(__DIR__, 2);
    try {
        $page = kirjuri_translation_review_page($root, (string) file_get_contents(__DIR__ . '/translation_review.html'));
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
    file_put_contents($argv[1], $page);
    $count = 0;
    foreach (kirjuri_translation_review_data($root) as $group) {
        $count += count($group['strings']);
    }
    echo "Wrote $argv[1]: $count strings\n";
}
