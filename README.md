# Verdict Console Filament

A Filament plugin for [`fissible/verdict-console`](https://github.com/fissible/verdict-console):
the operator console — approval queue, evidence browser, execution-claim queue, doctor screen, and
anomaly alarms — over the console's headless contracts.

> **Status: scaffolded, Resources in progress.** The package installs beside the console core, and
> `VerdictConsoleFilamentPlugin::make()` registers into an existing panel
> (`$panel->plugin(...)`). The Resources and ops surfaces are tracked in this repository's
> v0.1.0 milestone. Design of record: verdict-console
> [`docs/design/0001-verdict-console-design.md`](https://github.com/fissible/verdict-console/blob/main/docs/design/0001-verdict-console-design.md) §8–§9.

Depends on `fissible/verdict-console` (Packagist). No Filament types may leak into the core package;
the dependency points one way.
