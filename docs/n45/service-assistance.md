# Ticket follow-ups and canned responses

This revision consolidates the earlier service-assistance workflow into tickets. Stage it on `next`; deployment requires separate release approval.

## Follow-ups on tickets

Use **Tickets → Queues → Follow-ups due**. The existing client, assignment, search, list/board, and pagination controls continue to apply. Each ticket appears once even when several sources need attention. Field Mode offers the same filter in **Find work → Follow-ups**.

Sources are the ticket's next action, open customer commitments, pending ticket and task approvals, and unresolved field issues. Approval follow-ups become due 24 hours after the request. Completed, resolved, closed, deleted, inaccessible, and archived-client work is excluded.

The ticket's collapsed **Follow-ups** section holds the existing owner, follow-up deadline, next-step note, and optional escalation plan. It is also available within the mobile job. A valid future plan defers that item in the due filter; changing its source invalidates the old plan. The original commitment or approval is never extended or decided by a follow-up plan. Completing the source removes the item automatically.

Plans require Support write access and an eligible owner. Escalation remains an explicit choice of a different eligible technician. Reminders use internal notifications, at most once per UTC day per unchanged plan, recipient, and stage. The worker locks and re-reads the source before sending. Permissions are checked again before receipt replay and notification delivery.

Legacy follow-up page links redirect to the ticket or its due filter. There is no separate follow-up navigation item or global queue page. The internal reminder scan remains bounded at 5,000 source items and reports that limit; ticket filtering/counting happens in SQL before pagination and has no 5,000-item cap.

## Admin-managed canned responses

Administrators create, edit, and delete reusable responses at **Administration → Canned Responses**. Responses can apply to every ticket category or one category. Technicians cannot create or maintain responses from tickets or Field Mode.

On desktop, choose an update visibility in the ticket composer and use **Canned response**. On mobile, use **Conversation → Write an update → Canned response**. Selection inserts text into the unsent message, preserves existing text, and leaves visibility unchanged. The technician can edit the result before saving or sending it. Selection itself never sends a reply.

Bodies are fetched only when selected, with current ticket access, Support write access, category, and archive checks. Desktop insertion uses purified HTML; mobile insertion uses readable plain text. Responses and ticket data are not stored in the offline shell cache.

The earlier suggested-fix, resolution-capture, peer-review, and article-publication controls and endpoints have been retired. Existing client documents, knowledge records, and audit/retention evidence remain intact; client-specific knowledge is not automatically converted into globally available responses. Migration `n45-0026-service-assistance` remains immutable. This revision requires no schema migration or data conversion.

## Automatic mobile experience

Phones and tablets automatically enter Field Mode when they open the agent portal. Ticket and project links retain their context; a ticket follow-up link opens that job's follow-ups. A ticket-list link preserves search, client, due-follow-up, and open/completed context.

The device decision uses mobile device signals, including iPadOS touch capability when it reports a Mac user agent. It does not use window width: desktop browsers remain in the PSA when resized, and phones remain in Field Mode in landscape. Desktop Field Mode links redirect to the corresponding ticket/project or ticket list before the application starts. Desktop navigation has no Field Mode option. Administration and customer portal routes retain their own workflows.

JavaScript is required for Field Mode and automatic routing. Device classification is a UI decision, not an authorization boundary; the shared APIs retain their session, role, client, CSRF, and receipt protections. Static shell cache version 4 includes the routing script and removes older shell caches.

## Verification and rollback

`tests/service_assistance_database_assert.php` covers source projection, ticket filtering, source changes, future plans, role/client access, idempotent writes, concurrent reminders, completion while a worker waits, canned response sanitization/access, and legacy knowledge retention.

`tests/service_assistance_http_assert.php` uses real local PHP endpoints, sessions and a disposable database to exercise admin-only creation, technician insertion, CSRF, retired endpoints, ticket rendering and retained-client deletion protection. With `N45_ASSISTANCE_BROWSER=1`, it also runs `tests/field/assistance.cjs` for desktop/mobile filters, reply insertion without sending, exact retry, desktop redirection, mobile deep links, landscape and iPadOS routing. The existing Field Mode browser recovery suite uses explicit mobile device identities.

All fixtures use synthetic records and `.invalid` addresses. Browser emulation does not establish physical Android verification. Roll back the application revision to restore the previous UI if necessary; this revision does not alter or remove database records. Use the existing release gates and backup procedure before any production release.
