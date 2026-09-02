# Agreement entitlements and service reviews

Goal 8 turns the existing `contracts` rows into versioned operational agreements. The legacy row remains the client-level identity and current-version pointer; the normalized definition lives in:

- `agreement_versions` for draft, published, and superseded definitions;
- `agreement_entitlements` for covered users, devices, services, locations, and hours;
- `agreement_sla_rules` for request-type/priority selection;
- `agreement_version_events` for append-only publication history;
- `ticket_agreement_decisions` for the exact rule and explanation used on each ticket;
- `service_reviews` and `service_review_events` for immutable report snapshots and publication history.

## Deployment

N45 migration `n45-0014-agreement-entitlements` is restartable and adds the new tables plus the current-version/review-schedule columns on `contracts`. Its `2.8.1` value is legacy-bridge metadata, not an upstream migration marker. The migration does not activate or synthesize agreements from old contract rows. Existing client and global SLA assignments remain in effect until an agreement version with a matching SLA rule is published.

This migration is allocated after `n45-0011-documentation-readiness`, `n45-0012-unified-endpoint-network`, and `n45-0013-portal-request-catalog`. Final assembly must also include the required `n45-0015-documentation-evidence-reference-index` compatibility repair. The N45 runner enforces the contiguous manifest/disk order and immutable checksums instead of consulting `settings.config_current_database_version`. An old fork installation whose upstream marker already contains `2.8.1` must use the explicit legacy bridge documented in `docs/n45/migrations.md`; do not replay a numeric `2.8.1.php` file.

After migration:

1. Open **Agent → Agreements** and create or review a client agreement draft. Existing legacy contract rows expose a **Create Initial Draft** action; migration does not silently invent entitlement terms.
2. Add at least one entitlement/exclusion and one SLA rule. An SLA rule may intentionally select `None`. Adding a rule snapshots the SLA name, response/resolution targets, business days/hours, timezone, and classification semantics shown in the draft.
3. Publish the definition with an approval reason. Publication is transactional, verifies the current pointer and prior hash, hashes the normalized definition, records the supersession event, and schedules the first service review.
4. Confirm the **Service Review Drafts** cron job is enabled. It generates drafts only; a technician must approve publication.
5. Inspect **Admin → Agreement Governance** for drafts, due reviews, renewals, and rules that reference archived SLAs.

### N45 Internal baseline reconciler

The baseline is an explicit post-migration maintenance action, not install-time
seed data. It is restricted to an operator-supplied active, non-lead client ID
whose name is exactly `N45 Internal`; it refuses duplicate identities,
competing published agreements, any pre-existing unpublished version/lifecycle
history, commercial fields, or a non-Draft unpublished contract. It never loops over clients and must never be run against
a customer record.

Before the maintenance window, the owner must record:

- the exact `N45 Internal` client ID;
- an active internal technician ID whose role is administrator or has
  support-write permission;
- confirmation that no other published agreement should control the internal
  client; and
- the database snapshot/change record that will own the live canary evidence.

Run the full transaction and roll it back first, substituting the reviewed IDs:

```console
php deploy/psa/reconcile_internal_agreement.php --dry-run --client-id=ID --actor-id=ID
```

The only entitlement is the semantic service key
`n45-internal-agreement-canary`. Its only SLA rule requires that exact key plus
`Low` priority and selects SLA `None`. Both selectors are deliberate: ordinary unmatched tickets
retain the existing client/global SLA fallback. Review the
dry-run counts and refusal checks, then apply and immediately run the apply
command a second time. The second pass must report one unchanged agreement and
zero created/published rows.

```console
php deploy/psa/reconcile_internal_agreement.php --apply --client-id=ID --actor-id=ID
php deploy/psa/reconcile_internal_agreement.php --apply --client-id=ID --actor-id=ID
```

Do not add pricing, support targets, wildcard selectors, customer IDs, or a
real SLA to this baseline. A future change is an owner-reviewed new agreement
version, not an edit to the reconciler's canonical first version.

### Acceptance evidence

`tests/agreement_internal_acceptance_test.php` is the local synthetic acceptance
for the first immutable N45 Internal service review. It composes a complete
tenant/agreement/period-bound snapshot, verifies its canonical hash, inspects
and exports the draft, binds a named approval reason and publication event,
exports the published review, and proves that byte, presentation, tenant-event,
duplicate-approval, and published-row mutations are rejected. It also proves
that ordinary requests do not match the canary rule. This is synthetic
acceptance evidence only; it is not a claim about a live deployment.
The release-database harness repeats that lifecycle against disposable MariaDB:
it proves dry-run rollback, two-pass reconciliation, immutable agreement/event
bindings, draft generation, approval/publication, Markdown export, and mutation
and cross-tenant rejection.

After a later approved deployment, generate the first live review from a closed
period in **Agent → Agreements**, inspect recurring-issue and renewal text for
appropriate client presentation, publish it with a specific approval reason,
and archive the review/contract/version IDs, both SHA-256 values, generation and
publication event IDs, Markdown export, approver, timestamp, and reason. The
snapshot builder redacts email addresses and common credential/token patterns
from free-text evidence; the central validator rejects a snapshot that bypasses
that redaction contract. Never attach credentials or raw vendor payloads.

