<?php

namespace OPNsense\KazuhaHubWifiRadiusProxy\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;

/**
 * 1.2.0 lists the certificate sources, in fail-over order, in one field. A 1.1.0
 * configuration kept one source as a host and a port; it becomes the first and
 * only entry of the list. The two former fields are no longer in the model, so
 * they are read from config.xml as it was loaded and dropped when the model is
 * saved.
 */
class M1_2_0 extends BaseModelMigration
{
    /** http://host:port for the 1.1.0 host and port, or '' when there was no source. */
    public static function fromHostPort($host, $port)
    {
        $host = trim((string)$host);
        $port = trim((string)$port);
        if ($host === '' || !ctype_digit($port)) {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        return 'http://' . $host . ':' . (int)$port;
    }

    public function run($model)
    {
        if (trim((string)$model->cert_sync_servers) !== '') {
            return;
        }
        $general = Config::getInstance()->object()->OPNsense->kazuhahubwifiradiusproxy->general ?? null;
        if ($general === null) {
            return;
        }
        $servers = self::fromHostPort($general->cert_sync_host ?? '', $general->cert_sync_port ?? '');
        if ($servers !== '') {
            $model->cert_sync_servers = $servers;
        }
    }
}
