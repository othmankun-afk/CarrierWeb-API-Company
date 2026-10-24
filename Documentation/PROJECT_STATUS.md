# Project status

The Windows package provides a local EAI-backed API with five routes, client
registration and separate MySQL storage for each client. It contains no real
accounts, credentials or customer data.

The package has been tested on one Windows PC. Installation, client
registration, the five routes, Stop/Start, backup/restore and a manual Windows
restart passed in isolated tests. Company-machine testing and production
deployment are still to be completed.

## Available features

- Five GET endpoints with documented fields, dates, error responses and a
  60-second interval for each client and endpoint.
- An EAI collector that publishes validated data to local MySQL. API requests
  read this publication rather than contacting EAI.
- Client registration with a separate schema, generated API credentials and
  first-publication checks before the client is activated.
- Explicit resumption of an interrupted registration. The same client and
  schema are kept; a saved valid response is reused where possible.
- Start, Status, Test, Stop, update and recovery launchers, plus a client-access
  menu that locates the private file containing each client's five URLs.
- Handling of long extraction paths through a verified short local package,
  without moving an existing installation.
- Partitioned cursor handling that keeps the SDK's high-water IDs. A matching
  saved cursor-fault page can be reviewed and replayed locally without another
  EAI request.
- Last-known publications with freshness headers during an EAI outage.
  Missing or invalid publications are not returned as empty successful data.

Historical empty text is formatted at the API read boundary; stored events
and hashes are not rewritten. Unknown sensor markers and compatibility
constants remain distinct from real measurements.

## Local verification

The full Windows acceptance passed 74 checks. After a manual Windows restart,
59 further checks passed under the same Windows user, with no reinstall.
Client access and database states were preserved, and an additional test
client was registered before and after the restart.

A stopped backup restored 468 files, including data, configuration and
encrypted access, with identical file hashes. Start/Stop passed after restore.
The client-access menu passed 46 read-only checks. Update/rollback passed
327 checks while preserving 305 database files.

These tests used fictitious accounts. Continuous live collection, automatic
startup and restoration under another Windows user or PC need separate tests.
See [Test results](TEST_RESULTS.md) for the evidence and scope.

## Remaining decisions

| Area | What remains |
| --- | --- |
| Company installation | Test the package on a clean company PC under the intended Windows user. |
| Source compatibility | Confirm expected fleet scope and compare unresolved landmarks, telemetry units and active sensor/coupling cases using the same vehicle and event. |
| History | Confirm source depth, continuity and the customer periods to support. Missing history is not fetched during an API request. |
| Storage | Agree an event rate, disk budget and retention policy. The protective history quota remains in place; no purge is enabled. |
| Continuous operation | Test unattended startup, logoff and fault recovery on the intended host. A manual restart test does not cover these. |
| Shared customer access | Choose the server address, HTTPS setup, web server, identity and logging. Localhost URLs are not remote customer URLs. |
| Capacity and recovery | Measure representative load, database/index growth, backup time and restore targets on the company host. |
| Runtime support | Review maintenance and redistribution of the bundled legacy SDK, PHP and MySQL components. |

The minimal copied-database objective is not yet demonstrated. A
poll-deliver-delete design needs a supported way to refill missing history
and an agreed retention policy before any deletion can be considered.

Start with [Company testing](COMPANY_TEST.md). The
[API contract](API_CONTRACT.md) describes the delivered behavior, and
[Contract review](CONTRACT_REVIEW.md) lists the remaining provider questions.
