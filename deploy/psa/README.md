# N45 PSA deployment

Production deployment for `psa.n45tech.com` on `infra01`.

- Caddy terminates public TLS and proxies to `127.0.0.1:8088`.
- Apache/PHP and the minute cron dispatcher use the same immutable ITFlow image.
- MariaDB is reachable only on the private Compose network.
- `psa_app_data` persists `config.php` and uploads.
- `psa_db_data` persists the database.

Run from this directory with the production environment file stored outside the repository:

```bash
sudo docker compose --env-file /opt/n45/psa/.env up -d --build
```

The application is image-managed. Deploy updates by checking out the reviewed branch, rebuilding, and recreating the web and cron containers. Do not use ITFlow's in-app Git updater in this deployment.

