# Automation Investigator

The Automation Investigator adds asynchronous, read-only analysis to tickets created by the Operations event broker. It runs only after source normalization, identity resolution, incident correlation, ticket creation, and event persistence have completed. It never participates in the ingest transaction and cannot delay or roll back an alert.

## Activation

1. Configure an OpenAI-compatible provider under **Admin > AI Providers**.
2. Add a model under **Admin > AI Models** with the exact use case **Automation Investigation**.
3. Leave the **Automation Investigator** cron job enabled under **Maintenance > Cron**.
4. Deliver a canary event and verify that its ticket shows a completed read-only investigation.

The workflow deliberately does not fall back to a General model. Without the dedicated model, the cron job exits successfully without queuing evidence. After configuration, it picks up the latest eligible signal for existing open automation incidents as well as new ones.

## Evidence boundary

The investigator receives only:

- The redacted canonical Operations payload and up to four preceding signals.
- Incident source, severity, timing, and correlation counts.
- Ticket priority, impact, and urgency.
- The mapped location, asset, service, and non-personal endpoint posture fields.

It is not intentionally given credentials, contact names, assigned-user identity, asset notes, ticket replies, arbitrary documentation, or provider API keys. Sensitive key names and common inline credential forms are redacted again before transmission, strings and collections are bounded, and requests larger than 64 KiB fail closed.

Source text is marked as untrusted data in the system and user messages. Model output must pass a strict JSON contract before it is stored or rendered. ITFlow escapes every displayed field and never accepts model-provided HTML.

## Lifecycle

Each eligible source event may produce at most one investigation. A database lease prevents concurrent processing, expires after ten minutes, and retries provider or contract failures up to three times with bounded backoff. An incident that recovers before analysis is marked skipped. Completed records retain the provider, model, prompt version, input hash, structured result, and completion time for audit.

The ticket shows the latest investigation inside its existing automation incident card. It separates the summary, likely cause, impact, confidence, observed evidence, recommended next steps, and unknowns. Every completed result states that no remediation was attempted and requires technician verification.

## Safety boundary

This release contains no remediation executor, tool calling, shell access, endpoint action, ticket status transition, or ticket reply write. Future remediation must use separately reviewed, allowlisted, idempotent playbooks with deterministic verification and cannot treat model output as authorization.

To pause the feature, delete or reassign the dedicated Automation Investigation model, or disable the Automation Investigator cron job. Preserve completed and terminal queue rows for audit.
