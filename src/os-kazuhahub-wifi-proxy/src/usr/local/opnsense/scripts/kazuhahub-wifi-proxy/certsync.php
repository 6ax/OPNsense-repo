#!/usr/local/bin/php
<?php

/*
 * Keep the RADIUS certificate set in step with the central node.
 *
 *   certsync.php run [force]   fetch the central set when it changed, check it, install it, restart the service;
 *                        force also retries a set that was rolled back within the last day
 *   certsync.php state   print what the sync knows as JSON
 *
 * The central node publishes SHA256SUMS, server.pem, server.key and ca.pem over plain HTTP on the
 * private network, behind one HTTP Basic account per site; without an account nothing is pulled. A run fetches SHA256SUMS first and the
 * three files only when it differs from the accepted set. A set is accepted only if every file
 * matches its sum, the certificate chains to the fetched authority, is valid now, carries the
 * expected name in its subjectAltName and matches the key. It is then kept under /var/db/kazuhahub-wifi-proxy/certsync,
 * the exporter writes it where the daemon reads its certificates, the configuration is checked and
 * the service restarted, if it was running. Any failure leaves the service on the set it has: a new
 * set that fails the check or the restart is rolled back, the previous files are put back and the
 * service is brought back up, and that set is not tried again for a day unless the node publishes
 * another one. Only outbound requests are made: nothing listens. Key and certificate material is
 * never printed or logged.
 */

if (!defined('KAZUHAHUB_WIFIPROXY_NO_MAIN')) {
    define('KAZUHAHUB_WIFIPROXY_NO_MAIN', true);
}
require_once __DIR__ . '/export_certs.php';

/* Overridable so the tests can use a scratch directory. */
if (!defined('KAZUHAHUB_WIFIPROXY_SYNC_DIR')) {
    define('KAZUHAHUB_WIFIPROXY_SYNC_DIR', '/var/db/kazuhahub-wifi-proxy/certsync');
}
const KAZUHAHUB_WIFIPROXY_SYNC_FILES = ['server.pem', 'server.key', 'ca.pem'];
const KAZUHAHUB_WIFIPROXY_SYNC_MAX_BYTES = 65536;
const KAZUHAHUB_WIFIPROXY_SYNC_TIMEOUT = 20;
const KAZUHAHUB_WIFIPROXY_SYNC_RETRY = 86400;
const KAZUHAHUB_WIFIPROXY_SYNC_RC = '/usr/local/etc/rc.d/kazuhahub_wifiproxy';
const KAZUHAHUB_WIFIPROXY_SYNC_CHECK = '/usr/local/opnsense/scripts/kazuhahub-wifi-proxy/check.sh';

/**
 * Read SHA256SUMS in the format sha256sum writes: one line per file, exactly the three files.
 *
 * @return array file name => lowercase hex digest
 * @throws RuntimeException
 */
function kazuhahub_wifiproxy_sync_sums($text)
{
    $sums = [];
    foreach (preg_split('/\r?\n/', trim((string)$text)) as $line) {
        if (!preg_match('/^([0-9a-fA-F]{64}) [ *](?:\.\/)?([A-Za-z0-9._-]+)$/D', $line, $match)) {
            throw new RuntimeException('SHA256SUMS is not a list of SHA-256 sums.');
        }
        $name = $match[2];
        if (!in_array($name, KAZUHAHUB_WIFIPROXY_SYNC_FILES, true) || isset($sums[$name])) {
            throw new RuntimeException('SHA256SUMS lists an unexpected or repeated file.');
        }
        $sums[$name] = strtolower($match[1]);
    }
    if (count($sums) !== count(KAZUHAHUB_WIFIPROXY_SYNC_FILES)) {
        throw new RuntimeException('SHA256SUMS does not list server.pem, server.key and ca.pem.');
    }
    return $sums;
}

