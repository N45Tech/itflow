# Ticket recovery and operational controls

This release resumes the approved recoverable-deletion and ticket-operations work. Technician Field Mode remains deferred until its requirements are clarified with the owner.

Deleting a ticket moves it to **Tickets → Deleted**. Replies, files, time entries, tasks, approvals, and operational evidence stay with it. Level 3 support users can restore it to its prior lifecycle state at any time before a deliberate permanent deletion.

Under **Client → Edit → Governance**, a Level 3 client administrator sets the client's audit-retention policy and minimum deleted-ticket retention period. The default minimum is 30 days; changes apply to future deletions. Nothing is purged automatically. Permanent deletion requires the minimum period to have passed, a written reason, and the exact ticket confirmation. Strict retention blocks destruction of protected evidence. An allowed administrator override retains a separate deletion audit event.

Ticket operations add work type, impact and urgency, next action and waiting-on details, structured internal notes, handoff context, customer promises, and parent/child/duplicate/related links. Resolution requires a code and summary, with root cause required for problem investigations. Outstanding customer promises and existing approval/documentation gates must be satisfied before resolution. Existing terminal tickets are retained as historical completions.

The release includes additive migrations `n45-0022` and `n45-0023`. The PR database harness checks delete/restore rollback, client-specific retention, strict-policy purge protection, surviving audit history, promise gates, relationship boundaries, and migration replay. Full suites run on the release PR; the main merge is attested before the existing production deployment workflow runs.
