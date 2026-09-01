# Intune, Entra, and SentinelOne device-source adapters

These adapters keep Microsoft Intune, Microsoft Entra ID, CIPP, and SentinelOne in their specialized roles. n8n schedules and paginates source reads, converts only approved fields, and publishes the normalized device contract to ITFlow. ITFlow remains authoritative for client, location, and asset identity. CIPP is the Microsoft Graph access and pagination broker: CIPP-brokered reads emit `intune` or `entra` identities rather than a separate `cipp` device identity. Checkmk host and service identities belong to the alert/event pipeline; alert silence is not an endpoint inventory heartbeat and therefore does not participate in endpoint staleness or coverage.

No additional database migration is required. The implementation uses the existing `automation_entity_mappings`, `automation_entity_snapshots`, `asset_endpoint_states`, `asset_network_observations`, and `asset_change_events` tables.

## Workflows and schedules

| Workflow | Upstream API | Default UTC schedule |
| --- | --- | --- |
| `N45 - Microsoft Intune Device Reconciliation` | CIPP `ListGraphRequest` → Graph `deviceManagement/managedDevices` | `10 */6 * * *` |
| `N45 - Microsoft Entra Device Reconciliation` | CIPP `ListGraphRequest` → Graph `devices` | `40 */6 * * *` |
| `N45 - SentinelOne Agent Reconciliation` | SentinelOne v2.1 `sites` and `agents` | `55 */6 * * *` |

Every workflow is inactive on import and includes a manual trigger. Change schedules only after a successful canary.

The Microsoft workflows ask CIPP for manual pagination. n8n returns CIPP's exact opaque Microsoft Graph `nextLink` cursor and never edits it. The SentinelOne workflow follows `pagination.nextCursor`. Source calls and ITFlow publications retry five times, and source pages are paced to reduce throttling. A failed or truncated fetch stops before the completion record, so it cannot retire missing devices.

## Configuration

Create these n8n Variables:

- `N45_CIPP_BASE_URL`: the HTTPS base URL of the CIPP API, without credentials or query parameters.
- `N45_SENTINELONE_BASE_URL`: the regional SentinelOne management-console HTTPS base URL.
- `N45_DEVICE_SOURCE_MAP_JSON`: the compact JSON contents of `deploy/n8n/samples/device-source-map.example.json`, with real ITFlow IDs and source scopes.

The map is configuration, not a credential. Keep it in n8n Variables so tenant/site ownership can change without regenerating workflow JSON. Each `scope_id` must be unique within a source. `tenant_filter` is the CIPP tenant domain or customer ID; `site_id` is the SentinelOne site ID. `client_id` and optional `location_id` must already exist in ITFlow and be accessible to the API technician.

`create_asset` defaults to `false`. Leave it false for the first production cycles: devices resolve by saved source ID, serial number, or exact same-client/same-location name. Unresolved devices receive a durable mapping and redacted snapshot and reduce coverage, but do not create ambiguous assets. Enable automatic asset creation per scope only after the unresolved list has been reviewed.

`retirement_guard_percent` defaults to 50. A full sync whose record count falls below that percentage of the current scoped inventory fails closed. An empty source also fails closed when the scope previously contained devices. Set `allow_empty` only for a verified, intentionally empty tenant/site; use `retirement_guard_percent: 0` only for a supervised bulk-removal cycle, then restore the guard.

## Credentials and least privilege

Create and assign these n8n credentials after importing the workflows:

1. `N45 CIPP API`: generic OAuth2 client-credentials credential for the CIPP API application. Use the CIPP API token endpoint, client ID, client secret, and `.default` scope shown by the deployment's **CIPP → Integrations → CIPP-API** configuration. Give the API client a custom read-only role that permits `ListGraphRequest`. CIPP's service application needs Microsoft Graph application permissions `DeviceManagementManagedDevices.Read.All` for Intune and `Device.Read.All` for Entra. Do not put an access token or client secret in a workflow, Variable, URL, or mapping JSON.
2. `N45 SentinelOne API`: Header Auth named `Authorization`, with value `ApiToken <token>`. Create a dedicated read-only SentinelOne service user/token scoped only to the mapped accounts/sites and able to list sites and agents. Confirm endpoint names and response fields against the API documentation shipped by the deployed SentinelOne console before activation.
3. `N45 ITFlow API`: Header Auth named `Authorization`, with value `Bearer <ITFlow API key>`. Tie it to a dedicated active technician with Support write permission and access only to mapped clients.

