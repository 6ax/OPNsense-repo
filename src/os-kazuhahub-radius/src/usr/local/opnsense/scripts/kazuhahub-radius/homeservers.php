#!/usr/local/bin/php
<?php

/*
 * Print the state radiusd keeps for each central server, as JSON.
 *
 *   homeservers.php
 *
 * The state comes from radiusd's control socket through radmin, which only
 * root can reach. alive, zombie and dead are radiusd's own words: zombie means
 * a request went unanswered and the server is being probed, dead that it
 * stayed silent through the whole zombie period.
 */

const KAZUHAHUB_RADIUS_SOCKET = '/var/run/kazuhahub-radius/radiusd.sock';
const KAZUHAHUB_RADIUS_RADMIN = '/usr/local/sbin/radmin';
const KAZUHAHUB_RADIUS_TIMEOUT = 5;

/**
 * Parse the output of "show home_server list".
 *
 * Each line is: address, port, protocol, type, state, outstanding requests.
 *
 * @return array[] address, port, state and outstanding of each authentication home server
 */
function kazuhahub_radius_parse_home_servers($output)
{
    $servers = [];
    foreach (preg_split('/\R/', (string)$output) as $line) {
        $fields = preg_split('/\s+/', trim($line));
        if (count($fields) < 6 || filter_var($fields[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            continue;
        }
        if (!ctype_digit($fields[1]) || $fields[3] !== 'auth') {
            continue;
        }
        $state = in_array($fields[4], ['alive', 'zombie', 'dead', 'unknown'], true) ? $fields[4] : 'unknown';
        $servers[] = [
            'address' => $fields[0],
            'port' => (int)$fields[1],
            'state' => $state,
            'outstanding' => ctype_digit($fields[5]) ? (int)$fields[5] : 0,
        ];
    }
    return $servers;
}

/**
 * Run a command without a shell and collect its output within a time limit.
 *
 * @return array|null [output, exit status], null when it could not run or ran too long
 */
function kazuhahub_radius_run(array $command, $seconds)
{
    $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + $seconds;
    while (true) {
        $status = proc_get_status($process);
        $output .= (string)stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        if (!$status['running']) {
            $output .= (string)stream_get_contents($pipes[1]);
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($process, 9);
            proc_close($process);
            return null;
        }
        usleep(50000);
    }
    $code = $status['exitcode'];
    proc_close($process);
    return [$output, $code];
}

function kazuhahub_radius_home_servers($socket, $radmin)
{
    if (!file_exists($socket)) {
        return ['status' => 'stopped', 'servers' => []];
    }
    $result = kazuhahub_radius_run([$radmin, '-f', $socket, '-e', 'show home_server list'], KAZUHAHUB_RADIUS_TIMEOUT);
    if ($result === null || $result[1] !== 0) {
        return ['status' => 'unavailable', 'servers' => []];
    }
    return ['status' => 'ok', 'servers' => kazuhahub_radius_parse_home_servers($result[0])];
}

if (PHP_SAPI === 'cli' && !defined('KAZUHAHUB_RADIUS_NO_MAIN')) {
    echo json_encode(kazuhahub_radius_home_servers(KAZUHAHUB_RADIUS_SOCKET, KAZUHAHUB_RADIUS_RADMIN)) . "\n";
}
