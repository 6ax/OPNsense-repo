<?php
/* Validate the real Kazuha Hub RADIUS model with OPNsense's own field types, against a private XML fixture. */
function model_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function model_fields($messages): array
{
    $fields = [];
    foreach ($messages as $message) {
        $fields[] = $message->getField();
    }
    sort($fields);
    return array_values(array_unique($fields));
}

/* The unique constraint keeps its bookkeeping in static properties that are only reset when a model
   instance has validated more often than the previous one did; a second instance in the same process
   would read the first one's bookkeeping. A request validates one model instance, so reset it by hand. */
function model_validate($model, bool $full)
{
    foreach (['itemmap' => [], 'validationSequence' => -1] as $name => $value) {
        (new ReflectionProperty(\OPNsense\Base\Constraints\UniqueConstraint::class, $name))->setValue($value);
    }
    return $model->performValidation($full);
}

$package = $argv[1] ?? dirname(__DIR__, 2);
$loader = '/usr/local/opnsense/mvc/app/config/loader.php';
if (!is_file($loader)) {
    fwrite(STDERR, "Requires the native OPNsense Core and field types.\n");
    exit(77);
}
require_once($loader);
require_once('/usr/local/etc/inc/util.inc');
require_once('/usr/local/etc/inc/config.inc');
require_once($package . '/src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/General.php');
/* Test secrets: generated here, they match no real system. */
$apSecret = 'ap-' . bin2hex(random_bytes(8));
$centralSecret = 'ce-' . bin2hex(random_bytes(8));
$otherSecret = 'ot-' . bin2hex(random_bytes(8));

$directory = sys_get_temp_dir() . '/kazuhahub-radius-native-model-' . bin2hex(random_bytes(8));
mkdir($directory, 0700);
$fixture = $directory . '/config.xml';
file_put_contents($fixture, '<?xml version="1.0"?><opnsense><system><hostname>private-fixture</hostname></system>' .
    '<interfaces>' .
    '<wan><if>vtnet1</if><enable>1</enable><ipaddr>203.0.113.9</ipaddr><subnet>28</subnet><gateway>WAN_GW</gateway>' .
    '<blockpriv>1</blockpriv><blockbogons>1</blockbogons><descr>WAN</descr></wan>' .
    '<lan><if>vtnet0</if><enable>1</enable><ipaddr>192.168.0.1</ipaddr><subnet>22</subnet><blockpriv>0</blockpriv><blockbogons>0</blockbogons><descr>LAN</descr></lan>' .
    /* static with an upstream gateway: a WAN under another name */
    '<opt1><if>vtnet2</if><enable>1</enable><ipaddr>198.51.100.2</ipaddr><subnet>24</subnet><gateway>OTHER_GW</gateway><descr>WAN2</descr></opt1>' .
    '<opt2><if>vtnet3</if><enable>1</enable><ipaddr>dhcp</ipaddr><descr>DHCP</descr></opt2>' .
    '<opt3><if>pppoe0</if><enable>1</enable><ipaddr>pppoe</ipaddr><descr>PPPOE</descr></opt3>' .
    '<opt4><if>vtnet4</if><ipaddr>10.9.0.1</ipaddr><subnet>24</subnet><descr>OFF</descr></opt4>' .
    '<opt5><if>vtnet5</if><enable>1</enable><descr>NOIP</descr></opt5>' .
    '<opt6><if>vtnet6</if><enable>1</enable><ipaddr>10.6.0.1</ipaddr><subnet>24</subnet><blockpriv>1</blockpriv><descr>BLOCKED</descr></opt6>' .
    '<opt8><if>vtnet8</if><enable>1</enable><ipaddr>10.8.0.1</ipaddr><subnet>24</subnet><descr>UPLINK</descr></opt8>' .
    '<opt7><if>vtnet7</if><enable>1</enable><ipaddr>10.7.0.1</ipaddr><subnet>24</subnet><blockpriv>0</blockpriv><blockbogons>0</blockbogons><descr>IOT</descr></opt7>' .
    '</interfaces>' .
    '<OPNsense><Gateways><gateway_item><disabled>0</disabled><name>UP_GW</name><interface>opt8</interface><ipprotocol>inet</ipprotocol><gateway>10.8.0.254</gateway></gateway_item></Gateways></OPNsense>' .
    '<gateways><gateway_item><name>OLD_GW</name><interface>opt9</interface></gateway_item></gateways>' .
    '<cert><refid>cert1</refid><descr>Server</descr><crt>AAAA</crt><prv>BBBB</prv></cert>' .
    '<ca><refid>ca1</refid><descr>Authority</descr><crt>CCCC</crt></ca>' .
    '<unknown attr="retain">SENTINEL_UNKNOWN</unknown></opnsense>');
