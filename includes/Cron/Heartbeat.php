<?php
namespace MyShop\LicenseServer\Cron;

use MyShop\LicenseServer\Domain\Services\RateLimiter;
use MyShop\LicenseServer\Domain\Security\SecurityUtils;
use function MyShop\LicenseServer\lsr;

/**
 * Scheduled tasks for License Server maintenance and security cleanup.
 */
class Heartbeat
{
    /**
     * Initialize cron schedules and hooks.
     */
    public static function init(): void
    {
        // Register custom cron schedules
        add_filter('cron_schedules', [self::class, 'addCustomSchedules']);
        
        // Register cron events
        if (!wp_next_scheduled('lsr_security_cleanup')) {
            wp_schedule_event(time() + 300, 'hourly', 'lsr_security_cleanup');
        }
        
        if (!wp_next_scheduled('lsr_daily_maintenance')) {
            wp_schedule_event(time() + 600, 'daily', 'lsr_daily_maintenance');
        }
        
        if (!wp_next_scheduled('lsr_weekly_security_report')) {
            wp_schedule_event(time() + 900, 'weekly', 'lsr_weekly_security_report');
        }
        
        // Hook cron events to methods
        add_action('lsr_security_cleanup', [self::class, 'securityCleanup']);
        add_action('lsr_daily_maintenance', [self::class, 'dailyMaintenance']);
        add_action('lsr_weekly_security_report', [self::class, 'weeklySecurityReport']);
        
        // Legacy cron event for backward compatibility
        add_action('lsr_cron_heartbeat', [self::class, 'legacyHeartbeat']);
    }

    /**
     * Add custom cron schedules.
     *
     * @param array $schedules
     * @return array
     */
    public static function addCustomSchedules(array $schedules): array
    {
        $schedules['every_15_minutes'] = [
            'interval' => 900, // 15 minutes
            'display'  => __('Every 15 Minutes', 'license-server')
        ];
        
        $schedules['weekly'] = [
            'interval' => 604800, // 7 days
            'display'  => __('Weekly', 'license-server')
        ];
        
        return $schedules;
    }

