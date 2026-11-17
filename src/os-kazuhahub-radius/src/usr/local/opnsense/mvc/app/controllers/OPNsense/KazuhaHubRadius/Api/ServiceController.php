<?php

namespace OPNsense\KazuhaHubRadius\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass = '\OPNsense\KazuhaHubRadius\General';
    protected static $internalServiceEnabled = 'enabled';
    protected static $internalServiceTemplate = 'OPNsense/KazuhaHubRadius';
    protected static $internalServiceName = 'kazuhahubradius';

    private function mutation()
    {
        if ($this->request->getMethod() !== 'POST') {
            return false;
        }
        $this->throwReadOnly();
        return true;
    }

    private function backendJson($command)
    {
        $decoded = json_decode(trim((new Backend())->configdRun($command)), true);
        return is_array($decoded) ? $decoded : null;
    }

    public function startAction()
    {
        return $this->mutation() ? parent::startAction() : ['response' => []];
    }

    public function stopAction()
    {
        return $this->mutation() ? parent::stopAction() : ['response' => []];
    }

    public function restartAction()
    {
        return $this->mutation() ? parent::restartAction() : ['response' => []];
    }

    public function reconfigureAction()
    {
        return $this->mutation() ? parent::reconfigureAction() : ['status' => 'failed'];
    }

    /**
     * The service state the base class reports, plus what the page and the widget show next to it:
     * the addresses it listens on, a conflict that keeps it from starting, and the last configuration check.
     */
    public function statusAction()
    {
        $status = parent::statusAction();
        $class = static::$internalServiceClass;
        $targets = (new $class())->listenTargetsForSelection();
        $listen = [];
        foreach ($targets['addresses'] as $interface => $address) {
            $listen[] = ['interface' => $interface, 'address' => $address];
        }
        $status['listen'] = $listen;
        $status['refused'] = $targets['refused'];
        $last = $this->backendJson('kazuhahubradius lastcheck');
        $status['conflicts'] = is_array($last['conflicts'] ?? null) ? array_values($last['conflicts']) : [];
        $status['check'] = [
            'result' => is_string($last['result'] ?? null) ? $last['result'] : 'unknown',
            'message' => is_string($last['message'] ?? null) ? $last['message'] : '',
            'checked' => is_numeric($last['checked'] ?? null) ? (int)$last['checked'] : 0,
        ];
        return $status;
    }

    /* Export the certificates and check the generated configuration the way a start would. */
    public function checkAction()
    {
        if (!$this->mutation()) {
            return ['result' => 'failed', 'message' => gettext('POST required')];
        }
        $result = $this->backendJson('kazuhahubradius check');
        if ($result === null || !isset($result['result'])) {
            return ['result' => 'failed', 'message' => gettext('The configuration check is unavailable.'), 'conflicts' => []];
        }
        return $result;
    }

    /* The state radiusd itself keeps for each central server. */
    public function homeserversAction()
    {
        $result = $this->backendJson('kazuhahubradius homeservers');
        if ($result === null || !isset($result['status'])) {
            return ['status' => 'failed', 'servers' => []];
        }
        return $result;
    }
}
