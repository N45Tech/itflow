# N45 global transaction lock order

Cross-module writes acquire named advisory locks first, sort those names
lexicographically, and release them only after the database transaction commits
or rolls back. Row locks inside the transaction then use this global order:

1. authorization principals and permission rows
2. API keys
3. clients
4. projects
5. settings and sequence allocators
6. assets
7. durable external identities
8. automation incidents
9. tickets
10. tasks and approvals
11. agreements, versions, entitlements, and SLA rules
12. documentation obligations and evidence gates
13. documents and document versions
14. staged-file journal rows
15. automation delivery rows
16. custom-action outbox rows
17. audit rows

Rows in the same class are locked by ascending primary key. A caller may omit a
class, but it may never return to an earlier class. Preflight reads used only to
discover identifiers do not carry `FOR UPDATE` and must be revalidated after the
ordered locks are held.

`N45LockOrder` is a fail-fast assertion used by cross-module transactions. It is
not a substitute for database constraints: unique keys, compare-and-set writes,
tenant predicates, and immutable evidence checks remain mandatory.

## Current cross-module paths

- Ticket creation: client -> settings -> ticket -> agreement/SLA evidence -> audit.
- Project lifecycle: client -> project -> ticket -> task -> documentation -> audit.
- Documentation mutation: client -> ticket/task when applicable -> obligation -> document -> file stage -> audit.
- Endpoint reconciliation: client -> asset -> identity -> audit.
- Automation delivery: authorization -> API key -> client -> asset/identity -> incident -> ticket -> agreement/documentation -> delivery -> custom-action outbox -> audit.
- API-key administration: authorization -> API key -> audit.

Never hold a row lock while acquiring a new named advisory lock. Any new path
that crosses two or more classes must add a lock-order contract test, and any
path that can be entered concurrently from cron and HTTP must add a database
concurrency test.
