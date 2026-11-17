"""A former name of os-kazuhahub-wifi-radius-proxy, kept as a transitional package."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

PLUGIN = Path(__file__).resolve().parents[1]
HOOKS = PLUGIN / 'packaging/freebsd'
NAME = PLUGIN.name
SHORT = NAME[len('os-'):]
# The former service of each name: its rc name and what it generated.
FORMER = {
    'os-kazuhahub-radius': ('kazuhahub_radiusd', ['usr/local/etc/kazuhahub-radius', 'var/run/kazuhahub-radius',
                                                  'var/log/kazuhahub-radius']),
    'os-kazuhahub-wifi-proxy': ('kazuhahub_wifiproxy', ['usr/local/etc/kazuhahub-wifi-proxy', 'var/db/kazuhahub-wifi-proxy',
                                                        'var/run/kazuhahub-wifi-proxy', 'var/log/kazuhahub-wifi-proxy']),
}[NAME]

RECORDER = """#!/bin/sh
name="$(basename "$0")"
line="$name"
for argument in "$@"; do line="$line	$argument"; done
printf '%s\\n' "$line" >> "$HOOK_EVENTS"
exit 0
"""


def rewrite(source, root):
    return re.sub(r'(?<![A-Za-z0-9_./-])/(usr/local|var|etc/rc\.conf\.d)(?=/|\b)',
                  lambda match: str(root / match.group(1)), source)


class TransitionalTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='kzr-transitional-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'root'
        self.bin = Path(self.temporary.name) / 'bin'
        self.bin.mkdir()
        self.events = Path(self.temporary.name) / 'events'
        for name in ['service', 'configctl']:
            (self.bin / name).write_text(RECORDER)
            (self.bin / name).chmod(0o755)
        for relative in ['usr/local/opnsense/scripts/firmware/register.php', 'usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh']:
            stub = self.root / relative
            stub.parent.mkdir(parents=True)
            stub.write_text(RECORDER)
            stub.chmod(0o755)

    def put(self, relative, content='x'):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def run_hook(self, name, upgrade=False):
        hook = Path(self.temporary.name) / name
        hook.write_text(rewrite((HOOKS / name).read_text(), self.root))
        env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'HOOK_EVENTS': str(self.events)}
        env.pop('PKG_UPGRADE', None)
        if upgrade:
            env['PKG_UPGRADE'] = '1'
        return subprocess.run(['sh', str(hook)], capture_output=True, text=True, env=env, timeout=20)

    def calls(self):
        return [line.split('\t') for line in self.events.read_text().splitlines()] if self.events.exists() else []

    def test_the_package_depends_on_the_renamed_proxy_and_ships_only_its_version(self):
        manifest = (HOOKS / '+MANIFEST.in').read_text()
        self.assertIn('deps: { os-kazuhahub-wifi-radius-proxy: { origin: "opnsense/os-kazuhahub-wifi-radius-proxy", version: "1.2.0" } }',
                      manifest)
        metadata = json.loads((PLUGIN / 'src/usr/local/opnsense/version' / SHORT).read_text())
        self.assertEqual((NAME, '1.2.0'), (metadata['product_id'], metadata['product_version']))
        self.assertIn('VERSION="${VERSION:-1.2.0}"', (PLUGIN / 'build.sh').read_text())
        self.assertIn('VERSION?=\t1.2.0', (PLUGIN / 'Makefile').read_text())
        shipped = sorted(p.relative_to(PLUGIN / 'src').as_posix() for p in (PLUGIN / 'src').rglob('*') if p.is_file())
        self.assertEqual(['usr/local/opnsense/version/' + SHORT], shipped)

    def test_the_upgrade_removes_the_former_service_and_starts_the_renamed_one(self):
        rc, directories = FORMER
        leftovers = [self.put('etc/rc.conf.d/' + rc, rc + '_enable="YES"\n'), self.put('var/lib/php/tmp/opnsense_menu_cache.xml')]
        leftovers += [self.put(directory + '/inside/file') for directory in directories]
        kept = [self.put('etc/rc.conf.d/kazuhahub_wifiradiusproxy', 'kazuhahub_wifiradiusproxy_enable="YES"\n'),
                self.put('usr/local/etc/kazuhahub-wifi-radius-proxy/clients.conf'),
                self.put('var/db/kazuhahub-wifi-radius-proxy/certsync/current/server.pem'), self.put('conf/config.xml', 'kept')]
        result = self.run_hook('+POST_INSTALL')
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertFalse(any(path.exists() for path in leftovers))
        for directory in directories:
            self.assertFalse((self.root / directory).exists(), directory)
        self.assertTrue(all(path.exists() for path in kept))
        self.assertEqual(self.calls(), [['register.php', 'install', NAME], ['kazuhahub.sh', 'mirror'],
                                        ['service', 'configd', 'restart'], ['configctl', 'cron', 'restart'],
                                        ['service', 'kazuhahub_wifiradiusproxy', 'restart']])

    def test_a_renamed_service_that_is_off_stays_off(self):
        self.put('etc/rc.conf.d/kazuhahub_wifiradiusproxy', 'kazuhahub_wifiradiusproxy_enable="NO"\n')
        self.assertEqual(0, self.run_hook('+POST_INSTALL').returncode)
        self.assertNotIn(['service', 'kazuhahub_wifiradiusproxy', 'restart'], self.calls())

    def test_a_former_daemon_that_still_runs_is_stopped_first(self):
        sleeper = subprocess.Popen(['sleep', '60'])
        self.addCleanup(sleeper.kill)
        self.put('var/run/%s/radiusd.pid' % SHORT, '%d\n' % sleeper.pid)
        self.assertEqual(0, self.run_hook('+POST_INSTALL').returncode)
        self.assertIsNotNone(sleeper.wait(timeout=10))

    def test_removal_leaves_the_renamed_proxy_alone(self):
        self.put('etc/rc.conf.d/kazuhahub_wifiradiusproxy', 'kazuhahub_wifiradiusproxy_enable="YES"\n')
        result = self.run_hook('+POST_DEINSTALL')
        self.assertEqual(0, result.returncode)
        self.assertEqual(self.calls(), [['register.php', 'remove', NAME], ['kazuhahub.sh', 'forget', NAME]])
        self.assertTrue((self.root / 'etc/rc.conf.d/kazuhahub_wifiradiusproxy').exists())
        self.events.unlink()
        self.assertEqual(0, self.run_hook('+POST_DEINSTALL', upgrade=True).returncode)
        self.assertEqual(self.calls(), [])

if __name__ == '__main__':
    unittest.main()
