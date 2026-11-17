<?php
/* Execute the real API controllers against recording stand-ins for the framework. */
namespace OPNsense\Base {
    class ApiControllerBase
    {
        public $request;
        public bool $readOnly = false;
        public int $permissionChecks = 0;

        protected function throwReadOnly()
        {
            $this->permissionChecks++;
            if ($this->readOnly) {
                throw new \RuntimeException('Read-only account.');
            }
        }
    }

    /* The inherited model actions, reduced to a record of what the controller asked for. */
    class ApiMutableModelControllerBase extends ApiControllerBase
    {
        public static array $asked = [];

        public function searchBase($path, $fields = null, $defaultSort = null)
        {
            self::$asked[] = ['search', $path, $fields, $defaultSort];
            return ['rows' => []];
        }

        public function getBase($key, $path, $uuid = null)
        {
            self::$asked[] = ['get', $key, $path, $uuid];
            return [];
        }

        public function addBase($key, $path)
        {
            self::$asked[] = ['add', $key, $path];
            return ['result' => 'saved'];
        }

        public function setBase($key, $path, $uuid)
        {
            self::$asked[] = ['set', $key, $path, $uuid];
            return ['result' => 'saved'];
        }

        public function delBase($path, $uuid)
        {
            self::$asked[] = ['del', $path, $uuid];
            return ['result' => 'deleted'];
        }

        public function toggleBase($path, $uuid, $enabled = null)
        {
            self::$asked[] = ['toggle', $path, $uuid, $enabled];
            return ['result' => 'Enabled'];
        }
    }

    /* The inherited service actions, reduced to what they send to configd. */
    class ApiMutableServiceControllerBase extends ApiControllerBase
    {
        public static array $parents = [];
        public static string $state = 'running';

        private function run(string $verb): array
        {
            self::$parents[] = $verb;
            if ($this->request->isPost()) {
                (new \OPNsense\Core\Backend())->configdRun(static::$internalServiceName . ' ' . $verb);
            }
            return ['response' => 'OK'];
        }

        public function startAction() { return $this->run('start'); }
        public function stopAction() { return $this->run('stop'); }
        public function restartAction() { return $this->run('restart'); }
        public function reconfigureAction() { $this->run('reconfigure'); return ['status' => 'ok']; }

        public function statusAction()
        {
            return ['status' => self::$state, 'widget' => ['caption_start' => 'Start']];
        }
    }
}

namespace OPNsense\Core {
    class Backend
    {
        public static array $calls = [];
        public static array $replies = [];

        public static array $detached = [];

        public function configdRun($command, $detach = false)
        {
            self::$calls[] = $command;
            self::$detached[] = $detach;
            return self::$replies[$command] ?? '';
        }
    }
}

namespace OPNsense\KazuhaHubWifiRadiusProxy {
    /* The model, reduced to the one method the controller calls. */
    class General
    {
        public static array $targets = ['addresses' => ['lan' => '192.0.2.1'], 'refused' => ['wan' => 'wan']];

        public function listenTargetsForSelection()
        {
            return self::$targets;
        }
    }
}

namespace {
    use OPNsense\Base\ApiMutableModelControllerBase;
    use OPNsense\Base\ApiMutableServiceControllerBase;
    use OPNsense\Core\Backend;

    if (!function_exists('gettext')) {
        function gettext($text)
        {
            return $text;
        }
    }

    class TestRequest
    {
        public string $method = 'POST';

        public function getMethod()
        {
            return $this->method;
        }

        public function isPost()
        {
            return $this->method === 'POST';
        }
    }

    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    function controller(string $method = 'POST', bool $readOnly = false)
    {
        $controller = new OPNsense\KazuhaHubWifiRadiusProxy\Api\ServiceController();
        $controller->request = new TestRequest();
        $controller->request->method = $method;
        $controller->readOnly = $readOnly;
        Backend::$calls = [];
        Backend::$replies = [];
        ApiMutableServiceControllerBase::$parents = [];
        ApiMutableServiceControllerBase::$state = 'running';
        return $controller;
    }

    $api = dirname(__DIR__, 2) . '/src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubWifiRadiusProxy/Api';
    require $api . '/ServiceController.php';
    require $api . '/SettingsController.php';

