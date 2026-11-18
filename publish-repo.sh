#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH="" cd -- "$(dirname -- "$0")" && pwd)"
SITE_DIR="${SITE_DIR:-$SCRIPT_DIR/.site}"
REMOTE="${PUBLISH_REMOTE:-https://github.com/KazuhaHub/OPNsense-repo.git}"
# The former Pages address, kept alive by the owner's user site while routers move
# to the new one. Set PUBLISH_MIRROR to an empty value once it is retired.
MIRROR="${PUBLISH_MIRROR-https://github.com/KKazuhaK/KKazuhaK.github.io.git}"
command -v git >/dev/null 2>&1 || { echo 'git is required on the publishing host' >&2; exit 1; }
for entry in index.html kazuhahub.pub kazuhahub.conf release.json release.sig; do
    [ -f "$SITE_DIR/$entry" ] || { echo "missing signed site asset: $entry" >&2; exit 1; }
done
# Publishing never receives a private key or copies the source checkout.
if find "$SITE_DIR" -type f | LC_ALL=C grep -Eq '\.(key|pem)$|/(AGENTS|CLAUDE|HANDOFF|MEMORY)\.md$'; then
    echo 'refusing to publish local instructions or key files' >&2
    exit 1
fi
if grep -R -l -E -- '-----BEGIN (RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----' "$SITE_DIR" >/dev/null 2>&1; then
    echo 'refusing to publish private key material' >&2
    exit 1
fi
python3 "$SCRIPT_DIR/verify-repo.py" "$SITE_DIR" --source "$SCRIPT_DIR"
python3 -B "$SCRIPT_DIR/tests/run.py"
source_commit="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["source_commit"])' "$SITE_DIR/release.json")"
[ "$(git -C "$SCRIPT_DIR" rev-parse HEAD)" = "$source_commit" ] || { echo 'release source revision does not match the checkout' >&2; exit 1; }
[ -z "$(git -C "$SCRIPT_DIR" status --porcelain)" ] || { echo 'commit tested source before publishing' >&2; exit 1; }
work="$(mktemp -d "${TMPDIR:-/tmp}/mihomo-pages.XXXXXX")"
mirror_work="$work.mirror"
trap 'rm -rf "$work" "$mirror_work"' EXIT HUP INT TERM
initialize_snapshot() {
    snapshot_dir="$1"
    snapshot_remote="$2"
    snapshot_branch="$3"
    branch_ref="refs/heads/$snapshot_branch"
    git init -q --initial-branch="$snapshot_branch" "$snapshot_dir"
    git -C "$snapshot_dir" config user.name "$(git -C "$SCRIPT_DIR" config user.name)"
    git -C "$snapshot_dir" config user.email "$(git -C "$SCRIPT_DIR" config user.email)"
    remote_head="$(git ls-remote --refs "$snapshot_remote" "$branch_ref")"
    if [ -n "$remote_head" ]; then
        git -C "$snapshot_dir" fetch --no-tags --depth=1 "$snapshot_remote" "$branch_ref"
        # Set the parent without populating the index or copying stale site assets.
        git -C "$snapshot_dir" update-ref "$branch_ref" FETCH_HEAD
    fi
}

initialize_snapshot "$work" "$REMOTE" gh-pages
(cd "$SITE_DIR" && tar -cf - .) | (cd "$work" && tar -xf -)
mkdir -p "$work/.github/workflows"
cp "$SCRIPT_DIR/.github/workflows/pages.yml" "$work/.github/workflows/pages.yml"
cp "$SCRIPT_DIR/verify-repo.py" "$work/verify-repo.py"
git -C "$work" add .
git -C "$work" commit -q -s --allow-empty -m 'Publish signed package repository'
git -C "$work" push "$REMOTE" HEAD:refs/heads/gh-pages
echo '==> Signed repository published to gh-pages'

if [ -n "$MIRROR" ]; then
    # The same signed tree, served under /OPNsense-repo/ of the legacy user site.
    # Catalogs and packages are address-independent, so nothing is re-signed.
    initialize_snapshot "$mirror_work" "$MIRROR" main
    (cd "$SITE_DIR" && tar -cf - .) | (cd "$mirror_work" && tar -xf -)
    mkdir -p "$mirror_work/.github/workflows"
    cp "$SCRIPT_DIR/packaging/legacy-pages/pages.yml" "$mirror_work/.github/workflows/pages.yml"
    cp "$SCRIPT_DIR/packaging/legacy-pages/README.md" "$mirror_work/README.md"
    cp "$SCRIPT_DIR/verify-repo.py" "$mirror_work/verify-repo.py"
    git -C "$mirror_work" add .
    git -C "$mirror_work" commit -q -s --allow-empty -m 'Serve the signed repository at its legacy address'
    git -C "$mirror_work" push "$MIRROR" HEAD:refs/heads/main
    echo '==> Signed repository mirrored to the legacy address'
fi
