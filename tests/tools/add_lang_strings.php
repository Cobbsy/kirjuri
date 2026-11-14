<?php
/**
 * Add top-level strings to the language files in conf/, each at its sorted place, keeping the
 * file's own encoding: CRLF line endings stay CRLF, DE, ES and FI keep \u escapes, EN and PT_BR
 * stay raw UTF-8. Slashes are escaped (\/), as the language editor writes them.
 *
 * Usage, from the repository root:
 *
 *     php tests/tools/add_lang_strings.php strings.json
 *
 * strings.json maps a language code to the strings to add:
 *
 *     {"EN": {"new_key": "Text"}, "DE": {"new_key": "Text"}, ...}
 *
 * Every code in CLAUDE.md needs every key; the tool adds what it is given and refuses a key that
 * a file already has. Nested groups (classifications, device types and so on) are not touched.
 */

/** Language files whose non-ASCII characters are written as \u escapes. */
const KIRJURI_LANG_ESCAPED = array('DE', 'ES', 'FI');

/**
 * Add $strings (key => text) to conf/lang_$code.JSON under $root.
 * @return int the number of strings added
 */
function kirjuri_add_lang_strings(string $root, string $code, array $strings): int
{
    if (!preg_match('/^[A-Z]{2}(_[A-Z]{2})?$/', $code)) {
        throw new InvalidArgumentException("Not a language code: $code");
    }
    $path = $root . '/conf/lang_' . $code . '.JSON';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException("Cannot read $path");
    }
    $before = json_decode($raw, true);
    if (!is_array($before)) {
        throw new RuntimeException("$path is not valid JSON");
    }
    $nl = strpos($raw, "\r\n") !== false ? "\r\n" : "\n";
    $lines = explode($nl, $raw);
    $flags = in_array($code, KIRJURI_LANG_ESCAPED, true) ? 0 : JSON_UNESCAPED_UNICODE;

    ksort($strings, SORT_STRING);
    foreach ($strings as $key => $text) {
        $key = (string) $key;
        if (array_key_exists($key, $before)) {
            throw new RuntimeException("lang_$code.JSON already has \"$key\"");
        }
        // The top-level string entries: four spaces, a quoted key, a colon and a quoted value.
        $entries = array();
        foreach ($lines as $i => $line) {
            if (preg_match('/^    "((?:[^"\\\\]|\\\\.)*)": "/', $line, $match)) {
                $entries[$i] = json_decode('"' . $match[1] . '"');
            }
        }
        if (!$entries) {
            throw new RuntimeException("lang_$code.JSON has no top-level strings to sort among");
        }
        $entry = '    ' . json_encode($key, $flags) . ': ' . json_encode((string) $text, $flags) . ',';
        $after = null;
        foreach ($entries as $i => $existing) {
            if (strcmp($existing, $key) > 0) {
                $after = $i;
                break;
            }
        }
        if ($after !== null) {
            array_splice($lines, $after, 0, array($entry));
        } else {
            // After the last string. If that line ended the object, it gets the comma and the new one goes without.
            $last = array_key_last($entries);
            if (substr(rtrim($lines[$last]), -1) === ',') {
                array_splice($lines, $last + 1, 0, array($entry));
            } else {
                $lines[$last] .= ',';
                array_splice($lines, $last + 1, 0, array(rtrim($entry, ',')));
            }
        }
    }

    $out = implode($nl, $lines);
    // The file must read back as before plus exactly the new strings.
    $expected = $before;
    foreach ($strings as $key => $text) {
        $expected[(string) $key] = (string) $text;
    }
    $after = json_decode($out, true);
    ksort($expected, SORT_STRING);
    if (is_array($after)) {
        ksort($after, SORT_STRING);
    }
    if ($after !== $expected) {
        throw new RuntimeException("Adding to lang_$code.JSON would have changed other entries; nothing was written");
    }
    file_put_contents($path, $out);
    return count($strings);
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if (count($argv) !== 2) {
        fwrite(STDERR, "Usage: php tests/tools/add_lang_strings.php strings.json\n");
        exit(2);
    }
    $all = json_decode((string) @file_get_contents($argv[1]), true);
    if (!is_array($all)) {
        fwrite(STDERR, "{$argv[1]} is not a JSON object of language code => {key: text}\n");
        exit(2);
    }
    try {
        foreach ($all as $code => $strings) {
            $added = kirjuri_add_lang_strings(getcwd(), (string) $code, (array) $strings);
            echo "lang_$code.JSON: $added added\n";
        }
    } catch (Exception $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}
