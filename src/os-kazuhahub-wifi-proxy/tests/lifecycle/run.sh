#!/bin/sh
# FreeBSD lifecycle check for os-kazuhahub-wifi-proxy, run as root on the test host
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
CONF=/usr/local/etc/kazuhahub-wifi-proxy
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
# +PRE_INSTALL reports the refusal when the files only os-freeradius installs are present. pkg 2.8 prints
# "PRE-INSTALL script failed" and then installs the package anyway, so the refusal that actually holds is
# the one at start: the status names the conflict and the rc script will not start.
mkdir -p /usr/local/opnsense/version
printf '{}\n' > /usr/local/opnsense/version/freeradius
pkg add "$PKG" > "$W/refused-install.out" 2>&1
expect 'the pre-install hook reports os-freeradius by its files and pkg flags the failure' \
    "grep -q 'os-freeradius is installed' '$W/refused-install.out' && grep -q 'PRE-INSTALL script failed' '$W/refused-install.out'"
expect 'the post-install hook warns about the conflict' "grep -q 'Warning: os-freeradius is installed' '$W/refused-install.out'"
expect 'with the os-freeradius files present the conflict is reported' \
    "sh /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/check.sh conflicts | grep -q 'os-freeradius is installed'"
refuse 'with the os-freeradius files present the rc script refuses to start' 'service kazuhahub_wifiproxy onestart'
expect 'nothing was started next to os-freeradius' '! pgrep -x radiusd'
rm -f /usr/local/opnsense/version/freeradius
pkg info -e os-kazuhahub-wifi-proxy || pkg add "$PKG" > "$W/install.out" 2>&1
expect 'the package installs' 'pkg info -e os-kazuhahub-wifi-proxy'
expect 'the rc script is installed and executable' '[ -x /usr/local/etc/rc.d/kazuhahub_wifiproxy ]'
expect 'the configuration directory is 0750 root:freeradius' \
    "[ \"\$(stat -f '%Lp %Su:%Sg' $CONF)\" = '750 root:freeradius' ]"
expect 'the service is registered with the firmware plugin list' 'grep -q os-kazuhahub-wifi-proxy /conf/config.xml'
expect 'the first load creates no central server: the admin adds them' \
    "[ \"\$(grep -c '<central ' /conf/config.xml)\" = 0 ]"
expect 'the generated rc setting is NO before anything is enabled' \
    "grep -qs '^kazuhahub_wifiproxy_enable=\"NO\"' /etc/rc.conf.d/kazuhahub_wifiproxy"
expect 'configd knows the actions' 'configctl kazuhahubwifiproxy status | grep -q "not running"'
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
php "$W/seed.php" rules > "$W/rules.json"
expect 'the model refuses an enabled central server without a secret on the row field' "grep -q '\"central_secret\":\"refused\"' '$W/rules.json'"
expect 'the model refuses enabling the service without a usable central server' "grep -q '\"enabled_without_central\":\"refused\"' '$W/rules.json'"
expect 'the model refuses a client network wider than /8' "grep -q '\"wide_network\":\"refused\"' '$W/rules.json'"
expect 'the model refuses the LAN once a gateway of System > Gateways points at it' "grep -q '\"gateway_ref\":\"refused\"' '$W/rules.json'"
expect 'newsyslog reads the shipped rotation entry for the daemon log' \
    "newsyslog -nv -f /usr/local/etc/newsyslog.conf.d/kazuhahub-wifi-proxy.conf 2>&1 | grep -q 'kazuhahub-wifi-proxy/radius.log'"
expect 'the secrets are in config.xml, where OPNsense keeps its secrets' "grep -q '$AP' /conf/config.xml"

# --- generated configuration and checks ---------------------------------------
expect 'the real template engine renders the configuration' \
    "[ \"\$(configctl template reload OPNsense/KazuhaHubWifiProxy)\" = OK ]"
for file in radiusd.conf clients.conf proxy.conf mods-enabled/eap mods-enabled/always mods-enabled/attr_filter \
        policy.d/kazuhahub mods-config/attr_filter/access_reject sites-enabled/default sites-enabled/inner-tunnel \
        sites-enabled/control; do
    expect "generated: $file" "[ -s '$CONF/$file' ]"