/**
 * The PEM blocks of one kind in a text, normalised the way kazuhahub_wifiproxy_pem() does.
 *
 * @param string $label a regular expression for the label, such as CERTIFICATE
 * @return string[]
 */
function kazuhahub_wifiproxy_sync_blocks($text, $label)
{
    preg_match_all('/-----BEGIN (' . $label . ')-----.+?-----END \1-----/s', str_replace("\r", '', (string)$text), $matches);
    return array_map(fn($block) => $block . "\n", $matches[0]);
}

/** Whether the certificate names the host in a DNS entry of its subjectAltName. */
function kazuhahub_wifiproxy_sync_names($certificate, $name)
{
    $parsed = @openssl_x509_parse($certificate);
    $san = is_array($parsed) ? (string)($parsed['extensions']['subjectAltName'] ?? '') : '';
    foreach (array_map('trim', explode(',', $san)) as $entry) {
        if (strcasecmp($entry, 'DNS:' . $name) === 0) {
            return true;
        }
    }
    return false;
}

/**
 * Check a set and turn it into the files the daemon reads.
 *
 * server.pem may carry intermediates after the leaf and ca.pem several authorities; the key may
 * come with an EC parameters block. Chain and dates are checked the way openssl verify -CAfile
 * ca.pem does, against ca.pem alone, never the system trust store, and for TLS server use.
 *
 * @param array $files name => content for SHA256SUMS and the three files
 * @param int|null $now epoch seconds, for tests
 * @return array server.pem (leaf, intermediates, authorities), server.key, ca.pem (the first authority)
 * @throws RuntimeException with a message that carries no key or certificate material
 */
