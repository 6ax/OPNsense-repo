<?php
/*
 * Drive the real OPNsense core for the lifecycle check: put a throwaway
 * certificate authority and server certificate into the trust store, change the
 * plugin's settings through its model, try the interfaces the model must
 * refuse, and read the state back through the service controller.
 *
 *   php seed.php trust <pki-dir>
 *   php seed.php settings <secrets-dir> <enabled|disabled>
 *   php seed.php refusals
 *   php seed.php status
 *   php seed.php leak <secrets-dir>
 *
 * Every mode prints one JSON object. Secrets are read from files and never
 * printed; "leak" only reports whether any of them shows up in what the model
 * and the controller hand out.
 */
$loader = '/usr/local/opnsense/mvc/app/config/loader.php';
if (!is_file($loader)) {
    fwrite(STDERR, "Requires the native OPNsense Core.\n");
    exit(77);
}
require_once($loader);
require_once('/usr/local/etc/inc/util.inc');
require_once('/usr/local/etc/inc/config.inc');

use OPNsense\Core\Config;
use OPNsense\KazuhaHubRadius\General;

function seed_answer(array $answer, int $status = 0)
{
    echo json_encode($answer) . "\n";
    exit($status);
}

function seed_secret($directory, $name)
{
    return trim((string)file_get_contents($directory . '/' . $name));
}

function seed_messages($messages)
{
    $result = [];
    foreach ($messages as $message) {
        $result[] = $message->getField();
    }
    return array_values(array_unique($result));
}

$mode = $argv[1] ?? '';
switch ($mode) {
    case 'trust':
        $directory = $argv[2];
        $xml = Config::getInstance()->object();
        foreach (['ca', 'cert'] as $type) {
            foreach ($xml->$type as $existing) {
                if ((string)$existing->refid === 'lifecycle-' . $type) {
                    seed_answer(['result' => 'exists'], 1);
                }
            }
        }
        $ca = $xml->addChild('ca');
        $ca->addChild('refid', 'lifecycle-ca');
        $ca->addChild('descr', 'Lifecycle test authority');
        $ca->addChild('crt', base64_encode(file_get_contents($directory . '/ca.pem')));
        $cert = $xml->addChild('cert');
        $cert->addChild('refid', 'lifecycle-cert');
        $cert->addChild('descr', 'Lifecycle test server');
        $cert->addChild('crt', base64_encode(file_get_contents($directory . '/server.crt')));
        $cert->addChild('prv', base64_encode(file_get_contents($directory . '/server.key')));
        Config::getInstance()->save(null, false);
        seed_answer(['result' => 'ok']);
        break;

    case 'settings':
        $directory = $argv[2];
        $enabled = ($argv[3] ?? '') === 'enabled' ? '1' : '0';
        $model = new General();
        $model->setNodes([
            'enabled' => $enabled,
            'interfaces' => 'lan',
            'servercert' => 'lifecycle-cert',
            'ca' => 'lifecycle-ca',
            'client_networks' => '10.0.0.0/24',
            'client_secret' => seed_secret($directory, 'ap'),
        ]);
        $addresses = ['127.0.0.2', '127.0.0.3'];
        $secrets = ['c1', 'c2'];
        $index = 0;
        foreach ($model->centrals->central->iterateItems() as $central) {
            if ($index < 2) {
                $central->setNodes([
                    'enabled' => '1', 'address' => $addresses[$index], 'port' => '11812',
                    'secret' => seed_secret($directory, $secrets[$index]), 'sequence' => (string)(($index + 1) * 10),
                ]);
            }
            $index++;
        }
        while ($index < 2) {
            $central = $model->centrals->central->Add();
            $central->setNodes([
                'enabled' => '1', 'address' => $addresses[$index], 'port' => '11812',
                'secret' => seed_secret($directory, $secrets[$index]), 'sequence' => (string)(($index + 1) * 10),
            ]);
            $index++;
        }
        $problems = seed_messages($model->performValidation(true));
        if ($problems) {
            seed_answer(['result' => 'invalid', 'fields' => $problems], 1);
        }
        $model->serializeToConfig(true);
        Config::getInstance()->save(null, false);
        seed_answer(['result' => 'saved', 'enabled' => $enabled, 'centrals' => $index]);
        break;

    case 'refusals':
        $answer = [];
        foreach (['wan', 'lan', 'lan,wan', 'opt99'] as $value) {
            $model = new General();
            $model->interfaces = $value;
            $answer[$value] = seed_messages($model->performValidation(true)) === [] ? 'accepted' : 'refused';
        }
        $model = new General();
        $model->enabled = '1';
        $model->interfaces = 'wan';
        $answer['enabled-wan'] = in_array('interfaces', seed_messages($model->performValidation(false)), true)
            ? 'refused' : 'accepted';
        seed_answer($answer);
        break;

    case 'status':
        require_once('/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/Api/ServiceController.php');
        $controller = new OPNsense\KazuhaHubRadius\Api\ServiceController();
        $status = $controller->statusAction();
        $servers = $controller->homeserversAction();
        seed_answer(['status' => $status, 'homeservers' => $servers]);
        break;

    case 'leak':
        $directory = $argv[2];
        $model = new General();
        $dumps = [json_encode($model->getNodes())];
        $found = 0;
        foreach (['ap', 'c1', 'c2'] as $name) {
            $secret = seed_secret($directory, $name);
            foreach ($dumps as $dump) {
                if ($secret !== '' && strpos($dump, $secret) !== false) {
                    $found++;
                }
            }
        }
        seed_answer(['leaked' => $found]);
        break;

    default:
        seed_answer(['result' => 'usage'], 2);
}
