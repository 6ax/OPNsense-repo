"""The log format the standard log page uses for this plugin's daemon log."""
import importlib.util
from pathlib import Path
import sys
import types
import unittest

PACKAGE = Path(__file__).resolve().parents[1]
SOURCE = PACKAGE / 'src/usr/local/opnsense/scripts/syslog/logformats/kazuhahubwifiradius.py'


def load():
    """Load the format with a stand-in for core's NewBaseLogFormat."""
    package = types.ModuleType('logformats')
    package.__path__ = []

    class NewBaseLogFormat:
        def __init__(self, filename):
            self._filename = filename

    package.NewBaseLogFormat = NewBaseLogFormat
    sys.modules['logformats'] = package
    spec = importlib.util.spec_from_file_location('logformats.kazuhahubwifiradius', SOURCE)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module.KazuhaHubWifiRadiusLogFormat


class LogFormatTests(unittest.TestCase):
    def setUp(self):
        self.format = load()('/var/log/kazuhahub-wifi-radius-proxy/kazuhahub-wifi-radius-proxy_radius.log')

    def parsed(self, line):
        self.assertTrue(self.format.match(line))
        self.format.set_line(line)
        return self.format.timestamp, self.format.severity, self.format.line, self.format.process_name

    def test_logins_are_read_with_time_and_severity(self):
        self.assertEqual(self.parsed('Tue Nov 17 12:00:05 2026 : Auth: (3) Login OK: [user] (from client ap port 0 cli 02-00-00-00-00-01)'),
                         ('2026-11-17T12:00:05', 6, 'Auth: (3) Login OK: [user] (from client ap port 0 cli 02-00-00-00-00-01)', 'radiusd'))
        self.assertEqual(self.parsed('Wed Dec  2 08:01:02 2026 : Auth: (9) Login incorrect: [user] (from client ap port 0)')[:2],
                         ('2026-12-02T08:01:02', 5))
        self.assertEqual(self.parsed('Wed Dec  2 08:01:02 2026 : Auth: Login failed: [user] (from client ap port 0 cli x): '
                                     'no central server answered')[1], 4)

    def test_a_user_name_cannot_change_the_severity(self):
        self.assertEqual(self.parsed('Tue Nov 17 12:00:05 2026 : Auth: Login OK: [Login failed] (from client ap port 0 cli x)')[1], 6)
        self.assertEqual(self.parsed('Tue Nov 17 12:00:05 2026 : Auth: (5) Login incorrect (Home Server failed to respond): [anonymous] '
                                     '(from client ap port 0 cli x)')[1], 5)

    def test_levels_map_to_syslog_severities(self):
        for level, severity in [('Info', 6), ('Warning', 4), ('Error', 3), ('Debug', 7), ('Proxy', 6)]:
            self.assertEqual(self.parsed('Tue Nov 17 12:00:05 2026 : %s: something' % level)[1], severity)

    def test_other_files_and_lines_are_left_to_other_formats(self):
        self.assertFalse(self.format.match('<13>1 2026-11-17T12:00:05 host radiusd 1 - - x'))
        other = load()('/var/log/system/latest.log')
        self.assertFalse(other.match('Tue Nov 17 12:00:05 2026 : Auth: (3) Login OK: [user]'))


if __name__ == '__main__':
    unittest.main()
