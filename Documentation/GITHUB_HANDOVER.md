# Publishing the source and Windows package

Use this procedure to keep application source and the complete Windows test
package separate. A source commit does not publish a runtime release.

## 1. Check repository access and licences

Keep the repository private and give access only to the intended reviewers.
Agree the repository owner and collaborators with the company before changing
access or moving the project.

Check the vendor SDK's redistribution and hosting terms before sharing the
full package. Keep the supplied licence notices and MySQL corresponding
source. A private repository does not replace these permissions.

## 2. Push source, not installed state

Review the source inventory and staged diff. This checkout excludes runtimes,
binary archives, databases, credentials, captures and unrelated personal files.
The `.gitignore` is a second guard; it cannot retract a secret that has already
entered history.

For an existing repository, inspect its current branch and changes first.
Review the diff before pushing. Preserve unrelated work and existing history.

## 3. Prepare and verify the release

Choose a tag for the reviewed source revision. Create a **draft, pre-release**
and attach the matching Windows package, not GitHub's generated source archive.
Compare the uploaded asset's size and SHA-256 with the local ZIP and its
`SHA256SUMS.txt` file before publishing or sharing the link.

Attach the full runtime ZIP as a release asset, not as a file in the source
repository. See [GitHub: releases](https://docs.github.com/en/repositories/releasing-projects-on-github/about-releases)
for the release workflow.

`Distribution/portable-asset.json` identifies the runtime base used for
assembly; it is not the current download record. Keep those base hashes
unchanged. The release's Assets section contains the current ZIP and its
`SHA256SUMS.txt`.

Archive earlier packages for recovery. Link the current release from the
README, and check that the README at the release tag names the matching asset.

## 4. Tell the tester exactly what to download

Testers should open **Releases**, download the Windows asset linked in the
README, extract it fully to a local folder and follow the installation guide.
The launchers automatically handle long extraction paths; no manual move
is needed for a fresh installation. Never relocate initialized private state.
“Source code (zip)” is only a source checkout and does not contain runtimes.
No XAMPP is needed for the full bundle; Windows system prerequisites remain.
The supplied package is Windows x64 only; it is not a native macOS installer.

Check that the intended testers can open the private release. Resolve any
repository access problem before sharing the download link.
Enter EAI credentials through the local masked onboarding step, not GitHub.

## 5. Describe what has been tested

State that this is a Windows evaluation package. Manual Stop/Start, backup
and restore, client addition and a manual Windows restart have been checked
on one PC. A company machine still needs its own test.

Keep the remaining limits clear: unattended startup/logoff, public HTTPS,
representative load and storage sizing need validation. Upstream history
depth and some source-value conventions remain unconfirmed. A local PASS
does not establish complete parity with the old provider.

Do not enable production deployment or copy credentials into GitHub Actions
as part of publishing a test release.
