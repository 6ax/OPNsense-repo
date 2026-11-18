"""
Log format of Kazuha Hub Wi-Fi RADIUS: the daemon log and the login log, as the
plugin's Log File page (the standard log page) reads them.

    Tue Nov 17 12:00:05 2026 : Auth: (3) Login OK: [user] (from client ap port 0 cli 02-00-00-00-00-01)
"""

import re
import time

# Core imports every format here into one registry: should a core update ever rename
# the base class, this file then defines no format core picks up, instead of breaking
# every log page.
try:
    from . import NewBaseLogFormat
except ImportError:
    NewBaseLogFormat = object

_LINE = re.compile(r'^([A-Z][a-z]{2} [A-Z][a-z]{2} [ 0-9]\d \d{2}:\d{2}:\d{2} \d{4}) : ([A-Za-z]+): (.*)$')

_LOGIN = re.compile(r'^(?:\(\d+\) )?Login (OK|incorrect|failed)\b')

# FreeRADIUS levels to syslog severities; a rejected login is a notice, one no
# central server answered a warning.
_SEVERITY = {
    'Debug': 7,
    'Info': 6,
    'Auth': 6,
    'Proxy': 6,
    'Warning': 4,
    'WARNING': 4,
    'Error': 3,
    'ERROR': 3,
}


class KazuhaHubWifiRadiusLogFormat(NewBaseLogFormat):
    def __init__(self, filename):
        super().__init__(filename)
        self._timestamp = None
        self._severity = None
        self._message = None

    def match(self, line):
        return 'kazuhahub-wifi-radius-proxy' in self._filename and _LINE.match(line) is not None

    def set_line(self, line):
        found = _LINE.match(line)
        self._timestamp = None
        self._severity = 6
        self._message = line
        if found is None:
            return
        try:
            self._timestamp = time.strftime('%Y-%m-%dT%H:%M:%S', time.strptime(' '.join(found.group(1).split()), '%a %b %d %H:%M:%S %Y'))
        except ValueError:
            self._timestamp = None
        level, message = found.group(2), found.group(3)
        # Only the start of the message counts, so a user name cannot change the severity.
        login = _LOGIN.match(message) if level == 'Auth' else None
        if login is not None and login.group(1) == 'failed':
            self._severity = 4
        elif login is not None and login.group(1) == 'incorrect':
            self._severity = 5
        else:
            self._severity = _SEVERITY.get(level, 6)
        self._message = '%s: %s' % (level, message)

    @property
    def timestamp(self):
        return self._timestamp

    @property
    def process_name(self):
        return 'radiusd'

    @property
    def pid(self):
        return None

    @property
    def severity(self):
        return self._severity

    @property
    def line(self):
        return self._message
