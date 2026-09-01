# N45 migration and rollback policy

ITFlow upstream and N45 now have independent migration namespaces. Upstream files remain in `admin/database_updates/` and own `settings.config_current_database_version`; this fork's upstream base is `2.6.7`. N45 files live in `n45/migrations/` and are recorded by stable ID and SHA-256 checksum in `n45_schema_migrations`.

Both runners use the `itflow-database-updates` advisory lock. Before upstream migrations run, the updater records the no-op `n45-0000-namespace-foundation` migration. That durable row prevents a future upstream release that reuses a former fork version number from being mistaken for an old fork install. The N45 runner is ordered and retryable: it validates every migration's schema/data fingerprint before writing its ledger row, refuses changed checksums or unknown ledger IDs, and resumes at the first unrecorded ID. MySQL/MariaDB DDL may commit implicitly, so retry safety still depends on idempotent migration SQL.

## One-time legacy bridge

Installs upgraded by the older fork may have an N45 version in the upstream marker. Every former fork number must remain recorded as `legacy_version` metadata after its migration moves into the N45 stream; the released range is currently `2.6.8` through `2.7.7`, and the final feature integration extends it through `2.8.1`. Normal page loads only report this state; they never rewrite it. In a maintenance window:

1. Stop application writers and integration ingress.
2. Take and verify a restorable database snapshot.
3. Record the application commit and current database marker.
4. Use **Admin > Update > Bridge Legacy N45 Migrations**, or run from the application root:

   ```bash
   php scripts/update_cli.php --bridge_n45_migrations
   php scripts/update_cli.php --update_db
   ```

The bridge fingerprints each migration claimed by the legacy marker, then atomically commits its checksum ledger rows and restores the upstream marker to `2.6.7`. If any table, column, index, or data postcondition is absent, it refuses the bridge without changing the marker. An interrupted transaction is safe to retry. Completed bridge rows are distinguished from a future upstream version that happens to reuse an old fork number. The second command applies any remaining upstream migrations first, then pending N45 migrations.

The marker reset is namespace maintenance, **not a schema rollback**. It does not remove columns, restore deleted data, unhash credentials, or make an older checkout safe. A complete pre-upgrade snapshot restore is the only supported rollback across N45 schema changes.

## Fingerprint contract

Released bridge fingerprints fail closed. Detailed column entries verify the normalized SQL type, nullability, default, and extra attributes such as `auto_increment`. Detailed index entries verify uniqueness, the complete indexed-column sequence, and that no unexpected prefix length weakens the index. Unknown sections, incomplete metadata, duplicate identifiers, non-`SELECT` data checks, and partially observed database metadata are rejected before a ledger row is written.

The name-only column and index form remains readable for migrations whose schema is being integrated concurrently, but new or stabilized migrations must use detailed contracts for every security- or integrity-relevant field and index. `tests/n45_schema_fingerprint_test.php` carries a released-file inventory independent of the manifest, so deleting the same migration from both the directory and manifest still fails CI.

## Final feature integration reservations

The manifest reserves the next four feature IDs, plus one compatibility repair, so concurrent branches cannot collide or leave fork migrations in upstream's namespace:

| Former fork file | Stable N45 ID | Module |
| --- | --- | --- |
| `2.7.8.php` | `n45-0011-documentation-readiness` | Documentation |
| `2.7.9.php` | `n45-0012-unified-endpoint-network` | Endpoint |
| `2.8.0.php` | `n45-0013-portal-request-catalog` | Portal requests |
| `2.8.1.php` | `n45-0014-agreement-entitlements` | Agreements |
| — | `n45-0015-documentation-evidence-reference-index` | Documentation compatibility |

When integrating each feature, rename its file into `n45/migrations/`, change its guard to `FROM_N45_DB_UPDATER`, and make its header name the stable ID. Remove checks that read `settings.config_current_database_version` or require the preceding numeric fork marker; stable manifest order is the N45 prerequisite after the namespaces separate. Add the module and ordered migration definition to `n45/manifest.php`; copy the reservation's `legacy_version`, `data_change`, and rollback contract exactly; add complete column, index, and data fingerprints; update the released-file inventory and baseline-schema assertions; then add the migration to the released inventory below. `n45-0011` must retain `legacy_version => '2.7.8'`, not `null`. Remove no reservation: the durable mapping is needed to detect old installations whose upstream marker already contains that former fork number.

The namespace preflight refuses an update if a reserved numeric file still exists under `admin/database_updates/`, if a consumed reservation has the wrong manifest metadata or schema inventory, or if stable IDs are consumed out of order. Once `n45-0011` through `n45-0014` are present, it also requires `n45-0015`. Conversely, `n45-0015` cannot be consumed while any of `n45-0012` through `n45-0014` remains reserved. This deliberately makes an incomplete final merge fail before either migration runner changes the database; the compatibility-repair commit is not a standalone release and its reservation validation is expected to fail until all three intervening feature migrations are integrated.

### Legacy 2.7.8 evidence-index compatibility

The historical numeric `2.7.8.php` created `documentation_evidence_reference` as a **unique** five-column index. The final documentation model intentionally makes the same index non-unique so the same source reference can be verified more than once without overwriting append-only evidence. Treating either shape as generally valid would hide integrity drift.

The compatibility contract is therefore narrow:

1. `n45-0011` keeps the strict final fingerprint with the non-unique index.
2. Its `legacy_bridge_fingerprint` is an exact copy except that one index is unique. Only the explicit legacy bridge selects this alternate contract; normal migration and fresh-install validation continue to require the final shape.
3. `n45-0015-documentation-evidence-reference-index` inspects the existing index before DDL. It accepts only an absent index, the exact historical unique five-column BTREE, or the exact final non-unique five-column BTREE, with ascending columns and no prefix lengths. It adds an absent index, atomically replaces the historical unique index, and leaves the final index unchanged. Every other uniqueness, type, direction, prefix, count, or column-order shape fails closed. A post-DDL inspection must observe the exact final shape before the runner may record the migration. Its final fingerprint remains non-unique.

