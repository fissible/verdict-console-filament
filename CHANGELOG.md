# Changelog

All notable changes to Verdict Console Filament will be documented in this file.

## [Unreleased]

- **Require verdict-console `^0.6`.** The bound moves to the current minor per the standing
  prefer-lowest reasoning; 0.6.0's only change is the Verdict `^0.14` floor, which reaches this
  package solely through the console.
- **Ops surfaces (VC-30, #4).** Execution-claim reconciliation, console doctor, configuration
  inspection, and anomaly alarms render the console's claim, doctor, configuration, and incident
  boundaries in the host panel; claim resolution runs through the claim service with the operator's
  authority and a required reason.
- **Evidence browser page (VC-29, #3).** The host-panel browser renders the console's replaceable
  evidence read boundary as a read-only custom-data table, carries disposition and capability
  filters through that boundary, states whether recording is off, elsewhere, or merely empty, and
  exposes the returned record's digest, claim type, invocation ID, and fingerprints on demand.
- **Approval queue Resource (VC-28, #2).** The host-panel queue now renders the console's scoped,
  stably ordered pending approvals through its live approval-item contract, exposes only the
  per-row approve, reject, and close verbs it resolves, and relays each outcome to the core
  resolution service; the queue remains read-only, with no generic resource or bulk mutations.
- **Filament plugin scaffold (VC-27, #1).** Composer package depending on the console core
  (`fissible/verdict-console ^0.5`) and `filament/filament ^5.4` (the oldest Filament 5 minor
  admitting Laravel 13, so the prefer-lowest matrix cell resolves on both supported majors);
  `VerdictConsoleFilamentPlugin` registering into an existing panel -- the host's panel, not one
  of this package's own -- smoke-tested against a Testbench panel; the 24-cell CI matrix.
