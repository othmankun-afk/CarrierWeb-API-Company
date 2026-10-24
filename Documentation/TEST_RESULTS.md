# Test results

The Windows package has been tested on one PC under the same Windows user.
The results below distinguish local simulated tests from separate real-data
observations. Company-host acceptance and full legacy-provider equivalence
have not been established.

The results below summarize the recorded test runs. Some suites overlap,
so their check counts should not be added together.

## Installation, client registration and restart

| Scenario | Result |
| --- | --- |
| Full native Windows acceptance | PASS, 74 top-level checks with fictitious clients. |
| Add a client after Stop | PASS: a fourth client was registered, all five authenticated routes passed, and the three existing clients and access files were preserved. |
| Manual Stop/Start | PASS: states were retained, repeated Start reused the supervisor, and Stop confirmed that owned ports were closed. |
| Stopped backup and restore | PASS: 468 files, 129,892,612 bytes, including SQL data, configuration and encrypted client access. Every inventoried file matched after restore; Start/Stop then passed. |
| Actual manual Windows restart | PASS, 59 checks. The same Windows user could decrypt the retained secrets, access and database states matched, and manual Start required no reinstall. |
| Add another client after restart | PASS: a fifth client was registered and all five routes passed, with existing client access preserved. |

The fictitious accounts were deliberately paused before background operation.
Their expected DEGRADED status tested process and status handling, not
live synchronization. This campaign made no real EAI call and left its test
services stopped.

Backup/restore used the same Windows user and original paths. It does not
establish cross-user or cross-machine restoration of encrypted credentials.

## HTTP contract and data formatting

| Check | Recorded result |
| --- | --- |
| Five public HTTP endpoints | PASS, 76 checks covering schemas, date handling, errors, minute rules and access scope. |
| Focused cursor/history compatibility | PASS, 92 checks on each bundled PHP version, 8.2.12 and 5.6.28. |
| PHP regression for cursor/history behavior | PASS, eight suites and 1,002 assertions per runtime. The 92 focused checks are included in that count. |
| Isolated MySQL history reads | PASS, 17 checks per runtime, including SELECT-only reads and unchanged stored payload hashes. These checks are separate from the PHP suite count. |
| README examples and field reference | PASS: five complete request/response examples and 112 fields checked against the actual contract on both PHP runtimes. |

The cursor checks cover exact decimal-ID comparisons and the maximum
saved/advertised ID for each partition. Missing partitions, invalid IDs,
stale review state and a page from the wrong attempt are refused.

For EAI history, exact empty text is returned as one ASCII space at the read
boundary. Event IDs, timestamps, numbers and stored hashes stay unchanged.
Offline same-event comparisons confirmed this formatting rule; they did not
resolve fleet scope, historical landmark enrichment or telemetry coverage.

The reduced vehicle response also validates LastReportTime and
LastLocationTime. Invalid dates use the existing empty HTTP 503 response;
valid source text is preserved.

## Interrupted client registration

| Check | Recorded result |
| --- | --- |
| Registration state machine | PASS, 45 checks, including cancellation, quiet-minute guards, reuse of saved responses and refusal of damaged or ambiguous stages. |
| SQL, HTTP and Windows encrypted-access integration | PASS, 50 checks with fictitious clients. A failed stage resumed under the same tenant/schema and preserved two existing clients. |
| Resume after HTTP verification failure | PASS: the saved publication and API identity were reused without another EAI read. |
| Stopped update and rollback | PASS, 327 checks, with 305 database files unchanged and the launchers linked to the same instance. |
| Component regression | PASS, 2,276 PHP assertions across both runtimes, 238 JavaScript policy checks and 154 syntax checks. |

The five authenticated routes and their minute rule passed with fictitious
clients and no real EAI call. Transport failures require review. Incomplete
registry commits and damaged private files are not repaired automatically.

## Windows controls and client-access menu

Start, Status, Stop and normal client input use the bundled Node.js runtime.
The background-control test marked the 65 inventoried script files as
Internet-zone downloads. It did not remove those markers or change Windows
execution policy.

