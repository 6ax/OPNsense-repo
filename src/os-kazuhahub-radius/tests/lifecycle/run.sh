#!/bin/sh
# FreeBSD lifecycle check for os-kazuhahub-radius, run as root on the test host
# (an OPNsense VM that is not a production router).
#
#   sh run.sh <package.pkg> <work-dir> <eapol_test>
#
# The work directory is private to the run. It must hold seed.php beside the
# package. The script installs freeradius3 and the package, seeds the trust
# store and the settings through the real core, drives EAP-TTLS/PAP through the
# proxy with eapol_test against two fake central servers on loopback aliases,
# stops everything, deinstalls, and puts the host back: the configuration file,
# the config backups, the loopback aliases, the installed packages. Secrets are
# generated here, kept in <work-dir>/secrets and never printed. A JSON report
# is written to <work-dir>/report.json.
set -u
export LC_ALL=C
[ "$#" -eq 3 ] || { echo 'usage: run.sh package.pkg work-dir eapol_test' >&2; exit 1; }
PKG="$1"
W="$2"
EAPOL="$3"
STEPS="$W/steps.tsv"
SECRETS="$W/secrets"
PKI="$W/pki"
CONF=/usr/local/etc/kazuhahub-radius
FAILED=0
: > "$STEPS"

pass() { printf '%s\tpass\t%s\n' "$1" "${2:-}" >> "$STEPS"; echo "PASS $1"; }
fail() { printf '%s\tfail\t%s\n' "$1" "${2:-}" >> "$STEPS"; echo "FAIL $1 ${2:-}"; FAILED=1; }
# expect NAME COMMAND: the command is evaluated by sh and must succeed.
# A failure records the name only: the command may carry a test secret.
expect() {
    if eval "$2" >/dev/null 2>&1; then pass "$1"; else fail "$1"; fi
}
# refuse NAME COMMAND: the command must fail.
refuse() {
    if eval "$2" >/dev/null 2>&1; then fail "$1"; else pass "$1"; fi
}

mkdir -p "$SECRETS" "$PKI"
chmod 0700 "$SECRETS"
for name in ap c1 c2 inner; do
    [ -s "$SECRETS/$name" ] || openssl rand -hex 16 > "$SECRETS/$name"
done
AP="$(cat "$SECRETS/ap")"
USERPASS="$(cat "$SECRETS/inner")"

