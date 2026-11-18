"""Reject substituted source archives before upstream build code can execute."""
import gzip
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tarfile
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location(
    'upstream_source', Path(__file__).resolve().parents[1] / 'packaging/upstream_source.py')
source = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(source)


class UpstreamSourceTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.archive = Path(self.work.name) / 'source.tar.gz'
        self.lock = {'repository': 'Example/router', 'tag': 'v1.2.3', 'commit': 'a' * 40,
                     'url': 'https://github.com/Example/router/releases/download/v1.2.3/source.tar.gz',
                     'sha256': '0' * 64, 'root': 'router-source', 'plugin': 'agents/plugin'}

    def write(self, entries=None):
        entries = entries if entries is not None else [('router-source/agents/plugin/build.py', b'raise SystemExit(91)')]
        with tarfile.open(self.archive, 'w:gz') as archive:
            for entry in entries:
                if isinstance(entry, tarfile.TarInfo):
                    archive.addfile(entry)
                else:
                    name, content = entry
                    member = tarfile.TarInfo(name)
                    member.size = len(content)
                    archive.addfile(member, io.BytesIO(content))
        self.pin()

    def pin(self):
        self.lock['sha256'] = hashlib.sha256(self.archive.read_bytes()).hexdigest()

    def test_returns_exact_source_without_executing_build_script(self):
        self.write()
        self.assertEqual(source.read_source(self.archive, self.lock),
                         {'agents/plugin/build.py': b'raise SystemExit(91)'})

    def test_substituted_archive_fails_before_tar_parsing(self):
        self.write()
        self.archive.write_bytes(b'substitute')
        with patch.object(source, 'tar_stream', side_effect=AssertionError('parsed unverified bytes')):
            with self.assertRaisesRegex(ValueError, 'SHA256'):
                source.read_source(self.archive, self.lock)

    def test_mutable_or_unbound_source_identity_is_rejected(self):
        self.write()
        mutations = [{'tag': 'main'}, {'commit': 'a' * 7}, {'sha256': ''},
                     {'url': self.lock['url'] + '?download=1'},
                     {'url': self.lock['url'].replace('https:', 'http:')},
                     {'url': self.lock['url'].replace('Example/router', 'Other/router')},
                     {'root': '../router-source'}, {'plugin': '/agents/plugin'},
                     {'unexpected': 'ignored'}, {'commit': None}]
        for mutation in mutations:
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                source.read_source(self.archive, dict(self.lock, **mutation))

    def test_duplicate_json_lock_key_is_rejected(self):
        self.write()
        path = Path(self.work.name) / 'source.json'
        path.write_text(json.dumps(self.lock)[:-1] + ', "tag": "v9.9.9"}', encoding='utf-8')
        with self.assertRaisesRegex(ValueError, 'duplicate key'):
            source.load_lock(path)

    def test_decompression_limit_is_checked_before_parsing_tar_headers(self):
        self.archive.write_bytes(gzip.compress(b'x' * 100000))
        self.pin()
        with patch.object(source, 'MAX_TAR_BYTES', 4096), patch.object(
                source.tarfile, 'open', side_effect=AssertionError('parsed oversized tar')):
            with self.assertRaisesRegex(ValueError, 'tar size limit'):
                source.read_source(self.archive, self.lock)

    def test_unsafe_and_noncanonical_names_are_rejected(self):
        for name in ['../outside', '/absolute', 'router-source/../escape',
                     'router-source//double', 'router-source/./dot',
                     'router-source/back\\slash', 'router-source/C:drive',
                     'other-root/agents/plugin/build.py', 'router-source/tab\tname',
                     'router-source/' + 'x' * 256]:
            with self.subTest(name=name):
                self.write([(name, b'bad')])
                with self.assertRaises(ValueError):
                    source.read_source(self.archive, self.lock)

    def test_links_devices_fifo_and_sparse_entries_are_rejected(self):
        for kind in [tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE,
                     tarfile.BLKTYPE, tarfile.FIFOTYPE, tarfile.GNUTYPE_SPARSE]:
            member = tarfile.TarInfo('router-source/agents/plugin/unsafe')
            member.type = kind
            member.linkname = '/outside'
            self.write([member])
            with self.subTest(kind=kind), self.assertRaises(ValueError):
                source.read_source(self.archive, self.lock)

    def test_duplicate_paths_and_file_directory_collisions_are_rejected(self):
        for entries in [[('router-source/agents/plugin/file', b'a')] * 2,
                        [('router-source/agents', b'a'), ('router-source/agents/plugin/file', b'b')],
                        [('router-source/agents/plugin/file', b'b'), ('router-source/agents', b'a')]]:
            self.write(entries)
            with self.subTest(entries=entries), self.assertRaises(ValueError):
                source.read_source(self.archive, self.lock)

    def test_empty_or_missing_plugin_subtree_is_rejected(self):
        for entries in [[], [('router-source/README.md', b'only docs')]]:
            self.write(entries)
            with self.assertRaisesRegex(ValueError, 'plugin subtree'):
                source.read_source(self.archive, self.lock)

    def test_privileged_modes_are_rejected(self):
        member = tarfile.TarInfo('router-source/agents/plugin/privileged')
        member.mode = 0o4755
        self.write([member])
        with self.assertRaisesRegex(ValueError, 'privileged'):
            source.read_source(self.archive, self.lock)

    def test_limits_cover_compressed_members_expanded_bytes_and_tar_headers(self):
        self.write()
        for limit in ['MAX_ARCHIVE_BYTES', 'MAX_SOURCE_BYTES', 'MAX_MEMBERS', 'MAX_TAR_BYTES']:
            with self.subTest(limit=limit), patch.object(source, limit, 0):
                with self.assertRaisesRegex(ValueError, 'limit'):
                    source.read_source(self.archive, self.lock)

    def test_truncated_gzip_is_not_an_accepted_source(self):
        self.write()
        self.archive.write_bytes(self.archive.read_bytes()[:-8])
        self.pin()
        with self.assertRaisesRegex(ValueError, 'complete gzip'):
            source.read_source(self.archive, self.lock)

    def test_valid_gzip_with_invalid_tar_is_rejected(self):
        self.archive.write_bytes(gzip.compress(b'not a tar archive'))
        self.pin()
        with self.assertRaisesRegex(ValueError, 'complete gzip'):
            source.read_source(self.archive, self.lock)

    def test_explicit_root_and_parent_directories_are_allowed(self):
        members = []
        for name in ['router-source', 'router-source/agents', 'router-source/agents/plugin']:
            member = tarfile.TarInfo(name)
            member.type = tarfile.DIRTYPE
            members.append(member)
        members.append(('router-source/agents/plugin/file', b'exact'))
        self.write(members)
        self.assertEqual(source.read_source(self.archive, self.lock), {'agents/plugin/file': b'exact'})


if __name__ == '__main__':
    unittest.main()
