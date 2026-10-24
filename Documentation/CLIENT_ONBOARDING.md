# Add a client and issue API access

There is no administration website. An authorized operator uses
`Installation/04-Add-client.cmd` on the host that owns the instance.

The API follows the vehicles available to the EAI account. Before testing,
read the [API contract](API_CONTRACT.md) for field meanings, unknown values
and compatibility constants. Adding a client does not establish that every
result matches the previous provider's API.

## Operator procedure

1. Use Stop, then Status. Confirm the instance is stopped and its ports are
   closed before changing the client registry.
2. Coordinate the EAI account with the team. Other collectors must not be
   using it, and there must be no known call during the previous minute.
   This confirmation concerns the EAI account, not just this workstation.
3. Open Add client. Answer `YES` only when that condition is true. Enter the
   client's label, EAI username and masked **EAI password**, not an API token.
4. If the same account has an unfinished registration, the tool shows its last
   step and error. Type `RESUME` to continue that registration, or press Enter
   to cancel. This keeps its client identity and existing database. A valid
   saved response is reused; a new EAI read is made only when needed and after
   the quiet-minute checks. There is no automatic retry.
5. Let the first collection and local checks finish. Do not refresh an old URL or run
   another collector at the same time.
6. Keep the result. Only a successful first publication and five-route HTTP
   check activate the client and create the final access sheet.
7. Start the supervisor and check a fresh `RUNNING` / `PUBLISHED` status.
8. Open `Installation/09-Client-access.cmd`, choose the client and open the
   selected private access file to find its API links. Wait at least 60 seconds
   after the registration checks before making another request to those endpoints.

Install is needed only once per instance. To add another authorized EAI
account later, use Stop, Add client, then Start and Status; do not reinstall.
The same EAI account cannot be added twice. Client access can be used at any
time without stopping the API or registering anything again.

## Find the access file

Use `Installation/09-Client-access.cmd` from the package linked to your
installation. It lists the clients in that instance's active registry.
Enter a client number to open Windows Explorer with that client's existing
`ACCES-CLIENT-PRIVE.json` selected. Open it in a text editor and find `urls`.
The helper does not display passwords, read sheet contents or make API/EAI
requests. Opening the file manually reveals its API credentials, so keep it
private and close it when finished.

The exact full filename is also returned as `accessSheetPath` by Add client.
Its layout depends on where the installation was created:

| Installation | Private access-file location |
| --- | --- |
| Short extracted package path | `<extracted-package>\Etat-local\cwvehicles-<instance-id>\client-onboarding\<registration-id>\ACCES-CLIENT-PRIVE.json` |
| Default managed location for a long extraction path | `%LOCALAPPDATA%\CWAPI\p-<package-id>\Etat-local\cwvehicles-<instance-id>\client-onboarding\<registration-id>\ACCES-CLIENT-PRIVE.json` |
| Existing instance updated with `08-Update-existing.cmd` | The original private location is retained. Use `09-Client-access.cmd` from the package used for that update. |

If IT configured `CW_API_INSTALL_PARENT`, the managed package is under that
parent instead of the default `%LOCALAPPDATA%\CWAPI`. Use the exact returned
path; do not type the placeholder IDs or an abbreviated path
containing `...`. Client access avoids copying a long filename altogether.

A new extraction is not automatically linked to an older instance. Use the
original installation's launchers, or follow [the update procedure](OUTAGE_RECOVERY.md)
once to link the new package to the stopped existing instance. If a registered
client's file is missing, retain the diagnostic for review; do not recreate
the account or guess another client's folder.

Do not use access sheets under `Essais-locaux` or `Code/test-results`. They
belong to fictitious acceptance runs, not your registered clients, and their
temporary services are stopped after testing. The access menu reads only the
active client registry.

## What the tool does

`Code/tools/onboard-interactive.mjs` collects the operator's input directly in
Node.js. The EAI password is masked and passed to registration in memory, not
in command-line arguments.

It validates the input and stopped-instance state, reserves a registration
lock and prepares a private staging directory. SDK preflight verifies the
pinned source without sending a request. The tool then creates an isolated
schema and SQL accounts, reserves one EAI attempt durably, stores the returned
capture, validates its identity and feeds, and publishes it transactionally.

It generates an API username and a random 32-byte password represented as
hexadecimal. The generated password is checked to be different from the EAI
password. Five authenticated local HTTP requests must pass before the client
is committed to the active registry. The access sheet contains these five
URLs, the API username and password, and the first allowed request time.

The API account follows all vehicles authorized by its EAI account. A newly
authorized vehicle appears after a successful publication; no hand-edited
vehicle JSON or code change is needed. This does not reproduce a different
provider's manually restricted fleet.

Schemas and reader/publisher credentials are separate for each tenant.
EAI and publisher secrets are encrypted with Windows DPAPI. The HTTP reader
configuration contains its SQL credentials, and the private access sheet
contains the API password and URLs; Windows owner permissions restrict these
files. Give the final client only their API access, keeping EAI credentials,
SQL access and installation files on the host.

## Failure handling

The registry is not activated when pre-commit checks fail. The staging files,
attempt reservation and diagnostic category are kept. Do not remove a lock,
discard a stage or change the username to force another registration.

After reviewing an error, confirm the account has been quiet for at least a
minute, open Add client again and enter the **same EAI username**. Choose
`RESUME` when prompted. Supply the current EAI password through the masked
prompt. If another EAI read is needed, the local reservation must also be old
enough; a recent attempt is refused without sending anything. Each run can
make at most one EAI request. It never loops after a failure.

If a valid first response was saved, the tool continues local publication and
HTTP verification without another request. An expired unpublished response
is retained, and a reviewed fresh request may replace it. If the first
publication already exists, its saved EAI credentials and generated API
identity are retained; re-entering a different password does not silently
change that validated account. Existing clients are not re-registered.

Older failed stages from previous packages are supported when their source
files and protected SQL credentials are intact. A potentially partial registry
commit, damaged capture, ambiguous SQL provisioning or missing private files
still requires local review. These cases are not repaired by deleting state.

Known transport failures are reported as fixed categories such as
`EAI_DNS_FAILED`, `EAI_TRANSPORT_TIMEOUT` or
`EAI_TLS_CERTIFICATE_VALIDATION_FAILED`. An unclassified failure remains
`EAI_TRANSPORT_FAILED`; it is not evidence of an incorrect password. Provider
bodies and request credentials are not printed. The generic Visual C++ advice
is not shown for Add client failures.

For an already installed instance, apply the current package with
`08-Update-existing.cmd` while the instance is stopped. Do not run Install
again or copy a new package over a working state folder. The update preserves
registered clients, their access files, database and cursors.

The registration limit is 100 clients. Actual server capacity depends on the
workload and storage needs and must be measured before choosing deployment
limits.

## Moving to company hosting

A localhost URL works only on the machine running the API. For company hosting,
IT needs to configure the HTTPS address, web server, service identity and
deployment profile before client access is generated. Setting a hostname
does not create DNS or a certificate.
Create a fresh instance and re-enter secrets under its intended Windows
identity. Do not copy DPAPI state from the developer's PC.
