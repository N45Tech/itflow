# Checkmk → n8n → ITFlow

Checkmk owns infrastructure checks and metrics. ITFlow receives host/service incidents through the existing durable event broker. Start with N45 internal hosts; add client environments only after their host-to-client mappings and notification rules are reviewed. Operations remains an overview, with Checkmk incident labels and source diagnostics.

## What is shipped

- `n45_itflow.py`: standard-library Python notification method for Checkmk Community and commercial sites.
- `n45-itflow.example.json`: empty, fail-closed host allowlist; no credential or invented client IDs.
- Authenticated `Checkmk Webhook` in the generated Operations broker at `/webhook/n45-checkmk-events`.
- Checkmk policy, identity visibility, incident/ticket labels and maintenance support in ITFlow.
- Sender, broker, and disposable-database regression tests in next/main release checks.

Use the installed Checkmk site's notification context to validate these standard fields: `NOTIFY_WHAT`, `NOTIFY_HOSTNAME`, `NOTIFY_SERVICEDESC`, `NOTIFY_NOTIFICATIONTYPE`, `NOTIFY_HOSTSTATE`/`NOTIFY_SERVICESTATE`, `NOTIFY_LASTHOSTSTATECHANGE`/`NOTIFY_LASTSERVICESTATECHANGE`, and optional downtime depth/output fields. See the [notification script interface](https://docs.checkmk.com/latest/en/notifications.html#scripts) and [Checkmk's Nagios notification template](https://github.com/Checkmk/checkmk/blob/master/omd/packages/check_mk/skel/etc/nagios/conf.d/check_mk_templates.cfg). Confirm the actual installed version/context before enabling a production rule.

## Release order

1. Release the ITFlow application change and migration `n45-0032-checkmk-monitoring-source` through the reviewed next→main process.
2. Publish the tested n8n broker draft. Keep its existing credentials, event queue, schedules, routing variable and delivery nodes. `Checkmk Webhook` reuses the broker's existing **N45 Integration Webhook** header credential; its header is `X-N45-Integration-Key`. No credential goes in the URL or JSON.
3. Install/configure the sender on the Checkmk site and prove a controlled lifecycle. Sending to the old application before step 1 returns a retired-source error; do not enable Checkmk notifications early.

## Install on the Checkmk host

The prepared N45 site is `cmk`, with UI at `https://monitor.n45tech.com/cmk/`. The following commands run **inside the Checkmk container/host**, after copying this directory there. Substitute the actual site if different. Run as root only for file installation, then use the site user for validation and execution.

```sh
install -o cmk -g cmk -m 0750 n45_itflow.py /omd/sites/cmk/local/share/check_mk/notifications/n45_itflow
install -o cmk -g cmk -m 0600 n45-itflow.example.json /omd/sites/cmk/etc/n45-itflow.json
install -o cmk -g cmk -m 0600 /dev/null /omd/sites/cmk/etc/n45-itflow-webhook.key
```

Store the existing N45 Integration Webhook credential value in that final file using a secure editor/secret provisioning method. The sender rejects symlinks, broadly readable files, files owned by another user, non-printable/header-injection values and oversized values. Do not put the credential in shell arguments, notification parameters, source control, tickets or logs. The Checkmk site needs Python 3 and normal outbound HTTPS access to n8n; TLS verification stays enabled and redirects are rejected.

The configuration deliberately has no hosts. Add each **exact** Checkmk host name to `hosts` after looking up the actual ITFlow client, location and asset IDs. This illustrative structure uses placeholders, not actual IDs:

```json
{
  "hosts": {
    "EXACT_CHECKMK_HOST_NAME": {
      "client": {"id": "REPLACE_WITH_INTEGER_CLIENT_ID"},
      "location": {"id": "REPLACE_WITH_INTEGER_LOCATION_ID"},
      "asset": {"id": "REPLACE_WITH_INTEGER_ASSET_ID"},
      "auto_resolve": true
    }
  }
}
```

Use positive JSON integers for IDs. A unique client name can replace the client ID; ID routing is preferable. Location is optional. Asset ID is preferable; omitting asset maps by the exact hostname and never creates a new asset. ITFlow validates asset/location ownership against the client. Every host requires an explicit client mapping, and unknown or ambiguous identities fail visibly. The sender does not create clients, locations, assets or domains. Normal n8n source routing (`N45_EVENT_ROUTING_JSON.checkmk`) can assign the technician/category; contact mode defaults to none.

Keep web access behind the existing identity proxy. Register server agents over private management/VPN addresses, and keep the Agent Receiver private. Install vendor agents for Linux/Windows; use SNMP/API/special agents for supported hypervisors and network equipment. Discover and activate services, verify disk/memory/load/process/database checks and tune thresholds before enabling notifications. A configured webhook does not prove any server is monitored.

## Notification rule

In **Setup → Events → Notifications**, select **N45 ITFlow via n8n**, restrict the first rule to the mapped internal hosts, and choose one technical contact for the integration. Send host DOWN/UNREACH and recovery, and service WARN/CRIT/UNKNOWN and recovery. Use hard states, appropriate retry/delay settings, parent relationships and scheduled downtime to control noise. This rule sends incidents; it does not copy graphs or every metric to ITFlow.

The script ignores acknowledgement, custom, flapping and downtime lifecycle notices. Problems with positive host/service downtime depth are suppressed; real recoveries are retained. Checkmk also suppresses normal problem notifications during scheduled downtime. ITFlow's own maintenance windows and source thresholds are checked independently.

Host mapping identity is a hash of site+host. Incident identity is a hash of site+host+HOST/SERVICE+service; recovery reuses that incident key. Delivery identity includes notification type, state, source transition timestamp and bounded output, excluding the receiving contact. This prevents retries or multiple contacts from duplicating an event while preserving independent services and sites. Missing source time is rejected; receipt time cannot make an old failure look newer than recovery.

Exit `0` means a non-actionable notice was suppressed or n8n returned HTTP 202 with both `accepted=true` and `queued=true`. Exit `1` means transient delivery failure; HTTP 408/425/429/5xx and network failures are retryable. Exit `2` means invalid source/configuration or permanent HTTP rejection. Configure and verify the installed edition's notification spooler/retry behavior; do not assume every edition retries automatically. The n8n queue acknowledges only after persistence, retries ITFlow outages, retains terminal failures, and ITFlow rechecks the originating API authority.

## Acceptance

- [ ] Verify the running site's version, agent registration, intended hosts and discovered services.
- [ ] Review exact client/location/asset mappings and current API scope.
- [ ] Dry-run a native Test notification as the site user using `--dry-run`; it never reads or sends the credential.
- [ ] Prove an authorized controlled failure creates one correctly mapped ticket; retrying produces no second ticket.
- [ ] Prove a second service on the same host creates a distinct incident.
- [ ] Prove recovery updates the original ticket and obeys unfinished runbook/approval/customer-promise gates.
- [ ] Prove delayed failures do not reopen a newer recovery, and a later genuine outage creates a new ticket.
- [ ] Prove Checkmk downtime, ITFlow maintenance and policy disable/threshold controls.
- [ ] Prove n8n delivery retry and terminal-error visibility, with no credential retained in execution output.
- [ ] Verify critical incident paging with the intended GoAlert service and recovery route before enabling paging. The separate Alert Router being present does not establish that it is active/configured.
- [ ] Maintain an independent outside-in availability check for the monitoring service. Quiet alert history is not a Checkmk heartbeat or host-coverage proof.

Local/CI checks use synthetic contexts and disposable data. Physical server coverage, the site notification rule, actual network delivery and paging need their own live witness.

## Disable and rollback

Disable the Checkmk notification rule first, then disable its policy in ITFlow if needed. The seed migration uses `INSERT IGNORE`, so upgrades and replay preserve an existing disabled/custom policy. Historical rows are retained; reactivated Checkmk history becomes visible in the overview and diagnostics. Do not restore obsolete source mappings without reviewing their client ownership. Retired NetBox and Uptime Kuma sources stay blocked.
