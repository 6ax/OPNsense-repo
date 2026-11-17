# Kazuha Hub RADIUS for OPNsense

`os-kazuhahub-radius` is the RADIUS proxy for a site's "Kazuha Hub"
WPA2/WPA3-Enterprise Wi-Fi. It terminates the EAP-TTLS the access points send,
completes TLS with a server certificate from the OPNsense trust store, and
forwards the tunneled PAP request to the central RADIUS servers in a fail-over
order.

| | |
| --- | --- |
| Plugin version | `1.1.0` |
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
| Access point networks | the LAN subnet | IPv4 networks the access points live in (for example `192.0.2.0/24`); one client per network. |
| Access point secret | empty | The shared secret between the access points and this service. |
| Central servers | none | Added by the admin, typically a primary central and a backup central: address, port (1812 by default), secret and order; lower sequence first. |
| Sync certificates from the central node | on | See below. |
| Certificate source host / port | empty | The central node that serves the certificate set on a private network; nothing is pulled while it is empty. |
| Expected certificate name | empty | The DNS name the central server certificate must carry; nothing is pulled while it is empty. |
| Certificate source user / password | empty | This site's HTTP Basic account on the central node; the password is stored like the other secrets and never shown again. While either is empty nothing is pulled. |

**Secrets are never shown again.** Like every OPNsense secret they live in
`config.xml`, the API never returns them, and an empty form field keeps the
stored value. They appear only in the generated 0640 files, never in a log,
configd output, the status page or test output. A secret is 8 to 128 characters
from letters, digits and `! # & ( ) * + , - . / : ; < = > ? @ [ ] ^ _ { | } ~`.

## Certificate sync

Renewals and CA changes are made on the central node only. Every 6 hours (cron,
through the configd action `kazuhahubradius certsync`, also on install and with
**Sync now** on the page) the plugin fetches `SHA256SUMS` from
`http://<host>:<port>/` with this site's HTTP Basic account. Without a source
host, an expected certificate name or an account it pulls nothing and logs that;
a 401 is a failure like any other. Only when it differs from the accepted set does it
fetch `server.pem`, `server.key` and `ca.pem`, and the set is accepted only if
all of these hold:

- every file matches its line in `SHA256SUMS`, which lists exactly those three;
- the certificate chains to the fetched `ca.pem` (`openssl verify`);
- it is valid now, and so is the authority;
- its subjectAltName holds `DNS:<expected certificate name>`;
- the key belongs to the certificate.

The set is then kept in `/var/db/kazuhahub-radius/certsync/current` (0700, files
0600), the exporter writes it where radiusd reads its certificates, the
configuration is checked with `radiusd -XC`, and only this service is restarted,
if it was running. Any failure — unreachable node, a 403, a bad sum, a failed
check — leaves the service on the set it has and is logged to the system log as
`kazuhahub-radius: cert-sync: ...`; a new set that fails the configuration check
or the restart is rolled back, the previous files are put back and the service
is brought back up if the restart had taken it down; that set is then not tried
again for a day, unless **Sync now** is used. A run that notices the daemon is
not on the accepted set (a start that fell back to the trust store, or a run cut
off mid-way) installs it again. The set
selected from the trust store stays the fallback: it is used until a central set
has been accepted and whenever none is valid, so the service never ends up
without certificates.

The request is plain HTTP, without a proxy or redirects, and limited in size and
time. It is meant for a private network between the sites and the central node,
never for the internet. Nothing new listens.

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
| `/var/db/kazuhahub-radius/certsync/` | the accepted central certificate set, the previous one and the last sync result, 0700 root; removed on deinstall |
| `/var/log/kazuhahub-radius/radius.log` | daemon log |
| `/usr/local/opnsense/version/kazuhahub-radius` | product version (JSON) |

Deinstalling removes the generated configuration, the runtime files and the log;
the settings stay in `config.xml` and come back on a reinstall. An upgrade keeps
the generated configuration and restarts the service when it is enabled.

## Further behaviour

- **Uplinks.** An interface that any gateway of System > Gateways points at, disabled or not (the 26.7
  `//OPNsense/Gateways` model and the older `//gateways` section are both read), cannot carry the listener
  either; the templates repeat the check, so a gateway added later drops the listener.
- **Central servers.** The two defaults are offered **disabled** and without secrets. Enabling one needs a valid
  secret on that row (the message sits on the row's secret field, so the grid's add, edit and toggle actions refuse
  it), and the service needs at least one usable central server. The templates skip an unusable row instead of
  writing an empty secret; when none is left they leave a marker, and **Check now** and the start fail with a
  clear message.
- **Client networks.** Prefixes shorter than `/8` are refused, in the model and the template alike, and repeated
  networks produce one client. With no usable network or secret the same marker and refusal apply.
- **The configuration is rendered again before every start and reload** (the rc script runs
  `configctl template reload OPNsense/KazuhaHubRadius`), so an interface address that changed is picked up by the
  next start; a running listener set is not changed until a restart. This was chosen over an interface hook
  because it is one place that every start path goes through.
- **Refusals reach the status page.** Every refusal of the rc script (conflicts, no usable interface, central server
  or access point, a listen address that is not configured) is written to the last check result.
- **Source address toward the central servers.** The proxy requests use FreeRADIUS's ephemeral wildcard UDP socket
  on a random high port. It is not a listener: no `listen` section opens it, it only sends to the configured
  central servers and receives their answers, and the lifecycle check asserts that it is the only wildcard socket
  and not on port 1812. No `src_ipaddr` is set: the kernel picks the source address by route for each central server, and a single fixed address could disagree with the route to one of them.
- **Log rotation.** `/usr/local/etc/newsyslog.conf.d/kazuhahub-radius.conf` rotates the log at 2 MB, keeps 7 and
  sends radiusd a HUP to reopen it.
- **Coexistence check.** `+PRE_INSTALL` does not use `pkg info`, which is unreliable inside a pkg transaction; it
  looks for files only os-freeradius installs (`/usr/local/opnsense/version/freeradius` and others). pkg 2.8 prints "PRE-INSTALL script failed" and carries on, so the post-install warns again and the service itself refuses to start (and says so in the status) while those files exist.

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
