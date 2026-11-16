<?php

/*
 * Kazuha Hub RADIUS glue for OPNsense.
 *
 * The daemon is the FreeRADIUS server of the freeradius3 package, started by
 * its own rc script with a private configuration directory. This file only
 * registers it as a service, so the services page and the dashboard can show
 * and control it. No firewall rule is added: the access points sit on a local
 * network, which the default LAN rule already allows, and nothing is ever
 * opened on the WAN.
 */

function kazuhahubradius_enabled()
{
    return (config_read_array('OPNsense', 'kazuhahubradius', 'general', false)['enabled'] ?? '0') === '1';
}

function kazuhahubradius_services()
{
    $services = [];

    if (kazuhahubradius_enabled()) {
        $services[] = [
            'description' => gettext('Kazuha Hub RADIUS'),
            'configd' => [
                'restart' => ['kazuhahubradius restart'],
                'start' => ['kazuhahubradius start'],
                'stop' => ['kazuhahubradius stop'],
            ],
            'name' => 'kazuhahub_radiusd',
            'pidfile' => '/var/run/kazuhahub-radius/radiusd.pid',
        ];
    }

    return $services;
}
