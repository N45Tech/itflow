# N45 release decisions

These are operator-approved release contracts, not deployment defaults that may
be weakened ad hoc.

## Portal requests

- Incident reporting is available directly to any authorized portal contact and
  does not wait for approval.
- Planned work (new user, termination, new device, access change, and scheduled
  work) is manager-submitted and requires a distinct eligible manager to
  approve it. A requester can never approve their own submission.

## Device reconciliation

- Every device-source scope begins with create_asset set to false.
- After a review-only burn-in, an operator may enable create_asset for one
  explicitly mapped source/client scope.
- ITFlow may then create only clean new assets that pass the deterministic
  checks documented in docs/device-source-adapters.md. Ambiguous, stale,
  retired, ignored, mismatched, or cross-client identities always fail closed
  for review and never silently merge.

## Production authorization

Passing tests does not authorize an infra01 change. The release owner must be
shown the exact immutable commit SHA and the completed pre-GO phase of
[the release checklist](release-checklist.md), including exact-SHA CI,
parity/security review, recovery readiness and the production execution/canary
plan. Production work starts only after an explicit final **GO** for that exact
SHA; any SHA change invalidates the approval. Fresh off-host snapshots, restore
proof, migrations, reconciler idempotency, health checks and production canaries
are then mandatory maintenance-window gates before traffic is reopened.
