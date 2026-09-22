# N45 ITFlow go-live remediation status

This register tracks the findings from the 22 September 2026 go-live audit. A finding is marked complete only after its acceptance evidence passes. Production-only canaries remain open until witnessed in the target environment.

| Finding | Status | Current evidence / next gate |
| --- | --- | --- |
| P1-01 — Live n8n alignment and canaries | **Blocked** | Current definitions are identified, but no accessible `N45 Hetrix Webhook` credential exists and the n8n service would not guarantee a draft-only edit. Explicit approval is required before changing active workflow state. |
| P1-02 — Operational acceptance | **Pending** | Requires witnessed service, source-health, client configuration, mail, restore, and physical Field Mode checks. |
| P1-03 — Cross-page UI consistency | **In progress** | Notification, Location, Quote, and Recurring Tickets workspace migrations are verified. Recurring Invoices normalization and its saved-payment N+1 correction are implemented; full repository validation is pending. Remaining legacy workspace batches and exception matrix are open. |
| P1-04 — Authenticated route/visual coverage | **Pending** | Production-like route matrix and browser baselines have not started. |
| P2-01 — Software-list N+1 | **Complete** | Page query returns the assigned-seat aggregate without per-row SQL. GitHub database workflow run `35735609402` passed PHP lint, all regression contracts, browser checks, migration scenarios, and lock-order acceptance. |
| P2-02 — Pagination counting | **Pending** | Profile and replace the hottest `SQL_CALC_FOUND_ROWS` paths first. |
| P2-03 — Readiness and cron health | **Pending** | Application-aware readiness contract has not started. |
| P2-04 — Static-analysis gates | **Pending** | Incremental baseline and changed-code gate have not started. |
| P2-05 — Reproducible container bases | **Pending** | Digest pinning and deployment evidence have not started. |
| P3-01 — Legacy-page accessibility | **Pending** | Address alongside P1-03 page normalization. |

## Change log

- **2026-09-22 — P2-01 In progress:** replaced two per-record relationship reads in `agent/software.php` with assigned-seat counts returned by the existing paginated query; added a regression contract that rejects reintroduction of either per-row query.
- **2026-09-22 — P2-01 Complete:** GitHub database workflow run `35735609402` completed successfully across PHP lint, regression, browser, database, and lock-order stages.
- **2026-09-22 — P1-03 In progress:** migrated the notification list to the shared workspace structure and corrected accessible names and date-label associations. Full validation run [35737072218](https://github.com/N45Tech/itflow/actions/runs/35737072218) passed PHP syntax, PHP regressions, browser recovery paths, final-schema/upgrade checks, generated n8n validation, and canonical database lock-order checks.
- **2026-09-22 — P1-03 Locations batch Complete:** migrated Locations to the shared workspace header, filter band, data table, and recoverable empty state; added contextual labels for search, filters, bulk selection, and row actions; corrected malformed tag-filter links. Full validation run [35739420942](https://github.com/N45Tech/itflow/actions/runs/35739420942) passed PHP syntax, PHP regressions, browser recovery paths, final-schema/upgrade checks, generated n8n validation, and canonical database lock-order checks.
- **2026-09-22 — P1-03 Quotes batch Complete:** migrated Quotes to the shared workspace structure and recoverable empty state; corrected date/expiry sort indicators and accessible names for search, date filtering, and row actions. Full validation run [35740830674](https://github.com/N45Tech/itflow/actions/runs/35740830674) passed PHP syntax, PHP regressions, browser recovery paths, final-schema/upgrade checks, generated n8n validation, and canonical database lock-order checks.
- **2026-09-22 — P1-03 Recurring Tickets batch Complete:** migrated Recurring Tickets to the shared workspace structure and recoverable empty state; corrected malformed template markup and client-cell semantics; clarified billable state; added accessible names and confirmation handling. Full validation run [35743067242](https://github.com/N45Tech/itflow/actions/runs/35743067242) passed PHP syntax, PHP regressions, browser recovery paths, final-schema/upgrade checks, generated n8n validation, and canonical database lock-order checks.
- **2026-09-22 — P1-03 Recurring Invoices batch In progress:** migrated Recurring Invoices to the shared workspace structure, status navigation, and recoverable empty states; added accessible search, date, Auto Pay, and row-action controls; replaced the per-row saved-payment lookup with one bounded page query. Full repository validation is pending.
- **2026-09-22 — P1-01 Blocked:** live n8n mutation was not attempted after the service could not guarantee draft-only behavior. No workflow was changed or published.
