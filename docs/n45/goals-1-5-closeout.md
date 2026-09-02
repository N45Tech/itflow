# Goals 1–5 acceptance closeout

`docs/n45/goals-1-5-acceptance.json` is the machine-readable map from every
Goal 1–5 required outcome, runtime/freshness rule, and definition-of-done item
to repository evidence. Its `source_sha256` pins the exact goal-list snapshot
used for this audit. It deliberately keeps three evidence states separate:

- `not_required`: the item is a code/contract property.
- `pending`: only live data or a post-deployment canary can close it.
- `externally_recorded`: the roadmap records earlier production evidence, but
  the local report does not re-certify it.

Run the deterministic local audit with:

```bash
php scripts/n45-goals-1-5-audit.php --pretty --output=goals-1-5-report.json
```

Validate only the manifest and referenced test inventory with:

```bash
php scripts/n45-goals-1-5-audit.php --validate-only --pretty
```

The audit never changes production evidence status. A passing local report
means that the acceptance behavior and source contracts passed at the audited
Git SHA; it does not mean that production canaries ran.

## Deterministic scenario coverage

- `device_identity_burn_in_acceptance_test.php` verifies exact per-asset source
  coverage, timestamp-insensitive replay, explicit retirement disposition,
  orphan/duplicate/review queues, client ownership, and tenant/site scope.
- `runbook_closeout_acceptance_test.php` builds all 43 onboarding tasks and all
  25 offboarding tasks from the canonical starter definitions, then verifies
  immutable hashes, exact task mapping, complete state history, evidence,
  approvals, and self-approval rejection.
- `documentation_scenario_acceptance_test.php` uses a fixed clock for honest
  Current, Stale, Missing, Exception, expired-exception, waiver, and closure
  gate outcomes. Exception and waiver decisions require a distinct actor.
- `unified_endpoint_contract_acceptance_test.php` validates the source-neutral
  endpoint record across identity, posture, warranty/lifecycle, editable
  physical/virtual interfaces, MAC/IP history, VLAN and LLDP/switch-port state,
  timeline, tickets, evidence, and documentation.

## Production-only closeout evidence

The report currently retains these live prerequisites:

1. Goal 1: agree the required-source policy for each active managed endpoint,
   capture a pre-replay export, replay the same source payloads, allow at least
   one scheduled identity-reconciliation cycle, and pass the burn-in invariants
   with all queues empty or explicitly retired/ignored.
2. Goal 3: after deployment, complete one synthetic-client Managed Care
   Onboarding and one Client Offboarding execution through the authenticated
   integrity-verified closeout export.
3. Goal 4: after deployment, create honest Current, Stale, Missing, Exception,
   and closure-gate canaries. A second authorized actor must approve the
   exception; do not backdate production evidence.
4. Goal 5: deploy the combined candidate and run the consolidated Network
   Interfaces/read-only source-observation presentation canary.

Keep the generated local report, deployment SHA, canary timestamps, actor-safe
audit references, and exported closeouts together as the release evidence set.
