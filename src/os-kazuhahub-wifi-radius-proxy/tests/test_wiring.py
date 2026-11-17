"""Check that every piece of Kazuha Hub Wi-Fi RADIUS is wired to the others and to the release tooling."""
import configparser
import json
from pathlib import Path
import re
import shlex
import unittest
import xml.etree.ElementTree as ET

PACKAGE = Path(__file__).resolve().parents[1]
REPOSITORY = PACKAGE.parents[1]
SRC = PACKAGE / 'src'
OPNSENSE = SRC / 'usr/local/opnsense'
MVC = OPNSENSE / 'mvc/app'
MODULE = 'KazuhaHubWifiRadiusProxy'
ROUTE = 'kazuhahubwifiradiusproxy'
CONTROLLERS = MVC / 'controllers/OPNsense' / MODULE
MODELS = MVC / 'models/OPNsense' / MODULE
TEMPLATES = OPNSENSE / 'service/templates/OPNsense' / MODULE
RC = SRC / 'usr/local/etc/rc.d/kazuhahub_wifiradiusproxy'
SCRIPTS = OPNSENSE / 'scripts/kazuhahub-wifi-radius-proxy'


def read(relative):
    return (SRC / relative).read_text()


def static(source, name):
    return re.search(r'protected static \$%s = \'([^\']*)\';' % name, source).group(1)


