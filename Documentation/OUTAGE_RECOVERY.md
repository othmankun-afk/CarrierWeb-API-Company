# Availability during an EAI outage

## What the API returns

An interrupted or uncertain EAI request stays reserved until it is reviewed.
This prevents an automatic repeat, but it also means that running local
processes do not always imply that collection is advancing. Status reports
process health, collection state and publication freshness separately.

With the `last-known-with-warning` policy, the five routes
can read the last integrity-checked publication during an upstream outage.
No timestamp is rewritten to make it look recent. Response schemas stay the
same. Successful responses include:

| Header | Meaning |
| --- | --- |
| `X-Data-Freshness` | FRESH or STALE publication, not the age of each sensor measurement. |
| `X-Data-Captured-At` | Actual UTC capture time of that publication. |
| `X-Data-Age-Seconds` | Elapsed time since that capture. |
| `X-Collection-State` | Published, in progress, or review required. |

History contains locally collected records; complete source coverage is
unverified. Unknown sensor markers are preserved, and missing history is not
fetched automatically. A missing, corrupt, future-dated or unauthorized publication is
still rejected. Credentials, fleet scope, query bounds and the 60-second
account/endpoint quota still apply. A stale authorization scope reflects the
last publication; upstream permission changes cannot be known while EAI is
unavailable. Operators must disable local API access when revocation is required.

New installations use the last-known policy. Existing configurations
without it remain fail-closed until the operator approves an update to
that policy. The smoke test reports DEGRADED rather than PASS for stale data,
and WAIT rather than a generic failure for a minute-limit response.

## Update an existing local installation

1. Keep the original installation and all its data. Do not add the client again.
2. Use its Stop launcher and confirm that the supervisor and owned HTTP/SQL
   ports are stopped. A stale status file is not sufficient evidence.
3. Extract the current full Windows package separately. In that new package,
   run `Installation/08-Update-existing.cmd`.
4. Select the existing installation and type UPDATE only after reviewing the
   last-known-data policy. The updater verifies both inventories, the Windows
   owner, runtime identity and stopped ports. It backs up changed code/config,
   preserves the database, credentials and cursors, and writes a local reference
   so the new launchers keep using the same instance and API URLs.
5. Use only the new package's Start, Status and Test launchers afterwards.
   Never redistribute this initialized operator folder; distribute the original ZIP.

No runtime executable, licence or database schema is replaced by this updater.
An interrupted update retains its evidence. Previous inventoried files/config
are restored on a failed pre-commit update; new unused files are retained rather
than deleted. A mismatched or running installation is refused, not overwritten.

## Review and resume one interrupted synchronization

### A page was received but local processing failed

Stop the instance and open
`Installation/07-Recover-sync.cmd`. Select the affected tenant and type
`REPLAY LOCAL`. This mode makes no EAI request. It checks the exact persisted
generation, failed status and attempt time before committing the pending page.
It refuses a page from an earlier attempt or a generation already published.

Replay appends the received history and advances only to that page's cursors.
It does not reset the database or rewrite capture time. An old capture can
therefore still report `STALE` after a successful replay. Start normal
collection only after confirming account ownership and the quiet minute,
then verify that the capture time advances in Status.

For the specific persisted fault `FAULT_SUITE_CURSOR_REGRESSED`,
reviewed replay preserves the maximum of each saved and advertised partition
ID, matching the official SDK's `updateMaxID` behavior. It does not replace a
saved ID with a smaller metadata value. The exact failed generation, status,
attempt time and capture time must still match, and missing partitions remain
an error. Other faults do not receive this cursor correction automatically.
This is a local replay, not a cursor reset or a request for older source data.

For repeated event IDs with identical measurements, current state keeps two
distinct witness records for each equivalent payload. This limits duplicate
bookkeeping without hiding ambiguity. Every raw event remains in history.
Different payloads,
conflicting identities, future dates and global size limits are still checked.

### No valid page was received

An uncertain upstream request requires review before another attempt.

1. Stop the instance and confirm stopped ports.
2. Confirm no competing collector or EAI request for that account for at least
   one minute. Local attempts also retain the persisted minute reservation.
3. Run `Installation/07-Recover-sync.cmd`. Select exactly one tenant if needed.
   Type RESUME ONE and then QUIET to authorize a single reviewed EAI attempt.
   Without approval it performs a read-only review; it sends no EAI request.
4. Recovery checks the expected generation, status and attempt time atomically.
   It never resets cursors or deletes history. A successful attempt must publish
   and pass the five local HTTP checks before success is reported.
5. Start the instance and confirm PUBLISHED, a new capture timestamp and healthy
   synchronization. Normal periodic synchronization then resumes. If recovery
   fails, retain its capture/report and review the cause; do not repeatedly
   retry or re-add the account.

No Windows task is installed by recovery. Reboot/logoff and production operation
still require target-machine acceptance. Continuous availability also requires
the host, MySQL and HTTP service to remain running; stale-data mode does not
keep a powered-off or crashed workstation serving requests.
