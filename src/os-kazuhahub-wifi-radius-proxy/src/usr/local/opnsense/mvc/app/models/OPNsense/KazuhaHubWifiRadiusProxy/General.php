<?php

namespace OPNsense\KazuhaHubWifiRadiusProxy;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;

class General extends BaseModel
{
    /* The same characters the templates accept; none of them is special to the RADIUS daemon's configuration. */
    const MIN_PREFIX = 8;

    /* Certificate sources a site may list, tried in this order. */
    const MAX_SYNC_SERVERS = 8;

    /**
     * The certificate sources as base URLs, in order: each entry is http://address:port with an
     * IPv4 address, an IPv6 address in brackets or a host name, the same addresses the sync accepts.
     *
     * @return array|string the list, or a message naming the first entry that is not valid
     */
    public static function syncServers($text)
    {
        $servers = [];
        foreach (explode(',', (string)$text) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if (!preg_match('#^http://(\[([0-9A-Fa-f:.]+)\]|[A-Za-z0-9.-]+):([0-9]{1,5})/?$#D', $entry, $match)) {
                return sprintf(gettext('%s is not of the form http://address:port.'), $entry);
            }
            $host = $match[1];
            $valid = isset($match[2]) && $match[2] !== ''
                ? filter_var($match[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                : filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                    || (!preg_match('/^[0-9.]+$/D', $host)
                        && preg_match('/^(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/D', $host));
            $port = (int)$match[3];
            if (!$valid || $port < 1 || $port > 65535 || $match[3][0] === '0') {
                return sprintf(gettext('%s is not a valid certificate source address and port.'), $entry);
            }
            $base = 'http://' . strtolower($host) . ':' . $port . '/';
            if (in_array($base, $servers, true)) {
                return sprintf(gettext('%s is listed twice.'), $entry);
            }
            $servers[] = $base;
        }
        if (count($servers) > self::MAX_SYNC_SERVERS) {
            return sprintf(gettext('List at most %d certificate sources.'), self::MAX_SYNC_SERVERS);
        }
        return $servers;
    }

    /** The message for a list of certificate sources that cannot be used, or null. */
    public static function syncServerProblem($text)
    {
        $servers = self::syncServers($text);
        return is_string($servers) ? $servers : null;
    }

    const SECRET_PATTERN = '/^[A-Za-z0-9!#&()*+,.\/:;<=>?@^_{}|~\[\]-]{8,128}$/D';

    /**
     * The interfaces any gateway of System > Gateways points at. 26.7 keeps them in the Gateways model
     * (//OPNsense/Gateways/gateway_item); older configurations used //gateways/gateway_item. Disabled
     * gateways count too: a gateway on an interface marks it as an uplink.
     *
     * @return array interface name => true
     */
    public static function gatewayInterfaces()
    {
        $root = Config::getInstance()->object();
        $names = [];
        foreach ([$root->OPNsense->Gateways->gateway_item, $root->gateways->gateway_item] as $items) {
            foreach ($items as $item) {
                $name = trim((string)$item->interface);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        return $names;
    }

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
            'gateway_ref' => isset(self::gatewayInterfaces()[$name]),
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
     * is the interface of any gateway defined in System > Gateways, or carries the
     * private-network and bogon blocks core sets on WAN interfaces.
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
        if (!empty($facts['gateway_ref'])) {
            return 'gateway-ref';
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
            'gateway-ref' => gettext('%s is the interface of a gateway in System > Gateways, so it is an uplink.'),
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

        /* A central server that is switched on must be usable, whatever the service does: the messages sit on
           the row's own fields, so the grid's add, set and toggle actions see them. */
        $usable = 0;
        foreach ($this->centrals->central->iterateItems() as $central) {
            if ((string)$central->enabled !== '1') {
                continue;
            }
            if (!self::secretValid($central->secret->getValue())) {
                $messages->appendMessage(new Message(
                    gettext('An enabled central server needs a shared secret of 8 to 128 allowed characters.'),
                    $central->secret->__reference
                ));
            } elseif (trim((string)$central->address) !== '') {
                $usable++;
            }
        }
        /* The same prefix bound the template applies, so a saved network is never silently dropped. */
        foreach (self::split($this->client_networks->getValue()) as $entry) {
            $parts = explode('/', $entry);
            if (count($parts) === 2 && ctype_digit($parts[1]) && (int)$parts[1] < self::MIN_PREFIX) {
                $messages->appendMessage(new Message(
                    sprintf(gettext('%s is too wide; use a prefix of /%d or longer.'), $entry, self::MIN_PREFIX),
                    'client_networks'
                ));
            }
        }

        /* Every certificate source must be one the sync can reach: http://address:port, at most
           MAX_SYNC_SERVERS, none twice. Without one the sync pulls nothing. */
        $problem = self::syncServerProblem((string)$this->cert_sync_servers);
        if ($problem !== null) {
            $messages->appendMessage(new Message($problem, 'cert_sync_servers'));
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
        if ($usable === 0) {
            $messages->appendMessage(new Message(
                gettext('Enable at least one central server that has an address and a shared secret.'),
                'enabled'
            ));
        }
        return $messages;
    }
}
