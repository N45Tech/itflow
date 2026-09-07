---
version: 1
slug: "agent-followups-php"
primary_target: "agent/followups.php"
related_targets: ["agent/knowledge.php","agent/includes/ticket_suggestions.php","agent/field/assistance.mjs","agent/service_assistance.css"]
---

# Service assistance

## Scope and mode

Operate. A local extension of the PSA and Field Mode for technicians managing follow-ups, finding applicable prior fixes, and reviewing reusable resolution knowledge. Inherit `PRODUCT.md`, root `DESIGN.md`, and `agent/field/DESIGN.md`; no new visual world or global system change. Workflow and release details remain in `docs/n45/service-assistance.md`.

## Task and content

Keep promises, pending approvals, and blocked work moving with an eligible owner and a clear next step. Suggestions carry client-scoped evidence: matching reasons, an excerpt, an update time, and a source action. Knowledge exposes problem/applicability, resolution steps, and checks/cautions before an independent publication decision.

## Implemented direction

The PSA keeps its existing chrome, compact headings, divided lists, and labeled inline forms. Follow-up rows put source context and plan notes beside an owner/deadline column; that column stacks beneath the main content on narrow screens. Suggested fixes stay inside the incumbent ticket sidebar card.

Field Mode uses its established spruce masthead, paper surfaces, teal actions, compact interface type, divided work rows, and touch controls. Follow-up filters sit in a collapsed native disclosure, followed by the active filter summary and work count, so the first viewport reaches actionable work. Planning uses the existing titled dialog. Knowledge remains readable prose with the review reason and publication/return actions in the same form.

**Context stays with the action.** Keep the source, client, owner, due state, and next step together in the row or plan. The plan shows the original deadline beside its controls; a new follow-up does not change that commitment or decide an approval.

**Escalation is explicit.** Owner-only plans remain visibly owner-only. A different recipient and later escalation time require an explicit choice.

**Review stays independent.** Keep source access, draft/review/published state, revision, and changed-source/document explanations visible. Drafts and pending reviews are excluded from reviewed suggestions. Publication requires a different authorized reviewer and a reason; revisions preserve prior document history.

## Evidence and remaining scope

Finish handoff: detector returned `[]`; the finish reviewer returned `ship` with no material fixes, scoped to the primary assistance files and seven captures under `.impeccable/review/service-assistance/`: `desktop-followups.png`, `desktop-knowledge-draft.png`, `desktop-knowledge-review.png`, `mobile-followups.png`, `mobile-followup-plan.png`, `mobile-knowledge-review.png`, and `mobile-knowledge-actions.png`.

These captures use disposable local fixtures. They do not establish production or physical Android verification. No unresolved visual decisions; release scope is staging on `next`, without production deployment. The local-extension exception in new-work §3 applies: no seed, comp, or quality-bar card, and no new shipping raster assets. Existing product-context formatting and global design-token normalization remain outside this brief.
