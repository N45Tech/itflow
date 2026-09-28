# PSA audit follow-up

This is the remediation and acceptance register for the 6 September 2026 audit of release `39b3767ad06bf4c1a9c4afc678df792e8d1de399` (PR #23). The original audit remains a historical record. Production readiness for items 2–6 is not signed off by passing synthetic tests.

## Implemented corrections

| Finding | Corrected behavior | Regression evidence |
| --- | --- | --- |
| Contact mobile lookup escaped client restrictions | Both phone alternatives share the same client scope. Contact reads use an explicit projection that excludes the verification PIN. | 117 real HTTP requests cover ID, email, phone, mobile, list, explicit client filtering and allow/deny combinations; invalid-key rejection is also exercised. |
| Replayed incoming mail created duplicate tickets | A durable mailbox/message receipt commits with the ticket or reply, its SLA changes, watchers, notification queue and custom-action outbox. Replays reuse the recorded result. | Sequential replay, four concurrent workers, an interruption before commit, and a replay after commit but before mailbox acknowledgement. Missing Message-ID uses a raw-message fingerprint; a reused ID with changed content is rejected. |
| Attachment writes could fail while reporting success | Original messages and allowed attachments are written, flushed, hash-checked and finalized before inserting attachment references. Storage failure rolls back processing and leaves the mailbox message unread and flagged. | Injected destination failure after original-file persistence for intake and replies; no partial ticket/reply, attachment records, acknowledgement or action survives. Retry after storage repair succeeds. |
| Failed reply could fall through into new-ticket intake | Processing failures throw to the receipt owner, which rolls back and prevents new-ticket fallback. Only a missing ticket reference returns the ordinary no-match result. | Reply-storage failure and recovery tests plus transaction-owner contracts. |
| Closed-ticket reply body was discarded | An active contact from the ticket's current client can append the reply and original/allowed files. The ticket stays closed, closure timestamps remain intact, and staff are notified to review follow-up work. | Closed-history persistence/replay, unchanged closure, foreign-client rejection and archived-primary-contact rejection. |

The final-schema, fresh-install, clean upstream upgrade, legacy bridge, migration replay and current-database no-op harness includes both new runtime suites. The existing 67 PHP regressions and eight generated integration workflow/payload checks also pass locally. CI and the protected next-to-main release record provide the final commit-bound evidence.

## Mail operations

Migration `n45-0025-inbound-mail-receipts` adds `email_ingestion_receipts` and `email_ingestion_actions`; it does not merge historical duplicates or rewrite existing tickets. Keep receipts with the database backup. Removing a completed receipt could allow old mailbox data to be ingested again, including after a ticket was purged.

The original message is staged under `uploads/.mail-ingestion`, denied over HTTP. Completed ticket/reply processing stores its original under the ticket and removes the staging copy. Failed/rejected messages retain their recoverable source and remain unread/flagged in the mailbox. After correcting storage, contact eligibility or another processing issue, an operator can remove the mailbox flag to retry. Do not delete the source to clear an error. Identity conflicts require manual review, not a receipt reset.

The new **Inbound Email Actions** cron job retries committed custom actions independently of mailbox acknowledgement. Its ten-minute processing lease is recoverable after a worker crash; failures wait five minutes before retry. Delivery is at least once: custom handlers receive a stable `$custom_action_idempotency_key` and must deduplicate irreversible external actions using that key. Receipt replay never enqueues a second action. No custom handler is invoked inside the ticket transaction.

Operators can inspect processing without reading message contents:

```sql
SELECT receipt_status, COUNT(*) AS messages, MAX(receipt_created_at) AS latest
FROM email_ingestion_receipts GROUP BY receipt_status;
SELECT action_status, COUNT(*) AS actions, MIN(action_available_at) AS earliest
FROM email_ingestion_actions GROUP BY action_status;
```

Rollback across this migration requires the matching application/database snapshot. Preserve mailbox originals and receipt state before restoring. A SQL import check alone is not a complete recovery drill.

## Outstanding operational acceptance

| Audit item | Still required |
| --- | --- |
| 2 — Complete service workflows | Witness actual onboarding/offboarding runs through pinned published runbooks, documentation, evidence, independent approvals and exported closeout. Confirm the production catalog bindings and eligible approvers. |
| 3 — Integration coverage | Read fresh Level, Intune, Entra and SentinelOne source health; reconcile unique assets, unmapped/duplicate identities, stale and retired candidates per intended client. Historical retained executions are not current coverage proof. |
| 4 — Client configuration | Complete the [agreement sign-off](#agreement-sign-off) for each intended client. Review the catalog, approvers, SLA calendar, documentation ownership and retention; review and publish the first real N45 Internal service review through the authorized reviewer workflow. |
| 5 — Mail delivery | Exercise real intake and attachments, authorized/resolved/closed replies, and a controlled outbound failure followed by recovery using an explicitly authorized test recipient. Local queue assertions do not establish SMTP/IMAP delivery or OAuth refresh. |
| 6 — Recovery | Restore a selected off-host backup into a clean isolated environment: matching app release, SQL, uploads and configuration; disable outbound jobs/ingress; boot PSA and verify login, representative records/files, documentation and approvals. Record backup age, hashes, actual recovery point/time and cleanup. |
| Physical Field Mode | Complete the signed-in Android Chrome/PWA and customer-portal canary in [field-mode.md](field-mode.md). Browser simulation does not establish GPS or background behavior on a physical phone. |

Live source health and client configuration require approved read-only operational access. No current production configuration inventory, real business review, real email delivery or off-host restore is claimed by this change.

### Agreement sign-off

ITFlow's agreement definition is an operational rule set. Publishing it changes applicable ticket coverage, billable flags, SLA targets, and review scheduling; it does not capture a customer signature, establish acceptance, or create an invoice charge. `Active` is an internal contract status. A published version only applies within its effective dates, and existing tickets retain their saved terms unless explicitly re-stamped. Each published agreement maintains its own business-review schedule, even when a different agreement wins ticket-rule selection; the Business Reviews page lists those schedules separately.

For each client intended for go-live, record the following in the release evidence before publishing its ITFlow definition:

| Evidence | Acceptance check |
| --- | --- |
| Customer terms | Identify the approved customer agreement, amendment, or SOW in its source of record, with approval evidence, effective dates, renewal/notice terms, and the responsible reviewer. Do not infer acceptance from ITFlow status. |
| Operational match | Compare coverage and exclusions, quantity caps, billable classifications, service hours, escalation process, SLA targets, and review cadence against those approved terms. Resolve discrepancies with the responsible owner. |
| ITFlow binding | Record the client ID, agreement record ID, published version/hash, operator, and publication date. If multiple published agreements overlap, ticket resolution chooses one definition for the whole client: latest effective start, then highest version number, then highest record ID. It does not choose by ticket scope. Confirm the selected definition is intended; if concurrent agreements must govern distinct scopes, stop sign-off until that binding is designed and verified. |
| Ticket proof | Create a controlled representative ticket for included, billable, and excluded scope where applicable, and verify the selected agreement version and ticket outcome. Save the ticket IDs and results without changing customer production work. |

The product does not currently store or validate the signed source during publication. Keep the human approval and source reference in the release evidence; the required publication reason can carry a short reference, but is not a substitute for that evidence.
