<?php
// HTTPS: moving pages opened over plain HTTP to the HTTPS address in the settings, and asking browsers to keep to
// HTTPS (HSTS). Both are off until an administrator turns them on from the settings page opened over HTTPS at that
// address, so a mistyped address or a missing certificate can not send everyone somewhere that does not work.

/** The HSTS durations the settings page offers, in seconds: off, five minutes to try it out, and a year. */
const KIRJURI_HSTS_CHOICES = array('0', '300', '31536000');


/**
 * Whether the browser reached Kirjuri over HTTPS: directly, or through a proxy that ends HTTPS in front of it and says
 * so in X-Forwarded-Proto. A client can send that header itself, but only to its own request, and it decides
 * nothing that matters for anyone else: whether this request is moved, and whether its cookie is marked Secure.
 */
function kirjuri_request_is_https(array $server) {
    $https = strtolower(trim((string) ($server['HTTPS'] ?? '')));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    $forwarded = explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
    return strtolower(trim($forwarded[0])) === 'https';
}


/**
 * An HTTPS address as Kirjuri keeps it: scheme, host and port only, lowercase, without a trailing slash, such as
 * https://kirjuri.example.org or https://10.0.0.5:8443. Null for anything else, a path or a login included.
 */
function kirjuri_valid_https_address($value) {
    $value = strtolower(rtrim(trim((string) $value), '/'));
    $label = '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?';
    if (!preg_match('/^https:\/\/(' . $label . '(?:\.' . $label . ')*|\[[0-9a-f:.]+\])(?::(\d{1,5}))?$/D', $value, $match)) {
        return null;
    }
    if (isset($match[2]) && ((int) $match[2] < 1 || (int) $match[2] > 65535)) {
        return null;
    }
    return $value;
}


/** The https_address setting, or null when it is empty or not a valid address (doctor reports that). */
function kirjuri_https_address(array $settings) {
    return kirjuri_valid_https_address($settings['https_address'] ?? '');
}


/** Where to send a request that came over plain HTTP: the HTTPS address with the same path and query. Null to serve it. */
function kirjuri_https_redirect(array $settings, array $server) {
    $address = kirjuri_https_address($settings);
    if ($address === null || kirjuri_request_is_https($server)) {
        return null;
    }
    $uri = (string) ($server['REQUEST_URI'] ?? '');
    if ($uri === '' || $uri[0] !== '/' || strpos($uri, '//') === 0 || preg_match('/[\x00-\x20\x7f]/', $uri)) {
        $uri = '/';
    }
    return $address . $uri;
}


/** The Strict-Transport-Security header for this request, or null: only over HTTPS, and only when turned on. */
function kirjuri_hsts_header(array $settings, array $server) {
    $seconds = trim((string) ($settings['hsts_max_age'] ?? '0'));
    if (!kirjuri_request_is_https($server) || !ctype_digit($seconds) || (int) $seconds === 0) {
        return null;
    }
    return 'max-age=' . (int) $seconds;
}


/** Whether $address (from kirjuri_valid_https_address) is the host and port this request was sent to. */
function kirjuri_https_address_is_current($address, array $server) {
    $host = strtolower(trim((string) ($server['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return false;
    }
    $without_default_port = function ($authority) {
        return substr($authority, -4) === ':443' ? substr($authority, 0, -4) : $authority;
    };
    return $without_default_port(substr($address, strlen('https://'))) === $without_default_port($host);
}


/**
 * Check the HTTPS settings an administrator is saving, before they are written. A change that turns either on, or
 * changes the address, is taken only from the settings page opened over HTTPS, and the address only when it is the
 * one the page is open at: then HTTPS is known to work there. Turning them off is taken from anywhere.
 *
 * Returns the settings to save, with a refused value put back to its current one, and the language key of the
 * reason one was refused, or null.
 */
function kirjuri_check_https_settings(array $posted, array $current, array $server) {
    $problem = null;
    $https = kirjuri_request_is_https($server);

    if (array_key_exists('https_address', $posted)) {
        $now = trim((string) ($current['https_address'] ?? ''));
        $wanted = trim((string) $posted['https_address']);
        $address = kirjuri_valid_https_address($wanted);
        if ($wanted === '' || $wanted === $now || $address === $now) {
            $posted['https_address'] = $address ?? $wanted;
        } elseif ($address === null) {
            $posted['https_address'] = $now;
            $problem = 'https_address_invalid';
        } elseif (!$https) {
            $posted['https_address'] = $now;
            $problem = 'https_needs_https';
        } elseif (!kirjuri_https_address_is_current($address, $server)) {
            $posted['https_address'] = $now;
            $problem = 'https_address_other_host';
        } else {
            $posted['https_address'] = $address;
        }
    }

    if (array_key_exists('hsts_max_age', $posted)) {
        $now = trim((string) ($current['hsts_max_age'] ?? '0'));
        $wanted = trim((string) $posted['hsts_max_age']);
        if ($wanted === $now || $wanted === '0') {
            $posted['hsts_max_age'] = $wanted;
        } elseif (!in_array($wanted, KIRJURI_HSTS_CHOICES, true)) {
            $posted['hsts_max_age'] = $now; // Not a choice the page offers: only a crafted form sends it.
        } elseif (!$https) {
            $posted['hsts_max_age'] = $now;
            $problem = $problem ?? 'https_needs_https';
        } else {
            $posted['hsts_max_age'] = $wanted;
        }
    }

    return array($posted, $problem);
}
