# N45 exact-SHA release checklist

Use one copy of this checklist as the deployment-ticket record. A checked box
requires a link, identifier, command output, screenshot, export, or named
approver in the evidence field. Never carry a check mark to a different commit:
any code change creates a new candidate and requires the pre-GO gates again.

Goals 6, 9, and 10 are deferred and are not release claims for this candidate.
This checklist covers active Goals 1–5, 7, 8, and 11.

## Release identity

- Release branch:
- Exact commit SHA:
- Exact tree SHA:
- Reviewed upstream SHA and merge base:
- GitHub commit URL:
- Deployment ticket / evidence archive:
- Release owner:
- Deployment operator:
- Final GO approver:

## Phase A — pre-GO release freeze

For the automated production path, merging a reviewed pull request into
protected `main` is the exact-SHA GO. The deployment workflow records the main
commit and independently requires successful PHPLint, N45 Upstream Parity, and
SQL Syntax Check for db.sql push runs for that same SHA. The explicit human GO
below remains mandatory for emergency or exceptional releases outside the
protected-main workflow.

All items in this phase must be complete before requesting permission to change
`infra01`. Production backups, migrations, and canaries occur only after that
explicit GO and before traffic is reopened.

- [ ] `origin/codex/recovered-all-goals` resolves to the exact candidate SHA.
  Evidence:
- [ ] The candidate tree is clean for tracked files; its tree SHA is recorded
  above, and no later commit is being substituted.
  Evidence:
- [ ] The authoritative ITFlow upstream head used for review is recorded. The
  parity report shows the expected merge base, zero unreviewed overlaps, zero
  security-sensitive overlaps, no stale allowlist entry, and no behind count.
  Evidence:
- [ ] **PHP Linting**, **N45 Upstream Parity**, and **SQL Syntax Check for
  db.sql** all completed successfully for this exact SHA. A green run on an
  ancestor does not count.
  Evidence:
- [ ] The generated n8n workflows reproduce without a diff and their workflow
  and representative-payload tests pass.
  Evidence:
- [ ] The migration inventory, checksums, final-schema fingerprints, upgrade
  harness, retry behavior, lock-order concurrency checks, and all PHP regression
  contracts passed in CI.
  Evidence:
- [ ] The release diff has been reviewed for authentication, authorization,
  client isolation, secret handling, file finalization, destructive actions,
  terminal ticket writers, database transactions, and audit retention.
  Evidence:
- [ ] The current backup/restore procedure has a recent successful off-host
  restore drill, and the operator has confirmed capacity and access for a fresh
  database plus `psa_app_data` snapshot during this window.
  Evidence:
- [ ] Rollback triggers, maintenance contacts, outage window, external n8n
  schedule owners, kill switches, and the last safe application/database pair
  are recorded in the deployment ticket.
  Evidence:
- [ ] Disposable client, contacts, devices, source scopes, tickets, and review
  dates needed by the canary matrix are prepared and cannot affect real client
  notifications, billing, retention, or vendor actions.
  Evidence:
- [ ] The release owner has presented this exact SHA and Phase A evidence to the
  final approver. No `infra01` change has started.
  Evidence:

### Final authorization

- [ ] Explicit **GO** received for the exact SHA above.
  Approver / timestamp / approval record:

Stop if the SHA changes after GO. Return to Phase A and obtain a new approval.

## Phase B — maintenance-window safety

- [ ] Record baseline web, portal, cron, queue, database, disk, and integration
  health. Confirm the existing release is still serving before quiescing it.
  Evidence:
- [ ] Stop external n8n schedules and other integration ingress. Stop `web` and
  `cron`; keep MariaDB running. Verify no application writer remains.
  Evidence:
- [ ] Take fresh off-host snapshots of the database and `psa_app_data`. Record
  locations, timestamps, sizes and cryptographic checksums.
  Evidence:
- [ ] Prove the snapshots are readable and restorable using the approved restore
  verification procedure. A backup command returning zero is not restore proof.
  Evidence:
- [ ] Check out the exact approved SHA, verify it again, build the immutable web
  image with `--pull`, and record the image ID/digest. Do not use the in-app Git
  updater or `docker compose up -d --build`.
  Evidence:

## Phase C — migrations and reconciliation

- [ ] Run `php scripts/update_cli.php --update_db` in a one-off web container
  while application writers remain stopped. If the explicit legacy bridge is
  required, follow [migrations.md](migrations.md); never insert or mark ledger
  rows manually.
  Evidence:
- [ ] Rerun the database update and require an already-current result with no
  checksum, fingerprint, lock, marker, or partial-migration error.
  Evidence:
- [ ] Run template reconciliation as dry run → apply → dry run. The second dry
  run reports no changed drafts, new versions, project pins, or other writes.
  Evidence:
- [ ] Run documentation-requirement reconciliation as dry run → apply → dry run.
  The second dry run reports no changed definitions, versions, or obligations.
  Evidence:
- [ ] Run ticket-operations reconciliation as dry run → apply → dry run and
  require an idempotent final preview.
  Evidence:
- [ ] Run endpoint-record reconciliation as dry run → apply → dry run and
  require an idempotent final preview. Investigate every skipped or conflicting
  mapping; do not hand-edit projection rows.
  Evidence:
- [ ] Start only `web`. Keep `cron`, external schedules, and optional Level and
  automation ingress disabled until the canaries below are accepted.
  Evidence:

## Phase D — active-goal canaries

Each canary uses disposable data and archives the durable IDs, event rows,
hashes and exports needed to reproduce the result.

### Goal 1 — device identity and reconciliation

- [ ] Run one explicitly mapped Intune, Entra, and SentinelOne source scope with
  `create_asset: false`; verify terminal pagination, completion-last ordering,
  current health, expected counts, client/location boundaries and reviewed
  unresolved mappings.
- [ ] Replay the clean cycle and verify no duplicate asset, mapping, posture,
  topology, or timeline event. Verify an older delivery cannot restore state.
- [ ] Exercise ambiguity, cross-client identity, stale/failure, retirement guard
  and redacted-error paths; each fails closed without creating or moving an
  asset or retiring a healthy scope.
- [ ] Record the start and end of the review-only burn-in. Only after the agreed
  clean burn-in may one explicitly approved scope set `create_asset: true`.
- [ ] In that one scope, a clean new active device creates exactly one asset only
  after all deterministic checks pass; replay reuses it. Leave all other scopes
  review-only. Follow [device-source-adapters.md](../device-source-adapters.md).
  Evidence:

### Goal 2 — stateful alert ingestion regression

- [ ] Confirm the previously certified alert source still produces one durable
  incident/ticket across duplicate open/update deliveries, acknowledges and
  resolves in order, rejects stale or cross-client state, and records redacted
  append-only event evidence. Re-certify the full source only if its integration
  or shared transaction path changed.
  Evidence:

### Goal 3 — onboarding and offboarding runbooks

- [ ] Reconciled **Managed Care Onboarding** and **Client Offboarding** point to
  immutable published versions. Create one disposable execution of each and
  record the pinned version and definition hash.
- [ ] Exercise conditional task inclusion/skipping, dependency blocking,
  independent approval, evidence requirements, retry/replay, and every enabled
  resolve/close surface. No terminal state is possible before all gates pass.
- [ ] Complete both executions, export their verified closeouts, and confirm the
  immutable task, approval, evidence and state-event history remains readable.
  Evidence:

### Goal 4 — documentation readiness and evidence

- [ ] Run the **Current**, **Stale**, **Missing**, **Exception**, and **Closure**
  scenarios in [the deployment runbook](../../deploy/psa/README.md). The
  exception requester is level 2, its different approver is level 3, and the
  closure case covers every enabled terminal writer.
- [ ] Verify detail, queue, dashboard, readiness denominator and ticket gate all
  use the same evaluated rows. Archive obligation/version/event IDs, Evidence
  Locker occurrences, Change Passport, waiver/exception events and Promise
  Ledger rows.
  Evidence:

### Goal 5 — unified endpoint and network record

