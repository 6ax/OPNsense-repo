<?php

/*
 * Kazuha Hub Wi-Fi RADIUS glue for OPNsense.
 *
 * The daemon is the FreeRADIUS server of the freeradius3 package, started by
 * its own rc script with a private configuration directory. This file only
 * registers it as a service, so the services page and the dashboard can show
 * and control it. No firewall rule is added: the access points sit on a local
 * network, which the default LAN rule already allows, and nothing is ever
 * opened on the WAN.
 */

function kazuhahubwifiradiusproxy_enabled()
{
    return (config_read_array('OPNsense', 'kazuhahubwifiradiusproxy', 'general', false)['enabled'] ?? '0') === '1';
}

function kazuhahubwifiradiusproxy_services()
{
    $services = [];

    if (kazuhahubwifiradiusproxy_enabled()) {
        $services[] = [
            'description' => gettext('Kazuha Hub Wi-Fi RADIUS'),
            'configd' => [
                'restart' => ['kazuhahubwifiradiusproxy restart'],
                'start' => ['kazuhahubwifiradiusproxy start'],
                'stop' => ['kazuhahubwifiradiusproxy stop'],
            ],
            'name' => 'kazuhahub_wifiradiusproxy',
            'pidfile' => '/var/run/kazuhahub-wifi-radius-proxy/radiusd.pid',
        ];
    }

    return $services;
}

/* The certificate sync runs every 6 hours and does nothing while the service or the sync is off; the
   automatic-flag check runs hourly and does nothing once the flag is clear. */
function kazuhahubwifiradiusproxy_cron()
{
    $jobs = [];

    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d kazuhahubwifiradiusproxy certsync', '23', '*/6'];
    /* Until pkg no longer marks this package automatic; see keep_installed.sh. */
    $jobs[]['autocron'] = ['/bin/sh /usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/keep_installed.sh once', '41'];
    /* Settings of a former name that came back with a restored config.xml are moved over. */
    $jobs[]['autocron'] = ['/usr/local/bin/php /usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/takeover.php apply', '43'];

    return $jobs;
}
