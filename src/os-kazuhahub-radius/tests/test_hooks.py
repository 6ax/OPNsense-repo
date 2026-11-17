"""Execute the package hooks, the configuration check and the rc.d functions under a private root with stubs."""
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

PACKAGE = Path(__file__).resolve().parents[1]
HOOKS = PACKAGE / 'packaging/freebsd'
RC = PACKAGE / 'src/usr/local/etc/rc.d/kazuhahub_radiusd'
CHECK = PACKAGE / 'src/usr/local/opnsense/scripts/kazuhahub-radius/check.sh'

# Writes one line per call: the program name and its arguments, separated by tabs.
RECORDER = '''#!/bin/sh
name="$(basename "$0")"
line="$name"
for argument in "$@"; do line="$line	$argument"; done
printf '%s\\n' "$line" >> "$HOOK_EVENTS"
case "$name:$*" in
configctl:template\\ reload\\ OPNsense/KazuhaHubRadius)
    mkdir -p "$HOOK_ROOT/etc/rc.conf.d"
    printf 'kazuhahub_radiusd_enable="%s"\\n' "${HOOK_ENABLE:-NO}" > "$HOOK_ROOT/etc/rc.conf.d/kazuhahub_radiusd"
    ;;
pkg:config\\ ABI)
    [ -n "${HOOK_ABI:-}" ] || exit 1
    echo "$HOOK_ABI"
    ;;
pkg:info\\ -e\\ os-freeradius)
    [ "${HOOK_FREERADIUS:-0}" = 1 ] || exit 1
    ;;
esac
[ "${HOOK_FAIL:-}" = "$name" ] && exit 1
exit 0
'''


def rewrite(source, root):
    """One pass, so the private root itself (under /var on macOS) is never rewritten."""
    return re.sub(r'(?<![A-Za-z0-9_./-])/(usr/local|var|etc/rc\.conf\.d|sbin/ifconfig)(?=/|\b)',
                  lambda match: str(root / match.group(1)), source)


class HookTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='kzr-hooks-')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'root'
        self.bin = Path(self.temporary.name) / 'bin'
        self.bin.mkdir()
        self.events = Path(self.temporary.name) / 'events'
        for name in ['configctl', 'service', 'pkg']:
            (self.bin / name).write_text(RECORDER)
            (self.bin / name).chmod(0o755)
        # The installed package, as pkg leaves it before the post-install hook.
        shutil.copytree(PACKAGE / 'src', self.root)
        for relative in ['usr/local/opnsense/scripts/firmware/register.php', 'usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh',
                         'usr/local/opnsense/mvc/script/run_migrations.php']:
            stub = self.root / relative
            stub.parent.mkdir(parents=True, exist_ok=True)
            stub.write_text(RECORDER)
            stub.chmod(0o755)
        for path in [self.root / 'usr/local/etc/rc.d/kazuhahub_radiusd', self.root / 'usr/local/opnsense/scripts/kazuhahub-radius/check.sh']:
            path.chmod(0o600)

    def put(self, relative, content='x'):
        path = self.root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content)
        return path

    def run_hook(self, name, upgrade=False, **environment):
        hook = Path(self.temporary.name) / name
        hook.write_text(rewrite((HOOKS / name).read_text(), self.root))
        env = {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'],
               'HOOK_EVENTS': str(self.events), 'HOOK_ROOT': str(self.root), **environment}
        env.pop('PKG_UPGRADE', None)
        if upgrade:
            env['PKG_UPGRADE'] = '1'
        return subprocess.run(['sh', str(hook)], capture_output=True, text=True, env=env, timeout=20)

    def calls(self):
        if not self.events.exists():
            return []
        return [line.split('\t') for line in self.events.read_text().splitlines()]

    def test_every_hook_is_strict_shell_and_leaves_the_stock_radiusd_alone(self):
        for name in ['+PRE_INSTALL', '+POST_INSTALL', '+PRE_DEINSTALL', '+POST_DEINSTALL']:
            with self.subTest(hook=name):
                text = (HOOKS / name).read_text()
                self.assertTrue(text.startswith('#!/bin/sh\nset -eu\n'))
                self.assertEqual(subprocess.run(['sh', '-n', str(HOOKS / name)]).returncode, 0)
                self.assertNotRegex(text, r'(?<!kazuhahub_)radiusd_enable')
                self.assertNotIn('raddb', text)
                self.assertNotIn('sysrc', text)
                self.assertNotRegex(text, r'service radiusd|rc\.d/radiusd')
        for name in ['+PRE_INSTALL', '+POST_INSTALL', '+PRE_DEINSTALL', '+POST_DEINSTALL']:
            self.assertFalse((HOOKS / name).is_symlink())

    def test_pre_install_accepts_only_supported_abis(self):
        for abi, code in [('FreeBSD:15:amd64', 0), ('FreeBSD:14:amd64', 0), ('FreeBSD:16:amd64', 0),
                          ('FreeBSD:13:amd64', 1), ('FreeBSD:15:aarch64', 1), ('', 1)]:
            with self.subTest(abi=abi):
                result = self.run_hook('+PRE_INSTALL', HOOK_ABI=abi)
                self.assertEqual(result.returncode, code, result.stderr)
                if code:
                    self.assertIn('Unsupported ABI', result.stderr)

    def test_pre_install_refuses_next_to_os_freeradius_by_its_files_not_by_pkg_info(self):
        for marker in ['usr/local/opnsense/version/freeradius', 'usr/local/etc/inc/plugins.inc.d/freeradius.inc',
                       'usr/local/opnsense/mvc/app/models/OPNsense/Freeradius/General.xml']:
            with self.subTest(marker=marker):
                self.events.unlink(missing_ok=True)
                self.put(marker)
                result = self.run_hook('+PRE_INSTALL', HOOK_ABI='FreeBSD:15:amd64')
                self.assertEqual(result.returncode, 1)
                self.assertIn('os-freeradius is installed', result.stderr)
                self.assertIn('cannot coexist', result.stderr)
                # pkg info inside a pkg transaction is unreliable, so only the ABI is asked of pkg.
                self.assertNotIn(['pkg', 'info', '-e', 'os-freeradius'], self.calls())
                (self.root / marker).unlink()
        # Even a pkg that claims os-freeradius is installed changes nothing when the files are absent.
        result = self.run_hook('+PRE_INSTALL', HOOK_ABI='FreeBSD:15:amd64', HOOK_FREERADIUS='1')
        self.assertEqual(result.returncode, 0, result.stderr)
        # An unsupported ABI is refused first.
        self.put('usr/local/opnsense/version/freeradius')
        result = self.run_hook('+PRE_INSTALL', HOOK_ABI='FreeBSD:13:amd64')
        self.assertIn('Unsupported ABI', result.stderr)

    def test_the_post_install_warns_again_because_pkg_may_go_on_after_a_failed_pre_install(self):
        self.put('usr/local/opnsense/version/freeradius')
        result = self.run_hook('+POST_INSTALL')
        self.assertEqual(result.returncode, 0)
        self.assertIn('Warning: os-freeradius is installed', result.stderr)
        (self.root / 'usr/local/opnsense/version/freeradius').unlink()
        self.assertNotIn('os-freeradius', self.run_hook('+POST_INSTALL').stderr)

    def test_this_package_ships_none_of_the_files_that_mark_os_freeradius(self):
        for marker in ['usr/local/opnsense/version/freeradius', 'usr/local/etc/inc/plugins.inc.d/freeradius.inc',
                       'usr/local/opnsense/mvc/app/models/OPNsense/Freeradius/General.xml']:
            self.assertFalse((PACKAGE / 'src' / marker).exists(), marker)

    def test_a_fresh_install_prepares_everything_and_stays_stopped(self):
        caches = [self.put('var/lib/php/tmp/opnsense_menu_cache.xml'), self.put('var/lib/php/tmp/opnsense_acl_cache.json')]
        result = self.run_hook('+POST_INSTALL')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.calls(), [
            ['register.php', 'install', 'os-kazuhahub-radius'],
            ['kazuhahub.sh', 'mirror'],
            ['service', 'configd', 'restart'],
            ['run_migrations.php', 'OPNsense/KazuhaHubRadius'],
            ['configctl', 'template', 'reload', 'OPNsense/KazuhaHubRadius']])
        self.assertEqual((self.root / 'usr/local/etc/kazuhahub-radius').stat().st_mode & 0o777, 0o750)
        self.assertFalse(any(path.exists() for path in caches))
        self.assertEqual((self.root / 'usr/local/etc/rc.d/kazuhahub_radiusd').stat().st_mode & 0o777, 0o755)
        for relative in ['usr/local/opnsense/scripts/kazuhahub-radius/check.sh',
                         'usr/local/opnsense/scripts/kazuhahub-radius/export_certs.php',
                         'usr/local/opnsense/scripts/kazuhahub-radius/homeservers.php',
                         'usr/local/etc/inc/plugins.inc.d/kazuhahubradius.inc',
                         'usr/local/opnsense/www/js/widgets/KazuhaHubRadius.js']:
            self.assertEqual((self.root / relative).stat().st_mode & 0o777, 0o644, relative)
        self.assertIn('Services > Kazuha Hub RADIUS', result.stdout)
        self.assertFalse(any(call[0] == 'service' and call[1] == 'kazuhahub_radiusd' for call in self.calls()))

    def test_the_post_install_names_the_renamed_repository_hook(self):
        text = (HOOKS / '+POST_INSTALL').read_text()
        self.assertIn('/usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh mirror', text)
        self.assertNotIn('repos/kazuha.sh', text)

    def test_a_missing_repository_hook_only_skips_the_manifest_update(self):
        (self.root / 'usr/local/opnsense/scripts/firmware/repos/kazuhahub.sh').unlink()
        result = self.run_hook('+POST_INSTALL')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn(['kazuhahub.sh', 'mirror'], self.calls())

    def test_a_failing_manifest_update_only_warns(self):
        result = self.run_hook('+POST_INSTALL', HOOK_FAIL='kazuhahub.sh')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Warning: Kazuha Hub plugin restore manifest', result.stderr)

    def test_an_enabled_reinstall_or_upgrade_restarts_the_service(self):
        result = self.run_hook('+POST_INSTALL', HOOK_ENABLE='YES')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.calls()[-1], ['service', 'kazuhahub_radiusd', 'restart'])

    def test_install_survives_failing_helpers_with_warnings(self):
        for failing in ['configctl', 'service', 'run_migrations.php']:
            with self.subTest(failing=failing):
                self.events.unlink(missing_ok=True)
                result = self.run_hook('+POST_INSTALL', HOOK_FAIL=failing, HOOK_ENABLE='YES')
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertIn(['register.php', 'install', 'os-kazuhahub-radius'], self.calls())
        self.events.unlink(missing_ok=True)
        result = self.run_hook('+POST_INSTALL', HOOK_FAIL='configctl')
        self.assertIn('Warning: the Kazuha Hub RADIUS configuration could not be generated.', result.stderr)
        result = self.run_hook('+POST_INSTALL', HOOK_FAIL='run_migrations.php')
        self.assertIn('Warning: the Kazuha Hub RADIUS defaults could not be created.', result.stderr)

    def test_pre_deinstall_always_stops_the_service_and_nothing_else(self):
        for upgrade in [False, True]:
            for failing in ['', 'service']:
                with self.subTest(upgrade=upgrade, failing=failing):
                    self.events.unlink(missing_ok=True)
                    result = self.run_hook('+PRE_DEINSTALL', upgrade=upgrade, HOOK_FAIL=failing)
                    self.assertEqual(result.returncode, 0, result.stderr)
                    self.assertEqual(self.calls(), [['service', 'kazuhahub_radiusd', 'onestop']])

    def test_an_upgrade_keeps_the_generated_configuration_and_the_setting(self):
        kept = [self.put('etc/rc.conf.d/kazuhahub_radiusd', 'kazuhahub_radiusd_enable="YES"\n'),
                self.put('usr/local/etc/kazuhahub-radius/radiusd.conf'), self.put('usr/local/etc/kazuhahub-radius/certs/server.key'),
                self.put('var/run/kazuhahub-radius/radiusd.pid'), self.put('var/log/kazuhahub-radius/radius.log')]
        result = self.run_hook('+POST_DEINSTALL', upgrade=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.calls(), [])
        self.assertTrue(all(path.exists() for path in kept))

    def test_removal_cleans_up_generated_files_but_leaves_config_xml_and_the_stock_radiusd(self):
        removed = [self.put('etc/rc.conf.d/kazuhahub_radiusd'),
                   self.put('usr/local/etc/kazuhahub-radius/radiusd.conf'), self.put('usr/local/etc/kazuhahub-radius/clients.conf'),
                   self.put('usr/local/etc/kazuhahub-radius/certs/server.key'), self.put('usr/local/etc/kazuhahub-radius/certs/server.pem'),
                   self.put('var/run/kazuhahub-radius/radiusd.pid'), self.put('var/run/kazuhahub-radius/check.result'),
                   self.put('var/log/kazuhahub-radius/radius.log'),
                   self.put('var/lib/php/tmp/opnsense_menu_cache.xml')]
        kept = [self.put('conf/config.xml', '<kazuhahubradius>kept</kazuhahubradius>'),
                self.put('usr/local/etc/raddb/radiusd.conf', 'stock'), self.put('etc/rc.conf.d/radiusd', 'radiusd_enable="YES"\n'),
                self.put('var/log/radius.log', 'stock log'), self.put('var/run/radiusd/radiusd.pid', '1')]
        result = self.run_hook('+POST_DEINSTALL')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(any(path.exists() for path in removed))
        for directory in ['usr/local/etc/kazuhahub-radius', 'var/run/kazuhahub-radius', 'var/log/kazuhahub-radius']:
            self.assertFalse((self.root / directory).exists(), directory)
        self.assertTrue(all(path.exists() for path in kept))
        self.assertEqual(kept[0].read_text(), '<kazuhahubradius>kept</kazuhahubradius>')
        self.assertEqual(kept[1].read_text(), 'stock')
        self.assertEqual(self.calls(), [['register.php', 'remove', 'os-kazuhahub-radius'], ['service', 'configd', 'restart']])

    def test_removal_survives_failing_helpers(self):
        for failing in ['register.php', 'service']:
            with self.subTest(failing=failing):
                result = self.run_hook('+POST_DEINSTALL', HOOK_FAIL=failing)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertFalse((self.root / 'usr/local/etc/kazuhahub-radius').exists())


