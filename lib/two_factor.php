<?php
/* Two-factor sign-in: time-based one-time codes (TOTP, RFC 6238) from an authenticator app, and one-time
** recovery codes for a lost phone.
**
** The code maths, the encryption of the secrets, the recovery codes and the QR code have no side effects and
** are unit tested on their own. Secrets are stored in the two_factor table, encrypted with the key in
** conf/two_factor_key.php, so that a copy of the database alone can not make codes. Recovery codes are stored
** as password hashes in two_factor_recovery_codes. Neither table is read into the session or the templates.
*/

define('TWO_FACTOR_PERIOD', 30); // Seconds a code lasts, and TWO_FACTOR_DIGITS its length: what every authenticator app uses.
define('TWO_FACTOR_DIGITS', 6);
define('TWO_FACTOR_DRIFT', 1); // Codes this many periods before or after now are accepted too, for clocks that are a little off.
define('TWO_FACTOR_MAX_WRONG', 30); // Wrong codes in a row before codes from the app are refused for the account.
define('TWO_FACTOR_PENDING_SECONDS', 300); // How long the code step waits after the password...
define('TWO_FACTOR_SETUP_SECONDS', 900); // ...and how long setting up may take, from the password or from opening the setup page.
define('TWO_FACTOR_RECENT_SIGN_IN', 600); // A sign-in this recent confirms changes for LDAP accounts, which have no password here.
define('TWO_FACTOR_RECOVERY_CODES', 10);
define('TWO_FACTOR_RECOVERY_ALPHABET', '0123456789abcdefghjkmnpqrstvwxyz'); // Crockford's base32: no i, l, o or u to misread.
define('TWO_FACTOR_KEY_FILE', 'conf/two_factor_key.php');


/** $bytes in base32 (RFC 4648) without padding, as authenticator apps take secrets. */
function kirjuri_base32_encode($bytes) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    for ($i = 0; $i < strlen($bytes); $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    $text = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $text .= $alphabet[bindec(str_pad(substr($bits, $i, 5), 5, '0'))];
    }
    return $text;
}


