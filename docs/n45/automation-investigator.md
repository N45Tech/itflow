# Automation investigator rollout

The canonical implementation and activation instructions are in [Read-only automation investigation](automation-investigation.md).

The original draft and the parallel hardening continuation were consolidated into PR #53. There is one worker (`cron/automation_investigation.php`) and one N45 migration (`n45-0030-automation-investigation`). Do not apply the superseded draft `n45-0030-automation-investigations.php` schema or enable its old cron entry. No production deployment of that draft is assumed; a database that manually applied it must be inspected and reconciled before updating.

The deployment flag and cron entry are off by default. A dedicated model alone is not activation: client IDs, exact provider host and the deployment/cron switches must also be explicitly configured. This release performs no remediation, client-system action, ticket-state change, reply, or notification.
