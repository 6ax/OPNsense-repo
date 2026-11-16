# Kazuha Hub RADIUS for OPNsense

`os-kazuhahub-radius` is the RADIUS proxy for the Irvine site's "Kazuha Hub"
WPA2/WPA3-Enterprise Wi-Fi. It terminates the EAP-TTLS the access points send,
completes TLS with a server certificate from the OPNsense trust store, and
forwards the tunneled PAP request to the central RADIUS servers in a fail-over
order. It replaces the FreeRADIUS container that ran on the NAS.

| | |
| --- | --- |
| Plugin version | `1.0.0` |
| Target | OPNsense 26.7, `FreeBSD:15:amd64` |
| Dependency | the FreeBSD package `freeradius3` only (`net/freeradius3`, 3.2.10 on 26.7) |
| Menu | **Services > Kazuha Hub RADIUS > Settings** |

The package holds PHP, shell and configuration templates only: no native
executable and no Python dependency. One build serves every amd64 ABI; the
install hook accepts FreeBSD 14, 15 and 16 on amd64 only.

## How it works

- The daemon is the `radiusd` of `freeradius3`, started by its own rc script,
  `/usr/local/etc/rc.d/kazuhahub_radiusd`, with a private configuration
  directory, `/usr/local/etc/kazuhahub-radius/`. It does **not** read
  `/usr/local/etc/raddb`, does not touch `radiusd_enable` and uses nothing from
  os-freeradius.
- The default site listens on UDP 1812 on the static IPv4 address of each
  selected interface, never on `*`: `authorize` runs `filter_username`,
  `eap { ok = return }` and `reject`.
- Only EAP-TTLS is enabled (TLS 1.2 to 1.3). Nothing is authenticated inside the
  tunnel; the PAP request is handed to the realm `ENTRA`, which the central
  servers answer.
- The central servers form a fail-over pool in the configured order:
  `response_window = 15`, `zombie_period = 40`, probed with Status-Server
  (`check_interval = 30`, `check_timeout = 4`, `num_answers_to_alive = 3`).
  Their answers must carry a Message-Authenticator.
- The log goes to `/var/log/kazuhahub-radius/radius.log` and never holds a
  password, right or wrong.

## Settings

| Setting | Default | Notes |
| --- | --- | --- |
| Enable | off | Nothing runs while off. Before enabling, the certificates and every secret must be set. |
| Listen interfaces | `lan` | Enabled interfaces with a static IPv4 address only. |
| Server certificate / Certificate authority | empty | From **System > Trust**; the private key must be stored on this firewall. |
| Access point networks | the LAN subnet | IPv4 networks the access points live in (Irvine: `192.168.0.0/22`); one client per network. |
| Access point secret | empty | The shared secret between the access points and this service. |
| Central servers | Production `100.88.32.2:11812`, 4c6g `100.88.33.1:11812` | Address, port, secret and order; lower sequence first. |

**Secrets are never shown again.** Like every OPNsense secret they live in
`config.xml`, the API never returns them, and an empty form field keeps the
stored value. They appear only in the generated 0640 files, never in a log,
configd output, the status page or test output. A secret is 8 to 128 characters
from letters, digits and `! # & ( ) * + , - . / : ; < = > ? @ [ ] ^ _ { | } ~`.

## Never on the WAN

Saving is refused for an interface that is the `wan` key, has a gateway, has an
IPv4 type other than static (DHCP, PPPoE and so on), has no IPv4 address, is not
enabled, or carries the block-private and block-bogon options core sets on WAN
interfaces only. A saved configuration that later becomes WAN-like can no longer
be saved either. The templates repeat the same checks on the generated
configuration: with no usable address the listener falls back to the loopback and
the rc script refuses to start, so the daemon can never land on a wildcard
listener. The plugin adds no firewall rule: the default LAN rule already admits
the access points and nothing is opened on the WAN.

## Mutually exclusive with os-freeradius

Both run the same `radiusd`, so they cannot coexist:

- `+PRE_INSTALL` refuses to install while `os-freeradius` is installed;
- the rc script refuses to start while `os-freeradius` is installed or
  `radiusd_enable=YES`;
- the status panel of the settings page says why.

## Status and checks

The settings page shows the service state, the listen addresses, the state of
each central server (`alive`, `zombie`, `dead`, read with `radmin` from the
control socket `/var/run/kazuhahub-radius/radiusd.sock`, read-only and root
only), the result of the last configuration check and conflict warnings. **Check
now** exports the certificates and runs `radiusd -XC`; the result keeps only the
error lines, never a secret. A small dashboard widget shows the same.

A PHP helper exports the certificates from the trust store: `server.pem` (the
leaf followed by the chain, so the chain is sent to the clients), `server.key`
(0640 root:freeradius) and `ca.pem`, replaced atomically and only when the
content changed. They are exported and checked again before every start.

## Files

| Path | Content |
| --- | --- |
| `/usr/local/etc/kazuhahub-radius/` | generated configuration, 0750 root:freeradius, files 0640; removed on deinstall |
| `/var/run/kazuhahub-radius/` | pid, control socket, last check result |
| `/var/log/kazuhahub-radius/radius.log` | daemon log |
| `/usr/local/opnsense/version/kazuhahub-radius` | product version (JSON) |

Deinstalling removes the generated configuration, the runtime files and the log;
the settings stay in `config.xml` and come back on a reinstall. An upgrade keeps
the generated configuration and restarts the service when it is enabled.

## Tests

```sh
python3 -B tests/run.py --package os-kazuhahub-radius --python-only
php src/os-kazuhahub-radius/tests/native/test-model.php
```

The tests render every template with jinja2 (one and two central servers,
several interfaces, several client networks) and assert that the generated files
reproduce the behaviour above; wiring, hook, rc script and certificate export
tests come with them. The FreeBSD lifecycle check on the test host drives
EAP-TTLS/PAP with `eapol_test`: accept with the right password, reject with a
wrong one, and fail over to the second central server when the first stops.