Paused account states were loaded before readiness was reported. Repeated
Start reused the existing supervisor, and Stop confirmed shutdown and closed
owned ports. The separate scheduled-start scripts were not installed or
accepted by this test.

The client-access menu passed 46 read-only checks. It lists registered
clients, ignores unregistered test files, handles cancellation and missing
files, and does not read or print credentials. Managed-path checks passed
28 checks; complete/incomplete-package launcher guards passed 20.

Windows Explorer selection was tested through an injected launch boundary.
The actual highlighted file in an Explorer window was not observed by that
unit test. A junction-creation negative test was not completed. Neither
limitation was counted as a pass.

## Outages, recovery and isolation

The isolated SQL backup/restore and controlled-crash suite passed 35 checks
with 10,002 fictitious history events. It verified retained rows, types,
cursors and five responses, rollback of an uncommitted transaction and
refusal of an ambiguous retry. Only owned test instances were used.

Outage integration passed 45 checks. All five stored responses remained
available with STALE headers during the simulated outage and returned to
FRESH after one explicitly reviewed fictitious recovery. The operator
recovery path passed 14 checks with local SQL, HTTP and Windows encrypted
access. It preserved history and cursors and did not start the service
automatically.

Separate client schemas, passwords, HTTP scope and foreign-vehicle refusal
were checked. A browser request does not dispatch an EAI request or bypass
the collector's persisted minute guard.

## Real-data observations

A single source-page trial returned 31 vehicles,
76 positions, 89 telemetry records and 76 wired-telemetry records. CANbus
and divisions were empty in that page. Five local routes were checked.
This does not establish non-empty CANbus/divisions behavior or complete
source history.

Separate real-account installation and onboarding produced a valid first
publication and five successful local HTTP checks. A local smoke report
returned 174 vehicles and 174 vehiclesBase rows. The three history routes
returned empty arrays for the chosen vehicle and current-day window.
That smoke test made no EAI or old-API call. An empty result for that window
does not prove an empty upstream feed.

A cold-history study measured one source page. It did
not contain the requested target period. Its transport time is not a
measurement of full historical recovery. The page was not imported into the
active installation, no cursor was reset, and deletion remained disabled.

These observations are separate from the fictitious acceptance suite.
Private source pages, access files and customer diagnostics are not included
in the package.

## Comparison with the existing API

Different list order and EAI account scope can produce different first rows.
Compare vehicles by CWVehicleID, not row position or a label that may be
shared by several vehicles. Dynamic values also need the same event time and
source channel before a conversion difference can be concluded.

Unknown Engine is not OFF; unknown Plugged is not automatically equivalent
to an old empty value. Running-time values in the current vehicle response
are compatibility constants, not measured counters. Remaining landmarks,
telemetry definitions, units and active coupling cases are listed in
[Contract review](CONTRACT_REVIEW.md).

## Running the local checks

Use the complete Windows package. For a source build, follow the
[developer workflow in the source repository](https://github.com/othmankun-afk/CarrierWeb-API-Company/blob/main/Documentation/DEVELOPMENT.md).
From its root, these runners use isolated fictitious accounts, not real EAI
accounts:

```powershell
& .\Runtime\node\node.exe .\Tests\recette-portable.mjs
& .\Runtime\node\node.exe .\Code\tests\outage-integration.mjs "$PWD\Runtime"
& .\Runtime\node\node.exe .\Code\tests\recovery-local-integration.mjs "$PWD\Runtime"
& .\Runtime\php\php.exe -n .\Code\tests\unit.php
& .\Runtime\EAI\php.exe -n .\Code\tests\unit.php
```

The runners report PASS, PARTIAL or FAIL and retain their diagnostic results.

## Company checks still needed

Test installation on a clean company host, then verify real publications
advance over several cycles without a competing EAI collector. Confirm
non-empty history and known sensor cases, and agree the supported look-back
period with the provider.

Unattended startup, logoff, HTTPS, representative load, storage growth and
backup/restore targets still need validation on that host. The existing
manual Windows restart test does not cover all of those scenarios. The
package is not a native macOS installer.

See [Company testing](COMPANY_TEST.md) for the test sequence. Passing local
checks does not guarantee the absence of defects or certify a production
deployment.
