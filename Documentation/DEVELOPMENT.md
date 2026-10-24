# Developer workflow

## Source versus runnable package

This repository is the reviewable application source. It does not include
Node, PHP, MySQL or the proprietary EAI SDK. The full private Windows package
is a separate distribution with its own manifest, runtime notices and source
materials. The tester workflow is described in `INSTALLATION.md`.

Do not copy files from a working instance to make a runtime package. In
particular, do not copy `Etat-local`, real captures, DPAPI secrets or database
directories. Do not commit or push the complete Windows ZIP as a Git blob.

## Verify the source inventory

Use Node.js, either from your local development environment or from
the verified Windows package:

```powershell
node .\Tools\verify-source.mjs
```

This reads the source inventory, checks file hashes, required documentation
and obvious private-content patterns. It makes no API/EAI request and opens
no database. It is a packaging check, not an exhaustive security audit.

`.gitattributes` preserves file bytes across Windows checkouts because the
distribution uses SHA-256 pins. Do not normalize line endings without updating
the relevant inventories and rerunning the affected checks.

## Assemble a new isolated test package

Use the pinned, uninitialized Windows distribution as the runtime base. It
must be fully extracted, with its original `MANIFEST.json`. Assembly verifies
every base hash before copying it and then overlays only inventoried source
files. Existing initialized directories and reused targets are refused.

For a developer with Node available:

```powershell
node .\Tools\assemble-test-package.mjs --base C:\CW-API-Base --target C:\CW-API-Review
```

If Node is not installed, the base provides
`C:\CW-API-Base\Runtime\node\node.exe`. Use that executable to run the same
script. The target must be a new local directory. Long extraction paths are
handled by the launchers through a verified per-user runtime location.
Do not choose the source checkout or runtime base as the target.

Assembly does not install, collect, start services or modify the base. It
keeps licence/source material and generates a new portable file inventory.
The tracked `PACKAGE_README.md` becomes `README.md` in the assembled package,
so the installation guide stays separate from the repository overview.
The assembled package remains uninitialized. Run its
`Tests/01-Run-acceptance.cmd` and keep the result, including any PARTIAL/FAIL.

Archived result reports listed in `SOURCE-MANIFEST.json` under
`excludedPortableFiles` are omitted from assembly. The test runners remain
included and generate their own reports when executed.

`Distribution/portable-asset.json` identifies the runtime assembly base, not
the current download. Keep its pinned hashes unchanged when publishing a new
application package. The current release has its own ZIP and checksum.

Changes to publication availability, freshness headers, collection health or
recovery affect service behavior even when JSON fields stay the same. Test
these paths as well as the field schemas. Isolated tests do not establish
unattended startup or production readiness.

## Tests and evidence

The portable acceptance runner uses fictitious EAI records with real local
SQL, HTTP and DPAPI. It does not call EAI or the existing provider. It retains
its test artifacts rather than deleting them.

Keep the actual test result with the source revision, changed paths and test
commands. Distinguish tests using fictitious data from live-account checks,
and list anything not tested. Earlier reports belong to the build they tested.

Before a live-account test, coordinate with the account owner and confirm
the quiet interval and number of permitted requests. Source verification and
assembly do not need an EAI password. Review an ambiguous failure before
trying again; do not reset its state to force a new request.

## Changes to review carefully

- Contract fields/types, date parsing and unusual positions error bodies.
- Scope, authentication and object/vehicle authorization.
- Sensor channels, units, unknown markers and source timestamps.
- Atomic publication, cursor state, attempt reservations and failure recovery.
- Minute rules, response bounds, capacity and concurrency controls.
- Paths, owner ACL/DPAPI behavior and Windows startup identity.

Keep application logic within the company's agreed technology constraints.
Existing PowerShell helpers are Windows operational tooling. Do not introduce
a new application language or deployment dependency without team approval.
If Windows blocks a helper, keep the error and have IT check the company's
execution policy before continuing.