function kazuhahub_wifiproxy_sync_verify(array $files, $now = null, $name = '')
{
    if (!is_string($name) || $name === '') {
        throw new RuntimeException('No expected certificate name is set.');
    }
    $now = $now === null ? time() : (int)$now;
    $sums = kazuhahub_wifiproxy_sync_sums($files['SHA256SUMS'] ?? '');
    foreach (KAZUHAHUB_WIFIPROXY_SYNC_FILES as $file) {
        if (!isset($files[$file]) || !is_string($files[$file]) || $files[$file] === '') {
            throw new RuntimeException("The set has no $file.");
        }
        if (!hash_equals($sums[$file], hash('sha256', $files[$file]))) {
            throw new RuntimeException("$file does not match SHA256SUMS.");
        }
    }
    $certificates = kazuhahub_wifiproxy_sync_blocks($files['server.pem'], 'CERTIFICATE');
    $authorities = kazuhahub_wifiproxy_sync_blocks($files['ca.pem'], 'CERTIFICATE');
    $keys = kazuhahub_wifiproxy_sync_blocks($files['server.key'], '(?:RSA |EC )?PRIVATE KEY');
    if ($certificates === [] || @openssl_x509_read($certificates[0]) === false) {
        throw new RuntimeException('server.pem holds no readable certificate.');
    }
    if ($authorities === [] || @openssl_x509_read($authorities[0]) === false) {
        throw new RuntimeException('ca.pem holds no readable certificate.');
    }
    if (count($keys) !== 1) {
        throw new RuntimeException('server.key does not hold exactly one private key.');
    }
    $leaf = $certificates[0];
    $intermediates = array_slice($certificates, 1);
    $parsed = openssl_x509_parse($leaf);
    if ((int)($parsed['validTo_time_t'] ?? 0) <= $now) {
        throw new RuntimeException('The server certificate has expired.');
    }
    if ((int)($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now) {
        throw new RuntimeException('The server certificate is not valid yet.');
    }
    if (!kazuhahub_wifiproxy_sync_names($leaf, $name)) {
        throw new RuntimeException('The server certificate does not name ' . $name . '.');
    }
    foreach (array_merge($intermediates, $authorities) as $pem) {
        $dates = @openssl_x509_parse($pem);
        if (!is_array($dates) || (int)($dates['validTo_time_t'] ?? 0) <= $now || (int)($dates['validFrom_time_t'] ?? PHP_INT_MAX) > $now) {
            throw new RuntimeException('A certificate of the authority chain is not valid now.');
        }
    }
    /* openssl verify -CAfile ca.pem -untrusted <intermediates> -purpose sslserver. PHP adds the
       default CA file or directory when no file or no directory is given, so both are given. */
    $directory = sys_get_temp_dir() . '/kazuhahub-wifi-proxy-ca-' . bin2hex(random_bytes(6));
    if (!@mkdir($directory, 0700) || !@mkdir("$directory/none", 0700)) {
        throw new RuntimeException('A temporary directory cannot be created.');
    }
    try {
        file_put_contents("$directory/ca.pem", implode('', $authorities));
        $untrusted = null;
        if ($intermediates !== []) {
            file_put_contents("$directory/untrusted.pem", implode('', $intermediates));
            $untrusted = "$directory/untrusted.pem";
        }
        $chained = @openssl_x509_checkpurpose($leaf, X509_PURPOSE_SSL_SERVER, ["$directory/ca.pem", "$directory/none"], $untrusted);
    } finally {
        @unlink("$directory/ca.pem");
        @unlink("$directory/untrusted.pem");
        @rmdir("$directory/none");
        @rmdir($directory);
    }
    if ($chained !== true) {
        throw new RuntimeException('The server certificate does not chain to ca.pem for TLS server use.');
    }
    /* The bundle also checks that the key belongs to the certificate and that the chain issued it. */
    $bundle = kazuhahub_wifiproxy_bundle($leaf, $keys[0], array_merge($intermediates, $authorities));
    $bundle['ca.pem'] = $authorities[0];
    return $bundle;
}

/** Read a set from a directory: SHA256SUMS and the three files, or null when one is missing. */
function kazuhahub_wifiproxy_sync_read($directory)
{
    if (is_link($directory) || !is_dir($directory)) {
        return null;
    }
    $files = [];
    foreach (array_merge(['SHA256SUMS'], KAZUHAHUB_WIFIPROXY_SYNC_FILES) as $name) {
        $path = "$directory/$name";
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        $files[$name] = (string)@file_get_contents($path);
    }
    return $files;
}

/**
 * Hold the swap lock while $work runs: shared for readers, exclusive while the sync swaps sets,
 * so nobody reads a directory that is being replaced.
 */
function kazuhahub_wifiproxy_sync_locked($base, $exclusive, callable $work)
{
    $handle = is_dir($base) ? @fopen("$base/swap.lock", 'c') : false;
    if ($handle !== false) {
        @chmod("$base/swap.lock", 0600);
        flock($handle, $exclusive ? LOCK_EX : LOCK_SH);
    }
    try {
        return $work();
    } finally {
        if ($handle !== false) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

/**
 * The accepted set as the exporter installs it, or null when there is none that is valid now.
 *
 * @return array|null bundle
 */
function kazuhahub_wifiproxy_sync_current($name, $base = KAZUHAHUB_WIFIPROXY_SYNC_DIR, $now = null)
{
    $files = kazuhahub_wifiproxy_sync_locked($base, false, fn() => kazuhahub_wifiproxy_sync_read("$base/current"));
    if ($files === null) {
        return null;
    }
    try {
        return kazuhahub_wifiproxy_sync_verify($files, $now, $name);
    } catch (RuntimeException $error) {
        return null;
    }
}

/** The sync settings from config.xml: enabled, host, port. The service must be enabled too. */
function kazuhahub_wifiproxy_sync_settings()
{
    $general = config_read_array('OPNsense', 'kazuhahubwifiproxy', 'general', false);
    return [
        'service' => ($general['enabled'] ?? '0') === '1',
        'enabled' => ($general['cert_sync'] ?? '1') === '1',
        'host' => trim((string)($general['cert_sync_host'] ?? '')),
        'port' => (int)($general['cert_sync_port'] ?? 0),
        'name' => trim((string)($general['cert_sync_name'] ?? '')),
        'user' => (string)($general['cert_sync_user'] ?? ''),
        'password' => (string)($general['cert_sync_password'] ?? ''),
    ];
}

/** http://host:port/ for an IPv4 address, an IPv6 address or a host name. */
function kazuhahub_wifiproxy_sync_base($host, $port)
{
    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
        $host = '[' . $host . ']';
    } elseif (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        && !preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host)) {
        throw new RuntimeException('The certificate source host is not valid.');
    }
    if ($port < 1 || $port > 65535) {
        throw new RuntimeException('The certificate source port is not valid.');
    }
    return 'http://' . $host . ':' . $port . '/';
}

/**
 * GET one file: plain HTTP with Basic authentication, no proxy, no redirects, a size limit and a
 * time limit. A 401 is a failure like any other answer but 200.
 *
 * @return string body
 * @throws RuntimeException
 */
function kazuhahub_wifiproxy_sync_get($url, $user, $password)
{
    if ($user === '' || $password === '' || strpos($user, ':') !== false) {
        throw new RuntimeException('The certificate source account is not set.');
    }
    $body = '';
    $oversized = false;
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('The HTTP client cannot be set up.');
    }
    /* The body goes to the buffer before anything else is set, so it can never reach the output. */
    $sink = function ($handle, $chunk) use (&$body, &$oversized) {
        if (strlen($body) + strlen($chunk) > KAZUHAHUB_WIFIPROXY_SYNC_MAX_BYTES) {
            $oversized = true;
            return 0;
        }
        $body .= $chunk;
        return strlen($chunk);
    };
    if (!curl_setopt($handle, CURLOPT_WRITEFUNCTION, $sink) || !curl_setopt_array($handle, [
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => KAZUHAHUB_WIFIPROXY_SYNC_TIMEOUT,
        CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $user . ':' . $password,
    ])) {
        throw new RuntimeException('The HTTP client cannot be set up.');
    }
    $ok = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($handle);
    if ($oversized) {
        throw new RuntimeException('The central node sent a file larger than 64 KiB.');
    }
    if ($ok === false || $error !== 0) {
        throw new RuntimeException(sprintf('The central node did not answer (curl error %d).', $error));
    }
    if ($status === 401) {
        throw new RuntimeException('The central node refused the account (HTTP 401).');
    }
    if ($status !== 200) {
        throw new RuntimeException(sprintf('The central node answered HTTP %d.', $status));
    }
    return $body;
}

