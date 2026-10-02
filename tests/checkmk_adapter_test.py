import copy
import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from urllib.error import HTTPError, URLError

spec = importlib.util.spec_from_file_location("checkmk_adapter", Path(__file__).resolve().parents[1] / "deploy/checkmk/n45_itflow.py")
adapter = importlib.util.module_from_spec(spec)
spec.loader.exec_module(adapter)


class CheckmkAdapterTests(unittest.TestCase):
    def setUp(self):
        self.config = {"site": "cmk", "checkmk_url": "https://monitor.example.net/cmk/",
                       "webhook_url": "https://automate.example.net/webhook/checkmk",
                       "hosts": {"ops01": {"client": {"id": 7}, "location": {"id": 9}, "asset": {"id": 11}}}}
        self.env = {"NOTIFY_WHAT": "SERVICE", "NOTIFY_HOSTNAME": "ops01", "NOTIFY_SERVICEDESC": "Filesystem /",
                    "NOTIFY_NOTIFICATIONTYPE": "PROBLEM", "NOTIFY_SERVICESTATE": "CRIT",
                    "NOTIFY_LASTSERVICESTATECHANGE": "1790956800", "NOTIFY_SERVICEOUTPUT": "Disk full"}

    def test_lifecycle_has_stable_host_and_distinct_service_keys(self):
        problem = adapter.build_event(self.env, self.config)
        recovery = adapter.build_event({**self.env, "NOTIFY_NOTIFICATIONTYPE": "RECOVERY", "NOTIFY_SERVICESTATE": "OK",
                                       "NOTIFY_LASTSERVICESTATECHANGE": "1790957100"}, self.config)
        other = adapter.build_event({**self.env, "NOTIFY_SERVICEDESC": "MySQL"}, self.config)
        self.assertEqual(problem["incident_key"], recovery["incident_key"])
        self.assertNotEqual(problem["event_id"], recovery["event_id"])
        self.assertEqual(problem["identity"]["external_id"], other["identity"]["external_id"])
        self.assertNotEqual(problem["incident_key"], other["incident_key"])
        self.assertEqual(problem, adapter.build_event({**self.env, "NOTIFY_CONTACTNAME": "another-contact"}, self.config))
        self.assertEqual(problem["identity"]["client"]["id"], 7)
        self.assertFalse(problem["identity"]["options"]["create_asset"])
        self.assertEqual(recovery["state"], "resolved")
        self.assertIn("service=Filesystem+%2F", problem["url"])

    def test_host_service_site_and_delimiter_collisions_are_isolated(self):
        service = adapter.build_event(self.env, self.config)
        host_env = {**self.env, "NOTIFY_WHAT": "HOST", "NOTIFY_HOSTSTATE": "DOWN", "NOTIFY_LASTHOSTSTATECHANGE": "1790956800"}
        host = adapter.build_event(host_env, self.config)
        self.assertEqual(host["severity"], "critical")
        self.assertNotEqual(host["incident_key"], service["incident_key"])
        self.assertNotEqual(service["incident_key"], adapter.build_event(self.env, {**self.config, "site": "other"})["incident_key"])
        self.assertNotEqual(adapter.digest(["a:b", "c"]), adapter.digest(["a", "b:c"]))

    def test_state_table(self):
        for state, severity in [("WARN", "warning"), ("WARNING", "warning"), ("CRIT", "critical"), ("2", "critical"), ("UNKNOWN", "high")]:
            with self.subTest(state=state):
                self.assertEqual(adapter.build_event({**self.env, "NOTIFY_SERVICESTATE": state}, self.config)["severity"], severity)
        for state in ["OK", "0", "nonsense"]:
            with self.subTest(state=state), self.assertRaises(adapter.ConfigurationError):
                adapter.build_event({**self.env, "NOTIFY_SERVICESTATE": state}, self.config)

    def test_non_lifecycle_and_downtime_are_suppressed(self):
        for kind in ["ACKNOWLEDGEMENT", "CUSTOM", "FLAPPINGSTART", "FLAPPINGSTOP", "DOWNTIMESTART", "DOWNTIMEEND"]:
            self.assertIsNone(adapter.build_event({**self.env, "NOTIFY_NOTIFICATIONTYPE": kind}, self.config))
        for key in ["NOTIFY_HOSTDOWNTIME", "NOTIFY_SERVICEDOWNTIME"]:
            self.assertIsNone(adapter.build_event({**self.env, key: "1"}, self.config))
        self.assertIsNotNone(adapter.build_event({**self.env, "NOTIFY_NOTIFICATIONTYPE": "RECOVERY", "NOTIFY_SERVICESTATE": "OK", "NOTIFY_HOSTDOWNTIME": "1"}, self.config))

    def test_unmapped_and_missing_source_time_fail_closed(self):
        for changes in [{"NOTIFY_HOSTNAME": "foreign"}, {"NOTIFY_LASTSERVICESTATECHANGE": ""},
                        {"NOTIFY_LASTSERVICESTATECHANGE": "nan"}, {"NOTIFY_LASTSERVICESTATECHANGE": "-1"},
                        {"NOTIFY_NOTIFICATIONTYPE": "RECOVERY"}, {"NOTIFY_SERVICEDESC": ""}]:
            with self.subTest(changes=changes), self.assertRaises(adapter.ConfigurationError):
                adapter.build_event({**self.env, **changes}, self.config)
        invalid = copy.deepcopy(self.config)
        invalid["hosts"]["ops01"]["client"] = {"id": 0}
        with self.assertRaises(adapter.ConfigurationError):
            adapter.build_event(self.env, invalid)

    def test_secret_file_permissions_and_header_safety(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "credential"
            path.write_text("x" * 32)
            path.chmod(0o600)
            self.assertEqual(adapter.read_secret(path), "x" * 32)
            path.chmod(0o644)
            with self.assertRaises(adapter.ConfigurationError):
                adapter.read_secret(path)
            path.chmod(0o600)
            path.write_text("x" * 32 + "\r\nInjected: header")
            with self.assertRaises(adapter.ConfigurationError):
                adapter.read_secret(path)
            link = Path(directory) / "link"
            link.symlink_to(path)
            with self.assertRaises(OSError):
                adapter.read_secret(link)

    def test_https_and_redirect_protection(self):
        for url in ["http://example.net", "https://user:pass@example.net/", "https://example.net/?token=x",
                    "https://example.net/#x", "https://example.net:444/", "https://example.net\\@evil.net/", "https://example.net/\n"]:
            with self.subTest(url=url), self.assertRaises(adapter.ConfigurationError):
                adapter.https_url(url)
        self.assertIsNone(adapter.NoRedirect().redirect_request(None, None, 302, "", {}, "https://other.net"))

    def test_durable_receipt_and_retry_classification(self):
        class Response:
            def __init__(self, status, body): self.status, self.body = status, body
            def __enter__(self): return self
            def __exit__(self, *args): return False
            def read(self, size): return self.body[:size]
        class Opener:
            def __init__(self, value): self.value = value
            def open(self, request, timeout):
                self.request = request
                if isinstance(self.value, Exception): raise self.value
                return self.value
        event = adapter.build_event(self.env, self.config)
        for status, body, expected in [(202, b'{"accepted":true,"queued":true}', 0), (200, b'{}', 1),
                                       (202, b'{"accepted":true}', 1), (202, b'<html>login</html>', 1), (202, b'x' * 5000, 1)]:
            opener = Opener(Response(status, body))
            self.assertEqual(adapter.deliver(event, self.config, "x" * 32, opener), expected)
            self.assertEqual(opener.request.method, "POST")
            self.assertNotIn("x" * 32, opener.request.data.decode())
        for code in [301, 302, 400, 401, 403, 404, 408, 425, 429, 500, 503]:
            error = HTTPError("https://example.net", code, "fixture", {}, None)
            self.assertEqual(adapter.deliver(event, self.config, "x" * 32, Opener(error)), 1 if code in [408, 425, 429, 500, 503] else 2)
        self.assertEqual(adapter.deliver(event, self.config, "x" * 32, Opener(URLError("offline"))), 1)


if __name__ == "__main__":
    unittest.main()