$config = \OPNsense\Core\Config::getInstance();
$handle = new ReflectionProperty($config, 'config_file_handle');
fclose($handle->getValue($config));
$handle->setValue($config, fopen($fixture, 'r+'));
(new ReflectionProperty($config, 'config_file'))->setValue($config, $fixture);
(new ReflectionProperty($config, 'statusIsLocked'))->setValue($config, false);

/* A model without the seeded central servers; the migration offers them on every first load of this fixture. */
function model_fresh()
{
    $model = new \OPNsense\KazuhaHubRadius\General();
    foreach (array_keys(iterator_to_array($model->centrals->central->iterateItems())) as $uuid) {
        $model->centrals->central->del($uuid);
    }
    return $model;
}

function model_central($model, $address, $port, $secret, $enabled = '1', $sequence = '10')
{
    $node = $model->centrals->central->Add();
    $node->enabled = $enabled;
    $node->address = $address;
    $node->port = $port;
    $node->secret = $secret;
    $node->sequence = $sequence;
    return $node;
}

try {
    $config->lock();
    $config->lock(false);
    $model = new \OPNsense\KazuhaHubRadius\General();
    /* A fresh install does nothing: disabled, the LAN preselected. */
    model_check((string)$model->enabled === '0' && (string)$model->interfaces === 'lan', 'The defaults changed.');
    model_check(count(model_validate($model, true)) === 0, 'The defaults do not validate on a static LAN.');
    $options = array_keys($model->interfaces->getNodeData());
    sort($options);
    model_check($options === ['lan', 'opt1', 'opt4', 'opt6', 'opt7', 'opt8', 'wan'] || $options === ['lan', 'opt1', 'opt6', 'opt7', 'opt8', 'wan'],
        'Only enabled static interfaces may be offered: ' . json_encode($options));

    /* The first run offers both central servers and the LAN subnet; the secrets stay empty. */
    $model->runMigrations();
    $addresses = [];
    foreach ($model->centrals->central->iterateItems() as $central) {
        $addresses[] = (string)$central->address . ':' . (string)$central->port . ':' . (string)$central->sequence;
        model_check((string)$central->enabled === '0', 'A seeded central server is enabled without a secret.');
        model_check($central->secret->getValue() === '', 'A seeded central server has a secret.');
    }
    model_check($addresses === ['100.88.32.2:11812:10', '100.88.33.1:11812:20'], 'The seeded centrals changed: ' . json_encode($addresses));
    model_check((string)$model->client_networks === '192.168.0.0/22', 'The LAN network was not offered: ' . $model->client_networks);
    /* Reload the fixture: the seeds were only applied in memory and must not reach the cases below. */
    $config->lock();
    $config->lock(false);

    /* Interface choices: the WAN and everything that looks like one is refused, whatever else is set. */
    $cases = [
        ['lan', []],
        ['lan,opt7', []],
        ['opt7', []],
        ['wan', ['interfaces']],
        ['opt1', ['interfaces']],            /* gateway */
        ['opt2', ['interfaces']],            /* DHCP */
        ['opt3', ['interfaces']],            /* PPPoE */
        ['opt4', ['interfaces']],            /* disabled */
        ['opt5', ['interfaces']],            /* no IPv4 address */
        ['opt6', ['interfaces']],            /* block private networks: a WAN option */
        ['opt8', ['interfaces']],            /* a gateway of System > Gateways points at it (26.7 model) */
        ['lan,wan', ['interfaces']],
        ['lan,opt7,opt1', ['interfaces']],
        ['opt9', ['interfaces']],            /* does not exist (and an old-style gateway names it) */
        ['', ['interfaces']],                /* required */
    ];
    foreach ($cases as [$value, $expected]) {
        $candidate = model_fresh();
        $candidate->interfaces = $value;
        $fields = model_fields(model_validate($candidate, true));
        model_check($fields === $expected, sprintf('interfaces=%s: expected %s, got %s', $value,
            json_encode($expected), json_encode($fields)));
    }
    /* Even enabled and fully configured, a WAN cannot be saved. */
    $candidate = model_fresh();
    $candidate->enabled = '1';
    $candidate->interfaces = 'lan,wan';
    $candidate->servercert = 'cert1';
    $candidate->ca = 'ca1';
    $candidate->client_networks = '192.168.0.0/22';
    $candidate->client_secret = $apSecret;
    model_central($candidate, '10.99.0.1', '11812', $centralSecret);
    model_check(in_array('interfaces', model_fields(model_validate($candidate, false)), true),
        'A configuration that listens on the WAN validated.');

    /* Other fields. */
    $cases = [
        ['client_networks', '192.168.0.0/22', []],
        ['client_networks', '192.168.0.0/22,10.7.0.0/24', []],
        ['client_networks', '192.168.0.1', []],
        ['client_networks', '192.168.0.1/22', ['client_networks']],     /* host bits */
        ['client_networks', '10.0.0.0/7', ['client_networks']],         /* wider than the template keeps */
        ['client_networks', '0.0.0.0/0', ['client_networks']],
        ['client_networks', '10.0.0.0/8', []],
        ['client_networks', 'fe80::/10', ['client_networks']],
        ['client_networks', 'nonsense', ['client_networks']],
        ['client_secret', $apSecret, []],
        ['client_secret', 'short', ['client_secret']],
        ['client_secret', 'has"quote-and-more', ['client_secret']],
        ['client_secret', 'has$dollar-sign-x', ['client_secret']],
        ['client_secret', 'has\\backslash-xx', ['client_secret']],
        ['client_secret', 'has space in secret', ['client_secret']],
        ['servercert', 'cert1', []],
        ['servercert', 'nope', ['servercert']],
        ['ca', 'ca1', []],
        ['ca', 'cert1', ['ca']],
        ['enabled', '2', ['enabled']],
    ];
    foreach ($cases as [$field, $value, $expected]) {
        $candidate = model_fresh();
        $candidate->$field = $value;
        $fields = model_fields(model_validate($candidate, true));
        model_check($fields === $expected, sprintf('%s=%s: expected %s, got %s', $field, $value,
            json_encode($expected), json_encode($fields)));
    }

    /* A central server needs a valid address, port and sequence, and the address and port are unique. */
    $candidate = model_fresh();
    $one = model_central($candidate, '10.99.0.1', '11812', $centralSecret);
    $two = model_central($candidate, '10.99.0.1', '11812', $centralSecret, '1', '20');
    $fields = array_filter(model_fields(model_validate($candidate, true)), fn($f) => strpos($f, 'centrals.central.') === 0);
    model_check(count($fields) > 0, 'A duplicate central server validated.');
    foreach ([['address', 'not-an-address'], ['address', '10.0.0.0/8'], ['address', '::1'], ['port', '0'], ['port', '70000'],
              ['port', 'abc'], ['sequence', '0'], ['secret', 'short']] as [$field, $value]) {
        $candidate = model_fresh();
        $node = model_central($candidate, '10.99.0.1', '11812', $centralSecret);
        $node->$field = $value;
        $fields = array_filter(model_fields(model_validate($candidate, true)), fn($f) => strpos($f, 'centrals.central.') === 0);
        model_check(count($fields) === 1, sprintf('central %s=%s validated: %s', $field, $value, json_encode($fields)));
    }

    /* Enabling needs the certificates, the secrets and one enabled central server. */
    $candidate = model_fresh();
    $candidate->enabled = '1';
    $candidate->client_networks = '';
    $fields = model_fields(model_validate($candidate, false));
    model_check($fields === ['ca', 'client_networks', 'client_secret', 'enabled', 'servercert'],
        'Enabling an empty configuration validated: ' . json_encode($fields));
    $candidate = new \OPNsense\KazuhaHubRadius\General();
    $candidate->enabled = '1';
    $candidate->servercert = 'cert1';
    $candidate->ca = 'ca1';
    $candidate->client_secret = $apSecret;
    /* The seeded central servers are off and have no secret, so there is nothing usable to enable. */
    model_check(model_fields(model_validate($candidate, false)) === ['enabled'],
        'Enabling with no usable central server validated.');
    foreach ($candidate->centrals->central->iterateItems() as $central) {
        $central->enabled = '1';
    }
    $fields = model_fields(model_validate($candidate, false));
    /* One message on each row's secret field, and none usable, so the service itself is refused as well. */
    model_check(count($fields) === 3 && $fields[2] === 'enabled' && substr($fields[0], -7) === '.secret' && substr($fields[1], -7) === '.secret',
        'Enabled central servers without a secret validated: ' . json_encode($fields));
    foreach ($candidate->centrals->central->iterateItems() as $central) {
        $central->secret = $centralSecret;
    }
    model_check(count(model_validate($candidate, false)) === 0, 'A complete configuration does not validate.');

    /* A switched-on central server needs a usable secret whatever the service does, and the message sits on
       the row's own field, which is what the grid's add, set and toggle actions look at. */
    foreach (['0', '1'] as $service) {
        $candidate = model_fresh();
        $candidate->enabled = $service;
        $node = model_central($candidate, '10.99.0.1', '11812', '', '1');
        $fields = model_fields(model_validate($candidate, false));
        model_check(in_array($node->secret->__reference, $fields, true), "service=$service: no message on the secret field.");
        $off = model_central($candidate, '10.99.0.2', '11812', '', '0', '20');
        model_check(!in_array($off->secret->__reference, model_fields(model_validate($candidate, false)), true),
            'A disabled central server was asked for a secret.');
        $node->secret = 'bad secret!';
        model_check(in_array($node->secret->__reference, model_fields(model_validate($candidate, false)), true), 'A bad secret was accepted.');
        $node->secret = $centralSecret;
        model_check(!in_array($node->secret->__reference, model_fields(model_validate($candidate, false)), true), 'A good secret was refused.');
    }
    /* Switching the service on with no usable central server is refused. */
    $candidate = model_fresh();
    $candidate->enabled = '1';
    $candidate->servercert = 'cert1';
    $candidate->ca = 'ca1';
    $candidate->client_secret = $apSecret;
    model_check(model_fields(model_validate($candidate, false)) === ['enabled'], 'No central server at all validated.');
    model_central($candidate, '10.99.0.1', '11812', $centralSecret, '0');
    model_check(model_fields(model_validate($candidate, false)) === ['enabled'], 'Only a disabled central server validated.');

    /* Secrets are update-only: never read back, kept by an empty write, replaced by a new one. */
    $model = model_fresh();
    $model->enabled = '1';
    $model->interfaces = 'lan,opt7';
    $model->servercert = 'cert1';
    $model->ca = 'ca1';
    $model->client_networks = '192.168.0.0/22,10.7.0.0/24';
    $model->client_secret = $apSecret;
    $first = model_central($model, '10.99.0.1', '11812', $centralSecret, '1', '10');
    $second = model_central($model, '10.99.0.2', '11812', $otherSecret, '1', '20');
    model_check(count(model_validate($model, true)) === 0, 'The Irvine-style settings do not validate.');
    $dump = json_encode($model->getNodes());
    foreach ([$apSecret, $centralSecret, $otherSecret] as $secret) {
        model_check(strpos($dump, $secret) === false, 'The model handed out a secret.');
    }
    $model->setNodes(['client_secret' => '', 'enabled' => '1']);
    model_check($model->client_secret->getValue() === $apSecret, 'An empty write dropped the stored secret.');
    $first->setNodes(['secret' => '', 'description' => 'kept']);
    model_check($first->secret->getValue() === $centralSecret, 'An empty write dropped a central secret.');
    $replacement = 're-' . bin2hex(random_bytes(8));
    $first->setNodes(['secret' => $replacement]);
    model_check($first->secret->getValue() === $replacement, 'A new secret was not stored.');
    $first->secret = $centralSecret;
    $model->serializeToConfig(true);
    $config->save(null, false);
    $config->unlock();
    $xml = simplexml_load_file($fixture);
    $general = $xml->OPNsense->kazuhahubradius->general;
    model_check((string)$general->enabled === '1' && (string)$general->interfaces === 'lan,opt7' &&
        (string)$general->client_networks === '192.168.0.0/22,10.7.0.0/24' && (string)$general->servercert === 'cert1' &&
        (string)$general->client_secret === $apSecret, 'The settings did not reach config.xml as expected.');
    model_check(count($general->centrals->central) === 2 &&
        (string)$general->centrals->central[0]->secret === $centralSecret &&
        (string)$general->centrals->central[1]->address === '10.99.0.2', 'The central servers did not reach config.xml.');
    model_check((string)$xml->unknown === 'SENTINEL_UNKNOWN' && (string)$xml->unknown['attr'] === 'retain',
        'Saving lost an unrelated node.');
    $config->lock();
    $config->lock(false);
    $restored = new \OPNsense\KazuhaHubRadius\General();
    model_check((string)$restored->interfaces === 'lan,opt7' && $restored->client_secret->getValue() === $apSecret,
        'The settings did not survive a reload.');
    model_check(strpos(json_encode($restored->getNodes()), $apSecret) === false, 'A reloaded model handed out a secret.');
    /* The saved configuration is refused the moment its interface becomes a WAN. */
    $xml = simplexml_load_file($fixture);
    $xml->interfaces->opt7->addChild('gateway', 'LATE_GW');
    $xml->asXML($fixture);
    $config->unlock();
    $config->lock();
    $config->lock(false);
    $late = new \OPNsense\KazuhaHubRadius\General();
    model_check(in_array('interfaces', model_fields(model_validate($late, false)), true),
        'An interface that gained a gateway is still accepted.');
    echo "Native Kazuha Hub RADIUS model passed: WAN refusal, update-only secrets, seeds, validation and XML roundtrip.\n";
} finally {
    $config->unlock();
    foreach (glob($directory . '/*') as $file) {
        unlink($file);
    }
    rmdir($directory);
}
