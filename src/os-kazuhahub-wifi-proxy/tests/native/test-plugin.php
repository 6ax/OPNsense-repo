<?php
/* The service registration: present only while the plugin is enabled, and exactly what the rc script and configd offer. */
$setting = null;
function config_read_array($a, $b, $c, $default)
{
    global $setting;
    check($a === 'OPNsense' && $b === 'kazuhahubwifiproxy' && $c === 'general' && $default === false, 'The setting path changed.');
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

require_once($argv[1] ?? dirname(__DIR__, 2) . '/src/usr/local/etc/inc/plugins.inc.d/kazuhahubwifiproxy.inc');

foreach ([null, false, [], ['enabled' => '0'], ['enabled' => ''], ['enabled' => 'yes']] as $setting) {
    check(kazuhahubwifiproxy_services() === [], 'A disabled plugin registered a service: ' . json_encode($setting));
}
$setting = ['enabled' => '1'];
check(kazuhahubwifiproxy_services() === [[
    'description' => 'Kazuha Hub Wi-Fi Proxy',
    'configd' => [
        'restart' => ['kazuhahubwifiproxy restart'],
        'start' => ['kazuhahubwifiproxy start'],
        'stop' => ['kazuhahubwifiproxy stop'],
    ],
    'name' => 'kazuhahub_wifiproxy',
    'pidfile' => '/var/run/kazuhahub-wifi-proxy/radiusd.pid',
]], 'The registration changed.');
/* The certificate sync is scheduled whatever the settings; the run itself does nothing while they are off. */
foreach ([null, ['enabled' => '0'], ['enabled' => '1']] as $setting) {
    check(kazuhahubwifiproxy_cron() === [['autocron' => ['/usr/local/sbin/configctl -d kazuhahubwifiproxy certsync', '23', '*/6']],
        ['autocron' => ['/bin/sh /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/keep_installed.sh once', '41']],
        ['autocron' => ['/usr/local/bin/php /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/takeover.php apply', '43']]],
        'The certificate sync or the automatic-flag schedule changed.');
}
echo "Kazuha Hub Wi-Fi Proxy plugin registration passed: enabled only, rc and configd names, the 6-hourly sync.\n";