/** Write a set into a new directory, files 0600, the directory 0700. */
function kazuhahub_wifiproxy_sync_store($directory, array $files)
{
    if (!@mkdir($directory, 0700)) {
        throw new RuntimeException('The certificate cache cannot be written.');
    }
    foreach ($files as $name => $content) {
        $path = "$directory/$name";
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The certificate cache cannot be written.');
        }
        chmod($path, 0600);
        $ok = fwrite($handle, $content) === strlen($content) && fflush($handle);
        fclose($handle);
        if (!$ok) {
            throw new RuntimeException('The certificate cache cannot be written.');
        }
    }
}

function kazuhahub_wifiproxy_sync_remove($directory)
{
    if (is_link($directory)) {
        @unlink($directory);
        return;
    }
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) as $name) {
        if ($name !== '.' && $name !== '..') {
            @unlink("$directory/$name");
        }
    }
    @rmdir($directory);
}

function kazuhahub_wifiproxy_sync_log($priority, $message)
{
    openlog('kazuhahub-wifi-proxy', LOG_ODELAY, LOG_DAEMON);
    syslog($priority, 'cert-sync: ' . $message);
    closelog();
}

/** Expiry dates of a bundle, for the state. */
function kazuhahub_wifiproxy_sync_dates(array $bundle)
{
    $leaf = openssl_x509_parse($bundle['server.pem']);
    $authority = openssl_x509_parse($bundle['ca.pem']);
    return [
        'not_after' => (int)($leaf['validTo_time_t'] ?? 0),
        'ca_not_after' => (int)($authority['validTo_time_t'] ?? 0),
        'ca_fingerprint' => strtoupper((string)@openssl_x509_fingerprint($bundle['ca.pem'], 'sha256')),
    ];
}

