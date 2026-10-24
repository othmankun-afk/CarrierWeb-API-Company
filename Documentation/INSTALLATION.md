# Installation and first test

This procedure is for a Windows x64 test workstation. It creates a separate
local instance. It does not install into, reset or migrate an existing MySQL
server. No real client accounts are included.

## Before starting

Extract the complete ZIP to a local folder and keep all its directories together.
Run the launchers from the extracted folder, not from the ZIP viewer.

The launchers handle long extraction paths automatically. If the package root
exceeds 80 characters, Install first verifies the inventory and prepares an
unchanged runtime/code package under `%LOCALAPPDATA%\CWAPI\p-<package-id>`.
Install, Start, Status, Add client, Test and Stop then use that same managed
location. Keep using the buttons in the extracted folder; no manual move is
required. Short-path installations keep their existing layout.

Only files listed in the package inventory are prepared in this location.
Existing databases, accounts, captures and private state are never copied or
deleted. A different release
or extraction location gets a different managed identity; it does not silently
adopt an earlier installation. Keep that earlier instance stopped before
coordinating a new real-account trial.

IT may select a short local managed parent with `CW_API_INSTALL_PARENT` before
launching. The resulting runtime package root must remain at most 80 characters
for the legacy components. Network shares, modified package files and incomplete
managed copies are refused. Retain failed copies for review; do not delete them
or retry an uncertain EAI attempt.

The package includes Node.js 24.14.1, PHP 8.2.12 with mysqli, MySQL 5.7.44,
and the pinned EAI S.1366 runtime with PHP 5.6.28. It does not search for XAMPP,
an installed PHP configuration or another MySQL installation.

Windows PowerShell, DPAPI and the Microsoft C/C++ runtime components are still
system prerequisites. Signed installers are supplied in `Runtime/Prerequis`.
If a runtime component is missing, ask IT to review and install the appropriate
prerequisite. These installers are not run automatically. Start, Status, Stop
and the onboarding prompts use the bundled Node.js runtime; they do not execute
the old unsigned PowerShell control scripts. DPAPI and folder-permission
helpers still use Windows PowerShell. Company application restrictions still
apply; retain any refusal message for IT review.

Use the same Windows account throughout installation and operation. Secrets
are encrypted with DPAPI CurrentUser. Copying an initialized installation to
another user or computer is not a supported way to move its credentials.

## 1. Run the isolated acceptance test

Double-click `Tests/01-Run-acceptance.cmd`. No account or password is needed.
The test uses fictitious EAI records with real local SQL, HTTP, DPAPI and file
permissions. It creates its own new directories and ports. It never calls the
old API or EAI.

Allow several minutes: the runner waits for the real minute quotas instead
of clearing their state. Keep the window open until it gives a result and a
report path. Read the actual result and any failure category rather than
treating a completed run as success. The test includes the background controller.

Tests retain their files and diagnostic evidence. They do not clean up by
deleting databases or directories. Any test instance that cannot be confirmed
stopped is reported as a failure.

## 2. Create the operator's instance

Double-click `Installation/01-Install.cmd` once. It verifies the manifest and
bundled runtimes, chooses four available loopback ports, creates an empty
private MySQL instance and shuts it down cleanly. It makes no EAI request.

On success it prints `VERIFIED` and the new instance directory. The reference
is saved under `Etat-local/installation.json`. `Etat-local` contains private
installation state; it is not included in the source download.
For a long extraction path this reference and its private state live inside
the managed package printed by the launcher, not in the extracted source folder.
The first preparation can take a minute while Windows checks the bundled files.
Do not edit its generated JSON by hand.

If the tool says the installation already exists, use Status. Do not reinstall
over it. The installer refuses reuse to protect existing state.

## 3. Add an authorized account

Follow [Client onboarding](CLIENT_ONBOARDING.md). Keep any other collector for
that EAI account stopped, confirm at least a quiet minute, and authorize the
single first collection. The tool asks for a client label, EAI username and a
masked EAI password. A separate API username, random password and five URLs
are generated only after the first publication and local HTTP checks succeed.

Adding a client is a maintenance operation: the instance must be stopped.
An ambiguous failed attempt is retained for review, not silently retried.

## 4. Start and confirm

Double-click `02-Start.cmd`, then `03-Status.cmd`. Start waits for the supervisor,
worker, local MySQL and managed HTTP process to be ready. It does not register
a Windows scheduled task or prove that EAI is delivering new records.
A fresh Status should show:

