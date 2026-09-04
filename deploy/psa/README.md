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
