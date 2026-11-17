<?php
/* The central server state: radmin's table parsed, and the helper's answers when radmin is missing, failing or hanging. */
define('KAZUHAHUB_WIFIRADIUSPROXY_NO_MAIN', true);
require dirname(__DIR__, 2) . '/src/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/homeservers.php';

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/* What radmin prints for "show home_server list" on FreeRADIUS 3.2.10. */
$table = "127.0.0.2\t1812\tudp\tauth\talive\t0\n127.0.0.3\t1812\tudp\tauth\tzombie\t2\n" .
    "198.51.100.10\t1645\tudp\tauth\tdead\t0\n198.51.100.11\t1812\tudp\tauth\tunknown\t0\n";
$expected = [
    ['address' => '127.0.0.2', 'port' => 1812, 'state' => 'alive', 'outstanding' => 0],
    ['address' => '127.0.0.3', 'port' => 1812, 'state' => 'zombie', 'outstanding' => 2],
    ['address' => '198.51.100.10', 'port' => 1645, 'state' => 'dead', 'outstanding' => 0],
    ['address' => '198.51.100.11', 'port' => 1812, 'state' => 'unknown', 'outstanding' => 0],
];
check(kazuhahub_wifiradiusproxy_parse_home_servers($table) === $expected, 'The table was parsed wrongly.');
check(kazuhahub_wifiradiusproxy_parse_home_servers(str_replace("\n", "\r\n", $table)) === $expected, 'CRLF lines were parsed wrongly.');
check(kazuhahub_wifiradiusproxy_parse_home_servers(preg_replace('/\t/', '   ', $table)) === $expected, 'Spaces were parsed wrongly.');
check(kazuhahub_wifiradiusproxy_parse_home_servers('') === [], 'An empty table has servers.');
/* Noise, other server types, odd states and bad addresses never make an entry, and never break the page. */
check(kazuhahub_wifiradiusproxy_parse_home_servers("radmin> prompt\n127.0.0.2\t1812\tudp\tacct\talive\t0\n" .
    "not-an-ip\t1812\tudp\tauth\talive\t0\n127.0.0.4\tabc\tudp\tauth\talive\t0\n127.0.0.5\t1812\tudp\tauth\n") === [], 'Noise made entries.');
$odd = kazuhahub_wifiradiusproxy_parse_home_servers("127.0.0.6\t1812\tudp\tauth\t<script>\tmany\n");
check($odd === [['address' => '127.0.0.6', 'port' => 1812, 'state' => 'unknown', 'outstanding' => 0]], 'An odd state leaked through.');

$work = sys_get_temp_dir() . '/kazuhahub-wifi-radius-proxy-homeservers-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
try {
    /* No control socket: the service is not running. */
    check(kazuhahub_wifiradiusproxy_home_servers("$work/missing.sock", '/bin/true') === ['status' => 'stopped', 'servers' => []],
        'A missing socket is not "stopped".');

    /* A socket file and a radmin that answers, with the arguments it must be given. */
    file_put_contents("$work/radiusd.sock", '');
    file_put_contents("$work/args", '');
    $good = "$work/radmin-good";
    file_put_contents($good, "#!/bin/sh\nprintf '%s\\n' \"\$*\" > '$work/args'\nprintf '" .
        str_replace("\n", '\\n', str_replace("\t", '\\t', $table)) . "'\n");
    chmod($good, 0755);
    $answer = kazuhahub_wifiradiusproxy_home_servers("$work/radiusd.sock", $good);
    check($answer === ['status' => 'ok', 'servers' => $expected], 'The answer is wrong: ' . json_encode($answer));
    check(trim(file_get_contents("$work/args")) === "-f $work/radiusd.sock -e show home_server list", 'radmin got other arguments.');
    check(json_decode(json_encode($answer), true) === $answer, 'The answer is not plain JSON.');

    /* A radmin that fails, or is missing, is "unavailable", not an error page. */
    $bad = "$work/radmin-bad";
    file_put_contents($bad, "#!/bin/sh\necho 'Failed connecting to socket' >&2\nexit 1\n");
    chmod($bad, 0755);
    check(kazuhahub_wifiradiusproxy_home_servers("$work/radiusd.sock", $bad) === ['status' => 'unavailable', 'servers' => []], 'A failing radmin.');
    check(kazuhahub_wifiradiusproxy_home_servers("$work/radiusd.sock", "$work/nonexistent") === ['status' => 'unavailable', 'servers' => []],
        'A missing radmin.');

    /* A radmin that hangs is stopped after the time limit. */
    $slow = "$work/radmin-slow";
    file_put_contents($slow, "#!/bin/sh\nsleep 30\n");
    chmod($slow, 0755);
    $started = microtime(true);
    check(kazuhahub_wifiradiusproxy_run([$slow], 1) === null, 'A hanging command was waited for.');
    check(microtime(true) - $started < 5, 'The time limit did not hold.');
    $result = kazuhahub_wifiradiusproxy_run(['/bin/sh', '-c', 'printf out; exit 3'], 5);
    check($result === ['out', 3], 'The exit status or output was lost: ' . json_encode($result));
    echo "Kazuha Hub Wi-Fi RADIUS central server state passed: parsing, stopped, unavailable and the time limit.\n";
} finally {
    exec('rm -rf ' . escapeshellarg($work));
}
