<?php

namespace OPNsense\KazuhaHubWifiProxy\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/* get and set come from the base class; set validates the model and refuses
   read-only accounts before it saves. The secrets are update-only fields: the
   model never hands them out, so no action here can return one. */
class SettingsController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\KazuhaHubWifiProxy\General';
    protected static $internalModelName = 'general';

    public function searchCentralAction()
    {
        return $this->searchBase('centrals.central', ['enabled', 'description', 'address', 'port', 'sequence'], 'sequence');
    }

    public function getCentralAction($uuid = null)
    {
        return $this->getBase('central', 'centrals.central', $uuid);
    }

    public function addCentralAction()
    {
        return $this->addBase('central', 'centrals.central');
    }

    public function setCentralAction($uuid)
    {
        return $this->setBase('central', 'centrals.central', $uuid);
    }

    public function delCentralAction($uuid)
    {
        return $this->delBase('centrals.central', $uuid);
    }

    public function toggleCentralAction($uuid, $enabled = null)
    {
        return $this->toggleBase('centrals.central', $uuid, $enabled);
    }
}