done
expect 'generated files are not world readable' "[ -z \"\$(find $CONF -type f -perm -004)\" ]"
expect 'the default site listens on the LAN address only' \
    "grep -c 'ipaddr = ' $CONF/sites-enabled/default | grep -q '^1\$' && grep -q 'ipaddr = 10.0.0.1' $CONF/sites-enabled/default"
configctl kazuhahubwifiproxy check > "$W/check.json"
expect 'the configuration check passes' "grep -q '\"result\":\"ok\"' '$W/check.json'"
expect 'the check output carries no secret' \
    "! grep -q -F -e '$AP' -e \"\$(cat '$SECRETS/c1')\" -e \"\$(cat '$SECRETS/c2')\" -e '$USERPASS' '$W/check.json'"
expect 'the certificates are exported: leaf then authority in server.pem' \
    "[ \"\$(grep -c 'BEGIN CERTIFICATE' $CONF/certs/server.pem)\" = 2 ] && grep -q 'PRIVATE KEY' $CONF/certs/server.key && [ -s $CONF/certs/ca.pem ]"
expect 'the key file is 0640 root:freeradius' "[ \"\$(stat -f '%Lp %Su:%Sg' $CONF/certs/server.key)\" = '640 root:freeradius' ]"
expect 'the exported key belongs to the exported certificate' \
    "[ \"\$(openssl x509 -in $CONF/certs/server.pem -noout -pubkey | sha256 -q)\" = \"\$(openssl pkey -in $CONF/certs/server.key -pubout | sha256 -q)\" ]"
SUM_BEFORE="$(sha256 -q "$CONF/certs/server.key")"
configctl kazuhahubwifiproxy check >/dev/null
expect 'a second export leaves unchanged certificates alone' "[ \"\$(sha256 -q $CONF/certs/server.key)\" = '$SUM_BEFORE' ]"

# --- conflicts -----------------------------------------------------------------
printf 'radiusd_enable="YES"\n' > /etc/rc.conf.d/radiusd
configctl kazuhahubwifiproxy lastcheck > "$W/last.json"
expect 'the status names the stock radiusd service as a conflict' "grep -q 'radiusd_enable' '$W/last.json'"
refuse 'the rc script refuses to start next to the stock radiusd service' 'service kazuhahub_wifiproxy onestart'
expect 'nothing was started by the refused start' '! pgrep -x radiusd'
rm -f /etc/rc.conf.d/radiusd
expect 'the conflict is gone once the stock service is off' 'sh /usr/local/opnsense/scripts/kazuhahub-wifi-proxy/check.sh conflicts'

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
expect 'the fake central servers have valid configurations' \
    "/usr/local/sbin/radiusd -C -d '$W/central1' -n radiusd && /usr/local/sbin/radiusd -C -d '$W/central2' -n radiusd"
/usr/local/sbin/radiusd -d "$W/central1" -n radiusd
/usr/local/sbin/radiusd -d "$W/central2" -n radiusd
sleep 1
expect 'both fake central servers listen on their loopback aliases' \
    "sockstat -4 -l | grep -q '127.0.0.2:1814 ' && sockstat -4 -l | grep -q '127.0.0.3:1814 '"

expect 'the settings are enabled through the model' \
    "php '$W/seed.php' settings '$SECRETS' enabled | grep -q '\"saved\"'"
# An uplink gateway on the LAN added after the last render: the start renders again, finds no
# usable listen interface and refuses, and the status says why.
configctl template reload OPNsense/KazuhaHubWifiProxy >/dev/null
expect 'the rendered default site listens on the LAN before the gateway exists' "grep -q 'ipaddr = 10.0.0.1' $CONF/sites-enabled/default"
php "$W/seed.php" gateway add >/dev/null
refuse 'a start after a gateway was added to the LAN is refused' 'service kazuhahub_wifiproxy onestart'
expect 'the start rendered the configuration again and the listener is gone' \
    "grep -q 'no-listen-address' $CONF/sites-enabled/default && ! grep -q 'ipaddr = 10.0.0.1' $CONF/sites-enabled/default"
