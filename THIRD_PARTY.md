# Third-party material

The source checkout does not include third-party executables or the proprietary
EAI SDK runtime. It contains application code that integrates with the approved
SDK, plus recorded runtime hashes and retained licence/provenance documents.

The separate internal Windows bundle includes Node.js, PHP, MySQL, Microsoft
prerequisite installers and the supplied EAI SDK. Its full notices and MySQL
corresponding source must stay with that binary distribution. See the retained
`Licences/NOTICE.md` for the bundle's component inventory.

This preparation does not grant an open-source licence for company code or
public redistribution rights for CarrierWeb software. Confirm the hosting and
distribution audience with the company before publication. Preserve original
vendor notices; do not replace them with a blanket project licence.

The source repository and runtime release have different inventories. Missing
runtime binaries in a clone are intentional, not a suggestion to download an
arbitrary SDK or copy system DLLs from another workstation.
