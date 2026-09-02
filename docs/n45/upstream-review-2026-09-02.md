# N45 upstream review — 2026-09-02

## Scope

- Fork baseline / merge base: `0262707ef029ffb294df197e10d9b918adb0c85d`
- Reviewed upstream: `61bb9dd7cb39e228e14db2028793e34bf25a055a`
- Pre-integration fork head: `8d1ed142ff3a1f7a86e8a9a449fd571af1bc8937`
- Final release-candidate head: rerun and record after all goal branches merge

The parity harness reports 142 upstream commits, 900 upstream-changed
paths, 114 overlapping paths, and 82 security-sensitive overlaps from the
historic merge base. Those counts are evidence for review; they are not a safe
instruction to merge every upstream change into a production fork.

## Compatible backports included

The following changes were reimplemented against the N45 fork rather than
copied blindly across framework and lifecycle differences:

- Explicit module permission and client-scope enforcement on asset, contact,
  contact-list, and location-list surfaces.
- A client-scope precedence correction for API contact lookups by phone or
  mobile number.
- Text-safe construction of agent options in the task-approver picker.
- A cryptographically secure readable-password generator with minimum entropy
  floors.
- Durable application logging when an authenticated API query fails or returns
  no accessible resource, without retaining query strings.
- Local portal password changes require the existing password; federated
  sessions remain bound to their identity-provider authentication.
- Portal ticket notification no longer calls the removed `removeEmoji()`
  helper, and portal payment audit rows identify the authenticated contact.
- Read-only API `client_id` filtering narrows the linked user's existing RBAC
  scope and can never widen it.
- Client partial updates retain the stored lead state, and asset API deletion
  does not remove interfaces when the scoped parent deletion fails.
- Database-sourced names and payment identifiers are SQL-safe at audit sinks;
  audit/notification truncation also defends against an odd trailing escape.
- Payment deletion requires Full Sales and Full Financial permissions in both
  the handler and UI, uses the invoice's pinned currency, and warns about a
  manual Stripe refund only for a Stripe payment.
- Canonical ticket-priority validation/derivation is owned by Goal 6 and must
  remain server-side across API and mail entry points.
- Closed-ticket immutability and removal of direct destructive actions are
  owned by Goal 10.
- Compatible inbound-mail safety changes are reviewed as part of Goal 6's
  hardened email-to-ticket work.

## Reviewed correctness changes owned by concurrent goals

- Goal 6 owns upstream priority validation and mail-parser auto-responder
  notification. It also owns the compatible On Hold/resolved/closed SLA clock
  semantics and stale-open-clock reconciliation from `6af0634f4`.
- Goal 10 owns closed-ticket immutability and replacement of direct destructive
  ticket/client actions with recoverable retention flows.

These dispositions must be rechecked after the owning branches are integrated;
the final exact-SHA report is against the assembled candidate, not this
pre-integration review.

## Upstream changes intentionally excluded from this release

### Destructive client API

Upstream added an API endpoint that permanently deletes a client and many
associated records. It conflicts with Goal 10's recoverable deletion,
retention locks, and immutable audit requirements and is not adopted.

### Direct ticket-close API

Upstream's close endpoint writes ticket status and timestamps directly. The N45
fork requires documentation, evidence, runbook, project, agreement, and
retention gates to execute through the shared lifecycle service. A direct close
path is not adopted unless it is rebuilt on that service and passes every
terminal-path contract.

### UI framework migration

Upstream moved to Bootstrap 5, AdminLTE 4, Tom Select, Flatpickr, IMask, and
SweetAlert2 while removing jQuery and several legacy libraries. N45's current
fork has extensive operational UI and portal changes on the prior stack. A
wholesale merge inside the all-goals release would combine a framework rewrite
with three new operational domains and make regression attribution unreliable.
New Goal 9 JavaScript is therefore isolated and framework-neutral. The upstream
UI migration remains a separately tested release with its own visual, modal,
print, portal, and accessibility canaries.

### Image-managed update architecture

Upstream replaced direct web-request Git/database updates with a queued CLI
workflow. N45 production is already image-managed: `deploy/psa/README.md`
forbids the in-app Git updater and requires a reviewed immutable image, stopped
writers, a verified restore point, and `scripts/update_cli.php --update_db` in
the maintenance window. The upstream queue schema is coupled to the deferred
framework/database migration and is not copied into this release. Removing the
legacy web updater from the generic fork remains part of that convergence; it
is not an authorized N45 production deployment path.

### Performance-only database indexes

Upstream numeric migrations `2.7.5` and `2.7.6` add client-scope, child-fetch,
and mail-queue indexes. Their numbers collide with historical N45 markers, so
they cannot be copied into `admin/database_updates/`. They do not change data
semantics and are deferred to a new stable N45 migration after the all-goals
migration sequence is final. The release database gate must continue to reject
numeric migration leakage.

### Non-applicable fixes

- The lead-ticket picker change is intentionally not applied: N45 ticket
  creation treats leads as pre-service records and enforces that rule again in
  the server-side creation service.
- The portal PIN-wipe and statement-currency fixes target upstream portal
  features not present in this fork; the applicable password reauthentication
  and audit-actor portions were backported.
- The modal-ready fix follows upstream's jQuery-removal rewrite. N45 remains on
  the previous stack for this release, and Goal 9 code is framework-neutral.
- The IMAP/vendor refresh and UI-library updates remain part of a separately
  tested dependency/framework convergence release; no published security fix
  was identified in that version-only change.

### Unrelated commercial features

Stripe refund flows, account-statement enhancements, demo-data expansion, and
other unrelated product additions are outside this release. Their exclusion
does not suppress compatible security or correctness fixes in shared paths.

## Final release gate

### Owner decision: scheduled workflow publication

The repository's default branch is currently `master`. GitHub Actions does not
run a workflow's `schedule` trigger until that workflow exists on the default
branch, so push, pull-request, and manual runs are available now but the weekly
review is not yet recurring. After the all-goals candidate is assembled, the
recommended path is a workflow-only pull request that publishes
`.github/workflows/upstream-parity.yml` to `master`, without deploying the
application. Changing the repository's default branch is the alternative
owner decision. Until one is completed, the release owner must run the parity
review manually.

After every goal branch is integrated:

1. Fetch the exact current upstream commit.
2. Run `scripts/n45-upstream-review.sh` against the final candidate.
3. Review every newly reported security-sensitive overlap.
4. Record the exact upstream and candidate SHAs approved for release.
5. Require PHP regression, MariaDB release/upgrade, generated-workflow, and
   exact-SHA parity checks to pass on the published candidate.

No production deployment is authorized by this document.