configctl kazuhahubwifiproxy lastcheck > "$W/last-refused.json"
expect 'the status records why the start was refused' "grep -q 'No usable listen interface' '$W/last-refused.json' && grep -q '\"result\":\"failed\"' '$W/last-refused.json'"
expect 'nothing runs after the refused start' '! pgrep -u freeradius -x radiusd'
php "$W/seed.php" gateway remove >/dev/null
# What the reconfigure API does: stop, regenerate, start.
configctl kazuhahubwifiproxy stop >/dev/null
expect 'the reconfigure flow regenerates the configuration' \
    "[ \"\$(configctl template reload OPNsense/KazuhaHubWifiProxy)\" = OK ]"
expect 'the generated rc setting turns YES' "grep -q '^kazuhahub_wifiproxy_enable=\"YES\"' /etc/rc.conf.d/kazuhahub_wifiproxy"
expect 'the service starts through configd' "[ \"\$(configctl kazuhahubwifiproxy start)\" = OK ]"
sleep 2
expect 'the service is running' 'configctl kazuhahubwifiproxy status | grep -q "is running"'
expect 'the pid file exists and names a live process' \
    "kill -0 \$(cat /var/run/kazuhahub-wifi-proxy/radiusd.pid)"
# The proxy socket toward the central servers is an ephemeral wildcard UDP socket: it is only ever
# used for outbound requests to the configured central servers (no listen section opens it to
# clients), which is why the check pins it to one socket that is not on port 1812.
# Only the proxy's own sockets: the fake central servers are radiusd processes too.
sockstat -4 -l | awk -v pid="$(cat /var/run/kazuhahub-wifi-proxy/radiusd.pid)" '$3 == pid {print $6}' | sort > "$W/sockets.txt"
expect 'it listens on the LAN address and the loopback tunnel; the only other socket is the one outbound proxy socket on an ephemeral port, never 1812 or the WAN address' \
    "[ \"\$(grep -c '^\\*:' '$W/sockets.txt')\" = 1 ] && ! grep -q -E '^\\*:(1812|18120)\$' '$W/sockets.txt' && ! grep -q -E '198\\.23\\.159\\.179|^0\\.0\\.0\\.0:' '$W/sockets.txt' && grep -q '^10\\.0\\.0\\.1:1812\$' '$W/sockets.txt' && grep -q '^127\\.0\\.0\\.1:18120\$' '$W/sockets.txt' && [ \"\$(wc -l < '$W/sockets.txt' | tr -d ' ')\" = 3 ]"
expect 'the control socket lives in a 0750 directory owned by the daemon' \
    "[ -S /var/run/kazuhahub-wifi-proxy/radiusd.sock ] && [ \"\$(stat -f '%Su' /var/run/kazuhahub-wifi-proxy)\" = freeradius ] && [ \"\$(stat -f '%Lp' /var/run/kazuhahub-wifi-proxy)\" = 750 ]"
expect 'the daemon runs as the freeradius user' \
    "[ \"\$(ps -o user= -p \$(cat /var/run/kazuhahub-wifi-proxy/radiusd.pid) | tr -d ' ')\" = freeradius ]"

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
configctl kazuhahubwifiproxy homeservers > "$W/homeservers.json"
expect 'homeservers reports both central servers from radmin' \
    "grep -q '\"status\":\"ok\"' '$W/homeservers.json' && grep -q '127.0.0.2' '$W/homeservers.json' && grep -q '127.0.0.3' '$W/homeservers.json'"
expect 'the first central server is alive after serving requests' \
    "grep -q '\"address\":\"127.0.0.2\",\"port\":1814,\"state\":\"alive\"' '$W/homeservers.json'"
php "$W/seed.php" status > "$W/status.json"
expect 'the service controller reports running, the listen address and the last check' \
    "grep -q '\"status\":\"running\"' '$W/status.json' && grep -q '10.0.0.1' '$W/status.json' && grep -q '\"result\":\"ok\"' '$W/status.json'"
expect 'the service controller carries no secret' \
    "! grep -q -F -e '$AP' -e '$USERPASS' '$W/status.json'"
