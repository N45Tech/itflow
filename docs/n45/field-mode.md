# Technician Field Mode

Field Mode is an Android-first, installable web app at `/agent/field/`. It extends the existing N45 PSA, using the signed-in technician’s support permission and current client access. The agent portal automatically opens Field Mode on phones and tablets, preserving ticket and project links. Desktop browsers use the PSA and have no Field Mode option; direct Field Mode links return to the corresponding desktop record. Window width does not change the selected experience. Android Chrome offers installation when the site meets the browser’s install criteria. HTTPS is required for device location, camera access, service workers, and encrypted recovery.

## Accepted workflow and visual contract

The technician starts with today’s scheduled work or the active visit. The interface retains N45’s ink, spruce, paper, and action-green palette and Segoe UI/system typography. It follows the user’s saved light/dark preference. The first mobile viewport identifies the job, client, site, and arrival action. A fixed bottom navigation gives access to Today, Projects, Time, Issues, and Drafts; on larger screens it sits below the header. Job sections keep instructions, documentation, tasks, notes, issues, approvals, conversation, resources, and files together. On phones, a labeled section menu exposes every destination; larger screens use section links. The only entrance motion reveals an active visit, with a reduced-motion fallback.

The user approved foreground GPS: on opening/sign-in or returning to the visible app, current location and assigned ticket schedules identify possible stops. The tech always confirms arrival. One clear match opens the job; multiple matches remain choices. No location reading marks a technician onsite automatically. Location denial or an inaccurate reading leaves manual check-in available.

## Arrival and customer updates

Arrival matching requires a verified site pin, a location reading accurate to 100 metres or better, a scheduled ticket within four hours, and a position inside the site’s configured radius (150 metres by default; 50–500 configurable). A technician with client write access verifies a pin while physically at the displayed address. Changing the address invalidates the pin. This avoids depending on an external geocoding account and makes ambiguous shared-address jobs explicit.

Travel, onsite work, waiting, and breaks are activities within one active visit per technician. Switching tickets is allowed within the same client and site. Finish the visit before moving to another site. The legacy ticket `onsite` planning flag is not used as presence.

The customer portal’s appointment ticket shows the technician, travel/onsite/completed status, optional estimated arrival, and the latest shared position with its update time and accuracy. Position is shared only when the technician enables it. Updates run only while Field Mode is visible; this PWA does not offer background tracking. Positions older than one hour are omitted, and the map link says “last shared location.” Finishing the visit or disabling sharing clears the precise position. Other tickets worked during that visit are not projected into the appointment’s portal view.

## Work, time, and evidence

Projects expose “My tasks” and “Entire project.” Existing ordered project tickets carry the work plan; no new project-stage abstraction is added. Task instructions, dependencies, required evidence, and canonical approval gates remain in force.

Guided note templates cover troubleshooting, installation, maintenance, survey, and project work. Action, result, and next step feed the existing structured work-note model; extra detail is optional. Dictation uses the device keyboard. Notes are internal, and their save action does not book time.

Finishing a visit records a customer-facing summary and optional typed or drawn acknowledgment. A signature acknowledges the visit, not additional scope. Reviewed activities become existing internal ticket time entries. Breaks record zero worked time; changes greater than one minute require an explanation. Database uniqueness prevents simultaneous active visits, overlapping activity timers, and duplicate submitted time entries. UUID request receipts commit in the same transaction as the work, making a lost response safely retryable.

Photos use the existing ticket attachment and file-staging system, with optional task evidence linkage. Supported formats are JPEG, PNG, and WebP, up to 12 MiB; signatures are PNG up to 2 MiB. Neither is stored offline. Barcode/QR scanning fills client-scoped asset search when the browser supports it; typing a name or serial number is always available. The full document reader opens diagrams and rich content in an isolated, sanitized frame inside Field Mode alongside the plain-text reader.

Issues record type, ticket/project/task context, impact, owner, response deadline, optional documentation reference, and optional photo. Owners receive an in-app notification and can acknowledge or resolve; support administrators may respond as well. Issue changes retain an event trail and internal ticket notes. An open issue blocks completion of its linked task and ticket. A scope request records a decision request without authorizing work.

## Complete a job within Field Mode

Find due follow-ups with the Follow-ups filter in Find work. Administrators maintain canned responses in Administration; technicians insert them into an unsent message from Conversation → Write an update. See `service-assistance.md` for the consolidated ticket workflow.

The mobile workspace uses the same ticket records and permissions as the PSA. Its main header opens **Find work**, and Today also offers **New job**. Routine work no longer depends on full-ticket, asset, or document links into the desktop PSA.

