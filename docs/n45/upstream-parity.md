# N45 fork maintainability and upstream parity

This fork keeps N45-specific behavior explicit while preserving a practical path to review and adopt upstream ITFlow changes. Fork code should be additive where possible, enter the application through `n45/bootstrap.php`, and declare its runtime files and migrations in `n45/manifest.php`.

## Baseline audit

The initial audit compared N45 commit `405769a1` with its merge base at fork `origin/master` commit `0262707e`:

- 44 fork commits ahead and 0 behind the mirrored base.
- 201 changed files: 84 added, 117 modified, 0 deleted.
- 31,919 insertions and 2,479 deletions.
- Largest core overlaps are `admin/post/starter_content_model.php`, `agent/post/ticket.php`, `agent/post/task.php`, `client/index.php`, `db.sql`, `admin/post/ticket_template.php`, and `agent/post/project.php`.
- Largest additive modules are `functions/level.php`, `functions/runbooks.php`, `functions/automation_events.php`, `functions/automation.php`, `agent/runbook_export.php`, and `functions/integration_identity.php`.
- Four fork-added packaging files already had a blank line at EOF. Their exact `git diff --check` messages are recorded in `n45/upstream-diff-check.allowlist`; any new or changed whitespace error and any stale allowlist entry fails the review. Remove an entry when its file is next changed rather than broadening the exception.

This snapshot is historical evidence, not a permanently current metric. Generate the current report with:

```bash
bash scripts/n45-upstream-review.sh upstream/master HEAD upstream-parity-report.md
```

The script reports ahead/behind distance, upstream/fork path overlap, security-sensitive overlap, changed-file buckets, N45 migration changes, the largest diffs, available PHP/JavaScript/shell lint checks, exact known whitespace exceptions, and unexpected whitespace failures. It does not fetch or alter refs. The `N45 Upstream Parity` workflow fetches the authoritative upstream branch and publishes the same report as a workflow artifact and job summary.

If upstream and the fork both changed a path matched by `n45/security-sensitive-paths.regex`, the report fails until a reviewer supplies the exact upstream and fork commits:

```bash
N45_REVIEWED_BASE_SHA=<full-upstream-sha> \
N45_REVIEWED_HEAD_SHA=<full-fork-sha> \
bash scripts/n45-upstream-review.sh upstream/master HEAD upstream-parity-report.md
```

Workflow-dispatch inputs or the `N45_REVIEWED_BASE_SHA` and `N45_REVIEWED_HEAD_SHA` repository variables provide the same evidence in CI. A new commit or upstream advance invalidates the approval deliberately. Never weaken the path rules to make a review pass; review the overlap and approve its exact SHAs.

### Default-branch scheduling requirement

GitHub Actions runs a scheduled workflow only from the repository's default
branch. The N45 repository currently uses `master` as that branch, while this
workflow is being developed on the integration branch. Push, pull-request, and
manual-dispatch parity checks work from the integration branch, but the weekly
schedule is not operational until the workflow file reaches the default
branch.

After the all-goals candidate is complete, the repository owner must either
merge a minimal workflow-only pull request into `master` (recommended) or make
the assembled N45 branch the default branch. The workflow-only change does not
authorize or trigger a production deployment. Until one of those decisions is
implemented, record upstream review as a manual release responsibility rather
than describing it as recurring automation.

## Module boundary

`functions.php` loads fork-owned services through `n45RequireModule()`. Add a module or runtime file to `n45/manifest.php` before wiring it into core code. The loader deliberately loads code even when optional processing is disabled: referential cleanup, history access, and safe error responses must remain available.

Only these operations have deployment kill switches:

| Feature | Environment variable | Disabled behavior |
| --- | --- | --- |
| Level | `N45_FEATURE_LEVEL=0` | Reject webhook ingress, stop Level cron work, API calls fail closed, existing mappings remain intact. |
| Automation | `N45_FEATURE_AUTOMATION=0` | Reject automation API ingress and stop queue processing; existing incidents, events, and ticket-deletion cleanup remain intact. |

Unset flags default to enabled. Accepted false values are `0`, `false`, `no`, `off`, and `disabled`; accepted true values are `1`, `true`, `yes`, `on`, and `enabled`. Environment values override an optional `$n45_feature_flags` array in deployment configuration. A present but unrecognized value fails closed to disabled; it never falls back to an enabled default. Disabling Automation also centrally skips Level-to-Operations mirroring before any Operations database write.

Authentication restrictions, portal authorization, runbook lifecycle gates, evidence integrity, mail rendering, identity links, and deletion cleanup are intentionally not toggleable. Treating those as emergency switches would create a security or data-integrity downgrade.

## Upstream review routine

1. Fetch the authoritative upstream `master` into `refs/remotes/upstream/master` without rewriting the N45 branch.
2. Run `scripts/n45-upstream-review.sh upstream/master HEAD upstream-parity-report.md`, review every overlap candidate, and satisfy the exact-SHA gate for security-sensitive overlaps.
3. Create a temporary integration branch and merge or rebase there. Do not change a deployed branch in place.
4. Prefer upstream implementations for unchanged product behavior. Reapply N45 behavior through the module boundary or a small call-site hook.
5. Reconcile `db.sql`, ordered files in `n45/migrations/`, `n45/manifest.php`, and `docs/n45/migrations.md` together. Keep upstream migrations in their own namespace.
6. Run PHP lint, every `tests/*_test.php`, the N45 smoke contract, JavaScript syntax checks, and `git diff --check`.
7. Exercise login, client portal authorization, ticket creation/reply/resolve/delete, template publishing/instantiation, API authentication, Level ingress, and automation retry in a disposable environment restored from a recent production-shaped backup.
8. Record the upstream SHA, N45 SHA, database version, report artifact, test result, and rollback checkpoint in the change record.

## Conflict policy

- Never resolve `db.sql` independently from migrations. The baseline schema and the ordered upgrade path must describe the same end state.
- Preserve upstream authorization and validation improvements unless an explicit N45 requirement is stricter.
- Keep ticket lifecycle gates centralized. Do not add handler-specific bypasses for API, portal, guest, email parser, bulk, or automation paths.
- Keep destructive ticket deletion, dependent ticket rows, and Operations cleanup in one transaction even when automation ingress is disabled. Delete upload directories only after that database transaction commits.
- Avoid broad formatting or file moves in upstream-owned files; they hide semantic conflicts and make future reviews harder.
- New N45 tables and columns require a stable-ID migration, checksum-ledger manifest entry, schema/data fingerprint, explicit rollback note, baseline-schema parity, and a regression test.
- Before integrating a concurrent feature branch, check `maintenance.integration_migration_reservations` in `n45/manifest.php`. A reserved former fork version must be renamed into the listed stable N45 ID, retain that numeric version only as bridge metadata, and consume reservations in order. The runtime preflight and smoke contract reject leaked numeric files.

## Release evidence

An upstream-parity review is complete only when the generated report has no unexplained overlap, all migrations have rollback notes, the smoke contract passes, the full regression suite passes, and production deployment has a verified database restore point. Recurring review is operational only when the workflow is present on the repository's default branch. A clean textual merge alone is not sufficient evidence.
