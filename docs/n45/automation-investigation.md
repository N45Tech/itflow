# Read-only automation investigation (phase one)

This feature analyzes **retained alert telemetry**, not live client systems. It cannot execute scripts, call RMM tools, remediate, resolve/change tickets, post replies, send messages, or change escalation. GoAlert and existing ingestion continue independently. The separate technician-only ticket card contains a summary, a possible cause, model-reported confidence, missing evidence and suggested non-destructive checks. Treat output as unverified advice, not proof of a diagnosis or resolution.

## Deployment and explicit pilot opt-in

Apply the normal N45 application/database update including `n45-0030-automation-investigation` before enabling the worker. Do not alter old migration files or deploy application code without its matching schema. Both the deployment feature and cron job ship **off**. No production provider or client has been preselected.

Configure exactly one AI Models row with use case **Automation Investigation**, selecting an existing approved AI provider. This worker uses a fixed safety prompt; it ignores that model row's custom prompt and never falls back to General/Tickets. The provider must support OpenAI-compatible chat completions, JSON content and the chosen token-limit field. No tools are supplied. Model-specific temperature is optional; leave blank when unsupported. Multiple dedicated models fail closed rather than selecting one silently.

Set these in the private deployment environment, not this repository:

```dotenv
N45_FEATURE_AUTOMATION_INVESTIGATION=0
N45_AI_INVESTIGATION_CLIENT_IDS=
N45_AI_INVESTIGATION_PROVIDER_HOST=
N45_AI_INVESTIGATION_DAILY_LIMIT=20
N45_AI_INVESTIGATION_TOKEN_FIELD=max_tokens
```

Select a small, explicitly approved pilot: `CLIENT_IDS` must be exact positive ITFlow client IDs separated by commas without spaces, not display names or `*`. `PROVIDER_HOST` must equal the HTTPS host in the provider's full endpoint URL, without scheme/path; credentials, query strings, fragments, non-HTTPS URLs and other hosts are rejected. Provider keys remain in the existing AI Providers configuration, never in alerts or these new settings. The approved host may be a deliberately configured private/self-hosted service. Network egress should also be restricted outside PHP. The host allowlist is an administrator trust boundary, not a general SSRF firewall.

After reviewing client authorization, sensitive-data handling, provider contractual/data-retention terms and model behavior on synthetic alerts, set the feature flag to `1`, recreate both web and cron containers with the normal deployment process, and enable **Read-only Incident Investigation** under Maintenance > Cron. Master cron and the parent automation feature must also be enabled. The existing minute dispatcher will discover eligible **currently open** incidents, including an open backlog, for the approved clients. Existing high-priority human/client-created tickets are excluded.

For providers requiring `max_completion_tokens`, select that field instead. Unsupported or truncated responses fail safely; no repair/retry request is made. A real provider smoke test is still required before client-data activation: fixture tests use a mocked transport and do not prove a specific model's compatibility or output quality.

## Data boundary

The outgoing evidence is a fixed projection of source, severity, bounded title/description, observed timestamp and a read-only scope notice. It excludes ticket discussions, attachments, arbitrary metadata, identity objects, raw endpoint snapshots and credential stores. Known secret patterns, emails and source URLs are removed before persistence and transmission. Evidence and result hashes/records remain associated with the exact incident, client and ticket.

**Redaction is heuristic, not a DLP guarantee.** Free-text alert descriptions can contain sensitive information, including names, addresses or unrecognized secrets. Do not activate for sensitive or regulated client data until the approved provider and applicable agreements cover that processing. This rollout does not assert that data is anonymous or that a provider cannot retain it.

Source API-key/principal validity and tenant authority are rechecked before dispatch and when accepting output. Ticket reassignment, archive, recovery, deletion or a changed incident generation prevents publishing the result. Source/provider data is never promoted to a system instruction. Output must match a bounded five-field JSON schema, is redacted again and escaped as text in the agent UI. No client, guest, email or public API output path is added. Historical advisories are labeled and may remain useful as history, not current evidence.

## Reliability and cost controls

One durable generation key binds incident/client/ticket, recurrence opening time and semantic signal hash. Repeated deliveries or repeat counters do not buy another analysis. New content/recurrences can queue another generation. A database-scoped advisory lock prevents concurrent workers; a committed lease token fences late completions. The current source is checked before and after the model call. A source can change during a remote request; its already-transmitted evidence cannot be recalled, but obsolete output is discarded.

At most one provider call starts per worker invocation and per 60 seconds across workers. The durable daily ceiling defaults to 20 calls per UTC day (allowed range 1–100; invalid values disable the feature). Reservations count even if a later check cancels the call, so accounting is conservative. The request caps output at 1,200 tokens, connect time at 5 seconds, total time at 30 seconds and response body at 32 KiB. No redirects or automatic retries are permitted. Failed/unknown provider outcomes become terminal for that generation to avoid duplicate charges. This phase has no retry button: do not delete history to force a retry; investigate configuration and use a new synthetic incident.

The worker scans bounded batches and rate-limited pending work stays visible. Statuses: Pending, Processing, Complete, Superseded, Failed. Audit receipts capture queue, reservation and terminal outcome without prompts, keys or provider error bodies. Raw exception/provider responses are not logged. This is application-enforced append-only event recording, not tamper-proof storage against a database administrator.

Nightly maintenance removes evidence/result payloads older than 30 days even when the AI feature is off (master cron must still run). Minimal generation/audit metadata is retained to prevent replay after expiry. Permanent ticket deletion removes the related investigation/audit records through the existing authorized deletion transaction; recoverable deletion hides the result but preserves history.

## Disable and validate

Disable the deployment feature and recreate cron to stop new calls, or disable master cron immediately to stop the dispatcher and fence acceptance of in-flight output. An already-started remote request cannot be recalled. Disabling the per-job schedule affects subsequent dispatches, not an invocation already running. Keep schema in place for retention/deletion and historical reads. No source integrations, routing policy or remediation state need rollback.

Run `php tests/automation_investigation_test.php`, schema fingerprint and fork boundary tests. The **Read-only Investigation** GitHub workflow additionally executes all PHP regressions and real MariaDB fixtures for matching, rate limits, failed calls, revoked source authority, stale results, reassignment, leases, data cleanup and unchanged operational records. The normal release harness still validates fresh installs and historical upgrades. A green code/fixture check is not production activation.
