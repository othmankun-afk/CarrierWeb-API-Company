# CarrierWeb API — Windows test package

This package includes the API, EAI SDK, PHP, Node.js and MySQL runtimes,
installation tools and test guides. XAMPP is not required.
It is a Windows x64 package, not a native macOS installer.
Start with the [company test guide](Documentation/COMPANY_TEST.md).

## First test

1. Extract the entire ZIP to a local folder. Keep all folders together.
2. Open `Tests` and run `01-Run-acceptance.cmd` for a test with fictitious
   records. It does not contact EAI or use an existing customer database.
3. Read the result and keep the report. A `PARTIAL` result means that some
   checks still need attention; the report identifies them.

For a real account, follow [Installation](Documentation/INSTALLATION.md)
and [Client onboarding](Documentation/CLIENT_ONBOARDING.md). Coordinate EAI
access before adding the account so that two collectors do not use it together.

## Installation and operation

Use the files in the `Installation` folder:

| File | Purpose |
| --- | --- |
| `01-Install.cmd` | Prepare a new empty local installation. |
| `02-Start.cmd` | Start the supervisor and local services. |
| `03-Status.cmd` | Check services, synchronization health and publication time. |
| `04-Add-client.cmd` | Register an EAI account, or resume its interrupted registration. |
| `05-Test.cmd` | Check the five local API endpoints. |
| `06-Stop.cmd` | Stop the local installation before maintenance. |
| `07-Recover-sync.cmd` | Review an interruption, replay its received page locally, or authorize one EAI attempt. |
| `08-Update-existing.cmd` | Update a stopped installation while preserving its data and access. |
| `09-Client-access.cmd` | Choose a client and open its private access-file folder. Works while the API runs. |

Install once, add a client while stopped, then Start and check Status.
If registration fails, keep the diagnostic. Once the EAI account has been quiet
for a minute, run Add client with the same username and choose `RESUME` when
offered. It keeps the existing staged client and database; it does not create
a duplicate or retry automatically. See the onboarding guide for review cases.

Local acceptance, backup/restore and a manual Windows restart passed on the
test workstation. See [Test results](Documentation/TEST_RESULTS.md) for the checks and scope.
The company still needs to test the package on its own machine.

To update an existing instance, stop it and run
`08-Update-existing.cmd` from the extracted package. Confirm `UPDATE`,
then follow the recovery guide if needed, Start and check Status. Use Add client
only to register a new client. Do not reinstall or overwrite a state folder.

Client registration creates a private access sheet with the API username,
password and URLs. Its location is printed at the end of registration.
To find it later, open `Installation/09-Client-access.cmd`, choose the client
number and open the selected `ACCES-CLIENT-PRIVE.json` in Notepad. Its `urls`
section contains the five API links. No Stop, reinstall or new registration
is needed to find the file. Only clients registered in this installation
are listed; acceptance-test URLs are separate and must not be used.
See [Client onboarding](Documentation/CLIENT_ONBOARDING.md#find-the-access-file)
for the exact storage layout and the existing-installation procedure.
Wait at least 60 seconds after the registration checks before testing the
same endpoints again.

Long extraction paths are handled automatically through a short managed
location. Continue using the launchers in the extracted folder. The operator
windows do not need to stay open after an action completes.

Start, Status, Stop and the client-input prompts use the bundled Node.js
runtime. Start waits for the local services to be ready; Stop confirms their
ports are closed. Windows DPAPI and folder-permission helpers still require
PowerShell. No execution-policy change is made by these launchers.

Windows x64, PowerShell and the supplied runtimes' Microsoft C/C++ prerequisites
are required. Prerequisite installers are in `Runtime/Prerequis`; installation
of those system components is separate from the API setup.

## API and guides

The five endpoints are `vehicles`, `vehiclesBase`, `positions`, `canbus` and
`telemetry`. Requests read local publications; they do not contact EAI.
API access is limited to one request per client and endpoint every 60 seconds.

- [API contract](Documentation/API_CONTRACT.md): fields, formats, dates and errors.
- [Technical walkthrough](Documentation/TECHNICAL_WALKTHROUGH.md): collection and publication.
- [Recovery guide](Documentation/OUTAGE_RECOVERY.md): stale data, recovery and updates.
- [Test results](Documentation/TEST_RESULTS.md): completed checks and remaining acceptance.
- [Capacity policy](Documentation/CAPACITY_POLICY.md): storage limits and sizing.

The local acceptance test passed 74 checks, including background start,
repeated Start without duplication, Status and confirmed shutdown. The manual
Windows restart passed 59 checks with the same user: no reinstall, existing
access retained and another client added successfully. These tests used
fictitious clients and made no real EAI calls. They do not validate automatic
startup or uninterrupted collection.

On the company's machine, check installation, Stop/Start and advancing real
publications. Automatic startup, server HTTPS, load and storage still need
testing. Remaining sensor and history questions are listed in the contract
review.

During an EAI interruption, the last valid publication can remain available
with `STALE` headers. These responses are not live data. Check Status and the
recovery guide before restarting collection.

The collector handles repeated equivalent
measurements without stopping the whole account. Ambiguous sensor values stay
unknown and raw history is retained. `REPLAY LOCAL` in the recovery launcher
can publish an interrupted received page without an EAI call; its original
capture time is kept. A transport failure without a valid received page still
requires a reviewed attempt and a quiet account.

The collector follows the SDK's monotonic cursor rule. To recover from
`FAULT_SUITE_CURSOR_REGRESSED` after an update, use `07-Recover-sync.cmd`,
select the affected tenant and type `REPLAY LOCAL`. Only the received page
matching the persisted failure is reviewed. Saved high-water IDs, raw history
and original capture time are preserved; no EAI call is made by this mode.
Use `REPLAY LOCAL` when a matching received page is available.

Historical empty text is formatted as one space at the read boundary, as in
the SDK. Existing stored payloads and hashes are left intact. Remaining
legacy differences and the historical-storage assessment are described in
[Contract review](Documentation/CONTRACT_REVIEW.md).

Registration and recovery support existing and newly added accounts. On another
Windows PC, install the complete package and register the EAI account there. Do not
copy an initialized instance or its DPAPI secrets from another computer, and
do not run two collectors against the same EAI account.

## Package contents

`Code` contains the application; `Runtime` contains its dependencies.
`Database` and `Configuration-exemple` contain schemas and examples.
`Documentation` and `Tests` contain guides, checks and test summaries.
`Licences` retains the third-party notices and required source material.

The distribution contains no client credentials or initialized database.
Keep the original ZIP for sharing. A folder used for installation or client
registration contains private state and must not be redistributed.
