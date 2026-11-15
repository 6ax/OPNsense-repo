# Pinned upstream source archives

`upstream_source.py` is the archive validation primitive for plugins maintained
in another repository. It does not register a plugin, stage installed files,
execute downloaded code, download assets, or authorize signing or publication.
Existing `plugins.json` records and `verify-repo.py` behavior are unchanged.

A reviewed JSON lock contains exactly seven string fields:

| Field | Required value |
| --- | --- |
| `repository` | GitHub owner/repository |
| `tag` | Versioned release tag, such as `v1.2.3` |
| `commit` | Full lowercase 40-character source revision |
| `url` | HTTPS `github.com/OWNER/REPO/releases/download/TAG/ASSET.tar.gz` |
| `sha256` | Lowercase SHA256 of the compressed archive |
| `root` | Single root directory in that archive |
| `plugin` | Relative plugin subtree under the archive root |

The lock author must independently review the release tag's source revision and
asset provenance. An archive digest binds its bytes; it does not independently
prove the publisher's tag/commit assertion. Do not create a release lock from a
mutable branch, an unreviewed asset, or an unsigned development package.

With a locally cached archive and a reviewed lock:

```sh
python3 packaging/upstream_source.py --archive /path/to/source.tar.gz --lock /path/to/source.json
```

The command verifies the compressed digest before parsing, bounds compressed
bytes, decompression, member count and expanded file bytes, and reports the
complete source file inventory and per-file SHA256. It rejects duplicate paths,
noncanonical paths, root escapes, links, sparse files, devices, privileged modes,
file/directory collisions and a missing plugin subtree. It never extracts files
onto the host. A private temporary spool bounds decompression before tar headers
are parsed and is closed on both success and failure.

The next integration step must reconstruct installed files and lifecycle hooks
from this inventory and compare them with the package, including independently
reviewed build inputs and exact hashes for generated executables and licenses.
NetHub's rebuilt `frpc` requires its compiler/module locks, `FRP_BUILD.json`,
`FRP_VERSION`, binary and license inventory; matching its version string alone is
insufficient. No NetHub release record or placeholder release pins are installed
by this change. Native build, package verification and release approval remain
required before publication.

Validation: `python3 -B -m unittest discover -s tests -p test_upstream_source.py -v`.
The existing shared-suite discovery also includes these tests.
