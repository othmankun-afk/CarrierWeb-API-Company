# Company test guide

Use this guide to test the package on a Windows x64 workstation. It provides
the five API endpoints and a separate local database for each registered
client. Production hosting still needs validation, and some differences from
the previous API remain to be checked.

## Choose the right download

- **Source repository / GitHub Download ZIP:** code, examples, tests and
  documentation for review or development. It does not include the complete
  runtime needed for the operator launchers.
- **Full Windows ZIP in Releases:** the runnable test distribution, including
  the pinned runtimes and their licence notices. Extract the entire archive
  to a new local folder. Keep its folders together and run launchers from
  the extracted folder, not from inside the ZIP.

Use the original release ZIP, not another person's initialized installation.
No customer accounts, credentials or databases are included. Windows may ask
for execution approval; company application policy and prerequisites still
apply. See [Installation](INSTALLATION.md) for the runtime requirements.

## First evaluation

1. Run `Tests/01-Run-acceptance.cmd`. It creates isolated fictitious clients,
   uses local SQL and HTTP, and makes no real EAI call. Wait for the final
   result and retain the report. A completed window is not sufficient: check
   whether the result is `PASS`, `PARTIAL` or `FAIL`.
2. For a new operator instance, run `Installation/01-Install.cmd` **once**.
   It creates an empty private instance without contacting EAI. Do not run
   Install again to add clients or after restarting Windows.
3. Coordinate one authorized EAI account with its owner. No other collector
   may be using that account, and at least one minute must have passed since
   its last known call. Stop the instance with `06-Stop.cmd` and confirm
   `STOPPED` with `03-Status.cmd` before using `04-Add-client.cmd`.
4. Enter the client label, EAI username and masked EAI password. This is not
   the API password. Registration may make at most one EAI request; it does
   not automatically retry an uncertain attempt. Keep the final result. If
   registration fails, follow [Client onboarding](CLIENT_ONBOARDING.md)
   rather than deleting its stage or entering a different account name.
5. Run `02-Start.cmd`, then `03-Status.cmd`. Check a current heartbeat,
   HTTP/MySQL readiness and publication health. `RUNNING` alone does not
   prove that fresh EAI data is arriving. Observe advancing successful
   generations across several collection cycles.
6. Run `Installation/09-Client-access.cmd`. Select the client **by its number**.
   Explorer selects its existing `ACCES-CLIENT-PRIVE.json`; open it in a text
   editor and use the five links under `urls`. Respect the first allowed
   request time and the minute rule. Keep that file private.
7. After a quiet minute, `Installation/05-Test.cmd` checks the five local
   routes. An empty historical array only describes its vehicle/time query;
   it does not establish that the upstream history is empty.

Unless a path is shown in full, launcher names above are inside the extracted
package's `Installation` folder. Use the exact installation path printed by
the launcher.

## Where the private client file is stored

The access helper avoids manually finding or copying a long path. The file
also has an exact location returned as `accessSheetPath` by Add client:

| Instance layout | Location |
| --- | --- |
| Short extraction path | `<package>\Etat-local\cwvehicles-<instance-id>\client-onboarding\<registration-id>\ACCES-CLIENT-PRIVE.json` |
| Default managed location for a long extraction path | `%LOCALAPPDATA%\CWAPI\p-<package-id>\Etat-local\cwvehicles-<instance-id>\client-onboarding\<registration-id>\ACCES-CLIENT-PRIVE.json` |
| Existing instance updated with `08-Update-existing.cmd` | Its original private location is retained. Use the package linked by that update. |

An IT-configured `CW_API_INSTALL_PARENT` replaces the default managed parent.
Do not type placeholder IDs or abbreviated paths containing `...`. Sheets
under `Essais-locaux` or `Code/test-results` belong to fictitious tests, not
the operator's clients. [Client onboarding](CLIENT_ONBOARDING.md#find-the-access-file)
explains the layouts and helper behavior in detail.

## Add another client later

Use **Stop -> confirm STOPPED -> Add client -> Start -> Status**. Coordinate
the new account's exclusive use and quiet minute each time. Do not reinstall.
Successful registration creates separate access for the new client and keeps
existing clients and their access unchanged. The same EAI account cannot be
registered twice. An unfinished registration can be reviewed and resumed
with the same EAI username; it is not a reason to create another instance.

For an existing instance on an earlier release, use the stopped-instance
[update procedure](OUTAGE_RECOVERY.md). Do not extract a release over its
private database or assume that a new extraction is linked to it.

## Stop, restart and backup

Use `06-Stop.cmd` and confirm the stopped state before maintenance. After a
Windows restart, log in under the **same Windows account**, use the same
installation's `02-Start.cmd`, then check Status and the existing client
URLs. No reinstall is required. The normal launchers do not register an
automatic startup task. See [Windows startup](WINDOWS_STARTUP.md).

A local test with fictitious clients preserved the database, configuration,
encrypted credentials and client access across a stopped physical backup/restore and
a manual Windows reboot. It also added a fourth fictitious client before
reboot and a fifth after reboot without changing previous access. These were
synthetic, paused accounts: no real EAI request was made and background
collector continuity was not tested.

For an operator backup, obtain a confirmed clean Stop first. Retain the full
private instance together with its matching package, configuration and access
files; protect the backup as credentials-bearing material. The tested restore
was under the same Windows identity and original paths. Copying DPAPI files
does not provide portable credentials for another user or machine. IT must
define backup location, frequency, retention and a supervised restore test;
live copying of an active MySQL data directory is not the tested procedure.

## Host and client computers

The bundled host runtime is **Windows x64**, not a native macOS package. A
Mac can consume a separately hosted HTTP(S) API as a client. A URL beginning
with `127.0.0.1` works only on the computer running that instance; it cannot
be given to another computer as a hosted service URL. Company-wide access
needs an HTTPS host, DNS, certificate, service identity and logging
configuration. See [Server configuration](SERVER_CONFIGURATION.md).

## Acceptance still needed

- Install from the original ZIP on an independent company workstation.
- Verify real-account fleet scope, non-empty feeds and sustained fresh
  publications without a competing EAI collector.
- Resolve the remaining fleet, landmark, telemetry and upstream historical
  coverage questions in [Contract review](CONTRACT_REVIEW.md).
- Agree workload, history needs and storage budget. The default 100,000
  combined historical-event guard per tenant remains in place. No automatic
  deletion or poll-deliver-delete policy is enabled; see
  [Capacity policy](CAPACITY_POLICY.md).
- Accept any unattended startup, HTTPS hosting, load and recovery objectives
  on the intended host. Local test results do not establish those guarantees.

For review, share the original ZIP, repository and a test summary with private
details removed. Do not send initialized state, access sheets, credentials, raw
captures or private backup/test folders.
