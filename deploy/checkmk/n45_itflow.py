#!/usr/bin/env python3
# N45 ITFlow via n8n
"""Checkmk notification method. No third-party Python packages are required."""

import argparse
import hashlib
import json
import math
import os
from pathlib import Path
import re
import stat
import sys
from datetime import datetime, timezone
from urllib.error import HTTPError, URLError
from urllib.parse import urlencode, urlsplit
from urllib.request import HTTPRedirectHandler, Request, build_opener


class ConfigurationError(ValueError):
    pass


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def digest(parts):
    return hashlib.sha256(json.dumps(parts, ensure_ascii=False, separators=(",", ":")).encode()).hexdigest()


def https_url(value):
    if not isinstance(value, str) or re.search(r"[\s\\\x00-\x1f\x7f]", value):
        raise ConfigurationError("Invalid HTTPS endpoint")
    try:
        parsed = urlsplit(value)
        port = parsed.port
    except ValueError as error:
        raise ConfigurationError("Invalid HTTPS endpoint") from error
    if parsed.scheme != "https" or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ConfigurationError("Invalid HTTPS endpoint")
    if port not in (None, 443):
        raise ConfigurationError("HTTPS endpoint must use port 443")
    return value


def reference(value, required=False):
    if not isinstance(value, dict):
        raise ConfigurationError("Invalid host mapping")
    result = {}
    if "id" in value:
        if type(value["id"]) is not int or value["id"] < 1:
            raise ConfigurationError("Mapping IDs must be positive integers")
        result["id"] = value["id"]
    if value.get("name"):
        if not isinstance(value["name"], str) or len(value["name"]) > 200:
            raise ConfigurationError("Invalid mapping name")
        result["name"] = value["name"].strip()
    if required and not (result.get("id") or result.get("name")):
        raise ConfigurationError("An explicit client mapping is required")
    return result


def build_event(env, config):
    """Return one canonical lifecycle event, or None for non-actionable notices."""
    notification = env.get("NOTIFY_NOTIFICATIONTYPE", "").upper()
    if notification not in ("PROBLEM", "RECOVERY"):
        return None
    what = env.get("NOTIFY_WHAT", "").upper()
    if what not in ("HOST", "SERVICE"):
        raise ConfigurationError("NOTIFY_WHAT must be HOST or SERVICE")
    for key in ("NOTIFY_HOSTDOWNTIME", "NOTIFY_SERVICEDOWNTIME" if what == "SERVICE" else "NOTIFY_HOSTDOWNTIME"):
        try:
            downtime = int(env.get(key, "0") or "0")
        except ValueError as error:
            raise ConfigurationError("Invalid downtime depth") from error
        if downtime > 0 and notification == "PROBLEM":
            return None
    site = config.get("site", "")
    host = env.get("NOTIFY_HOSTNAME", "")
    service = env.get("NOTIFY_SERVICEDESC", "") if what == "SERVICE" else ""
    if not isinstance(site, str) or not re.fullmatch(r"[A-Za-z0-9_-]{1,64}", site):
        raise ConfigurationError("An explicit site ID is required")
    if not host or len(host) > 255 or (what == "SERVICE" and (not service or len(service) > 1024)):
        raise ConfigurationError("Missing or overlong host/service identity")
    hosts = config.get("hosts", {})
    mapping = hosts.get(host) if isinstance(hosts, dict) else None
    if not isinstance(mapping, dict):
        raise ConfigurationError("Host has no explicit ITFlow mapping")
    client = reference(mapping.get("client", {}), required=True)
    location = reference(mapping.get("location", {}))
    asset = reference(mapping.get("asset", {"name": host}))
    prefix = "SERVICE" if what == "SERVICE" else "HOST"
    raw_state = env.get("NOTIFY_" + prefix + "STATE", "").upper()
    state_map = {"0": "OK", "1": "WARN", "2": "CRIT", "3": "UNKNOWN"} if what == "SERVICE" else {"0": "UP", "1": "DOWN", "2": "UNREACH"}
    raw_state = state_map.get(raw_state, raw_state)
    if what == "SERVICE":
        raw_state = {"WARNING": "WARN", "CRITICAL": "CRIT"}.get(raw_state, raw_state)
    allowed = ("OK", "WARN", "CRIT", "UNKNOWN") if what == "SERVICE" else ("UP", "DOWN", "UNREACH", "UNREACHABLE")
    if raw_state not in allowed:
        raise ConfigurationError("Unknown Checkmk state")
    healthy = raw_state in ("OK", "UP")
    if (notification == "RECOVERY") != healthy:
        raise ConfigurationError("Notification type contradicts the host/service state")
    # A source transition timestamp remains stable when Checkmk retries delivery.
    # Never substitute receipt time: that can let a delayed problem reopen a recovery.
    try:
        epoch = float(env.get("NOTIFY_LAST" + prefix + "STATECHANGE", ""))
        if not math.isfinite(epoch) or epoch <= 0:
            raise ValueError("Invalid timestamp")
        occurred_at = datetime.fromtimestamp(epoch, timezone.utc).isoformat().replace("+00:00", "Z")
    except (ValueError, OverflowError, OSError) as error:
        raise ConfigurationError("Missing source state-change timestamp") from error
    output = env.get("NOTIFY_" + prefix + "OUTPUT", "")[:7000]
    object_key = [site, host, what, service]
    incident_key = "checkmk:object:" + digest(object_key)
    event_id = "checkmk:event:" + digest(object_key + [notification, raw_state, occurred_at, output])
    query = {"view_name": "service" if what == "SERVICE" else "hoststatus", "host": host, "site": site}
    if service:
        query["service"] = service
    source_url = https_url(config.get("checkmk_url", "")).rstrip("/") + "/check_mk/view.py?" + urlencode(query)
    name = host + (" / " + service if service else " / Host availability")
    severity = "low" if healthy else {"WARN": "warning", "CRIT": "critical", "DOWN": "critical"}.get(raw_state, "high")
    return {
        "source": "checkmk", "event_id": event_id, "incident_key": incident_key,
        "entity_type": "host", "state": "resolved" if healthy else "open",
        "severity": severity, "title": ("Checkmk: " + name)[:500],
        "description": (name + " reported " + raw_state + ".\n\n" + output)[:8000],
        "occurred_at": occurred_at, "url": source_url, "auto_resolve": mapping.get("auto_resolve", True) is True,
        "request_type_key": "monitoring-alert", "contact_mode": "none",
        "identity": {
            "entity_type": "host", "external_id": "checkmk:host:" + digest([site, host]),
            "external_name": host, "client": client, "location": location, "asset": asset,
            "options": {"create_client": False, "create_location": False, "create_asset": False},
        },
        "metadata": {"checkmk": {"site": site, "host": host, "service": service, "what": what,
                                   "notification_type": notification, "state": raw_state}},
    }