expect 'the log holds no password and no secret' \
    "! grep -q -e '$USERPASS' -e 'wrong-password-for-the-test' -e '$AP' -e \"\$(cat '$SECRETS/c1')\" -e \"\$(cat '$SECRETS/c2')\" /var/log/kazuhahub-wifi-proxy/radius.log"
expect 'the log records the accept and the reject' \
    "grep -q 'Login OK' /var/log/kazuhahub-wifi-proxy/radius.log && grep -q 'Login incorrect' /var/log/kazuhahub-wifi-proxy/radius.log"

expect 'a reload keeps the service serving' \
    "[ \"\$(configctl kazuhahubwifiproxy reload)\" = OK ] && sleep 1 && run_eapol '$W/accept.conf' && grep -q '^SUCCESS' '$W/eapol.out'"

# --- certificate sync from a fake central node ---------------------------------
# A throwaway "central" PKI served over plain HTTP on a loopback alias, as the real node serves its
# set on the tailnet. The sync must install it, restart the service on it, ignore every bad set
# while the service keeps running, and fall back to the trust store when it is switched off.
expect 'the certificate sync is scheduled every 6 hours in the crontab' \
    "grep -q 'configctl -d kazuhahubwifiproxy certsync' /var/cron/tabs/root"
CPKI="$W/central-pki"
DIST="$W/dist"
mkdir -p "$CPKI" "$DIST"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$CPKI/ca.key" -out "$CPKI/ca.pem" -days 3 \
    -subj '/O=Lifecycle/CN=Lifecycle Central CA' \
    -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign >/dev/null 2>&1
central_leaf() {
    printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth\n' "$2" > "$CPKI/$1.ext"
    openssl req -newkey "rsa:${3:-2048}" -nodes -keyout "$CPKI/$1.key" -out "$CPKI/$1.csr" -subj '/CN=radius.example.test' >/dev/null 2>&1
    openssl x509 -req -in "$CPKI/$1.csr" -CA "$CPKI/ca.pem" -CAkey "$CPKI/ca.key" -CAcreateserial \
        -out "$CPKI/$1.pem" -days 3 -extfile "$CPKI/$1.ext" >/dev/null 2>&1
}
central_leaf good 'DNS:radius.example.test'
central_leaf renewed 'DNS:radius.example.test'
# Passes every check of the sync, but radiusd refuses a key this small: the rollback case.
central_leaf weak 'DNS:radius.example.test' 512
central_leaf wrongname 'DNS:radius.example.invalid'
# publish LEAF [corrupt]: the set the fake central node serves, with sums as sha256sum writes them.
publish() {
    cp "$CPKI/$1.pem" "$DIST/server.pem"; cp "$CPKI/$1.key" "$DIST/server.key"; cp "$CPKI/ca.pem" "$DIST/ca.pem"
    : > "$DIST/SHA256SUMS"
    for f in server.pem server.key ca.pem; do printf '%s  %s\n' "$(sha256 -q "$DIST/$f")" "$f" >> "$DIST/SHA256SUMS"; done
    [ "${2:-}" = corrupt ] && printf '\n' >> "$DIST/server.key"
    return 0
}
publish good
# One Basic account, as the real node has one per site; generated here and never printed.
printf 'lifecycle-site\n' > "$SECRETS/sync-user"
openssl rand -hex 16 > "$SECRETS/sync-password"
cat > "$W/node.php" <<'NODEEOF'
<?php
$secrets = getenv('NODE_SECRETS');
if (($_SERVER['PHP_AUTH_USER'] ?? '') !== trim(file_get_contents("$secrets/sync-user"))
    || ($_SERVER['PHP_AUTH_PW'] ?? '') !== trim(file_get_contents("$secrets/sync-password"))) {
    header('WWW-Authenticate: Basic realm="certs"', true, 401);
    return true;
}
$name = ltrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if (!in_array($name, ['SHA256SUMS', 'server.pem', 'server.key', 'ca.pem'], true)) {
    http_response_code(404);
    return true;
}
readfile(getenv('NODE_DIST') . '/' . $name);
return true;
NODEEOF
start_node() {
    NODE_SECRETS="$SECRETS" NODE_DIST="$DIST" php -S 127.0.0.4:8080 "$W/node.php" >/dev/null 2>&1 &
    SOURCE_PID=$!
    sleep 1
}
ifconfig lo0 alias 127.0.0.4/32
start_node
refuse 'the model refuses a source host that is not a host name or address' \
    "php '$W/seed.php' certsync 'not a host!' 8080 on"
