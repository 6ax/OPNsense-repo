#!/usr/local/bin/php
<?php

/*
 * Export the server certificate, its key and the authority chain from the
 * OPNsense trust store to the files the RADIUS daemon reads.
 *
 *   export_certs.php
 *
 * The source is the set synced from the central node (certsync.php) while certificate sync is on
 * and that set is valid; otherwise the server certificate and authority selected from the trust store.
 *
 * Writes, under /usr/local/etc/kazuhahub-wifi-proxy/certs:
 *   server.pem  the leaf followed by the authority chain, so clients get the chain
 *   server.key  the private key
 *   ca.pem      the selected authority
 * A file is replaced only when its content changed, and atomically, so a
 * running daemon never reads a half-written file. Key and certificates are
 * never printed; on failure one line says what is wrong.
 */

/* Overridable so the tests can use a scratch directory. */
if (!defined('KAZUHAHUB_WIFIPROXY_CERT_DIR')) {
    define('KAZUHAHUB_WIFIPROXY_CERT_DIR', '/usr/local/etc/kazuhahub-wifi-proxy/certs');
}
const KAZUHAHUB_WIFIPROXY_GROUP = 'freeradius';
const KAZUHAHUB_WIFIPROXY_MAX_CHAIN = 8;

/**
 * Normalise a PEM block: LF line endings, one trailing newline, nothing around it.
 *
 * @return string|null null when the text holds no certificate or key block
 */
function kazuhahub_wifiproxy_pem($text)
{
    $text = str_replace("\r", '', (string)$text);
    if (!preg_match('/-----BEGIN [A-Z0-9 ]+-----.+?-----END [A-Z0-9 ]+-----/s', $text, $match)) {
        return null;
    }
    return $match[0] . "\n";
}

/**
 * Build the three files from the certificate, its key and the ordered authority chain.
 *
 * @param string $certificate PEM of the server certificate
 * @param string $key PEM of its private key
 * @param string[] $chain PEM of the selected authority first, then its parents
 * @return array file name => content
 * @throws RuntimeException with a message that carries no key or certificate material
 */
function kazuhahub_wifiproxy_bundle($certificate, $key, array $chain)
{
    $certificate = kazuhahub_wifiproxy_pem($certificate);
    $key = kazuhahub_wifiproxy_pem($key);
    $authorities = [];
    foreach ($chain as $pem) {
        $pem = kazuhahub_wifiproxy_pem($pem);
        if ($pem !== null) {
            $authorities[] = $pem;
        }
    }
    if ($certificate === null || @openssl_x509_read($certificate) === false) {
        throw new RuntimeException('The server certificate cannot be read.');
    }
    if ($key === null || @openssl_pkey_get_private($key) === false) {
        throw new RuntimeException('The server certificate has no usable private key on this firewall.');
    }
    if (!@openssl_x509_check_private_key($certificate, $key)) {
        throw new RuntimeException('The private key does not belong to the server certificate.');
    }
    if (empty($authorities)) {
        throw new RuntimeException('The certificate authority cannot be read.');
    }
    $issued = false;
    foreach ($authorities as $pem) {
        $public = @openssl_pkey_get_public($pem);
        if ($public !== false && @openssl_x509_verify($certificate, $public) === 1) {
            $issued = true;
            break;
        }
    }
    if (!$issued) {
        throw new RuntimeException('The server certificate was not issued by the selected certificate authority.');
    }
    return [
        'server.pem' => $certificate . implode('', $authorities),
        'server.key' => $key,
        'ca.pem' => $authorities[0],
    ];
}

/**
 * Replace a file atomically when its content differs.
 *
 * @return bool whether the file was written
 */
function kazuhahub_wifiproxy_write($path, $content, $group = null)
{
    if (is_link($path)) {
        throw new RuntimeException('Refusing to write through a symbolic link.');
    }
    if (is_file($path) && @file_get_contents($path) === $content) {
        return false;
    }
    $temporary = dirname($path) . '/.' . basename($path) . '.' . bin2hex(random_bytes(6));
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new RuntimeException('A certificate file cannot be written.');
    }
    chmod($temporary, 0640);
    $ok = fwrite($handle, $content) === strlen($content) && fflush($handle);
    fclose($handle);
    if ($ok && $group !== null) {
        @chgrp($temporary, $group);
    }
    if (!$ok || !@rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('A certificate file cannot be written.');
    }
    return true;
}

