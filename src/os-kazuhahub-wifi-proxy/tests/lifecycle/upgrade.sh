#!/bin/sh
# Upgrade check for the rename, run as root on the test host (an OPNsense VM
# that is not a production router):
#
#   sh upgrade.sh <dist-dir> <work-dir> <eapol_test>
#
# <dist-dir> holds the new os-kazuhahub-wifi-proxy and the transitional
# os-kazuhahub-radius packages. The run installs the published
# os-kazuhahub-radius 1.0.0 from the signed Kazuha Hub repository, configures
# and starts it, proves EAP-TTLS through it, then upgrades it the way the
# firmware does: pkg upgrade against a local repository with the two new
# packages. It checks that every setting, secret and central server uuid moved
# to the new name, that the former service left nothing behind, that the new
# one serves, and that removing the transitional package keeps the proxy.
# Everything is put back at the end. Secrets are generated here and never
# printed; a JSON report is written to <work-dir>/report.json.
set -u
export LC_ALL=C
[ "$#" -eq 3 ] || { echo 'usage: upgrade.sh dist-dir work-dir eapol_test' >&2; exit 1; }
DIST="$1"
W="$2"
EAPOL="$3"
STEPS="$W/steps.tsv"
SECRETS="$W/secrets"
PKI="$W/pki"
REPO="$W/repo"
REPOCONF=/usr/local/etc/pkg/repos/lifecycle-upgrade.conf
FAILED=0
: > "$STEPS"