| Technician need | Field Mode workflow |
| --- | --- |
| Find unscheduled or previous work | Search by ticket, subject, or client; choose assigned work or all accessible work, and include completed jobs. Results paginate. |
| Record additional work | Select an accessible client and create a job assigned to yourself, with contact, site, project, schedule, and an optional published runbook template. |
| Manage the job | Update status, assignment, schedule, work type, impact, urgency, site, contact, and next action. Handoffs record the reason and current state; stale edits are rejected. |
| Communicate and book time | Read the paginated conversation, add an internal or portal update, explicitly send a customer email, or record past manual time. Email shows the contact/watchers before sending and checks that the audience has not changed. |
| Obtain approval | Request, re-request, or reroute a ticket or task approval. Authorized internal approvers can approve or decline with a reason; requesters cannot decide their own requests. Customer decisions remain with the customer. |
| Maintain the work plan | Add tasks, complete eligible tasks, or reopen a task with a reason. Runbook dependencies and evidence rules continue to apply. |
| Track commitments | Record a customer commitment and future deadline, then record fulfillment or cancellation with its outcome. |
| Use client resources | Call or email contacts, inspect networks and asset interfaces, scan/search assets, link a primary ticket asset, and append an asset update with client write permission. |
| Access credentials | Reveal a scoped credential with vault permission and an unlocked sign-in vault. Username, password, and available one-time code clear on close, navigation, cover, backgrounding, or timeout. |
| Maintain documentation | Search/read complete client documents, add an internal document or dated update, assess documentation impact, and link/verify requirements with current evidence. Updates preserve document versions and invalidate affected verification. |
| Collect files | Take a photo or upload PDF, text, JPEG, PNG, or WebP evidence up to 12 MiB; inspect/download paginated ticket attachments inside the field workflow. |
| Finish or resume work | Resolve or close with the required resolution and closure evidence. Reopen resolved tickets; create a follow-up for a closed ticket. |

Approval writes use the existing route availability, notification, and audit functions. New requests and retries rotate approval tokens; only token hashes are stored. Task and ticket approvals retain their independent gates. An approval request is not a decision or permission to proceed.

Document verification checks the current obligation revision, document, and selected evidence. Requirement states use the canonical current-readiness projection, including stale verification. A documentation change cannot silently retain a previous verification. Rich document frames disable scripts, forms, and remote image loading.

Customer-facing updates default to internal visibility until the technician chooses otherwise. Email queues, time entries, job creation, approval mutations, and other writes commit with the existing UUID receipt. Repeating a committed submission does not duplicate those effects. Credentials bypass receipt storage and are never put in offline drafts or service-worker caches. Other forms require a connection; only the existing encrypted guided-note recovery is available offline.

Completion checks retain active-visit, unreviewed-time, issue, commitment, task, approval, and documentation gates. Resolution evidence, the terminal status change, and the Change Passport commit together. No new schema migration is required for the workspace extension.

## Recovery and lifecycle

Encrypted recovery is opt-in per technician on each device and requires a separate passphrase of at least ten characters. PBKDF2-SHA256 derives an AES-256-GCM key. Origin, account, and draft ID are authenticated with every envelope. Keys stay in memory and are dropped when the one-minute privacy cover activates. The passphrase is not stored or recoverable by the server. Only note drafts and their ticket context are persisted; client documents, API responses, photos, signatures, and customer locations are never service-worker cached. Offline drafts require unlocking and explicit review before sending after reconnect. Server sign-in, CSRF, module permission, and client scope are revalidated on submission and retries.

An active visit or unreviewed time prevents ticket resolution and recoverable deletion. Field history prevents transfer to a different client and participates in strict evidence retention. An explicitly authorized permanent purge removes only the selected ticket’s field records. If a finished visit spans other tickets, it remains attached to a surviving ticket, with the original customer summary and acknowledgment cleared and a retention event recorded.

Migration `n45-0024-technician-field-mode` adds seven tables; it does not alter existing ticket scheduling or time columns. Deploy the migration and code together through the existing release gates. Rollback requires preserving field evidence and restoring the pre-upgrade database and application snapshot together.

## Verification and rollout

The release database harness exercises matching prerequisites, confirmed arrival, exact retry receipts, same-site ticket splitting, nonoverlapping activity timers, note evidence, blocker gates and responses, reviewed time, revoked client access, and shared-visit retention. Pure PHP tests cover invalid/stale GPS readings, distance, ambiguity, schedule bounds, and address invalidation. `tests/field/browser.cjs` uses synthetic data for mobile and desktop behavior, encrypted offline recovery, dropped-response retries, safe document rendering, project filtering, job search/creation, job management, conversation/time, approval re-requests, credential clearing, privacy cover, and cache isolation. The workspace database assertions additionally cover stale-update rollback, recipient-safe email queuing and retries, credential isolation, published runbook creation, approval decisions, documentation re-verification, and ticket lifecycle transitions. It requires Playwright and its Chromium browser.

Before broad field use, perform the signed-in PSA canary in the user’s local Firefox and an actual Android Chrome/PWA visit: install, deny and allow location, verify a test site, confirm arrival, background/resume, recover a note after losing connectivity, attach a photo, submit and resolve a blocker, finish, and review time. Also create an unscheduled job, send a test customer update, request and decide an approval using separate users, inspect an asset and credential, verify documentation, resolve/reopen, and close a test ticket. Check the customer portal with a contact who owns that appointment. Synthetic browser and disposable-database tests do not substitute for that device canary.