class ConfigdTests(unittest.TestCase):
    def actions(self):
        parser = configparser.ConfigParser(interpolation=None)
        parser.read(OPNSENSE / 'service/conf/actions.d/actions_kazuhahubwifiradiusproxy.conf')
        return parser

    def test_the_actions_are_the_documented_set_and_resolve_to_packaged_files(self):
        actions = self.actions()
        self.assertEqual(set(actions.sections()),
                         {'start', 'stop', 'restart', 'reload', 'status', 'check', 'lastcheck', 'homeservers',
                          'certsync', 'certsyncnow', 'certsyncstate'})
        rc = {'start': 'start', 'stop': 'onestop', 'restart': 'restart', 'reload': 'reload', 'status': 'onestatus'}
        for section in actions.sections():
            with self.subTest(action=section):
                words = shlex.split(actions[section]['command'])
                if section in rc:
                    self.assertEqual(words, ['/usr/local/etc/rc.d/kazuhahub_wifiradiusproxy', rc[section]])
                    self.assertEqual(actions[section]['type'], 'script' if section != 'status' else 'script_output')
                else:
                    self.assertEqual(actions[section]['type'], 'script_output')
                for word in words:
                    if word.startswith('/usr/local/etc/') or word.startswith('/usr/local/opnsense/'):
                        self.assertTrue((SRC / word.lstrip('/')).is_file(), word)
                self.assertEqual(actions[section].get('parameters', ''), '')

    def test_the_check_and_the_state_reach_only_their_own_helpers(self):
        actions = self.actions()
        self.assertEqual(shlex.split(actions['check']['command']),
                         ['/bin/sh', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/check.sh', 'run'])
        self.assertEqual(shlex.split(actions['lastcheck']['command']),
                         ['/bin/sh', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/check.sh', 'last'])
        self.assertEqual(shlex.split(actions['homeservers']['command']),
                         ['/usr/local/bin/php', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/homeservers.php'])
        self.assertEqual(shlex.split(actions['certsync']['command']),
                         ['/usr/local/bin/php', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/certsync.php', 'run'])
        self.assertEqual(shlex.split(actions['certsyncnow']['command']),
                         ['/usr/local/bin/php', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/certsync.php', 'run', 'force'])
        self.assertEqual(shlex.split(actions['certsyncstate']['command']),
                         ['/usr/local/bin/php', '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/certsync.php', 'state'])
        # The certificate export is reachable only through the check and the rc script.
        self.assertNotIn('export_certs', (OPNSENSE / 'service/conf/actions.d/actions_kazuhahubwifiradiusproxy.conf').read_text())

    def test_a_stopped_service_answers_the_status_action_with_text(self):
        # rc.d onestatus exits 1 when stopped; configd would swallow the text of a failing script_output action.
        self.assertEqual(self.actions()['status'].get('errors', '').strip().lower(), 'no')
        for section in set(self.actions().sections()) - {'status'}:
            self.assertEqual(self.actions()[section].get('errors', ''), '', section)
        self.assertIn('is running', 'kazuhahub_wifiradiusproxy is running as pid 1.')

    def test_controllers_call_only_registered_actions(self):
        registered = set(self.actions().sections())
        calls = []
        for path in (CONTROLLERS / 'Api').glob('*.php'):
            source = path.read_text()
            calls += re.findall(r'configdp?Run\(\s*\'([^\']+)\'', source)
            calls += re.findall(r'backendJson\(\s*\'([^\']+)\'', source)
        self.assertEqual(sorted(calls), sorted(['kazuhahubwifiradiusproxy lastcheck', 'kazuhahubwifiradiusproxy check', 'kazuhahubwifiradiusproxy homeservers',
                                                'kazuhahubwifiradiusproxy certsyncnow', 'kazuhahubwifiradiusproxy certsyncstate']))
        for command in calls:
            group, action = command.split()
            self.assertEqual(group, ROUTE)
            self.assertIn(action, registered)
        # The service base class runs these for start, stop, restart, status and reconfigure.
        self.assertTrue({'start', 'stop', 'restart', 'status', 'reload'} <= registered)


class ServiceTests(unittest.TestCase):
    def test_service_bindings_agree_across_controller_template_rc_and_plugin_hook(self):
        service = (CONTROLLERS / 'Api/ServiceController.php').read_text()
        self.assertEqual(static(service, 'internalServiceTemplate'), 'OPNsense/' + MODULE)
        self.assertTrue((OPNSENSE / 'service/templates' / static(service, 'internalServiceTemplate') / '+TARGETS').is_file())
        self.assertEqual(static(service, 'internalServiceName'), ROUTE)
        self.assertTrue((OPNSENSE / 'service/conf/actions.d' / ('actions_%s.conf' % ROUTE)).is_file())
        self.assertEqual(static(service, 'internalServiceEnabled'), 'enabled')
        self.assertEqual(static(service, 'internalServiceClass'), '\\OPNsense\\%s\\General' % MODULE)
        settings = (CONTROLLERS / 'Api/SettingsController.php').read_text()
        self.assertEqual(static(settings, 'internalModelClass'), '\\OPNsense\\%s\\General' % MODULE)
        self.assertEqual(static(settings, 'internalModelName'), 'general')
        model = ET.parse(MODELS / 'General.xml').getroot()
        self.assertEqual(model.find('mount').text, '//OPNsense/%s/general' % ROUTE)
        self.assertEqual(static(service, 'internalServiceTemplate').split('/')[1], MODULE)

    def test_the_rc_script_the_plugin_hook_and_the_generated_setting_name_the_same_service(self):
        rc = RC.read_text()
        for line in ['# PROVIDE: kazuhahub_wifiradiusproxy', '# REQUIRE: NETWORKING', '# KEYWORD: shutdown',
                     'name="kazuhahub_wifiradiusproxy"', 'rcvar="kazuhahub_wifiradiusproxy_enable"',
                     ': "${kazuhahub_wifiradiusproxy_enable:=NO}"', 'extra_commands="reload"', 'sig_reload="HUP"']:
            self.assertIn(line, rc)
        self.assertIn('command="/usr/local/sbin/radiusd"', rc)
        self.assertIn('command_args="-d ${kazuhahub_wifiradiusproxy_dir} -n radiusd"', rc)
        self.assertIn('kazuhahub_wifiradiusproxy_dir="/usr/local/etc/kazuhahub-wifi-radius-proxy"', rc)
        inc = read('usr/local/etc/inc/plugins.inc.d/kazuhahubwifiradiusproxy.inc')
        self.assertIn("'name' => 'kazuhahub_wifiradiusproxy'", inc)
        self.assertIn("'pidfile' => '/var/run/kazuhahub-wifi-radius-proxy/radiusd.pid'", inc)
        self.assertIn('pidfile="/var/run/kazuhahub-wifi-radius-proxy/radiusd.pid"', rc)
        for verb in ['restart', 'start', 'stop']:
            self.assertIn("'%s' => ['kazuhahubwifiradiusproxy %s']" % (verb, verb), inc)
        self.assertIn("config_read_array('OPNsense', 'kazuhahubwifiradiusproxy', 'general', false)", inc)
        targets = (TEMPLATES / '+TARGETS').read_text()
        self.assertIn('rc.conf.d:/etc/rc.conf.d/kazuhahub_wifiradiusproxy', targets)
        self.assertIn('kazuhahub_wifiradiusproxy_enable', (TEMPLATES / 'rc.conf.d').read_text())

    def test_the_pid_file_the_rc_script_waits_for_is_the_one_the_daemon_is_told_to_write(self):
        main = (TEMPLATES / 'radiusd.conf').read_text()
        self.assertIn('run_dir = /var/run/kazuhahub-wifi-radius-proxy', main)
        self.assertIn('name = radiusd', main)
        self.assertIn('pidfile = ${run_dir}/${name}.pid', main)

    def test_paths_agree_between_the_rc_script_the_check_the_templates_and_the_exporter(self):
        check = (SCRIPTS / 'check.sh').read_text()
        export = (SCRIPTS / 'export_certs.php').read_text()
        homeservers = (SCRIPTS / 'homeservers.php').read_text()
        rc = RC.read_text()
        for source in [check, rc]:
            self.assertIn('/usr/local/etc/kazuhahub-wifi-radius-proxy', source)
        self.assertIn('conf_dir=/usr/local/etc/kazuhahub-wifi-radius-proxy', check)
        self.assertIn('run_dir=/var/run/kazuhahub-wifi-radius-proxy', check)
        self.assertIn('log_dir=/var/log/kazuhahub-wifi-radius-proxy', check)
        self.assertIn("define('KAZUHAHUB_WIFIRADIUSPROXY_CERT_DIR', '/usr/local/etc/kazuhahub-wifi-radius-proxy/certs');", export)
        self.assertIn("KAZUHAHUB_WIFIRADIUSPROXY_SOCKET = '/var/run/kazuhahub-wifi-radius-proxy/radiusd.sock'", homeservers)
        eap = (TEMPLATES / 'mods/eap').read_text()
        self.assertIn('certdir = ${confdir}/certs', (TEMPLATES / 'radiusd.conf').read_text())
        for name in ['server.key', 'server.pem', 'ca.pem']:
            self.assertIn('/' + name, eap)
            self.assertIn("'%s'" % name, export)
        self.assertIn('socket = ${run_dir}/${name}.sock', (TEMPLATES / 'sites/control').read_text())
        self.assertIn('/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/check.sh', rc)
        self.assertIn('/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/export_certs.php', check)
        # The rc script's refusal and its status page come from the same check.
        for text in ['conflicts', 'start']:
            self.assertIn('"${kazuhahub_wifiradiusproxy_check}" %s' % text, rc)
        for command in ['pkg info -e os-freeradius', 'service -e']:
            self.assertIn(command, check)

    def test_the_listen_wait_reads_the_addresses_the_site_template_writes_and_the_markers_agree(self):
        rc = RC.read_text()
        self.assertIn("sites-enabled/default", rc)
        check = (SCRIPTS / 'check.sh').read_text()
        for marker, template in [('no-listen-address', 'sites/default'), ('no-usable-central', 'proxy.conf'),
                                 ('no-usable-client', 'clients.conf')]:
            self.assertIn('# %s' % marker, (TEMPLATES / template).read_text())
            self.assertIn('# %s$' % marker, check)
        # The rc script records every refusal in the check result and renders before it checks.
        self.assertIn('"${kazuhahub_wifiradiusproxy_check}" record', rc)
        self.assertIn('template reload OPNsense/KazuhaHubWifiRadiusProxy', rc)
        self.assertIn('# REQUIRE: NETWORKING configd', rc)

    def test_the_log_is_rotated_by_a_plain_newsyslog_entry(self):
        text = read('usr/local/etc/newsyslog.conf.d/kazuhahub-wifi-radius-proxy.conf')
        entries = [line.split() for line in text.splitlines() if line and not line.startswith('#')]
        self.assertEqual(len(entries), 1)
        self.assertEqual(entries[0][:3], ['/var/log/kazuhahub-wifi-radius-proxy/radius.log', 'freeradius:freeradius', '640'])
        self.assertEqual(entries[0][-3:], ['JC', '/var/run/kazuhahub-wifi-radius-proxy/radiusd.pid', '1'])
        self.assertIn('${logdir}/radius.log', (TEMPLATES / 'radiusd.conf').read_text())


class CertSyncTests(unittest.TestCase):
    def test_the_sync_is_scheduled_every_six_hours_through_its_configd_action(self):
        inc = read('usr/local/etc/inc/plugins.inc.d/kazuhahubwifiradiusproxy.inc')
        self.assertIn("function kazuhahubwifiradiusproxy_cron()", inc)
        self.assertIn("['/usr/local/sbin/configctl -d kazuhahubwifiradiusproxy certsync', '23', '*/6']", inc)

    def test_the_sync_only_fetches_and_never_listens(self):
        sync = (SCRIPTS / 'certsync.php').read_text()
        for word in ['stream_socket_server', 'socket_listen', 'socket_bind', 'listen =', 'CURLPROTO_HTTPS']:
            self.assertNotIn(word, sync)
        self.assertIn('CURLOPT_PROTOCOLS => CURLPROTO_HTTP,', sync)
        self.assertIn('CURLOPT_FOLLOWLOCATION => false', sync)
        self.assertIn("CURLOPT_NOPROXY => '*'", sync)
        # The expected certificate name is a setting, not a constant.
        self.assertNotIn('kazuha.org', sync)
        self.assertIn("define('KAZUHAHUB_WIFIRADIUSPROXY_SYNC_DIR', '/var/db/kazuhahub-wifi-radius-proxy/certsync');", sync)
        # The exporter prefers the synced set and the sync restarts through the rc script after the check.
        export = (SCRIPTS / 'export_certs.php').read_text()
        self.assertIn("require_once __DIR__ . '/certsync.php';", export)
        self.assertIn("const KAZUHAHUB_WIFIRADIUSPROXY_SYNC_RC = '/usr/local/etc/rc.d/kazuhahub_wifiradiusproxy';", sync)
        self.assertIn("const KAZUHAHUB_WIFIRADIUSPROXY_SYNC_CHECK = '/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/check.sh';", sync)

    def test_the_sync_defaults_to_on_without_a_source(self):
        model = ET.parse(MODELS / 'General.xml').getroot().find('items')
        self.assertEqual(model.find('cert_sync').get('type'), 'BooleanField')
        self.assertEqual(model.find('cert_sync/Default').text, '1')
        # No source and no account ship as defaults: the admin enters them.
        self.assertIsNone(model.find('cert_sync_servers/Default'))
        self.assertIsNone(model.find('cert_sync_host'))
        self.assertIsNone(model.find('cert_sync_port'))
        # The account has no default: nothing is pulled until it is typed.
        self.assertIsNone(model.find('cert_sync_user/Default'))
        self.assertEqual(model.find('cert_sync_password').get('type'), 'UpdateOnlyTextField')
        self.assertIsNone(model.find('cert_sync_password/Default'))
        sync = (SCRIPTS / 'certsync.php').read_text()
        self.assertIn('CURLOPT_HTTPAUTH => CURLAUTH_BASIC,', sync)
        self.assertEqual(ET.parse(MODELS / 'General.xml').getroot().find('version').text, '1.2.0')


class RouteTests(unittest.TestCase):
    def test_menu_acl_and_pages_agree(self):
        menu = ET.parse(MODELS / 'Menu/Menu.xml').getroot()
        urls = {node.get('url') for node in menu.iter() if node.get('url')}
        self.assertEqual(urls, {'/ui/kazuhahubwifiradiusproxy', '/ui/kazuhahubwifiradiusproxy/*'})
        heading = menu.find('Services/KazuhaHubWifiRadiusProxy')
        self.assertEqual((heading.get('VisibleName'), heading.get('order')), ('Kazuha Hub Wi-Fi RADIUS', '93'))
        self.assertTrue((CONTROLLERS / 'IndexController.php').is_file())
        self.assertTrue((MVC / 'views/OPNsense' / MODULE / 'index.volt').is_file())
        acl = ET.parse(MODELS / 'ACL/ACL.xml').getroot()
        self.assertEqual(acl[0].tag, 'page-services-kazuhahubwifiradiusproxy')
        self.assertEqual(acl[0].find('name').text, 'Services: Kazuha Hub Wi-Fi RADIUS')
        self.assertEqual([pattern.text for pattern in acl.iter('pattern')], ['ui/kazuhahubwifiradiusproxy/*', 'api/kazuhahubwifiradiusproxy/*'])

    def test_the_grid_endpoints_the_page_uses_exist_in_the_settings_controller(self):
        volt = (MVC / 'views/OPNsense' / MODULE / 'index.volt').read_text()
        settings = (CONTROLLERS / 'Api/SettingsController.php').read_text()
        used = re.findall(r"'/api/kazuhahubwifiradiusproxy/settings/([a-zA-Z]+)/?'", volt)
        self.assertEqual(sorted(used), sorted(['searchCentral', 'searchCentral', 'getCentral', 'setCentral', 'addCentral',
                                               'delCentral', 'toggleCentral', 'get', 'set']))
        for action in used:
            if action not in ('get', 'set'):
                self.assertRegex(settings, r'public function %sAction\(' % action)
        self.assertRegex(volt, r'data-editDialog="DialogCentral"')
        self.assertIn("'id': 'DialogCentral'", volt)
        for action in ['get', 'set']:
            self.assertIn('/api/kazuhahubwifiradiusproxy/settings/%s' % action, volt)
        for action in ['status', 'homeservers', 'check', 'certsync', 'reconfigure']:
            self.assertIn('/api/kazuhahubwifiradiusproxy/service/%s' % action, volt)

    def test_forms_match_the_model_fields(self):
        model = ET.parse(MODELS / 'General.xml').getroot().find('items')
        general = {child.tag for child in model if child.tag != 'centrals'}
        central = {child.tag for child in model.find('centrals/central')}
        form = ET.parse(CONTROLLERS / 'forms/general.xml').getroot()
        self.assertEqual({field.find('id').text for field in form}, {'general.' + name for name in general})
        dialog = ET.parse(CONTROLLERS / 'forms/dialogCentral.xml').getroot()
        self.assertEqual({field.find('id').text for field in dialog}, {'central.' + name for name in central})

    def test_every_secret_is_an_update_only_field_so_no_endpoint_can_return_it(self):
        model = ET.parse(MODELS / 'General.xml').getroot().find('items')
        secrets = [model.find('client_secret'), model.find('centrals/central/secret')]
        for node in secrets:
            self.assertEqual(node.get('type'), 'UpdateOnlyTextField')
        # No other text-like field is named after a secret, and the password inputs are the only way to type one.
        for node in model.iter():
            if 'secret' in node.tag or 'password' in node.tag:
                self.assertEqual(node.get('type'), 'UpdateOnlyTextField', node.tag)
        for form in [CONTROLLERS / 'forms/general.xml', CONTROLLERS / 'forms/dialogCentral.xml']:
            for field in ET.parse(form).getroot():
                if 'secret' in field.find('id').text:
                    self.assertEqual(field.find('type').text, 'password')

    def test_the_widget_endpoints_are_covered_by_the_acl_and_the_file_is_packaged(self):
        metadata = ET.parse(OPNSENSE / 'www/js/widgets/Metadata/KazuhaHubWifiRadiusProxy.xml').getroot()
        widget = metadata.find(ROUTE)
        self.assertEqual(widget.find('filename').text, 'KazuhaHubWifiRadiusProxy.js')
        self.assertTrue((OPNSENSE / 'www/js/widgets' / widget.find('filename').text).is_file())
        self.assertEqual([endpoint.text for endpoint in widget.iter('endpoint')], ['/api/kazuhahubwifiradiusproxy/service/*'])
        script = (OPNSENSE / 'www/js/widgets/KazuhaHubWifiRadiusProxy.js').read_text()
        for url in re.findall(r"ajaxCall\('([^']+)'", script):
            self.assertTrue(url.startswith('/api/kazuhahubwifiradiusproxy/service/'), url)
        translations = {node.tag for node in widget.find('translations')}
        for name in re.findall(r'this\.translations\.([a-z]+)', script):
            self.assertIn(name, translations)


class ReleaseTests(unittest.TestCase):
    def test_the_version_is_one_value_everywhere(self):
        version = json.loads(read('usr/local/opnsense/version/kazuhahub-wifi-radius-proxy'))
        self.assertEqual({key: version[key] for key in ('product_id', 'product_name', 'product_abi', 'product_arch',
                                                        'product_version', 'product_tier')},
                         {'product_id': 'os-kazuhahub-wifi-radius-proxy', 'product_name': 'kazuhahub-wifi-radius-proxy', 'product_abi': '26.7',
                          'product_arch': 'amd64', 'product_version': '1.2.0', 'product_tier': '4'})
        self.assertEqual(version['product_email'], 'https://github.com/KazuhaHub/')
        self.assertEqual(version['product_website'], 'https://github.com/KazuhaHub/OPNsense-repo')
        build = (PACKAGE / 'build.sh').read_text()
        self.assertIn('VERSION="${VERSION:-1.2.0}"', build)
        self.assertIn('PKG_NAME="${PKG_NAME:-os-kazuhahub-wifi-radius-proxy}"', build)
        self.assertIn('MAINTAINER="${MAINTAINER:-https://github.com/KazuhaHub/}"', build)
        self.assertIn('ORIGIN="${ORIGIN:-opnsense/os-kazuhahub-wifi-radius-proxy}"', build)
        self.assertIn('VERSION?=\t1.2.0', (PACKAGE / 'Makefile').read_text())
        record = json.loads((REPOSITORY / 'packaging/plugins.json').read_text())['plugins']['os-kazuhahub-wifi-radius-proxy']
        self.assertEqual(record['version'], '1.2.0')
        self.assertEqual(record['abi'], 'independent')
        self.assertEqual(record['staging'], 'tree')
        self.assertEqual(record['stage'], {'src': '/'})
        self.assertEqual(record['deps'], {'freeradius3': 'net/freeradius3'})
        self.assertEqual(record['version_file'], {'path': '/usr/local/opnsense/version/kazuhahub-wifi-radius-proxy', 'format': 'json'})
        # A record is the only machinery the verifier accepts: no users, groups or directories keys.
        self.assertFalse({'users', 'groups', 'directories', 'shared', 'vendored', 'generated'} & set(record))

    def test_the_manifest_depends_on_freeradius3_and_nothing_else(self):
        manifest = (PACKAGE / 'packaging/freebsd/+MANIFEST.in').read_text()
        self.assertEqual(re.findall(r'^deps: (.*)$', manifest, re.MULTILINE),
                         ['{ freeradius3: { origin: "net/freeradius3", version: ">=0" } }'])
        for key in ['users:', 'groups:', 'directories:', 'lua_scripts:', 'config:', 'python', 'os-freeradius']:
            self.assertNotIn(key, manifest)
        description = (PACKAGE / 'packaging/freebsd/pkg-descr').read_text()
        self.assertIn('os-freeradius', description)
        self.assertEqual(len(re.findall(r'^name:', manifest, re.MULTILINE)), 1)

    def test_build_lists_every_shipped_file_and_each_one_exists(self):
        build = (PACKAGE / 'build.sh').read_text()
        listed = set(re.findall(r'^need_file "(src/[^"]+)"$', build, re.MULTILINE))
        shipped = {path.relative_to(PACKAGE).as_posix() for path in SRC.rglob('*') if path.is_file()}
        self.assertEqual(listed, shipped)
        for hook in ['+MANIFEST.in', '+PRE_INSTALL', '+POST_INSTALL', '+PRE_DEINSTALL', '+POST_DEINSTALL', 'pkg-descr']:
            self.assertIn('need_file "packaging/freebsd/%s"' % hook, build)
            self.assertTrue((PACKAGE / 'packaging/freebsd' / hook).is_file())
        # Hooks are committed regular files, never links.
        for hook in (PACKAGE / 'packaging/freebsd').iterdir():
            self.assertFalse(hook.is_symlink(), hook)
        self.assertEqual([path for path in SRC.rglob('*') if path.is_symlink()], [])

    def test_only_the_rc_script_is_executable_in_the_package(self):
        build = (PACKAGE / 'build.sh').read_text()
        self.assertIn('chmod 0755 "$STAGEDIR/usr/local/etc/rc.d/kazuhahub_wifiradiusproxy"', build)
        self.assertEqual(re.findall(r'chmod 0755', build), ['chmod 0755'])
        hook = (PACKAGE / 'packaging/freebsd/+POST_INSTALL').read_text()
        self.assertIn('chmod 0755 /usr/local/etc/rc.d/kazuhahub_wifiradiusproxy', hook)
        # Every file the post-install hook chmods exists in the package.
        for command in re.findall(r'^chmod [^\n]+', hook.replace('\\\n', ' '), re.MULTILINE):
            for path in re.findall(r'/usr/local/\S+', command):
                self.assertTrue((SRC / path.lstrip('/')).is_file(), path)

    def test_the_repository_tooling_knows_the_plugin(self):
        ci = (REPOSITORY / '.github/workflows/ci.yml').read_text()
        self.assertRegex(ci, r'for plugin in [^\n]* kazuhahub-wifi-radius-proxy [^\n]*; do')
        for path in ['src/os-kazuhahub-wifi-radius-proxy/build.sh', 'src/os-kazuhahub-wifi-radius-proxy/src/usr/local/etc/rc.d/kazuhahub_wifiradiusproxy',
                     'src/os-kazuhahub-wifi-radius-proxy/src/usr/local/opnsense/scripts/kazuhahub-wifi-radius-proxy/check.sh']:
            self.assertIn(path, ci)
        runner = (REPOSITORY / 'tests/run.py').read_text()
        self.assertIn("'os-kazuhahub-wifi-radius-proxy'", runner)
        packages = (REPOSITORY / 'tests/test_plugin_packages.py').read_text()
        self.assertIn("'os-kazuhahub-wifi-radius-proxy':", packages)

    def test_documentation_is_in_both_languages_and_names_the_conflict_and_the_wan_rule(self):
        chinese = (PACKAGE / 'README.md').read_text()
        english = (PACKAGE / 'README.US.md').read_text()
        for text in [chinese, english]:
            for word in ['os-freeradius', 'freeradius3', 'kazuhahub-wifi-radius-proxy', '1812', 'radmin', 'eapol_test', 'ENTRA',
                         '192.0.2.0/24']:
                self.assertIn(word, text)
        self.assertRegex(chinese, r'[\u4e00-\u9fff]')
        self.assertNotRegex(english, r'[\u4e00-\u9fff]')
        self.assertIn('WAN', chinese)
        self.assertIn('Never on the WAN', english)

    def test_nothing_in_the_plugin_attributes_the_work_to_a_tool(self):
        forbidden = re.compile(r'co-authored|generated by|written by|assisted by|claude|anthropic|codex|openai|copilot', re.I)
        for path in PACKAGE.rglob('*'):
            if path.is_file() and path.suffix not in {'.pyc'} and '__pycache__' not in path.parts and path.name != 'test_wiring.py':
                self.assertIsNone(forbidden.search(path.read_text(errors='replace')), str(path))

    def test_comments_are_english(self):
        for path in PACKAGE.rglob('*'):
            if path.is_file() and path.name != 'README.md' and '__pycache__' not in path.parts:
                self.assertNotRegex(path.read_text(errors='replace'), r'[\u4e00-\u9fff]', str(path))


if __name__ == '__main__':
    unittest.main()
