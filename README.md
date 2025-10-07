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
CronRadar::monitor('daily-backup', '0 2 * * *', 300);
```

## Configuration

Set environment variable:
- `CRONRADAR_API_KEY`: Your API key from cronradar.com
- `CRONRADAR_BASE_URL`: Base URL override (optional, default: `https://cronradar.com`)
- `CRONRADAR_METHOD`: HTTP method for ping (optional, default: `GET`)

## Documentation

See [cronradar.com/docs](https://cronradar.com/docs) for full documentation.

## License

Proprietary - © 2025 CronRadar. All rights reserved.
