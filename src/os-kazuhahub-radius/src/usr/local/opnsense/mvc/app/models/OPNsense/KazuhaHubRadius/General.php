<?php

namespace OPNsense\KazuhaHubRadius;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;

class General extends BaseModel
{
    /* The same characters the templates accept; none of them is special to the RADIUS daemon's configuration. */
    const SECRET_PATTERN = '/^[A-Za-z0-9!#&()*+,.\/:;<=>?@^_{}|~\[\]-]{8,128}$/D';

    /**
     * Reduce an interface node of config.xml to what decides whether the RADIUS
     * listener may live on it.
     *
     * @return array|null null when the interface does not exist
     */
    public static function interfaceFacts($name)
    {
        if (!is_string($name) || !preg_match('/^[a-z0-9_]{1,32}$/D', $name)) {
            return null;
        }
        $interfaces = Config::getInstance()->object()->interfaces;
        if (!isset($interfaces->$name)) {
            return null;
        }
        $node = $interfaces->$name;
        return [
            'enable' => isset($node->enable) && (string)$node->enable !== '0',
            'ipaddr' => (string)$node->ipaddr,
            'gateway' => trim((string)$node->gateway),
            /* core writes <blockpriv>0</blockpriv> on interfaces that do not block, so a flag counts when it is not "0" */
            'blockpriv' => isset($node->blockpriv) && (string)$node->blockpriv !== '0',
            'blockbogons' => isset($node->blockbogons) && (string)$node->blockbogons !== '0',
        ];
    }

    /**
     * Why the listener may not use an interface, or null when it may.
     *
     * The service answers the Wi-Fi access points on the local networks only. An
     * interface is refused when it is the WAN, has an upstream gateway, takes its
     * address from DHCP, PPPoE or another dynamic type, has no IPv4 address, or
     * carries the private-network and bogon blocks core sets on WAN interfaces.
     *
     * @param string $name interface key, such as lan or opt1
     * @param array|null $facts the result of interfaceFacts()
     * @return string|null a short reason code
     */
    public static function listenRefusal($name, $facts)
    {
        if (!is_string($name) || !preg_match('/^[a-z0-9_]{1,32}$/D', $name)) {
            return 'invalid';
        }
        if ($name === 'wan') {
            return 'wan';
        }
        if (!is_array($facts)) {
            return 'missing';
        }
        if (empty($facts['enable'])) {
            return 'disabled';
        }
        $address = (string)($facts['ipaddr'] ?? '');
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return 'not-static';
        }
        $first = (int)explode('.', $address)[0];
        if ($first === 0 || $first === 127 || $first >= 224) {
            return 'not-unicast';
        }
        if (trim((string)($facts['gateway'] ?? '')) !== '') {
            return 'gateway';
        }
        if (!empty($facts['blockpriv']) || !empty($facts['blockbogons'])) {
            return 'wan-like';
        }
        return null;
    }

    public static function refusalMessage($name, $reason)
    {
        $messages = [
            'invalid' => gettext('%s is not a valid interface name.'),
            'wan' => gettext('%s is the WAN; the RADIUS service must never listen there.'),
            'missing' => gettext('%s does not exist.'),
            'disabled' => gettext('%s is not enabled.'),
            'not-static' => gettext('%s has no static IPv4 address.'),
            'not-unicast' => gettext('%s has no usable IPv4 address.'),
            'gateway' => gettext('%s has an upstream gateway, so it looks like a WAN.'),
            'wan-like' => gettext('%s blocks private or bogon networks, so it looks like a WAN.'),
        ];
        return sprintf($messages[$reason] ?? gettext('%s cannot be used.'), $name);
    }

    /**
     * Split a list of interface names into the addresses to listen on and the refused ones.
     *
     * @param string[] $names interface keys
     * @param callable $lookup name => facts as interfaceFacts() returns them
     * @return array ['addresses' => [name => IPv4], 'refused' => [name => reason]]
     */
    public static function listenTargets(array $names, callable $lookup)
    {
        $result = ['addresses' => [], 'refused' => []];
        foreach ($names as $name) {
            if (isset($result['addresses'][$name]) || isset($result['refused'][$name])) {
                continue;
            }
            $facts = $lookup($name);
            $reason = self::listenRefusal($name, $facts);
            if ($reason === null) {
                $result['addresses'][$name] = $facts['ipaddr'];
            } else {
                $result['refused'][$name] = $reason;
            }
        }
        return $result;
    }

    public static function secretValid($value)
    {
        return is_string($value) && preg_match(self::SECRET_PATTERN, $value) === 1;
    }

    private static function split($value)
    {
        return array_values(array_filter(array_map('trim', explode(',', (string)$value)), 'strlen'));
    }

    /** The interfaces the operator selected, as listenTargets() sorts them. */
    public function listenTargetsForSelection()
    {
        return self::listenTargets(self::split($this->interfaces->getValue()), [self::class, 'interfaceFacts']);
    }

    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        /* Always, not only when the field changed: a configuration that was
           valid when saved must not keep a listener on an interface that has
           since become a WAN. */
        $targets = $this->listenTargetsForSelection();
        foreach ($targets['refused'] as $name => $reason) {
            $messages->appendMessage(new Message(self::refusalMessage($name, $reason), 'interfaces'));
        }

        if ((string)$this->enabled !== '1') {
            return $messages;
        }
        if (self::split($this->interfaces->getValue()) === []) {
            $messages->appendMessage(new Message(gettext('Select at least one interface to listen on.'), 'interfaces'));
        }
        if ($this->servercert->getValue() === '') {
            $messages->appendMessage(new Message(gettext('Select the server certificate.'), 'servercert'));
        }
        if ($this->ca->getValue() === '') {
            $messages->appendMessage(new Message(gettext('Select the certificate authority.'), 'ca'));
        }
        if (self::split($this->client_networks->getValue()) === []) {
            $messages->appendMessage(new Message(gettext('Add at least one network the access points live in.'), 'client_networks'));
        }
        if (!self::secretValid($this->client_secret->getValue())) {
            $messages->appendMessage(new Message(gettext('Set the shared secret of the access points.'), 'client_secret'));
        }
        $usable = 0;
        foreach ($this->centrals->central->iterateItems() as $central) {
            if ((string)$central->enabled !== '1') {
                continue;
            }
            $usable++;
            if (!self::secretValid($central->secret->getValue())) {
                $messages->appendMessage(new Message(sprintf(
                    gettext('Set the shared secret of the central server %s before enabling the service.'),
                    (string)$central->address
                ), 'enabled'));
            }
        }
        if ($usable === 0) {
            $messages->appendMessage(new Message(gettext('Enable at least one central server.'), 'enabled'));
        }
        return $messages;
    }
}
