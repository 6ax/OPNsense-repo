<?php

namespace OPNsense\KazuhaHubRadius\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;

/**
 * First start of the model: offer the two central servers and the LAN as the
 * network of the access points. The servers start disabled and without secrets, so
 * nothing can be enabled until the operator has typed them.
 */
class M1_0_0 extends BaseModelMigration
{
    /** The network a static LAN address belongs to, such as 192.168.0.0/22, or null. */
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
        $known = false;
        foreach ($model->centrals->central->iterateItems() as $existing) {
            $known = true;
            break;
        }
        if (!$known) {
            foreach ([['Production', '100.88.32.2', '10'], ['4c6g', '100.88.33.1', '20']] as $seed) {
                $node = $model->centrals->central->Add();
                /* Off until the operator has typed the shared secret: an enabled server without one is refused. */
                $node->enabled = '0';
                $node->description = $seed[0];
                $node->address = $seed[1];
                $node->port = '11812';
                $node->sequence = $seed[2];
            }
        }
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