function kazuhahub_wifiproxy_sync_record($base, array $state)
{
    $path = "$base/state.json";
    $temporary = "$path." . bin2hex(random_bytes(6));
    if (@file_put_contents($temporary, json_encode($state) . "\n") !== false) {
        @chmod($temporary, 0600);
        @rename($temporary, $path);
    }
    @unlink($temporary);
}

/** Whether the files the daemon reads are the ones the bundle holds. */
function kazuhahub_wifiproxy_sync_installed(array $bundle, $live)
{
    foreach (KAZUHAHUB_WIFIPROXY_SYNC_FILES as $name) {
        if (is_link("$live/$name") || @file_get_contents("$live/$name") !== $bundle[$name]) {
            return false;
        }
    }
    return true;
}

/** Write a small marker file in the cache, or remove it for null. */
function kazuhahub_wifiproxy_sync_mark($path, $content)
{
    if ($content === null) {
        @unlink($path);
        return;
    }
    @file_put_contents($path, $content);
    @chmod($path, 0600);
}

/**
 * One sync run.
 *
 * Markers in the cache: "applied" holds the SHA256SUMS of the set whose check and restart last
 * finished, so a run that was cut off after the swap, or a daemon that is not on the accepted set,
 * is noticed and the set installed again; "rejected" holds the SHA256SUMS of a set that was rolled
 * back while the previous one could be restored, so it is not tried again for a day unless forced.
 *
 * @param array $settings as kazuhahub_wifiproxy_sync_settings() returns them, plus 'force' to retry a rejected set
 * @param callable $fetch name => body, throws RuntimeException
 * @param callable $apply fn(?bool $running): [?string failure, bool running before, ?string stage (check or restart)];
 *        null restarts the service only if it runs, true brings it up, false only checks and exports
 * @param string $base the cache directory
 * @param string $live the directory the daemon reads its certificates from
 * @param int|null $now epoch seconds, for tests
 * @return array state: result (updated, reinstalled, unchanged, failed, unconfigured, skipped, busy, disabled), message, checked
 */
