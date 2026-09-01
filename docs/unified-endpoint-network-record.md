# Unified Endpoint and Network Record

ITFlow assets remain the canonical device identity. The unified record adds source-specific posture and immutable observations without allowing an integration to move an identity between clients or assets.

## Data model

- `asset_endpoint_states` stores the current normalized state for one external source on one asset. The source/external identity and asset/source pairs are unique.
- `asset_network_observations` stores observation periods. A changed MAC/IP set, VLAN, interface classification, or LLDP/CDP neighbor closes the old row and opens a new row; unchanged observations only advance `last_seen_at`.
- `asset_change_events` is append-only and records redacted before/after documents, a deduplication fingerprint, and optional tenant-validated ticket, document, and evidence references. Immutable reference labels remain visible if a related record is later deleted.
- Existing `assets`, `asset_interfaces`, `asset_interface_links`, `ticket_assets`, `asset_documents`, and runbook evidence remain the systems of record for manually maintained relationships.

The asset page combines these records into assigned user, Entra/Intune identity, compliance, encryption, Secure Boot, operating system/build, Level and SentinelOne health, warranty/lifecycle, physical and virtual interfaces, address history, VLAN/switch adjacency, ticket evidence, and the device/network timeline.

## Source ingestion contract

External adapters use a two-step contract. First register or refresh the durable, tenant-scoped device identity:

```text
POST /api/v1/integrations/endpoint/create.php
Authorization: Bearer <ITFlow API key>
Content-Type: application/json
```

```json
{
  "client_id": 42,
  "asset_id": 314,
  "source": "intune",
  "external_id": "managed-device-id",
  "external_parent_id": "microsoft-tenant-id",
  "external_name": "EXAMPLE-LT-01",
  "state": "automatic",
  "strategy": "adapter_exact_id",
  "confidence": 100,
  "observed_at": "2026-09-01T18:00:00Z",
  "identity_facts": {
    "managed_device_id": "managed-device-id",
    "entra_device_id": "entra-object-id",
    "serial_number": "EXACT-SERIAL"
  },
  "metadata": {
    "adapter": "n8n-intune-v1"
  }
}
```

`client_id` is always required and is checked against the API-key user's Support write permission and client scope. `external_parent_id` is the Microsoft tenant id or SentinelOne site id and is required. The adapter may submit `automatic` only when it has an exact asset id under its deterministic policy. Ambiguous candidates use `suggested` or `conflicting` (and may use `asset_id: 0`) so they enter Operations > Endpoint identity review. Adapters cannot assert `confirmed`, `ignored`, or `retired`: those are technician/lifecycle decisions. Existing nonzero bindings are immutable through this endpoint, a different Microsoft tenant or SentinelOne site fails closed into conflict, and an identity already owned by another client is rejected before mutation.

After the returned mapping is `automatic` or `confirmed`, post normalized posture and topology to:

```text
POST /api/v1/integrations/endpoint/update.php
Authorization: Bearer <ITFlow API key>
Content-Type: application/json
```

The same API key and exact `client_id`, `asset_id`, `source`, and `external_id` are required. Example body:

```json
{
  "client_id": 42,
  "asset_id": 314,
  "source": "intune",
  "external_id": "intune-device-id",
  "status": "active",
  "observed_at": "2026-09-01T18:00:00Z",
  "facts": {
    "assigned_user": {
      "id": "entra-user-id",
      "name": "Example User",
      "email": "user@example.com"
    },
    "entra_device_id": "entra-device-id",
    "compliance_state": "compliant",
    "encryption_state": "encrypted",
    "secure_boot_state": "enabled",
    "operating_system": "Windows 11 Pro",
    "os_version": "23H2",
    "os_build": "22631.4037",
    "last_seen_at": "2026-09-01T17:58:00Z"
  },
  "network_interfaces": [
    {
      "key": "ethernet:1",
      "name": "Ethernet",
      "type": "Ethernet",
      "mac": "00:11:22:33:44:55",
      "ip_addresses": ["10.20.30.40", "fe80::1234"],
      "vlan_id": 30,
      "vlan_name": "Workstations",
      "neighbor_protocol": "lldp",
      "neighbor_name": "switch-01",
      "neighbor_chassis_id": "00:aa:bb:cc:dd:ee",
      "neighbor_port": "Gi1/0/12"
    }
  ]
}
```

