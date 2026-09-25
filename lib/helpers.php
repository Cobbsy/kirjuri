<?php
/* Helper functions with no side effects when loaded: no session, database or
** settings access. include_functions.php loads this file, and the unit tests in
** tests/Unit load it on its own.
*/

define('LOGIN_MAX_FAILURES', 10); // Failed logins allowed per username...
define('LOGIN_FAILURE_WINDOW', 900); // ...within this many seconds before further attempts are refused.

function array_trim($array) {
    foreach ($array as $key => $value) {
        if ( ($value === "") || ($value === null) ) {
            unset($array[$key]);
        }
    }
    return $array;
}


function filter_username($username) {
    $strip_chars = array("!", "<", ">", "'", ":", ";", "/", "\"", "#", "%", "\\", "&", "|", "?", "*", "$", ")", "(", "[", "]", "{", "}");
    $username = strtolower(trim(str_replace($strip_chars, "", $username)));
    return $username;
}


function login_throttle_file($username) {
    if (!file_exists('cache/login_throttle')) {
        mkdir('cache/login_throttle');
    }
    return 'cache/login_throttle/' . hash('sha256', strtolower($username)) . '.json';
}


function login_throttle_state($username) {
    $file = login_throttle_file($username);
    $state = file_exists($file) ? json_decode(file_get_contents($file), true) : null;
    if (!is_array($state) || (time() - $state['last_failure']) > LOGIN_FAILURE_WINDOW) {
        return array('failures' => 0, 'last_failure' => 0);
    }
    return $state;
}


function login_throttled($username) {
    $state = login_throttle_state($username);
    return $state['failures'] >= LOGIN_MAX_FAILURES;
}


function login_throttle_record_failure($username) {
    $state = login_throttle_state($username);
    $state['failures']++;
    $state['last_failure'] = time();
    file_put_contents(login_throttle_file($username), json_encode($state), LOCK_EX);
}


function login_throttle_clear($username) {
    $file = login_throttle_file($username);
    if (file_exists($file)) {
        unlink($file);
    }
}


function ini_value($value) {
    // Make a value safe to write inside double quotes in an ini file.
    return str_replace(array('"', "\r", "\n"), array("'", ' ', ' '), (string) $value);
}


function generate_token($length) {
    // Generate a random hex token, used for session, CSRF and file name tokens.
    return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
}


function seconds_to_time($seconds) {
    // Thanks to https://stackoverflow.com/questions/8273804/convert-seconds-into-days-hours-minutes-and-seconds
    $dtF = new \DateTime('@0');
    $dtT = new \DateTime("@$seconds");
    return $dtF->diff($dtT)->format('%a days, %h hours, %i minutes and %s seconds');
}


function delete_directory($dir) {
    // Thanks to http://stackoverflow.com/questions/1653771/how-do-i-remove-a-directory-that-is-not-empty
    if (is_link($dir)) {
        return unlink($dir); // Remove the link itself, never what it points to.
    }
    if (!file_exists($dir)) {
        return true;
    }
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        if (!delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    return rmdir($dir);
}


/** Minimum length of passwords set in Kirjuri, on the web and from the command line. */
const KIRJURI_MIN_PASSWORD_LENGTH = 8;


/**
 * Parse a comma separated list of IPv4 addresses and ranges, e.g. "10.0.0.1, 10.1.0.0/16", for an
 * account's allow or deny list. Returns the entries, or array('') for an empty list (the stored form).
 * Throws InvalidArgumentException with the first invalid entry as its message.
 */
function kirjuri_parse_ip_list($text) {
    $entries = array();
    foreach (explode(',', (string) $text) as $entry) {
        $entry = trim($entry);
        if ($entry === '') {
            continue; // An empty first entry would make ip_allowed() skip the whole allow list.
        }
        $parts = explode('/', $entry, 2);
        $valid = filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && (!isset($parts[1]) || (ctype_digit($parts[1]) && (int) $parts[1] <= 32));
        if (!$valid) {
            throw new InvalidArgumentException($entry);
        }
        $entries[] = $entry;
    }
    return empty($entries) ? array('') : $entries;
}


function ip_in_range( $ip, $range ) {
    if ( strpos( $ip, ":" ) !== false ) {
        // Return default false for ipv6 addresses.
        return false;
    }
    // Copied and modified from https://gist.github.com/tott/7684443, thanks!
    if ( strpos( $range, '/' ) === false ) {
        $range .= '/32';
    }
    // $range is in IP/CIDR format eg 127.0.0.1/24
    list( $range, $netmask ) = explode( '/', $range, 2 );
    if ( !ctype_digit( $netmask ) ) {
        return false; // "10.0.0.1/" would otherwise be a /0 range matching every address.
    }
    $netmask = (int) $netmask;
    $range_decimal = ip2long( $range );
    $ip_decimal = ip2long( $ip );
    if ( ($range_decimal === false) || ($ip_decimal === false) || ($netmask < 0) || ($netmask > 32) ) {
        return false;
    }
    $wildcard_decimal = pow( 2, ( 32 - $netmask ) ) - 1;
    $netmask_decimal = ~ $wildcard_decimal;
    return ( $ip_decimal & $netmask_decimal ) == ( $range_decimal & $netmask_decimal );
}


function filter_numbers($a)  // Filter out everything but numbers.
{
    return preg_replace('/[^0-9]/', '', (string) $a);
}


function filter_letters_and_numbers($a) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string) $a);
}


function encrypt($in, $key) {
    // Encrypt a string with AES-256-CBC
    if (!function_exists('openssl_encrypt')) {
        event_log_write('0', 'Error', 'Missing dependency: OpenSSL. Can not encrypt audit log files. Please install OpenSSL.');
        return $in;
    }
    $iv = generate_token(16); // 16 printable characters, as decrypt() reads the IV from the first 16 bytes.
    $key = base64_encode($key);
    $in = gzencode($in);
    $encrypted = openssl_encrypt($in, 'AES-256-CBC', $key, 0, $iv);
    return $iv.$encrypted;
}


function decrypt($in, $key) {
    // Decrypt a string.
    if (!function_exists('openssl_decrypt')) {
        event_log_write('0', 'Error', 'Missing dependency: OpenSSL. Can not decrypt audit log files. Please install OpenSSL.');
        return $in;
    }
    $iv = substr($in, 0, 16);
    $key = base64_encode($key);
    $decrypted = openssl_decrypt(substr($in, 16), 'AES-256-CBC', $key, 0, $iv);
    $decrypted = gzdecode($decrypted);
    return $decrypted;
}


function api_key_for($user) {
    // The API key is derived from the username and password hash, so changing the password changes the key.
    return hash('sha1', $user['username'].$user['password']);
}


/**
 * A value made safe for a spreadsheet cell: text starting with =, +, -, @, a tab or a carriage return
 * is prefixed with an apostrophe, so Excel and LibreOffice show it instead of running it as a formula.
 */
function kirjuri_csv_cell($value) {
    $value = (string) $value;
    return ($value !== '' && strpos("=+-@\t\r", $value[0]) !== false) ? "'" . $value : $value;
}


/** Write rows (arrays with the same keys) as a semicolon separated CSV with a header row. */
function kirjuri_write_csv($handle, array $rows) {
    fwrite($handle, "sep=;\n"); // Tells Excel which separator to use.
    if (empty($rows)) {
        return;
    }
    fputcsv($handle, array_map('kirjuri_csv_cell', array_keys($rows[0])), ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, array_map('kirjuri_csv_cell', array_values($row)), ';', '"', '');
    }
}