    /* The bindings the framework reads. */
    $statics = [];
    foreach (['internalServiceClass', 'internalServiceEnabled', 'internalServiceTemplate', 'internalServiceName'] as $name) {
        $statics[$name] = (new ReflectionProperty(OPNsense\KazuhaHubWifiRadiusProxy\Api\ServiceController::class, $name))->getValue();
    }
    check($statics === ['internalServiceClass' => '\OPNsense\KazuhaHubWifiRadiusProxy\General', 'internalServiceEnabled' => 'enabled',
        'internalServiceTemplate' => 'OPNsense/KazuhaHubWifiRadiusProxy', 'internalServiceName' => 'kazuhahubwifiradiusproxy'], 'Service bindings changed.');
    check((new ReflectionProperty(OPNsense\KazuhaHubWifiRadiusProxy\Api\SettingsController::class, 'internalModelName'))->getValue() === 'general'
        && (new ReflectionProperty(OPNsense\KazuhaHubWifiRadiusProxy\Api\SettingsController::class, 'internalModelClass'))->getValue()
            === '\OPNsense\KazuhaHubWifiRadiusProxy\General', 'Settings bindings changed.');

    /* Service mutations: POST only, and read-only accounts are refused before configd. */
    foreach (['start', 'stop', 'restart', 'reconfigure'] as $action) {
        $method = $action . 'Action';
        $c = controller('GET');
        $c->$method();
        check(Backend::$calls === [] && ApiMutableServiceControllerBase::$parents === [] && $c->permissionChecks === 0,
            "GET reached the backend through $action.");
        $c = controller('POST', true);
        try {
            $c->$method();
            check(false, "A read-only account ran $action.");
        } catch (RuntimeException $error) {
            check($error->getMessage() === 'Read-only account.', $error->getMessage());
        }
        check(Backend::$calls === [], "A read-only account reached configd through $action.");
        $c = controller();
        $c->$method();
        check(ApiMutableServiceControllerBase::$parents === [$action] && $c->permissionChecks === 1, "$action did not run once.");
    }

    /* Status: the base answer plus what the page and the widget show. */
    $c = controller('GET');
    Backend::$replies['kazuhahubwifiradiusproxy lastcheck'] = json_encode([
        'result' => 'failed', 'message' => "Error: nope\nsecond line", 'checked' => 1790000000,
        'conflicts' => ['os-freeradius is installed.', 'The stock radiusd service is enabled.']]);
    $status = $c->statusAction();
    check($status['status'] === 'running' && $status['widget'] === ['caption_start' => 'Start'], 'The base status was lost.');
    check($status['listen'] === [['interface' => 'lan', 'address' => '192.0.2.1']], 'The listen addresses are wrong.');
    check($status['refused'] === ['wan' => 'wan'], 'The refused interfaces are wrong.');
    check($status['conflicts'] === ['os-freeradius is installed.', 'The stock radiusd service is enabled.'], 'The conflicts are wrong.');
    check($status['check'] === ['result' => 'failed', 'message' => "Error: nope\nsecond line", 'checked' => 1790000000], 'The check is wrong.');
    check(Backend::$calls === ['kazuhahubwifiradiusproxy lastcheck', 'kazuhahubwifiradiusproxy certsyncstate'],
        'The status asked for more than the last check and the sync state.');
    check($status['certsync'] === ['result' => 'unknown'], 'A silent sync state was not unknown.');
    $c = controller('GET');
    Backend::$replies['kazuhahubwifiradiusproxy certsyncstate'] = '{"enabled":true,"result":"unchanged","in_use":"central set","not_after":5}';
    check($c->statusAction()['certsync'] === ['enabled' => true, 'result' => 'unchanged', 'in_use' => 'central set', 'not_after' => 5],
        'The sync state was lost.');
    $c = controller('GET');
    ApiMutableServiceControllerBase::$state = 'disabled';
    check($c->statusAction()['status'] === 'disabled', 'A disabled state was lost.');
    /* A silent or broken backend still gives a complete answer. */
    foreach (['', 'garbage', '[]', '{"result":5,"message":[],"checked":"x","conflicts":"no"}'] as $reply) {
        $c = controller('GET');
        Backend::$replies['kazuhahubwifiradiusproxy lastcheck'] = $reply;
        $status = $c->statusAction();
        check($status['check'] === ['result' => 'unknown', 'message' => '', 'checked' => 0] && $status['conflicts'] === [],
            'A bad reply broke the status: ' . $reply);
    }
    OPNsense\KazuhaHubWifiRadiusProxy\General::$targets = ['addresses' => [], 'refused' => []];
    $status = controller('GET')->statusAction();
    check($status['listen'] === [] && $status['refused'] === [], 'An empty selection has listeners.');

