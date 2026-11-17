<?php

namespace OPNsense\KazuhaHubWifiRadiusProxy;

class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->generalForm = $this->getForm('general');
        $this->view->formDialogCentral = $this->getForm('dialogCentral');
        $this->view->pick('OPNsense/KazuhaHubWifiRadiusProxy/index');
    }
}
