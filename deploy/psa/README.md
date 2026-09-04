# N45 PSA deployment

Production deployment for `psa.n45tech.com` on `infra01`, with the client portal available at `portal.n45tech.com`.

- Caddy terminates public TLS and proxies to `127.0.0.1:8088`.
- `portal.n45tech.com` is a DNS alias of the PSA host; its bare root redirects to `/client/` while all portal routes use the same application backend.
- Apache/PHP and the minute cron dispatcher use the same immutable ITFlow image.
- MariaDB is reachable only on the private Compose network.
- `psa_app_data` persists `config.php` and uploads.
- `psa_db_data` persists the database.

For the initial N45 deployment, clone this repository to `/opt/n45/psa/app` and run:

```bash
sudo /opt/n45/psa/app/deploy/psa/install-production.sh
```

The first administrator's generated local/vault password is written once to `/home/n45admin/psa-initial-admin.txt` with mode `0600`.

The application is image-managed. Deploy updates by checking out the reviewed branch, rebuilding, and recreating the web and cron containers. Do not use ITFlow's in-app Git updater in this deployment.

## Automated main-branch deployment

`.github/workflows/deploy-production.yml` deploys the exact current `main`
commit after the SQL release workflow succeeds and the same commit also has
successful PHPLint and N45 Upstream Parity push runs. The workflow uses the
GitHub `production` environment, serializes deployments, pins the SSH host key,
and can only invoke the root-owned deployment wrapper with a full commit SHA.

Install the restricted host command once from a trusted checkout on `infra01`:

```bash
sudo sh /opt/n45/psa/app/deploy/psa/install-github-deployer.sh n45admin
```

Give the deployment-only SSH public key to `n45admin`, then configure these
GitHub `production` environment secrets:

- `INFRA01_SSH_HOST`: the SSH hostname or address for `infra01`.
- `INFRA01_SSH_USER`: `n45admin` unless the deployment account changes.
- `INFRA01_SSH_PORT`: optional; blank uses port 22.
- `INFRA01_SSH_PRIVATE_KEY`: the deployment-only private key.
- `INFRA01_SSH_KNOWN_HOSTS`: a reviewed `known_hosts` entry for the exact host.

Protect `main` so changes arrive through reviewed pull requests and require
PHPLint, N45 Upstream Parity, and SQL Syntax Check for db.sql. Merging into that
protected branch is the production authorization. Leave the `production`
environment without required reviewers for fully automatic deployment; adding
a reviewer turns the same workflow into a one-click approval gate.

The host script independently confirms that the requested SHA is the tip of
`origin/main`, refuses a dirty checkout or concurrent deployment, verifies both
backups by reading them, proves the SQL dump with a temporary restore, applies
database updates twice, runs every reconciler through dry-run/apply/dry-run,
starts a web-only safe-mode canary, restores the prior feature-flag and cron
state, and observes one cron cycle. A failure before database work restores the
previous image. A later failure leaves the new web image available with Level
and automation disabled and cron stopped; it never performs an automatic
database rollback that could discard production writes.

Create the deployment-ticket record from the
[exact-SHA release checklist](../../docs/n45/release-checklist.md). Complete its
pre-GO phase before requesting authorization to change `infra01`, then use its
maintenance, canary, evidence and reopen phases as stop gates for the approved
SHA.

For every update, keep database migration and reconciliation inside one maintenance window. Do not run `docker compose up -d --build`: that can start application writers on the new image before its migrations complete. Use this order instead:

1. Require green release CI for the exact reviewed commit and record that commit in the deployment ticket.
2. Stop external n8n schedules and other integration ingress, then stop the `web` and `cron` services. Keep MariaDB running.
3. Take and verify a restorable snapshot of both the database and `psa_app_data`; record the snapshot location before changing the checkout or image.
4. Check out the reviewed commit and build the immutable image with `sudo docker compose --env-file /opt/n45/psa/.env build --pull web`.
5. If `scripts/update_cli.php --update_db` reports that a legacy N45 bridge is required, follow the one-time bridge procedure in `docs/n45/migrations.md`; never mark ledger rows complete manually. Then rerun the database update until it reports current:

   ```bash
   sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web su -s /bin/sh -c 'php scripts/update_cli.php --update_db' www-data
   ```