    /* The check exports certificates and runs a program: POST only, and refused for read-only accounts. */
    $c = controller('GET');
    $answer = $c->checkAction();
    check($answer['result'] === 'failed' && Backend::$calls === [], 'GET ran the check.');
    $c = controller('POST', true);
    try {
        $c->checkAction();
        check(false, 'A read-only account ran the check.');
    } catch (RuntimeException $error) {
        check(Backend::$calls === [], 'A read-only account reached configd through the check.');
    }
    $c = controller();
    Backend::$replies['kazuhahubwifiradiusproxy check'] = '{"result":"ok","message":"Configuration appears to be OK","checked":5,"conflicts":[]}';
    check($c->checkAction()['result'] === 'ok' && Backend::$calls === ['kazuhahubwifiradiusproxy check'], 'The check was not run once.');
    $c = controller();
    Backend::$replies['kazuhahubwifiradiusproxy check'] = 'Execute error';
    $answer = $c->checkAction();
    check($answer['result'] === 'failed' && $answer['conflicts'] === [], 'A failing backend gave no failure.');

    /* The certificate sync fetches and may restart the service: POST only, and refused for read-only accounts. */
    $c = controller('GET');
    check($c->certsyncAction()['result'] === 'failed' && Backend::$calls === [], 'GET ran the certificate sync.');
    $c = controller('POST', true);
    try {
        $c->certsyncAction();
        check(false, 'A read-only account ran the certificate sync.');
    } catch (RuntimeException $error) {
        check(Backend::$calls === [], 'A read-only account reached configd through the certificate sync.');
    }
    $c = controller();
    Backend::$detached = [];
    check($c->certsyncAction() === ['result' => 'started'] && Backend::$calls === ['kazuhahubwifiradiusproxy certsyncnow']
        && Backend::$detached === [true], 'The sync was not started once, detached.');

    /* The central server state. */
    $c = controller('GET');
    Backend::$replies['kazuhahubwifiradiusproxy homeservers'] = '{"status":"ok","servers":[{"address":"10.0.0.1","port":1812,"state":"alive","outstanding":0}]}';
    check($c->homeserversAction()['servers'][0]['state'] === 'alive', 'The central server state was lost.');
    Backend::$replies['kazuhahubwifiradiusproxy homeservers'] = 'Execute error';
    check(controller('GET')->homeserversAction() === ['status' => 'failed', 'servers' => []], 'A failing backend gave servers.');

    /* The grid actions go to the central server list and nowhere else. */
    $settings = new OPNsense\KazuhaHubWifiRadiusProxy\Api\SettingsController();
    ApiMutableModelControllerBase::$asked = [];
    $settings->searchCentralAction();
    $settings->getCentralAction('u1');
    $settings->addCentralAction();
    $settings->setCentralAction('u2');
    $settings->delCentralAction('u3');
    $settings->toggleCentralAction('u4', '0');
    check(ApiMutableModelControllerBase::$asked === [
        ['search', 'centrals.central', ['enabled', 'description', 'address', 'port', 'sequence'], 'sequence'],
        ['get', 'central', 'centrals.central', 'u1'],
        ['add', 'central', 'centrals.central'],
        ['set', 'central', 'centrals.central', 'u2'],
        ['del', 'centrals.central', 'u3'],
        ['toggle', 'centrals.central', 'u4', '0'],
    ], 'The grid actions changed: ' . json_encode(ApiMutableModelControllerBase::$asked));
    /* The search never lists the secret. */
    check(!in_array('secret', ApiMutableModelControllerBase::$asked[0][2], true), 'The search lists the secret.');
    echo "Kazuha Hub Wi-Fi RADIUS controller checks passed: POST-only mutations, read-only refusal, status merge, certificate sync and grid bindings.\n";
}