/** The bytes of base32 $text, ignoring case, spaces and padding; null when it is not base32. */
function kirjuri_base32_decode($text) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $text = strtoupper((string) preg_replace('/[\s=]+/', '', (string) $text));
    if (!preg_match('/^[A-Z2-7]*$/', $text)) {
        return null;
    }
    $bits = '';
    for ($i = 0; $i < strlen($text); $i++) {
        $bits .= str_pad(decbin(strpos($alphabet, $text[$i])), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}


/** The one-time code for $counter (RFC 4226): for TOTP, the counter is the number of periods since 1970. */
function kirjuri_hotp($secret, $counter, $digits = TWO_FACTOR_DIGITS) {
    $hash = hash_hmac('sha1', pack('J', $counter), $secret, true);
    $offset = ord($hash[19]) & 0x0f;
    $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}


/** The time step (RFC 6238) that $time falls in. */
function kirjuri_totp_step($time) {
    return intdiv((int) $time, TWO_FACTOR_PERIOD);
}


/**
 * The time step whose code $code is, or null. Codes of the steps around $time are accepted, but only those after
 * $last_step, the step of the code last accepted: each code works once (RFC 6238, section 5.2).
 */
function kirjuri_totp_match($secret, $code, $time, $last_step) {
    $code = (string) preg_replace('/[\s-]+/', '', (string) $code);
    if (!preg_match('/^[0-9]{' . TWO_FACTOR_DIGITS . '}$/', $code)) {
        return null;
    }
    $now = kirjuri_totp_step($time);
    $found = null;
    for ($step = $now - TWO_FACTOR_DRIFT; $step <= $now + TWO_FACTOR_DRIFT; $step++) {
        if (hash_equals(kirjuri_hotp($secret, $step), $code) && $step > (int) $last_step) {
            $found = $step;
        }
    }
    return $found;
}


/** A new secret for an authenticator app: 160 random bits, as RFC 4226 recommends. */
function kirjuri_two_factor_new_secret() {
    return random_bytes(20);
}


/** A secret in groups of four characters, for typing into an app that can not scan the QR code. */
function kirjuri_two_factor_readable_key($secret) {
    return trim(chunk_split(kirjuri_base32_encode($secret), 4, ' '));
}


/** The otpauth:// link an authenticator app reads from the QR code. $issuer names Kirjuri in the app's list. */
function kirjuri_two_factor_uri($secret, $username, $issuer) {
    $issuer = trim(str_replace(':', '', (string) $issuer)); // The label is "issuer:account".
    if ($issuer === '') {
        $issuer = 'Kirjuri';
    }
    return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode((string) $username) . '?' . http_build_query(array(
            'secret' => kirjuri_base32_encode($secret),
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => TWO_FACTOR_DIGITS,
            'period' => TWO_FACTOR_PERIOD,
        ), '', '&', PHP_QUERY_RFC3986);
}


/**
 * A QR code of $text as SVG markup to put inside a page, drawn here: nothing is fetched from elsewhere. Its colours
 * come from the theme's .qr-dark and .qr-light classes, and its size from the box it is in.
 */
function kirjuri_two_factor_qr_svg($text) {
    $writer = new \BaconQrCode\Writer(new \BaconQrCode\Renderer\ImageRenderer(
        new \BaconQrCode\Renderer\RendererStyle\RendererStyle(240, 4), // A margin of four modules, as the QR standard asks.
        new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
    ));
    $svg = (string) preg_replace('/^<\?xml[^>]*>\s*/', '', $writer->writeString($text));
    $svg = str_replace(array(' fill="#ffffff"', ' fill="#000000"'), array(' class="qr-light"', ' class="qr-dark"'), $svg);
    return (string) preg_replace('/^<svg([^>]*) width="\d+" height="\d+"/', '<svg$1 aria-hidden="true" focusable="false"', $svg);
}


/** A new recovery code: ten characters (50 random bits) in two groups, such as "k7m2q-x9p4r". */
function kirjuri_recovery_code_generate() {
    $code = '';
    for ($i = 0; $i < 10; $i++) {
        $code .= TWO_FACTOR_RECOVERY_ALPHABET[random_int(0, strlen(TWO_FACTOR_RECOVERY_ALPHABET) - 1)];
    }
    return substr($code, 0, 5) . '-' . substr($code, 5);
}


/**
 * A recovery code as typed, in the form it is hashed in: lower case without spaces or dashes, with the letters
 * that look like digits read as those digits. Null when it can not be a recovery code.
 */
function kirjuri_recovery_code_normalise($input) {
    $code = strtr(strtolower((string) preg_replace('/[\s-]+/', '', (string) $input)), array('o' => '0', 'i' => '1', 'l' => '1'));
    return preg_match('/^[0-9a-hjkmnp-tv-z]{10}$/', $code) ? $code : null;
}


/**
 * $plaintext encrypted and authenticated with AES-256-GCM under the 32 byte $key. $context is bound to it, so that
 * it only decrypts for the same purpose (the account it belongs to). Returns "v1:" and base64 text.
 */
function kirjuri_seal($plaintext, $key, $context) {
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt((string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string) $context, 16);
    if ($ciphertext === false) {
        throw new RuntimeException('Could not encrypt: OpenSSL has no AES-256-GCM.');
    }
    return 'v1:' . base64_encode($iv . $tag . $ciphertext);
}


/** The plaintext of kirjuri_seal() output, or null when it was changed, or sealed with another key or context. */
function kirjuri_unseal($sealed, $key, $context) {
    if (!is_string($sealed) || !is_string($key) || strlen($key) !== 32 || substr($sealed, 0, 3) !== 'v1:') {
        return null;
    }
    $raw = base64_decode(substr($sealed, 3), true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $plaintext = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), (string) $context);
    return $plaintext === false ? null : $plaintext;
}


/**
 * The key that encrypts the secrets, from conf/two_factor_key.php, or null when there is none (or it is damaged).
 * With $create, a missing key file is made first. The file is a PHP file, so that it shows nothing if a web
 * server without Kirjuri's access rules serves it; it is read as text, never run.
 */