- [ ] Run the combined-record canary in
  [unified-endpoint-network-record.md](../unified-endpoint-network-record.md):
  stable client/asset identity, source-owned posture, at least two interfaces,
  changed-address/neighbor history, replay and older-delivery protection,
  cross-client rejection, ambiguity review, stale recovery, and decision audit.
  Evidence:

### Goal 7 — portal request catalog and approvals

- [ ] Install the six starter drafts, then bind and publish exactly these
  operator-reviewed pairs:

  | Catalog key | Published runbook | Submission / approval |
  | --- | --- | --- |
  | `new-user` | User Onboarding | manager / different manager |
  | `termination` | User Offboarding | manager / different manager |
  | `new-device` | Device Deployment | manager / different manager |
  | `access-change` | Access Change | manager / different manager |
  | `incident-report` | Incident Triage and Response | any authorized contact / direct |
  | `scheduled-work` | Scheduled Work | manager / different manager |

- [ ] Verify each catalog release pins the intended immutable runbook version
  and definition hash. Do not auto-bind drafts or publish an unreviewed mapping.
- [ ] With two distinct eligible manager contacts, submit one disposable
  `scheduled-work` request. Self-approval and an ineligible contact fail; before
  approval no ticket exists; the second manager approval creates exactly one
  ticket pinned to **Scheduled Work**.
- [ ] Replay submission/approval actions without a duplicate ticket, complete
  the runbook approvals and evidence, export the request/closeout, then close
  the ticket. Confirm immutable request responses and events remain visible.
- [ ] Submit one `incident-report` as a non-manager authorized contact and verify
  it creates exactly one ticket immediately with no approval bypass elsewhere.
  Evidence:

### Goal 8 — agreements, entitlements and service reviews

- [ ] For the disposable **N45 Internal** client, create the initial agreement
  draft, add one explicit scoped entitlement, and add an inert canary rule using
  request key `n45-release-canary`, priority `Low`, SLA **None**, and an explicit
  classification. Verify it cannot match ordinary request keys.
- [ ] Publish the agreement with an approval reason; record the immutable
  version, definition hash and publication/supersession event trail. Verify a
  later draft edit cannot rewrite it.
- [ ] Generate one service-review draft for a bounded disposable period. Verify
  its agreement version/hash, ticket/SLA, endpoint, backup, documentation and
  renewal inputs; export Markdown and independently verify the stored hash.
- [ ] Have an authorized reviewer publish it with an approval reason. Confirm
  the published export names the approver, timestamp and reason, and that the
  snapshot and append-only events remain immutable. Follow
  [AGREEMENT_ENTITLEMENTS.md](../../deploy/psa/AGREEMENT_ENTITLEMENTS.md).
  Evidence:

### Goal 11 — release provenance

- [ ] Reconfirm the running container reports or contains the exact approved
  commit and recorded image digest. Archive the final parity report and all
  exact-SHA workflow URLs with the deployment evidence.
  Evidence:

## Phase E — reopen or rollback

- [ ] Confirm login, PSA and portal routing, database connectivity, uploads,
  queue/outbox state, cron visibility, audit logging, notifications and all
  configured health endpoints before enabling background writers.
  Evidence:
- [ ] Start `cron`, observe one controlled dispatcher cycle, then re-enable
  external schedules and optional integrations one source at a time. Confirm no
  unexpected duplicate, backlog, stale scope or cross-client action.
  Evidence:
- [ ] Archive migration output, reconciler passes, canary evidence, exports,
  hashes, image digest, exact SHA, snapshots and operator/approver timestamps.
  Evidence:
- [ ] Release owner accepts the canary evidence and authorizes normal traffic.
  Evidence:

Rollback immediately on migration/fingerprint ambiguity, non-idempotent
reconciliation, client-boundary failure, duplicate ticket/asset/action,
unverifiable evidence hash, a bypassed terminal gate, unexplained schema drift,
or unrecoverable health regression. Quiesce writers first and restore the
complete compatible application, database and app-data set; do not attempt a
schema-only downgrade.
