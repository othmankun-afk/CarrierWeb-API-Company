# Windows restart and unattended startup

## Normal manual operation

The normal Start, Status and Stop launchers use the bundled Node.js controller.
They do not execute `Control-Suite.ps1` or `Start-Suite-Supervisor.ps1`.
Start waits for matching process heartbeats and local service readiness;
Status reports synchronization health separately.

This is manual background operation within the Windows user's session.
After a Windows restart, sign in under the same Windows account and run
`Installation/02-Start.cmd` from the package linked to the existing instance.
Then use `03-Status.cmd` and check publication health. Do not run Install again.
Stop with `06-Stop.cmd` and confirm `STOPPED` before maintenance.

A manual Windows reboot passed on the local test PC, with 59 post-restart
checks. The same user retained encrypted access and database state, started
the existing instance without reinstalling and added another fictitious
client with all five routes working. The accounts were paused, so this test
verified state persistence and manual operation without real EAI collection.

Scheduled startup after logoff or reboot has not been accepted and remains
a separate IT deployment step. Manual Start does not register, replace or
reconfigure a scheduled task, including one recorded by an earlier installation.
The local result does not replace a test on the intended company host.

## Optional IT-managed unattended operation

`Code/service/Install-Server-Startup.ps1` is an IT tool. The portable installer
does not execute it or register a Windows task. Its default/`-ValidateOnly`
mode checks the plan, owner identity and DPAPI decryption in memory without
registering a task, writing secrets or starting collection.

## Identity first

Initialize the instance under the Windows account intended to run it. The
plan records `windowsOwnerSid`; CurrentUser DPAPI uses that same identity.
SYSTEM, LocalService or another administrator is not an interchangeable
replacement. An old plan without the required SID is refused. Prepare a
controlled fresh installation rather than editing its identity fields.

## Registration needs explicit approval

After IT review, use `-Register` with a non-empty `-ApprovalReference` and
the absolute plan path. The script requests the **Windows password of the
same account**, not EAI or API credentials. It refuses another identity or
an already existing task.

The task is named `CWEaiSuite-<installation UUID>`, uses AtStartup, Password
logon, Limited privilege and a synchronous supervisor. Windows Task Scheduler
stores the credential needed outside a session. It is not put in process
arguments or the suite's files. Registration does not immediately start it.
The registered action, identity and trigger are checked afterwards; earlier
task metadata and intent are retained. A partial registration failure needs
IT review, not deletion or an automatic repeat.

Windows policy may refuse scripts, credentials or batch logon. Do not bypass
that refusal or switch identities. The task action does not force an
execution-policy bypass. The manual Node.js launchers and the scheduled-start
scripts use separate paths. A successful manual Start does not validate the
scheduled task or the target machine's logon rights.

## Unattended target acceptance sequence

1. Validate the HTTPS deployment profile and intended Windows identity.
2. Start explicitly; confirm fresh heartbeat, open ports, publication and URLs.
3. With an approved test window, log off and verify from another computer
   that publication continues under the intended identity.
4. Reboot in an approved window and repeat the checks.
5. Test a clean Stop and verify it is respected. A persisted stop request
   must not be ignored just because a boot task exists.
6. Verify faulted/reserved EAI attempts stay paused for review after restart.
   Retain the result and any diagnostic state for recovery review.

Task registration, operation after logoff and collection at boot still need
testing on the intended company host. The local manual-reboot test did not
register a scheduled task.
