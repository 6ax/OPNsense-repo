<?php
/* The certificate sync: what it accepts, what it refuses, how it installs, rolls back and reports, with a throwaway PKI. */
define('KAZUHAHUB_RADIUS_NO_MAIN', true);
define('KAZUHAHUB_RADIUS_SYNC_NO_MAIN', true);
$work = sys_get_temp_dir() . '/kazuhahub-radius-certsync-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
define('KAZUHAHUB_RADIUS_SYNC_DIR', "$work/main-cache");
define('KAZUHAHUB_RADIUS_CERT_DIR', "$work/main-live");
require dirname(__DIR__, 2) . '/src/usr/local/opnsense/scripts/kazuhahub-radius/certsync.php';

/* The core lookups the exporter's main uses. */
$general = [];
$store = [];
function config_read_array($a, $b, $c, $default)
{
    return $GLOBALS['general'];
}
function lookup_cert($ref)
{
    return $GLOBALS['store'][$ref] ?? null;
}
function lookup_ca($ref)
{
    return $GLOBALS['store'][$ref] ?? null;
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sh($command)
{
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0) {
        throw new RuntimeException("Fixture command failed: $command\n" . implode("\n", $output));
    }
}

function refused(callable $call, $what)
{
    try {
        $call();
    } catch (RuntimeException $error) {
        $text = $error->getMessage();
        check($text !== '' && strpos($text, 'BEGIN') === false && strpos($text, 'MII') === false, "$what leaked material: $text");
        return $text;
    }
    throw new RuntimeException("$what was accepted.");
}

const NAME = 'radius.example.test';

function verify_set(array $files, $now = null)
{
    return kazuhahub_radius_sync_verify($files, $now, NAME);
}

function current_set($base, $now = null)
{
    return kazuhahub_radius_sync_current(NAME, $base, $now);
}

function sums(array $files)
{
    $text = '';
    foreach (['server.pem', 'server.key', 'ca.pem'] as $name) {
        $text .= hash('sha256', $files[$name]) . '  ' . $name . "\n";
    }
    return $text;
}

function set(array $files)
{
    return ['SHA256SUMS' => sums($files)] + $files;
}

