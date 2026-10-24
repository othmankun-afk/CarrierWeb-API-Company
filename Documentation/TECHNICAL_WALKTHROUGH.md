# How the API works

The starting point was the existing customer API contract and the official
EAI SDK. The application keeps those two roles separate: EAI supplies authorized
source data; the PHP API presents a controlled JSON view of a local copy.
It does not query the internal CarrierWeb database directly.

```text
Authorized EAI account
  -> pinned official SDK and collector
  -> captured page, identity and mapping checks
  -> transactional MySQL publication per tenant
  -> read-only PHP API
  -> customer's five URLs
```

The collector and API use this local publication independently. An API request
does not query the internal CarrierWeb database or trigger EAI collection.

## 1. The installer prepares an empty environment

`Code/tools/portable.mjs` locates runtimes relative to the package, checks
manifest hashes and delegates initialization to `initialize-empty-suite.mjs`.
The bootstrap creates a new private `cwvehicles-<UUID>` directory, assigns
ports and initializes its own SQL instance. It does not import a developer's
database or create a real client at this stage.

The service plan records the owner and runtime paths. Secret material is
separate, protected by DPAPI CurrentUser and private directory permissions.
Each host therefore needs a fresh installation under its intended Windows
identity, rather than a copy of another user's initialized folder.

## 2. Onboarding establishes the account boundary

`tools/onboard-interactive.mjs` collects masked input directly in Node.js.
It passes the password in memory, not through process arguments.
`add-suite-client-core.mjs`
stages the account, creates its schema and SQL users, reserves a single first
EAI attempt and verifies the first publication. Generated API access is
different from EAI access. The registry is committed only after five local
HTTP checks succeed. Failed registrations retain their diagnostic state and
can be resumed explicitly.

The data perimeter is the EAI account's authorized fleet. The delivered
HTTP code also checks the tenant and vehicle scope. Separate SQL grants
provide an additional boundary; an HTTP filter alone is not the isolation
strategy. The HTTP reader is SELECT-only.

## 3. Collection is independent of HTTP requests

`service/control-suite.mjs` implements the normal Start, Status and Stop
actions directly in Node.js. Start checks the owned ports, launches the
supervisor without a persistent terminal, and waits for matching supervisor
and worker heartbeats plus service readiness. Repeating Start on that same
healthy process pair does not create another supervisor. Stop requests a
graceful shutdown and confirms the owned ports are closed. This manual mode
does not install a boot task or change Windows execution policy.

`service/suite-supervisor.mjs` supervises the worker. `suite-worker.mjs`
owns the private SQL and local HTTP processes in local mode and schedules
collections. `suite-runtime.mjs` and `collect-suite-eai.php` bind the pinned
SDK and the database bridge. The vendor SDK is not edited to manufacture
fields or suppress errors.

Before transport, an attempt is durably reserved with a minimum interval of
60 seconds. A crash or ambiguous response leaves reviewable state; restarting
does not silently repeat the upstream request. Successful later cycles can
advance the publication generation. Refreshing a browser only reads the last
published generation.

## 4. Mapping is explicit

`src/EaiVehicleMapping.php` maps vehicle identity and current fields.
`EaiSuiteMapping.php` maps historical feeds. `EaiCurrentVehicleSignals.php`
handles supported sensor sources, channels and timestamps.
`VehiclesLegacyCompatibility.php` and `LegacyVehicleCounterPolicy.php`
define the compatibility rules used by the API.

Mapping examples:

- A division description comes from the matching divisions record, not
  from a hardcoded client label.
- Location comes from EAI LastLocation. Another geocoder can use a different
  place label without changing the vehicle identity.
- Ambiguous sensor values remain unresolved until their source and unit
  are confirmed.
- Battery is not converted to volts without a confirmed source rule.
- Unknown markers are not real OFF/zero measurements.
- Vehicle running-time zero/empty values are compatibility constants, not
  evidence of measured engine duration.

The contract and mapping tests describe what is implemented. They do not
reveal or prove the old provider's private calculation rules.

## 5. Storage publishes a consistent generation

`SuiteServiceStore.php` validates and accepts a page in a transaction.
Current state, history, cursors and generation must be consistent; a failed
page is not partially published. `SuiteLiveRepository.php` serves published
data to the API. The schema is `Code/database/suite_live.sql`.

Current internal JSON can be stored losslessly in compressed form; clients
still receive normal JSON. History is retained, so storage continues to grow.
Capacity guards can refuse a page or pause a tenant. A full account requires
a storage plan before collection can resume; its history and cursors are kept.

## 6. PHP enforces the wire contract

`public/router.php` and `public/index.php` are entry points.
`src/Api.php` handles routing and access, `Contract.php` validates exact field
names/types and dates, and `CarrierwebResponse.php` preserves the historical
public error behavior. `RequestProtection.php` adds request controls.

The same tenant/endpoint quota is shared across vehicles and date ranges.
Five different endpoint names have separate budgets; changing a vehicle ID
does not obtain another budget for positions. Limits also bound response
rows, bytes and concurrent work. They do not define historical availability.

Public error compatibility is intentionally unusual: many failures are empty
503 responses, while some positions failures are HTTP 200 text. Private
diagnostics provide context without changing the public wire contract.

## 7. Tests check different layers

Offline PHP suites check mapping, validation and edge cases. Loopback HTTP
checks exercise the actual entry points. Fictitious onboarding tests use
real SQL, DPAPI and separate tenant grants. Recovery tests restore into a
fresh test instance and exercise a failure of its own SQL process.

Authorized live trials then check that real EAI data can pass through the
pipeline. These checks complement the fictitious-data tests; they do not
prove every old-provider calculation or value convention. The precise
results and remaining gaps are in `TEST_RESULTS.md`.

## What changes for company production

The production host needs an HTTPS web server and address, a service identity
for secrets, a startup policy, capacity, monitoring and tested backup/recovery.
Only `Code/public` belongs in the web root. Other directories stay private.
The supplied local PHP server is a test runner, not the public production
server. Existing local success does not validate a different target machine.