refuse 'the model refuses a source port out of range' "php '$W/seed.php' certsync 127.0.0.4 70000 on"
expect 'the sync source is saved through the model, without an account first' "php '$W/seed.php' certsync 127.0.0.4 8080 on | grep -q '\"saved\"'"
radius_pid() { cat /var/run/kazuhahub-wifi-proxy/radiusd.pid 2>/dev/null; }
PID_BEFORE="$(radius_pid)"
configctl kazuhahubwifiproxy certsync > "$W/sync0.json"
expect 'without an account nothing is pulled and the service is left alone' \
    "grep -q '\"run\":\"skipped\"' '$W/sync0.json' && [ ! -d /var/db/kazuhahub-wifi-proxy/certsync/current ] && [ \"\$(radius_pid)\" = '$PID_BEFORE' ]"
cp "$SECRETS/sync-password" "$SECRETS/sync-password.real"
openssl rand -hex 16 > "$SECRETS/sync-password"
php "$W/seed.php" certsync 127.0.0.4 8080 on "$SECRETS" >/dev/null
mv "$SECRETS/sync-password.real" "$SECRETS/sync-password"
configctl kazuhahubwifiproxy certsync > "$W/sync01.json"
expect 'a refused account (HTTP 401) keeps the current set' \
    "grep -q 'HTTP 401' '$W/sync01.json' && [ ! -d /var/db/kazuhahub-wifi-proxy/certsync/current ] && [ \"\$(radius_pid)\" = '$PID_BEFORE' ]"
expect 'the account is saved through the model' "php '$W/seed.php' certsync 127.0.0.4 8080 on '$SECRETS' | grep -q '\"saved\"'"
configctl kazuhahubwifiproxy certsync > "$W/sync1.json"
sleep 2
expect 'the first sync installs the central set' \
    "grep -q '\"result\":\"updated\"' '$W/sync1.json' && grep -q '\"in_use\":\"central set\"' '$W/sync1.json'"
expect 'the service was restarted on it and runs' \
    "[ -n \"\$(radius_pid)\" ] && [ \"\$(radius_pid)\" != '$PID_BEFORE' ] && configctl kazuhahubwifiproxy status | grep -q 'is running'"
expect 'the daemon reads the central certificate and authority' \
    "openssl x509 -in $CONF/certs/server.pem -noout -issuer | grep -q 'Lifecycle Central CA' && cmp -s $CONF/certs/ca.pem '$CPKI/ca.pem'"
expect 'the cache is private: 0700 directories, 0600 files' \
    "[ \"\$(stat -f '%Lp' /var/db/kazuhahub-wifi-proxy/certsync)\" = 700 ] && [ \"\$(stat -f '%Lp' /var/db/kazuhahub-wifi-proxy/certsync/current)\" = 700 ] && [ -z \"\$(find /var/db/kazuhahub-wifi-proxy -type f ! -perm 0600)\" ]"
cp "$W/accept.conf" "$W/accept-central.conf"
sed -i '' "s#ca_cert=\"$PKI/ca.pem\"#ca_cert=\"$CPKI/ca.pem\"#" "$W/accept-central.conf"
expect 'EAP-TTLS succeeds against the central authority' \
    "run_eapol '$W/accept-central.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
expect 'a client that trusts only the old authority now refuses the server' \
    "! run_eapol '$W/accept.conf' 10"
PID_SYNCED="$(radius_pid)"
CERT_SYNCED="$(sha256 -q $CONF/certs/server.pem)"
configctl kazuhahubwifiproxy certsync > "$W/sync2.json"
expect 'an unchanged set is left alone and the service is not restarted' \
    "grep -q '\"result\":\"unchanged\"' '$W/sync2.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ]"
