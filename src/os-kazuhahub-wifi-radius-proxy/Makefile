PKG_NAME?=	os-kazuhahub-wifi-radius-proxy
VERSION?=	1.2.1
TARGET_ABI?=	FreeBSD:*:amd64

.PHONY: package install clean

package:
	TARGET_ABI="$(TARGET_ABI)" PKG_NAME="$(PKG_NAME)" VERSION="$(VERSION)" ./build.sh

install: package
	pkg add -f "dist/$(PKG_NAME).pkg"

clean:
	rm -rf work/freebsd-pkg