The bridge records `n45-0011` from the exact historical shape, restores the upstream marker, and then the normal N45 runner applies the repair. Keep application writers stopped through both commands. Do not expose the briefly bridged legacy shape to normal request processing, and do not roll back by recreating the obsolete unique index.

### Experimental 2.7.9 endpoint installs

`n45-0012-unified-endpoint-network` replaces the former numeric `2.7.9.php`. Its normal N45 execution is retry-safe: it creates each endpoint table independently, repairs the finalized canonical-delivery and immutable-reference columns that were absent from earlier experimental table shapes, and verifies uniqueness, complete column order, and prefix lengths for `automation_snapshot_source_entity_hash`. It normalizes only an absent index or the exact unique four-column shape released by n45-0008; the exact final unique six-column shape is a no-op and every other shape fails closed without being dropped.

The legacy bridge remains deliberately read-only. A database whose upstream marker already says `2.7.9` must match the **final** n45-0012 fingerprint before the bridge records the stable ledger row. If an experimental install advanced that marker after only part of the DDL completed, the bridge refuses without changing the marker. Keep writers and integration ingress stopped, take a verified snapshot, repair and validate the endpoint tables and snapshot index to the final `db.sql` shape under a reviewed maintenance procedure, and then retry the bridge. Do not bypass the fingerprint or mark n45-0012 complete manually.

## Released migration inventory

| Stable ID | Legacy marker | Module | Data rewrite | Rollback requirement |
| --- | --- | --- | --- | --- |
| `n45-0000-namespace-foundation` | — | Schema | No | Retain the namespace ledger row; restore a pre-N45 snapshot for a full rollback. |
| `n45-0001-entra-agent-sso` | 2.6.8 | Entra | No | Restore the pre-upgrade snapshot before reverting authentication code. |
| `n45-0002-level-integration` | 2.6.9 | Level | No | Disable Level ingress, preserve mapping history, and restore the snapshot. |
| `n45-0003-automation-integration` | 2.7.0 | Automation | No | Disable ingress, preserve incident evidence, and restore the snapshot. |
| `n45-0004-mail-template-metadata` | 2.7.1 | Mail templates | No | Drain or preserve queued mail and restore the snapshot. |
| `n45-0005-portal-access-scopes` | 2.7.2 | Portal | Yes | Restore the snapshot; the permission backfill is not automatically reversible. |
| `n45-0006-operations-ticket-delete-integrity` | 2.7.3 | Automation | Yes | Restore the snapshot; deleted orphan history has no down migration. |
| `n45-0007-level-interface-links` | 2.7.4 | Level | No | Disable Level processing and restore the snapshot. |
| `n45-0008-external-identity-lifecycle` | 2.7.5 | External identity | Yes | Restore the snapshot; initialized lifecycle state is not down-migrated. |
| `n45-0009-automation-event-lifecycle` | 2.7.6 | Automation | Yes | Disable processing and restore the snapshot; queue state is not safely reversible. |
| `n45-0010-versioned-runbooks` | 2.7.7 | Runbooks | Yes | Restore the snapshot; token hashing and audit baselines are intentionally irreversible. |
| `n45-0011-documentation-readiness` | 2.7.8 | Documentation | Yes | Restore the pre-upgrade snapshot; legacy ticket exemptions and append-only readiness evidence have no down migration. |
| `n45-0012-unified-endpoint-network` | 2.7.9 | Endpoint | No | Disable endpoint ingestion and restore the pre-upgrade database snapshot; reconciled posture and audit history are not down-migrated. |
| `n45-0013-portal-request-catalog` | 2.8.0 | Portal requests | No | Disable portal request publication and submission, preserve request history, and restore the pre-upgrade database snapshot. |
| `n45-0014-agreement-entitlements` | 2.8.1 | Agreements | No | Deploy compatible application code, preserve published agreement and review evidence, and restore the pre-upgrade database snapshot if schema rollback is required. |
| `n45-0015-documentation-evidence-reference-index` | — | Documentation | No | Do not recreate the obsolete unique index; restore the snapshot only with the complete documentation schema. |

Any environment that experimentally ran an earlier local `2.8.0.php` must be restored from its pre-upgrade snapshot or explicitly reconciled to the final portal request schema before release deployment. An advanced numeric marker alone is not proof of the final schema: the legacy bridge fails closed on an older or partial shape. Once namespace state is reconciled, an unrecorded `n45-0013` remains pending and the normal runner executes its retry-safe repair before recording the ledger row.

`n45/manifest.php` is the machine-readable authority. `db.sql` contains the fully migrated schema and the empty ledger table; setup verifies every fingerprint and records all current IDs as `fresh-install`.

## Adding a migration

1. Pick the next never-reused stable ID and create `n45/migrations/<stable-id>.php`.
2. Guard it with `FROM_N45_DB_UPDATER`; make every query fail explicitly and safe to retry.
3. Add the ordered manifest entry, module ownership, legacy metadata (if applicable), detailed column/index fingerprints, data postconditions, data-impact classification, and rollback note.
4. Update `db.sql` to the same end state. Never put N45 files back in `admin/database_updates/`.
5. Extend upgrade, retry, bridge/fingerprint, and baseline-schema tests in a disposable database.
6. Never edit a migration after it has been applied to any environment; add a new migration instead, because the ledger checksum deliberately detects drift.

Feature flags stop optional ingress or processing. They do not undo schema, erase history, bypass deletion cleanup, or make older code compatible with a newer database.
