#!/usr/local/bin/php
<?php

/*
 * Take the settings of the former name over, once, before the model first loads.
 *
 *   takeover.php         move the settings; the install hook runs this
 *   takeover.php apply   also render the configuration and start the service when
 *                        something was moved; the hourly cron job runs this, so a
 *                        config.xml restored from before the upgrade is picked up
 *
 * Until 1.0.0 this plugin was os-kazuhahub-radius and kept its settings under
 * //OPNsense/kazuhahubradius/general. The whole node is moved, byte for byte,
 * to //OPNsense/kazuhahubwifiproxy/general: the general settings, the secrets
 * and each central server with its uuid. Nothing is validated on the way, so a
 * setting the model would refuse today is kept for the operator to fix instead
 * of being lost. It is marked as model version 1.0.0, the version the former
 * name wrote, so only the defaults of newer fields are added afterwards. A
 * configuration that already has the new node is left alone. Prints one line.
 */

/**
 * Move the former node in a parsed config.xml.
 *
 * @param SimpleXMLElement $root the <opnsense> element
 * @return string moved, none (nothing to take over) or kept (the new node already exists)
 */
function kazuhahub_wifiproxy_takeover(SimpleXMLElement $root)
{
    if (!isset($root->OPNsense->kazuhahubradius->general)) {
        return 'none';
    }
    if (isset($root->OPNsense->kazuhahubwifiproxy->general)) {
        return 'kept';
    }
    $parent = dom_import_simplexml($root->OPNsense);
    $former = dom_import_simplexml($root->OPNsense->kazuhahubradius);
    $general = dom_import_simplexml($root->OPNsense->kazuhahubradius->general)->cloneNode(true);
    $general->setAttribute('version', '1.0.0');
    $node = $parent->ownerDocument->createElement('kazuhahubwifiproxy');
    $node->appendChild($general);
    $parent->replaceChild($node, $former);
    return 'moved';
}

if (PHP_SAPI === 'cli' && !defined('KAZUHAHUB_WIFIPROXY_NO_MAIN')) {
    require_once('config.inc');
    $config = \OPNsense\Core\Config::getInstance();
    $result = kazuhahub_wifiproxy_takeover($config->object());
    if ($result === 'moved') {
        $config->save();
        echo "Took the os-kazuhahub-radius settings over.\n";
        if (($argv[1] ?? '') === 'apply') {
            exec('/usr/local/sbin/configctl template reload OPNsense/KazuhaHubWifiProxy >/dev/null 2>&1');
            exec('/usr/local/sbin/configctl kazuhahubwifiproxy restart >/dev/null 2>&1');
        }
    } elseif ($result === 'kept') {
        echo "The settings already exist under the new name; the former ones were left alone.\n";
    } else {
        echo "No former settings.\n";
    }
}