Rollback is application-first: deploy the prior image before changing schema. The added tables and columns are intentionally retained so published definitions, ticket decisions, and client review evidence are not destroyed. No down migration deletes these accountability records.

Publication is activation, not future scheduling: a version whose effective date is still in the future remains a draft until that date. This prevents a future version from superseding today’s agreement pointer and silently dropping current ticket coverage.

## Deterministic SLA selection

Rules match in this fixed order:

1. exact request type and exact priority;
2. exact request type and wildcard priority;
3. wildcard request type and exact priority;
4. wildcard request type and wildcard priority.

`rule_order`, then the durable rule ID, break otherwise equal ties. A matching agreement rule wins over the pre-existing client/global SLA assignment. Publication requires its referenced SLA to exist and be active, but the published rule then uses its snapshotted targets and calendar even if that source SLA is later edited or archived. It never silently substitutes a different commercial promise. If the published agreement has no matching rule, the existing assignment mechanism remains the disclosed fallback.

If a client has overlapping active published agreements, selection is still stable: latest effective-from date, then highest version number, then contract ID. The recorded decision names that agreement and version before applying request-type/priority precedence. Avoid overlapping general-purpose agreements unless that ordering is intentional.

Every application is appended to the ticket decision trail, including repeated selections and explicit technician overrides. Agreement decisions first resolve the locked ticket against the published users/devices/services/locations/hours clauses. Exact record or semantic-key clauses beat broad clauses, every configured scope must match, a broad quantity overage becomes billable, and an uncovered configured scope fails closed as excluded. Additional ticket devices are linked before this decision. The ticket row, immutable request key, billable/onsite behavior, target/calendar snapshot, entitlement evidence, and ticket-bound decision hash are written under the same ticket/client lock and database transaction. Callers that already own a ticket-creation transaction pass `true` as `applyTicketSla()`'s fourth argument.

Behavior matrix v1 is explicit and versioned: `included` is SLA-eligible/non-billable/remote; `excluded` is SLA-ineligible/billable/remote; `onsite` is SLA-eligible/non-billable/onsite; `after_hours` and `billable` are SLA-eligible/billable/remote. Ticket history displays the resolved classification and per-scope evidence. The app-local deadline remains for existing UI, while a canonical UTC deadline is stored and used for breach/judgment comparisons so a rule timezone or DST transition cannot make the same instant ambiguous.

`agreement_version_support_hours` is descriptive text only. It is never parsed and the current clock time never silently changes a ticket's commercial classification. `included`, `excluded`, `onsite`, `after_hours`, and `billable` are explicit immutable rule/entitlement values with classification basis `explicit_rule`; unsupported inferred bases are rejected.

Published versions record both `published_at` and `superseded_at`. A historical service review resolves the version whose effective range and publication interval both contain the review boundary, including a superseded version where appropriate. It does not follow today's contract pointer retroactively.

## Compatibility seams

Goal 8 does not depend on unmerged Goal 4 or Goal 7 schema.

- Goal 7 can define `requestCatalogAgreementKeyForTicket(array $ticket): string`. Its stable catalog key then replaces the normalized `ticket_category` fallback for agreement rule matching.
- Goal 4 can define `documentationServiceReviewReadiness(int $client_id): array`. That structured readiness result is copied into each new service-review snapshot. Without it, the report includes basic document inventory and explicitly says readiness was unavailable.
- A unified endpoint implementation can define `unifiedDeviceServiceReviewSnapshot(int $client_id): array`. Until present, Goal 8 uses Level asset links plus source-neutral SentinelOne identity mappings and labels that source in the report.

Adapters must return aggregate, client-scoped data and must not include credentials, secrets, raw vendor payloads, or cross-client identifiers. Goal 8 validates the documented aggregate keys, non-negative counts, count/percentage consistency, and bounded source text before copying adapter data into a snapshot. Invalid adapter results are logged and the disclosed client-scoped fallback remains in use. Once a service review is published, later adapter changes do not rewrite its captured snapshot.

## Review traceability

Review generation runs inside a consistent database snapshot and covers ticket/SLA performance, recurring issues, endpoint management/security, backup health, documentation readiness, renewals, and deterministic recommendations. It stores canonical JSON and hashes the exact stored bytes, including the executive summary and pinned agreement version/hash. The report UI and Markdown export centrally verify the hash, tenant/period/agreement bindings, required input sections, presentation fields, and generation/publication events. Published exports include the approving technician, timestamp, reason, and snapshot-bound evidence. Published review rows have no edit path; corrections are generated as a new draft.

The daily scheduler selects only active, unarchived contracts for active clients with a valid current published pointer. A due boundary `D` covers `[D - cadence months, D - 1 day]`; end-of-month dates clamp instead of drifting. Overdue contracts advance from their previous due boundary one cadence at a time, and the schedule uses an exact compare-and-set only after that draft was generated successfully. Each contract failure is isolated and logged.