# --- facts and a snapshot of what has to be put back -------------------------
FACTS_FREEBSD="$(freebsd-version)"
FACTS_OPNSENSE="$(opnsense-version | head -n 1)"
DISK="$(df -h / | tail -n 1)"
cp /conf/config.xml "$W/config.xml.orig"
ls /conf/backup > "$W/backups.orig" 2>/dev/null || : > "$W/backups.orig"
# OPNsense prunes the oldest backups once the limit is reached, so keep copies to put back.
mkdir -p "$W/backup.orig"
cp -p /conf/backup/* "$W/backup.orig/" 2>/dev/null
pkg query -a '%n' | sort > "$W/packages.orig"
FREERADIUS_PRESENT=0
pkg info -e freeradius3 && FREERADIUS_PRESENT=1
# The package leaves its user, its group and an empty log behind when it is removed.
USER_PRESENT=0
id freeradius >/dev/null 2>&1 && USER_PRESENT=1
LOG_PRESENT=0
[ -e /var/log/radius.log ] && LOG_PRESENT=1

expect 'preflight: os-freeradius is not installed' '! pkg info -e os-freeradius'
expect 'preflight: no radiusd is running' '! pgrep -x radiusd'
expect 'preflight: the stock radiusd is not enabled' '! service -e | grep -q "/radiusd$"'

# --- throwaway PKI -------------------------------------------------------------
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$PKI/ca.key" -out "$PKI/ca.pem" -days 3 \
    -subj '/CN=Lifecycle Test CA' >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$PKI/server.key" -out "$PKI/server.csr" \
    -subj '/CN=radius.lifecycle.invalid' >/dev/null 2>&1
printf 'subjectAltName=DNS:radius.lifecycle.invalid\nextendedKeyUsage=serverAuth\n' > "$PKI/ext.cnf"
openssl x509 -req -in "$PKI/server.csr" -CA "$PKI/ca.pem" -CAkey "$PKI/ca.key" -CAcreateserial \
    -out "$PKI/server.crt" -days 3 -extfile "$PKI/ext.cnf" >/dev/null 2>&1
expect 'throwaway PKI: the server certificate verifies against its CA' \
    "openssl verify -CAfile '$PKI/ca.pem' '$PKI/server.crt'"

# --- install -------------------------------------------------------------------
pkg install -y freeradius3 >/dev/null 2>&1
expect 'freeradius3 is installed' 'pkg info -e freeradius3'
FREERADIUS_VERSION="$(pkg query '%v' freeradius3)"
RADDB_SUM="$(find /usr/local/etc/raddb -type f -exec sha256 -q {} + | sort | sha256 -q)"
# The stock configuration must never be touched; it is compared again at the end.
pkg add "$PKG" > "$W/install.out" 2>&1
expect 'the package installs' 'pkg info -e os-kazuhahub-radius'
expect 'the rc script is installed and executable' '[ -x /usr/local/etc/rc.d/kazuhahub_radiusd ]'
expect 'the configuration directory is 0750 root:freeradius' \
    "[ \"\$(stat -f '%Lp %Su:%Sg' $CONF)\" = '750 root:freeradius' ]"
expect 'the service is registered with the firmware plugin list' 'grep -q os-kazuhahub-radius /conf/config.xml'
expect 'the first load created the default central servers' \
    "[ \"\$(grep -c '<central ' /conf/config.xml)\" -ge 2 ]"
expect 'the generated rc setting is NO before anything is enabled' \
    "grep -qs '^kazuhahub_radiusd_enable=\"NO\"' /etc/rc.conf.d/kazuhahub_radiusd"
expect 'configd knows the actions' 'configctl kazuhahubradius status | grep -q "not running"'
expect 'nothing is running after a fresh install' '! pgrep -x radiusd'
expect 'the stock radiusd setting was not touched' '! service -e | grep -q "/radiusd$"'

# --- trust store and settings through the real core ----------------------------
expect 'the throwaway CA and certificate enter the trust store' \
    "php '$W/seed.php' trust '$PKI' | grep -q '\"ok\"'"
php "$W/seed.php" refusals > "$W/refusals.json"
expect 'the model refuses the WAN, alone or among others, and an unknown interface' \
    "grep -q '\"wan\":\"refused\"' '$W/refusals.json' && grep -q '\"lan,wan\":\"refused\"' '$W/refusals.json' && grep -q '\"opt99\":\"refused\"' '$W/refusals.json' && grep -q '\"enabled-wan\":\"refused\"' '$W/refusals.json'"
expect 'the model accepts the LAN' "grep -q '\"lan\":\"accepted\"' '$W/refusals.json'"
expect 'the settings are saved through the model, disabled first' \
    "php '$W/seed.php' settings '$SECRETS' disabled | grep -q '\"saved\"'"
expect 'the model never hands a secret out' \
    "php '$W/seed.php' leak '$SECRETS' | grep -q '\"leaked\":0'"
expect 'the secrets are in config.xml, where OPNsense keeps its secrets' "grep -q '$AP' /conf/config.xml"

# --- generated configuration and checks ---------------------------------------
expect 'the real template engine renders the configuration' \
    "[ \"\$(configctl template reload OPNsense/KazuhaHubRadius)\" = OK ]"
for file in radiusd.conf clients.conf proxy.conf mods-enabled/eap mods-enabled/always mods-enabled/attr_filter \
        policy.d/kazuhahub mods-config/attr_filter/access_reject sites-enabled/default sites-enabled/inner-tunnel \
        sites-enabled/control; do
    expect "generated: $file" "[ -s '$CONF/$file' ]"
done
expect 'generated files are not world readable' "[ -z \"\$(find $CONF -type f -perm -004)\" ]"
expect 'the default site listens on the LAN address only' \
    "grep -c 'ipaddr = ' $CONF/sites-enabled/default | grep -q '^1\$' && grep -q 'ipaddr = 10.0.0.1' $CONF/sites-enabled/default"
configctl kazuhahubradius check > "$W/check.json"
expect 'the configuration check passes' "grep -q '\"result\":\"ok\"' '$W/check.json'"
expect 'the check output carries no secret' \
    "! grep -q -F -e '$AP' -e \"\$(cat '$SECRETS/c1')\" -e \"\$(cat '$SECRETS/c2')\" -e '$USERPASS' '$W/check.json'"
expect 'the certificates are exported: leaf then authority in server.pem' \
    "[ \"\$(grep -c 'BEGIN CERTIFICATE' $CONF/certs/server.pem)\" = 2 ] && grep -q 'PRIVATE KEY' $CONF/certs/server.key && [ -s $CONF/certs/ca.pem ]"
expect 'the key file is 0640 root:freeradius' "[ \"\$(stat -f '%Lp %Su:%Sg' $CONF/certs/server.key)\" = '640 root:freeradius' ]"
expect 'the exported key belongs to the exported certificate' \
    "[ \"\$(openssl x509 -in $CONF/certs/server.pem -noout -pubkey | sha256 -q)\" = \"\$(openssl pkey -in $CONF/certs/server.key -pubout | sha256 -q)\" ]"
SUM_BEFORE="$(sha256 -q "$CONF/certs/server.key")"
configctl kazuhahubradius check >/dev/null
expect 'a second export leaves unchanged certificates alone' "[ \"\$(sha256 -q $CONF/certs/server.key)\" = '$SUM_BEFORE' ]"

# --- conflicts -----------------------------------------------------------------
printf 'radiusd_enable="YES"\n' > /etc/rc.conf.d/radiusd
configctl kazuhahubradius lastcheck > "$W/last.json"
expect 'the status names the stock radiusd service as a conflict' "grep -q 'radiusd_enable' '$W/last.json'"
refuse 'the rc script refuses to start next to the stock radiusd service' 'service kazuhahub_radiusd onestart'
expect 'nothing was started by the refused start' '! pgrep -x radiusd'
rm -f /etc/rc.conf.d/radiusd
expect 'the conflict is gone once the stock service is off' \
    "! configctl kazuhahubradius lastcheck | grep -q 'radiusd_enable'"

# --- fake central servers and the proxy ----------------------------------------
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
modconfdir = \${confdir}/mods-config
logdir = $c/log
run_dir = $c/run
db_dir = \${raddbdir}
name = radiusd
pidfile = \${run_dir}/radiusd.pid
max_request_time = 30
cleanup_delay = 5
max_requests = 1024
log {
	destination = files
	file = \${logdir}/radius.log
	auth = yes
	auth_badpass = no
	auth_goodpass = no
}
security {
	allow_core_dumps = no
	max_attributes = 200
	reject_delay = 0
	status_server = yes
}
proxy_requests = no
client all {
	ipv4addr = 127.0.0.0/8
	secret = "$secret"
	require_message_authenticator = no
}
thread pool {
	start_servers = 2
	max_servers = 8
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
    cat > "$c/mods-enabled/always" <<EOF
always reject {
	rcode = reject
}
always ok {
	rcode = ok
}
EOF
    cat > "$c/sites-enabled/default" <<EOF
server default {
	listen {
		type = auth
		ipaddr = $address
		port = 11812
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
expect 'the fake central servers have valid configurations' \
    "/usr/local/sbin/radiusd -C -d '$W/central1' -n radiusd && /usr/local/sbin/radiusd -C -d '$W/central2' -n radiusd"
/usr/local/sbin/radiusd -d "$W/central1" -n radiusd
/usr/local/sbin/radiusd -d "$W/central2" -n radiusd
sleep 1
expect 'both fake central servers listen on their loopback aliases' \
    "sockstat -4 -l | grep -q '127.0.0.2:11812' && sockstat -4 -l | grep -q '127.0.0.3:11812'"

expect 'the settings are enabled through the model' \
    "php '$W/seed.php' settings '$SECRETS' enabled | grep -q '\"saved\"'"
# What the reconfigure API does: stop, regenerate, start.
configctl kazuhahubradius stop >/dev/null
expect 'the reconfigure flow regenerates the configuration' \
    "[ \"\$(configctl template reload OPNsense/KazuhaHubRadius)\" = OK ]"
expect 'the generated rc setting turns YES' "grep -q '^kazuhahub_radiusd_enable=\"YES\"' /etc/rc.conf.d/kazuhahub_radiusd"
expect 'the service starts through configd' "[ \"\$(configctl kazuhahubradius start)\" = OK ]"
sleep 2
expect 'the service is running' 'configctl kazuhahubradius status | grep -q "is running"'
expect 'the pid file exists and names a live process' \
    "kill -0 \$(cat /var/run/kazuhahub-radius/radiusd.pid)"
expect 'it listens on the LAN address, the loopback tunnel and an unbound proxy socket, never on the WAN or a wildcard 1812' \
    "! sockstat -4 -l | grep radiusd | grep -E '198\\.23\\.159\\.179|\\*:1812|0\\.0\\.0\\.0:1812' && sockstat -4 -l | grep radiusd | grep -q '10.0.0.1:1812' && sockstat -4 -l | grep radiusd | grep -q '127.0.0.1:18120'"
expect 'the control socket is a root-only socket' \
    "[ -S /var/run/kazuhahub-radius/radiusd.sock ] && [ \"\$(stat -f '%Su' /var/run/kazuhahub-radius)\" = freeradius ] && [ \"\$(stat -f '%Lp' /var/run/kazuhahub-radius)\" = 750 ]"
expect 'the daemon runs as the freeradius user' \
    "[ \"\$(ps -o user= -p \$(cat /var/run/kazuhahub-radius/radiusd.pid) | tr -d ' ')\" = freeradius ]"

cat > "$W/accept.conf" <<EOF
network={
	ssid="lifecycle"
	key_mgmt=WPA-EAP
	eap=TTLS
	identity="testuser"
	anonymous_identity="anonymous"
	password="$USERPASS"
	ca_cert="$PKI/ca.pem"
	phase2="auth=PAP"
}
EOF
cat > "$W/reject.conf" <<EOF
network={
	ssid="lifecycle"
	key_mgmt=WPA-EAP
	eap=TTLS
	identity="testuser"
	anonymous_identity="anonymous"
	password="wrong-password-for-the-test"
	ca_cert="$PKI/ca.pem"
	phase2="auth=PAP"
}
EOF
run_eapol() { "$EAPOL" -c "$1" -a 10.0.0.1 -p 1812 -s "$AP" -r0 -t "${2:-20}" > "$W/eapol.out" 2>&1; }
expect 'EAP-TTLS/PAP with the right password is accepted through the proxy' \
    "run_eapol '$W/accept.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
expect 'EAP-TTLS/PAP with a wrong password is rejected' \
    "! run_eapol '$W/reject.conf' && grep -q '^FAILURE' '$W/eapol.out'"
expect 'a client outside the configured networks gets no answer' \
    "! '$EAPOL' -c '$W/accept.conf' -a 10.0.0.1 -p 1812 -s 'not-the-secret-0000' -r0 -t 6"
configctl kazuhahubradius homeservers > "$W/homeservers.json"
expect 'homeservers reports both central servers from radmin' \
    "grep -q '\"status\":\"ok\"' '$W/homeservers.json' && grep -q '127.0.0.2' '$W/homeservers.json' && grep -q '127.0.0.3' '$W/homeservers.json'"
expect 'the first central server is alive after serving requests' \
    "grep -q '\"address\":\"127.0.0.2\",\"port\":11812,\"state\":\"alive\"' '$W/homeservers.json'"
php "$W/seed.php" status > "$W/status.json"
expect 'the service controller reports running, the listen address and the last check' \
    "grep -q '\"status\":\"running\"' '$W/status.json' && grep -q '10.0.0.1' '$W/status.json' && grep -q '\"result\":\"ok\"' '$W/status.json'"
expect 'the service controller carries no secret' \
    "! grep -q -F -e '$AP' -e '$USERPASS' '$W/status.json'"
expect 'the log holds no password and no secret' \
    "! grep -q -e '$USERPASS' -e 'wrong-password-for-the-test' -e '$AP' -e \"\$(cat '$SECRETS/c1')\" -e \"\$(cat '$SECRETS/c2')\" /var/log/kazuhahub-radius/radius.log"
expect 'the log records the accept and the reject' \
    "grep -q 'Login OK' /var/log/kazuhahub-radius/radius.log && grep -q 'Login incorrect' /var/log/kazuhahub-radius/radius.log"

expect 'a reload keeps the service serving' \
    "[ \"\$(configctl kazuhahubradius reload)\" = OK ] && sleep 1 && run_eapol '$W/accept.conf' && grep -q '^SUCCESS' '$W/eapol.out'"

# --- fail-over -----------------------------------------------------------------
kill "$(cat "$W/central1/run/radiusd.pid")"
sleep 1
FAILOVER=0
attempt=0
while [ "$attempt" -lt 4 ] && [ "$FAILOVER" = 0 ]; do
    attempt=$((attempt + 1))
    if run_eapol "$W/accept.conf" 25 && grep -q '^SUCCESS' "$W/eapol.out"; then FAILOVER=1; fi
done
if [ "$FAILOVER" = 1 ]; then pass 'fail-over: with the first central server stopped, the second one accepts' "attempt $attempt"; else fail 'fail-over: with the first central server stopped, the second one accepts'; fi
configctl kazuhahubradius homeservers > "$W/homeservers-failover.json"
expect 'fail-over: the stopped central server is reported zombie or dead, the second alive' \
    "grep -q -E '\"address\":\"127.0.0.2\",\"port\":11812,\"state\":\"(zombie|dead)\"' '$W/homeservers-failover.json' && grep -q '\"address\":\"127.0.0.3\",\"port\":11812,\"state\":\"alive\"' '$W/homeservers-failover.json'"

# --- stop, restart, stop -------------------------------------------------------
expect 'the service stops through configd' "[ \"\$(configctl kazuhahubradius stop)\" = OK ]"
sleep 1
expect 'after the stop the daemon and its sockets are gone' \
    '! pgrep -f "radiusd -d /usr/local/etc/kazuhahub-radius" && [ ! -e /var/run/kazuhahub-radius/radiusd.sock ]'
expect 'a stopped service reports no central servers' \
    "configctl kazuhahubradius homeservers | grep -q '\"status\":\"stopped\"'"
expect 'the service restarts through configd' "[ \"\$(configctl kazuhahubradius restart)\" = OK ] && sleep 2 && configctl kazuhahubradius status | grep -q 'is running'"

# --- deinstall with the service running ---------------------------------------
kill "$(cat "$W/central2/run/radiusd.pid")" 2>/dev/null
pkg delete -y os-kazuhahub-radius > "$W/deinstall.out" 2>&1
expect 'deinstall removes the package' '! pkg info -e os-kazuhahub-radius'
expect 'deinstall leaves nothing running' '! pgrep -x radiusd'
for path in $CONF /var/run/kazuhahub-radius /var/log/kazuhahub-radius /etc/rc.conf.d/kazuhahub_radiusd \
        /usr/local/etc/rc.d/kazuhahub_radiusd /usr/local/opnsense/scripts/kazuhahub-radius \
        /usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius /usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius; do
    expect "deinstall removed $path" "[ ! -e '$path' ]"
done
expect 'the settings stay in config.xml' "grep -q 'kazuhahubradius' /conf/config.xml"
expect 'the stock FreeRADIUS configuration was never touched' \
    "[ \"\$(find /usr/local/etc/raddb -type f -exec sha256 -q {} + | sort | sha256 -q)\" = '$RADDB_SUM' ]"
expect 'freeradius3 is still installed after the plugin is gone' 'pkg info -e freeradius3'

# --- put the host back ---------------------------------------------------------
pkill -f "radiusd -d $W/central" 2>/dev/null
ifconfig lo0 -alias 127.0.0.2
ifconfig lo0 -alias 127.0.0.3
cp "$W/config.xml.orig" /conf/config.xml
ls /conf/backup > "$W/backups.now" 2>/dev/null || : > "$W/backups.now"
for name in $(comm -13 "$W/backups.orig" "$W/backups.now"); do rm -f "/conf/backup/$name"; done
for name in $(comm -23 "$W/backups.orig" "$W/backups.now"); do cp -p "$W/backup.orig/$name" "/conf/backup/$name"; done
expect 'the configuration file is restored exactly' "cmp -s '$W/config.xml.orig' /conf/config.xml"
expect 'the config backups are as they were' "[ \"\$(ls /conf/backup | sort | sha256 -q)\" = \"\$(sort '$W/backups.orig' | sha256 -q)\" ]"
if [ "$FREERADIUS_PRESENT" = 0 ]; then
    pkg delete -y freeradius3 >/dev/null 2>&1
fi
pkg query -a '%n' | sort > "$W/packages.now"
for name in $(comm -13 "$W/packages.orig" "$W/packages.now"); do pkg delete -fy "$name" >/dev/null 2>&1; done
pkg clean -ay >/dev/null 2>&1
if [ "$USER_PRESENT" = 0 ]; then
    pw userdel freeradius >/dev/null 2>&1
    pw groupdel freeradius >/dev/null 2>&1
fi
[ "$LOG_PRESENT" = 1 ] || rm -f /var/log/radius.log
pkg query -a '%n' | sort > "$W/packages.now"
expect 'the installed packages are as they were' "cmp -s '$W/packages.orig' '$W/packages.now'"
expect 'the freeradius user and group are gone again' \
    "[ '$USER_PRESENT' = 1 ] || { ! id freeradius && ! getent group freeradius; }"
expect 'the loopback aliases are gone' '! ifconfig lo0 | grep -q -E "127\.0\.0\.[23] "'
service configd restart >/dev/null 2>&1

# --- report --------------------------------------------------------------------
python3 - "$W" "$PKG" "$FACTS_FREEBSD" "$FACTS_OPNSENSE" "$FREERADIUS_VERSION" "$DISK" <<'PY'
import hashlib
import json
import sys
from pathlib import Path

work, package, freebsd, opnsense, freeradius, disk = sys.argv[1:7]
steps = []
for line in Path(work, 'steps.tsv').read_text().splitlines():
    name, status, *detail = line.split('\t')
    steps.append({'step': name, 'result': status, 'detail': detail[0] if detail else ''})
report = {
    'package': Path(package).name,
    'package_sha256': hashlib.sha256(Path(package).read_bytes()).hexdigest(),
    'freebsd': freebsd,
    'opnsense': opnsense,
    'freeradius3': freeradius,
    'disk_before': disk,
    'passed': sum(1 for step in steps if step['result'] == 'pass'),
    'failed': sum(1 for step in steps if step['result'] == 'fail'),
    'steps': steps,
}
Path(work, 'report.json').write_text(json.dumps(report, indent=1) + '\n')
print(json.dumps({key: report[key] for key in ('package', 'passed', 'failed')}))
PY
exit "$FAILED"
