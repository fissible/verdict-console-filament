# Changelog

All notable changes to Verdict Console Filament will be documented in this file.

## [Unreleased]

- **Approval queue Resource (VC-28, #2).** The host-panel queue now renders the console's scoped,
  stably ordered pending approvals through its live approval-item contract, exposes only the
  per-row approve, reject, and close verbs it resolves, and relays each outcome to the core
  resolution service; the queue remains read-only, with no generic resource or bulk mutations.
- **Filament plugin scaffold (VC-27, #1).** Composer package depending on the console core
  (`fissible/verdict-console ^0.5`) and `filament/filament ^5.4` (the oldest Filament 5 minor
  admitting Laravel 13, so the prefer-lowest matrix cell resolves on both supported majors);
  `VerdictConsoleFilamentPlugin` registering into an existing panel -- the host's panel, not one
  of this package's own -- smoke-tested against a Testbench panel; the 24-cell CI matrix.
