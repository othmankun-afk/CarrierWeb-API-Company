# API contract

This contract describes the API routes, responses and EAI mappings. The
response format follows the existing API; remaining differences in source
measurements and conversions are listed in the contract review.

## Routes and authentication

All data routes use case-sensitive GET paths:

```text
{base}/cweai/{username}/vehicles?password={password}
{base}/cweai/{username}/vehiclesBase?password={password}
{base}/cweai/{username}/positions?password={password}&vehicleid={id}&ymdfrom={start}&ymdto={end}
{base}/cweai/{username}/canbus?password={password}&vehicleid={id}&ymdfrom={start}&ymdto={end}
{base}/cweai/{username}/telemetry?password={password}&vehicleid={id}&ymdfrom={start}&ymdto={end}
```

`base` is installation-specific. Local mode listens on `127.0.0.1`; shared
hosting requires a server address and HTTPS. The API username and generated
password are not EAI or MySQL credentials. Basic and Bearer authentication
are not supported alternatives in this contract.

The password in a URL is a secret. Exclude query strings from access logs,
screenshots, tickets and shared reports. The historical permissive CORS
header does not authenticate a caller. Do not expose the local PHP test server
as a public web service.

## Source scope and freshness

The fleet is the full set authorized by the tenant's EAI account. It is not
necessarily the same set or order as the existing provider's API. Current
vehicle lists preserve their incoming EAI order; no equivalent old-provider
ordering rule has been established. Match vehicles by `CWVehicleID`, not row
position or a non-unique label such as `MT CAN`.

The collector requests vehicles, divisions, positions, CANbus and telemetry;
wired telemetry can enrich current vehicle signals but has no sixth public
URL. Valid pages are published atomically to local MySQL. HTTP requests read
that local publication; they do not trigger EAI collection or backfill.

`LastReportTime`, `LastLocationTime` and sensor timestamps describe source
events and retain their original times after synchronization. Missing,
invalid or unavailable publications are refused.

Newly registered accounts and the update procedure use
`last-known-with-warning`. This keeps the last valid publication available
during an EAI outage. HTTP 200 responses include `X-Data-Freshness`,
`X-Data-Captured-At`, `X-Data-Age-Seconds` and `X-Collection-State`.
STALE is not live data. These headers describe publication freshness, not the
age of every sensor or proof of complete history. Scope is the last published
authorized fleet; upstream revocations cannot be discovered during an outage.
Older configurations without the explicit policy retain fail-closed behavior.
Unauthorized, corrupt or future-dated data is never served by this policy.
The `Documentation/OUTAGE_RECOVERY.md` guide explains recovery and updates.

## Dates and history

`vehicleid` is an authorized positive decimal `CWVehicleID`, from 1 to
2147483647, without leading zeros. Accepted input dates are UTC:

```text
YYYY-MM-DD
YYYY-MM-DD HH:MM
YYYY-MM-DD HH:MM:SS
```

URL-encode spaces and reserved characters. Missing time means `00:00:00`,
including on `ymdto`. A full day therefore needs an explicit end time:
`ymdfrom=2026-10-20%2000:00:00&ymdto=2026-10-20%2023:59:59`.
Both boundaries are inclusive.

History is ordered by measurement time, then event ID. Event IDs are strings
to avoid numeric rounding. CANbus `MeasuredTime` uses `DD.MM.YYYY H:MM:SS`;
other measurement timestamps use `YYYY-MM-DD HH:MM:SS`.

History queries read retained local records. The upstream historical depth
has not been established; request-time backfill and automatic deletion are
not implemented. An empty array means no matching **local** rows, without
establishing upstream availability or complete coverage. Response-size limits
apply separately from retention.

## Limits

- At least 60 seconds between requests for the same tenant and endpoint.
  Vehicles, date ranges and history diagnostics share that endpoint's budget.
- EAI dispatch has a separate persisted minimum interval of 60 seconds.
  Browser refreshes cannot bypass it. Ambiguous attempts are not retried automatically.
- The local HTTP profile allows up to 10,000 rows and 16 MiB per response.
  Split larger history windows; there is no automatic HTTP pagination.
- Concurrency, request deadlines and storage guards are separate controls,
  not substitutes for the minute rule. Locks coordinate one host, not a
  distributed cluster.
- The default combined history guard is 100,000 events per tenant. It is a
  protective bound, not proven production capacity or a retention period.
  Configured quotas can replace it after capacity sizing. No automatic purge
  makes room for a new page.

## Public responses and errors

Successful data responses are HTTP 200 with
`Content-Type: application/json; charset=utf-8` and an array of objects.
Strings remain strings; numbers are finite JSON numbers. Spaces, sentinels
and codes are not trimmed or relabelled by the serializer.

For EAI-backed history (`positions`, `canbus` and `telemetry`), an exact empty
text value is returned as one ASCII space, matching the SDK's text convention.
This applies only when reading the response. `ID`, `MeasuredTime`, numeric
values and current vehicle snapshots are unchanged, as are stored events and
their hashes. Existing non-empty text is preserved.

For compatibility, most public failures return an **empty HTTP 503** body.
This includes invalid access, unavailable source state, oversized results and
minute-limit failures on endpoints other than positions. A 503 is not enough
to diagnose its cause; consult private status and diagnostics.

