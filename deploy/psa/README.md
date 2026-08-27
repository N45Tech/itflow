# N45 PSA deployment

Production deployment for `psa.n45tech.com` on `infra01`, with the client portal available at `portal.n45tech.com`.

- Caddy terminates public TLS and proxies to `127.0.0.1:8088`.
- `portal.n45tech.com` is a DNS alias of the PSA host; its bare root redirects to `/client/` while all portal routes use the same application backend.
- Apache/PHP and the minute cron dispatcher use the same immutable ITFlow image.
- MariaDB is reachable only on the private Compose network.
- `psa_app_data` persists `config.php` and uploads.
- `psa_db_data` persists the database.

Run from this directory with the production environment file stored outside the repository:

```bash
sudo docker compose --env-file /opt/n45/psa/.env up -d --build
```

For the initial N45 deployment, clone this repository to `/opt/n45/psa/app` and run:

```bash
sudo /opt/n45/psa/app/deploy/psa/install-production.sh
```

The first administrator's generated local/vault password is written once to `/home/n45admin/psa-initial-admin.txt` with mode `0600`.

The application is image-managed. Deploy updates by checking out the reviewed branch, rebuilding, and recreating the web and cron containers. Do not use ITFlow's in-app Git updater in this deployment.

After deploying this revision, reconcile the recommended ticket workflow, Managed Care SLAs and operational tags from the web container. Preview first; both commands are idempotent.

```bash
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_ticket_operations.php --dry-run
sudo docker compose --env-file /opt/n45/psa/.env run --rm --no-deps web php deploy/psa/reconcile_ticket_operations.php
```