Configure `N45 - Automation Failure to ITFlow` as the Error Workflow on all three source workflows. Its source-specific branch writes a redacted last error to every mapped scope; its existing branch also opens the normal automation incident. n8n credentials remain encrypted in n8n and are not exported in the workflow JSON.

## Publication and full-sync contract

The workflows post records serially to:

```text
POST /api/v1/integrations/device_source/update.php
```

A `publish` action resolves or refreshes the immutable source identity, records an allowlisted snapshot, and, when mapped, atomically updates endpoint posture and topology. The source `scope_id` is stored as `automation_mapping_external_parent_id`.

After every source page has normalized successfully, the workflow sends one `complete` action per mapped tenant/site. ITFlow retires only device mappings that match all of these conditions:

- exact source;
- exact parent tenant/site scope;
- exact ITFlow client;
- not already retired; and
- `last_synced_at` older than this cycle's start time.

Each candidate is re-read immediately before retirement. Retirement uses the existing identity cascade, which retires source posture and closes current network observations. Older/out-of-order cycle completions are acknowledged without changing state. Completion is emitted after all `publish` items and the HTTP node uses one-item batches, so it cannot overtake device delivery.

The final completion also stores a synthetic `sync_scope` mapping with last success, reported/mapped/unmapped/retired counts, coverage percentage, and the last completed cycle. A `failure` action marks that scope stale while preserving its previous last-success timestamp.

Read current health with:

```text
GET /api/v1/integrations/device_source/read.php?source=intune&client_id=42
Authorization: Bearer <ITFlow API key>
```

The response is RBAC/client scoped. A healthy cycle has `state: automatic`, a recent `last_success_at`, an empty `last_error`, and expected coverage. `state: stale` means the last scheduled execution failed. Coverage below 100% means one or more source devices could not be bound to an ITFlow asset.

## Normalized source fields

| Source | Identity and assignment | Posture | Network |
| --- | --- | --- | --- |
| Intune | managed-device ID, Entra device ID, assigned user, name/serial/make/model | compliance, encryption, Secure Boot, OS/version, management state, last sync | Wi-Fi and Ethernet MACs when supplied |
| Entra | directory object ID and Entra `deviceId`, display name | account enabled, compliance flag, OS/version, approximate last sign-in | none from the directory inventory |
| SentinelOne | agent ID, site ID, computer name/serial | agent/EDR health, active threats, firewall, OS/version, last active | agent-reported interfaces, MAC, IPv4, and IPv6 |

Only ITFlow's endpoint allowlist is retained. Vendor response expansion, tokens, authorization headers, phone/IMEI fields, last-logged-in-user values, and arbitrary metadata are discarded. Error text is scrubbed for bearer/basic/API-token values, common secret query parameters, and common JSON secret fields before storage or incident delivery.

## Deployment and canary

1. Deploy the ITFlow application version containing the unified endpoint tables and this adapter API. No adapter-specific migration is needed.
2. Rebuild and test the JSON with `node deploy/n8n/build-workflows.mjs` and `node deploy/n8n/test-workflows.mjs`.
3. Import the three new workflow JSON files and the updated error workflow. Assign credentials and Variables, but keep workflows inactive.
4. Verify every map against the ITFlow client/location IDs and the source tenant/site IDs. Confirm that the ITFlow API technician can access each mapped client and no others.
5. Run one tenant/site manually with `create_asset: false`. Confirm source pagination reaches its terminal cursor, the completion action is last, and the health endpoint reports the expected source count.
6. Review unresolved mappings. Bind or create the intended ITFlow assets, replay the canary, and require expected coverage before expanding scope.
7. Canary one device with a changed compliance/security value and one interface change. Verify the endpoint summary and timeline change once, replay creates no duplicate event, and an older delivery cannot restore prior state.
8. Remove one test device from a non-production source scope or use an approved disposable record. Confirm a complete full sync retires that source identity and closes its topology without archiving or moving the ITFlow asset.
9. Force a source 429/5xx and a bad credential in a test scope. Verify retry behavior, no completion/retirement on failure, redacted error text, stale health, and the automation incident.
10. Activate one workflow for one full schedule interval, then expand mappings incrementally. Monitor n8n executions, ITFlow source health, unresolved mappings, retirement count, and coverage.

## Rollback

Deactivate the three workflows first. Do not delete mappings or endpoint history. Revert the application deployment if necessary; this feature adds no schema. Rotate or revoke the CIPP/SentinelOne credentials if compromise is suspected. A bad completion is recoverable by correcting the map and replaying a full source cycle: existing source IDs bind back to their immutable ITFlow assets, while any identity remap still fails closed for technician review.