def read_secret(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    with os.fdopen(descriptor, "r", encoding="utf-8") as handle:
        info = os.fstat(handle.fileno())
        if not stat.S_ISREG(info.st_mode) or info.st_mode & 0o077 or info.st_uid != os.geteuid():
            raise ConfigurationError("Secret file must be private and owned by the site user")
        secret = handle.read(4097).strip()
    if not 20 <= len(secret) <= 4096 or re.search(r"[^\x20-\x7e]", secret):
        raise ConfigurationError("Invalid webhook credential")
    return secret


def deliver(event, config, secret, opener=None):
    request = Request(https_url(config.get("webhook_url", "")),
                      data=json.dumps(event, ensure_ascii=False).encode(), method="POST",
                      headers={"Content-Type": "application/json", "X-N45-Integration-Key": secret})
    try:
        with (opener or build_opener(NoRedirect())).open(request, timeout=20) as response:
            body = response.read(4097)
            if response.status != 202 or len(body) > 4096:
                return 1
            receipt = json.loads(body)
            return 0 if isinstance(receipt, dict) and receipt.get("accepted") is True and receipt.get("queued") is True else 1
    except HTTPError as error:
        return 1 if error.code in (408, 425, 429) or error.code >= 500 else 2
    except (URLError, TimeoutError, OSError, ValueError):
        return 1


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", default=str(Path(os.environ.get("OMD_ROOT", "/omd/sites/cmk")) / "etc/n45-itflow.json"))
    parser.add_argument("--dry-run", action="store_true", help="Print only the mapped event; never read or send a credential")
    args = parser.parse_args(argv)
    try:
        config = json.loads(Path(args.config).read_text(encoding="utf-8"))
        if not isinstance(config, dict):
            raise ConfigurationError("Configuration must be an object")
        event = build_event(os.environ, config)
        if event is None:
            print("N45 ITFlow: notification suppressed by lifecycle/maintenance rules")
            return 0
        if args.dry_run:
            print(json.dumps(event, ensure_ascii=False, indent=2))
            return 0
        result = deliver(event, config, read_secret(config["secret_file"]))
        print("N45 ITFlow: " + {0: "event durably queued", 1: "temporary delivery failure", 2: "delivery/configuration rejected"}[result])
        return result
    except (ConfigurationError, OSError, KeyError, TypeError, json.JSONDecodeError):
        print("N45 ITFlow: configuration or source context is invalid", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
