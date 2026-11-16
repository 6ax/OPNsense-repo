<?php
/* The listener rules of the real model class, run against stand-ins for the framework: which interfaces may
   carry the RADIUS service, and what the first-run migration offers. */
namespace OPNsense\Base {
    class BaseModel
    {
    }

    class BaseModelMigration
    {
    }
}

namespace OPNsense\Base\Messages {
    class Message
    {
        public function __construct(public $message = '', public $field = '')
        {
        }
    }
}

namespace OPNsense\Core {
    class Config
    {
        public static function getInstance()
        {
            return new self();
        }
    }
}

namespace {
    if (!function_exists('gettext')) {
        function gettext($text)
        {
            return $text;
        }
    }

    function check($condition, $message)
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    $root = dirname(__DIR__, 2) . '/src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius';
    require $root . '/General.php';
    require $root . '/Migrations/M1_0_0.php';

    use OPNsense\KazuhaHubRadius\General;
    use OPNsense\KazuhaHubRadius\Migrations\M1_0_0;

    function facts($address, $extra = [])
    {
        return $extra + ['enable' => true, 'ipaddr' => $address, 'gateway' => '', 'blockpriv' => false, 'blockbogons' => false];
    }

    /* The reason an interface is refused, or null when the listener may live there. */
    $cases = [
        ['lan', facts('192.168.0.1'), null],
        ['opt1', facts('10.9.0.1'), null],
        ['opt20', facts('172.16.5.1'), null],
        ['lan', facts('192.168.0.1', ['gateway' => '  ']), null],             /* blank is no gateway */
        ['wan', facts('203.0.113.9'), 'wan'],                                  /* the key alone decides */
        ['wan', facts('192.168.0.1'), 'wan'],
        ['wan', null, 'wan'],
        ['lan', null, 'missing'],
        ['lan', facts('192.168.0.1', ['enable' => false]), 'disabled'],
        ['lan', facts('dhcp'), 'not-static'],
        ['lan', facts('pppoe'), 'not-static'],
        ['lan', facts('pptp'), 'not-static'],
        ['lan', facts('l2tp'), 'not-static'],
        ['lan', facts(''), 'not-static'],
        ['lan', facts('192.168.0'), 'not-static'],
        ['lan', facts('192.168.0.256'), 'not-static'],
        ['lan', facts('fe80::1'), 'not-static'],
        ['lan', facts('192.168.0.1/24'), 'not-static'],
        ['lan', facts('0.0.0.0'), 'not-unicast'],
        ['lan', facts('127.0.0.1'), 'not-unicast'],
        ['lan', facts('224.0.0.1'), 'not-unicast'],
        ['lan', facts('255.255.255.255'), 'not-unicast'],
        ['opt1', facts('198.51.100.2', ['gateway' => 'OTHER_GW']), 'gateway'],
        ['opt1', facts('10.1.0.1', ['gateway_ref' => true]), 'gateway-ref'],
        ['opt1', facts('10.1.0.1', ['gateway_ref' => false]), null],
        ['opt1', facts('10.1.0.1', ['gateway' => 'GW', 'gateway_ref' => true]), 'gateway'],
        ['opt1', facts('10.1.0.1', ['blockpriv' => true]), 'wan-like'],
        ['opt1', facts('10.1.0.1', ['blockbogons' => true]), 'wan-like'],
        ['opt1', facts('10.1.0.1', ['gateway' => 'GW', 'blockpriv' => true]), 'gateway'],
        ['', facts('10.1.0.1'), 'invalid'],
        ['LAN', facts('10.1.0.1'), 'invalid'],
        ['lan,wan', facts('10.1.0.1'), 'invalid'],
        ['../lan', facts('10.1.0.1'), 'invalid'],
        [str_repeat('a', 33), facts('10.1.0.1'), 'invalid'],
        [null, facts('10.1.0.1'), 'invalid'],
        [['lan'], facts('10.1.0.1'), 'invalid'],
        ['lan', 'not-an-array', 'missing'],
    ];
    foreach ($cases as [$name, $facts, $expected]) {
        $actual = General::listenRefusal($name, $facts);
        check($actual === $expected, sprintf('%s %s: expected %s, got %s', json_encode($name), json_encode($facts),
            var_export($expected, true), var_export($actual, true)));
    }

