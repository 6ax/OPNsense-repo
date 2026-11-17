"""Render the packaged configd templates the way core's template engine does.

The jinja environment, the registered filters and tests and the helpers object
mirror /usr/local/opnsense/service/modules/template.py and
addons/template_helpers.py of OPNsense 26.7, so a template that renders here
renders the same on the firewall. The configuration a template sees is the
config.xml tree as nested dictionaries: a repeating element is a list, a single
one a dictionary, and a flag such as <enable>1</enable> a string.
"""
import copy
from pathlib import Path
import re
import shlex

import jinja2

PACKAGE = Path(__file__).resolve().parents[1]
TEMPLATE_ROOT = PACKAGE / 'src/usr/local/opnsense/service/templates'
MODULE = 'OPNsense/KazuhaHubRadius'
CONFIG_DIR = '/usr/local/etc/kazuhahub-radius'

# Throwaway secrets generated for the tests only; they match no real system.
CLIENT_SECRET = 'ap-secret-0123456789'
CENTRAL_SECRETS = ['central-one-secret-01', 'central-two-secret-02']


class Helpers:
    """The subset of core's template helpers these templates use."""

    def __init__(self, data):
        self._data = data

    def getNodeByTag(self, tag):
        node = self._data
        for item in tag.split('.'):
            if item in node:
                node = node[item]
            else:
                return None
        return node

    def exists(self, tag):
        return self.getNodeByTag(tag) is not None

    def empty(self, tag):
        node = self.getNodeByTag(tag)
        if node is None or len(node) == 0:
            return True
        return hasattr(node, 'strip') and node.strip() in ('', '0')

    def toList(self, tag, sortBy=None, sortAs=None):
        result = self.getNodeByTag(tag)
        if result is None:
            return []
        if type(result) != list:
            result = [result]
        if sortBy is None:
            return result
        if sortAs == 'int':
            return sorted(result, key=lambda d: int(d[sortBy]))
        return sorted(result, key=lambda d: d[sortBy])


def environment():
    env = jinja2.Environment(loader=jinja2.FileSystemLoader(str(TEMPLATE_ROOT)), trim_blocks=True,
                             extensions=['jinja2.ext.do', 'jinja2.ext.loopcontrols'])
    env.filters['shlex_quote'] = shlex.quote
    env.filters['shlex_split'] = shlex.split
    env.filters['regex_replace'] = lambda value, pattern, replacement: re.sub(pattern, replacement, value)
    env.tests['regex_match'] = lambda value, pattern: bool(re.match(pattern, value))
    return env


def targets():
    """The +TARGETS mapping as core's list_module reads it: source template to target path."""
    result = {}
    for line in (TEMPLATE_ROOT / MODULE / '+TARGETS').read_text().split('\n'):
        parts = line.split(':')
        if len(parts) > 1 and parts[0].strip()[0] != '#':
            result[parts[0].lstrip('!').strip()] = parts[1].strip()
    return result


def render(config):
    """Render every target for a configuration; returns {target path: content}."""
    env = environment()
    out = {}
    for source, target in targets().items():
        data = copy.deepcopy(config)
        env.globals['helpers'] = Helpers(data)
        content = env.get_template('%s/%s' % (MODULE, source)).render(data)
        # Core puts back the last end-of-line jinja drops when the template had one.
        if len(content) > 1 and content[-1] != '\n' and (TEMPLATE_ROOT / MODULE / source).read_bytes()[-1:] == b'\n':
            content += '\n'
        out[target] = content
    return out


def interface(address=None, subnet='24', enable='1', **extra):
    node = {'if': 'em0', 'descr': 'x', 'ipaddr': address or ''}
    if subnet:
        node['subnet'] = subnet
    if enable is not None:
        node['enable'] = enable
    node.update(extra)
    return node


def configuration(enabled='1', interfaces='lan', networks='192.0.2.0/24', centrals=2, client_secret=CLIENT_SECRET,
                  extra_interfaces=None, general=None, central_rows=None):
    """A complete configuration: two central servers, one LAN."""
    rows = central_rows
    if rows is None:
        seeds = [('primary', '198.51.100.10', '1814', '10'), ('backup', '198.51.100.11', '1814', '20')]
        rows = [{'enabled': '1', 'description': name, 'address': address, 'port': port, 'sequence': sequence,
                 'secret': CENTRAL_SECRETS[index]}
                for index, (name, address, port, sequence) in enumerate(seeds[:centrals])]
    node = {'enabled': enabled, 'interfaces': interfaces, 'servercert': 'cert1', 'ca': 'ca1',
            'client_networks': networks, 'client_secret': client_secret,
            'centrals': {'central': rows[0] if len(rows) == 1 else rows} if rows else {'central': []}}
    node.update(general or {})
    config_interfaces = {'wan': interface('203.0.113.9', '28', gateway='WAN_GW', blockpriv='1', blockbogons='1'),
                         'lan': interface('192.0.2.1', '24')}
    config_interfaces.update(extra_interfaces or {})
    return {'OPNsense': {'kazuhahubradius': {'general': node}}, 'interfaces': config_interfaces}
