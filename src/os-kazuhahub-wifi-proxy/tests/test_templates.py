"""Render every template with jinja2 and check the generated files reproduce the intended behaviour."""
import re
import unittest

import render_support as support
from render_support import CENTRAL_SECRETS, CLIENT_SECRET, CONFIG_DIR, configuration, interface, render, targets

ROW = {'enabled': '1', 'description': 'x', 'port': '1814', 'sequence': '10'}


def strip_comment(line):
    quoted = False
    for index, char in enumerate(line):
        if char == '"':
            quoted = not quoted
        elif char == '#' and not quoted:
            return line[:index]
    return line


def parse(text):
    """A small reader for the FreeRADIUS configuration syntax: sections, assignments and bare calls."""
    root = {'head': None, 'items': []}
    stack = [root]
    for number, raw in enumerate(text.splitlines(), 1):
        line = strip_comment(raw).strip()
        if not line:
            continue
        if line == '}':
            if len(stack) == 1:
                raise AssertionError('Unbalanced } on line %d' % number)
            stack.pop()
        elif line.endswith('{'):
            node = {'head': line[:-1].strip(), 'items': []}
            stack[-1]['items'].append(node)
            stack.append(node)
        else:
            match = re.fullmatch(r'(\S+)\s*(:=|\+=|=)\s*(.+)', line)
            if match:
                stack[-1]['items'].append({'key': match.group(1), 'op': match.group(2), 'value': match.group(3)})
            else:
                stack[-1]['items'].append({'call': line})
    if len(stack) != 1:
        raise AssertionError('Unclosed section: %s' % stack[-1]['head'])
    return root


def sections(node, head):
    return [item for item in node['items'] if item.get('head') == head]


def section(node, head):
    found = sections(node, head)
    if len(found) != 1:
        raise AssertionError('Expected one %s section, found %d' % (head, len(found)))
    return found[0]


def values(node, key):
    return [item['value'] for item in node['items'] if item.get('key') == key]


def calls(node):
    return [item['call'] for item in node['items'] if 'call' in item]


def heads(node):
    return [item['head'] for item in node['items'] if 'head' in item]


def path(name):
    return CONFIG_DIR + '/' + name


