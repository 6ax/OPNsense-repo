#!/bin/sh
set -eu

PKG_NAME="${PKG_NAME:-os-kazuhahub-radius}"
VERSION="${VERSION:-1.0.0}"
ORIGIN="${ORIGIN:-opnsense/os-kazuhahub-radius}"
COMMENT="${COMMENT:-Kazuha Hub RADIUS proxy: EAP-TTLS Wi-Fi authentication forwarded to central servers}"
MAINTAINER="${MAINTAINER:-https://github.com/KazuhaHub/}"
WWW="${WWW:-https://github.com/KazuhaHub/OPNsense-repo}"
PREFIX="${PREFIX:-/usr/local}"
FORMAT="${FORMAT:-tgz}"
TARGET_ABI="${TARGET_ABI:-${ABI:-FreeBSD:*:amd64}}"
OUTPUT_NAME="${OUTPUT_NAME:-${PKG_NAME}.pkg}"

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)"
WORKDIR="${WORKDIR:-"$SCRIPT_DIR/work/freebsd-pkg"}"
STAGEDIR="$WORKDIR/stage"
METADIR="$WORKDIR/meta"
PLIST="$WORKDIR/pkg-plist"
DISTDIR="${DISTDIR:-"$SCRIPT_DIR/dist"}"

die() {
	echo "error: $*" >&2
	exit 1
}

need_file() {
	[ -e "$SCRIPT_DIR/$1" ] || die "missing required file: $1"
}

command -v pkg >/dev/null 2>&1 || die "pkg command not found. Run this script on FreeBSD/OPNsense."
command -v tar >/dev/null 2>&1 || die "tar command not found."

need_file "src/usr/local/etc/inc/plugins.inc.d/kazuhahubradius.inc"
need_file "src/usr/local/etc/newsyslog.conf.d/kazuhahub-radius.conf"
need_file "src/usr/local/etc/rc.d/kazuhahub_radiusd"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/IndexController.php"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/forms/general.xml"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/forms/dialogCentral.xml"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/Api/ServiceController.php"
need_file "src/usr/local/opnsense/mvc/app/controllers/OPNsense/KazuhaHubRadius/Api/SettingsController.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/General.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/General.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/Migrations/M1_0_0.php"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/Menu/Menu.xml"
need_file "src/usr/local/opnsense/mvc/app/models/OPNsense/KazuhaHubRadius/ACL/ACL.xml"
need_file "src/usr/local/opnsense/mvc/app/views/OPNsense/KazuhaHubRadius/index.volt"
need_file "src/usr/local/opnsense/scripts/kazuhahub-radius/check.sh"
need_file "src/usr/local/opnsense/scripts/kazuhahub-radius/export_certs.php"
need_file "src/usr/local/opnsense/scripts/kazuhahub-radius/homeservers.php"
need_file "src/usr/local/opnsense/service/conf/actions.d/actions_kazuhahubradius.conf"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/+TARGETS"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/radiusd.conf"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/clients.conf"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/proxy.conf"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/policy"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/attr_filter"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/rc.conf.d"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/mods/eap"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/mods/always"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/mods/attr_filter"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/sites/default"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/sites/inner-tunnel"
need_file "src/usr/local/opnsense/service/templates/OPNsense/KazuhaHubRadius/sites/control"
need_file "src/usr/local/opnsense/version/kazuhahub-radius"
need_file "src/usr/local/opnsense/www/js/widgets/KazuhaHubRadius.js"
need_file "src/usr/local/opnsense/www/js/widgets/Metadata/KazuhaHubRadius.xml"
need_file "packaging/freebsd/+MANIFEST.in"
need_file "packaging/freebsd/+PRE_INSTALL"
need_file "packaging/freebsd/+POST_INSTALL"
need_file "packaging/freebsd/+PRE_DEINSTALL"
need_file "packaging/freebsd/+POST_DEINSTALL"
need_file "packaging/freebsd/pkg-descr"

case "$TARGET_ABI" in
	native)
		PKG_ABI="$(env -u ABI pkg config ABI 2>/dev/null || env -u ABI pkg -vv | awk -F'"' '/ABI =/ {print $2; exit}')"
		;;
	FreeBSD:*:amd64)
		PKG_ABI="$TARGET_ABI"
		;;
	*)
		die "unsupported ABI: $TARGET_ABI"
		;;