6. With `web` and `cron` still stopped, run the dry-run/apply reconciliations below. A failed migration, integrity check, or non-idempotent second dry run is a stop condition.
7. Start only the web service for the documented canaries. Keep optional integration flags disabled and cron stopped until the canary evidence is accepted.
8. Recreate `web` and `cron` with the reviewed environment, re-enable external schedules, and archive the migration output, reconciliation counts, canary evidence, image tag, commit, and snapshot reference.

Two deployment kill switches can quiesce optional integration traffic without changing the database. Set `N45_FEATURE_LEVEL=0` or `N45_FEATURE_AUTOMATION=0` in `/opt/n45/psa/.env`, then recreate both the web and cron services. The defaults are enabled. These flags reject new ingress and stop processors; they do not delete existing records, bypass ticket-deletion cleanup, or make an older application release compatible with a migrated database. See `docs/n45/migrations.md` before any code or database rollback.

Agreement-entitlement activation, SLA precedence, service-review scheduling, compatibility adapters, and rollback expectations are documented in [AGREEMENT_ENTITLEMENTS.md](AGREEMENT_ENTITLEMENTS.md).

After deploying this revision and completing the database update, reconcile the canonical templates from the web container. Always run the dry run first and review its counts; it performs the full transaction, including validation, publication and project-stage pinning, then rolls it back. This is the explicit upgrade path for existing templates: apply unarchives matching templates, replaces their editable metadata and draft tasks with the canonical definitions, and replaces the matching project composition, while retaining immutable published history. Reconciliation fails closed if a same-name template already has published history under a different stable key; that identity requires an explicit fork or mapping rather than an in-place rewrite. The apply command is idempotent: unchanged Managed Care Onboarding and Client Offboarding definitions reuse their published runbook versions, and project composition is pinned to those immutable versions.

```bash
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_templates.php --dry-run
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_templates.php --apply
```

Reconcile the versioned N45 documentation-requirement catalog and project the
resulting client obligations. Inspect the rollback-only pass before applying:

```bash
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_documentation_requirements.php --dry-run
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_documentation_requirements.php --apply
```

Keep application writers and integration ingress stopped until the migration and
documentation reconciliation have completed. Review the dry-run counts, apply
once, then run the dry run again: the second pass must report no changed drafts,
new versions, or changed obligations. On a disposable canary client and ticket,
record the following evidence before reopening traffic:

| Canary | Expected evidence |
| --- | --- |
| Current | An authorized verification with policy-compliant evidence produces a Current obligation, the expected next-review date and one new Evidence Locker occurrence pinned to the current requirement version. The client detail and Operations readiness contribution agree. |
| Stale | Reevaluation of an overdue verified obligation produces Stale in the owner queue and client detail, removes readiness credit, and blocks a linked configuration-change ticket without writing a Change Passport. |
| Missing | An applicable requirement without a canonical document appears as Missing in the same evaluated rows used by the queue and readiness denominator, and blocks terminal ticket state. |
| Exception | A level-2 requester and different authorized level-3 reviewer can create and approve a time-bounded exception. Its event history is append-only; expiry restores the underlying Missing or Stale status on the next hourly job. |
| Closure | Attempt the same blocked ticket through every enabled terminal path (agent, API, portal/guest, bulk, automation, project and cron). Each must leave ticket state unchanged. After valid evidence or a separately approved version-pinned ticket waiver, resolution succeeds once and transactionally records the exact Change Passport, obligation snapshots and any Promise Ledger entries. |

Archive the canary ticket IDs, requirement-version IDs, obligation/event IDs and
the before/after readiness counts with the deployment record. Any disagreement
between detail, queue, dashboard and gate output is a stop condition; do not
repair projections by hand—rerun the evaluator/reconciliation after diagnosing
the source rows.

Then reconcile the recommended ticket workflow, Managed Care SLAs and operational tags. Preview first; both commands are idempotent.

```bash
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_ticket_operations.php --dry-run
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_ticket_operations.php
```

After migration `n45-0012-unified-endpoint-network`, backfill the unified endpoint and network record from existing Level links and the latest mapped Entra, Intune, and SentinelOne snapshots. Preview first; both modes take the same advisory lock and run the same tenant and identity validation. The apply mode is idempotent.

```bash
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_endpoint_records.php --dry-run
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_endpoint_records.php --apply
```
