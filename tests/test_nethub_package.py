"""Break independently pinned candidate files, metadata, hooks and build records."""
import copy
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch


SPEC = importlib.util.spec_from_file_location(
    'nethub_package', Path(__file__).resolve().parents[1] / 'packaging/nethub_package.py')
candidate = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(candidate)
PLUGIN = 'agents/opnsense/plugin'
BIN = '/usr/local/opnsense/nethub-agent/bin/'
VERSION_FILE = '/usr/local/opnsense/version/nethub-agent'


def sha(raw):
    return hashlib.sha256(raw).hexdigest()


class NetHubPackageTests(unittest.TestCase):
    def setUp(self):
        self.work = tempfile.TemporaryDirectory()
        self.addCleanup(self.work.cleanup)
        self.root = Path(self.work.name)
        self.archive = self.root / 'source.tar.gz'
        self.package = self.root / 'candidate.pkg'
        self.cache = self.root / 'generated'
        self.cache.mkdir()
        self.record_path = self.root / 'reviewed-build.json'
        self.lock = {'repository': 'KazuhaHub/nethub', 'tag': 'v1.2.3', 'commit': 'a' * 40,
            'url': 'https://github.com/KazuhaHub/nethub/releases/download/v1.2.3/source.tar.gz',
            'sha256': '0' * 64, 'root': 'synthetic-fixture', 'plugin': PLUGIN}
        product = {'product_id': 'os-nethub-agent', 'product_version': '1.2.3',
                   'product_email': 'maintainer@example.com', 'product_website': 'https://github.com/KazuhaHub/nethub'}
        self.copied = {VERSION_FILE: json.dumps(product).encode(),
            '/usr/local/etc/rc.d/nethub_agent_frpc': b'#!/bin/sh\nexit 0\n'}
        self.template = {'name': 'os-nethub-agent', 'origin': 'net/os-nethub-agent', 'version': '@VERSION@',
            'comment': 'Synthetic source fixture', 'maintainer': product['product_email'], 'www': product['product_website'],
            'abi': '@ABI@', 'arch': '@ARCH@', 'prefix': '/usr/local', 'licenselogic': 'single', 'licenses': ['Apache-2.0']}
        build = {PLUGIN + '/' + name: b'fixed build fixture\n' for name in [
            'build.sh', 'build.py', 'frpc_source.py', 'frp_artifact.py', 'frp-artifacts.json',
            'FRP_VERSION', 'frpc-build/go.mod', 'frpc-build/go.sum']}
        build.update({'server/.go-version': b'1.26.11\n', 'deploy/images/licenses.go': b'fixed collector fixture\n',
            PLUGIN + '/build.sh': b'VERSION=1.2.3\nexit 91\n',
            PLUGIN + '/frp-artifacts.json': b'{"version":"0.71.1"}', PLUGIN + '/FRP_VERSION': b'0.71.1\n'})
        self.scripts = {phase: '#!/bin/sh\necho "100% ' + phase + ' fixture"\n' for phase in
                        ['pre-install', 'post-install', 'pre-deinstall', 'post-deinstall']}
        hooks = dict(zip(self.scripts, ['+PRE_INSTALL', '+POST_INSTALL', '+PRE_DEINSTALL', '+POST_DEINSTALL']))
        source_files = dict(build)
        source_files.update({PLUGIN + '/src' + name: raw for name, raw in self.copied.items()})
        source_files[PLUGIN + '/packaging/freebsd/+MANIFEST.in'] = json.dumps(self.template).encode()
        source_files[PLUGIN + '/packaging/freebsd/pkg-descr'] = b'Fixture package description.\n'
        source_files.update({PLUGIN + '/packaging/freebsd/' + hooks[phase]: raw.encode()
                             for phase, raw in self.scripts.items()})
        with tarfile.open(self.archive, 'w:gz') as archive:
            for name, raw in source_files.items():
                self.add(archive, 'synthetic-fixture/' + name, raw)
        self.lock['sha256'] = sha(self.archive.read_bytes())
        elf = b'\x7fELF\x02\x01\x01\x09' + b'\0' * 8 + b'\x02\0\x3e\0synthetic, never executed'
        locks = {name: sha(build[PLUGIN + '/frpc-build/' + name]) for name in ['go.mod', 'go.sum']}
        self.generated = {'frpc': elf, 'FRP_VERSION': b'0.71.1\n', 'FRP_LICENSE': b'Fixture frp license.\n',
            'frpc-licenses/GO-LICENSE': b'Fixture Go license.\n',
            'frpc-licenses/modules.txt': b'example.com/dependency@v1.0.0\ngithub.com/fatedier/frp@v0.71.1\n',
            'frpc-licenses/github.com/fatedier/frp@v0.71.1/LICENSE': b'Fixture frp license.\n',
            'frpc-licenses/example.com/dependency@v1.0.0/LICENSE': b'Fixture dependency license.\n'}
        self.build_receipt = {'compiler': 'go1.26.11', 'frp_version': '0.71.1', 'target': 'freebsd_amd64',
            'tags': ['frpc', 'noweb'], 'dependency_locks': locks, 'binary_sha256': sha(elf)}
        self.generated['FRP_BUILD.json'] = json.dumps(self.build_receipt).encode()
        for name, raw in self.generated.items():
            path = self.cache / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(raw)
        self.record = {'schema_version': 1, 'source_commit': self.lock['commit'],
            'source_archive_sha256': self.lock['sha256'], 'abi': 'FreeBSD:15:amd64',
            'compiler': 'go1.26.11', 'frp_version': '0.71.1',
            'build_input_sha256': {name: sha(raw) for name, raw in build.items()},
            'generated_files': {name: sha(raw) for name, raw in self.generated.items()}}
        self.pin_record()
        self.payload = dict(self.copied, **{BIN + name: raw for name, raw in self.generated.items()})
        self.manifest = dict(self.template, version='1.2.3', abi='FreeBSD:15:amd64',
            arch='freebsd:15:x86:64', desc='Fixture package description.')
        self.write_package()

    @staticmethod
    def add(archive, name, raw, mode=0o644, uid=0, gid=0, uname="", gname=""):
        member = tarfile.TarInfo(name)
        member.size = len(raw)
        member.mode = mode
        member.uid = uid
        member.gid = gid
        member.uname = uname
        member.gname = gname
        archive.addfile(member, io.BytesIO(raw))

    def pin_record(self):
        raw = json.dumps(self.record, sort_keys=True).encode()
        self.record_path.write_bytes(raw)
        self.record_sha = sha(raw)

    def write_package(self, scripts=None, extra=None, wrong_mode=False, compact=None, flatsize=None, wrong_owner=False, file_attributes=None):
        manifest = dict(self.manifest, files={name: '1$' + sha(raw) for name, raw in self.payload.items()},
                        flatsize=sum(map(len, self.payload.values())) if flatsize is None else flatsize,
                        scripts=self.scripts if scripts is None else scripts)
        if file_attributes is not None:
            for name, checksum in manifest['files'].items():
                mode = 0o755 if name in ['/usr/local/etc/rc.d/nethub_agent_frpc', BIN + 'frpc'] else 0o644
                manifest['files'][name] = dict(sum=checksum, uname='root', gname='wheel',
                                              perm=format(mode, '04o'), mtime=0, **file_attributes)
        with tarfile.open(self.package, 'w') as archive:
            self.add(archive, '+MANIFEST', json.dumps(manifest).encode())
            self.add(archive, '+COMPACT_MANIFEST', json.dumps(manifest if compact is None else compact).encode())
            for name, raw in self.payload.items():
                mode = 0o755 if name in ['/usr/local/etc/rc.d/nethub_agent_frpc', BIN + 'frpc'] else 0o644
                owner = (65534, 65534, 'root', 'wheel') if file_attributes is not None else (
                    1000 if wrong_owner else 0, 0, '', '')
                self.add(archive, name.lstrip('/'), raw, 0o644 if wrong_mode else mode, *owner)
            if extra:
                self.add(archive, *extra)

    def verify(self):
        return candidate.verify_candidate(self.package, self.archive, self.lock,
            self.record_path, self.record_sha, self.cache)

    def test_exact_candidate_matches_pinned_source_cache_and_hooks(self):
        result = self.verify()
        self.assertTrue(result['matches_reviewed_inputs_and_bytes'])
        self.assertEqual(result['files_compared'], len(self.payload))
        self.assertEqual(result['hooks_compared'], 4)
        self.assertFalse(result['native_lifecycle_or_release_accepted'])

    def test_declared_package_notices_match_the_pinned_source(self):
        notices = {'LICENSE': b'NetHub license.\n', 'NOTICE': b'NetHub notice.\n'}
        declarations = {
            PLUGIN + '/packaging/freebsd/notice-files': b'LICENSE\nNOTICE\n',
            **notices,
        }
        with tarfile.open(self.archive) as archive:
            members = {item.name: archive.extractfile(item).read() for item in archive}
        with tarfile.open(self.archive, 'w:gz') as archive:
            for name, raw in members.items():
                self.add(archive, name, raw)
            for name, raw in declarations.items():
                self.add(archive, 'synthetic-fixture/' + name, raw)
        self.lock['sha256'] = self.record['source_archive_sha256'] = sha(self.archive.read_bytes())
        self.pin_record()
        prefix = '/usr/local/share/licenses/os-nethub-agent/'
        self.payload.update({prefix + name: raw for name, raw in notices.items()})
        self.write_package()
        self.assertEqual(self.verify()['files_compared'], len(self.payload))
        self.payload[prefix + 'NOTICE'] = b'Replaced notice.\n'
        self.write_package()
        with self.assertRaises(ValueError):
            self.verify()

    def test_native_libpkg_file_metadata_is_validated(self):
        self.write_package(file_attributes={})
        self.assertTrue(self.verify()['matches_reviewed_inputs_and_bytes'])
        self.write_package(file_attributes={'unexpected': True})
        with self.assertRaisesRegex(ValueError, 'per-file manifest attributes'):
            self.verify()

    def test_registry_can_check_generated_bytes_against_independent_record(self):
        def verify_candidate_outputs():
            return candidate.verify_candidate(self.package, self.archive, self.lock,
                self.record_path, self.record_sha, generated_directory=None)

        self.assertTrue(verify_candidate_outputs()['matches_reviewed_inputs_and_bytes'])
        self.payload[BIN + 'frpc'] += b'altered'
        self.write_package()
        with self.assertRaisesRegex(ValueError, 'differs from reviewed bytes'):
            verify_candidate_outputs()

    def test_cli_reports_scoped_candidate_verification(self):
        lock = self.root / 'source-lock.json'
        lock.write_text(json.dumps(self.lock), encoding='utf-8')
        result = subprocess.run([sys.executable, '-B', str(Path(candidate.__file__)),
            '--package', str(self.package), '--archive', str(self.archive), '--source-lock', str(lock),
            '--build-record', str(self.record_path), '--build-record-sha256', self.record_sha,
            '--generated-directory', str(self.cache)], check=True, capture_output=True, text=True)
        report = json.loads(result.stdout)
        self.assertTrue(report['matches_reviewed_inputs_and_bytes'])
        self.assertFalse(report['native_lifecycle_or_release_accepted'])
        self.assertEqual(report['build_record_sha256'], self.record_sha)

    def test_libpkg_percent_encoded_hooks_are_accepted(self):
        self.write_package(scripts={name: raw.replace('%', '%25') for name, raw in self.scripts.items()})
        self.assertTrue(self.verify()['matches_reviewed_inputs_and_bytes'])

    def test_swapped_copied_binary_or_license_bytes_fail_with_consistent_manifest(self):
        for name in [VERSION_FILE, BIN + 'frpc', BIN + 'frpc-licenses/GO-LICENSE']:
            previous = self.payload[name]
            self.payload[name] = bytes([previous[0] ^ 1]) + previous[1:]
            self.write_package()
            with self.subTest(name=name), self.assertRaisesRegex(ValueError, 'differs from source'):
                self.verify()
            self.payload[name] = previous

    def test_metadata_dependencies_and_side_effects_cannot_drift(self):
        for mutation in [{'version': '9.9.9'}, {'abi': 'FreeBSD:14:amd64'}, {'name': 'os-other'},
                         {'licenses': ['MIT']}, {'deps': {'unexpected': {'origin': 'net/unexpected'}}},
                         {'deps': []}, {'deps': None},
                         {'lua_scripts': {'post-install': 'execute()'}}, {'unknown': 'unreviewed'}]:
            previous = self.manifest
            self.manifest = dict(previous, **mutation)
            self.write_package()
            with self.subTest(mutation=mutation), self.assertRaises(ValueError):
                self.verify()
            self.manifest = previous

    def test_changed_missing_and_extra_hooks_are_rejected(self):
        for scripts in [dict(self.scripts, **{'post-install': 'different hook'}),
                        {name: raw for name, raw in self.scripts.items() if name != 'post-install'},
                        dict(self.scripts, **{'unexpected': 'extra hook'})]:
            self.write_package(scripts=scripts)
            with self.subTest(scripts=scripts), self.assertRaises(ValueError):
                self.verify()

    def test_extra_duplicate_missing_and_nonexecutable_files_are_rejected(self):
        self.write_package(extra=('usr/local/unlisted', b'unlisted'))
        with self.assertRaises(ValueError):
            self.verify()
        self.write_package(extra=(VERSION_FILE.lstrip('/'), self.copied[VERSION_FILE]))
        with self.assertRaises(ValueError):
            self.verify()
        self.write_package(wrong_mode=True)
        with self.assertRaisesRegex(ValueError, 'permissions'):
            self.verify()
        self.write_package(wrong_owner=True)
        with self.assertRaisesRegex(ValueError, 'ownership'):
            self.verify()
        del self.payload[BIN + 'FRP_VERSION']
        self.write_package()
        with self.assertRaises(ValueError):
            self.verify()

    def test_catalog_manifest_cannot_disagree_with_installed_metadata(self):
        self.write_package(compact={'name': 'os-other', 'version': '1.2.3', 'abi': 'FreeBSD:15:amd64'})
        with self.assertRaisesRegex(ValueError, 'Catalog manifest'):
            self.verify()

    def test_declared_flat_size_must_match_the_reconstructed_files(self):
        self.write_package(flatsize=sum(map(len, self.payload.values())) + 1)
        with self.assertRaisesRegex(ValueError, 'flat size'):
            self.verify()

    def test_build_record_requires_its_separate_review_pin_and_source_binding(self):
        self.record_path.write_bytes(self.record_path.read_bytes() + b' ')
        with self.assertRaisesRegex(ValueError, 'reviewed digest'):
            self.verify()
        self.pin_record()
        for key, value in [('source_commit', 'b' * 40), ('source_archive_sha256', 'b' * 64),
                           ('compiler', 'go1.0.0'), ('frp_version', '9.9.9'), ('build_input_sha256', {})]:
            original = copy.deepcopy(self.record)
            self.record[key] = value
            self.pin_record()
            with self.subTest(key=key), self.assertRaisesRegex(ValueError, 'pinned source inputs'):
                self.verify()
            self.record = original
        self.pin_record()

    def test_missing_extra_or_changed_generated_cache_files_are_rejected(self):
        path = self.cache / 'FRP_VERSION'
        path.unlink()
        with self.assertRaisesRegex(ValueError, 'missing'):
            self.verify()
        path.write_bytes(b'wrong')
        with self.assertRaisesRegex(ValueError, 'reviewed bytes'):
            self.verify()
        path.write_bytes(self.generated['FRP_VERSION'])
        (self.cache / 'extra-file').write_bytes(b'extra')
        with self.assertRaisesRegex(ValueError, 'closed inventory'):
            self.verify()

    def test_resealed_wrong_binary_target_receipt_or_license_still_fails(self):
        cases = [('frpc', self.generated['frpc'][:7] + b'\0' + self.generated['frpc'][8:]),
                 ('FRP_BUILD.json', json.dumps(dict(self.build_receipt, compiler='go1.0.0')).encode()),
                 ('FRP_LICENSE', b'a different license')]
        for name, raw in cases:
            previous = self.generated[name]
            (self.cache / name).write_bytes(raw)
            self.record['generated_files'][name] = sha(raw)
            self.pin_record()
            with self.subTest(name=name), self.assertRaises(ValueError):
                self.verify()
            (self.cache / name).write_bytes(previous)
            self.record['generated_files'][name] = sha(previous)
        self.pin_record()

    def test_resealed_module_index_must_cover_exact_license_modules(self):
        name = 'frpc-licenses/modules.txt'
        for raw in [b'github.com/fatedier/frp@v0.71.1\n',
                    self.generated[name] + b'unknown.example/module@v1.0.0\n',
                    self.generated[name].replace(b'\n', b'\r\n')]:
            (self.cache / name).write_bytes(raw)
            self.record['generated_files'][name] = sha(raw)
            self.pin_record()
            with self.subTest(raw=raw), self.assertRaisesRegex(ValueError, 'license|module'):
                self.verify()

    def test_missing_module_index_is_not_a_complete_build_record(self):
        del self.record['generated_files']['frpc-licenses/modules.txt']
        self.pin_record()
        with self.assertRaisesRegex(ValueError, 'complete generated-file inventory'):
            self.verify()

    def test_generated_file_and_total_byte_bounds_are_enforced(self):
        for name in ['MAX_FILE_BYTES', 'MAX_BUILD_BYTES']:
            with self.subTest(name=name), patch.object(candidate, name, 1):
                with self.assertRaisesRegex(ValueError, 'size bound'):
                    self.verify()

    def test_package_size_bound_is_checked_before_archive_parsing(self):
        with patch.object(candidate, 'MAX_PACKAGE_BYTES', 1), patch.object(
                candidate, 'verify_snapshot', side_effect=AssertionError('parsed an oversized package')):
            with self.assertRaisesRegex(ValueError, 'package exceeds its size bound'):
                self.verify()

    def test_candidate_readers_and_digest_use_the_same_snapshot(self):
        original = self.package.read_bytes()
        method = candidate.verify.manifest_of

        def read_manifest(path, *args):
            self.assertNotEqual(path, self.package)
            self.package.write_bytes(b'concurrent replacement of the original path')
            return method(path, *args)

        with patch.object(candidate.verify, 'manifest_of', side_effect=read_manifest):
            report = self.verify()
        self.assertEqual(report['package_sha256'], sha(original))
        self.assertNotEqual(report['package_sha256'], sha(self.package.read_bytes()))


if __name__ == '__main__':
    unittest.main()
