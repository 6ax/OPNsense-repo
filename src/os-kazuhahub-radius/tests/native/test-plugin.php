<?php
/* The service registration: present only while the plugin is enabled, and exactly what the rc script and configd offer. */
$setting = null;
function config_read_array($a, $b, $c, $default)
{
    global $setting;
    check($a === 'OPNsense' && $b === 'kazuhahubradius' && $c === 'general' && $default === false, 'The setting path changed.');
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

require_once($argv[1] ?? dirname(__DIR__, 2) . '/src/usr/local/etc/inc/plugins.inc.d/kazuhahubradius.inc');

foreach ([null, false, [], ['enabled' => '0'], ['enabled' => ''], ['enabled' => 'yes']] as $setting) {
    check(kazuhahubradius_services() === [], 'A disabled plugin registered a service: ' . json_encode($setting));
}
$setting = ['enabled' => '1'];
check(kazuhahubradius_services() === [[
    'description' => 'Kazuha Hub RADIUS',
    'configd' => [
        'restart' => ['kazuhahubradius restart'],
        'start' => ['kazuhahubradius start'],
        'stop' => ['kazuhahubradius stop'],
    ],
    'name' => 'kazuhahub_radiusd',
    'pidfile' => '/var/run/kazuhahub-radius/radiusd.pid',
]], 'The registration changed.');
/* The certificate sync is scheduled whatever the settings; the run itself does nothing while they are off. */
foreach ([null, ['enabled' => '0'], ['enabled' => '1']] as $setting) {
    check(kazuhahubradius_cron() === [['autocron' => ['/usr/local/sbin/configctl -d kazuhahubradius certsync', '23', '*/6']]],
        'The certificate sync schedule changed.');
}
echo "Kazuha Hub RADIUS plugin registration passed: enabled only, rc and configd names, the 6-hourly sync.\n";
