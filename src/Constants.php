<?php

namespace CronRadar;

/**
 * Centralized constants for the CronRadar SDK.
 * Mirrors the .NET CronRadarConstants for cross-SDK consistency.
 */
final class Constants
{
    /** CronRadar API base URL. */
    public const BASE_URL = 'https://cron.life';

    /** HTTP request timeout in seconds. */
    public const DEFAULT_TIMEOUT_SECONDS = 5;

    /** Default grace period in seconds before alerting on missed runs. */
    public const DEFAULT_GRACE_PERIOD_SECONDS = 60;

    /** Maximum allowed length of a monitor key. */
    public const MAX_MONITOR_KEY_LENGTH = 200;

    /** Environment variable for the API key. */
    public const API_KEY_ENV_VAR = 'CRONRADAR_API_KEY';

    /** Environment variable for enabling debug logging. */
    public const DEBUG_ENV_VAR = 'CRONRADAR_DEBUG';

    /**
     * Validate a monitor key. Returns true if valid; logs a warning and returns false otherwise.
     */
    public static function isValidMonitorKey(?string $monitorKey): bool
    {
        if ($monitorKey === null || trim($monitorKey) === '') {
            error_log('[CronRadar] Invalid monitor key: must not be null or empty.');
            return false;
        }
        if (strlen($monitorKey) > self::MAX_MONITOR_KEY_LENGTH) {
            error_log(sprintf(
                '[CronRadar] Invalid monitor key: length %d exceeds maximum of %d characters.',
                strlen($monitorKey),
                self::MAX_MONITOR_KEY_LENGTH
            ));
            return false;
        }
        return true;
    }
}
