# CronRadar PHP SDK

The base SDK for monitoring scheduled jobs in PHP. Wrap a callable, send a heartbeat, or track full lifecycle — CronRadar tracks duration, alerts on missed schedules, and recovers from accidental monitor deletion.

For Laravel scheduler auto-discovery, install the `cronradar/laravel` extension on top of this base.

## Install

```bash
composer require cronradar/cronradar
```

PHP 8.1+ supported.

## Setup

The SDK reads its API key from the `CRONRADAR_API_KEY` environment variable.

```bash
export CRONRADAR_API_KEY=ck_app_xxxxxxxxxxxxxxxxxxxx
```

Or in `.env`:

```dotenv
CRONRADAR_API_KEY=ck_app_xxxxxxxxxxxxxxxxxxxx
```

Get the API key from the CronRadar dashboard at [app.cronradar.com](https://app.cronradar.com) under your application's settings. API keys have the form `ck_app_<random>`.

The SDK does not require explicit initialization. The first call reads the env var; if it's missing, the SDK logs a single warning to `error_log` and silently no-ops every subsequent call (per the "never break a user's job" guarantee).

## Quickstart

```php
<?php
use CronRadar\CronRadar;

// First-time call: pass schedule so the monitor auto-registers.
CronRadar::monitor('daily-backup', '0 2 * * *');

// Subsequent calls: just the key.
CronRadar::monitor('daily-backup');
```

That's the minimal integration. CronRadar:

- Creates the `daily-backup` monitor on the first ping (because `schedule` was provided)
- Records each subsequent ping
- Alerts you (email + any other configured channels) if the monitor doesn't ping within `0 2 * * *` plus its grace period
- Re-creates the monitor if you delete it from the dashboard and the schedule param is still being passed

## Manual lifecycle

For finer-grained tracking — duration measurement, explicit failure messages, hung-job detection — use the lifecycle endpoints.

### Wrapper (recommended)

```php
<?php
use CronRadar\CronRadar;

$backupJob = CronRadar::wrap('daily-backup', fn() => runBackup(), '0 2 * * *');
$result = $backupJob();
```

The wrapper:

- Calls `startJob` before invoking your callable
- Calls `completeJob` if it returns
- Calls `failJob` with the exception message if it throws
- Re-throws the original exception so your error handlers run normally
- Auto-registers the monitor on first run if `schedule` is provided

### Manual call style

```php
<?php
use CronRadar\CronRadar;

CronRadar::startJob('daily-backup');
try {
    runBackup();
    CronRadar::completeJob('daily-backup');
} catch (\Throwable $e) {
    CronRadar::failJob('daily-backup', $e->getMessage());
    throw $e;   // always re-throw — monitoring observes, never alters behavior
}
```

## Reference

### `CronRadar::monitor(string $key, ?string $schedule = null, ?int $gracePeriod = null): void`

Records a successful execution.

```php
CronRadar::monitor('daily-backup');
CronRadar::monitor('daily-backup', '0 2 * * *');
CronRadar::monitor('daily-backup', '0 2 * * *', 120);
```

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `$key` | `string` | yes | Monitor key. Lowercase, kebab/snake/dot-case. Max 64 chars. |
| `$schedule` | `?string` | optional | Standard 5-field cron expression. Required on the *first* ping for self-healing registration. |
| `$gracePeriod` | `?int` | optional | Seconds. How late the next ping can be before an alert fires. Default: 60. |

Throws: never. Network errors and 4xx/5xx responses are caught and logged via `error_log`.

### `CronRadar::startJob(string $key): void`

Records that a job has begun executing.

```php
CronRadar::startJob('daily-backup');
```

Used in conjunction with `completeJob` or `failJob` to measure duration and detect hung jobs.

### `CronRadar::completeJob(string $key): void`

Records successful completion.

```php
CronRadar::completeJob('daily-backup');
```

Pair with a prior `startJob($key)` to record duration in the dashboard.

### `CronRadar::failJob(string $key, ?string $message = null): void`

Records explicit failure.

```php
CronRadar::failJob('daily-backup', 'Database connection refused');
```

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `$key` | `string` | yes | Monitor key. |
| `$message` | `?string` | optional | Human-readable failure detail, shown in the dashboard. |

Triggers an immediate alert (no grace period). The message appears in the alert payload.

### `CronRadar::wrap(string $key, callable $fn, ?string $schedule = null, ?int $gracePeriod = null): callable`

Wraps a callable with full lifecycle tracking. Returns a callable that, when invoked, calls `$fn` with start/complete/fail tracking.

```php
$wrapped = CronRadar::wrap('daily-backup', fn() => runBackup(), '0 2 * * *');
$result = $wrapped();
```

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `$key` | `string` | yes | Monitor key. |
| `$fn` | `callable` | yes | The callable to instrument. |
| `$schedule` | `?string` | optional | Cron expression for self-healing registration. |
| `$gracePeriod` | `?int` | optional | Seconds. Default: 60. |

The wrapped callable returns whatever `$fn` returns. If `$fn` throws, the wrapper re-throws after recording the failure. Monitoring never swallows your exceptions.

### `CronRadar::syncMonitor(string $key, string $schedule, ?string $source = null, ?string $name = null): void`

Pre-registers a monitor without sending a ping. Used by the `cronradar/laravel` extension during application startup.

```php
CronRadar::syncMonitor('daily-backup', '0 2 * * *', 'laravel', 'Daily Backup');
```

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `$key` | `string` | yes | Monitor key. |
| `$schedule` | `string` | yes | Cron expression. |
| `$source` | `?string` | optional | Identifier for the framework or origin. |
| `$name` | `?string` | optional | Human-readable display name. |

Used internally by extensions; rarely needed in application code.

## Configuration

| Environment variable | Required | Default | Purpose |
|---|---|---|---|
| `CRONRADAR_API_KEY` | yes | — | API key from the CronRadar dashboard. Format `ck_app_xxxxx`. |
| `CRONRADAR_BASE_URL` | no | `https://cron.life` | Override the ingestion endpoint. Used for self-hosted or staging environments. |
| `CRONRADAR_TIMEOUT` | no | `5` | HTTP request timeout in seconds. |
| `CRONRADAR_LOG_ERRORS` | no | `1` | Set to `0` to suppress the `error_log` warnings the SDK emits on network failure. |

## Error handling

The SDK upholds two hard guarantees:

1. **Never throws to user code.** Network errors, timeouts, 4xx/5xx responses, malformed payloads — all caught and logged via `error_log`. A failing CronRadar API must not break a user's cron job.
2. **Re-throws user-job exceptions.** When using `wrap`, the original exception from your callable always propagates. Monitoring observes; it does not change behavior.

What this looks like at runtime:

| Situation | SDK behavior | Your code |
|---|---|---|
| Network unreachable | Logs to error_log; returns | Continues normally |
| 5-second timeout | Logs to error_log; returns | Continues normally |
| 401 Unauthorized | Logs to error_log; returns | Continues normally |
| Wrapped callable throws | Records `failJob`; re-throws | Receives the original exception |
| `CRONRADAR_API_KEY` missing | Logs once per process; subsequent calls no-op | Continues normally |

If you need to know whether a ping succeeded — for example, in tests — every static method returns `void` either way; success is silent. Inspect `error_log` (or php's stderr) or set `CRONRADAR_LOG_ERRORS=1` (default) to see failures.

## Troubleshooting

### `401 Unauthorized` in error_log

Your `CRONRADAR_API_KEY` is wrong or missing. Verify:

```bash
echo $CRONRADAR_API_KEY    # should print ck_app_xxxxx
```

The key must match the one shown on **app.cronradar.com → your application → Settings → API Keys**. Keys are not retrievable after creation — if you lost it, create a new one and rotate.

### `404 Monitor Not Found` on first ping

You called `monitor($key)` without a `$schedule` for a brand-new key. The first ping must include `$schedule` so CronRadar can register the monitor:

```php
CronRadar::monitor('daily-backup', '0 2 * * *');
```

Subsequent pings can omit `$schedule`. If you delete the monitor from the dashboard and the next ping doesn't include `$schedule`, you'll see the same 404.

### My cron expression is rejected

CronRadar uses the standard 5-field POSIX cron format: `minute hour day-of-month month day-of-week`. Six-field expressions (with seconds) and Quartz-style 7-field expressions are not accepted. Common pitfalls:

| Wrong | Right |
|---|---|
| `0 0 2 * * *` (6 fields) | `0 2 * * *` |
| `0 2 ? * MON-FRI` (Quartz `?`) | `0 2 * * 1-5` |

### The job runs but I never see a ping in the dashboard

Three things to check, in order:

1. **API key** — see "401 Unauthorized" above.
2. **Network** — outbound HTTPS to `https://cron.life` may be blocked by firewall.
3. **Process termination** — short-lived CLI scripts using fire-and-forget transports may exit before the HTTP request completes. The default transport is synchronous so this is uncommon.

### Errors are noisy in development

Set `CRONRADAR_LOG_ERRORS=0` in dev environments where you don't have a real API key set:

```bash
CRONRADAR_LOG_ERRORS=0 php my_job.php
```

The SDK still no-ops cleanly; it just stops complaining.

## Links

- **Documentation:** [docs.cronradar.com](https://docs.cronradar.com)
- **Agent-friendly index:** [docs.cronradar.com/llms.txt](https://docs.cronradar.com/llms.txt)
- **OpenAPI spec:** [api.cronradar.com/swagger/v1/swagger.json](https://api.cronradar.com/swagger/v1/swagger.json)
- **Packagist:** [packagist.org/packages/cronradar/cronradar](https://packagist.org/packages/cronradar/cronradar)
- **GitHub:** [github.com/cronradar/cronradar-php](https://github.com/cronradar/cronradar-php)
- **Laravel extension:** [packagist.org/packages/cronradar/laravel](https://packagist.org/packages/cronradar/laravel)
- **Support:** support@cronradar.com

## License

© 2026 [CronRadar](https://cronradar.com) · Proprietary — see [LICENSE](./LICENSE).
