# Verdict Console Filament

A Filament plugin for [`fissible/verdict-console`](https://github.com/fissible/verdict-console):
the operator console — approval queue, evidence browser, execution-claim queue, doctor screen, and
anomaly alarms — over the console's headless contracts.

> **Status: the v0.1.0 surfaces are shipped** — approval queue Resource, evidence browser,
> execution-claim queue, console doctor, configuration inspection, and the anomaly-alarms widget.
> Design of record: verdict-console
> [`docs/design/0001-verdict-console-design.md`](https://github.com/fissible/verdict-console/blob/main/docs/design/0001-verdict-console-design.md) §8–§9.

## Installation

```bash
composer require fissible/verdict-console-filament:^0.3
```

Register the plugin into an existing panel — the host's panel, not one of this package's own:

```php
use Fissible\VerdictConsoleFilament\VerdictConsoleFilamentPlugin;

$panel->plugin(VerdictConsoleFilamentPlugin::make());
```

Depends on `fissible/verdict-console` (Packagist). No Filament types may leak into the core package;
the dependency points one way.