publish wrongname
configctl kazuhahubwifiproxy certsync > "$W/sync3.json"
expect 'a set for another name is refused and the service keeps running on the current one' \
    "grep -q '\"result\":\"failed\"' '$W/sync3.json' && grep -q 'radius.example.test' '$W/sync3.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ] && [ \"\$(sha256 -q $CONF/certs/server.pem)\" = '$CERT_SYNCED' ]"
# A renewed set whose key was changed after the sums were written.
publish renewed corrupt
configctl kazuhahubwifiproxy certsync > "$W/sync4.json"
expect 'a file that does not match its sum is refused' \
    "grep -q 'does not match SHA256SUMS' '$W/sync4.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ]"
# A set radiusd cannot load: rolled back, the running daemon untouched, the previous files back.
publish weak
configctl kazuhahubwifiproxy certsync > "$W/sync45.json"
expect 'a set radiusd refuses is rolled back without restarting the service' \
    "grep -q 'rolled back: the configuration check failed' '$W/sync45.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ] && [ \"\$(sha256 -q $CONF/certs/server.pem)\" = '$CERT_SYNCED' ] && run_eapol '$W/accept-central.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
configctl kazuhahubwifiproxy certsync > "$W/sync46.json"
expect 'the rolled-back set is not tried again at once' "grep -q 'less than a day' '$W/sync46.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ]"
expect 'the configuration check is OK again after the rollback' "configctl kazuhahubwifiproxy lastcheck | grep -q '\"result\":\"ok\"'"
kill "$SOURCE_PID" 2>/dev/null
wait "$SOURCE_PID" 2>/dev/null
configctl kazuhahubwifiproxy certsync > "$W/sync5.json"
expect 'an unreachable central node keeps the current set' \
    "grep -q 'did not answer' '$W/sync5.json' && [ \"\$(radius_pid)\" = '$PID_SYNCED' ] && run_eapol '$W/accept-central.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
expect 'every refusal was logged, without key material or the password' \
    "[ \"\$(grep -c 'kazuhahub-wifi-proxy.*cert-sync:' /var/log/system/latest.log)\" -ge 6 ] && ! grep 'cert-sync:' /var/log/system/latest.log | grep -q -F -e 'BEGIN' -e 'PRIVATE' -e \"\$(cat '$SECRETS/sync-password')\""
expect 'the sync output carries no key material and no password' "! grep -q -F -e 'BEGIN' -e 'PRIVATE' -e \"\$(cat '$SECRETS/sync-password')\" '$W'/sync*.json"
sockstat -4 -l | awk -v pid="$(radius_pid)" '$3 == pid {print $6}' | sort > "$W/sockets-synced.txt"
expect 'the sync opened nothing new: the same three sockets' \
    "[ \"\$(wc -l < '$W/sockets-synced.txt' | tr -d ' ')\" = 3 ] && grep -q '^10\\.0\\.0\\.1:1812\$' '$W/sockets-synced.txt' && ! sockstat -4 -l | grep -q ':8080 '"
php "$W/seed.php" status > "$W/status-synced.json"
expect 'the service controller never hands the sync password out' "! grep -q -F \"\$(cat '$SECRETS/sync-password')\" '$W/status-synced.json'"
expect 'the service controller reports the central set in use' \
    "grep -q '\"in_use\":\"central set\"' '$W/status-synced.json' && grep -q '127.0.0.4:8080' '$W/status-synced.json'"
expect 'with the sync off the restart falls back to the trust store' \
    "php '$W/seed.php' certsync 127.0.0.4 8080 off | grep -q '\"saved\"' && [ \"\$(configctl kazuhahubwifiproxy restart)\" = OK ] && sleep 2 && openssl x509 -in $CONF/certs/server.pem -noout -issuer | grep -q 'Lifecycle Test CA' && run_eapol '$W/accept.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
expect 'a sync that is off does nothing' \
    "configctl kazuhahubwifiproxy certsync | grep -q '\"enabled\":false'"
