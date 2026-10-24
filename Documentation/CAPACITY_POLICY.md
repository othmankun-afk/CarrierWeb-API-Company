# Storage and capacity decisions

The current policy is **keep data, do not delete**. No purge, timed retention
or poll–deliver–delete procedure is enabled.

## What the current guards mean

Without an explicit policy, the existing guard allows at most 100,000
combined historical events per tenant. It can refuse a page before exactly
that count if the page would not fit. This is a protective implementation
bound, not a retention period or a production sizing recommendation.

An approved `EAI_SUITE_CAPACITY_V1` policy can set `maxTenantEvents`,
`maxTenantPayloadBytes`, `maxPublicationEvents` and `warningPercent`.
The page quota counts mapped rows, including already known rows; it bounds
work, not just net growth. A full account is refused before another EAI
attempt. A page that exceeds quota is rolled back as a whole: current state,
history, generation and cursors are not partially committed. The pending
capture and previous publication remain available for review.

These controls do not remove data, clear faults, replay captures or prove
free disk exists. Keep-all history and retained captures/logs still grow.

## Evidence needed before changing capacity

Measure physical data and index allocation per event at representative scale,
separately from JSON payload bytes. Record steady-state production per vehicle
and endpoint outside catch-up. A quick ingestion of older events is not a
daily live production rate. Reserve space for current state, captures, logs,
other tenants, backups, restore workspaces and headroom.

Choose a planning horizon for sizing, not for expiration. State the expected
fleet, client count, concurrent workload, recovery point/time requirements
and storage budget. Tests using fictitious data do not establish a client
budget or a physical-server cost. Compression is lossless for current internal JSON
but is not unlimited storage and does not change API JSON output.

The read-only calculator is `Code/tools/capacity-plan.mjs`; start with
`Code/configuration/capacity-input.template.json`. Supply the required
measurements before calculating an estimate. Input definitions and equations
are in `Code/docs/CAPACITY_INPUTS.md`.

## Explicit quota evidence

Policy evidence requires these fields:

```text
evidence.reference
evidence.sha256
evidence.approvedBy
evidence.approvedUtc
evidence.measuredHistoryBytesPerEvent
evidence.historyBudgetBytes
evidence.safetyMarginPercent
```

The tool checks the evidence file's actual SHA-256 and budget consistency.
A hash proves file identity, not the truth of a measurement or an approval.
Integer quota/measurement fields are bounded by 2147483647 for PHP 5.6
32-bit compatibility. Larger values require representation changes and tests,
not coercion to floating point.

## Applying a policy to a stopped instance

`Code/tools/configure-suite-capacity.mjs` accepts absolute plan, tenant,
policy and evidence paths. Stop first and confirm all four ports are closed.
The tool validates the private installation marker and owner, takes a lock,
validates policy through PHP and preserves prior configurations and evidence
under `capacity-policy-reviews/<UUID>`. It does not connect to SQL or EAI.

The client plan, HTTP reader configuration and DPAPI publisher connection
are updated consistently. Other clients are retained. If final writes are
only partial, `registration.lock` stays in place and the service must remain
stopped until the prior and prepared configurations have been reviewed and
the interrupted update resolved. A quota change leaves any `FAULT_*` state
for separate recovery review.

## Current operational conclusion

Local tests using fictitious data cover refusal, isolation and recovery
behavior. They do not yet justify a production server size, cost per client,
retention promise or indefinite keep-all operation. Target load, physical
growth and backup/restore measurements are still required before approving
production capacity or increasing the current guards.