class TemplateTests(unittest.TestCase):
    def files(self, **kwargs):
        return render(configuration(**kwargs))

    def test_the_targets_are_exactly_the_files_this_configuration_needs(self):
        files = self.files()
        self.assertEqual(set(files), {
            '/etc/rc.conf.d/kazuhahub_wifiproxy',
            path('radiusd.conf'), path('clients.conf'), path('proxy.conf'),
            path('mods-enabled/eap'), path('mods-enabled/always'), path('mods-enabled/attr_filter'),
            path('policy.d/kazuhahub'), path('mods-config/attr_filter/access_reject'),
            path('sites-enabled/default'), path('sites-enabled/inner-tunnel'), path('sites-enabled/control')})
        # Everything lands in the private directory, except the service setting.
        for target in files:
            self.assertTrue(target.startswith(CONFIG_DIR + '/') or target == '/etc/rc.conf.d/kazuhahub_wifiproxy', target)
        for content in files.values():
            self.assertTrue(content.endswith('\n'))

    def test_every_template_file_is_a_target_and_every_target_has_a_template(self):
        root = support.TEMPLATE_ROOT / support.MODULE
        shipped = {entry.relative_to(root).as_posix() for entry in root.rglob('*') if entry.is_file()} - {'+TARGETS'}
        self.assertEqual(shipped, set(targets()))

    def test_every_generated_file_is_balanced_and_nothing_reads_the_stock_configuration(self):
        for name, content in self.files().items():
            with self.subTest(file=name):
                parse(content)
                self.assertNotIn('/usr/local/etc/raddb', content)
                self.assertNotIn('raddb/', content.replace('raddbdir', ''))
                self.assertNotRegex(content, r'(?m)^\s*\$INCLUDE\s+(/|\.\.)')

    def test_the_service_setting_follows_the_enable_flag(self):
        self.assertEqual(self.files()['/etc/rc.conf.d/kazuhahub_wifiproxy'], 'kazuhahub_wifiproxy_enable="YES"\n')
        self.assertEqual(self.files(enabled='0')['/etc/rc.conf.d/kazuhahub_wifiproxy'], 'kazuhahub_wifiproxy_enable="NO"\n')
        config = configuration()
        del config['OPNsense']
        self.assertEqual(render(config)['/etc/rc.conf.d/kazuhahub_wifiproxy'], 'kazuhahub_wifiproxy_enable="NO"\n')

    # --- the default virtual server -------------------------------------------------

    def default_site(self, **kwargs):
        return section(parse(self.files(**kwargs)[path('sites-enabled/default')]), 'server default')

    def test_the_default_site_reproduces_the_production_behaviour(self):
        site = self.default_site()
        listeners = sections(site, 'listen')
        self.assertEqual(len(listeners), 1)
        self.assertEqual([(i['key'], i['value']) for i in listeners[0]['items']],
                         [('type', 'auth'), ('ipaddr', '192.0.2.1'), ('port', '1812')])
        authorize = section(site, 'authorize')
        self.assertEqual(calls(authorize), ['filter_username', 'reject'])
        self.assertEqual(heads(authorize), ['eap'])
        self.assertEqual([(i['key'], i['value']) for i in section(authorize, 'eap')['items']], [('ok', 'return')])
        # The order matters: the EAP module answers before the final reject.
        self.assertEqual([item.get('call') or item.get('head') for item in authorize['items']],
                         ['filter_username', 'eap', 'reject'])
        self.assertEqual(calls(section(site, 'authenticate')), ['eap'])
        self.assertEqual(calls(section(site, 'post-proxy')), ['eap'])
        reject = section(section(site, 'post-auth'), 'Post-Auth-Type REJECT')
        self.assertEqual(calls(reject), ['attr_filter.access_reject', 'eap', 'remove_reply_message_if_eap'])
        self.assertEqual(heads(site), ['listen', 'authorize', 'authenticate', 'post-proxy', 'post-auth'])

    def test_it_listens_on_each_selected_static_interface_and_nowhere_else(self):
        extra = {'opt1': interface('198.51.100.1', '24'), 'opt2': interface('10.9.0.1', '24', blockpriv='0', blockbogons='0')}
        site = self.default_site(interfaces='lan,opt1,opt2', extra_interfaces=extra)
        self.assertEqual([values(item, 'ipaddr') for item in sections(site, 'listen')],
                         [['192.0.2.1'], ['198.51.100.1'], ['10.9.0.1']])
        # A repeated interface and a repeated address listen once.
        extra['opt3'] = interface('198.51.100.1', '24')
        site = self.default_site(interfaces='lan,lan,opt1,opt3', extra_interfaces=extra)
        self.assertEqual([values(item, 'ipaddr') for item in sections(site, 'listen')], [['192.0.2.1'], ['198.51.100.1']])

    def test_the_wan_and_anything_wan_like_never_gets_a_listener(self):
        extra = {
            'opt1': interface('198.51.100.2', '24', gateway='OTHER_GW'),         # an upstream gateway
            'opt2': interface('dhcp', None),                                      # dynamic
            'opt3': interface('pppoe', None),
            'opt4': interface('10.4.0.1', '24', enable=None),                     # not enabled
            'opt5': interface('', None),                                          # no address
            'opt6': interface('10.6.0.1', '24', blockpriv='1'),                   # blocks private networks, like a WAN
            'opt7': interface('10.7.0.1', '24', blockbogons='1'),
            'opt8': interface('127.0.0.1', '8'),
            'opt9': interface('0.0.0.0', '8'),
            'opt10': interface('224.0.0.1', '4'),
            'opt11': interface('10.11.0.999', '24'),
            'opt12': {'descr': 'no addressing at all'},
        }
        selection = 'wan,' + ','.join(sorted(extra))
        site = self.default_site(interfaces=selection, extra_interfaces=extra)
        listeners = sections(site, 'listen')
        # Nothing usable: only the loopback fallback, which the rc script refuses to start with.
        self.assertEqual([values(item, 'ipaddr') for item in listeners], [['127.0.0.1']])
        text = self.files(interfaces=selection, extra_interfaces=extra)[path('sites-enabled/default')]
        self.assertIn('# no-listen-address', text)
        for forbidden in ['203.0.113.9', '198.51.100.2', '10.6.0.1', '10.7.0.1', '10.4.0.1', '224.0.0.1', '*', '0.0.0.0']:
            self.assertNotIn(forbidden, text)
        # Mixed with a good interface, only the good one is used and no fallback is added.
        text = self.files(interfaces='wan,lan,' + ','.join(sorted(extra)), extra_interfaces=extra)[path('sites-enabled/default')]
        self.assertEqual(re.findall(r'ipaddr = (\S+)', text), ['192.0.2.1'])
        self.assertNotIn('no-listen-address', text)

    def test_a_missing_or_empty_selection_never_produces_a_wildcard_listener(self):
        for interfaces in ['', 'nonexistent', ' , ']:
            with self.subTest(interfaces=interfaces):
                text = self.files(interfaces=interfaces)[path('sites-enabled/default')]
                self.assertEqual(re.findall(r'ipaddr = (\S+)', text), ['127.0.0.1'])
                self.assertIn('# no-listen-address', text)
        config = configuration()
        del config['OPNsense']
        text = render(config)[path('sites-enabled/default')]
        self.assertEqual(re.findall(r'ipaddr = (\S+)', text), ['127.0.0.1'])
        config = configuration()
        del config['interfaces']
        self.assertEqual(re.findall(r'ipaddr = (\S+)', render(config)[path('sites-enabled/default')]), ['127.0.0.1'])
        for target, content in render(configuration()).items():
            self.assertNotRegex(content, r'ipaddr = (\*|0\.0\.0\.0)', target)
            self.assertNotRegex(content, r'ipv4addr = (\*|0\.0\.0\.0)', target)

    def test_the_only_other_listeners_are_the_loopback_tunnel_and_the_control_socket(self):
        files = render(configuration())
        listeners = []
        for name in ['sites-enabled/default', 'sites-enabled/inner-tunnel', 'sites-enabled/control']:
            tree = parse(files[path(name)])

            def walk(node):
                for item in node['items']:
                    if item.get('head') == 'listen':
                        listeners.append({entry['key']: entry['value'] for entry in item['items']})
                    elif 'head' in item:
                        walk(item)
            walk(tree)
        self.assertEqual(listeners, [
            {'type': 'auth', 'ipaddr': '192.0.2.1', 'port': '1812'},
            {'ipaddr': '127.0.0.1', 'port': '18120', 'type': 'auth'},
            {'type': 'control', 'socket': '${run_dir}/${name}.sock', 'mode': 'ro'}])

    # --- the inner tunnel -----------------------------------------------------------

    def test_the_inner_tunnel_hands_the_pap_request_to_the_central_realm(self):
        site = section(parse(self.files()[path('sites-enabled/inner-tunnel')]), 'server inner-tunnel')
        self.assertEqual(heads(site), ['listen', 'authorize', 'authenticate', 'post-auth', 'pre-proxy', 'post-proxy'])
        authorize = section(site, 'authorize')
        self.assertEqual([item.get('call') or item.get('head') for item in authorize['items']],
                         ['filter_username', 'if (!&User-Password)', 'update control'])
        self.assertEqual(calls(section(authorize, 'if (!&User-Password)')), ['reject'])
        self.assertEqual([(i['key'], i['op'], i['value']) for i in section(authorize, 'update control')['items']],
                         [('&Proxy-To-Realm', ':=', '"ENTRA"')])
        for empty in ['authenticate', 'pre-proxy', 'post-proxy']:
            self.assertEqual(section(site, empty)['items'], [])
        self.assertEqual(calls(section(section(site, 'post-auth'), 'Post-Auth-Type REJECT')), ['attr_filter.access_reject'])

    def test_the_control_socket_is_read_only_and_inside_the_private_run_directory(self):
        listen = section(parse(self.files()[path('sites-enabled/control')]), 'listen')
        self.assertEqual({item['key']: item['value'] for item in listen['items']},
                         {'type': 'control', 'socket': '${run_dir}/${name}.sock', 'mode': 'ro'})
        main = parse(self.files()[path('radiusd.conf')])
        self.assertEqual(values(main, 'run_dir'), ['/var/run/kazuhahub-wifi-proxy'])
        self.assertEqual(values(main, 'name'), ['radiusd'])

    # --- proxy.conf -----------------------------------------------------------------

    def proxy(self, **kwargs):
        return parse(self.files(**kwargs)[path('proxy.conf')])

    def home_servers(self, tree):
        return [item for item in tree['items'] if str(item.get('head', '')).startswith('home_server ') and
                not str(item['head']).startswith('home_server_pool')]

    def test_two_central_servers_form_a_fail_over_pool_in_the_configured_order(self):
        tree = self.proxy()
        self.assertEqual([values(item, 'default_fallback') for item in sections(tree, 'proxy server')], [['no']])
        servers = self.home_servers(tree)
        self.assertEqual([item['head'] for item in servers], ['home_server central_1', 'home_server central_2'])
        expected = {'type': 'auth', 'port': '1814', 'require_message_authenticator': 'yes', 'response_window': '15',
                    'zombie_period': '40', 'status_check': 'status-server', 'check_interval': '30',
                    'check_timeout': '4', 'num_answers_to_alive': '3'}
        for server, address, secret in zip(servers, ['198.51.100.10', '198.51.100.11'], CENTRAL_SECRETS):
            options = {item['key']: item['value'] for item in server['items']}
            self.assertEqual(options, {**expected, 'ipaddr': address, 'secret': '"%s"' % secret})
        pool = section(tree, 'home_server_pool central')
        self.assertEqual(values(pool, 'type'), ['fail-over'])
        self.assertEqual(values(pool, 'home_server'), ['central_1', 'central_2'])
        realm = section(tree, 'realm ENTRA')
        self.assertEqual(values(realm, 'auth_pool'), ['central'])
        self.assertEqual(calls(realm), ['nostrip'])
        self.assertEqual(heads(tree), ['proxy server', 'home_server central_1', 'home_server central_2',
                                        'home_server_pool central', 'realm ENTRA'])

    def test_one_central_server_is_a_pool_of_one(self):
        tree = self.proxy(centrals=1)
        self.assertEqual([item['head'] for item in self.home_servers(tree)], ['home_server central_1'])
        self.assertEqual(values(section(tree, 'home_server_pool central'), 'home_server'), ['central_1'])

    def test_the_sequence_decides_the_order_numerically_and_disabled_servers_are_left_out(self):
        rows = [
            {**ROW, 'address': '10.0.0.30', 'sequence': '100', 'secret': 'thirty-secret-0001'},
            {**ROW, 'address': '10.0.0.20', 'sequence': '9', 'secret': 'twenty-secret-0002'},
            {**ROW, 'address': '10.0.0.40', 'sequence': '5', 'secret': 'forty-secret-00003', 'enabled': '0'},
            {**ROW, 'address': '10.0.0.10', 'sequence': '10', 'secret': 'tenten-secret-0004', 'port': '1645'},
        ]
        tree = self.proxy(central_rows=rows)
        servers = self.home_servers(tree)
        self.assertEqual([values(item, 'ipaddr')[0] for item in servers], ['10.0.0.20', '10.0.0.10', '10.0.0.30'])
        self.assertEqual([values(item, 'port')[0] for item in servers], ['1814', '1645', '1814'])
        self.assertEqual(values(section(tree, 'home_server_pool central'), 'home_server'),
                         ['central_1', 'central_2', 'central_3'])

    def test_without_an_enabled_central_server_there_is_no_realm_to_proxy_to(self):
        for rows in [[], [{**ROW, 'address': '10.0.0.1', 'secret': 'sssssssss1', 'enabled': '0'}]]:
            tree = self.proxy(central_rows=rows)
            self.assertEqual(heads(tree), ['proxy server'])
            self.assertIn('# no-usable-central', self.files(central_rows=rows)[path('proxy.conf')])
        config = configuration()
        del config['OPNsense']
        self.assertEqual(heads(parse(render(config)[path('proxy.conf')])), ['proxy server'])

    def test_a_malformed_central_server_is_skipped_rather_than_written(self):
        good = {**ROW, 'address': '10.0.0.9', 'secret': 'good-secret-000001'}
        for bad in [{'address': 'not-an-address'}, {'address': '10.0.0.999'}, {'address': '10.0.0.1/24'}, {'address': ''},
                    {'port': '0'}, {'port': '70000'}, {'port': 'abc'}, {'port': ''}, {'address': '1.2.3.4}\nrealm X'}, {'address': '10.0.0.8\n'}, {'port': '1814\n'}]:
            with self.subTest(bad=bad):
                row = {**ROW, 'address': '10.0.0.8', 'secret': 'bad-secret-0000001', **bad}
                servers = self.home_servers(self.proxy(central_rows=[row, good]))
                self.assertEqual([values(item, 'ipaddr')[0] for item in servers], ['10.0.0.9'])

    def test_a_secret_that_could_break_the_configuration_is_never_written(self):
        # An unusable entry is left out with a marker, never written with an empty or unsafe secret.
        for secret in ['abc"def-ghijkl', 'abc\\def-ghijkl', 'abc$def-ghijkl', 'abc def ghijkl', 'short', '', None,
                       'ab\nsecret = "x"', "abc'def-ghijkl", 'a' * 129, 'trailing-newline-ok\n']:
            with self.subTest(secret=secret):
                files = self.files(client_secret=secret, central_rows=[{**ROW, 'address': '10.0.0.9', 'secret': secret}])
                proxy, clients = files[path('proxy.conf')], files[path('clients.conf')]
                self.assertEqual(self.home_servers(parse(proxy)), [])
                self.assertEqual(heads(parse(proxy)), ['proxy server'])
                self.assertIn('# no-usable-central', proxy)
                self.assertEqual(parse(clients)['items'], [])
                self.assertIn('# no-usable-client', clients)
                self.assertNotRegex(proxy + clients, r'secret = ""')
                if secret:
                    self.assertNotIn(secret, proxy + clients)

    def test_one_unusable_central_does_not_take_the_others_with_it(self):
        rows = [{**ROW, 'address': '10.0.0.1', 'secret': ''}, {**ROW, 'address': '10.0.0.2', 'secret': 'good-secret-000001', 'sequence': '20'}]
        proxy = self.files(central_rows=rows)[path('proxy.conf')]
        self.assertEqual([values(item, 'ipaddr')[0] for item in self.home_servers(parse(proxy))], ['10.0.0.2'])
        self.assertNotIn('no-usable-central', proxy)

    def test_the_markers_appear_only_when_something_is_missing(self):
        files = self.files()
        for name, marker in [('proxy.conf', 'no-usable-central'), ('clients.conf', 'no-usable-client'),
                             ('sites-enabled/default', 'no-listen-address')]:
            self.assertNotIn(marker, files[path(name)])
        files = self.files(central_rows=[], networks='', interfaces='wan')
        for name, marker in [('proxy.conf', 'no-usable-central'), ('clients.conf', 'no-usable-client'),
                             ('sites-enabled/default', 'no-listen-address')]:
            self.assertRegex(files[path(name)], r'(?m)^\s*# ' + marker + '$')

    def test_empty_or_missing_values_never_crash_the_rendering(self):
        for general in [{'client_networks': None}, {'interfaces': None}, {'client_secret': None}, {'client_networks': ''},
                        {'client_networks': None, 'interfaces': None, 'client_secret': None, 'centrals': ''}]:
            with self.subTest(general=general):
                files = self.files(general=general)
                self.assertEqual(len(files), 12)
        files = self.files(general={'client_networks': None})
        self.assertIn('# no-usable-client', files[path('clients.conf')])
        files = self.files(general={'interfaces': None})
        self.assertIn('# no-listen-address', files[path('sites-enabled/default')])

    # --- uplinks ----------------------------------------------------------------------

    def uplink_config(self, **kwargs):
        config = configuration(**kwargs)
        return config

    def listen_addresses(self, config):
        return re.findall(r'ipaddr = (\S+)', render(config)[path('sites-enabled/default')])

    def test_an_interface_a_gateway_points_at_never_gets_a_listener(self):
        # 26.7 keeps gateways in the Gateways model; older configurations under gateways.
        for place in ['mvc', 'legacy']:
            for items in [{'interface': 'lan'}, [{'interface': 'opt9'}, {'interface': 'lan', 'disabled': '1'}]]:
                with self.subTest(place=place, items=items):
                    config = self.uplink_config()
                    if place == 'mvc':
                        config['OPNsense']['Gateways'] = {'gateway_item': items}
                    else:
                        config['gateways'] = {'gateway_item': items}
                    self.assertEqual(self.listen_addresses(config), ['127.0.0.1'])
                    self.assertIn('# no-listen-address', render(config)[path('sites-enabled/default')])
        # A gateway on another interface leaves the LAN alone, and so does an empty section.
        for gateways in [{'gateway_item': {'interface': 'wan'}}, {'gateway_item': [{'interface': 'wan'}, {'interface': 'opt3'}]},
                         {'gateway_item': []}, {}, '', {'gateway_item': {'interface': None}}, {'gateway_item': 'garbage'}]:
            with self.subTest(gateways=gateways):
                config = self.uplink_config()
                config['OPNsense']['Gateways'] = gateways
                self.assertEqual(self.listen_addresses(config), ['192.0.2.1'])
        # Only the uplink loses its listener when several interfaces are selected.
        config = self.uplink_config(interfaces='lan,opt1', extra_interfaces={'opt1': interface('10.1.0.1', '24')})
        config['OPNsense']['Gateways'] = {'gateway_item': {'interface': 'opt1'}}
        self.assertEqual(self.listen_addresses(config), ['192.0.2.1'])

    # --- client networks -------------------------------------------------------------

    def test_repeated_networks_become_one_client(self):
        tree = parse(self.files(networks='10.7.0.0/24,10.7.0.0/24, 172.16.5.9 ,172.16.5.9/32,192.0.2.0/24')[path('clients.conf')])
        self.assertEqual([values(item, 'ipv4addr')[0] for item in tree['items']], ['10.7.0.0/24', '172.16.5.9', '192.0.2.0/24'])
        self.assertEqual([item['head'] for item in tree['items']],
                         ['client kazuhahub-ap-1', 'client kazuhahub-ap-2', 'client kazuhahub-ap-3'])

    def test_secrets_with_every_allowed_character_are_written_verbatim(self):
        secret = 'Az09!#&()*+,./:;<=>?@^_{}|~[]-'
        files = self.files(client_secret=secret, central_rows=[{**ROW, 'address': '10.0.0.9', 'secret': secret}])
        self.assertEqual(values(self.home_servers(parse(files[path('proxy.conf')]))[0], 'secret'), ['"%s"' % secret])
        self.assertEqual(values(sections(parse(files[path('clients.conf')]), 'client kazuhahub-ap-1')[0], 'secret'), ['"%s"' % secret])

    # --- clients.conf ---------------------------------------------------------------

    def test_one_client_per_network_with_the_access_point_secret(self):
        tree = parse(self.files(networks='192.0.2.0/24,10.7.0.0/24,172.16.5.9')[path('clients.conf')])
        clients = [item for item in tree['items'] if item['head'].startswith('client ')]
        self.assertEqual([item['head'] for item in clients],
                         ['client kazuhahub-ap-1', 'client kazuhahub-ap-2', 'client kazuhahub-ap-3'])
        self.assertEqual([values(item, 'ipv4addr')[0] for item in clients], ['192.0.2.0/24', '10.7.0.0/24', '172.16.5.9'])
        for client in clients:
            self.assertEqual({item['key']: item['value'] for item in client['items']}, {
                'ipv4addr': client['items'][0]['value'], 'secret': '"%s"' % CLIENT_SECRET,
                'require_message_authenticator': 'auto', 'limit_proxy_state': 'auto'})

    def test_no_client_is_written_for_a_bad_network_and_never_a_localhost_client(self):
        for networks in ['', 'nonsense', '10.0.0.0/7', '10.0.0.0/33', '999.1.1.1/24', 'fe80::/10', '*', 'any']:
            with self.subTest(networks=networks):
                tree = parse(self.files(networks=networks)[path('clients.conf')])
                self.assertEqual(tree['items'], [])
        text = self.files()[path('clients.conf')]
        self.assertNotIn('127.0.0.1', text)
        self.assertEqual(self.files(networks='192.0.2.0/24, bad ,10.7.0.0/24')[path('clients.conf')].count('client kazuhahub-ap-'), 2)

    # --- modules, policy and main file ---------------------------------------------

    def test_the_eap_module_is_ttls_only_with_the_production_tls_settings(self):
        eap = section(parse(self.files()[path('mods-enabled/eap')]), 'eap')
        self.assertEqual({item['key']: item['value'] for item in eap['items'] if 'key' in item}, {
            'default_eap_type': 'ttls', 'timer_expire': '60', 'ignore_unknown_eap_types': 'no',
            'max_sessions': '${max_requests}'})
        self.assertEqual(heads(eap), ['tls-config tls-common', 'ttls'])
        tls = section(eap, 'tls-config tls-common')
        self.assertEqual({item['key']: item['value'] for item in tls['items'] if 'key' in item}, {
            'private_key_file': '${certdir}/server.key', 'certificate_file': '${certdir}/server.pem',
            'ca_file': '${cadir}/ca.pem', 'cipher_list': '"HIGH"', 'cipher_server_preference': 'yes',
            'tls_min_version': '"1.2"', 'tls_max_version': '"1.3"', 'ecdh_curve': '""'})
        self.assertEqual(values(section(tls, 'cache'), 'enable'), ['no'])
        self.assertEqual(values(section(tls, 'ocsp'), 'enable'), ['no'])
        self.assertEqual({item['key']: item['value'] for item in section(eap, 'ttls')['items']}, {
            'tls': 'tls-common', 'default_eap_type': 'pap', 'copy_request_to_tunnel': 'no',
            'use_tunneled_reply': 'no', 'virtual_server': '"inner-tunnel"'})
        # No password for the key: it is exported unencrypted for the daemon.
        self.assertNotIn('private_key_password', self.files()[path('mods-enabled/eap')])

    def test_only_the_modules_the_sites_use_are_configured(self):
        files = self.files()
        always = parse(files[path('mods-enabled/always')])
        self.assertEqual(heads(always), ['always ' + name for name in
                                          ['reject', 'fail', 'ok', 'handled', 'invalid', 'userlock', 'notfound', 'noop', 'updated']])
        for item in always['items']:
            self.assertEqual(values(item, 'rcode'), [item['head'].split()[1]])
        attr = section(parse(files[path('mods-enabled/attr_filter')]), 'attr_filter attr_filter.access_reject')
        self.assertEqual({item['key']: item['value'] for item in attr['items']},
                         {'key': '"%{User-Name}"', 'filename': '${modconfdir}/attr_filter/access_reject'})
        # The filter file keeps what an Access-Reject may carry, and nothing else.
        rows = re.findall(r'^\s+(\S+) =\* ANY', files[path('mods-config/attr_filter/access_reject')], re.MULTILINE)
        self.assertEqual(rows, ['EAP-Message', 'State', 'Message-Authenticator', 'Error-Cause', 'Reply-Message',
                                'MS-CHAP-Error', 'Proxy-State'])
        self.assertEqual(len(re.findall(r'(?m)^DEFAULT$', files[path('mods-config/attr_filter/access_reject')])), 1)

    def test_the_policy_file_carries_exactly_the_two_policies_the_sites_call(self):
        tree = parse(self.files()[path('policy.d/kazuhahub')])
        self.assertEqual(heads(tree), ['filter_username', 'remove_reply_message_if_eap'])
        text = self.files()[path('policy.d/kazuhahub')]
        for rule in ['User-Name contains whitespace', 'Multiple @ in User-Name', 'multiple ..s', 'at least one dot',
                     'Realm ends with a dot', 'Realm begins with a dot']:
            self.assertIn(rule, text)
        remove = section(tree, 'remove_reply_message_if_eap')
        self.assertEqual(heads(remove), ['if (&reply:EAP-Message && &reply:Reply-Message)', 'else'])
        self.assertEqual(calls(section(remove, 'else')), ['noop'])
        # Every policy and module the sites call exists in the generated files.
        files = self.files()
        known = set(heads(tree)) | {'eap', 'reject', 'attr_filter.access_reject'}
        for name in ['sites-enabled/default', 'sites-enabled/inner-tunnel']:
            def walk(node):
                for item in node['items']:
                    if 'call' in item:
                        self.assertIn(item['call'], known, item['call'])
                    elif 'head' in item and not item['head'].startswith(('listen', 'if', 'update', 'else')):
                        walk(item)
            walk(parse(files[path(name)]))

    def test_the_main_file_is_self_contained_and_never_logs_passwords(self):
        tree = parse(self.files()[path('radiusd.conf')])
        self.assertEqual(values(tree, 'raddbdir'), ['/usr/local/etc/kazuhahub-wifi-proxy'])
        self.assertEqual(values(tree, 'logdir'), ['/var/log/kazuhahub-wifi-proxy'])
        self.assertEqual(values(tree, 'run_dir'), ['/var/run/kazuhahub-wifi-proxy'])
        self.assertEqual(values(tree, 'pidfile'), ['${run_dir}/${name}.pid'])
        self.assertEqual(values(tree, 'max_requests'), ['16384'])
        log = section(tree, 'log')
        options = {item['key']: item['value'] for item in log['items']}
        self.assertEqual(options['destination'], 'files')
        self.assertEqual(options['file'], '${logdir}/radius.log')
        self.assertEqual((options['auth_badpass'], options['auth_goodpass']), ('no', 'no'))
        security = section(tree, 'security')
        self.assertEqual(values(security, 'user'), ['freeradius'])
        self.assertEqual(values(security, 'group'), ['freeradius'])
        self.assertEqual(values(security, 'allow_core_dumps'), ['no'])
        self.assertEqual(sorted(item['call'] for item in tree['items'] if 'call' in item),
                         ['$INCLUDE clients.conf', '$INCLUDE proxy.conf', '$INCLUDE sites-enabled/'])
        self.assertEqual([calls(item) for item in sections(tree, 'modules')], [['$INCLUDE mods-enabled/']])
        self.assertEqual([calls(item) for item in sections(tree, 'policy')], [['$INCLUDE policy.d/']])
        self.assertEqual(values(tree, 'proxy_requests'), ['yes'])
        # The dictionaries and the module libraries are the compiled-in defaults of freeradius3,
        # so an upgrade of the package, which changes the library directory, never breaks this file.
        text = self.files()[path('radiusd.conf')]
        self.assertNotIn('libdir', text)
        self.assertNotIn('dictdir', text)

    # --- secrets stay where they belong ------------------------------------------------

    def test_the_secrets_appear_only_in_the_two_files_that_need_them(self):
        files = render(configuration())
        holders = {name for name, content in files.items()
                   if any(secret in content for secret in [CLIENT_SECRET] + CENTRAL_SECRETS)}
        self.assertEqual(holders, {path('clients.conf'), path('proxy.conf')})
        self.assertIn(CLIENT_SECRET, files[path('clients.conf')])
        self.assertNotIn(CLIENT_SECRET, files[path('proxy.conf')])
        for secret in CENTRAL_SECRETS:
            self.assertIn(secret, files[path('proxy.conf')])
            self.assertNotIn(secret, files[path('clients.conf')])
        # Descriptions are free text and never reach a generated file.
        rows = [{**ROW, 'address': '10.0.0.9', 'secret': 'good-secret-000001', 'description': 'DESCRIPTION-MARKER } realm'}]
        for content in render(configuration(central_rows=rows)).values():
            self.assertNotIn('DESCRIPTION-MARKER', content)


if __name__ == '__main__':
    unittest.main()