    /* Every refusal has a readable message that names the interface. */
    foreach (['invalid', 'wan', 'missing', 'disabled', 'not-static', 'not-unicast', 'gateway', 'gateway-ref', 'wan-like', 'unheard-of'] as $reason) {
        $message = General::refusalMessage('opt3', $reason);
        check(strpos($message, 'opt3') !== false && strpos($message, '%') === false, "No message for $reason: $message");
    }

    /* A selection splits into addresses and refusals, once per name, in the order given. */
    $interfaces = [
        'lan' => facts('192.168.0.1'),
        'opt1' => facts('10.1.0.1'),
        'opt2' => facts('198.51.100.2', ['gateway' => 'GW']),
        'opt3' => facts('dhcp'),
    ];
    $lookup = fn($name) => $interfaces[$name] ?? null;
    $result = General::listenTargets(['lan', 'wan', 'opt1', 'lan', 'opt2', 'opt3', 'opt9', 'wan'], $lookup);
    check($result === [
        'addresses' => ['lan' => '192.168.0.1', 'opt1' => '10.1.0.1'],
        'refused' => ['wan' => 'wan', 'opt2' => 'gateway', 'opt3' => 'not-static', 'opt9' => 'missing'],
    ], 'The selection was split wrongly: ' . json_encode($result));
    check(General::listenTargets([], $lookup) === ['addresses' => [], 'refused' => []], 'An empty selection is not empty.');
    $asked = [];
    General::listenTargets(['wan'], function ($name) use (&$asked) {
        $asked[] = $name;
        return null;
    });
    check($asked === ['wan'], 'The lookup is not needed for the WAN but is harmless.');

    /* Secrets: the characters the templates accept, and no others. */
    $good = ['abcdefgh', str_repeat('a', 128), 'Az09!#&()*+,./:;<=>?@^_{}|~[]-', 'ap-0123456789abcdef'];
    $bad = ['', 'short', str_repeat('a', 129), 'has"quote-xx', 'has\'quote-xx', 'has$dollar-xx', 'has\\backslash',
        'has space inside', "line\nbreak-secret", 'percent%{User-Name}', 'tab' . "\t" . 'inside-secret', 'unicode-secret-é', "trailing-newline-ok\n", null, 5];
    foreach ($good as $secret) {
        check(General::secretValid($secret), "A good secret was refused: $secret");
    }
    foreach ($bad as $secret) {
        check(!General::secretValid($secret), 'A bad secret was accepted: ' . var_export($secret, true));
    }
    /* The pattern in the model XML is the same one. */
    $xml = simplexml_load_file($root . '/General.xml');
    foreach ([$xml->items->client_secret->Mask, $xml->items->centrals->central->secret->Mask] as $mask) {
        $pattern = (string)$mask;
        foreach ($good as $secret) {
            check(preg_match($pattern, $secret) === 1, "The model mask refuses a good secret: $secret");
        }
        foreach ($bad as $secret) {
            if (is_string($secret)) {
                check(preg_match($pattern, $secret) !== 1, "The model mask accepts a bad secret: $secret");
            }
        }
    }

    /* The first-run migration offers the network the LAN address belongs to. */
    foreach ([['192.168.0.1', '22', '192.168.0.0/22'], ['192.168.1.77', '24', '192.168.1.0/24'], ['10.20.30.40', '8', '10.0.0.0/8'],
        ['172.16.5.9', '30', '172.16.5.8/30'], ['10.0.0.1', '31', null], ['10.0.0.1', '7', null], ['dhcp', '24', null],
        ['10.0.0.1', '', null], ['10.0.0.1', 'x', null], ['', '24', null]] as [$address, $prefix, $expected]) {
        check(M1_0_0::networkOf($address, $prefix) === $expected, "networkOf($address, $prefix)");
    }
    echo "Kazuha Hub RADIUS listener checks passed: WAN-like interfaces refused, targets split, secrets and seeds.\n";
}
