#!/bin/sh
# Build eapol_test from the wpa_supplicant source on FreeBSD, in a scratch
# directory, because the FreeBSD package does not ship it.
#
#   sh build-eapol-test.sh <scratch-dir>
#
# Prints the path of the binary on the last line. Needs gmake and a C compiler.
set -eu
[ "$#" -eq 1 ] || { echo 'usage: build-eapol-test.sh scratch-dir' >&2; exit 1; }
version=2.11
scratch="$1"
mkdir -p "$scratch"
cd "$scratch"
fetch -q -o wpa.tar.gz "https://w1.fi/releases/wpa_supplicant-$version.tar.gz"
tar xzf wpa.tar.gz
cd "wpa_supplicant-$version/wpa_supplicant"
cat > .config <<'EOF'
CONFIG_EAPOL_TEST=y
CONFIG_EAP_TTLS=y
CONFIG_EAP_MSCHAPV2=y
CONFIG_EAP_MD5=y
CONFIG_EAP_TLS=y
CONFIG_TLS=openssl
CONFIG_DRIVER_NONE=y
CONFIG_NO_RANDOM_POOL=y
CONFIG_L2_PACKET=freebsd
CFLAGS += -Wno-error -Wno-deprecated-declarations
EOF
# CLOCK_BOOTTIME is the same constant as CLOCK_MONOTONIC on FreeBSD 15, which
# makes the switch in os_unix.c fail to compile.
sed -i '' '/case CLOCK_BOOTTIME:/d' ../src/utils/os_unix.c
gmake eapol_test CC=cc >/dev/null
echo "$scratch/wpa_supplicant-$version/wpa_supplicant/eapol_test"
