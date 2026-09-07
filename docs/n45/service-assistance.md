# Follow-ups and resolution knowledge

These features extend the existing PSA and Field Mode. The implementation is staged for `next`; production deployment is a separate release action. Both use existing client scope and support permissions. Suggestions use recorded evidence and deterministic matching; they do not call an external AI service or share content across clients.

## Follow-ups

Open **Tickets → Follow-ups** or **Today → Follow-ups** in Field Mode. Filter by assignment, due/upcoming, type or client name. The ticket sidebar also links to its own follow-ups.

| Source | Initial follow-up deadline | Initial owner |
| --- | --- | --- |
| Next action / waiting on a client or vendor | Recorded next-action deadline | Ticket assignee |
| Customer commitment | Recorded promise deadline | Commitment creator |
| Pending ticket approval | 24 hours after the request | Requester |
| Pending task approval on an incomplete task | 24 hours after the request | Requester |
| Open or acknowledged field issue | Recorded response deadline | Issue owner |

A plan adds an eligible internal owner, a future follow-up date, an optional different escalation recipient, a later escalation date, and a required next step/reason. An unplanned item has no escalation recipient. If an owner loses access, the ticket assignee or creator is used only if eligible; otherwise the queue says **Needs an owner**. An invalid escalation recipient must be replaced explicitly.

Planning does not extend a customer commitment, approve a request, resolve an issue or change ticket status. Source changes invalidate the old plan. Source completion removes the item; closed, deleted and archived-client tickets are excluded. Approval decisions retain their original routing. Local PSA deadlines are converted to UTC; Field Mode dates and follow-up plans are stored in UTC.

The **Service Follow-ups** cron job runs every 15 minutes under the existing global cron gate. It sends internal in-app reminders, plus escalation notices when explicitly planned. Each unchanged source/plan, recipient and notification stage is delivered at most once per UTC day. New plans may create a new reminder that day. Unique receipts and a transaction keep retrying and concurrent workers from duplicating notifications. Reads refresh after acquiring the canonical client/ticket locks, so work completed while a worker waits is no longer due.

The queue examines at most 5,000 sources per filtered view and displays 40 items per page. It warns when the source limit is reached; the cron job also reports that capacity condition. Filter by client/type for inspection and review queue capacity before using this at substantially higher volumes. Items with no eligible owner need assignment from **All accessible work**.

## Suggested fixes

The ticket sidebar and Field Mode's **Suggested fixes** section show successful prior resolutions and current documents for the same accessible client. Matching asset/service relationships and shared subject/resolution/document terms determine relevance; each result shows the reason, excerpt, timestamp and source link. The first version ranks the 200 most recent matching candidates per source, returns eight results and reports a candidate limit when reached.

Successful resolutions require a resolved/closed ticket, a recorded summary and one of: fixed, workaround, configuration changed, access restored, request fulfilled or client confirmed. Failed, canceled, duplicate and generic legacy closures do not become suggested resolutions. Document access additionally requires client-module read permission.

## Knowledge review

From a successful resolved ticket, choose **Capture this resolution**. Capture creates one reusable draft per source ticket and opens that same draft on retries. Edit the problem/applicability, resolution steps, and checks/cautions; remove secrets and verify the scope before requesting review.

| Action | Required permissions |
| --- | --- |
| Read knowledge and its source documents | Support read + client read + client access |
| Capture, edit, submit, start a revision | Support write + client write + client access |
| Publish or return for changes | Full Support + client write + client access; different from creator and last editor |

Submitting requests an in-app review from currently eligible peers. If none exist, both interfaces explain which access an administrator must assign. Publication requires a review reason and a source resolution that still matches the reviewed draft. It creates an internal client document and links the source asset when valid. Drafts and pending reviews never become reviewed suggestions.

Published articles can start a revision. The draft starts from the last reviewed article; authors must read and acknowledge relevant changes in the current client document. Review cannot overwrite a document edited concurrently. Publishing a revision preserves the previous document version and updates the same document ID, while invalidating linked documentation verification through the existing lifecycle helper. An edited document, changed source resolution or pending revision is excluded from reviewed suggestions until republished. Restore an unavailable published document before revising it.

Desktop and Field Mode support the complete capture, edit, submission, return, publication, revision and pagination flow. These actions require a connection. Client data and drafts from this feature are not stored in the service worker or offline note vault; save drafts before navigating away.

## Retention and release

Migration `n45-0026-service-assistance` adds `service_followup_plans`, `service_followup_notices`, `service_assistance_events` and `service_knowledge`. It changes no existing ticket or document records. It is registered in the N45 reservation inventory, final schema and fingerprint checks, and is safe to replay before its ledger receipt is written.

Plan and knowledge history are protected ticket evidence. Client transfer is blocked after assistance history exists. Recoverable ticket deletion hides records and restoration reveals them again. Explicit ticket purge removes assistance metadata and receipts through the existing retention workflow; an already published client document remains an independent client record. The linked knowledge document and its versions inherit the existing canonical-document archive/deletion guard. Both desktop and legacy API client deletion refuse retained assistance history. Activity screens show the latest 20 events; the database keeps the complete history.

Before eventual production release, use the existing `next` → `main` gates and a matched database/application backup. To roll back, stop the Service Follow-ups job, preserve review and notification evidence and restore the matching pre-upgrade snapshots. Do not drop these tables from an active installation.

Verification covers fresh install, upstream/legacy upgrade, migration replay, canonical sources, role/client scope, stale plans, changed sources, concurrent notification workers, completion while waiting for locks, retry receipts, independent review, document revision conflicts, retention, actual HTTP sessions/CSRF/method checks, and desktop/Field Mode browser workflows. Local browser evidence uses disposable fixtures, not the live PSA or physical Android hardware.
