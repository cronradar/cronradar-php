# CronRadar PHP SDK

Monitor cron jobs with one function call.

## Installation

```bash
composer require cronradar/php
```

## Usage

```php
<?php

use CronRadar\CronRadar;

// After your cron job completes successfully
CronRadar::monitor('daily-backup');

// With self-healing (auto-register if monitor doesn't exist)
CronRadar::monitor('daily-backup', '0 2 * * *');
```

## Lifecycle Tracking

**Option 1: Wrapper (Automatic)**
```php
$backupJob = CronRadar::wrap('daily-backup', fn() => runBackup(), '0 2 * * *');
$backupJob();
```

**Option 2: Manual**
```php
CronRadar::startJob('daily-backup');
try {
    runBackup();
    CronRadar::completeJob('daily-backup');
} catch (Exception $e) {
    CronRadar::failJob('daily-backup', $e->getMessage());
    throw $e;
}
```

## Configuration

Set environment variable:
- `CRONRADAR_API_KEY`: Your API key from cronradar.com

## Documentation

See [cronradar.com/docs](https://cronradar.com/docs) for full documentation.

## License

Proprietary - © 2025 CronRadar. All rights reserved.