```text
State: RUNNING
Local HTTP: True; Local MySQL: True
<tenant>: PUBLISHED; generation <number>
Supervisor: RUNNING
```

A fresh heartbeat matters. A stale status file alone is not proof that the
processes are running. Check both the ports and the publication state. Faulted
or reserved EAI attempts are not automatically replayed after a restart.

Do not start a second copy against the same EAI account to troubleshoot a
failure. Record the category, instance and timestamp for review.

## 5. Test the URLs

Open `Installation/09-Client-access.cmd` from the package linked to this
installation and choose a client number. Explorer opens its private folder
with `ACCES-CLIENT-PRIVE.json` selected. Open the file in Notepad and use the
five links under `urls`. No Stop or new registration is needed to find it.
Add client also prints the exact filename as `accessSheetPath`; the
[client guide](CLIENT_ONBOARDING.md#find-the-access-file) gives each storage layout.
Do not use sheets under `Essais-locaux`: those are fictitious test accounts.
Do not paste access sheets or passwords into a report. They contain client
API access, not EAI or MySQL access. Loopback URLs work only on this computer.

Wait at least 60 seconds after the first HTTP checks or any manual refresh,
then run `05-Test.cmd`. It reads the five local routes and writes a private
report without recording response bodies or credentials. It queries history
for a sample vehicle from today's UTC midnight to the current time. Zero rows
only describe that local query, not the client's entire history.

To compare manually, use the same `CWVehicleID` and measurement timestamp in
both APIs. Do not compare the first row of each list. The EAI-authorized fleet
can be larger, and list ordering is not a proven old-provider contract.

Refresh reads the latest successful publication. It does not force EAI to
create a new event. A stationary or silent vehicle can retain an old
`LastReportTime` or `LastLocationTime` even while the collector is healthy.

## 6. Stop, restart and keep evidence

Use `06-Stop.cmd`, then Status until `STOPPED` is confirmed. Stop requests a
graceful shutdown; do not close MySQL forcibly. Start again and check that
publication state is retained. No deletion is needed for this procedure.

Manual startup and a Windows reboot passed on the local test PC, including
retained access and adding another fictitious client without reinstalling.
Repeat these checks on the intended company host. Logoff and scheduled startup
need separate tests; see [Windows startup](WINDOWS_STARTUP.md).

## Common installation problems

| Message or symptom | Next step |
| --- | --- |
| `INCOMPLETE PACKAGE` | Extract the whole ZIP; keep all sibling folders together. |
| `EXTRACT_TO_SHORT_LOCAL_PATH_REQUIRED` | Use the current complete Windows package and its top-level Installation launchers. This message can occur with an older launcher or a directly invoked internal helper. Do not move an initialized installation. |
| `INCOMPLETE_MANAGED_PACKAGE_REQUIRES_REVIEW` | A previous preparation did not finish. Keep its files for the maintainer to inspect before continuing. |
| `INITIALIZED_PACKAGE_MOVED_REQUIRES_REVIEW` | The source already contains initialized state. Do not relocate its secrets/database or re-add the account; review the original installation. |
| A dialog asks for an installed PHP or XAMPP | You opened an internal code-only helper. Close it and use top-level `Installation`. |
| Missing C/C++ runtime | Ask IT to review `Runtime/Prerequis`; do not copy DLLs from another PC. |
| Windows refuses execution | Check the exact file named in the error. Ordinary operation uses the current bundled Node.js controller; retain any remaining company-policy refusal for IT review. |
| HTTP 503 after another request | Wait a quiet minute; if it continues, inspect Status and private diagnostics. A 503 has several possible causes. |
| `FAULT_*` or a pending attempt | Stop and review the retained state. Do not reset a cursor, remove a lock or repeatedly re-add the account. |

For an independent test, use the original ZIP and perform this procedure
under the tester's own Windows account. Do not send the
initialized folder from the development PC. Public URLs and production
hosting require a separate IT configuration and acceptance.

## Upgrading an existing instance or reviewing an EAI outage

Use [Outage recovery](OUTAGE_RECOVERY.md). `08-Update-existing.cmd` preserves
the selected stopped instance, its data and API access. `07-Recover-sync.cmd`
reviews and, only after explicit approval, resumes one EAI attempt. Neither
tool deletes history or silently retries an ambiguous remote request.

STALE HTTP 200 data is the last verified publication, not fresh measurements.
Status and the Test summary must be checked for DEGRADED/STALE/review warnings.
