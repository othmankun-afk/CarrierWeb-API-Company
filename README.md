# CarrierWeb API

A PHP API for current vehicle data, positions, CANbus and telemetry collected
through the CarrierWeb EAI SDK. Each client receives separate API credentials
and five URLs for its authorized fleet.

**Status:** Windows company test build. Local acceptance, backup/restore,
manual Windows restart and adding further clients passed on the test PC.
The company still needs to test it on its own machine. Differences from the
existing API are listed in the contract review.

**For company testing:** [Download the Windows package](https://github.com/othmankun-afk/CarrierWeb-API-Company/releases).
Choose `CarrierWeb-API-Windows.zip` under **Assets**, extract it fully
and start with the [company test guide](Documentation/COMPANY_TEST.md).
PHP, Node.js, MySQL and the EAI SDK are bundled; XAMPP is not required.
Repository access is required to download the private release. GitHub's
generated source archives are not the installation package.
This installation package supports Windows x64 only. A native macOS installation
has not been implemented or tested; a Mac can consume an API hosted on a reachable server.

## Contents

- [Project overview](#project-overview)
- [Architecture](#architecture)
- [Project structure](#project-structure)
- [Requirements and downloads](#requirements-and-downloads)
- [Installation and first test](#installation-and-first-test)
- [Add a client](#add-a-client)
- [Endpoints](#endpoints)
- [Example requests](#example-requests)
- [Example responses](#example-responses)
- [Field reference](#field-reference)
- [Dates, limits and errors](#dates-limits-and-errors)
- [Synchronization and recovery](#synchronization-and-recovery)
- [Tests and verification](#tests-and-verification)
- [Known limitations and company deployment](#known-limitations-and-company-deployment)
- [Documentation and support](#documentation-and-support)

## Project overview

The application separates EAI collection from customer API requests. A
collector retrieves data using the authorized EAI account, validates the
returned records and publishes them to a separate local MySQL instance.
The PHP API reads that publication and returns the documented JSON format.

The implemented features are:

- Five GET endpoints with fixed field names and JSON types.
- Per-client schemas and separate reader/publisher database credentials.
- Generic client registration and generated API usernames, passwords and URLs.
- Periodic EAI collection, consistent publication and retained local history.
- A minimum 60-second API interval per client and endpoint.
- Windows installation, start, status, test, stop, update and recovery launchers.
- Freshness headers when the last valid publication is served during an outage.

There is no administration website. Client registration is an operator task
on the machine that owns the installation. Customers use the API URLs only;
they do not receive EAI credentials or database access.

## Architecture

```text
EAI account and authorized fleet
             |
     Official EAI SDK
             |
  Collector: reserve -> retrieve -> validate
             |
  MySQL publication, history and cursors
       (isolated schema per client)
             |
      Read-only PHP API
             |
   Customer's five GET endpoints
```

The supervisor manages the local worker, HTTP and SQL processes. The worker
reserves EAI attempts before sending them and publishes a valid page in a
transaction. A failed page is not partly exposed to API readers.

An HTTP request does not contact EAI or fetch missing history. Refresh reads
the latest successful publication. Event timestamps still belong to the
vehicle or sensor event, not to the browser refresh. The application does
not query the internal CarrierWeb database directly.

## Project structure

```text
Code/
  public/                 HTTP entry points
  src/                    Contract, access checks, mapping and repositories
  service/                Collection, supervision and Windows operations
  tools/                  Setup, onboarding and maintenance tools
  tests/                  Offline and integration test sources
  database/               Application SQL schema
Configuration-exemple/    Example configuration, without real accounts
Database/                 Packaged database schema and notes
Documentation/            Contract, operator guides and test results
Installation/             Numbered Windows launchers
Tests/                    Acceptance runner and test summaries
Tools/                    Source verification and package assembly
Distribution/             Runtime-base inventory and package checksums
Licences/                 Retained component notices
SOURCE-MANIFEST.json       Source file inventory and SHA-256 checksums
```

Only `Code/public` belongs in a web server's public directory. Configuration,
SDK files, database files, captures, logs and private access sheets must not
be exposed through the web root.

## Requirements and downloads

### Source repository

This private repository contains source, documentation, SQL schemas and
tests. It does **not** contain the proprietary SDK runtime, PHP, Node.js,
MySQL, customer accounts or an initialized database.

Use the **Windows test package** linked above for installation. GitHub's
**Code > Download ZIP** and the automatically generated **Source code (zip)**
assets contain source only, without the runtimes needed by the installer.

### Complete Windows test package

The release linked above contains Node.js 24.14.1, PHP 8.2.12 with mysqli,
MySQL 5.7.44 and the EAI S.1366 SDK runtime with PHP 5.6.28. XAMPP is not
required. IT still needs to review support, maintenance and redistribution
of these components.

Maintainers assemble releases from the reviewed source and a hash-pinned,
uninitialized runtime base. That base is recorded in
[Distribution/portable-asset.json](Distribution/portable-asset.json); it is
not the download for testers. See the
[developer workflow](Documentation/DEVELOPMENT.md) for build instructions.

The test machine needs:

- Windows x64, Windows PowerShell and Windows DPAPI.
- The required Microsoft C/C++ runtime components. The full package supplies
  prerequisite installers under `Runtime/Prerequis`; they are not installed automatically.
- Permission to run the supplied scripts under company policy.
- Available loopback ports and enough local disk space for collected history.
- Authorized EAI access and network connectivity for a real-account trial.
  Fictitious acceptance does not require EAI credentials or calls.

Use the same Windows identity throughout setup and operation. DPAPI secrets
cannot be migrated by copying an initialized folder to another user or PC.

## Installation and first test

These steps apply to the **complete Windows package**, not the source checkout.
For an existing installation, use the [update procedure](Documentation/OUTAGE_RECOVERY.md)
instead of reinstalling or adding the same client again.

1. **Extract the entire package** to a local folder. Keep its directories
   together and run files from the extracted folder, not the ZIP viewer.
   Long extraction paths are handled through a verified short managed location;
   continue using the launchers in the original extraction folder.
2. **Run `Tests/01-Run-acceptance.cmd`.** It uses fictitious EAI records with
   isolated local SQL, HTTP and Windows security checks. Keep the report and
   its actual `PASS`, `PARTIAL` or `FAIL` result. It makes no real EAI call.
3. **Run `Installation/01-Install.cmd` once.** It verifies the package, selects
   available ports, initializes a new empty private MySQL instance and stops
   it cleanly. Success prints `VERIFIED` and an instance path. No client is
   added and no EAI request is sent at this stage.
4. **Add an authorized client** using the procedure below while the instance
   is stopped. Wait for first publication and the generated access sheet.
5. **Run `Installation/02-Start.cmd`, then `Installation/03-Status.cmd`.**
   Start waits for the supervisor, worker and local services to be ready.
   This confirms process startup, not healthy EAI synchronization.
   Check a fresh Status result and the last successful publication time.
6. **Wait at least 60 seconds after onboarding's HTTP checks or another API
   request**, then run `Installation/05-Test.cmd`. It tests the five local
   routes and prints a private report path. Do not run a browser refresh at
   the same time as the smoke test.
7. **Stop with `Installation/06-Stop.cmd`** when finished. Confirm a fresh
   `STOPPED` status and closed owned ports before maintenance.

A healthy manually started instance should show values such as:

```text
State: RUNNING
Local HTTP: True; Local MySQL: True
<tenant>: PUBLISHED; generation <number>
Supervisor: RUNNING
```

Also inspect data health, publication age and any attention message.
`RUNNING` alone does not mean the collector is producing new data.

### Operator launchers

| File under `Installation/` | Purpose |
| --- | --- |
| `01-Install.cmd` | Prepare a new empty local instance. |
| `02-Start.cmd` | Start background operation and confirm service readiness. |
| `03-Status.cmd` | Inspect processes, ports, publication age and synchronization health. |
| `04-Add-client.cmd` | Register an EAI account and issue separate API access. |
| `05-Test.cmd` | Test the five local routes, respecting their quotas. |
| `06-Stop.cmd` | Stop the owned local instance before maintenance. |
| `07-Recover-sync.cmd` | Review an interrupted synchronization; one attempt requires explicit approval. |
| `08-Update-existing.cmd` | Update a stopped existing instance while preserving its data and access. |
| `09-Client-access.cmd` | Choose a registered client and open the folder with its private access file selected. No Stop is needed. |

No permanent terminal window is needed for the manual background test mode.
Start, Status, Stop and the onboarding prompts run through the bundled Node.js
runtime, without launching an unsigned PowerShell control script. Windows
DPAPI and permission helpers remain Windows prerequisites. No execution-policy
change is part of installation or startup.
Unattended startup after reboot or logoff still needs target-machine acceptance.
See the [installation guide](Documentation/INSTALLATION.md) for the full procedure.

## Add a client

1. Stop the instance and verify that its owned ports are closed.
2. Coordinate the EAI account with its owner. Stop competing collectors and
   confirm there has been no EAI call for that account for at least one minute.
3. Open `Installation/04-Add-client.cmd`. Confirm the quiet account only when
   it is true, then enter the client label, EAI username and masked EAI password.
4. Allow the single initial EAI collection and local publication checks to
   finish. Do not run another collector or repeatedly retry an interrupted attempt.
5. Keep the generated private access sheet, then Start and check Status.

Registration validates the connection and returned account scope, creates an
isolated schema and generates a separate API username and random password.
The API password is different from the EAI password. The client becomes active
only after the first publication and five local HTTP checks succeed.

The output identifies the private `ACCES-CLIENT-PRIVE.json` access sheet. It
contains the API username, password, five URLs and first permitted request time.
Only the API access should be handed to the customer; never share EAI or SQL
credentials, DPAPI files or the initialized installation folder.

### Find a client's API URLs

1. In the extracted package, open `Installation/09-Client-access.cmd`.
2. Choose the client number. Windows Explorer opens its existing private
   folder with `ACCES-CLIENT-PRIVE.json` selected.
3. Open that file in a text editor, such as Notepad. The `urls` section
   contains the five links for that client. Use them on the API host after
   Start and a healthy Status, respecting the 60-second request interval.

You can run Client access while the API is running. It reads the active
client registry only; it does not collect data, recreate credentials or
search the acceptance-test folders. A new, unlinked extraction cannot find
an earlier instance: use its original launchers or follow the existing-instance
update procedure first. Do not reinstall or register the same account again
just to find a URL.

The file stays at
`<installation-directory>\client-onboarding\<registration-id>\ACCES-CLIENT-PRIVE.json`.
The exact path is also printed as `accessSheetPath` after Add client. See
[the access-file locations](Documentation/CLIENT_ONBOARDING.md#find-the-access-file)
for the short-path and managed layouts. Do not use URLs from `Essais-locaux`:
those belong to isolated fictitious tests, whose services stop after the run.

The fleet follows the EAI account's authorized vehicles. A newly authorized
vehicle appears after a successful publication without editing vehicle JSON
or application code. A different fleet restriction in the old API is not
automatically reproduced. See [client onboarding](Documentation/CLIENT_ONBOARDING.md).

If an initial registration fails, open Add client again with the same EAI
username. The tool offers an explicit `RESUME` for an intact unfinished
registration. It keeps that client's identity and database, reuses a saved
valid response where possible, and checks the quiet minute before any new
EAI read. It does not retry in the background or create a duplicate client.
A partially committed registry or damaged local files still need review.

## Endpoints

All routes are case-sensitive GET requests. Authentication uses the API
username in the path and the generated `password` query parameter. Basic
and Bearer authentication are not supported alternatives.

```text
{base}/cweai/{username}/{endpoint}?password={password}
```

`base` includes the installation's scheme, host and port. Use the access sheet's
actual URL: a sample port in this README is not the port assigned at installation.

| Endpoint | Result | Required query parameters | Fields per object |
| --- | --- | --- | ---: |
| `vehicles` | Full current vehicle state and available signals | `password` | 44 |
| `vehiclesBase` | Reduced current vehicle state | `password` | 14 |
| `positions` | Collected position history for one vehicle | `password`, `vehicleid`, `ymdfrom`, `ymdto` | 14 |
| `canbus` | Collected CANbus records for one vehicle | `password`, `vehicleid`, `ymdfrom`, `ymdto` | 33 |
| `telemetry` | Collected telemetry records for one vehicle | `password`, `vehicleid`, `ymdfrom`, `ymdto` | 7 |

Successful data responses are JSON arrays. There is no sixth public wired
telemetry endpoint; supported wired signals can enrich current vehicle fields.
Match vehicles by `CWVehicleID`, not their list position or a non-unique name.

## Example requests

Everything in these examples is fictitious: account, password, port, vehicle
ID, dates, codes and values. Replace them with the authorized access sheet and
a vehicle returned by that account. These are request examples, not live demo URLs.

```text
GET http://127.0.0.1:8080/cweai/example-client/vehicles?password=EXAMPLE_ONLY
GET http://127.0.0.1:8080/cweai/example-client/vehiclesBase?password=EXAMPLE_ONLY
GET http://127.0.0.1:8080/cweai/example-client/positions?password=EXAMPLE_ONLY&vehicleid=7&ymdfrom=2026-10-20%2000:00:00&ymdto=2026-10-20%2023:59:59
GET http://127.0.0.1:8080/cweai/example-client/canbus?password=EXAMPLE_ONLY&vehicleid=7&ymdfrom=2026-10-20%2000:00:00&ymdto=2026-10-20%2023:59:59
GET http://127.0.0.1:8080/cweai/example-client/telemetry?password=EXAMPLE_ONLY&vehicleid=7&ymdfrom=2026-10-20%2000:00:00&ymdto=2026-10-20%2023:59:59
```

To inspect headers as well as the body with `curl.exe` on Windows:

```powershell
curl.exe -i "http://127.0.0.1:8080/cweai/example-client/vehicles?password=EXAMPLE_ONLY"
```

Respect the minute rule before another request to the same endpoint, including
one made by the smoke test. A saved URL retains its `ymdto` value; update it
when extending the query window. Credentials in query strings must be excluded from shared
screenshots, tickets and access logs.

## Example responses

Each example below contains one complete object for its endpoint. Values are
illustrative only, not captured customer data or proof of a physical unit.
History IDs are quoted strings so large IDs are not rounded by JSON clients.

### vehicles response

```json
[
  {
    "VehicleNumber": "DEMO-001",
    "CWVehicleID": 7,
    "VehicleType": 1,
    "VehicleDescription": "FICTITIOUS DEMO TRUCK",
    "License": "EXAMPLE-001",
    "Owner": "EXAMPLE",
    "SubcontractorCode": "EXAMPLE",
    "DivisionID": 0,
    "DivisionDesc": "-",
    "LastReportTime": "2026-10-20 10:01:00",
    "LastLocationTime": "2026-10-20 10:00:00",
    "Latitude": 12.34567,
    "Longitude": -1.23456,
    "Country": "ZZ",
    "Location": "FICTITIOUS DEPOT",
    "Zipcode": "00000",
    "CurrentSpeed": 0,
    "GPSOdometer": 123450,
    "TachoOdometer": 0,
    "LandmarkID": 0,
    "LandmarkDesc": " ",
    "CWDriver0ID": 0,
    "Driver0Name": " ",
    "Driver0Number": "",
    "CWDriver1ID": 0,
    "Driver1Name": " ",
    "Driver1Number": "",
    "HOSActivityId": 0,
    "HOSActivityTime": "1999-11-30 00:00:00",
    "LastCanbusTime": "1999-11-30 00:00:00",
    "CWTrailerID": 0,
    "Plugged": "-",
    "PluggedType": " ",
    "FuelLevel": 0,
    "FuelLevelTime": "1999-11-30 00:00:00",
    "VIN": "",
    "Fuel": 0,
    "Ignition": 0,
    "Door": "-",
    "Temperature": -1000,
    "Engine": "-",
    "Battery": -1000,
    "EngineRunningTime": 0,
    "EngineRunningTimeHHMM": ""
  }
]
```

### vehiclesBase response

```json
[
  {
    "CWVehicleID": 7,
    "VehicleType": 1,
    "VehicleDescription": "FICTITIOUS DEMO TRUCK",
    "License": "EXAMPLE-001",
    "LastLocationTime": "2026-10-20 10:00:00",
    "LastReportTime": "2026-10-20 10:01:00",
    "Latitude": 12.34567,
    "Longitude": -1.23456,
    "Country": "ZZ",
    "Location": "FICTITIOUS DEPOT",
    "Zipcode": "00000",
    "CurrentSpeed": 0,
    "GPSOdometer": 123450,
    "Ignition": 0
  }
]
```

### positions response

```json
[
  {
    "ID": "90071992547409931",
    "CWVehicleID": 7,
    "MeasuredTime": "2026-10-20 10:00:00",
    "GpsValid": 1,
    "Latitude": 12.34567,
    "Longitude": -1.23456,
    "Country": "ZZ",
    "Location": "FICTITIOUS DEPOT",
    "Zipcode": "00000",
    "Ignition": 0,
    "CurrentSpeed": 0,
    "GPSOdometer": 123450,
    "PositionType": 0,
    "LandmarkID": 0
  }
]
```

### canbus response

```json
[
  {
    "ID": "90071992547409932",
    "CWVehicleID": 7,
    "MeasuredTime": "20.10.2026 10:00:00",
    "CWDriverID": 0,
    "DriverNumber": " ",
    "DriverName": " ",
    "Odometer": 123450,
    "Fuel": 0,
    "CO2": 0,
    "FuelLevel": 0,
    "HighRPM": 0,
    "HighTorque": 0,
    "CruiseControl": 0,
    "Accelerations": 0,
    "OverSpeed": 0,
    "BrakeApplications": 0,
    "HardBrakeApplications": 0,
    "EngineRollout": 0,
    "EngineRunningTime": 0,
    "IdleTime": 0,
    "IdleFuel": 0,
    "IdleCO2": 0,
    "PTOTime": 0,
    "PTOFuel": 0,
    "PTOCO2": 0,
    "GreenZoneRPMFuel": 0,
    "GreenZoneRPMDistance": 0,
    "GreenZoneRPMTime": 0,
    "RetarderDistance": 0,
    "RetarderTime": 0,
    "CatalystLevel": 0,
    "LongIdleTime": 0,
    "UnprocessedOdometer": 0
  }
]
```

### telemetry response

```json
[
  {
    "ID": "90071992547409930",
    "CWVehicleID": 7,
    "MeasuredTime": "2026-10-20 10:00:00",
    "TransponderID": 501,
    "TransponderDescription": "FICTITIOUS SENSOR",
    "TelemetryType": 153,
    "Value0": 1920
  }
]
```

For an HTTP 200 response backed by a valid publication, inspect these headers:

| Header | Meaning |
| --- | --- |
| `X-Data-Freshness` | `FRESH` or `STALE` publication. |
| `X-Data-Captured-At` | Original UTC capture time, not the last browser refresh. |
| `X-Data-Age-Seconds` | Publication age in seconds. |
| `X-Collection-State` | Published, in progress or review required. |

These describe the publication, not the age of every sensor or the completeness
of the requested history.

## Field reference

Field names and JSON types are fixed. `number` means a JSON number, not a quoted
numeric string, boolean or null. History `ID` is a decimal string of 1–40 digits.
Vehicle IDs are positive integers up to `2147483647`, without leading zeros
in request parameters. All numeric output values must be finite and have an
absolute value no greater than `9007199254740991`.

The tables describe the implemented representation. Do not infer universal
speed, distance, fuel, counter or sensor units from a field name alone. Latitude
and longitude are divided by `100000` from the source integer representation;
other values follow their mapping rules. Unresolved source semantics are
documented in the [API contract](Documentation/API_CONTRACT.md).

### vehicles fields

<details>
<summary>44 fields — full current state</summary>

| Field | JSON type | Meaning or representation |
| --- | --- | --- |
| `VehicleNumber` | string | Source vehicle label; not necessarily unique. |
| `CWVehicleID` | number | Vehicle identifier used for matching and history requests. |
| `VehicleType` | number | Source vehicle-type code. |
| `VehicleDescription` | string | Source vehicle description. |
| `License` | string | License text returned by EAI. |
| `Owner` | string | Source owner text. |
| `SubcontractorCode` | string | Source subcontractor code. |
| `DivisionID` | number | Source division identifier. |
| `DivisionDesc` | string | Joined EAI division description; `"-"` for division ID 0. |
| `LastReportTime` | string | UTC source report timestamp. |
| `LastLocationTime` | string | UTC source location timestamp. |
| `Latitude` | number | Latitude after the defined source scaling. |
| `Longitude` | number | Longitude after the defined source scaling. |
| `Country` | string | Source country code. |
| `Location` | string | EAI LastLocation text; geocoding labels may differ from the old API. |
| `Zipcode` | string | Source postal-code text. |
| `CurrentSpeed` | number | Mapped EAI Speed value. |
| `GPSOdometer` | number | Mapped GPS odometer value. |
| `TachoOdometer` | number | Mapped tachograph odometer value. |
| `LandmarkID` | number | Source landmark identifier. |
| `LandmarkDesc` | string | Source landmark description. |
| `CWDriver0ID` | number | First source driver identifier. |
| `Driver0Name` | string | First source driver name. |
| `Driver0Number` | string | First source driver number. |
| `CWDriver1ID` | number | Second source driver identifier. |
| `Driver1Name` | string | Second source driver name. |
| `Driver1Number` | string | Second source driver number. |
| `HOSActivityId` | number | Source hours-of-service activity code. |
| `HOSActivityTime` | string | Represented source activity timestamp. |
| `LastCanbusTime` | string | Represented source CANbus timestamp. |
| `CWTrailerID` | number | Source trailer identifier. |
| `Plugged` | string | Supported coupling signal or unknown `"-"`. |
| `PluggedType` | string | Supported coupling type or unknown `" "` (one space). |
| `FuelLevel` | number | Mapped source fuel-level value; not implicitly a percentage. |
| `FuelLevelTime` | string | Represented source fuel-level timestamp. |
| `VIN` | string | Source VIN text. |
| `Fuel` | number | Mapped source fuel value; no inferred unit conversion. |
| `Ignition` | number | Source ignition state/code; not an alias for Engine. |
| `Door` | string | Supported door signal or unknown `"-"`. |
| `Temperature` | number | Supported mapped temperature signal or unknown `-1000`. |
| `Engine` | string | Supported RPM-based state or unknown `"-"`; not guessed from battery voltage. |
| `Battery` | number | Selected mapped raw signal or unknown `-1000`; not labelled volts. |
| `EngineRunningTime` | number | Approved vehicle compatibility constant `0`, not measured runtime. |
| `EngineRunningTimeHHMM` | string | Approved vehicle compatibility constant `""`, not a measured duration. |

</details>

### vehiclesBase fields

<details>
<summary>14 fields — reduced current state</summary>

| Field | JSON type | Meaning or representation |
| --- | --- | --- |
| `CWVehicleID` | number | Vehicle identifier. |
| `VehicleType` | number | Source vehicle-type code. |
| `VehicleDescription` | string | Source vehicle description. |
| `License` | string | Source License text. |
| `LastLocationTime` | string | UTC source location timestamp. |
| `LastReportTime` | string | UTC source report timestamp. |
| `Latitude` | number | Latitude after source scaling. |
| `Longitude` | number | Longitude after source scaling. |
| `Country` | string | Source country code. |
| `Location` | string | EAI LastLocation text. |
| `Zipcode` | string | Source postal-code text. |
| `CurrentSpeed` | number | Mapped EAI Speed value. |
| `GPSOdometer` | number | Mapped GPS odometer value. |
| `Ignition` | number | Source ignition state/code. |

</details>

### positions fields

<details>
<summary>14 fields — position history</summary>

| Field | JSON type | Meaning or representation |
| --- | --- | --- |
| `ID` | string | Decimal source event ID, kept as text. |
| `CWVehicleID` | number | Authorized vehicle identifier. |
| `MeasuredTime` | string | UTC event time in `YYYY-MM-DD HH:MM:SS`. |
| `GpsValid` | number | Source GPS-validity code. |
| `Latitude` | number | Latitude after source scaling. |
| `Longitude` | number | Longitude after source scaling. |
| `Country` | string | Source country code. |
| `Location` | string | Source location text for the event. |
| `Zipcode` | string | Source postal-code text. |
| `Ignition` | number | Source ignition state/code. |
| `CurrentSpeed` | number | Mapped speed value. |
| `GPSOdometer` | number | Mapped GPS odometer value. |
| `PositionType` | number | Source position-type code. |
| `LandmarkID` | number | Source landmark identifier. |

</details>

### canbus fields

<details>
<summary>33 fields — CANbus history</summary>

CANbus values retain their implemented EAI representation. The names below
are not a universal physical-unit dictionary; in particular, do not assume
that every duration is in hours or every fuel value is in litres.

| Field | JSON type | Meaning or representation |
| --- | --- | --- |
| `ID` | string | Decimal source event ID, kept as text. |
| `CWVehicleID` | number | Authorized vehicle identifier. |
| `MeasuredTime` | string | UTC event time in `DD.MM.YYYY H:MM:SS`. |
| `CWDriverID` | number | Source driver identifier. |
| `DriverNumber` | string | Source driver number. |
| `DriverName` | string | Source driver name. |
| `Odometer` | number | Mapped CANbus odometer value. |
| `Fuel` | number | Mapped event fuel value. |
| `CO2` | number | Mapped event CO2 value, with SDK-compatible rounding. |
| `FuelLevel` | number | Mapped fuel-level value. |
| `HighRPM` | number | Source high-RPM metric. |
| `HighTorque` | number | Source high-torque metric. |
| `CruiseControl` | number | Source cruise-control metric. |
| `Accelerations` | number | Source acceleration metric. |
| `OverSpeed` | number | Source overspeed metric. |
| `BrakeApplications` | number | Source brake-application metric. |
| `HardBrakeApplications` | number | Source hard-braking metric. |
| `EngineRollout` | number | Source engine-rollout metric. |
| `EngineRunningTime` | number | Mapped event runtime value, separate from the vehicle compatibility constant. |
| `IdleTime` | number | Source idling-time value. |
| `IdleFuel` | number | Source idling-fuel value. |
| `IdleCO2` | number | Source idling CO2 value, with SDK-compatible rounding. |
| `PTOTime` | number | Source power-take-off time value. |
| `PTOFuel` | number | Source power-take-off fuel value. |
| `PTOCO2` | number | Source power-take-off CO2 value, with SDK-compatible rounding. |
| `GreenZoneRPMFuel` | number | Source green-zone RPM fuel metric. |
| `GreenZoneRPMDistance` | number | Source green-zone RPM distance metric. |
| `GreenZoneRPMTime` | number | Source green-zone RPM time metric. |
| `RetarderDistance` | number | Source retarder-distance value. |
| `RetarderTime` | number | Source retarder-time value. |
| `CatalystLevel` | number | Source catalyst-level value. |
| `LongIdleTime` | number | Source long-idling time value. |
| `UnprocessedOdometer` | number | Source unprocessed odometer value. |

</details>

### telemetry fields

<details>
<summary>7 fields — telemetry history</summary>

| Field | JSON type | Meaning or representation |
| --- | --- | --- |
| `ID` | string | Decimal source event ID, kept as text. |
| `CWVehicleID` | number | Authorized vehicle identifier. |
| `MeasuredTime` | string | UTC event time in `YYYY-MM-DD HH:MM:SS`. |
| `TransponderID` | number | Source transponder identifier. |
| `TransponderDescription` | string | Source transponder description. |
| `TelemetryType` | number | EAI telemetry-type code; interpret using its supported type definition. |
| `Value0` | number | Mapped source channel value; its unit depends on the applicable type. |

</details>

### Unknown and compatibility values

| Fields in `vehicles` | Missing, unsupported or ambiguous value |
| --- | --- |
| `Door`, `Engine`, `Plugged` | `"-"` |
| `PluggedType` | `" "` — one space |
| `Temperature`, `Battery` | `-1000` |
| `EngineRunningTime`, `EngineRunningTimeHHMM` | Fixed compatibility values: `0` and `""`. They are not calculated engine hours. |

Unknown is not OFF, zero fuel, a temperature measurement or a battery reading.
Supported signals use source/channel/time selection; ambiguous channels are
not resolved by choosing a convenient value. The generic profile does not
apply a fixed sensor-expiry period. Preserve source spaces and sentinel
values when reading JSON.

## Dates, limits and errors

### Dates and local history

`ymdfrom` and `ymdto` accept UTC values in these formats:

```text
YYYY-MM-DD
YYYY-MM-DD HH:MM
YYYY-MM-DD HH:MM:SS
```

Both bounds are inclusive. A date without a time means **midnight**, including
the end date. To request one complete day, explicitly use `00:00:00` through
`23:59:59`, with spaces encoded as `%20`. ISO `T`/`Z` timestamps and timezone
suffixes are not accepted input formats.

History is ordered by measurement time, then event ID. Only collected local
records are queried. `[]` means no matching local rows; it does not prove
that the source has no older events or that collection coverage is complete.
There is no request-time backfill or proved oldest available source date.

### Limits

| Control | Current behavior |
| --- | --- |
| API minute rule | At least 60 seconds per account and endpoint, shared across vehicle IDs, dates and applicable diagnostics. |
| EAI minute rule | Separate persisted minimum of 60 seconds between dispatches for the account. |
| Response rows | Up to 10,000 per response in the local profile. |
| Response size | Up to 16 MiB in the local profile. |
| Pagination | No automatic HTTP pagination or silent truncation; split large history intervals and respect the minute rule. |
| Stored history guard | Default 100,000 combined historical events per tenant; a protective bound, not a retention period or proven server capacity. |

There is no automatic purge to make room. Capacity changes require measured
evidence and approval; see [capacity policy](Documentation/CAPACITY_POLICY.md).

### Public responses

Normal data responses use HTTP 200 with a JSON array and
`Content-Type: application/json; charset=utf-8`. The public error behavior
preserves the existing wire contract rather than exposing internal JSON errors.

| Condition | Public response |
| --- | --- |
| Invalid access, unavailable publication, oversized result, most other failures | Empty HTTP 503. |
| Minute limit on endpoints other than `positions` | Empty HTTP 503; inspect `Retry-After` when supplied. |
| Recognized missing `positions` parameters | HTTP 200 with historical documentation/text. |
| Recognized empty positions start | HTTP 200 with `[]`. |
| Recognized invalid positions start | HTTP 200 with text beginning `ERR:` and ending `[]`. |
| Positions minute limit | HTTP 200 with `API ERROR: getPositionsHistory function limit request per 1 minute reached[]`. |

Check both the HTTP status and the body. A legacy HTTP 200 can contain text,
not JSON. Do not assume a 503 means only a network failure or only a rate limit.
The complete recognized cases are in the [API contract](Documentation/API_CONTRACT.md).

### Troubleshooting

| Observation | What to check |
| --- | --- |
| `INCOMPLETE PACKAGE` | Download the complete Windows package from Releases and extract all folders. The source ZIP does not include the runtimes. |
| Connection refused | Check Start, fresh Status, owned HTTP/SQL ports and the exact generated URL. |
| HTTP 503 | Check the minute reservation, private Status/diagnostics, publication validity and capacity guards. Avoid repeated refreshes. |
| HTTP 200 with `STALE` | The last valid publication is readable, but it is not fresh. Review collector health and the last successful capture time. |
| Empty history `[]` | Check the authorized vehicle and explicit UTC interval against locally collected events. |
| Different first vehicles from the old API | Compare the same `CWVehicleID`; fleet scope and ordering can differ. |
| Old vehicle or sensor timestamps | Check source event times separately from publication freshness. A successful poll does not invent a new event. |
| Windows refuses execution | Keep the exact message and file name for IT review. Use the current complete release, not an older control script. |

## Synchronization and recovery

Partitioned EAI cursors retain their highest saved ID, following the official
SDK's high-water rule. Smaller metadata does not move a cursor backwards.
The store still rejects lost partitions and unreviewed regressions. For an
existing `FAULT_SUITE_CURSOR_REGRESSED`, install this build while stopped,
then use `07-Recover-sync.cmd` and `REPLAY LOCAL` for that tenant. This reviews
the matching received page without another EAI call or history deletion.

Historical EAI responses also preserve the SDK's one-space representation of
empty text. This is applied when reading; stored events, identifiers and hashes
are not rewritten. Fleet scope, geofence enrichment and telemetry coverage
still need the confirmations listed in [Contract review](Documentation/CONTRACT_REVIEW.md).

Repeated identical measurements do not stop collection. Ambiguous sensor
values remain unknown, and each raw history event is stored separately.
The [technical walkthrough](Documentation/TECHNICAL_WALKTHROUGH.md) explains
how equivalent measurements are handled.

If a complete page was received but failed local processing,
`07-Recover-sync.cmd` also supports `REPLAY LOCAL`. It reprocesses that page
without contacting EAI, verifies the interrupted attempt and preserves the
original capture time. A stale page stays stale. An already published page
from an earlier attempt is refused. See the recovery guide below.

New accounts and explicitly confirmed updates use `last-known-with-warning`. During a
temporary EAI outage, an intact previous publication can remain readable with
explicit `STALE` headers. Old configurations without that policy remain
fail-closed. Missing, corrupt, future-dated or unauthorized data is still refused.

A reserved or ambiguous EAI attempt is not automatically replayed. Stop the
instance, confirm its owned ports are closed and verify that no competing
collector or EAI request has used the account for at least one minute. Use
`07-Recover-sync.cmd` for review. It makes no EAI call without explicit approval.
`RESUME ONE` followed by
`QUIET` authorizes one checked attempt; it does not reset history or cursors.
Start and confirm a new healthy publication after successful recovery.

Use `08-Update-existing.cmd` from the corrected full package for an existing
stopped installation. The explicit update preserves database contents,
credentials, cursors and API URLs. Do not add the account again to clear a fault.
See [outage recovery and update](Documentation/OUTAGE_RECOVERY.md).

Last-known mode requires the host, HTTP and MySQL processes to remain available.
During an outage the cached fleet reflects the last successful authorization
snapshot. Disable local API access if the account's access must be revoked.

## Tests and verification

### Source checkout

A developer with Node.js can verify the source inventory:

```powershell
node .\Tools\verify-source.mjs
```

This checks source hashes and packaging rules. It makes no EAI/API request and
opens no database. A successful source check is not an end-to-end installation test.

To assemble a fresh test package, a maintainer also needs the separately
hash-pinned runtime base with no initialized customer data:

```powershell
node .\Tools\assemble-test-package.mjs --base C:\CW-API-Base --target C:\CW-API-Review
```

The target must be a new directory outside the source and base. Assembly does
not start services or contact EAI. See [developer workflow](Documentation/DEVELOPMENT.md).

### Complete package

Run `Tests/01-Run-acceptance.cmd` from the full package. The report lists passed,
failed and incomplete checks and is saved with the test files. Coordinate
real EAI trials with the account owner.

The Windows package was tested on one Windows workstation with fictitious clients:

| Scenario | Result |
| --- | --- |
| Installation, client isolation, five routes and service controls | PASS — 74 acceptance checks. |
| Manual Windows restart | PASS — 59 checks after restart; no reinstall and existing access preserved. |
| Adding clients before and after restart | PASS — five routes for each new client, without changing existing access. |
| Backup and restore while stopped | PASS — 468 files restored byte for byte, including SQL data, configuration and encrypted access. Start/Stop also passed after restore. |
| PHP contract and mapping suites | PASS on PHP 8.2 and the SDK's PHP 5.6 runtime. |

These acceptance tests made no real EAI calls. The restart check used the same
Windows user, with fictitious accounts paused. It verifies manual restart and
retained access, not automatic startup or uninterrupted EAI collection.
See [test results](Documentation/TEST_RESULTS.md) for the individual checks
and the scenarios still to test on the company's machine.

## Known limitations and company deployment

The next step is to install the package on the company's test machine and
check that real publications keep advancing.
Automatic startup, logoff and recovery on that machine still need testing.

The bundled PHP server and `127.0.0.1` URLs are for local tests. Access from
other computers needs a server address, HTTPS and a production web server.
Create a new installation on the server with credentials protected for its
Windows account. The same-user restore test does not prove cross-machine
or cross-user recovery.

Storage, backup targets and expected load still need to be sized for the
company. Component support and redistribution also need checking before
deploying the bundled SDK and runtimes on a server.

Historical depth, some sensor units and differences from the old API are
listed in [Contract review](Documentation/CONTRACT_REVIEW.md). Compare the
same vehicle, measurement time and source signal. Fixed compatibility values
do not establish how the old provider calculated its fields.

## Documentation and support

| Guide | Contents |
| --- | --- |
| [Company test guide](Documentation/COMPANY_TEST.md) | Download, first test, further clients, restart and private URLs. |
| [API contract](Documentation/API_CONTRACT.md) | Exact routes, schemas, dates, limits and public wire behavior. |
| [Installation](Documentation/INSTALLATION.md) | Windows setup and first local test. |
| [Client onboarding](Documentation/CLIENT_ONBOARDING.md) | Registration, generated access and tenant isolation. |
| [Technical walkthrough](Documentation/TECHNICAL_WALKTHROUGH.md) | How collection, mapping, publication and HTTP serving fit together. |
| [Update and recovery](Documentation/OUTAGE_RECOVERY.md) | Last-known publications, interrupted synchronization and existing-instance updates. |
| [Test results](Documentation/TEST_RESULTS.md) | Recorded checks, real observations and open acceptance work. |
| [Capacity policy](Documentation/CAPACITY_POLICY.md) | Storage guards, sizing and quota changes. |
| [Developer workflow](Documentation/DEVELOPMENT.md) | Source verification and assembly from the pinned runtime base. |
| [Private release procedure](Documentation/GITHUB_HANDOVER.md) | Preparing and checking a source/package release. |
| [Third-party material](THIRD_PARTY.md) | SDK/runtime distribution boundaries and retained notices. |

