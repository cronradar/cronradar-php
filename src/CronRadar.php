<?php

namespace CronRadar;

/**
 * Dead-simple cron job monitoring with auto-registration support.
 * Primary function: monitor() for execution confirmation. Advanced: sync() for pre-registration.
 */
class CronRadar
{
    /**
     * Monitor a job execution by recording it in CronRadar.
     * Optionally provide schedule for self-healing (auto-registration on 404).
     *
     * @param string $monitorKey The monitor key identifying your job
     * @param string|null $schedule Optional cron schedule for auto-registration if monitor doesn't exist
     * @return void
     */
    public static function monitor(string $monitorKey, ?string $schedule = null): void
    {
        try {
            $apiKey = getenv('CRONRADAR_API_KEY') ?: '';
            if (empty($apiKey)) {
                error_log("[CronRadar] Warning: CRONRADAR_API_KEY environment variable not set. Monitor '{$monitorKey}' will not be tracked.");
                return;
            }

            // Try to ping
            $response = self::sendPingInternal($monitorKey, $apiKey);

            // Self-healing: if monitor doesn't exist and schedule provided, create it
            if ($response && $response['status'] === 404 && !empty($schedule)) {
                error_log("[CronRadar] Monitor '{$monitorKey}' not found. Auto-registering with schedule '{$schedule}'...");

                $source = self::detectSource();
                self::syncMonitor($monitorKey, $schedule, $source);

                // Retry ping
                self::sendPingInternal($monitorKey, $apiKey);
            }
        } catch (\Throwable $e) {
            // Never throw - protect user's job execution
            // Optionally log for debugging
            error_log("[CronRadar] Error during ping: {$e->getMessage()}");
        }
    }

    /**
     * Signal that a job has started executing.
     * Used for lifecycle tracking to detect hung jobs and measure execution duration.
     *
     * @param string $monitorKey The monitor key identifying your job
     * @param string|null $schedule Optional cron schedule for auto-registration
     * @return void
     */
    public static function startJob(string $monitorKey, ?string $schedule = null): void
    {
        try {
            $apiKey = getenv('CRONRADAR_API_KEY') ?: '';
            if (empty($apiKey)) return;

            self::sendLifecyclePing($monitorKey, 'start', $apiKey, $schedule);
        } catch (\Throwable $e) {
            error_log("[CronRadar] Error during startJob: {$e->getMessage()}");
        }
    }

    /**
     * Signal that a job has completed successfully.
     * Used for lifecycle tracking to record completion time and calculate duration.
     *
     * @param string $monitorKey The monitor key identifying your job
     * @return void
     */
    public static function completeJob(string $monitorKey): void
    {
        try {
            $apiKey = getenv('CRONRADAR_API_KEY') ?: '';
            if (empty($apiKey)) return;

            self::sendLifecyclePing($monitorKey, 'complete', $apiKey);
        } catch (\Throwable $e) {
            error_log("[CronRadar] Error during completeJob: {$e->getMessage()}");
        }
    }

    /**
     * Signal that a job has failed.
     * Triggers immediate alerts without waiting for grace period.
     *
     * @param string $monitorKey The monitor key identifying your job
     * @param string|null $message Optional failure message for debugging
     * @return void
     */
    public static function failJob(string $monitorKey, ?string $message = null): void
    {
        try {
            $apiKey = getenv('CRONRADAR_API_KEY') ?: '';
            if (empty($apiKey)) return;

            self::sendLifecyclePing($monitorKey, 'fail', $apiKey, null, $message);
        } catch (\Throwable $e) {
            error_log("[CronRadar] Error during failJob: {$e->getMessage()}");
        }
    }

    /**
     * Wrap a callable with automatic lifecycle monitoring.
     * Handles start/complete/fail signals automatically.
     *
     * Example:
     *   $backupJob = CronRadar::wrap('backup-job', fn() => runBackup());
     *   $backupJob();
     *
     * @param string $monitorKey The monitor key identifying your job
     * @param callable $func The function to wrap
     * @param string|null $schedule Optional cron schedule for auto-registration
     * @return callable Wrapped function
     */
    public static function wrap(string $monitorKey, callable $func, ?string $schedule = null): callable
    {
        return function(...$args) use ($monitorKey, $func, $schedule) {
            self::startJob($monitorKey, $schedule);
            try {
                $result = $func(...$args);
                self::completeJob($monitorKey);
                return $result;
            } catch (\Throwable $e) {
                self::failJob($monitorKey, $e->getMessage());
                throw $e; // Re-throw to preserve original behavior
            }
        };
    }