function kazuhahub_wifiproxy_sync_run(array $settings, callable $fetch, callable $apply, $base = KAZUHAHUB_WIFIPROXY_SYNC_DIR,
    $live = KAZUHAHUB_WIFIPROXY_CERT_DIR, $now = null)
{
    $checked = $now === null ? time() : (int)$now;
    $state = fn($result, $message) => ['result' => $result, 'message' => $message, 'checked' => $checked];
    if (empty($settings['service']) || empty($settings['enabled'])) {
        return $state('disabled', 'Certificate sync is off.');
    }
    if (($settings['host'] ?? '') === '' || (int)($settings['port'] ?? 0) === 0 || ($settings['name'] ?? '') === '') {
        return $state('unconfigured', 'No certificate source or expected certificate name is set, so nothing was pulled. Keeping the current set.');
    }
    if (($settings['user'] ?? '') === '' || ($settings['password'] ?? '') === '') {
        return $state('skipped', 'No certificate source account is set, so nothing was pulled. Keeping the current set.');
    }
    if (!is_dir($base) && !@mkdir($base, 0700, true)) {
        return $state('failed', 'The certificate cache cannot be created.');
    }
    @chmod($base, 0700);
    $lock = @fopen("$base/lock", 'c');
    if ($lock !== false) {
        @chmod("$base/lock", 0600);
    }
    if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
        return $state('busy', 'Another certificate sync is running.');
    }
    $keep = ' Keeping the current set.';
    $applied = "$base/applied";
    $rejected = "$base/rejected";
    /* Drop current for previous (or for nothing, so the trust store is used), export what is left
       and bring the service up if asked. When that works the dropped set is to blame and is not
       tried again for a day; the applied marker then names what the daemon runs. */
    $rollback = function ($bringUp, $badSums, $appliedBefore = null) use ($base, $apply, $applied, $rejected) {
        $restoredSums = kazuhahub_wifiproxy_sync_locked($base, true, function () use ($base) {
            kazuhahub_wifiproxy_sync_remove("$base/current");
            if (is_dir("$base/previous")) {
                @rename("$base/previous", "$base/current");
            }
            $files = kazuhahub_wifiproxy_sync_read("$base/current");
            return $files['SHA256SUMS'] ?? null;
        });
        [$restored] = $apply($bringUp);
        if ($restored === null) {
            kazuhahub_wifiproxy_sync_mark($rejected, $badSums);
            kazuhahub_wifiproxy_sync_mark($applied, $bringUp ? $restoredSums : $appliedBefore);
        }
        return $restored;
    };
    try {
        try {
            $files = ['SHA256SUMS' => $fetch('SHA256SUMS')];
            kazuhahub_wifiproxy_sync_sums($files['SHA256SUMS']);
        } catch (RuntimeException $error) {
            return $state('failed', $error->getMessage() . $keep);
        }
        $current = kazuhahub_wifiproxy_sync_read("$base/current");
        $bundle = kazuhahub_wifiproxy_sync_current($settings['name'], $base, $checked);
        if ($current !== null && $bundle !== null && $current['SHA256SUMS'] === $files['SHA256SUMS']) {
            if (kazuhahub_wifiproxy_sync_installed($bundle, $live) && @file_get_contents($applied) === $current['SHA256SUMS']) {
                return $state('unchanged', 'The central set is unchanged.');
            }
            /* The accepted set did not finish its check and restart, or the daemon is not on it, for
               example after a start that fell back to the trust store: install it again. Until that
               finishes the set does not count as applied, since the check exports before it checks. */
            kazuhahub_wifiproxy_sync_mark($applied, null);
            [$failure, $wasRunning, $stage] = $apply(null);
            if ($failure === null) {
                kazuhahub_wifiproxy_sync_mark($applied, $current['SHA256SUMS']);
                return $state('reinstalled', 'Installed the accepted central set again.') + kazuhahub_wifiproxy_sync_dates($bundle);
            }
            /* radiusd refuses the accepted set itself, for example one whose run was cut off after the
               swap: drop it for the previous set, or the trust store, like a new set that fails. A running
               daemon that failed only the check is left alone; a stopped one, most likely a start that
               failed on this set, and one the restart took down are brought back up. */
            $restored = $rollback($stage === 'restart' || !$wasRunning, $current['SHA256SUMS']);
            return $state('failed', 'The accepted central set was dropped: ' . $failure
                . ($restored === null ? '' : ' Restoring the previous set also failed: ' . $restored));
        }
        if (empty($settings['force']) && is_file($rejected) && @file_get_contents($rejected) === $files['SHA256SUMS']
            && (int)@filemtime($rejected) > $checked - KAZUHAHUB_WIFIPROXY_SYNC_RETRY) {
            return $state('failed', 'This central set was rolled back less than a day ago; it is tried again after a day, or with Sync now.' . $keep);
        }
        try {
            foreach (KAZUHAHUB_WIFIPROXY_SYNC_FILES as $name) {
                $files[$name] = $fetch($name);
            }
            $bundle = kazuhahub_wifiproxy_sync_verify($files, $checked, $settings['name']);
        } catch (RuntimeException $error) {
            return $state('failed', $error->getMessage() . $keep);
        }

        /* current -> previous, the new set -> current; a failed apply puts them back. */
        $staging = "$base/incoming";
        kazuhahub_wifiproxy_sync_remove($staging);
        try {
            kazuhahub_wifiproxy_sync_store($staging, $files);
        } catch (RuntimeException $error) {
            kazuhahub_wifiproxy_sync_remove($staging);
            return $state('failed', $error->getMessage() . $keep);
        }
        $hadCurrent = is_dir("$base/current");
        $swapped = kazuhahub_wifiproxy_sync_locked($base, true, function () use ($base, $staging, $hadCurrent) {
            kazuhahub_wifiproxy_sync_remove("$base/previous");
            if (($hadCurrent && !@rename("$base/current", "$base/previous")) || !@rename($staging, "$base/current")) {
                if ($hadCurrent && !is_dir("$base/current")) {
                    @rename("$base/previous", "$base/current");
                }
                return false;
            }
            return true;
        });
        if (!$swapped) {
            kazuhahub_wifiproxy_sync_remove($staging);
            return $state('failed', 'The certificate cache cannot be updated.' . $keep);
        }
        $appliedBefore = is_file($applied) ? (string)@file_get_contents($applied) : null;
        kazuhahub_wifiproxy_sync_mark($applied, null);
        [$failure, $wasRunning, $stage] = $apply(null);
        if ($failure === null) {
            kazuhahub_wifiproxy_sync_mark($applied, $files['SHA256SUMS']);
            kazuhahub_wifiproxy_sync_mark($rejected, null);
            return $state('updated', 'Installed the new central set.') + kazuhahub_wifiproxy_sync_dates($bundle);
        }
        if (!$hadCurrent) {
            kazuhahub_wifiproxy_sync_remove("$base/previous");
        }
        /* Put the files of the previous set back where the daemon reads them. A failed check left the
           daemon untouched, so the files are only exported again and the marker stays what it was; a
           failed restart left it down, so it is brought back up if it was running. */
        $restored = $rollback($stage === 'restart' && $wasRunning, $files['SHA256SUMS'], $appliedBefore);
        return $state('failed', 'The new set was rolled back: ' . $failure
            . ($restored === null ? '' : ' Restoring the previous set also failed: ' . $restored));
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Run a command without a shell; returns its exit status. */
function kazuhahub_wifiproxy_sync_exec(array $command)
{
    exec(implode(' ', array_map('escapeshellarg', $command)) . ' >/dev/null 2>&1', $output, $status);
    return $status;
}

/**
 * Install the current set and restart the service. The check exports the certificates the way a
 * start does and runs radiusd's configuration check, so a set the daemon cannot load never reaches
 * a restart.
 *
 * @param bool|null $running null: restart only when the service runs now; true: bring it up, by a
 *        restart when it runs and a start when it does not; false: only check and export
 * @return array [?string failure, bool whether it was running before, ?string the stage that failed]
 */
function kazuhahub_wifiproxy_sync_apply(?bool $running = null, ?callable $exec = null)
{
    $exec = $exec ?? 'kazuhahub_wifiproxy_sync_exec';
    $runningNow = $exec([KAZUHAHUB_WIFIPROXY_SYNC_RC, 'onestatus']) === 0;
    $running = $running ?? $runningNow;
    if ($exec(['/bin/sh', KAZUHAHUB_WIFIPROXY_SYNC_CHECK, 'start']) !== 0) {
        return ['the configuration check failed.', $runningNow, 'check'];
    }
    if ($running && $exec([KAZUHAHUB_WIFIPROXY_SYNC_RC, $runningNow ? 'restart' : 'start']) !== 0) {
        return ['the service did not restart.', $runningNow, 'restart'];
    }
    return [null, $runningNow, null];
}

/** What the page shows: the last run, and whether the daemon's files are the accepted central set. */
function kazuhahub_wifiproxy_sync_state(array $settings, $base = KAZUHAHUB_WIFIPROXY_SYNC_DIR, $now = null, $live = KAZUHAHUB_WIFIPROXY_CERT_DIR)
{
    $last = json_decode((string)@file_get_contents("$base/state.json"), true);
    $last = is_array($last) ? $last : [];
    $state = [
        'enabled' => !empty($settings['enabled']),
        'source' => ($settings['host'] ?? '') === '' ? '' : sprintf('%s:%d', $settings['host'], (int)($settings['port'] ?? 0)),
        'account' => ($settings['user'] ?? '') !== '' && ($settings['password'] ?? '') !== '',
        'result' => is_string($last['result'] ?? null) ? $last['result'] : 'unknown',
        'message' => is_string($last['message'] ?? null) ? $last['message'] : '',
        'checked' => (int)($last['checked'] ?? 0),
        'accepted' => (int)($last['accepted'] ?? 0),
        'in_use' => 'trust store',
    ];
    $bundle = !empty($settings['enabled']) ? kazuhahub_wifiproxy_sync_current((string)($settings['name'] ?? ''), $base, $now) : null;
    if ($bundle !== null && kazuhahub_wifiproxy_sync_installed($bundle, $live)) {
        $state['in_use'] = 'central set';
        $state += kazuhahub_wifiproxy_sync_dates($bundle);
    }
    return $state;
}

if (PHP_SAPI === 'cli' && !defined('KAZUHAHUB_WIFIPROXY_SYNC_NO_MAIN') && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    require_once('config.inc');
    $settings = kazuhahub_wifiproxy_sync_settings();
    $mode = $argv[1] ?? 'run';
    if ($mode === 'state') {
        echo json_encode(kazuhahub_wifiproxy_sync_state($settings)) . "\n";
        exit(0);
    }
    if ($mode !== 'run') {
        fwrite(STDERR, "usage: certsync.php run [force]|state\n");
        exit(2);
    }
    /* Sync now retries a set that was rolled back within the last day. */
    $settings['force'] = ($argv[2] ?? '') === 'force';
    try {
        $base = kazuhahub_wifiproxy_sync_base($settings['host'], $settings['port']);
        $fetch = fn($name) => kazuhahub_wifiproxy_sync_get($base . $name, $settings['user'], $settings['password']);
    } catch (RuntimeException $error) {
        $message = $error->getMessage();
        $fetch = function ($name) use ($message) {
            throw new RuntimeException($message);
        };
    }
    $state = kazuhahub_wifiproxy_sync_run($settings, $fetch, 'kazuhahub_wifiproxy_sync_apply');
    if (in_array($state['result'], ['skipped', 'unconfigured'], true) && !is_dir(KAZUHAHUB_WIFIPROXY_SYNC_DIR)) {
        @mkdir(KAZUHAHUB_WIFIPROXY_SYNC_DIR, 0700, true);
    }
    if (!in_array($state['result'], ['disabled', 'busy'], true) && is_dir(KAZUHAHUB_WIFIPROXY_SYNC_DIR)) {
        $previous = json_decode((string)@file_get_contents(KAZUHAHUB_WIFIPROXY_SYNC_DIR . '/state.json'), true);
        $state['accepted'] = $state['result'] === 'updated' ? $state['checked'] : (int)($previous['accepted'] ?? 0);
        kazuhahub_wifiproxy_sync_record(KAZUHAHUB_WIFIPROXY_SYNC_DIR, $state);
    }
    if ($state['result'] === 'updated' || $state['result'] === 'reinstalled') {
        kazuhahub_wifiproxy_sync_log(LOG_NOTICE, sprintf('%s the central set from %s:%d, valid to %s, CA %s',
            $state['result'] === 'updated' ? 'installed' : 'reinstalled', $settings['host'], $settings['port'],
            gmdate('Y-m-d', $state['not_after']), $state['ca_fingerprint']));
    } elseif (in_array($state['result'], ['failed', 'skipped', 'unconfigured'], true)) {
        kazuhahub_wifiproxy_sync_log(LOG_WARNING, $state['message']);
    }
    echo json_encode(kazuhahub_wifiproxy_sync_state($settings) + ['run' => $state['result'], 'run_message' => $state['message']]) . "\n";
    exit(0);
}
