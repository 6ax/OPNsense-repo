<?php
/* The service registration: present only while the plugin is enabled, and exactly what the rc script and configd offer. */
$setting = null;
function config_read_array($a, $b, $c, $default)
{
    global $setting;
    check($a === 'OPNsense' && $b === 'kazuhahubwifiradiusproxy' && $c === 'general' && $default === false, 'The setting path changed.');
    return $setting;
}

if (!function_exists('gettext')) {
    function gettext($text)
    {
        return $text;
    }
}

function check($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once($argv[1] ?? dirname(__DIR__, 2) . '/src/usr/local/etc/inc/plugins.inc.d/kazuhahubwifiradiusproxy.inc');

foreach ([null, false, [], ['enabled' => '0'], ['enabled' => ''], ['enabled' => 'yes']] as $setting) {
    check(kazuhahubwifiradiusproxy_services() === [], 'A disabled plugin registered a service: ' . json_encode($setting));
}
$setting = ['enabled' => '1'];
check(kazuhahubwifiradiusproxy_services() === [[
    'description' => 'Kazuha Hub Wi-Fi RADIUS',
    'configd' => [
        'restart' => ['kazuhahubwifiradiusproxy restart'],
        'start' => ['kazuhahubwifiradiusproxy start'],
        'stop' => ['kazuhahubwifiradiusproxy stop'],
    ],
    'name' => 'kazuhahub_wifiradiusproxy',
    'pidfile' => '/var/run/kazuhahub-wifi-radius-proxy/radiusd.pid',
]], 'The registration changed.');
/* The certificate sync is scheduled whatever the settings; the run itself does nothing while they are off. */
foreach ([null, ['enabled' => '0'], ['enabled' => '1']] as $setting) {
    check(kazuhahubwifiradiusproxy_cron() === [['autocron' => ['/usr/local/sbin/configctl -d kazuhahubwifiradiusproxy certsync', '23', '*/6']],
        ['autocron' => ['/bin/sh /usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/keep_installed.sh once', '41']],
        ['autocron' => ['/usr/local/bin/php /usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/takeover.php apply', '43']]],
        'The certificate sync or the automatic-flag schedule changed.');
}
echo "Kazuha Hub Wi-Fi RADIUS plugin registration passed: enabled only, rc and configd names, the 6-hourly sync.\n";