function kirjuri_two_factor_key($create = false) {
    if ($create && !file_exists(TWO_FACTOR_KEY_FILE)) {
        // 'x' fails when another request has just made the file: only one key is ever written.
        $handle = @fopen(TWO_FACTOR_KEY_FILE, 'x');
        if ($handle !== false) {
            flock($handle, LOCK_EX);
            fwrite($handle, '<?php return "' . bin2hex(random_bytes(32)) . '";' . "\n");
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
            event_log_write('0', 'Auth', 'Created the two-factor sign-in key ' . TWO_FACTOR_KEY_FILE . '. Back it up with the database: without it, authenticator apps stop working.');
        }
    }
    $handle = @fopen(TWO_FACTOR_KEY_FILE, 'r');
    if ($handle === false) {
        return null;
    }
    flock($handle, LOCK_SH); // Wait for a key that is being written.
    $contents = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return preg_match('/"([0-9a-f]{64})"/', (string) $contents, $match) ? hex2bin($match[1]) : null;
}


/** What a secret is bound to when it is encrypted: the account, so it can not be moved to another one. */
function kirjuri_two_factor_context($user_id) {
    return 'kirjuri two-factor secret of user ' . (string) $user_id;
}


/**
 * The two_factor_required setting: "0" (no one), "admins" or "everyone". Anything else is "0", as Kirjuri
 * reads its Yes/No settings; bin/kirjuri doctor warns about it.
 */
function kirjuri_two_factor_requirement(array $settings) {
    $value = isset($settings['two_factor_required']) ? strtolower(trim((string) $settings['two_factor_required'])) : '0';
    return in_array($value, array('admins', 'everyone'), true) ? $value : '0';
}


/** Whether the two_factor_required setting asks $user to sign in with two-factor sign-in. */
function kirjuri_two_factor_required_for(array $settings, array $user) {
    $requirement = kirjuri_two_factor_requirement($settings);
    return $requirement === 'everyone' || ($requirement === 'admins' && (string) $user['access'] === '0');
}


/** The two_factor row of $user_id (secret, enabled_at, last_step, wrong_codes), or null when it is off. */
function kirjuri_two_factor_row(PDO $db, $user_id) {
    $query = $db->prepare('SELECT * FROM two_factor WHERE user_id = :id');
    $query->execute(array(':id' => $user_id));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}


function kirjuri_two_factor_enabled(PDO $db, $user_id) {
    return kirjuri_two_factor_row($db, $user_id) !== null;
}


/** Who has two-factor sign-in: user ID => array(enabled_at, wrong_codes, recovery_codes left). */
function kirjuri_two_factor_status(PDO $db) {
    $query = $db->prepare('SELECT t.user_id, t.enabled_at, t.wrong_codes,
        (SELECT COUNT(*) FROM two_factor_recovery_codes r WHERE r.user_id = t.user_id) AS recovery_codes
        FROM two_factor t');
    $query->execute();
    $status = array();
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $status[(string) $row['user_id']] = array(
            'enabled_at' => (int) $row['enabled_at'],
            'wrong_codes' => (int) $row['wrong_codes'],
            'recovery_codes' => (int) $row['recovery_codes'],
        );
    }
    return $status;
}


/**
 * Turn on two-factor sign-in for $user_id with $secret, once $code shows that the app makes the right codes.
 * A secret the account had before is replaced, and so are its recovery codes, unless $replace is false. Returns
 * the new recovery codes, which are shown once and stored only as hashes, or null when the code is wrong (or,
 * without $replace, when the account has two-factor sign-in already).
 */