Some historical positions responses deliberately differ:

| Condition | Preserved response |
| --- | --- |
| Recognized missing parameters | HTTP 200, historical documentation/text |
| Recognized empty start | HTTP 200, `[]` |
| Recognized invalid start | HTTP 200, text beginning `ERR:` and ending `[]` |
| Minute quota exceeded | HTTP 200, `API ERROR: getPositionsHistory function limit request per 1 minute reached[]` |

Clients must inspect both status and body, not assume every HTTP 200 is JSON.
Where supplied, `Retry-After` describes the remaining wait. An authenticated
`/diagnostic/{username}/history` route can explain observed coverage and
failures; it does not establish completeness or bypass the endpoint quota.
Legacy wire text is preserved, even when its wording or language differs
from these English guides.

## Exact field names and JSON types

`s` means string and `n` means number. The lists below are checked against
`Code/src/Contract.php`. Case is significant. These are output types, not a
universal physical-unit dictionary.

### vehiclesBase — current reduced state, 14 fields

```text
CWVehicleID:n VehicleType:n VehicleDescription:s License:s LastLocationTime:s LastReportTime:s Latitude:n Longitude:n Country:s Location:s Zipcode:s CurrentSpeed:n GPSOdometer:n Ignition:n
```

### vehicles — current full state, 44 fields

```text
VehicleNumber:s CWVehicleID:n VehicleType:n VehicleDescription:s License:s Owner:s SubcontractorCode:s DivisionID:n DivisionDesc:s LastReportTime:s LastLocationTime:s Latitude:n Longitude:n Country:s Location:s Zipcode:s CurrentSpeed:n GPSOdometer:n TachoOdometer:n LandmarkID:n LandmarkDesc:s CWDriver0ID:n Driver0Name:s Driver0Number:s CWDriver1ID:n Driver1Name:s Driver1Number:s HOSActivityId:n HOSActivityTime:s LastCanbusTime:s CWTrailerID:n Plugged:s PluggedType:s FuelLevel:n FuelLevelTime:s VIN:s Fuel:n Ignition:n Door:s Temperature:n Engine:s Battery:n EngineRunningTime:n EngineRunningTimeHHMM:s
```

`DivisionDesc` joins the vehicle's EAI `DivisionID` to the divisions feed's
`Description`; division ID 0 has the `"-"` marker. `Location` is EAI
`LastLocation`, not proof of the same geocoding label as the old API.

Door, Temperature, Battery, Engine, Plugged and PluggedType use the delivered
EAI signal policy: a unique applicable physical source, controlled temporal
selection and no arbitrary choice between ambiguous channels. Battery is
kept in its mapped raw representation, not labelled as volts. Engine uses
the supported RPM source where available, not a guessed battery threshold
or an automatic synonym for Ignition. Temperature and switch states depend
on supported EAI type definitions; see the mapper and its fixture tests.

Missing, unsupported or ambiguous measurements use these unknown markers:

| Fields | Unknown marker |
| --- | --- |
| Door, Engine, Plugged | `"-"` |
| PluggedType | `" "` (one space) |
| Temperature, Battery | `-1000` |

These markers represent unavailable measurements. The generic profile has no
sensor TTL, so publication freshness must be checked separately from each
sensor's measurement time.

In vehicles, EngineRunningTime=`0` and EngineRunningTimeHHMM=`""` are
**compatibility constants**, not measured running time. The CANbus
field with the same name is a separate event value. Do not infer a measured
vehicle counter from the constants.

### positions — GPS history, 14 fields

```text
ID:s CWVehicleID:n MeasuredTime:s GpsValid:n Latitude:n Longitude:n Country:s Location:s Zipcode:s Ignition:n CurrentSpeed:n GPSOdometer:n PositionType:n LandmarkID:n
```

### canbus — CANbus history, 33 fields

```text
ID:s CWVehicleID:n MeasuredTime:s CWDriverID:n DriverNumber:s DriverName:s Odometer:n Fuel:n CO2:n FuelLevel:n HighRPM:n HighTorque:n CruiseControl:n Accelerations:n OverSpeed:n BrakeApplications:n HardBrakeApplications:n EngineRollout:n EngineRunningTime:n IdleTime:n IdleFuel:n IdleCO2:n PTOTime:n PTOFuel:n PTOCO2:n GreenZoneRPMFuel:n GreenZoneRPMDistance:n GreenZoneRPMTime:n RetarderDistance:n RetarderTime:n CatalystLevel:n LongIdleTime:n UnprocessedOdometer:n
```

### telemetry — sensor history, 7 fields

```text
ID:s CWVehicleID:n MeasuredTime:s TransponderID:n TransponderDescription:s TelemetryType:n Value0:n
```

TelemetryType and Value0 retain their mapped EAI code/value representation.
Interpret units and channels using the applicable EAI type definition and
mapping rule.

## Acceptance still required

Target-machine installation, unattended startup, HTTPS, representative load,
backup/recovery on target, upstream history coverage and active sensor/coupling
cases remain separate acceptance items. In particular, unknown Engine is not
equivalent to OFF, and unknown Plugged is not identical to an empty old value.
Compare the same vehicle and event before judging dynamic differences.