esac

case "$PKG_ABI" in
	FreeBSD:*:amd64)
		ABI_MAJOR="$(printf '%s\n' "$PKG_ABI" | awk -F: '{print $2}')"
		PKG_ARCH="freebsd:${ABI_MAJOR}:x86:64"
		;;
	*)
		die "unsupported ABI: $PKG_ABI"
		;;
esac

rm -rf "$WORKDIR"
mkdir -p "$STAGEDIR" "$METADIR" "$DISTDIR"

copy_tree() {
	src="$1"
	dst="$2"
	mkdir -p "$dst"
	(cd "$src" && tar --exclude '.DS_Store' --exclude '._*' --exclude '__pycache__' --exclude '*.pyc' --exclude '*.pyo' -cf - .) | (cd "$dst" && tar -xf -)
}

echo "==> Staging files"
copy_tree "$SCRIPT_DIR/src" "$STAGEDIR"
PRODUCT_VERSION="$(sed -n 's/.*"product_version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
	"$STAGEDIR/usr/local/opnsense/version/kazuhahub-radius")"
[ "$PRODUCT_VERSION" = "$VERSION" ] || die "VERSION does not match the committed Kazuha Hub RADIUS product metadata"

find "$STAGEDIR" -type f -exec chmod 0644 {} +
chmod 0755 "$STAGEDIR/usr/local/etc/rc.d/kazuhahub_radiusd"

echo "==> Generating plist"
find "$STAGEDIR" \( -type f -o -type l \) | sed "s#^$STAGEDIR##" | sort > "$PLIST"

FLATSIZE=0
while IFS= read -r file; do
	if [ -L "$STAGEDIR$file" ]; then
		size=0
	else
		size="$(wc -c < "$STAGEDIR$file" | tr -d ' ')"
	fi
	FLATSIZE=$((FLATSIZE + size))
done < "$PLIST"

echo "==> Generating metadata"
sed \
	-e "s#@PKG_NAME@#$PKG_NAME#g" \
	-e "s#@ORIGIN@#$ORIGIN#g" \
	-e "s#@VERSION@#$VERSION#g" \
	-e "s#@COMMENT@#$COMMENT#g" \
	-e "s#@MAINTAINER@#$MAINTAINER#g" \
	-e "s#@WWW@#$WWW#g" \
	-e "s#@ABI@#$PKG_ABI#g" \
	-e "s#@ARCH@#$PKG_ARCH#g" \
	-e "s#@PREFIX@#$PREFIX#g" \
	-e "s#@FLATSIZE@#$FLATSIZE#g" \
	-e "/@DESC@/r $SCRIPT_DIR/packaging/freebsd/pkg-descr" \
	-e "/@DESC@/d" \
	"$SCRIPT_DIR/packaging/freebsd/+MANIFEST.in" > "$METADIR/+MANIFEST"

install -m 0644 "$SCRIPT_DIR/packaging/freebsd/+PRE_INSTALL" "$METADIR/+PRE_INSTALL"
install -m 0644 "$SCRIPT_DIR/packaging/freebsd/+POST_INSTALL" "$METADIR/+POST_INSTALL"
install -m 0644 "$SCRIPT_DIR/packaging/freebsd/+PRE_DEINSTALL" "$METADIR/+PRE_DEINSTALL"
install -m 0644 "$SCRIPT_DIR/packaging/freebsd/+POST_DEINSTALL" "$METADIR/+POST_DEINSTALL"

echo "==> Creating package for $PKG_ABI"
env -u ABI pkg create -f "$FORMAT" -r "$STAGEDIR" -m "$METADIR" -p "$PLIST" -o "$DISTDIR"

CREATED="$DISTDIR/$PKG_NAME-$VERSION.pkg"
if [ -f "$CREATED" ] && [ "$(basename "$CREATED")" != "$OUTPUT_NAME" ]; then
	mv -f "$CREATED" "$DISTDIR/$OUTPUT_NAME"
fi

echo "==> Package: $DISTDIR/$OUTPUT_NAME"
env -u ABI pkg info -F "$DISTDIR/$OUTPUT_NAME" >/dev/null
echo "==> Verified package metadata"
if command -v sha256 >/dev/null 2>&1; then
	sha256 "$DISTDIR/$OUTPUT_NAME"
fi