$server = null;
try {
    $q = escapeshellarg($work);
    $ca = "-addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign";
    sh("openssl req -x509 -newkey rsa:2048 -nodes -keyout $q/ca.key -out $q/ca.pem -days 3 -subj '/O=Test/CN=Test RADIUS CA' $ca");
    sh("openssl req -x509 -newkey rsa:2048 -nodes -keyout $q/other.key -out $q/other.pem -days 3 -subj '/CN=Other CA' $ca");
    /* The same authority re-signed: same key and subject, new dates, as the central node did. */
    sh("openssl req -x509 -new -key $q/ca.key -out $q/ca2.pem -days 30 -subj '/O=Test/CN=Test RADIUS CA' $ca");
    $leaf = function ($name, $san, $issuer) use ($work, $q) {
        file_put_contents("$work/$name.ext", "subjectAltName=$san\nextendedKeyUsage=serverAuth\n");
        sh("openssl req -newkey rsa:2048 -nodes -keyout $q/$name.key -out $q/$name.csr -subj '/CN=radius.example.test'");
        sh("openssl x509 -req -in $q/$name.csr -CA $q/$issuer.pem -CAkey $q/$issuer.key -CAcreateserial -out $q/$name.pem -days 2 -extfile $q/$name.ext");
    };
    $leaf('good', 'DNS:radius.example.test', 'ca');
    $leaf('renewed', 'DNS:other.invalid, DNS:radius.example.test', 'ca');
    $leaf('wrongname', 'DNS:radius.example.test.invalid', 'ca');
    $leaf('stranger', 'DNS:radius.example.test', 'other');
    file_put_contents("$work/clientonly.ext", "subjectAltName=DNS:radius.example.test\nextendedKeyUsage=clientAuth\n");
    sh("openssl req -newkey rsa:2048 -nodes -keyout $q/clientonly.key -out $q/clientonly.csr -subj '/CN=radius.example.test'");
    sh("openssl x509 -req -in $q/clientonly.csr -CA $q/ca.pem -CAkey $q/ca.key -CAcreateserial -out $q/clientonly.pem -days 2 -extfile $q/clientonly.ext");
    sh("openssl req -newkey rsa:2048 -nodes -keyout $q/int.key -out $q/int.csr -subj '/CN=Test Intermediate'");
    file_put_contents("$work/int.ext", "basicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\n");
    sh("openssl x509 -req -in $q/int.csr -CA $q/ca.pem -CAkey $q/ca.key -CAcreateserial -out $q/int.pem -days 2 -extfile $q/int.ext");
    $leaf('viaint', 'DNS:radius.example.test', 'int');
    sh("openssl ecparam -name prime256v1 -genkey -out $q/ec.key");
    file_put_contents("$work/ec.ext", "subjectAltName=DNS:radius.example.test\nextendedKeyUsage=serverAuth\n");
    sh("openssl req -new -key $q/ec.key -out $q/ec.csr -subj '/CN=radius.example.test'");
    sh("openssl x509 -req -in $q/ec.csr -CA $q/ca.pem -CAkey $q/ca.key -CAcreateserial -out $q/ec.pem -days 2 -extfile $q/ec.ext");
    $pem = fn($name) => file_get_contents("$work/$name");
    $good = ['server.pem' => $pem('good.pem'), 'server.key' => $pem('good.key'), 'ca.pem' => $pem('ca.pem')];
    $renewed = ['server.pem' => $pem('renewed.pem'), 'server.key' => $pem('renewed.key'), 'ca.pem' => $pem('ca2.pem')];
    $leafDates = openssl_x509_parse($good['server.pem']);

    /* A good set becomes leaf + authority, the key, the authority. */
    $bundle = verify_set(set($good));
    check($bundle['server.pem'] === trim($good['server.pem']) . "\n" . trim($good['ca.pem']) . "\n", 'server.pem is not leaf then authority.');
    check($bundle['server.key'] === trim($good['server.key']) . "\n" && $bundle['ca.pem'] === trim($good['ca.pem']) . "\n", 'The key or ca.pem changed.');
    /* The re-signed authority is accepted for a certificate the original issued. */
    check(isset(verify_set(set(['ca.pem' => $pem('ca2.pem')] + $good))['server.pem']), 'The re-signed authority was refused.');
    check(isset(verify_set(set($renewed))['server.pem']), 'A SAN list holding the name was refused.');
    /* A full chain in server.pem, an authority list in either order, an EC key behind its parameters. */
    $chain = verify_set(set(['server.pem' => $pem('viaint.pem') . $pem('int.pem'), 'server.key' => $pem('viaint.key'),
        'ca.pem' => $pem('ca.pem')]));
    check(substr_count($chain['server.pem'], 'BEGIN CERTIFICATE') === 3 && $chain['ca.pem'] === trim($pem('ca.pem')) . "\n",
        'A full chain was not leaf, intermediate, root with the root as ca.pem.');
    check(isset(verify_set(set(['server.pem' => $pem('viaint.pem'), 'server.key' => $pem('viaint.key'),
        'ca.pem' => $pem('ca.pem') . $pem('int.pem')]))['server.pem']), 'An authority list was refused.');
    check(isset(verify_set(set(['server.pem' => $pem('viaint.pem'), 'server.key' => $pem('viaint.key'),
        'ca.pem' => $pem('int.pem') . $pem('ca.pem')]))['server.pem']), 'An authority list in the other order was refused.');
    check(strpos($pem('ec.key'), 'EC PARAMETERS') !== false, 'The EC fixture has no parameters block.');
    check(isset(verify_set(set(['server.pem' => $pem('ec.pem'), 'server.key' => $pem('ec.key')] + $good))['server.key']),
        'An EC key behind its parameters was refused.');
    $dotted = set($good);
    $dotted['SHA256SUMS'] = str_replace('  ', '  ./', $dotted['SHA256SUMS']);
    check(isset(verify_set($dotted)['server.pem']), 'sha256sum ./name output was refused.');

    /* SHA256SUMS as sha256sum writes it, binary marker and upper case included; nothing else. */
    $upper = set($good);
    $upper['SHA256SUMS'] = strtoupper(hash('sha256', $good['server.pem'])) . ' *server.pem' . "\r\n"
        . hash('sha256', $good['server.key']) . '  server.key' . "\n" . hash('sha256', $good['ca.pem']) . '  ca.pem';
    check(isset(verify_set($upper)['server.pem']), 'A valid SHA256SUMS variant was refused.');
    $line = fn($name) => hash('sha256', $good[$name]) . '  ' . $name . "\n";
    $bad = [
        'empty sums' => '',
        'a missing file' => $line('server.pem') . $line('ca.pem'),
        'a repeated file' => $line('server.pem') . $line('server.pem') . $line('server.key') . $line('ca.pem'),
        'an extra file' => sums($good) . hash('sha256', 'x') . "  extra.pem\n",
        'a path' => str_replace('  ca.pem', '  ../ca.pem', sums($good)),
        'a short digest' => substr(sums($good), 1),
        'junk' => "not a sum list\n",
    ];
    foreach ($bad as $name => $text) {
        refused(fn() => verify_set(['SHA256SUMS' => $text] + $good), $name);
    }
    refused(fn() => verify_set(['SHA256SUMS' => sums($good)] + ['server.key' => $good['server.key'] . ' '] + $good),
        'a file that does not match its sum');
    refused(fn() => verify_set(['SHA256SUMS' => sums($good), 'server.pem' => $good['server.pem'], 'ca.pem' => $good['ca.pem']]),
        'a set without its key');

    /* Every check of the reference: chain, dates, name, key. */
    $cases = [
        'a certificate another authority issued' => [['server.pem' => $pem('stranger.pem'), 'server.key' => $pem('stranger.key')] + $good, null, 'chain'],
        'the wrong authority' => [['ca.pem' => $pem('other.pem')] + $good, null, 'chain'],
        'a certificate for another name' => [['server.pem' => $pem('wrongname.pem'), 'server.key' => $pem('wrongname.key')] + $good, null, 'radius.example.test'],
        'a key of another certificate' => [['server.key' => $pem('renewed.key')] + $good, null, 'key'],
        'an expired certificate' => [$good, $leafDates['validTo_time_t'] + 1, 'expired'],
        'a certificate not valid yet' => [$good, $leafDates['validFrom_time_t'] - 3600, 'not valid yet'],
        'a key in place of the certificate' => [['server.pem' => $good['server.key']] + $good, null, 'server.pem'],
        'a key in place of the authority' => [['ca.pem' => $good['server.key']] + $good, null, 'ca.pem'],
        'a certificate for clients only' => [['server.pem' => $pem('clientonly.pem'), 'server.key' => $pem('clientonly.key')] + $good, null, 'TLS server'],
        'an intermediate without its root' => [['server.pem' => $pem('viaint.pem'), 'server.key' => $pem('viaint.key'), 'ca.pem' => $pem('int.pem')], null, 'chain'],
        'two keys' => [['server.key' => $good['server.key'] . $pem('renewed.key')] + $good, null, 'exactly one'],
    ];
    foreach ($cases as $name => [$files, $now, $expect]) {
        $text = refused(fn() => verify_set(set($files), $now), $name);
        check(stripos($text, $expect) !== false, "$name was refused for another reason: $text");
    }

    /* Source address. */
    check(kazuhahub_radius_sync_base('198.51.100.10', 8080) === 'http://198.51.100.10:8080/', 'An IPv4 source changed.');
    check(kazuhahub_radius_sync_base('fd7a::2', 80) === 'http://[fd7a::2]:80/', 'An IPv6 source was not bracketed.');
    check(kazuhahub_radius_sync_base('certs.example.test', 8080) === 'http://certs.example.test:8080/', 'A host name changed.');
    foreach ([['a b', 80], ['http://x', 80], ['x/y', 80], ['host@x', 80], ['', 80], ['198.51.100.10', 0], ['198.51.100.10', 65536]] as [$host, $port]) {
        refused(fn() => kazuhahub_radius_sync_base($host, $port), "the source $host:$port");
    }

    /* A run: fetch, verify, install, apply, roll back. */
    $base = "$work/cache";
    $on = ['service' => true, 'enabled' => true, 'host' => '198.51.100.10', 'port' => 8080, 'name' => NAME, 'user' => 'site-test',
        'password' => bin2hex(random_bytes(12))];
    $served = set($good);
    $fetched = [];
    $fetch = function ($name) use (&$served, &$fetched) {
        $fetched[] = $name;
        if (!isset($served[$name])) {
            throw new RuntimeException('The central node did not answer (curl error 7).');
        }
        return $served[$name];
    };
    /* The real apply: check.sh exports first (the accepted set, else the trust store) and then checks,
       so the live files change even when the check fails. Failures: null or [message, stage]. */
    $applied = 0;
    $failures = [];
    $modes = [];
    $live = "$work/live";
    $trust = ['server.pem' => "trust store certificate\n", 'server.key' => "trust store key\n", 'ca.pem' => "trust store authority\n"];
    $wasRunning = true;
    $apply = function (?bool $running) use (&$applied, &$failures, &$modes, &$base, &$live, $trust, &$wasRunning) {
        $applied++;
        $modes[] = $running;
        kazuhahub_radius_install($live, current_set($base) ?? $trust);
        $failure = array_shift($failures);
        return $failure === null ? [null, $wasRunning, null] : [$failure[0], $wasRunning, $failure[1]];
    };
    $run = fn($settings, $directory = null) => kazuhahub_radius_sync_run($settings, $fetch, $apply, $directory ?? $base, $live);
    $marker = fn($name) => @file_get_contents("$base/$name");

    foreach ([['service' => false] + $on, ['enabled' => false] + $on] as $off) {
        $state = $run($off);
        check($state['result'] === 'disabled' && $fetched === [] && $applied === 0 && !is_dir($base), 'A sync that is off did something.');
    }
    /* Without a source nothing is pulled. */
    foreach ([['host' => ''] + $on, ['port' => 0] + $on, ['name' => ''] + $on] as $nowhere) {
        $state = $run($nowhere);
        check($state['result'] === 'unconfigured' && strpos($state['message'], 'source') !== false && $fetched === [] && !is_dir($base),
            'A sync without a source pulled something.');
    }
    /* Without an account nothing is pulled. */
    foreach ([['user' => ''] + $on, ['password' => ''] + $on] as $anonymous) {
        $state = $run($anonymous);
        check($state['result'] === 'skipped' && $fetched === [] && $applied === 0 && !is_dir($base), 'A sync without an account pulled something.');
    }

    $served = [];
    $state = $run($on);
    check($state['result'] === 'failed' && strpos($state['message'], 'Keeping the current set') !== false, 'An unreachable node was not a failure.');
    check($applied === 0 && !is_dir("$base/current"), 'An unreachable node changed something.');

    $served = set($good);
    $fetched = [];
    $state = $run($on);
    check($state['result'] === 'updated' && $applied === 1 && $modes === [null], 'The first good set was not installed: ' . json_encode($state));
    check($fetched === ['SHA256SUMS', 'server.pem', 'server.key', 'ca.pem'], 'The fetch order changed: ' . json_encode($fetched));
    check($state['not_after'] === $leafDates['validTo_time_t'] && strlen(str_replace(':', '', $state['ca_fingerprint'])) === 64, 'The dates are missing.');
    check((fileperms($base) & 0777) === 0700 && (fileperms("$base/current") & 0777) === 0700
        && (fileperms("$base/lock") & 0777) === 0600 && (fileperms("$base/applied") & 0777) === 0600, 'The cache is not private.');
    foreach (['SHA256SUMS', 'server.pem', 'server.key', 'ca.pem'] as $name) {
        check((fileperms("$base/current/$name") & 0777) === 0600, "$name in the cache is not 0600.");
        check(file_get_contents("$base/current/$name") === $served[$name], "$name in the cache differs.");
    }
    check($marker('applied') === $served['SHA256SUMS'], 'The applied marker does not name the new set.');
    check(current_set($base) === $bundle, 'The cached set is not what the exporter installs.');
    check(current_set($base, $leafDates['validTo_time_t'] + 1) === null, 'An expired cached set is still offered.');

    /* Unchanged: one request, nothing applied. */
    $fetched = [];
    $state = $run($on);
    check($state['result'] === 'unchanged' && $fetched === ['SHA256SUMS'] && $applied === 1, 'An unchanged set was fetched or applied again.');

    /* The daemon's files differ from the accepted set (a start fell back to the trust store): install it again. */
    kazuhahub_radius_install($live, $trust);
    $state = $run($on);
    check($state['result'] === 'reinstalled' && $applied === 2 && $modes[1] === null, 'A stale daemon set was not installed again.');
    check(file_get_contents("$live/server.pem") === $bundle['server.pem'], 'The reinstall did not export the accepted set.');
    check($run($on)['result'] === 'unchanged' && $applied === 2, 'A reinstalled set was applied again.');
    /* A run cut off after the swap left no applied marker: the set is installed again. */
    unlink("$base/applied");
    check($run($on)['result'] === 'reinstalled' && $applied === 3 && $marker('applied') === set($good)['SHA256SUMS'],
        'A set whose apply never finished counted as unchanged.');
    /* A reinstall radiusd refuses drops the set: the daemon on the old set is left alone, the trust store
       is exported (there is no previous set yet) and the set waits a day. */
    kazuhahub_radius_install($live, $trust);
    $failures = [['the configuration check failed.', 'check']];
    $modes = [];
    $state = $run($on);
    check($state['result'] === 'failed' && strpos($state['message'], 'dropped') !== false && $modes === [null, false],
        'A refused reinstall restarted something or was not dropped: ' . json_encode([$state, $modes]));
    check(!is_dir("$base/current") && file_get_contents("$live/server.pem") === $trust['server.pem']
        && kazuhahub_radius_sync_state($on, $base, null, $live)['in_use'] === 'trust store', 'The refused set is still offered.');
    check($marker('rejected') === set($good)['SHA256SUMS'], 'The refused set was not marked.');
    check(strpos($run($on)['message'], 'less than a day') !== false, 'A refused set was tried again at once.');
    /* Sync now takes it again; a reinstall whose restart fails brings the service back up. */
    check($run(['force' => true] + $on)['result'] === 'updated', 'A forced retry did not install the set.');
    kazuhahub_radius_install($live, $trust);
    $failures = [['the service did not restart.', 'restart']];
    $modes = [];
    $state = $run($on);
    check($state['result'] === 'failed' && $modes === [null, true], 'A reinstall that left the service down did not start it: ' . json_encode($modes));
    /* A stopped service whose start failed on the accepted set (a run cut off after the swap): the set is
       dropped for the previous one and the service is started on that. */
    unlink("$base/rejected");
    check($run(['force' => true] + $on)['result'] === 'updated', 'The set was not installed again.');
    $wasRunning = false;
    kazuhahub_radius_install($live, $trust);
    $failures = [['the configuration check failed.', 'check']];
    $modes = [];
    $state = $run($on);
    check($state['result'] === 'failed' && $modes === [null, true], 'A stopped service was not brought up after the drop: ' . json_encode($modes));
    $wasRunning = true;
    unlink("$base/rejected");
    check($run(['force' => true] + $on)['result'] === 'updated' && !file_exists("$base/rejected"), 'The set did not come back.');
    check($run($on)['result'] === 'unchanged', 'The set was not settled.');
    $applied = 1;
    $modes = [];

    /* A changed set that fails a check, or a download, is ignored. */
    foreach (['a bad key' => set(['server.key' => $pem('renewed.key')] + $good),
              'a download that fails' => ['SHA256SUMS' => sums($renewed), 'server.pem' => $renewed['server.pem']]] as $name => $bad) {
        $served = $bad;
        $state = $run($on);
        check($state['result'] === 'failed' && $applied === 1, "$name was applied.");
        check(current_set($base) === $bundle, "$name changed the cached set.");
    }

    /* A new set radiusd's check refuses: the daemon was never touched, so the previous files are only
       exported again, and the set is not tried again for a day. */
    $served = set($renewed);
    $failures = [['the configuration check failed.', 'check']];
    $state = $run($on);
    check($state['result'] === 'failed' && strpos($state['message'], 'rolled back: the configuration check failed.') !== false, 'A refused set was kept: ' . json_encode($state));
    check($modes === [null, false], 'The restore after a failed check restarted the service: ' . json_encode($modes));
    check(current_set($base) === $bundle && file_get_contents("$live/server.pem") === $bundle['server.pem'],
        'The previous set was not restored where the daemon reads it.');
    check($marker('rejected') === $served['SHA256SUMS'] && $marker('applied') === set($good)['SHA256SUMS'], 'The markers are wrong after a rollback.');
    $fetched = [];
    $state = $run($on);
    check($state['result'] === 'failed' && strpos($state['message'], 'less than a day') !== false && $applied === 3
        && $fetched === ['SHA256SUMS'], 'A rolled-back set was tried again at once.');
    /* Sync now forces a retry; this time the restart fails, so the restore brings the service back up. */
    $failures = [['the service did not restart.', 'restart']];
    $modes = [];
    $state = $run(['force' => true] + $on);
    check($state['result'] === 'failed' && $modes === [null, true], 'A forced retry or its restore went wrong: ' . json_encode($modes));
    /* When the previous set cannot be restored either, the fault is not the new set's: it is not marked. */
    unlink("$base/rejected");
    $failures = [['the configuration check failed.', 'check'], ['the configuration check failed.', 'check']];
    $state = $run($on);
    check(strpos($state['message'], 'Restoring the previous set also failed') !== false, 'A failed restore was not reported.');
    check(!file_exists("$base/rejected") && current_set($base) === $bundle, 'A failed restore marked the set or lost the previous one.');

    /* Then it goes through, and the old set is kept as the previous one. */
    $applied = 0;
    $state = $run($on);
    check($state['result'] === 'updated' && $applied === 1, 'The renewed set was not installed.');
    check(!file_exists("$base/rejected") && $marker('applied') === $served['SHA256SUMS'], 'The markers are wrong after the update.');
    check(file_get_contents("$base/previous/server.pem") === $good['server.pem'], 'The previous set was not kept.');
    check(current_set($base)['ca.pem'] === trim($pem('ca2.pem')) . "\n", 'The renewed authority is not in use.');
    $names = scandir($base);
    check(!in_array('incoming', $names, true), 'The staging directory was left behind.');

    /* A first set the service refuses leaves no cache, so the trust store is exported again. */
    $fresh = "$work/fresh";
    $served = set($good);
    $failures = [['the configuration check failed.', 'check']];
    $applied = 0;
    $base = $fresh;
    $state = $run($on);
    check($state['result'] === 'failed' && $applied === 2 && !is_dir("$fresh/current") && file_get_contents("$live/server.pem") === $trust['server.pem'],
        'A refused first set was kept.');
    $base = "$work/cache";

    /* One run at a time. */
    $lock = fopen("$base/lock", 'c');
    flock($lock, LOCK_EX);
    $state = $run($on);
    check($state['result'] === 'busy', 'Two runs overlapped.');
    flock($lock, LOCK_UN);
    fclose($lock);

    /* Links are never followed into the cache. */
    $linked = "$work/linked";
    mkdir($linked, 0700);
    mkdir("$work/elsewhere", 0700);
    foreach (set($good) as $name => $content) {
        file_put_contents("$work/elsewhere/$name", $content);
    }
    symlink("$work/elsewhere", "$linked/current");
    check(current_set($linked) === null, 'A linked cache was used.');

    /* Apply: check first, restart only a running service, never start a stopped one. */
    $calls = [];
    $results = [];
    $exec = function (array $command) use (&$calls, &$results) {
        $calls[] = implode(' ', $command);
        return array_shift($results) ?? 0;
    };
    $rc = '/usr/local/etc/rc.d/kazuhahub_radiusd';
    $check = '/bin/sh /usr/local/opnsense/scripts/kazuhahub-radius/check.sh start';
    $results = [0, 0, 0];
    check(kazuhahub_radius_sync_apply(null, $exec) === [null, true, null] && $calls === ["$rc onestatus", $check, "$rc restart"], 'A running service was not checked and restarted.');
    $calls = [];
    $results = [1, 0];
    check(kazuhahub_radius_sync_apply(null, $exec) === [null, false, null] && $calls === ["$rc onestatus", $check], 'A stopped service was started.');
    $calls = [];
    $results = [0, 1];
    check(kazuhahub_radius_sync_apply(null, $exec) === ['the configuration check failed.', true, 'check'] && $calls === ["$rc onestatus", $check], 'A failed check restarted the service.');
    $calls = [];
    $results = [0, 0, 1];
    check(kazuhahub_radius_sync_apply(null, $exec) === ['the service did not restart.', true, 'restart'], 'A failed restart was not reported.');
    $calls = [];
    $results = [1, 0, 0];
    check(kazuhahub_radius_sync_apply(true, $exec) === [null, false, null] && $calls === ["$rc onestatus", $check, "$rc start"], 'A restore did not start the stopped service.');
    $calls = [];
    $results = [0, 0];
    check(kazuhahub_radius_sync_apply(false, $exec) === [null, true, null] && $calls === ["$rc onestatus", $check], 'A check-only restore restarted the service.');

    /* State: which set the daemon's files are, and what the last run said; never the password. */
    kazuhahub_radius_install($live, current_set($base));
    kazuhahub_radius_sync_record($base, ['result' => 'unchanged', 'message' => 'The central set is unchanged.', 'checked' => 5, 'accepted' => 4]);
    check((fileperms("$base/state.json") & 0777) === 0600, 'The state file is not private.');
    $state = kazuhahub_radius_sync_state($on, $base, null, $live);
    check($state['in_use'] === 'central set' && $state['result'] === 'unchanged' && $state['checked'] === 5 && $state['accepted'] === 4
        && $state['source'] === '198.51.100.10:8080' && $state['enabled'] === true && $state['account'] === true && $state['ca_not_after'] > 0,
        'The state is wrong: ' . json_encode($state));
    check(strpos(json_encode($state), $on['password']) === false && strpos(json_encode($state), 'BEGIN') === false, 'The state carries a secret.');
    kazuhahub_radius_install($live, $trust);
    check(kazuhahub_radius_sync_state($on, $base, null, $live)['in_use'] === 'trust store', 'A daemon on the trust store was reported on the central set.');
    $state = kazuhahub_radius_sync_state(['enabled' => false] + $on, $base, null, $live);
    check($state['in_use'] === 'trust store' && !isset($state['not_after']), 'A sync that is off reported the central set.');
    check(kazuhahub_radius_sync_state(['user' => ''] + $on, $base, null, $live)['account'] === false, 'A missing account was not reported.');
    check(kazuhahub_radius_sync_state(['host' => ''] + $on, $base, null, $live)['source'] === '', 'An empty source was shown as an address.');
    refused(fn() => kazuhahub_radius_sync_verify(set($good), null, ''), 'a set checked without an expected name');

    /* The exporter: the valid accepted set wins, the trust store is the fallback, a 1.0.0 config syncs. */
    $store = ['cert' => ['crt' => base64_encode($pem('stranger.pem')), 'prv' => base64_encode($pem('stranger.key'))],
        'other' => ['crt' => base64_encode($pem('other.pem'))]];
    $general = ['servercert' => 'cert', 'ca' => ['other'], 'cert_sync_name' => NAME];
    [$source] = kazuhahub_radius_main();
    check($source === 'trust store' && strpos(file_get_contents(KAZUHAHUB_RADIUS_CERT_DIR . '/server.pem'), trim($pem('stranger.pem'))) === 0,
        'Without a synced set the trust store was not used.');
    kazuhahub_radius_sync_store(KAZUHAHUB_RADIUS_SYNC_DIR, []);
    kazuhahub_radius_sync_store(KAZUHAHUB_RADIUS_SYNC_DIR . '/current', set($good));
    [$source] = kazuhahub_radius_main();
    check($source === 'central set' && file_get_contents(KAZUHAHUB_RADIUS_CERT_DIR . '/server.pem') === $bundle['server.pem'],
        'A config without cert_sync did not use the synced set.');
    $general['cert_sync'] = '0';
    [$source] = kazuhahub_radius_main();
    check($source === 'trust store', 'The sync switched off still used the synced set.');
    $general['cert_sync'] = '1';
    file_put_contents(KAZUHAHUB_RADIUS_SYNC_DIR . '/current/server.key', $pem('renewed.key'));
    [$source] = kazuhahub_radius_main();
    check($source === 'trust store', 'An invalid synced set was used.');
    $general = ['cert_sync' => '1', 'cert_sync_name' => NAME];
    refused(fn() => kazuhahub_radius_main(), 'an invalid synced set without a trust store certificate');

    /* The HTTP client against a local server: 200 only, no redirects, a size limit. */
    if (extension_loaded('curl')) {
        $docroot = "$work/www";
        mkdir($docroot, 0700);
        $password = bin2hex(random_bytes(12));
        file_put_contents("$docroot/password", $password);
        file_put_contents("$docroot/router.php", '<?php
if (($_SERVER["PHP_AUTH_USER"] ?? "") !== "site-test" || ($_SERVER["PHP_AUTH_PW"] ?? "") !== file_get_contents(__DIR__ . "/password")) {
    header("WWW-Authenticate: Basic realm=\"certs\"", true, 401); echo "no"; return true;
}
$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($path === "/SHA256SUMS") { echo "sums"; return true; }
if ($path === "/moved") { header("Location: /SHA256SUMS", true, 302); return true; }
if ($path === "/big") { echo str_repeat("x", 70000); return true; }
http_response_code(403); echo "forbidden"; return true;
');
        $port = 0;
        for ($try = 0; $try < 20 && $server === null; $try++) {
            $port = random_int(20000, 60000);
            $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", "$docroot/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            $ready = false;
            for ($wait = 0; $wait < 50 && !$ready; $wait++) {
                usleep(100000);
                $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
                if ($socket) {
                    fclose($socket);
                    $ready = true;
                }
            }
            if (!$ready) {
                proc_terminate($server);
                $server = null;
            }
        }
        check($server !== null, 'The local HTTP server did not start.');
        $url = "http://127.0.0.1:$port/";
        $get = fn($name) => kazuhahub_radius_sync_get($url . $name, 'site-test', $password);
        check($get('SHA256SUMS') === 'sums', 'A 200 body was not returned with the account.');
        $text = refused(fn() => kazuhahub_radius_sync_get($url . 'SHA256SUMS', 'site-test', 'wrong-password'), 'a wrong password');
        check(strpos($text, 'HTTP 401') !== false && strpos($text, 'wrong-password') === false, 'A 401 was not named, or named the password.');
        check(strpos(refused(fn() => kazuhahub_radius_sync_get($url . 'SHA256SUMS', '', ''), 'no account'), 'not set') !== false,
            'A request without an account was made.');
        check(strpos(refused(fn() => $get('server.key'), 'a 403'), 'HTTP 403') !== false, 'A 403 was not named.');
        check(strpos(refused(fn() => $get('moved'), 'a redirect'), 'HTTP 302') !== false, 'A redirect was followed.');
        check(strpos(refused(fn() => $get('big'), 'an oversized body'), '64 KiB') !== false, 'An oversized body was not named.');
        refused(fn() => kazuhahub_radius_sync_get("https://127.0.0.1:$port/SHA256SUMS", 'site-test', $password), 'another protocol');
        proc_terminate($server);
        proc_close($server);
        $server = null;
    }
    echo "Kazuha Hub RADIUS certificate sync passed: sums, chain, dates, name and key checks, install, rollback, state, HTTP limits.\n";
} finally {
    if ($server !== null) {
        proc_terminate($server);
    }
    exec('rm -rf ' . escapeshellarg($work));
}
