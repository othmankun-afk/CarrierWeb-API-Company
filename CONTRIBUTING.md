# Contributing

Keep changes small enough to review against the contract and a named test.
Separate source/semantic changes from packaging and operator-text changes.

Before opening a pull request:

1. Read the current contract and project status.
2. Check that the diff contains no credentials, real client URLs, captures,
   databases, personal notes or operational state.
3. Explain which behavior changes and which old behavior must remain.
4. Run the affected tests on an isolated package; retain the actual result.
5. State what was not tested, especially live EAI, another machine, reboot,
   source-history completeness and provider-specific sensor semantics.

Review cursor, quota and sensor-channel changes against the API contract.
Run their regression tests in a separate test instance and retain the results.

Use fictitious data for ordinary regression work. Real EAI requests need
separate coordination with the account owner. Keep supported application
languages and dependencies within the company's agreed constraints.

For a bug report, include the endpoint, source revision, UTC interval, error
category and reproduction steps. Remove passwords from URLs and screenshots;
do not attach customer data, access files or local configuration. Read
`Documentation/DEVELOPMENT.md` before assembling a runtime package.