    /**
     * Pre-register a monitor with CronRadar, setting up expectations for when it should run.
     * Used internally by extensions to sync discovered jobs. Advanced usage only.
     *
     * @param string $monitorKey The unique identifier for this monitor
     * @param string $schedule Cron expression defining when the job runs
     * @param string|null $source Source framework (e.g., "hangfire", "laravel"). Auto-detected if null.
     * @param string|null $name Human-readable name. Generated from key if null.
     * @return void
     */
    public static function syncMonitor(
        string $monitorKey,
        string $schedule,
        ?string $source = null,
        ?string $name = null
    ): void {
        try {
            $apiKey = getenv('CRONRADAR_API_KEY') ?: '';
            if (empty($apiKey)) {
                error_log("[CronRadar] Warning: CRONRADAR_API_KEY environment variable not set. Monitor '{$monitorKey}' will not be synced.");
                return;
            }

            $source = $source ?? self::detectSource();
            $name = $name ?? self::generateReadableName($monitorKey);

            $syncRequest = [
                'source' => $source,
                'monitors' => [
                    [
                        'key' => $monitorKey,
                        'name' => $name,
                        'schedule' => $schedule,
                        'gracePeriod' => 60
                    ]
                ]
            ];

            $url = 'https://cron.life/api/sync';

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($syncRequest));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($apiKey . ':')
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 300) {
                error_log("[CronRadar] Monitor '{$monitorKey}' synced successfully.");
            } else {
                error_log("[CronRadar] Failed to sync monitor '{$monitorKey}': HTTP {$httpCode}");
            }
        } catch (\Throwable $e) {
            error_log("[CronRadar] Error syncing monitor '{$monitorKey}': {$e->getMessage()}");
            // Never throw - protect user's job execution
        }
    }

    /**
     * Send ping internally
     *
     * @param string $monitorKey
     * @param string $apiKey
     * @return array|null
     */
    private static function sendPingInternal(string $monitorKey, string $apiKey): ?array
    {
        try {
            // URL-based auth: /ping/{monitorKey}/{apiKey}
            $url = 'https://cron.life/ping/' . urlencode($monitorKey) . '/' . urlencode($apiKey);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return ['status' => $httpCode, 'body' => $response];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Send lifecycle ping (start/complete/fail) internally
     *
     * @param string $monitorKey
     * @param string $endpoint
     * @param string $apiKey
     * @param string|null $schedule
     * @param string|null $message
     * @return void
     */
    private static function sendLifecyclePing(
        string $monitorKey,
        string $endpoint,
        string $apiKey,
        ?string $schedule = null,
        ?string $message = null
    ): void {
        try {
            // URL-based auth: /ping/{monitorKey}/{apiKey}/{endpoint}
            $url = 'https://cron.life/ping/' . urlencode($monitorKey) . '/' . urlencode($apiKey) . '/' . $endpoint;

            // Add query parameters
            $params = [];
            if (!empty($schedule)) {
                $params[] = 'schedule=' . urlencode($schedule);
            }
            if (!empty($message)) {
                $params[] = 'message=' . urlencode($message);
            }

            if (!empty($params)) {
                $url .= '?' . implode('&', $params);
            }

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);

            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable $e) {
            // Silently fail to protect job execution
        }
    }

    /**
     * Detect the calling source/framework based on stack trace and loaded classes.
     *
     * @return string
     */
    private static function detectSource(): string
    {
        try {
            // Check for Laravel
            if (function_exists('app') && class_exists('Illuminate\Foundation\Application')) {
                return 'laravel';
            }

            // Check for Symfony
            if (class_exists('Symfony\Component\HttpKernel\Kernel')) {
                return 'symfony';
            }

            // Check for CodeIgniter
            if (defined('BASEPATH') || class_exists('CodeIgniter\CodeIgniter')) {
                return 'codeigniter';
            }

            // Check stack trace
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            foreach ($backtrace as $frame) {
                if (isset($frame['class'])) {
                    $class = strtolower($frame['class']);
                    if (strpos($class, 'illuminate') !== false) return 'laravel-direct';
                    if (strpos($class, 'symfony') !== false) return 'symfony-direct';
                }
            }
        } catch (\Throwable $e) {
            // If detection fails, fall back to manual
        }

        return 'manual';
    }

    /**
     * Generate a human-readable name from a monitor key.
     * Converts kebab-case to Title Case.
     *
     * @param string $monitorKey
     * @return string
     */
    private static function generateReadableName(string $monitorKey): string
    {
        if (empty($monitorKey)) {
            return $monitorKey;
        }

        // Handle kebab-case: "check-overdue-pings" -> "Check Overdue Pings"
        if (strpos($monitorKey, '-') !== false) {
            return implode(' ', array_map('ucfirst', explode('-', strtolower($monitorKey))));
        }

        // Handle snake_case: "check_overdue_pings" -> "Check Overdue Pings"
        if (strpos($monitorKey, '_') !== false) {
            return implode(' ', array_map('ucfirst', explode('_', strtolower($monitorKey))));
        }

        // Handle PascalCase: "CheckOverduePings" -> "Check Overdue Pings"
        if (ctype_upper($monitorKey[0])) {
            return preg_replace('/(?<!^)([A-Z])/', ' $1', $monitorKey);
        }

        // Default: just capitalize first letter
        return ucfirst($monitorKey);
    }

}