class FakeRoot(unittest.TestCase):
    """A private root with the stubs the check script and the rc script call."""

    STUB_RADIUSD = '''#!/bin/sh
printf '%s\\n' "$*" >> "$STUB_EVENTS"
if [ -n "${STUB_RADIUSD_OUTPUT_FILE:-}" ]; then cat "$STUB_RADIUSD_OUTPUT_FILE"; fi
exit "${STUB_RADIUSD_STATUS:-0}"
'''
    STUB_PHP = '''#!/bin/sh
printf 'php %s\\n' "$*" >> "$STUB_EVENTS"
[ -n "${STUB_PHP_OUTPUT:-}" ] && printf '%s\\n' "$STUB_PHP_OUTPUT"
exit "${STUB_PHP_STATUS:-0}"
'''
    STUB_PKG = '''#!/bin/sh
printf 'pkg %s\\n' "$*" >> "$STUB_EVENTS"
[ "$1 $2 $3" = "info -e os-freeradius" ] && [ "${STUB_FREERADIUS:-0}" = 1 ]
'''
    STUB_SERVICE = '''#!/bin/sh
printf 'service %s\\n' "$*" >> "$STUB_EVENTS"
if [ "$1" = "-e" ]; then
    [ "${STUB_STOCK_RADIUSD:-0}" = 1 ] && echo /usr/local/etc/rc.d/radiusd
    echo /etc/rc.d/sshd
fi
exit 0
'''
    STUB_CHOWN = '''#!/bin/sh
printf 'chown %s\\n' "$*" >> "$STUB_EVENTS"
exit "${STUB_CHOWN_STATUS:-0}"
'''

    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='kzr-root-')
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        self.root = self.base / 'root'
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        self.events = self.base / 'events'
        self.events.write_text('')
        for name, body in [('pkg', self.STUB_PKG), ('service', self.STUB_SERVICE), ('chown', self.STUB_CHOWN)]:
            (self.bin / name).write_text(body)
            (self.bin / name).chmod(0o755)
        for relative, body in [('usr/local/sbin/radiusd', self.STUB_RADIUSD), ('usr/local/bin/php', self.STUB_PHP)]:
            path = self.root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(body)
            path.chmod(0o755)

    def env(self, **extra):
        return {**os.environ, 'PATH': str(self.bin) + os.pathsep + os.environ['PATH'], 'STUB_EVENTS': str(self.events), **extra}

    def lines(self):
        return self.events.read_text().splitlines()