pass() { printf '%s\tpass\t\n' "$1" >> "$STEPS"; echo "PASS $1"; }
fail() { printf '%s\tfail\t\n' "$1" >> "$STEPS"; echo "FAIL $1"; FAILED=1; }
expect() { if eval "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi; }

mkdir -p "$SECRETS" "$PKI" "$REPO/All"
chmod 0700 "$SECRETS"
for name in ap c1 c2 inner; do
    [ -s "$SECRETS/$name" ] || openssl rand -hex 16 > "$SECRETS/$name"
done
AP="$(cat "$SECRETS/ap")"
USERPASS="$(cat "$SECRETS/inner")"

cp /conf/config.xml "$W/config.xml.orig"
ls /conf/backup > "$W/backups.orig" 2>/dev/null || : > "$W/backups.orig"
mkdir -p "$W/backup.orig"
cp -p /conf/backup/* "$W/backup.orig/" 2>/dev/null
pkg query -a '%n' | sort > "$W/packages.orig"
USER_PRESENT=0
id freeradius >/dev/null 2>&1 && USER_PRESENT=1
LOG_PRESENT=0
[ -e /var/log/radius.log ] && LOG_PRESENT=1

expect 'preflight: neither name is installed and no radiusd runs' \
    '! pkg info -e os-kazuhahub-radius && ! pkg info -e os-kazuhahub-wifi-proxy && ! pgrep -x radiusd'
expect 'preflight: the published repository offers os-kazuhahub-radius 1.0.0' \
    "[ \"\$(pkg rquery -r kazuhahub '%v' os-kazuhahub-radius)\" = 1.0.0 ]"

# --- the former plugin, as a router runs it -------------------------------------
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$PKI/ca.key" -out "$PKI/ca.pem" -days 3 \
    -subj '/CN=Upgrade Test CA' >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$PKI/server.key" -out "$PKI/server.csr" \
    -subj '/CN=radius.upgrade.invalid' >/dev/null 2>&1
printf 'subjectAltName=DNS:radius.upgrade.invalid\nextendedKeyUsage=serverAuth\n' > "$PKI/ext.cnf"
openssl x509 -req -in "$PKI/server.csr" -CA "$PKI/ca.pem" -CAkey "$PKI/ca.key" -CAcreateserial \
    -out "$PKI/server.crt" -days 3 -extfile "$PKI/ext.cnf" >/dev/null 2>&1
pkg install -y os-kazuhahub-radius > "$W/install-old.out" 2>&1
expect 'the published 1.0.0 installs with freeradius3' \
    "[ \"\$(pkg query '%v' os-kazuhahub-radius)\" = 1.0.0 ] && pkg info -e freeradius3"
expect 'the throwaway CA and certificate enter the trust store' "php '$W/seed.php' trust '$PKI' | grep -q '\"ok\"'"

# Settings through the former model, the way its page saves them.
cat > "$W/legacy.php" <<'PHPEOF'
<?php
require_once('/usr/local/opnsense/mvc/app/config/loader.php');
require_once('/usr/local/etc/inc/util.inc');
require_once('/usr/local/etc/inc/config.inc');
$secrets = $argv[1];
$read = fn($name) => trim((string)file_get_contents("$secrets/$name"));
$model = new \OPNsense\KazuhaHubRadius\General();
$model->setNodes(['enabled' => '1', 'interfaces' => 'lan', 'servercert' => 'lifecycle-cert', 'ca' => 'lifecycle-ca',
    'client_networks' => '10.0.0.0/24', 'client_secret' => $read('ap')]);
foreach (array_keys(iterator_to_array($model->centrals->central->iterateItems())) as $uuid) {
    $model->centrals->central->del($uuid);
}
foreach ([['127.0.0.2', 'c1', '10', 'primary'], ['127.0.0.3', 'c2', '20', 'backup']] as [$address, $secret, $sequence, $name]) {
    $model->centrals->central->Add()->setNodes(['enabled' => '1', 'description' => $name, 'address' => $address,
        'port' => '1814', 'secret' => $read($secret), 'sequence' => $sequence]);
}
$messages = [];
foreach ($model->performValidation(true) as $message) {
    $messages[] = $message->getField();
}
if ($messages) {
    echo json_encode(['result' => 'invalid', 'fields' => $messages]) . "\n";
    exit(1);
}
$model->serializeToConfig(true);
\OPNsense\Core\Config::getInstance()->save(null, false);
$uuids = array_keys(iterator_to_array($model->centrals->central->iterateItems()));
sort($uuids);
echo json_encode(['result' => 'saved', 'uuids' => $uuids]) . "\n";
PHPEOF
php "$W/legacy.php" "$SECRETS" > "$W/legacy.json"
expect 'the former settings are saved through the former model' "grep -q '\"saved\"' '$W/legacy.json'"

ifconfig lo0 alias 127.0.0.2/32
ifconfig lo0 alias 127.0.0.3/32
make_central() {
    n="$1"; address="$2"; secret="$(cat "$SECRETS/c$n")"
    c="$W/central$n"
    rm -rf "$c"; mkdir -p "$c/mods-enabled" "$c/sites-enabled" "$c/log" "$c/run"
    cat > "$c/radiusd.conf" <<EOF
prefix = /usr/local
raddbdir = $c
confdir = \${raddbdir}
logdir = $c/log
run_dir = $c/run
db_dir = \${raddbdir}
name = radiusd
pidfile = \${run_dir}/radiusd.pid
log {
	destination = files
	file = \${logdir}/radius.log
}
security {
	allow_core_dumps = no
	status_server = yes
}
proxy_requests = no
client all {
	ipv4addr = 127.0.0.0/8
	secret = "$secret"
	require_message_authenticator = no
}
modules {
	pap {
	}
	files authorized_users {
		filename = \${confdir}/users
	}
	\$INCLUDE mods-enabled/
}
\$INCLUDE sites-enabled/
EOF
    printf 'testuser Cleartext-Password := "%s"\n' "$USERPASS" > "$c/users"
    printf 'always reject {\n\trcode = reject\n}\nalways ok {\n\trcode = ok\n}\n' > "$c/mods-enabled/always"
    cat > "$c/sites-enabled/default" <<EOF
server default {
	listen {
		type = auth
		ipaddr = $address
		port = 1814
	}
	authorize {
		Autz-Type Status-Server {
			ok
		}
		authorized_users
		pap
	}
	authenticate {
		Auth-Type PAP {
			pap
		}
	}
}
EOF
    chmod 0700 "$c"
}
make_central 1 127.0.0.2
make_central 2 127.0.0.3
/usr/local/sbin/radiusd -d "$W/central1" -n radiusd
/usr/local/sbin/radiusd -d "$W/central2" -n radiusd
sleep 1

cat > "$W/accept.conf" <<EOF
network={
	ssid="upgrade"
	key_mgmt=WPA-EAP
	eap=TTLS
	identity="testuser"
	anonymous_identity="anonymous"
	password="$USERPASS"
	ca_cert="$PKI/ca.pem"
	phase2="auth=PAP"
}
EOF
# Called through expect's eval.
# shellcheck disable=SC2329
run_eapol() { "$EAPOL" -c "$W/accept.conf" -a 10.0.0.1 -p 1812 -s "$AP" -r0 -t 20 > "$W/eapol.out" 2>&1; }
configctl template reload OPNsense/KazuhaHubRadius >/dev/null
configctl kazuhahubradius start >/dev/null
sleep 2
expect 'the former service runs and serves EAP-TTLS' \
    "configctl kazuhahubradius status | grep -q 'is running' && run_eapol && grep -q '^SUCCESS' '$W/eapol.out'"
OLD_PID="$(cat /var/run/kazuhahub-radius/radiusd.pid 2>/dev/null)"

# --- the upgrade, as the firmware runs it ---------------------------------------
# pkg upgrades a package only from the repository it came from, so the local
# repository stands in for the Kazuha Hub one under its name, as the published
# release will on the router.
cp "$DIST"/os-kazuhahub-wifi-proxy-*.pkg "$DIST"/os-kazuhahub-radius-*.pkg "$REPO/All/"
pkg repo -q "$REPO" >/dev/null 2>&1
cat > "$REPOCONF" <<EOF
kazuhahub: { url: "file://$REPO", signature_type: "none", enabled: yes }
EOF
pkg update -f > "$W/update.out" 2>&1
pkg upgrade -y os-kazuhahub-radius > "$W/upgrade.out" 2>&1
expect 'the upgrade replaces the former package with the transitional one and installs the renamed proxy' \
    "[ \"\$(pkg query '%v' os-kazuhahub-radius)\" = 1.1.0 ] && [ \"\$(pkg query '%v' os-kazuhahub-wifi-proxy)\" = 1.1.0 ]"
sleep 3
expect 'the former daemon is gone and its files with it' \
    "! kill -0 '$OLD_PID' && [ ! -e /etc/rc.conf.d/kazuhahub_radiusd ] && [ ! -e /usr/local/etc/kazuhahub-radius ] && [ ! -e /var/run/kazuhahub-radius ] && [ ! -e /usr/local/etc/rc.d/kazuhahub_radiusd ]"
expect 'the former settings moved to the new name, the former node is gone' \
    "grep -q '<kazuhahubwifiproxy>' /conf/config.xml && ! grep -q '<kazuhahubradius>' /conf/config.xml"
# shellcheck disable=SC2016
for uuid in $(php -r 'foreach (json_decode(file_get_contents($argv[1]), true)["uuids"] as $u) echo "$u\n";' "$W/legacy.json"); do
    expect "central server $uuid kept its uuid" \
        "php -r '\$x = simplexml_load_file(\"/conf/config.xml\"); foreach (\$x->OPNsense->kazuhahubwifiproxy->general->centrals->central as \$c) if ((string)\$c[\"uuid\"] === \$argv[1]) exit(0); exit(1);' '$uuid'"
done
expect 'every secret is in config.xml exactly once' \
    "[ \"\$(grep -c -F '$AP' /conf/config.xml)\" = 1 ] && [ \"\$(grep -c -F \"\$(cat '$SECRETS/c1')\" /conf/config.xml)\" = 1 ] && [ \"\$(grep -c -F \"\$(cat '$SECRETS/c2')\" /conf/config.xml)\" = 1 ]"
expect 'the renamed service runs, alone' \
    "configctl kazuhahubwifiproxy status | grep -q 'is running' && [ \"\$(pgrep -f 'radiusd -d /usr/local/etc/kazuhahub-wifi-proxy' | wc -l | tr -d ' ')\" = 1 ] && ! pgrep -f 'radiusd -d /usr/local/etc/kazuhahub-radius'"
expect 'the renamed service serves EAP-TTLS with the carried-over settings' "run_eapol && grep -q '^SUCCESS' '$W/eapol.out'"
expect 'it listens on the LAN address on 1812 and nowhere new' \
    "sockstat -4 -l | awk -v pid=\"\$(cat /var/run/kazuhahub-wifi-proxy/radiusd.pid)\" '\$3 == pid {print \$6}' | sort | tr '\n' ' ' | grep -q -E '^\\*:[0-9]+ 10\\.0\\.0\\.1:1812 127\\.0\\.0\\.1:18120 \$'"
expect 'the old configd actions are gone and the new ones answer' \
    "! configctl kazuhahubradius status 2>&1 | grep -q 'is running' && configctl kazuhahubwifiproxy lastcheck | grep -q '\"result\":\"ok\"'"
cat > "$W/read.php" <<'PHPEOF'
<?php
require_once('/usr/local/opnsense/mvc/app/config/loader.php');
require_once('/usr/local/etc/inc/util.inc');
require_once('/usr/local/etc/inc/config.inc');
$model = new \OPNsense\KazuhaHubWifiProxy\General();
$rows = iterator_to_array($model->centrals->central->iterateItems());
exit((string)$model->enabled === '1' && (string)$model->servercert === 'lifecycle-cert' && count($rows) === 2 ? 0 : 1);
PHPEOF
expect 'the renamed model reads the carried-over settings' "php '$W/read.php'"
KEPT=0
for _ in $(seq 1 24); do
    if [ "$(pkg query '%a' os-kazuhahub-wifi-proxy)" = 0 ]; then KEPT=1; break; fi
    sleep 5
done
if [ "$KEPT" = 1 ]; then pass 'the renamed proxy is no longer marked automatic'; else fail 'the renamed proxy is no longer marked automatic'; fi

# --- removing the transitional package keeps the proxy -------------------------
pkg delete -y os-kazuhahub-radius > "$W/delete-transitional.out" 2>&1
pkg autoremove -y > "$W/autoremove.out" 2>&1
expect 'removing the transitional package and autoremove keep the proxy running' \
    "! pkg info -e os-kazuhahub-radius && pkg info -e os-kazuhahub-wifi-proxy && configctl kazuhahubwifiproxy status | grep -q 'is running' && run_eapol && grep -q '^SUCCESS' '$W/eapol.out'"

# --- put the host back ---------------------------------------------------------
pkg delete -y os-kazuhahub-wifi-proxy > "$W/delete.out" 2>&1
sleep 2
expect 'the renamed proxy deinstalls cleanly' \
    "! pgrep -x -U freeradius radiusd && [ ! -e /usr/local/etc/kazuhahub-wifi-proxy ] && ! grep -q 'kazuhahubwifiproxy certsync' /var/cron/tabs/root"
pkill -f "radiusd -d $W/central" 2>/dev/null
ifconfig lo0 -alias 127.0.0.2
ifconfig lo0 -alias 127.0.0.3
rm -f "$REPOCONF"
pkg update -f >/dev/null 2>&1
cp "$W/config.xml.orig" /conf/config.xml
ls /conf/backup > "$W/backups.now" 2>/dev/null || : > "$W/backups.now"
for name in $(comm -13 "$W/backups.orig" "$W/backups.now"); do rm -f "/conf/backup/$name"; done
for name in $(comm -23 "$W/backups.orig" "$W/backups.now"); do cp -p "$W/backup.orig/$name" "/conf/backup/$name"; done
pkg query -a '%n' | sort > "$W/packages.now"
for name in $(comm -13 "$W/packages.orig" "$W/packages.now"); do pkg delete -fy "$name" >/dev/null 2>&1; done
pkg clean -ay >/dev/null 2>&1
if [ "$USER_PRESENT" = 0 ]; then
    pw userdel freeradius >/dev/null 2>&1
    pw groupdel freeradius >/dev/null 2>&1
fi
[ "$LOG_PRESENT" = 1 ] || rm -f /var/log/radius.log
pkg query -a '%n' | sort > "$W/packages.now"
expect 'the configuration file is restored exactly' "cmp -s '$W/config.xml.orig' /conf/config.xml"
expect 'the installed packages are as they were' "cmp -s '$W/packages.orig' '$W/packages.now'"
expect 'the loopback aliases and the local repository are gone' \
    "! ifconfig lo0 | grep -q -E '127\\.0\\.0\\.[23] ' && [ ! -e '$REPOCONF' ]"
service configd restart >/dev/null 2>&1

python3 - "$W" <<'PY'
import json
import sys
from pathlib import Path

work = sys.argv[1]
steps = []
for line in Path(work, 'steps.tsv').read_text().splitlines():
    name, status, _ = (line.split('\t') + ['', ''])[:3]
    steps.append({'step': name, 'result': status})
report = {'passed': sum(1 for s in steps if s['result'] == 'pass'), 'failed': sum(1 for s in steps if s['result'] == 'fail'), 'steps': steps}
Path(work, 'report.json').write_text(json.dumps(report, indent=1) + '\n')
print(json.dumps({key: report[key] for key in ('passed', 'failed')}))
PY
exit "$FAILED"
