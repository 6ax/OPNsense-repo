#!/bin/sh
# Clear pkg's automatic flag on os-kazuhahub-wifi-proxy, so that removing the
# transitional os-kazuhahub-radius never lets the firmware's autoremove take the
# proxy with it. Started detached by the install hook, it waits until the pkg
# transaction that installed the package has released its lock and gives up
# after ten minutes; "once", from the hourly cron job, tries a single time, so a
# reboot or a slow transaction only delays it.
set -u
tries=120
[ "${1:-}" = once ] && tries=1
i=0
while [ "$i" -lt "$tries" ]; do
    case "$(pkg query %a os-kazuhahub-wifi-proxy 2>/dev/null)" in
    0) exit 0 ;;
    1) pkg set -y -A 0 os-kazuhahub-wifi-proxy >/dev/null 2>&1 && exit 0 ;;
    esac
    i=$((i + 1))
    [ "$i" -lt "$tries" ] && sleep 5
done
exit 1