# Back on, with the daemon still on the trust store set: the next run notices and installs the accepted set again.
start_node
publish good
php "$W/seed.php" certsync 127.0.0.4 8080 on >/dev/null
configctl kazuhahubwifiproxy certsync > "$W/sync6.json"
sleep 2
expect 'a daemon left on the trust store set gets the accepted central set again' \
    "grep -q '\"run\":\"reinstalled\"' '$W/sync6.json' && openssl x509 -in $CONF/certs/server.pem -noout -issuer | grep -q 'Lifecycle Central CA' && run_eapol '$W/accept-central.conf' && grep -q '^SUCCESS' '$W/eapol.out'"
kill "$SOURCE_PID" 2>/dev/null
wait "$SOURCE_PID" 2>/dev/null
php "$W/seed.php" certsync 127.0.0.4 8080 off >/dev/null
configctl kazuhahubwifiproxy restart >/dev/null
sleep 2
ifconfig lo0 -alias 127.0.0.4

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
configctl kazuhahubwifiproxy homeservers > "$W/homeservers-failover.json"
expect 'fail-over: the stopped central server is reported zombie or dead, the second alive' \
    "grep -q -E '\"address\":\"127.0.0.2\",\"port\":1814,\"state\":\"(zombie|dead)\"' '$W/homeservers-failover.json' && grep -q '\"address\":\"127.0.0.3\",\"port\":1814,\"state\":\"alive\"' '$W/homeservers-failover.json'"

# --- stop, restart, stop -------------------------------------------------------
expect 'the service stops through configd' "[ \"\$(configctl kazuhahubwifiproxy stop)\" = OK ]"
sleep 1
expect 'after the stop the daemon and its sockets are gone' \
    '! pgrep -f "radiusd -d /usr/local/etc/kazuhahub-wifi-proxy" && [ ! -e /var/run/kazuhahub-wifi-proxy/radiusd.sock ]'
expect 'a stopped service reports no central servers' \
    "configctl kazuhahubwifiproxy homeservers | grep -q '\"status\":\"stopped\"'"
expect 'the service restarts through configd' "[ \"\$(configctl kazuhahubwifiproxy restart)\" = OK ] && sleep 2 && configctl kazuhahubwifiproxy status | grep -q 'is running'"

# --- deinstall with the service running ---------------------------------------
kill "$(cat "$W/central2/run/radiusd.pid")" 2>/dev/null
pkg delete -y os-kazuhahub-wifi-proxy > "$W/deinstall.out" 2>&1
expect 'deinstall removes the package' '! pkg info -e os-kazuhahub-wifi-proxy'
expect 'deinstall leaves nothing running' '! pgrep -x radiusd'
expect 'deinstall drops the certificate sync from the crontab' "! grep -q 'kazuhahubwifiproxy certsync' /var/cron/tabs/root"
for path in $CONF /var/db/kazuhahub-wifi-proxy /var/run/kazuhahub-wifi-proxy /var/log/kazuhahub-wifi-proxy /etc/rc.conf.d/kazuhahub_wifiproxy \
        /usr/local/etc/rc.d/kazuhahub_wifiproxy /usr/local/opnsense/scripts/kazuhahub-wifi-proxy \
        /usr/local/opnsense/service/templates/OPNsense/KazuhaHubWifiProxy /usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubWifiProxy; do
    expect "deinstall removed $path" "[ ! -e '$path' ]"
done
expect 'the settings stay in config.xml' "grep -q 'kazuhahubwifiproxy' /conf/config.xml"
expect 'the stock FreeRADIUS configuration was never touched' \
    "[ \"\$(find /usr/local/etc/raddb -type f -exec sha256 -q {} + | sort | sha256 -q)\" = '$RADDB_SUM' ]"
expect 'freeradius3 is still installed after the plugin is gone' 'pkg info -e freeradius3'

# --- put the host back ---------------------------------------------------------
pkill -f "radiusd -d $W/central" 2>/dev/null
ifconfig lo0 -alias 127.0.0.2
ifconfig lo0 -alias 127.0.0.3
ifconfig lo0 -alias 127.0.0.4 2>/dev/null
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
expect 'the loopback aliases are gone' '! ifconfig lo0 | grep -q -E "127\.0\.0\.[234] "'
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