The write is transactional. Posture and effective topology share one equal-timestamp winner selected by `(safety rank, posture hash, topology hash, external id)`, so A→B and B→A deliveries converge on the same record. An inactive candidate owns the empty-topology hash. An older or losing equal-second candidate can only advance `last_seen_at`; it cannot alter posture, topology, or the timeline. Timestamps more than five minutes in the future are rejected, and a conflicting, retired, stale, or unconfirmed identity cannot masquerade as active. Retired, conflicting, unmanaged, and unknown source states close their current network observations rather than publishing untrusted topology. A replacement source identifier may bind to an asset only after the prior source row and mapping are both retired; the identity change is written to the device timeline, and invalid rebinds return HTTP 409.

Identity registration uses `observed_at` as the source-inventory sighting watermark. Older replays acknowledge delivery without changing the mapping, and normalized identity snapshots advance their watermark monotonically. A previously retired durable id that reappears becomes a suggestion instead of silently reclaiming its former asset. A technician-confirmed mapping remains confirmed across polling refreshes and stale/recovered cycles. Every discovery, conflict, state change, confirm, ignore, retire, and remap appends a redacted before/after row to `automation_mapping_decisions`.

Endpoint API JSON bodies are limited to 2 MiB, a nesting depth of 64, 10,000 decoded nodes, and 256 items in any one container. Header and query credentials are authenticated before the body is decoded; the bounded body-key compatibility path is decoded first only when no such credential exists. Each reconciliation accepts at most 128 interfaces, 64 supplied addresses per interface, and 2,048 supplied addresses overall; oversized or malformed input receives a 4xx response. Only an explicit allowlist of endpoint facts is retained. Unknown vendor fields, tokens, credentials, and arbitrary payload expansion do not enter the canonical posture row. The network contract transactionally locks referenced interfaces, networks, and neighbors, validates them against the same client, and rejects disagreements between an observation, its ITFlow interface network, and the network's configured VLAN.

### Adapter field contracts and deployment dependency

- **Microsoft Intune:** `external_id` is Microsoft Graph `managedDevice.id`; `external_parent_id` is the customer Microsoft tenant id. Identity facts retain the managed-device id, Entra/Azure AD device id, serial, and source name. Endpoint facts publish Intune device id, Entra device id, compliance, encryption, Secure Boot, primary user id/name/email, OS/version/build, agent/management version, and `lastSyncDateTime`. Interface/address data is included only when the adapter's authoritative source supplies it.
- **Microsoft Entra:** `external_id` is the Entra device object id and `external_parent_id` is the customer Microsoft tenant id. Identity facts retain immutable object/device ids and source name. Endpoint facts publish the Entra id, registered/primary owner where authoritative, OS/version, trust/join details in allowlisted `details`, and the adapter observation time. Entra does not override Intune-owned compliance, encryption, or Secure Boot.
- **SentinelOne:** `external_id` is the SentinelOne agent id and `external_parent_id` is the SentinelOne site id. Identity facts retain agent/site/account ids and source name. Endpoint facts publish independent health, online/last-active state, agent version, OS/version/build, and lifecycle. A site change is a conflict requiring review; it is never treated as a silent client move.
- **Level.io:** ITFlow's built-in Level full sync and signed webhook worker own registration, facts, interfaces, missing-device retirement, and group-to-client routing. External workflows must not register Level identities through the endpoint adapter API.

ITFlow deliberately does **not** store Microsoft Graph/CIPP or SentinelOne polling credentials in this feature and does not poll those services. Production completion therefore requires deployed n8n/CIPP/SentinelOne workers to enumerate every authorized customer tenant/site, apply their deterministic asset match, call `create.php`, and then call `update.php` only for the exact returned binding. Poll failures must be retried by the worker and monitored there. ITFlow's hourly identity reconciliation is the second line of defense: Level mappings become stale after 24 hours without an inventory sighting; Intune, Entra, and SentinelOne become stale after 48 hours. The Cron job records its own failure and emits a deduplicated application notification. It cannot distinguish a deliberately disabled external poller from a broken one, so Operations source coverage and the external worker's health must both be checked.

