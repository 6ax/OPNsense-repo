"""The pre-rename os-kazuha-repo name, kept as a transitional package."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

PLUGIN = Path(__file__).resolve().parents[1]
STUB = PLUGIN / 'src/usr/local/opnsense/scripts/firmware/repos/kazuha.sh'


class TransitionalTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.hook = self.root / 'usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh'
        self.trace = self.root / 'trace'

    def install_hook(self):
        self.hook.parent.mkdir(parents=True)
        self.hook.write_text('#!/bin/sh\nprintf "%s\\n" "$*" >> "$KAZUHAHUB_REPO_ROOT/trace"\n')

    def run_stub(self, *arguments):
        return subprocess.run(['sh', str(STUB), *arguments], capture_output=True, text=True,
                              env=dict(os.environ, KAZUHAHUB_REPO_ROOT=str(self.root)))

    def test_the_firmware_configure_run_belongs_to_the_renamed_hook(self):
        self.install_hook()
        self.assertEqual(0, self.run_stub().returncode)
        self.assertFalse(self.trace.exists())

    def test_manifest_verbs_are_forwarded_to_the_renamed_hook(self):
        self.install_hook()
        for arguments in (['mirror'], ['manifest'], ['forget', 'os-frp']):
            with self.subTest(arguments=arguments):
                self.assertEqual(0, self.run_stub(*arguments).returncode)
        self.assertEqual(['mirror', 'manifest', 'forget os-frp'], self.trace.read_text().splitlines())

    def test_a_missing_renamed_hook_is_not_an_error_for_the_caller(self):
        self.assertEqual(0, self.run_stub('mirror').returncode)

    def test_unknown_verbs_are_refused(self):
        self.install_hook()
        self.assertNotEqual(0, self.run_stub('configure').returncode)
        self.assertFalse(self.trace.exists())

    def test_the_package_depends_on_the_renamed_repository_plugin(self):
        manifest = (PLUGIN / 'packaging/freebsd/+MANIFEST.in').read_text()
        self.assertIn('deps: { os-kazuhahub-repo: { origin: "opnsense/os-kazuhahub-repo"', manifest)
        metadata = json.loads((PLUGIN / 'src/usr/local/opnsense/version/kazuha-repo').read_text())
        self.assertEqual(('os-kazuha-repo', '1.1.0'), (metadata['product_id'], metadata['product_version']))
        self.assertIn('VERSION="${VERSION:-1.1.0}"', (PLUGIN / 'build.sh').read_text())

    def test_removal_leaves_the_renamed_repository_alone(self):
        body = (PLUGIN / 'packaging/freebsd/+POST_DEINSTALL').read_text()
        self.assertEqual(['exit 0'], [line for line in body.splitlines() if line and not line.startswith('#')])


if __name__ == '__main__':
    unittest.main()
