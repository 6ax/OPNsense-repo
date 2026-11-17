<?php

/*
 * Kazuha Hub Wi-Fi Proxy glue for OPNsense.
 *
 * The daemon is the FreeRADIUS server of the freeradius3 package, started by
 * its own rc script with a private configuration directory. This file only
 * registers it as a service, so the services page and the dashboard can show
 * and control it. No firewall rule is added: the access points sit on a local
 * network, which the default LAN rule already allows, and nothing is ever
 * opened on the WAN.
 */

function kazuhahubwifiproxy_enabled()
{
    return (config_read_array('OPNsense', 'kazuhahubwifiproxy', 'general', false)['enabled'] ?? '0') === '1';
}

function kazuhahubwifiproxy_services()
{
    $services = [];

    if (kazuhahubwifiproxy_enabled()) {
        $services[] = [
            'description' => gettext('Kazuha Hub Wi-Fi Proxy'),
            'configd' => [
                'restart' => ['kazuhahubwifiproxy restart'],
                'start' => ['kazuhahubwifiproxy start'],
                'stop' => ['kazuhahubwifiproxy stop'],
            ],
            'name' => 'kazuhahub_wifiproxy',
            'pidfile' => '/var/run/kazuhahub-wifi-proxy/radiusd.pid',
        ];
    }

    return $services;
}

/* The certificate sync runs every 6 hours and does nothing while the service or the sync is off; the
   automatic-flag check runs hourly and does nothing once the flag is clear. */
function kazuhahubwifiproxy_cron()
{
    $jobs = [];

    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d kazuhahubwifiproxy certsync', '23', '*/6'];
    /* Until pkg no longer marks this package automatic; see keep_installed.sh. */
    $jobs[]['autocron'] = ['/bin/sh /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/keep_installed.sh once', '41'];
    /* Settings of the former name that came back with a restored config.xml are moved over. */
    $jobs[]['autocron'] = ['/usr/local/bin/php /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/takeover.php apply', '43'];

    return $jobs;
}
