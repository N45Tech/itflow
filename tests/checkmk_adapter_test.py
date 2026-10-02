import copy
import contextlib
import fcntl
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
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

    def test_outbox_survives_restart_and_duplicate_delivery(self):
        with tempfile.TemporaryDirectory() as directory:
            config = {**self.config, "outbox_dir": str(Path(directory) / "outbox")}
            connection, path = adapter.open_outbox(config)
            event = adapter.build_event(self.env, config)
            adapter.enqueue(connection, event, 100)
            adapter.enqueue(connection, {**event, "title": "Duplicate payload must not replace the original"}, 101)
            connection.close()
            connection, _ = adapter.open_outbox(config)
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM events").fetchone()[0], 1)
            self.assertEqual(json.loads(connection.execute("SELECT payload FROM events").fetchone()[0]), event)
            self.assertEqual((path / "events.sqlite3").stat().st_mode & 0o777, 0o600)
            self.assertIsNone(adapter.outbox_status(connection, 101)["last_flush_age_seconds"])
            seen = []
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda e, *_: seen.append(e) or 0, 102), 0)
            self.assertEqual(seen, [event])
            self.assertEqual(adapter.outbox_status(connection, 103)["pending"], 0)
            self.assertEqual(adapter.outbox_status(connection, 103)["last_flush_age_seconds"], 1)
            connection.close()

    def test_outbox_retries_without_changing_delivery_identity(self):
        with tempfile.TemporaryDirectory() as directory:
            config = {**self.config, "outbox_dir": str(Path(directory) / "outbox")}
            connection, path = adapter.open_outbox(config)
            event = adapter.build_event(self.env, config)
            adapter.enqueue(connection, event, 100)
            seen = []
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda e, *_: seen.append(e) or 1, 100), 1)
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda e, *_: seen.append(e) or 0, 159), 1)
            self.assertEqual(len(seen), 1)
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda e, *_: seen.append(e) or 0, 160), 0)
            self.assertEqual(seen, [event, event])
            connection.close()

    def test_terminal_events_are_held_and_expired_worker_leases_recover(self):
        with tempfile.TemporaryDirectory() as directory:
            config = {**self.config, "outbox_dir": str(Path(directory) / "outbox")}
            connection, path = adapter.open_outbox(config)
            event = adapter.build_event(self.env, config)
            adapter.enqueue(connection, event, 100)
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda *_: 2, 100), 2)
            self.assertEqual(adapter.outbox_status(connection, 200)["held"], 1)
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda *_: self.fail("Held event was retried"), 200), 2)
            with connection:
                connection.execute("UPDATE events SET status='pending',next_attempt=200,lease_until=320")
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda *_: self.fail("Unexpired lease was claimed"), 319), 1)
            self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda *_: 0, 320), 0)
            with (path / "worker.lock").open("w") as lock:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                self.assertEqual(adapter.flush_outbox(connection, path, config, "fixture", lambda *_: self.fail("Concurrent worker ran"), 321), 0)
            connection.close()

    def test_outbox_permissions_symlinks_and_capacity_fail_visibly(self):
        with tempfile.TemporaryDirectory() as directory:
            config = {**self.config, "outbox_dir": str(Path(directory) / "outbox")}
            connection, path = adapter.open_outbox(config)
            event = adapter.build_event(self.env, config)
            with connection:
                connection.executemany("INSERT INTO events(event_id,payload,created_at,next_attempt) VALUES (?,'{}',100,100)",
                                       [(str(i),) for i in range(20000)])
            with self.assertRaises(adapter.ConfigurationError):
                adapter.enqueue(connection, event, 101)
            connection.close()
            (path / "events.sqlite3").chmod(0o644)
            with self.assertRaises(adapter.ConfigurationError):
                adapter.open_outbox(config)
            (path / "events.sqlite3").chmod(0o600)
            path.chmod(0o755)
            with self.assertRaises(adapter.ConfigurationError):
                adapter.open_outbox(config)
            path.chmod(0o700)
            link = Path(directory) / "link"
            link.symlink_to(path)
            with self.assertRaises(adapter.ConfigurationError):
                adapter.open_outbox({**config, "outbox_dir": str(link)})

    def test_notification_cli_queues_without_network_or_credentials(self):
        with tempfile.TemporaryDirectory() as directory:
            config = {**self.config, "outbox_dir": str(Path(directory) / "outbox")}
            config_file = Path(directory) / "config.json"
            config_file.write_text(json.dumps(config))
            with patch.dict(adapter.os.environ, self.env, clear=True), patch.object(adapter, "read_secret", side_effect=AssertionError("Credential read")), \
                    patch.object(adapter, "deliver", side_effect=AssertionError("Network call")), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(adapter.main(["--config", str(config_file), "--dry-run"]), 0)
                self.assertFalse(Path(config["outbox_dir"]).exists())
                self.assertEqual(adapter.main(["--config", str(config_file)]), 0)
                self.assertEqual(adapter.main(["--config", str(config_file), "--status"]), 0)
            connection, _ = adapter.open_outbox(config)
            self.assertEqual(adapter.outbox_status(connection)["pending"], 1)
            with connection:
                connection.execute("UPDATE events SET status='held'")
            with contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(adapter.main(["--config", str(config_file), "--retry-held"]), 0)
            self.assertEqual(adapter.outbox_status(connection)["held"], 0)
            self.assertEqual(adapter.outbox_status(connection)["pending"], 1)
            connection.close()

    def test_local_check_reports_backlog_held_and_worker_failure(self):
        status = {"pending": 0, "held": 0, "oldest_pending_age_seconds": 0, "last_flush_age_seconds": 60}
        for changes, expected in [({}, 0), ({"pending": 1, "oldest_pending_age_seconds": 301}, 1),
                                  ({"pending": 1, "oldest_pending_age_seconds": 901}, 2), ({"held": 1}, 2),
                                  ({"last_flush_age_seconds": None}, 2), ({"last_flush_age_seconds": 301}, 2)]:
            with self.subTest(changes=changes):
                self.assertTrue(adapter.local_check({**status, **changes}).startswith(str(expected) + ' "N45 ITFlow notification outbox" '))


if __name__ == "__main__":
    unittest.main()
