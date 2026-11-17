<?php
/* The certificate export: what it writes, when it writes, and what it refuses, with a throwaway PKI. */
define('KAZUHAHUB_WIFIPROXY_NO_MAIN', true);
require dirname(__DIR__, 2) . '/src/usr/local/opnsense/scripts/kazuhahub-wifi-proxy/export_certs.php';

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

$work = sys_get_temp_dir() . '/kazuhahub-wifi-proxy-certs-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
try {
    $q = escapeshellarg($work);
    /* root -> intermediate -> leaf, and an unrelated authority */
    sh("openssl req -x509 -newkey rsa:2048 -nodes -keyout $q/root.key -out $q/root.pem -days 2 -subj '/CN=Test Root' " .
        "-addext basicConstraints=critical,CA:TRUE");
    sh("openssl req -newkey rsa:2048 -nodes -keyout $q/int.key -out $q/int.csr -subj '/CN=Test Intermediate'");
    file_put_contents("$work/int.ext", "basicConstraints=critical,CA:TRUE\n");
    sh("openssl x509 -req -in $q/int.csr -CA $q/root.pem -CAkey $q/root.key -CAcreateserial -out $q/int.pem -days 2 -extfile $q/int.ext");
    sh("openssl req -newkey rsa:2048 -nodes -keyout $q/leaf.key -out $q/leaf.csr -subj '/CN=radius.test.invalid'");
    sh("openssl x509 -req -in $q/leaf.csr -CA $q/int.pem -CAkey $q/int.key -CAcreateserial -out $q/leaf.pem -days 2");
    sh("openssl req -x509 -newkey rsa:2048 -nodes -keyout $q/other.key -out $q/other.pem -days 2 -subj '/CN=Other CA'");
    sh("openssl req -newkey rsa:2048 -nodes -keyout $q/stray.key -out $q/stray.csr -subj '/CN=stray'");
    $pem = fn($name) => file_get_contents("$work/$name");

    $leaf = $pem('leaf.pem');
    $key = $pem('leaf.key');
    $int = $pem('int.pem');
    $root = $pem('root.pem');

    /* The files: leaf then the authority chain, the key, the selected authority alone. */
    $files = kazuhahub_wifiproxy_bundle($leaf, $key, [$int, $root]);
    check(array_keys($files) === ['server.pem', 'server.key', 'ca.pem'], 'The bundle names changed.');
    check($files['server.pem'] === trim($leaf) . "\n" . trim($int) . "\n" . trim($root) . "\n", 'server.pem is not leaf, authority, parent.');
    check($files['server.key'] === trim($key) . "\n", 'server.key is not the key.');
    check($files['ca.pem'] === trim($int) . "\n", 'ca.pem is not the selected authority.');
    check(substr_count($files['server.pem'], 'BEGIN CERTIFICATE') === 3, 'The chain was not included.');
    /* Line endings and blank lines around the blocks are normalised away. */
    $messy = str_replace("\n", "\r\n", "\n\n" . $leaf . "\n\n");
    check(kazuhahub_wifiproxy_bundle($messy, $key, [$int])['server.pem'] === trim($leaf) . "\n" . trim($int) . "\n", 'Messy input was kept.');
    /* The leaf is verified against the chain, not only the first entry. */
    check(isset(kazuhahub_wifiproxy_bundle($leaf, $key, [$root, $int])['server.pem']), 'A chain in another order was refused.');

    /* What it refuses, without ever printing key material. */
    $refusals = [
        'unreadable certificate' => [$key, $key, [$int]],
        'no certificate' => ['', $key, [$int]],
        'no key' => [$leaf, '', [$int]],
        'another key' => [$leaf, $pem('other.key'), [$int]],
        'no authority' => [$leaf, $key, []],
        'unreadable authority' => [$leaf, $key, ['not a certificate']],
        'wrong authority' => [$leaf, $key, [$pem('other.pem')]],
        'the root only' => [$leaf, $key, [$root]],
    ];
    foreach ($refusals as $name => [$cert, $private, $chain]) {
        try {
            kazuhahub_wifiproxy_bundle($cert, $private, $chain);
            check(false, "$name was accepted.");
        } catch (RuntimeException $error) {
            $text = $error->getMessage();
            check($text !== '' && strpos($text, 'PRIVATE KEY') === false && strpos($text, 'BEGIN') === false
                && strpos($text, 'MII') === false, "$name leaked material: $text");
        }
    }

    /* Writing: the first export writes all three, the second none, a change only the file it touches. */
    $directory = "$work/certs";
    $changed = kazuhahub_wifiproxy_export($directory, $leaf, $key, [$int, $root]);
    check($changed === ['server.pem', 'server.key', 'ca.pem'], 'The first export did not write all files: ' . json_encode($changed));
    foreach (['server.pem', 'server.key', 'ca.pem'] as $name) {
        check((fileperms("$directory/$name") & 0777) === 0640, "$name is not 0640.");
        check(file_get_contents("$directory/$name") === $files[$name], "$name differs from the bundle.");
    }
    check((fileperms($directory) & 0777) === 0750, 'The directory is not 0750.');
    $before = [];
    foreach (['server.pem', 'server.key', 'ca.pem'] as $name) {
        $before[$name] = fileinode("$directory/$name");
    }
    clearstatcache();
    check(kazuhahub_wifiproxy_export($directory, $leaf, $key, [$int, $root]) === [], 'An unchanged export wrote files.');
    foreach ($before as $name => $inode) {
        check(fileinode("$directory/$name") === $inode, "$name was replaced although it did not change.");
    }
    $changed = kazuhahub_wifiproxy_export($directory, $leaf, $key, [$int]);
    check($changed === ['server.pem'], 'Dropping a parent must change only server.pem: ' . json_encode($changed));
    check(substr_count(file_get_contents("$directory/server.pem"), 'BEGIN CERTIFICATE') === 2, 'The chain did not shrink.');
    check(array_values(array_filter(scandir($directory), fn($n) => $n[0] === '.' && $n !== '.' && $n !== '..')) === [],
        'A temporary file was left behind.');
    $names = array_map('basename', glob("$directory/*"));
    sort($names);
    check($names === ['ca.pem', 'server.key', 'server.pem'], 'Unexpected files: ' . json_encode($names));

    /* A failed export leaves the files that work untouched. */
    $bundle = file_get_contents("$directory/server.pem");
    try {
        kazuhahub_wifiproxy_export($directory, $leaf, $pem('other.key'), [$int]);
        check(false, 'A key mismatch was exported.');
    } catch (RuntimeException $error) {
        check(file_get_contents("$directory/server.pem") === $bundle, 'A failed export changed a file.');
    }

    /* Links are never followed. */
    mkdir("$work/target");
    symlink("$work/target", "$work/linkdir");
    try {
        kazuhahub_wifiproxy_export("$work/linkdir", $leaf, $key, [$int]);
        check(false, 'A symbolic link was used as the directory.');
    } catch (RuntimeException $error) {
        check(glob("$work/target/*") === [], 'Something was written through the link.');
    }
    mkdir("$work/linked");
    file_put_contents("$work/victim", 'untouched');
    symlink("$work/victim", "$work/linked/server.pem");
    try {
        kazuhahub_wifiproxy_export("$work/linked", $leaf, $key, [$int]);
        check(false, 'A symbolic link was written through.');
    } catch (RuntimeException $error) {
        check(file_get_contents("$work/victim") === 'untouched', 'The link target changed.');
    }

    /* The PEM reader. */
    check(kazuhahub_wifiproxy_pem('') === null && kazuhahub_wifiproxy_pem('plain text') === null, 'A non-PEM text was read.');
    check(kazuhahub_wifiproxy_pem("junk\n-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\njunk") ===
        "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n", 'The block was not cut out.');
    echo "Kazuha Hub Wi-Fi Proxy certificate export passed: chain order, atomic changed-only writes, refusals without key material.\n";
} finally {
    exec('rm -rf ' . escapeshellarg($work));
}