class CheckScriptTests(FakeRoot):
    def setUp(self):
        super().setUp()
        self.script = self.base / 'check.sh'
        self.script.write_text(rewrite(CHECK.read_text(), self.root))
        self.conf = self.root / 'usr/local/etc/kazuhahub-radius'
        (self.conf / 'sites-enabled').mkdir(parents=True)
        (self.conf / 'sites-enabled/default').write_text('x')
        (self.conf / 'radiusd.conf').write_text('x')
        os.chmod(self.conf / 'radiusd.conf', 0o644)

    def run_check(self, mode='run', output=None, **extra):
        env = self.env(**extra)
        if output is not None:
            path = self.base / 'radiusd.out'
            path.write_text(output)
            env['STUB_RADIUSD_OUTPUT_FILE'] = str(path)
        return subprocess.run(['sh', str(self.script), mode], capture_output=True, text=True, env=env, timeout=20)

    def run_check_args(self, *arguments):
        return subprocess.run(['sh', str(self.script), *arguments], capture_output=True, text=True, env=self.env(), timeout=20)

    def test_a_good_configuration_reports_ok_and_the_command_the_spec_names(self):
        result = self.run_check(output='Configuration appears to be OK\n')
        self.assertEqual(result.returncode, 0, result.stderr)
        report = json.loads(result.stdout)
        self.assertEqual((report['result'], report['message'], report['conflicts']), ('ok', 'Configuration appears to be OK', []))
        self.assertGreater(report['checked'], 1700000000)
        self.assertIn('-XC -d %s -n radiusd' % self.conf, '\n'.join(self.lines()))
        self.assertIn('php %s' % (self.root / 'usr/local/opnsense/scripts/kazuhahub-radius/export_certs.php'), self.lines())

    def test_the_directories_and_files_are_prepared_for_the_daemon(self):
        (self.conf / 'certs').mkdir()
        (self.conf / 'certs/server.key').write_text('k')
        os.chmod(self.conf / 'certs/server.key', 0o644)
        self.run_check()
        self.assertEqual(self.conf.stat().st_mode & 0o777, 0o750)
        self.assertEqual((self.conf / 'sites-enabled').stat().st_mode & 0o777, 0o750)
        for relative in ['radiusd.conf', 'certs/server.key', 'sites-enabled/default']:
            self.assertEqual((self.conf / relative).stat().st_mode & 0o777, 0o640, relative)
        for directory in ['var/run/kazuhahub-radius', 'var/log/kazuhahub-radius']:
            self.assertEqual((self.root / directory).stat().st_mode & 0o777, 0o750, directory)
        owners = [line for line in self.lines() if line.startswith('chown ')]
        self.assertIn('chown freeradius:freeradius %s %s' % (self.root / 'var/run/kazuhahub-radius', self.root / 'var/log/kazuhahub-radius'), owners)
        self.assertIn('chown -R root:freeradius %s' % self.conf, owners)

    def test_a_failing_check_reports_only_the_error_lines(self):
        conf = str(self.conf)
        dump = '\n'.join([
            'FreeRADIUS Version 3.2.10', 'including configuration file %s/radiusd.conf' % conf,
            ' \tsecret = <<< secret >>>', ' \tprivate_key_password = hunter2-password', '  \tsecret = "leaked-central-secret"',
            'radiusd: #### Loading Modules ####',
            'Unable to check file "%s/certs/server.key": No such file or directory' % conf,
            '%s/mods-enabled/eap[15]: Failed parsing configuration item "private_key_file"' % conf,
            'rlm_eap_ttls: Failed initializing SSL context', 'Error: a line mentioning the secret value: leaked-central-secret',
            'Errors reading or parsing %s/radiusd.conf' % conf, ''])
        result = self.run_check(output=dump, STUB_RADIUSD_STATUS='1')
        report = json.loads(result.stdout)
        self.assertEqual(report['result'], 'failed')
        self.assertEqual(report['message'].split('\n'), [
            'Unable to check file "%s/certs/server.key": No such file or directory' % conf,
            '%s/mods-enabled/eap[15]: Failed parsing configuration item "private_key_file"' % conf,
            'rlm_eap_ttls: Failed initializing SSL context',
            'Errors reading or parsing %s/radiusd.conf' % conf])
        self.assertNotIn('leaked-central-secret', result.stdout)
        self.assertNotIn('hunter2', result.stdout)
        self.assertNotIn('####', result.stdout)

    def test_a_flood_of_error_lines_is_cut_short(self):
        output = '\n'.join('Error: %d %s' % (number, 'x' * 400) for number in range(40)) + '\n'
        report = json.loads(self.run_check(output=output, STUB_RADIUSD_STATUS='1').stdout)
        lines = report['message'].split('\n')
        self.assertEqual(len(lines), 12)
        self.assertTrue(all(len(line) <= 240 for line in lines))
        self.assertTrue(lines[-1].startswith('Error: 39 '))

    def test_a_failure_without_a_usable_line_still_says_so(self):
        report = json.loads(self.run_check(output='nothing useful here\n', STUB_RADIUSD_STATUS='1').stdout)
        self.assertEqual((report['result'], report['message']), ('failed', 'The configuration check failed.'))

    def test_a_certificate_export_failure_stops_before_the_daemon_is_asked(self):
        report = json.loads(self.run_check(STUB_PHP_STATUS='1', STUB_PHP_OUTPUT='The private key does not belong to the server certificate.').stdout)
        self.assertEqual((report['result'], report['message']),
                         ('failed', 'The private key does not belong to the server certificate.'))
        self.assertFalse(any('-XC' in line for line in self.lines()))

    def test_a_directory_that_cannot_be_prepared_is_reported(self):
        report = json.loads(self.run_check(STUB_CHOWN_STATUS='1').stdout)
        self.assertEqual((report['result'], report['message']), ('failed', 'The configuration directory could not be prepared.'))
        self.assertFalse(any('-XC' in line for line in self.lines()))

    def test_conflicts_name_the_installed_package_and_the_enabled_stock_service(self):
        for extra, expected in [({'STUB_FREERADIUS': '1'}, ['os-freeradius is installed']),
                                ({'STUB_STOCK_RADIUSD': '1'}, ['radiusd_enable']),
                                ({'STUB_FREERADIUS': '1', 'STUB_STOCK_RADIUSD': '1'}, ['os-freeradius is installed', 'radiusd_enable']),
                                ({}, [])]:
            with self.subTest(extra=extra):
                report = json.loads(self.run_check(output='Configuration appears to be OK\n', **extra).stdout)
                self.assertEqual(len(report['conflicts']), len(expected))
                for conflict, word in zip(report['conflicts'], expected):
                    self.assertIn(word, conflict)
                last = json.loads(self.run_check('last', **extra).stdout)
                self.assertEqual(last['conflicts'], report['conflicts'])
                code = self.run_check('conflicts', **extra)
                self.assertEqual(code.returncode, 1 if expected else 0)
                self.assertEqual(len(code.stdout.splitlines()), len(expected))
        for marker in ['usr/local/opnsense/version/freeradius', 'usr/local/etc/inc/plugins.inc.d/freeradius.inc']:
            path = self.root / marker
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('x')
            code = self.run_check('conflicts')
            self.assertEqual(code.returncode, 1, marker)
            self.assertIn('os-freeradius is installed', code.stdout)
            path.unlink()
        # The stock service is looked up through service -e, never by touching its settings.
        self.assertIn('service -e', self.lines())
        self.assertFalse(any(line.startswith(('sysrc', 'service radiusd')) for line in self.lines()))

    def test_the_last_result_survives_between_calls_and_is_unknown_before_the_first(self):
        first = json.loads(self.run_check('last').stdout)
        self.assertEqual((first['result'], first['message'], first['checked']), ('unknown', '', 0))
        self.run_check(output='Configuration appears to be OK\n')
        again = json.loads(self.run_check('last').stdout)
        self.assertEqual((again['result'], again['message']), ('ok', 'Configuration appears to be OK'))
        self.run_check(output='Error: broken\n', STUB_RADIUSD_STATUS='1')
        failed = json.loads(self.run_check('last').stdout)
        self.assertEqual((failed['result'], failed['message']), ('failed', 'Error: broken'))
        # The last mode only reads: it neither exports nor checks.
        before = len(self.lines())
        self.run_check('last')
        self.assertEqual(len(self.lines()), before + 2)   # pkg and service, for the conflicts

    def test_the_json_stays_valid_whatever_the_message_holds(self):
        for message in ['Error: say "quoted" and back\\slash', 'Error: tab\there', 'Error: unicode ' + chr(0xe9) + chr(0x4e2d), 'Error: \x01 control']:
            with self.subTest(message=message):
                report = json.loads(self.run_check(output=message + '\n', STUB_RADIUSD_STATUS='1').stdout)
                self.assertEqual(report['result'], 'failed')
                self.assertTrue(report['message'].startswith('Error: '))

    def test_start_mode_gives_the_verdict_as_an_exit_status_and_keeps_stdout_clean(self):
        good = self.run_check('start', output='Configuration appears to be OK\n')
        self.assertEqual((good.returncode, good.stdout, good.stderr), (0, '', ''))
        bad = self.run_check('start', output='Error: broken\n', STUB_RADIUSD_STATUS='1')
        self.assertEqual((bad.returncode, bad.stdout), (1, ''))
        self.assertEqual(bad.stderr.strip(), 'Error: broken')

    def test_a_marker_in_the_rendered_files_fails_the_check_with_a_clear_message(self):
        files = {'sites-enabled/default': ('# no-listen-address', 'No usable listen interface'),
                 'proxy.conf': ('# no-usable-central', 'No usable central server'),
                 'clients.conf': ('# no-usable-client', 'No usable access point')}
        for relative, (marker, text) in files.items():
            with self.subTest(marker=marker):
                (self.conf / relative).write_text('server {\n\t%s\n}\n' % marker if 'sites' in relative else marker + '\n')
                report = json.loads(self.run_check(output='Configuration appears to be OK\n').stdout)
                self.assertEqual(report['result'], 'failed')
                self.assertIn(text, report['message'])
                # The daemon is not even asked.
                self.assertFalse(any('-XC' in line for line in self.lines()))
                (self.conf / relative).write_text('x\n')
                self.events.write_text('')
        for relative, (marker, text) in files.items():
            (self.conf / relative).write_text(marker + '\n' if 'sites' not in relative else '\t' + marker + '\n')
        report = json.loads(self.run_check().stdout)
        self.assertEqual([line.split(':')[0] for line in report['message'].split('\n')],
                         ['No usable listen interface', 'No usable central server', 'No usable access point'])
        failed = self.run_check('start')
        self.assertEqual(failed.returncode, 1)
        self.assertIn('No usable', failed.stderr)

    def test_the_rc_script_can_record_its_refusal_as_the_failed_result(self):
        self.assertEqual(self.run_check('record').returncode, 0)
        self.assertEqual(json.loads(self.run_check('last').stdout)['message'], 'The service was refused.')
        self.run_check_args('record', 'the listen address is not configured: 10.0.0.1')
        last = json.loads(self.run_check('last').stdout)
        self.assertEqual((last['result'], last['message']), ('failed', 'the listen address is not configured: 10.0.0.1'))
        self.run_check_args('record', 'x' * 500 + '\nsecond\nthird\nfourth')
        last = json.loads(self.run_check('last').stdout)
        self.assertEqual(len(last['message'].split('\n')), 3)
        self.assertTrue(all(len(line) <= 240 for line in last['message'].split('\n')))

    def test_an_unknown_mode_is_a_usage_error(self):
        result = self.run_check('nonsense')
        self.assertEqual(result.returncode, 2)
        self.assertIn('usage', result.stderr)