/**
 * Export the files into a directory; returns the names that changed.
 */
function kazuhahub_wifiproxy_export($directory, $certificate, $key, array $chain, $group = null)
{
    return kazuhahub_wifiproxy_install($directory, kazuhahub_wifiproxy_bundle($certificate, $key, $chain), $group);
}

/**
 * Write a bundle built by kazuhahub_wifiproxy_bundle() into a directory; returns the names that changed.
 */
function kazuhahub_wifiproxy_install($directory, array $files, $group = null)
{
    if (is_link($directory)) {
        throw new RuntimeException('Refusing to use a symbolic link as the certificate directory.');
    }
    if (!is_dir($directory)) {
        @mkdir($directory, 0750, true);
        if ($group !== null) {
            @chgrp($directory, $group);
        }
    }
    $changed = [];
    foreach ($files as $name => $content) {
        if (kazuhahub_wifiproxy_write($directory . '/' . $name, $content, $group)) {
            $changed[] = $name;
        }
    }
    return $changed;
}

/* Core's includes define the configuration in the global scope, so they are required at the top level below. */
function kazuhahub_wifiproxy_main()
{
    $general = config_read_array('OPNsense', 'kazuhahubwifiproxy', 'general', false);
    $group = function_exists('posix_getgrnam') && posix_getgrnam(KAZUHAHUB_WIFIPROXY_GROUP) !== false
        ? KAZUHAHUB_WIFIPROXY_GROUP : null;
    /* The set synced from the central node wins while it is valid; the trust store is the fallback. */
    if (($general['cert_sync'] ?? '1') === '1') {
        require_once __DIR__ . '/certsync.php';
        $synced = kazuhahub_wifiproxy_sync_current(trim((string)($general['cert_sync_name'] ?? '')));
        if ($synced !== null) {
            return ['central set', kazuhahub_wifiproxy_install(KAZUHAHUB_WIFIPROXY_CERT_DIR, $synced, $group)];
        }
        if (is_dir(KAZUHAHUB_WIFIPROXY_SYNC_DIR . '/current')) {
            kazuhahub_wifiproxy_sync_log(LOG_WARNING, 'the accepted central set is not valid now; using the trust store.');
        }
    }
    $certref = (string)($general['servercert'] ?? '');
    /* The legacy config reader turns every element named ca into a list of one, whatever it is nested in. */
    $caref = is_array($general['ca'] ?? null) ? reset($general['ca']) : ($general['ca'] ?? '');
    $caref = is_string($caref) ? $caref : '';
    if ($certref === '' || $caref === '') {
        throw new RuntimeException('Select the server certificate and the certificate authority first.');
    }
    $cert = lookup_cert($certref);
    if (!is_array($cert) || empty($cert['crt']) || empty($cert['prv'])) {
        throw new RuntimeException('The selected server certificate or its private key is missing.');
    }
    $chain = [];
    $seen = [];
    $next = $caref;
    while ($next !== '' && count($chain) < KAZUHAHUB_WIFIPROXY_MAX_CHAIN && !isset($seen[$next])) {
        $seen[$next] = true;
        $ca = lookup_ca($next);
        if (!is_array($ca) || empty($ca['crt'])) {
            break;
        }
        $chain[] = base64_decode($ca['crt']);
        $next = (string)($ca['caref'] ?? '');
    }
    return ['trust store', kazuhahub_wifiproxy_export(
        KAZUHAHUB_WIFIPROXY_CERT_DIR,
        base64_decode($cert['crt']),
        base64_decode($cert['prv']),
        $chain,
        $group
    )];
}

if (PHP_SAPI === 'cli' && !defined('KAZUHAHUB_WIFIPROXY_NO_MAIN')) {
    require_once('config.inc');
    require_once('certs.inc');
    try {
        [$source, $changed] = kazuhahub_wifiproxy_main();
        echo $changed ? 'Exported from the ' . $source . ': ' . implode(', ', $changed) . "\n"
            : 'Certificates from the ' . $source . " are up to date.\n";
    } catch (Throwable $error) {
        echo $error->getMessage() . "\n";
        exit(1);
    }
}