    /**
     * Hourly security cleanup tasks.
     */
    public static function securityCleanup(): void
    {
        try {
            $startTime = microtime(true);
            $cleanupStats = [
                'rate_limits_cleaned' => 0,
                'security_events_cleaned' => 0,
                'failed_attempts_cleaned' => 0,
                'expired_blocks_cleaned' => 0
            ];

            // 1. Clean up rate limiting data
            /** @var RateLimiter $rateLimiter */
            $rateLimiter = lsr(RateLimiter::class);
            if ($rateLimiter) {
                $rateLimiter->cleanup();
                $cleanupStats['rate_limits_cleaned'] = 1;
            }

            // 2. Clean up old security events (keep only last 30 days)
            $cleanupStats['security_events_cleaned'] = self::cleanupSecurityEvents();

            // 3. Clean up expired failed attempts
            $cleanupStats['failed_attempts_cleaned'] = self::cleanupFailedAttempts();

            // 4. Clean up expired IP blocks
            $cleanupStats['expired_blocks_cleaned'] = self::cleanupExpiredBlocks();

            // 5. Clean up old API event logs (keep only last 7 days)
            self::cleanupApiEvents();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            self::log('security_cleanup_completed', [
                'duration_ms' => $duration,
                'stats' => $cleanupStats
            ]);

        } catch (\Exception $e) {
            self::log('security_cleanup_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Daily maintenance tasks.
     */
    public static function dailyMaintenance(): void
    {
        try {
            $startTime = microtime(true);
            $maintenanceStats = [];

            // 1. Update license statuses based on subscriptions
            $maintenanceStats['licenses_synced'] = self::syncLicenseStatuses();

            // 2. Clean up orphaned activations (licenses that don't exist)
            $maintenanceStats['orphaned_activations_cleaned'] = self::cleanupOrphanedActivations();

            // 3. Generate security statistics
            $maintenanceStats['security_stats'] = SecurityUtils::getSecurityStats();

            // 4. Check for suspicious patterns
            $maintenanceStats['suspicious_patterns'] = self::checkSuspiciousPatterns();

            // 5. Optimize database tables
            $maintenanceStats['tables_optimized'] = self::optimizeDatabaseTables();

            // 6. Clean up old transients
            $maintenanceStats['transients_cleaned'] = self::cleanupOldTransients();

            $endTime = microtime(true);
            $duration = round(($endTime - $startTime) * 1000, 2);

            self::log('daily_maintenance_completed', [
                'duration_ms' => $duration,
                'stats' => $maintenanceStats
            ]);

            // Update last maintenance timestamp
            update_option('lsr_last_maintenance', time());

        } catch (\Exception $e) {
            self::log('daily_maintenance_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Weekly security report generation.
     */
    public static function weeklySecurityReport(): void
    {
        if (!get_option('lsr_enable_security_reports', false)) {
            return;
        }

        try {
            $report = self::generateSecurityReport();
            
            // Email report to admin if enabled
            if (get_option('lsr_email_security_reports', false)) {
                self::emailSecurityReport($report);
            }

            // Store report in database
            self::storeSecurityReport($report);

            self::log('security_report_generated', [
                'report_date' => date('Y-m-d'),
                'total_events' => $report['total_events'],
                'blocked_ips' => count($report['blocked_ips'])
            ]);

        } catch (\Exception $e) {
            self::log('security_report_error', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Legacy heartbeat for backward compatibility.
     */
    public static function legacyHeartbeat(): void
    {
        // Call security cleanup for backward compatibility
        self::securityCleanup();
    }

    /**
     * Clean up old security events.
     *
     * @return int Number of events cleaned
     */
    private static function cleanupSecurityEvents(): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return 0;
        }

        $cutoffDate = date('Y-m-d H:i:s', time() - (30 * 24 * 60 * 60)); // 30 days ago

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoffDate
            )
        );

        return (int) $deleted;
    }

    /**
     * Clean up expired failed attempts.
     *
     * @return int Number of attempts cleaned
     */
    private static function cleanupFailedAttempts(): int
    {
        global $wpdb;
        
        // Get all failed attempt transients
        $pattern = '_transient_lsr_failed_attempts_%';
        $transients = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ),
            ARRAY_A
        );

        $cleaned = 0;
        foreach ($transients as $transient) {
            $optionName = $transient['option_name'];
            $transientName = str_replace('_transient_', '', $optionName);
            
            // Check if transient is expired
            if (get_transient($transientName) === false) {
                delete_option($optionName);
                delete_option('_transient_timeout_' . $transientName);
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Clean up expired IP blocks.
     *
     * @return int Number of blocks cleaned
     */
    private static function cleanupExpiredBlocks(): int
    {
        // For now, manual blocks don't expire
        // This could be extended to support temporary blocks
        return 0;
    }

    /**
     * Clean up old API events.
     */
    private static function cleanupApiEvents(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_api_events';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $cutoffDate = date('Y-m-d H:i:s', time() - (7 * 24 * 60 * 60)); // 7 days ago

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s",
                $cutoffDate
            )
        );
    }

    /**
     * Sync license statuses with WooCommerce subscriptions.
     *
     * @return int Number of licenses synced
     */
    private static function syncLicenseStatuses(): int
    {
        if (!class_exists('WC_Subscriptions')) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'lsr_licenses';

        // Get all licenses with subscription IDs
        $licenses = $wpdb->get_results(
            "SELECT id, subscription_id, status FROM {$table} WHERE subscription_id IS NOT NULL",
            ARRAY_A
        );

        $synced = 0;
        foreach ($licenses as $license) {
            $subscription = wcs_get_subscription($license['subscription_id']);
            if (!$subscription) {
                continue;
            }

            $subStatus = $subscription->get_status();
            $expectedStatus = in_array($subStatus, ['active', 'pending-cancel']) ? 'active' : 'inactive';

            if ($license['status'] !== $expectedStatus) {
                $wpdb->update(
                    $table,
                    ['status' => $expectedStatus],
                    ['id' => $license['id']]
                );
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Clean up orphaned activations.
     *
     * @return int Number of orphaned activations cleaned
     */
    private static function cleanupOrphanedActivations(): int
    {
        global $wpdb;
        
        $activationsTable = $wpdb->prefix . 'lsr_activations';
        $licensesTable = $wpdb->prefix . 'lsr_licenses';

        $deleted = $wpdb->query("
            DELETE a FROM {$activationsTable} a
            LEFT JOIN {$licensesTable} l ON a.license_id = l.id
            WHERE l.id IS NULL
        ");

        return (int) $deleted;
    }

    /**
     * Check for suspicious patterns in recent events.
     *
     * @return array Suspicious patterns found
     */
    private static function checkSuspiciousPatterns(): array
    {
        global $wpdb;
        $patterns = [];

        // Check for IPs with many different license keys
        $suspiciousIps = $wpdb->get_results("
            SELECT ip, COUNT(DISTINCT data->'$.license_key_hash') as unique_keys
            FROM {$wpdb->prefix}lsr_api_events 
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND ip IS NOT NULL
            GROUP BY ip
            HAVING unique_keys > 5
        ", ARRAY_A);

        if ($suspiciousIps) {
            $patterns['suspicious_ips'] = $suspiciousIps;
        }

        // Check for domains with many activations
        // This would require parsing the JSON data, simplified for now
        
        return $patterns;
    }

    /**
     * Optimize database tables.
     *
     * @return int Number of tables optimized
     */
    private static function optimizeDatabaseTables(): int
    {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'lsr_licenses',
            $wpdb->prefix . 'lsr_activations', 
            $wpdb->prefix . 'lsr_releases',
            $wpdb->prefix . 'lsr_security_events',
            $wpdb->prefix . 'lsr_api_events'
        ];

        $optimized = 0;
        foreach ($tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
                $wpdb->query("OPTIMIZE TABLE {$table}");
                $optimized++;
            }
        }

        return $optimized;
    }

    /**
     * Clean up old WordPress transients.
     *
     * @return int Number of transients cleaned
     */
    private static function cleanupOldTransients(): int
    {
        global $wpdb;
        
        // Clean up expired transients (WordPress doesn't do this automatically)
        $deleted = $wpdb->query("
            DELETE a, b FROM {$wpdb->options} a, {$wpdb->options} b
            WHERE a.option_name LIKE '_transient_%'
            AND a.option_name NOT LIKE '_transient_timeout_%'
            AND b.option_name = CONCAT('_transient_timeout_', SUBSTRING(a.option_name, 12))
            AND b.option_value < UNIX_TIMESTAMP()
        ");

        return (int) $deleted;
    }

    /**
     * Generate comprehensive security report.
     *
     * @return array Security report data
     */
    private static function generateSecurityReport(): array
    {
        global $wpdb;
        
        $report = [
            'generated_at' => current_time('mysql', 1),
            'period_start' => date('Y-m-d H:i:s', time() - (7 * 24 * 60 * 60)),
            'period_end' => current_time('mysql', 1),
            'total_events' => 0,
            'blocked_ips' => get_option('lsr_blocked_ips', []),
            'top_events' => [],
            'security_stats' => SecurityUtils::getSecurityStats()
        ];

        // Get event statistics if table exists
        $eventsTable = $wpdb->prefix . 'lsr_security_events';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$eventsTable}'") === $eventsTable) {
            $report['total_events'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$eventsTable} WHERE created_at >= %s",
                    $report['period_start']
                )
            );

            $report['top_events'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT event, COUNT(*) as count FROM {$eventsTable} 
                     WHERE created_at >= %s 
                     GROUP BY event 
                     ORDER BY count DESC 
                     LIMIT 10",
                    $report['period_start']
                ),
                ARRAY_A
            );
        }

        return $report;
    }

    /**
     * Email security report to admin.
     *
     * @param array $report
     */
    private static function emailSecurityReport(array $report): void
    {
        $adminEmail = get_option('admin_email');
        $subject = '[' . get_bloginfo('name') . '] Weekly License Server Security Report';
        
        $message = "License Server Security Report\n";
        $message .= "==============================\n\n";
        $message .= "Report Period: " . $report['period_start'] . " to " . $report['period_end'] . "\n\n";
        $message .= "Summary:\n";
        $message .= "- Total Security Events: " . $report['total_events'] . "\n";
        $message .= "- Blocked IPs: " . count($report['blocked_ips']) . "\n";
        $message .= "- Failed Attempts (24h): " . $report['security_stats']['failed_attempts_24h'] . "\n\n";
        
        if (!empty($report['top_events'])) {
            $message .= "Top Security Events:\n";
            foreach ($report['top_events'] as $event) {
                $message .= "- {$event['event']}: {$event['count']} times\n";
            }
            $message .= "\n";
        }
        
        if (!empty($report['blocked_ips'])) {
            $message .= "Currently Blocked IPs:\n";
            foreach ($report['blocked_ips'] as $ip) {
                $message .= "- {$ip}\n";
            }
        }
        
        $message .= "\nYou can view detailed reports in your WordPress admin under License Server settings.";
        
        wp_mail($adminEmail, $subject, $message);
    }

    /**
     * Store security report in database.
     *
     * @param array $report
     */
    private static function storeSecurityReport(array $report): void
    {
        update_option('lsr_last_security_report', $report);
    }

    /**
     * Log cron events.
     *
     * @param string $event
     * @param array $data
     */
    private static function log(string $event, array $data = []): void
    {
        if (!get_option('lsr_enable_cron_logging', true)) {
            return;
        }

        $logData = array_merge([
            'component' => 'CronHeartbeat',
            'event' => $event,
            'timestamp' => current_time('mysql', 1)
        ], $data);

        error_log('[License Server Cron] ' . wp_json_encode($logData));
    }

    /**
     * Get cron status and statistics.
     *
     * @return array
     */
    public static function getStatus(): array
    {
        return [
            'last_security_cleanup' => wp_next_scheduled('lsr_security_cleanup'),
            'last_daily_maintenance' => wp_next_scheduled('lsr_daily_maintenance'),
            'last_weekly_report' => wp_next_scheduled('lsr_weekly_security_report'),
            'last_maintenance_run' => get_option('lsr_last_maintenance', 0),
            'cron_enabled' => wp_get_ready_cron_jobs() !== false
        ];
    }
}