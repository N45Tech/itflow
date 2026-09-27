# PSA container templates

This directory contains the reusable application image, Compose service,
runtime configuration, migration, and data-reconciliation templates used by
the N45 ITFlow fork.

Production topology, privileged deployment automation, host bootstrap,
backup/recovery procedures, and release evidence are intentionally maintained
in a separate private operations repository. Do not add environment-specific
hostnames, filesystem paths, credentials, or production runbooks here.

The application is image-managed. Database migrations and reconciliation must
run before application writers are enabled, and deployments must use an
immutable image tag associated with the tested source commit.

The web and cron services share one fail-closed readiness contract at
`/healthz.php`. It reports ready only when application bootstrap and database
access succeed, the upstream and N45 migration streams are current, persistent
uploads are writable, cron is enabled, and the dispatcher heartbeat is no more
than five minutes old. The public response contains no component, path,
configuration, or credential detail.

The cron container starts a lock-safe dispatcher every minute even when an
earlier dispatch is still processing a long job. The dispatcher already owns
database claims and per-job locks for this overlap; the container scheduler
also forwards termination to active dispatchers during shutdown.
