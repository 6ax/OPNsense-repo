#!/bin/sh
set -eu

# The repository was renamed to Kazuha Hub and its hook is now kazuhahub.sh,
# installed by os-kazuhahub-repo.  Plugins built before the rename still call
# this path after they install, so the restore manifest verbs are forwarded.
# Core runs every hook in this directory with no arguments when it configures
# the firmware; that run belongs to kazuhahub.sh alone, so it does nothing here.
hook="${KAZUHA_REPO_ROOT:-}/usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh"
case "${1:-}" in
'')
    exit 0
    ;;
mirror|manifest|forget)
    [ -f "$hook" ] || exit 0
    exec /bin/sh "$hook" "$@"
    ;;
*)
    echo 'usage: kazuha.sh [mirror | manifest | forget os-<plugin>]' >&2
    exit 1
    ;;
esac
