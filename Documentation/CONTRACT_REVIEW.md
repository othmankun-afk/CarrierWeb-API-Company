# Contract review and historical storage

The API is a locally tested EAI-backed implementation, not a certified copy
of every legacy behavior. This review separates measured behavior, local
implementation choices and the questions still requiring the provider.

## What the current implementation does

The five GET routes read the local publication and collected history. They
do not query the CarrierWeb production database or fetch missing EAI history
during a customer request. A successful empty array only describes the
matching local rows; it does not certify source coverage.

The SDK transport uses account-wide incremental cursor pages, not a proved
arbitrary vehicle/date-range query. A separately reviewed historical page
can omit the requested period, even when older data exists in another feed.
There is no demonstrated end-of-backlog marker or complete cold-period
recovery time in this review.

Existing legacy observations include history older than fifteen minutes.
The oldest returned sample is not a guaranteed retention boundary. Local
10,000-row/16 MiB response guards, the 60-second dispatch rule and the default
100,000-event history guard are not measurements of upstream limits.

## Cursor handling and historical text

| Area | Correction | Boundary retained |
| --- | --- | --- |
| Partitioned cursors | Keep the larger saved/advertised ID per partition, matching the official SDK. Decimal IDs are compared exactly. | Missing partitions and invalid IDs are rejected; no reset. |
| Existing cursor fault | Explicit `REPLAY LOCAL` reviews only the matching persisted failure and received page. | No EAI call, no changed capture time, no automatic retry. |
| Historical empty text | Convert exact empty strings to the SDK's single-space representation when serving EAI history. | Stored events/hashes, identifiers, timestamps, numeric values and current snapshots are not rewritten. |

These rules apply to authorized accounts, not to a hardcoded
customer. A failed transport without a valid received page is a different
case and still needs a reviewed recovery decision.

## Poll-deliver-delete: not approved

No purge is implemented or enabled. A safe minimal-history design needs all
of the following before choosing a retention policy:

1. The supported customer look-back period and response-time requirement.
2. A supported source refill method, retention guarantee and completeness
   marker, verified with independent expected events.
3. Representative pages required per cold period and measured import/index
   time, alongside normal synchronization on the same account scheduler.
4. A concurrency/repeat policy: one successful GET does not mean every
   consumer has received or durably acknowledged the data.
5. A company-approved disk budget, backup policy and retention decision.

For N required pages, dispatches alone span at least `(N - 1) * 60` seconds,
before considering the first eligible slot, transport, validation and import.
N is currently unknown. One page's duration cannot supply it. The current
maintenance path also requires the installation to remain stopped, so it
does not demonstrate backfill alongside an uninterrupted live collector.

Deleting local history while keeping its advanced cursor does not make those
events reappear on the next incremental poll. Refill may be impossible once
upstream retention expires. Keep history and the existing protective quota
until the above decisions are recorded. Do not enlarge a quota simply to
postpone the decision.

## Provider confirmations still needed

| Topic | Required confirmation |
| --- | --- |
| Contract limits | Guaranteed historical depth by feed, maximum date window/rows/bytes, clipping behavior, pagination and sustained request allowance. |
| Fleet scope | Which vehicles should the API and EAI identities authorize, including inactive/type-specific vehicles? |
| Landmarks | Is the legacy historical `LandmarkID` copied from the event or enriched elsewhere, and using which effective-time rule? |
| Telemetry | Which types does the legacy API expose/filter, and what are their current definitions, units and sentinel values? |
| Refill | Supported historical replay method, page caps, independent completion signal and interaction with normal cursors. |
| Errors | Authoritative invalid-authentication and denied-vehicle responses; remaining observed errors are not universal guarantees. |

Until these are answered or covered by independent acceptance fixtures, do
not claim full legacy equivalence, bounded cold-history latency or a minimal
copied database. Company-host installation, real advancing cycles, HTTPS,
capacity and restart/recovery acceptance remain separate requirements.