## Source precedence

Only active or stale rows from Intune, Entra, SentinelOne, Level, and ITFlow can contribute to the unified summary. Intune owns compliance, encryption, Secure Boot, and its device identifier. Intune then Entra own managed assignment; Entra then Intune own the Entra identifier. OS values use Intune, Level, SentinelOne, then Entra, with the base ITFlow asset as fallback. Warranty and asset lifecycle remain manually governed ITFlow fields. Level and SentinelOne health remain separately source-owned so one integration cannot conceal missing coverage in another. Conflicting, unmanaged, unknown, retired, and unrecognized-source rows remain visible for investigation but cannot override the summary.

`unifiedDeviceServiceReviewSnapshot($client_id)` is the stable reporting seam for active-device counts, endpoint-management coverage, and SentinelOne mapping coverage.

Operations shows per-client Level, Intune, Entra, and SentinelOne device coverage, explicit missing Level/Intune counts, managed Windows devices missing SentinelOne, source stale/conflict/review counts, scheduled reconciliation status, the review queue, bulk confirm/ignore/retire, single-record remap, and the append-only decision ledger. Remap requires Full Support permission, exact current and target tenant access, and an explicit reason. It retires old topology before moving the current source-state pointer; Level-managed interfaces are archived and recreated by the next trusted sync.

## Deployment and reconciliation

The stable migration `n45-0012-unified-endpoint-network` creates the endpoint tables and mapping-decision ledger and makes the identity-snapshot replay key tenant- and asset-binding safe. Normal N45 execution is retry-safe for interrupted or earlier experimental 2.7.9 table shapes: it repairs the finalized canonical-delivery and immutable-reference columns and normalizes an absent or exact historical n45-0008 snapshot index. Any other unexpected index shape fails closed without being dropped. A legacy database already marked `2.7.9` must match the final fingerprint before the read-only bridge will record n45-0012; an incomplete experimental install must be snapshotted and repaired to the final `db.sql` shape during the maintenance window before retrying the bridge. After the database update, preview and then apply the reconciler:

```bash
php deploy/psa/reconcile_endpoint_records.php --dry-run
php deploy/psa/reconcile_endpoint_records.php --apply
```

It backfills Level state and interfaces, then consumes the latest available Entra, Intune, and SentinelOne identity snapshots. The command uses an advisory lock and one transaction; each candidate is re-read `FOR UPDATE` after locking its live asset, and dry-run executes the same validation before rolling it back. Mappings without a snapshot are reported and left untouched. Live Level synchronization re-locks and compares both the asset link and identity mapping before persisting asset, interface, or snapshot facts, and requires the exact trusted asset/client binding. A Level full sync locks and verifies the same cascade target before retiring missing device identity, posture, and current topology together; divergence fails closed without mutating device facts. If a Level group starts resolving to a different client, the existing link is quarantined before any asset, interface, or snapshot field is changed; its previously trusted posture and topology are retired pending an explicit asset transfer.

After reconciliation, run a canary with one endpoint that has at least two interfaces and verify:

1. The asset/client identity is unchanged.
2. Level health, OS, and last-seen values appear.
3. Physical/virtual classification and MAC/IP values match Level.
4. A changed address or neighbor creates a new observation and timeline event.
5. A replay does not create another change event.
6. An older delivery cannot restore previous state.
7. A cross-client identity, interface, VLAN, neighbor, or evidence reference is rejected.
8. An ambiguous Level serial enters the review queue without creating another asset.
9. A stale identity is marked by the scheduled job, then returns to its preserved confirmed binding after a fresh adapter delivery.
10. Confirm, ignore, retire, and remap each append a mapping-decision audit row; a restricted technician cannot act on another client's or unbound identity.

Intune, Entra, SentinelOne, VLAN, and LLDP/CDP completeness depends on those sources publishing the normalized fields. Missing sources remain explicitly `Unmanaged` or `Unknown`; they are never inferred as healthy.
