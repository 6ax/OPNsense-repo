#!/usr/local/bin/php
<?php

/*
 * Take the settings of a former name over, once, before the model first loads.
 *
 *   takeover.php         move the settings; the install hook runs this
 *   takeover.php apply   also render the configuration and start the service when
 *                        something was moved; the hourly cron job runs this, so a
 *                        config.xml restored from before the upgrade is picked up
 *
 * Until 1.0.0 this plugin was os-kazuhahub-radius and kept its settings under
 * //OPNsense/kazuhahubradius/general; in 1.1.0 it was os-kazuhahub-wifi-proxy,
 * under //OPNsense/kazuhahubwifiproxy/general. The whole node of the newest
 * name found is moved, byte for byte, to
 * //OPNsense/kazuhahubwifiradiusproxy/general: the general settings, the secrets
 * and each central server with its uuid. Nothing is validated on the way, so a
 * setting the model would refuse today is kept for the operator to fix instead
 * of being lost. It is marked with the model version that name wrote (1.0.0 for
 * the first, 1.1.0 for the second unless it says otherwise), so only the
 * defaults of newer fields are added afterwards. A configuration that already
 * has the new node is left alone, and so is an older former node next to the
 * one that is moved: it is never deleted, since it may hold the only real
 * settings. Prints one line.
 */

/* Former names, newest first, with the model version each one wrote. */
const KAZUHAHUB_WIFIRADIUSPROXY_FORMER = [
    'kazuhahubwifiproxy' => ['package' => 'os-kazuhahub-wifi-proxy', 'version' => '1.1.0'],
    'kazuhahubradius' => ['package' => 'os-kazuhahub-radius', 'version' => '1.0.0'],
];

/**
 * Move the newest former node in a parsed config.xml.
 *
 * @param SimpleXMLElement $root the <opnsense> element
 * @param string|null $from set to the former node name that was moved or kept back
 * @return string moved, none (nothing to take over) or kept (the new node already exists)
 */
function kazuhahub_wifiradiusproxy_takeover(SimpleXMLElement $root, &$from = null)
{
    $from = null;
    foreach (KAZUHAHUB_WIFIRADIUSPROXY_FORMER as $name => $former) {
        if (isset($root->OPNsense->$name->general)) {
            $from = $name;
            break;
        }
    }
    if ($from === null) {
        return 'none';
    }
    if (isset($root->OPNsense->kazuhahubwifiradiusproxy->general)) {
        return 'kept';
    }
    $parent = dom_import_simplexml($root->OPNsense);
    $node = dom_import_simplexml($root->OPNsense->$from);
    $general = dom_import_simplexml($root->OPNsense->$from->general)->cloneNode(true);
    if ($from === 'kazuhahubradius' || !$general->hasAttribute('version')) {
        $general->setAttribute('version', KAZUHAHUB_WIFIRADIUSPROXY_FORMER[$from]['version']);
    }
    $renamed = $parent->ownerDocument->createElement('kazuhahubwifiradiusproxy');
    $renamed->appendChild($general);
    $parent->replaceChild($renamed, $node);
    return 'moved';
}

if (PHP_SAPI === 'cli' && !defined('KAZUHAHUB_WIFIRADIUSPROXY_NO_MAIN')) {
    require_once('config.inc');
    $config = \OPNsense\Core\Config::getInstance();
    $result = kazuhahub_wifiradiusproxy_takeover($config->object(), $from);
    if ($result === 'moved') {
        $config->save();
        echo 'Took the ' . KAZUHAHUB_WIFIRADIUSPROXY_FORMER[$from]['package'] . " settings over.\n";
        if (($argv[1] ?? '') === 'apply') {
            /* The newer fields, such as the 1.2.0 list of certificate sources, as the install does. */
            exec('/usr/local/opnsense/mvc/script/run_migrations.php OPNsense/KazuhaHubWifiRadiusProxy >/dev/null 2>&1');
            exec('/usr/local/sbin/configctl template reload OPNsense/KazuhaHubWifiRadiusProxy >/dev/null 2>&1');
            exec('/usr/local/sbin/configctl kazuhahubwifiradiusproxy restart >/dev/null 2>&1');
        }
    } elseif ($result === 'kept') {
        echo "The settings already exist under the new name; the former ones were left alone.\n";
    } else {
        echo "No former settings.\n";
    }
}
