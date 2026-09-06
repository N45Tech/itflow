# Technician Field Mode

Field Mode is an Android-first, installable web app at `/agent/field/`. It extends the existing N45 PSA, using the signed-in technician’s support permission and current client access. Open it from the Support sidebar, a ticket, or a project. Android Chrome offers installation when the site meets the browser’s install criteria. HTTPS is required for device location, camera access, service workers, and encrypted recovery.

## Accepted workflow and visual contract

The technician starts with today’s scheduled work or the active visit. The interface retains N45’s ink, spruce, paper, and action-green palette and Segoe UI/system typography. It follows the user’s saved light/dark preference. The first mobile viewport identifies the job, client, site, and arrival action. A fixed bottom navigation gives access to Today, Projects, Time, Issues, and Drafts; on larger screens it sits below the header. Job sections keep instructions, documentation, tasks, notes, issues, and photos together. The only entrance motion reveals an active visit, with a reduced-motion fallback.

The user approved foreground GPS: on opening/sign-in or returning to the visible app, current location and assigned ticket schedules identify possible stops. The tech always confirms arrival. One clear match opens the job; multiple matches remain choices. No location reading marks a technician onsite automatically. Location denial or an inaccurate reading leaves manual check-in available.

## Arrival and customer updates

Arrival matching requires a verified site pin, a location reading accurate to 100 metres or better, a scheduled ticket within four hours, and a position inside the site’s configured radius (150 metres by default; 50–500 configurable). A technician with client write access verifies a pin while physically at the displayed address. Changing the address invalidates the pin. This avoids depending on an external geocoding account and makes ambiguous shared-address jobs explicit.

Travel, onsite work, waiting, and breaks are activities within one active visit per technician. Switching tickets is allowed within the same client and site. Finish the visit before moving to another site. The legacy ticket `onsite` planning flag is not used as presence.

The customer portal’s appointment ticket shows the technician, travel/onsite/completed status, optional estimated arrival, and the latest shared position with its update time and accuracy. Position is shared only when the technician enables it. Updates run only while Field Mode is visible; this PWA does not offer background tracking. Positions older than one hour are omitted, and the map link says “last shared location.” Finishing the visit or disabling sharing clears the precise position. Other tickets worked during that visit are not projected into the appointment’s portal view.

## Work, time, and evidence

Projects expose “My tasks” and “Entire project.” Existing ordered project tickets carry the work plan; no new project-stage abstraction is added. Task instructions, dependencies, required evidence, and canonical approval gates remain in force.

Guided note templates cover troubleshooting, installation, maintenance, survey, and project work. Action, result, and next step feed the existing structured work-note model; extra detail is optional. Dictation uses the device keyboard. Notes are internal, and their save action does not book time.

Finishing a visit records a customer-facing summary and optional typed or drawn acknowledgment. A signature acknowledges the visit, not additional scope. Reviewed activities become existing internal ticket time entries. Breaks record zero worked time; changes greater than one minute require an explanation. Database uniqueness prevents simultaneous active visits, overlapping activity timers, and duplicate submitted time entries. UUID request receipts commit in the same transaction as the work, making a lost response safely retryable.

Photos use the existing ticket attachment and file-staging system, with optional task evidence linkage. Supported formats are JPEG, PNG, and WebP, up to 12 MiB; signatures are PNG up to 2 MiB. Neither is stored offline. Barcode/QR scanning fills client-scoped asset search when the browser supports it; typing a name or serial number is always available. A full document link preserves access to diagrams and rich content alongside the mobile plain-text reader.

Issues record type, ticket/project/task context, impact, owner, response deadline, optional documentation reference, and optional photo. Owners receive an in-app notification and can acknowledge or resolve; support administrators may respond as well. Issue changes retain an event trail and internal ticket notes. An open issue blocks completion of its linked task and ticket. A scope request records a decision request without authorizing work.

## Recovery and lifecycle

Encrypted recovery is opt-in per technician on each device and requires a separate passphrase of at least ten characters. PBKDF2-SHA256 derives an AES-256-GCM key. Origin, account, and draft ID are authenticated with every envelope. Keys stay in memory and are dropped when the one-minute privacy cover activates. The passphrase is not stored or recoverable by the server. Only note drafts and their ticket context are persisted; client documents, API responses, photos, signatures, and customer locations are never service-worker cached. Offline drafts require unlocking and explicit review before sending after reconnect. Server sign-in, CSRF, module permission, and client scope are revalidated on submission and retries.

An active visit or unreviewed time prevents ticket resolution and recoverable deletion. Field history prevents transfer to a different client and participates in strict evidence retention. An explicitly authorized permanent purge removes only the selected ticket’s field records. If a finished visit spans other tickets, it remains attached to a surviving ticket, with the original customer summary and acknowledgment cleared and a retention event recorded.

Migration `n45-0024-technician-field-mode` adds seven tables; it does not alter existing ticket scheduling or time columns. Deploy the migration and code together through the existing release gates. Rollback requires preserving field evidence and restoring the pre-upgrade database and application snapshot together.

## Verification and rollout

The release database harness exercises matching prerequisites, confirmed arrival, exact retry receipts, same-site ticket splitting, nonoverlapping activity timers, note evidence, blocker gates and responses, reviewed time, revoked client access, and shared-visit retention. Pure PHP tests cover invalid/stale GPS readings, distance, ambiguity, schedule bounds, and address invalidation. `tests/field/browser.cjs` uses synthetic data for mobile and desktop behavior, encrypted offline recovery, dropped-response retries, safe document rendering, project filtering, privacy cover, and cache isolation. It requires Playwright and its Chromium browser.

Before broad field use, perform the signed-in PSA canary in the user’s local Firefox and an actual Android Chrome/PWA visit: install, deny and allow location, verify a test site, confirm arrival, background/resume, recover a note after losing connectivity, attach a photo, submit and resolve a blocker, finish, and review time. Check the customer portal with a contact who owns that appointment. Synthetic browser and disposable-database tests do not substitute for that device canary.
