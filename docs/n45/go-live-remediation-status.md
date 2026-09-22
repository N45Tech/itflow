# N45 ITFlow go-live remediation status

This register tracks the findings from the 22 September 2026 go-live audit. A finding is marked complete only after its acceptance evidence passes. Production-only canaries remain open until witnessed in the target environment.

| Finding | Status | Current evidence / next gate |
| --- | --- | --- |
| P1-01 — Live n8n alignment and canaries | **Blocked** | Current definitions are identified, but no accessible `N45 Hetrix Webhook` credential exists and the n8n service would not guarantee a draft-only edit. Explicit approval is required before changing active workflow state. |
| P1-02 — Operational acceptance | **Pending** | Requires witnessed service, source-health, client configuration, mail, restore, and physical Field Mode checks. |
| P1-03 — Cross-page UI consistency | **Pending** | Shared-component migration and exception matrix have not started. |
| P1-04 — Authenticated route/visual coverage | **Pending** | Production-like route matrix and browser baselines have not started. |
| P2-01 — Software-list N+1 | **In progress** | Page query now returns the assigned-seat aggregate without per-row SQL. Contract and release tests must pass before completion. |
| P2-02 — Pagination counting | **Pending** | Profile and replace the hottest `SQL_CALC_FOUND_ROWS` paths first. |
| P2-03 — Readiness and cron health | **Pending** | Application-aware readiness contract has not started. |
| P2-04 — Static-analysis gates | **Pending** | Incremental baseline and changed-code gate have not started. |
| P2-05 — Reproducible container bases | **Pending** | Digest pinning and deployment evidence have not started. |
| P3-01 — Legacy-page accessibility | **Pending** | Address alongside P1-03 page normalization. |

## Change log

- **2026-09-22 — P2-01 In progress:** replaced two per-record relationship reads in `agent/software.php` with assigned-seat counts returned by the existing paginated query; added a regression contract that rejects reintroduction of either per-row query.
- **2026-09-22 — P1-01 Blocked:** live n8n mutation was not attempted after the service could not guarantee draft-only behavior. No workflow was changed or published.