function kirjuri_two_factor_enable(PDO $db, $user_id, $secret, $code, $time, $replace = true) {
    $step = kirjuri_totp_match($secret, $code, $time, 0);
    if ($step === null) {
        return null;
    }
    $key = kirjuri_two_factor_key(true);
    if ($key === null) {
        throw new RuntimeException('Can not write ' . TWO_FACTOR_KEY_FILE . '. Is conf/ writable by the web server?');
    }
    $sealed = kirjuri_seal($secret, $key, kirjuri_two_factor_context($user_id));
    $db->beginTransaction();
    try {
        // The confirming code counts as used, so it can not sign anyone in afterwards.
        if ($replace) {
            $query = $db->prepare('INSERT INTO two_factor (user_id, secret, enabled_at, last_step, wrong_codes)
                VALUES (:id, :secret, :enabled_at, :step, 0)
                ON DUPLICATE KEY UPDATE secret = :secret2, enabled_at = :enabled_at2, last_step = :step2, wrong_codes = 0');
            $query->execute(array(':id' => $user_id, ':secret' => $sealed, ':enabled_at' => (int) $time, ':step' => $step,
                ':secret2' => $sealed, ':enabled_at2' => (int) $time, ':step2' => $step));
        } else {
            $query = $db->prepare('INSERT IGNORE INTO two_factor (user_id, secret, enabled_at, last_step, wrong_codes) VALUES (:id, :secret, :enabled_at, :step, 0)');
            $query->execute(array(':id' => $user_id, ':secret' => $sealed, ':enabled_at' => (int) $time, ':step' => $step));
            if ($query->rowCount() !== 1) {
                $db->rollBack();
                return null;
            }
        }
        $codes = kirjuri_two_factor_replace_recovery_codes($db, $user_id);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $codes;
}


/** New recovery codes for $user_id in place of the old ones, which stop working. Returns the codes. */
function kirjuri_two_factor_replace_recovery_codes(PDO $db, $user_id) {
    $delete = $db->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = :id');
    $delete->execute(array(':id' => $user_id));
    $insert = $db->prepare('INSERT INTO two_factor_recovery_codes (user_id, code_hash, created_at) VALUES (:id, :hash, :created_at)');
    $codes = array();
    for ($i = 0; $i < TWO_FACTOR_RECOVERY_CODES; $i++) {
        $code = kirjuri_recovery_code_generate();
        $insert->execute(array(':id' => $user_id, ':hash' => password_hash((string) kirjuri_recovery_code_normalise($code), PASSWORD_DEFAULT), ':created_at' => time()));
        $codes[] = $code;
    }
    return $codes;
}


function kirjuri_two_factor_recovery_codes_left(PDO $db, $user_id) {
    $query = $db->prepare('SELECT COUNT(*) FROM two_factor_recovery_codes WHERE user_id = :id');
    $query->execute(array(':id' => $user_id));
    return (int) $query->fetchColumn();
}


/** Count a wrong code for $user_id and return how many have been entered in a row. */
function kirjuri_two_factor_count_wrong(PDO $db, $user_id) {
    $query = $db->prepare('UPDATE two_factor SET wrong_codes = wrong_codes + 1 WHERE user_id = :id');
    $query->execute(array(':id' => $user_id));
    $row = kirjuri_two_factor_row($db, $user_id);
    return $row === null ? 0 : (int) $row['wrong_codes'];
}


/**
 * Check a code from the app for $user_id at $time. The result is "ok"; "wrong" (counted); "blocked", when
 * TWO_FACTOR_MAX_WRONG wrong codes in a row were entered, after which codes from the app are refused until an
 * admin resets or unlocks the account (recovery codes still work); "unavailable", when the secret can not be
 * decrypted because conf/two_factor_key.php is missing or was replaced; or "off". "wrong_codes" is the number of
 * wrong codes in a row: before this one when it was right, so the user can be told about them.
 */
function kirjuri_two_factor_check_code(PDO $db, $user_id, $code, $time) {
    $row = kirjuri_two_factor_row($db, $user_id);
    if ($row === null) {
        return array('result' => 'off', 'wrong_codes' => 0);
    }
    $wrong = (int) $row['wrong_codes'];
    if ($wrong >= TWO_FACTOR_MAX_WRONG) {
        return array('result' => 'blocked', 'wrong_codes' => $wrong);
    }
    $key = kirjuri_two_factor_key();
    $secret = ($key === null) ? null : kirjuri_unseal($row['secret'], $key, kirjuri_two_factor_context($user_id));
    if ($secret === null) {
        return array('result' => 'unavailable', 'wrong_codes' => $wrong);
    }
    $step = kirjuri_totp_match($secret, $code, $time, (int) $row['last_step']);
    if ($step !== null) {
        // Only if no other request has used this code, or a later one, in the meantime.
        $query = $db->prepare('UPDATE two_factor SET last_step = :step, wrong_codes = 0 WHERE user_id = :id AND last_step < :step2');
        $query->execute(array(':step' => $step, ':id' => $user_id, ':step2' => $step));
        if ($query->rowCount() === 1) {
            return array('result' => 'ok', 'wrong_codes' => $wrong);
        }
    }
    return array('result' => 'wrong', 'wrong_codes' => kirjuri_two_factor_count_wrong($db, $user_id));
}


/**
 * Sign in with a recovery code: a matching code is deleted, so it works once, and ends a block. The result is
 * "ok" with "left", the codes that remain; "wrong" (counted like a wrong code); or "off". "wrong_codes" as in
 * kirjuri_two_factor_check_code().
 */
function kirjuri_two_factor_use_recovery_code(PDO $db, $user_id, $input) {
    $row = kirjuri_two_factor_row($db, $user_id);
    if ($row === null) {
        return array('result' => 'off', 'wrong_codes' => 0, 'left' => 0);
    }
    $code = kirjuri_recovery_code_normalise($input);
    if ($code !== null) {
        $query = $db->prepare('SELECT id, code_hash FROM two_factor_recovery_codes WHERE user_id = :id');
        $query->execute(array(':id' => $user_id));
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $stored) {
            if (password_verify($code, $stored['code_hash'])) {
                $delete = $db->prepare('DELETE FROM two_factor_recovery_codes WHERE id = :id AND user_id = :user_id');
                $delete->execute(array(':id' => $stored['id'], ':user_id' => $user_id));
                if ($delete->rowCount() !== 1) {
                    break; // Another request used it first.
                }
                $reset = $db->prepare('UPDATE two_factor SET wrong_codes = 0 WHERE user_id = :id');
                $reset->execute(array(':id' => $user_id));
                return array('result' => 'ok', 'wrong_codes' => (int) $row['wrong_codes'], 'left' => kirjuri_two_factor_recovery_codes_left($db, $user_id));
            }
        }
    }
    return array('result' => 'wrong', 'wrong_codes' => kirjuri_two_factor_count_wrong($db, $user_id), 'left' => kirjuri_two_factor_recovery_codes_left($db, $user_id));
}


/** Clear the count of wrong codes, which ends a block. Returns whether the account has two-factor sign-in. */
function kirjuri_two_factor_unlock(PDO $db, $user_id) {
    if (!kirjuri_two_factor_enabled($db, $user_id)) {
        return false;
    }
    $query = $db->prepare('UPDATE two_factor SET wrong_codes = 0 WHERE user_id = :id');
    $query->execute(array(':id' => $user_id));
    return true;
}


/** Turn off two-factor sign-in for $user_id: its secret and recovery codes go. Returns whether it was on. */
function kirjuri_two_factor_remove(PDO $db, $user_id) {
    $codes = $db->prepare('DELETE FROM two_factor_recovery_codes WHERE user_id = :id');
    $codes->execute(array(':id' => $user_id));
    $query = $db->prepare('DELETE FROM two_factor WHERE user_id = :id');
    $query->execute(array(':id' => $user_id));
    return $query->rowCount() > 0;
}


/**
 * Record a change to or a use of two-factor sign-in in the audit log and the event log. $event names it for the
 * audit log; $details must never hold a code, a recovery code, a secret or a password.
 */
function kirjuri_two_factor_log($description, $event, $username, array $details = array(), $level = 'Auth') {
    $stamp = audit_log_write(array('two_factor' => $event, 'account' => $username) + $details);
    event_log_write('0', $level, $description, $stamp);
}
