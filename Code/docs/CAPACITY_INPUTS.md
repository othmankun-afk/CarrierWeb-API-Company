# Capacity planning inputs and calculations

`tools/capacity-plan.mjs` calculates a storage scenario from an input JSON and
local measurement files. It verifies their SHA256 pins and prints the report
without changing the installation. UNC/network paths and URI evidence paths
are rejected.

Start from `configuration/capacity-input.template.json` and fill in the `null`
values. Calculate each group of clients with a similar workload separately;
different sensor mixes can require different storage estimates. Test inputs
are fictitious and should be replaced with company measurements.

```powershell
node .\tools\capacity-plan.mjs .\configuration\capacity-input.template.json
node .\tests\capacity-plan.mjs
```

## Inputs and units

- `evidencePins`: array of `{id,path,sha256}`. Paths are resolved relative to
  the input JSON; pin source measurement reports, not private credentials.
- `current.fixedLogicalBytesPerClient`: current nonhistorical JSON bytes for
  one client (current bundle, scope/cursors, snapshots), without historical
  payloads. It is not physical disk allocation.
- `current.fixedAllocatedBytesPerClient`: separately measured nonhistorical
  table allocation. Do not substitute JSON bytes for physical allocation.
- `current.currentHistoryRowsPerClient`: all stored positions + CANbus +
  telemetry rows in the assessed client. It describes now, not retention.
- `current.historyRowsByEndpoint`: required in keep-all mode; these totals must
  equal the current combined count. Existing rows are not expired by the model.
- `current.sharedAllocatedBytes`: system/shared allocation counted **once**
  for the planned server, not once per tenant.
- `current.auxiliaryAllocatedBytes`: explicitly measured/reserved captures,
  logs and other files not already counted elsewhere. Unbounded capture/log
  growth needs its own archive policy; this calculator does not create one.
- `current.alreadyAllocatedBytesForPlannedScope`: existing bytes of the same
  scope, to deduct once when comparing **additional demand** to free disk.
  Use `0` for a new empty installation. For an existing installation, include
  its current allocation; a future retention policy may not shrink those files.
- `measurements.endpoints.*.payloadBytesPerEvent`: observed JSON bytes per
  event, with `payloadEvidenceId`. An endpoint average from one account is
  not a proven average for every new account.
- `allocatedDataBytesPerEvent`, `allocatedIndexBytesPerEvent` and
  `allocationEvidenceId`: **separate physical calibration**. Use a controlled
  table/index growth measurement at representative scale and record how
  allocation slack, metadata, JSON, IDs and indexes are included. Payload size
  and one small `.ibd` file are insufficient for this calibration.
  Allocated data already includes the payload: the physical total does not
  add payload bytes a second time. Small tables are allocation-step dominated.
- `workload.clientCount`, `vehiclesPerClient`: positive integer cohort size.
- `workload.storageMode`: `KEEP_ALL_NO_DELETION` in the template. Supply an
  explicit `planningHorizonDays` to size existing history PLUS new production
  over that horizon. No rows expire when that horizon ends.
- Optional `RETAINED_WINDOW_MODEL_ONLY` accepts `retentionDays` for a theoretical
  window calculation. It does not enable deletion or size indefinite keep-all
  operation. Upstream availability must be established separately.
- `eventsPerDayPerVehicle`: per-endpoint daily event production. With basis
  `STEADY_STATE_OBSERVED`, provide a pinned observation, a window in seconds,
  and `catchupExcluded:true`. Measure normal production separately from
  historical catch-up. Alternatively use
  `OPERATOR_ASSUMPTION` with a clear `basisDescription`; the result remains
  conditional on that assumption.
- `observedIngestion.eventsPerSecond` plus `evidenceId`: optional short-window
  ingestion rate used **only** for conditional row-guard stop runway. It is
  never used as the daily production rate or as a retention requirement.
- `infrastructure.freeDiskBytes`: measured current free space for the planned
  disk scope. Supply `reservedHeadroomBytes` explicitly; there is no default
  compression or headroom allowance.
- `backupCopies`, `backupSizeRatio`, `restoreWorkspaceCopies`: explicit disk
  budget. A backup ratio must be supported by the actual backup format/test;
  a logical dump can differ substantially from allocated InnoDB files. This
  simple model charges backup/restore copies to the same volume; assess
  independent backup volumes separately. It does not install a schedule.
- `requiredRpoSeconds`, `requiredRtoSeconds`: company requirements, separately
  from `verifiedBackupIntervalSeconds`, `verifiedRestoreSeconds`. Measure
  restore duration at the assessed scale and include DNS, secrets, validation
  and review time where required by the recovery target.
- `costPerGiBMonth`, `costCurrency`: optional storage-only commercial estimate.
  It excludes RAM, CPU, IOPS, hosting base fees, HTTPS, monitoring and labour.

## Calculation and verdict

For each endpoint and client:

`keep-all projected rows = current rows + ceil(events/day/vehicle * vehicles * planning horizon days)`

For the optional theoretical retained-window mode only:

`window rows = ceil(events/day/vehicle * vehicles * retention days)`

`logical bytes = rows * payload bytes/event`

`physical bytes = rows * (allocated data bytes/event + index bytes/event)`

The model adds fixed tenant allocation, multiplies by planned client count,
then adds shared and auxiliary allocation **once**. Backup copies, restore
workspaces and explicit headroom are added separately. It compares additional
demand, after scope-matching existing allocation, with measured free space.
This is a linear scenario calculation, not a MySQL allocation guarantee.

Missing decisions or calibrations produce `BLOCKED_MISSING_INPUTS` with the
exact fields. Derived values with missing dependencies are `null`, not zero.
Complete operator assumptions produce `CONDITIONAL_OPERATOR_ASSUMPTION`.
Observed scenarios remain `CONDITIONAL_CAPACITY_MODEL`; neither means
production-ready. The separate `feasibility` section explicitly identifies
disk, technical-row-guard or recovery requirements that are not met; a fully
entered scenario can therefore still fail its requirements. Per-vehicle
figures are averages of this scenario, not new observations or per-day rates.
Numeric values must be finite, nonnegative and safely
representable; counts must be safe integers. Arithmetic overflow is rejected.

## Existing 100000-row guard

The assessment shows the **100000 combined historical rows per tenant**
default guard, remaining rows, projected-row fit and optional time to the
guard. This is a technical threshold, separate from disk capacity and
retention. A page fails if it would exceed the guard; its size can cause
collection to stop before exactly 100000 rows. The calculator does not know
the next page size.

A tenant faulted at this guard needs a capacity decision and reviewed
recovery before collection resumes. The calculator leaves quotas, stored
rows and recovery state unchanged. Validate historical coverage, power-loss
recovery, offsite backup and DPAPI restoration separately.