class RcScriptTests(FakeRoot):
    """Run the real rc.d functions with rc.subr and the helpers stubbed."""

    def setUp(self):
        super().setUp()
        (self.bin / 'sleep').write_text('#!/bin/sh\nprintf "sleep %s\\n" "$*" >> "$STUB_EVENTS"\n')
        (self.bin / 'sleep').chmod(0o755)
        source = RC.read_text()
        source = source.replace('. /etc/rc.subr', '''load_rc_config() { :; }
warn() { echo "warn: $*" >&2; }
run_rc_command() { case "$1" in prestart) kazuhahub_radiusd_prestart ;; prereload) kazuhahub_radiusd_prereload ;; poststop) kazuhahub_radiusd_poststop ;; esac; }''')
        source = rewrite(source, self.root)
        self.script = self.base / 'rc'
        self.script.write_text(source)
        self.conf = self.root / 'usr/local/etc/kazuhahub-radius'
        (self.conf / 'sites-enabled').mkdir(parents=True)
        self.site = self.conf / 'sites-enabled/default'
        self.site.write_text('server default {\n\tlisten {\n\t\ttype = auth\n\t\tipaddr = 192.168.0.1\n\t\tport = 1812\n\t}\n\n'
                             '\tlisten {\n\t\ttype = auth\n\t\tipaddr = 10.7.0.1\n\t\tport = 1812\n\t}\n}\n')
        self.check = self.root / 'usr/local/opnsense/scripts/kazuhahub-radius/check.sh'
        self.check.parent.mkdir(parents=True, exist_ok=True)
        self.configctl = self.root / 'usr/local/sbin/configctl'
        self.configctl.write_text('''#!/bin/sh
printf 'configctl %s\\n' "$*" >> "$STUB_EVENTS"
exit "${STUB_CONFIGCTL_STATUS:-0}"
''')
        self.configctl.chmod(0o755)
        self.check.write_text('''#!/bin/sh
printf 'check %s\\n' "$*" >> "$STUB_EVENTS"
case "$1" in
conflicts) [ -z "${STUB_CONFLICT:-}" ] || { echo "$STUB_CONFLICT"; exit 1; } ;;
start) [ "${STUB_CHECK_FAIL:-0}" = 0 ] || { echo 'Error: broken' >&2; exit 1; } ;;
esac
exit 0
''')
        self.ifconfig = self.root / 'sbin/ifconfig'
        self.ifconfig.parent.mkdir(parents=True, exist_ok=True)
        self.ifconfig.write_text('''#!/bin/sh
printf 'ifconfig %s\\n' "$*" >> "$STUB_EVENTS"
echo "vtnet0: flags=8843"
for address in ${STUB_ADDRESSES:-192.168.0.1 10.7.0.1}; do echo "	inet $address netmask 0xffffff00 broadcast 255.255.255.255"; done
''')
        self.ifconfig.chmod(0o755)

    def run_rc(self, action, **extra):
        return subprocess.run(['sh', str(self.script), action], capture_output=True, text=True, env=self.env(**extra), timeout=20)

    def test_a_clean_start_checks_conflicts_renders_checks_and_then_waits_for_the_addresses(self):
        result = self.run_rc('prestart')
        self.assertEqual((result.returncode, result.stderr), (0, ''))
        calls = [line for line in self.lines() if line.startswith(('check', 'ifconfig', 'configctl'))]
        # The configuration is rendered again before the check, because the interfaces may have changed;
        # then one look at the interfaces per listen address.
        self.assertEqual(calls, ['check conflicts', 'configctl template reload OPNsense/KazuhaHubRadius', 'check start',
                                 'ifconfig -a inet', 'ifconfig -a inet'])

    def test_a_conflict_refuses_the_start_before_anything_else_runs_and_is_recorded(self):
        result = self.run_rc('prestart', STUB_CONFLICT='os-freeradius is installed.')
        self.assertEqual(result.returncode, 1)
        self.assertIn('refusing to start: os-freeradius is installed.', result.stderr)
        self.assertEqual([line for line in self.lines() if line.startswith(('check', 'configctl'))],
                         ['check conflicts', 'check record refusing to start: os-freeradius is installed.'])

    def test_a_failing_configuration_check_refuses_the_start(self):
        # The check itself records why: unusable interface, central server or access point included.
        result = self.run_rc('prestart', STUB_CHECK_FAIL='1')
        self.assertEqual(result.returncode, 1)
        self.assertIn('Error: broken', result.stderr)
        self.assertFalse(any(line.startswith('ifconfig') for line in self.lines()))

    def test_a_render_that_fails_only_warns_and_the_last_configuration_is_used(self):
        result = self.run_rc('prestart', STUB_CONFIGCTL_STATUS='1')
        self.assertEqual(result.returncode, 0)
        self.assertIn('could not be rendered again', result.stderr)
        self.assertIn('check start', self.lines())

    def test_an_address_that_never_appears_refuses_the_start_after_waiting(self):
        result = self.run_rc('prestart', STUB_ADDRESSES='192.168.0.1')
        self.assertEqual(result.returncode, 1)
        self.assertIn('the listen address is not configured on this firewall: 10.7.0.1', result.stderr)
        self.assertIn('check record the listen address is not configured on this firewall: 10.7.0.1', self.lines())
        self.assertEqual(len([line for line in self.lines() if line.startswith('sleep')]), 30)

    def test_an_address_that_appears_late_lets_the_start_go_on(self):
        # Each pass asks once per address; the stub reports the second address from the fourth pass on.
        self.ifconfig.write_text('''#!/bin/sh
count="$(cat "$STUB_EVENTS.count" 2>/dev/null || echo 0)"
count=$((count + 1))
echo "$count" > "$STUB_EVENTS.count"
echo "	inet 192.168.0.1 netmask 0xffffff00"
[ "$count" -lt 8 ] || echo "	inet 10.7.0.1 netmask 0xffffff00"
''')
        result = self.run_rc('prestart')
        self.assertEqual((result.returncode, result.stderr), (0, ''))
        self.assertEqual(len([line for line in self.lines() if line.startswith('sleep')]), 3)

    def test_an_address_is_matched_whole_not_as_a_prefix(self):
        result = self.run_rc('prestart', STUB_ADDRESSES='192.168.0.10 10.7.0.11')
        self.assertEqual(result.returncode, 1)
        self.assertIn('192.168.0.1 10.7.0.1', result.stderr)

    def test_reload_renders_and_checks_the_configuration_first_and_stop_removes_the_socket(self):
        self.assertEqual(self.run_rc('prereload').returncode, 0)
        self.assertEqual([line for line in self.lines() if line.startswith(('check', 'configctl'))],
                         ['configctl template reload OPNsense/KazuhaHubRadius', 'check start'])
        self.assertEqual(self.run_rc('prereload', STUB_CHECK_FAIL='1').returncode, 1)
        socket = self.root / 'var/run/kazuhahub-radius/radiusd.sock'
        socket.parent.mkdir(parents=True)
        socket.write_text('')
        self.assertEqual(self.run_rc('poststop').returncode, 0)
        self.assertFalse(socket.exists())

    def test_the_rc_script_never_touches_the_stock_service_setting(self):
        # Comments may name the stock service; the commands never do.
        text = '\n'.join(line for line in RC.read_text().splitlines() if not line.lstrip().startswith('#'))
        self.assertNotIn('sysrc', text)
        self.assertNotIn('/usr/local/etc/raddb', text)
        self.assertNotRegex(text, r'(?<!kazuhahub_)radiusd_enable')
        self.assertEqual(subprocess.run(['sh', '-n', str(RC)]).returncode, 0)


if __name__ == '__main__':
    unittest.main()
