<?php

namespace OPNsense\KazuhaHubWifiProxy\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;

/**
 * First start of the model: offer the LAN as the network of the access points.
 * No central server is created; the operator adds them. Settings of the former
 * name, os-kazuhahub-radius, are moved over by takeover.php before this runs and
 * carry model version 1.0.0, so this migration does not run for them.
 */
class M1_0_0 extends BaseModelMigration
{
    /** The network a static LAN address belongs to, such as 192.0.2.0/24, or null. */
    public static function networkOf($address, $prefix)
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || !ctype_digit((string)$prefix)) {
            return null;
        }
        $prefix = (int)$prefix;
        if ($prefix < 8 || $prefix > 30) {
            return null;
        }
        $mask = (0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF;
        return long2ip(ip2long($address) & $mask) . '/' . $prefix;
    }

    public function run($model)
    {
        if ((string)$model->client_networks === '') {
            $lan = Config::getInstance()->object()->interfaces->lan ?? null;
            if ($lan !== null && !isset($lan->gateway)) {
                $network = self::networkOf((string)$lan->ipaddr, (string)$lan->subnet);
                if ($network !== null) {
                    $model->client_networks = $network;
                }
            }
        }
    }
}
