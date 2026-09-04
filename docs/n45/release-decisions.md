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

For the automated path, merging a reviewed pull request into protected `main`
is the production authorization. Deployment begins only when PHPLint, N45
Upstream Parity, and SQL Syntax Check for db.sql all report success for the exact
current main-branch SHA. The workflow and infra01 wrapper independently verify
that SHA and serialize production changes. A manual workflow rerun is subject
to the same current-main and exact-SHA test requirements.

The host deployment still performs the mandatory maintenance gates from
[the release checklist](release-checklist.md): verified database and application
data snapshots, database restore proof, migrations, reconciler idempotency,
safe-mode web health checks, restoration of the prior integration state, and a
controlled cron cycle. A post-migration failure never triggers an automatic
database restore; it leaves web in safe mode and cron stopped for reviewed
recovery. Emergency and exceptional releases outside protected `main` retain
the explicit final **GO** requirement for their exact immutable SHA.
