<?php
namespace MyShop\LicenseServer\Admin\Screens;

use MyShop\LicenseServer\Domain\Services\RateLimiter;
use MyShop\LicenseServer\Domain\Security\SecurityUtils;
use MyShop\LicenseServer\API\Middleware;
use MyShop\LicenseServer\Cron\Heartbeat;
use function MyShop\LicenseServer\lsr;

/**
 * Security dashboard and management screen.
 */
class SecurityScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'license-server'));
        }

        $message = '';
        $activeTab = $_GET['tab'] ?? 'overview';

        // Handle form submissions
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $message = self::handleFormSubmissions();
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('License Server Security', 'license-server') . '</h1>';

        if ($message) {
            echo $message;
        }

        // Render tabs
        self::renderTabs($activeTab);

        // Render tab content
        switch ($activeTab) {
            case 'blocked-ips':
                self::renderBlockedIpsTab();
                break;
            case 'events':
                self::renderSecurityEventsTab();
                break;
            case 'settings':
                self::renderSecuritySettingsTab();
                break;
            case 'reports':
                self::renderReportsTab();
                break;
            default:
                self::renderOverviewTab();
                break;
        }

        echo '</div>';
    }

    /**
     * Handle form submissions from various tabs.
     *
     * @return string Message to display
     */
    private static function handleFormSubmissions(): string
    {
        if (!isset($_POST['lsr_security_nonce']) || !wp_verify_nonce($_POST['lsr_security_nonce'], 'lsr_security_action')) {
            return '<div class="notice notice-error"><p>' . esc_html__('Security verification failed.', 'license-server') . '</p></div>';
        }

        $action = $_POST['action'] ?? '';

        switch ($action) {
            case 'block_ip':
                return self::handleBlockIp();
            case 'unblock_ip':
                return self::handleUnblockIp();
            case 'clear_events':
                return self::handleClearEvents();
            case 'save_settings':
                return self::handleSaveSettings();
            case 'generate_report':
                return self::handleGenerateReport();
            default:
                return '<div class="notice notice-error"><p>' . esc_html__('Unknown action.', 'license-server') . '</p></div>';
        }
    }

    /**
     * Render navigation tabs.
     *
     * @param string $activeTab
     */
    private static function renderTabs(string $activeTab): void
    {
        $tabs = [
            'overview' => __('Overview', 'license-server'),
            'blocked-ips' => __('Blocked IPs', 'license-server'),
            'events' => __('Security Events', 'license-server'),
            'settings' => __('Settings', 'license-server'),
            'reports' => __('Reports', 'license-server')
        ];

        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $tab => $label) {
            $class = $activeTab === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
            $url = admin_url('admin.php?page=lsr-security&tab=' . $tab);
            echo '<a href="' . esc_url($url) . '" class="' . esc_attr($class) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    /**
     * Render overview tab with security statistics.
     */
    private static function renderOverviewTab(): void
    {
        $stats = SecurityUtils::getSecurityStats();
        $rateLimiter = lsr(RateLimiter::class);
        $rateLimiterStats = $rateLimiter ? $rateLimiter->getGlobalStats() : [];
        $cronStatus = Heartbeat::getStatus();

        echo '<div class="lsr-security-overview">';
        
        // Security Statistics Cards
        echo '<div class="lsr-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">';
        
        self::renderStatCard(__('Blocked IPs', 'license-server'), $stats['blocked_ips'], 'dashicons-shield-alt', 'red');
        self::renderStatCard(__('Failed Attempts (24h)', 'license-server'), $stats['failed_attempts_24h'], 'dashicons-warning', 'orange');
        self::renderStatCard(__('Security Events (24h)', 'license-server'), $stats['security_events_24h'], 'dashicons-visibility', 'blue');
        self::renderStatCard(__('Active Rate Limits', 'license-server'), $rateLimiterStats['active_rate_limits'] ?? 0, 'dashicons-clock', 'green');
        
        echo '</div>';

        // Recent Security Events
        echo '<div class="lsr-recent-events">';
        echo '<h3>' . esc_html__('Recent Security Events', 'license-server') . '</h3>';
        self::renderRecentEvents();
        echo '</div>';

        // System Status
        echo '<div class="lsr-system-status">';
        echo '<h3>' . esc_html__('System Status', 'license-server') . '</h3>';
        self::renderSystemStatus($cronStatus);
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render blocked IPs management tab.
     */
    private static function renderBlockedIpsTab(): void
    {
        $blockedIps = Middleware::getBlockedIps();

        echo '<div class="lsr-blocked-ips">';
        
        // Add IP form
        echo '<div class="lsr-add-ip-form">';
        echo '<h3>' . esc_html__('Block IP Address', 'license-server') . '</h3>';
        echo '<form method="post" style="display: flex; gap: 10px; align-items: end;">';
        wp_nonce_field('lsr_security_action', 'lsr_security_nonce');
        echo '<input type="hidden" name="action" value="block_ip">';
        echo '<div>';
        echo '<label for="ip_address">' . esc_html__('IP Address:', 'license-server') . '</label><br>';
        echo '<input type="text" id="ip_address" name="ip_address" pattern="^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$" required style="width: 200px;">';
        echo '</div>';
        echo '<div>';
        echo '<label for="reason">' . esc_html__('Reason:', 'license-server') . '</label><br>';
        echo '<input type="text" id="reason" name="reason" placeholder="Manual block" style="width: 300px;">';
        echo '</div>';
        echo '<input type="submit" class="button button-primary" value="' . esc_attr__('Block IP', 'license-server') . '">';
        echo '</form>';
        echo '</div>';

        // Blocked IPs list
        echo '<div class="lsr-blocked-list">';
        echo '<h3>' . esc_html__('Currently Blocked IPs', 'license-server') . '</h3>';
        
        if (empty($blockedIps)) {
            echo '<p>' . esc_html__('No IPs are currently blocked.', 'license-server') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('IP Address', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Block Date', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Recent Events', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Actions', 'license-server') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($blockedIps as $ip) {
                echo '<tr>';
                echo '<td><code>' . esc_html($ip) . '</code></td>';
                echo '<td>' . self::getIpBlockDate($ip) . '</td>';
                echo '<td>' . self::getIpEventCount($ip) . '</td>';
                echo '<td>';
                echo '<form method="post" style="display: inline;">';
                wp_nonce_field('lsr_security_action', 'lsr_security_nonce');
                echo '<input type="hidden" name="action" value="unblock_ip">';
                echo '<input type="hidden" name="ip_address" value="' . esc_attr($ip) . '">';
                echo '<input type="submit" class="button button-small" value="' . esc_attr__('Unblock', 'license-server') . '" onclick="return confirm(\'' . esc_js__('Are you sure you want to unblock this IP?', 'license-server') . '\')">';
                echo '</form>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        echo '</div>';

        echo '</div>';
    }

    /**
     * Render security events tab.
     */
    private static function renderSecurityEventsTab(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';

        echo '<div class="lsr-security-events">';
        
        // Clear events form
        echo '<div class="lsr-clear-events">';
        echo '<form method="post" style="float: right;">';
        wp_nonce_field('lsr_security_action', 'lsr_security_nonce');
        echo '<input type="hidden" name="action" value="clear_events">';
        echo '<input type="submit" class="button" value="' . esc_attr__('Clear Old Events', 'license-server') . '" onclick="return confirm(\'' . esc_js__('This will delete events older than 30 days. Continue?', 'license-server') . '\')">';
        echo '</form>';
        echo '</div>';

        echo '<h3>' . esc_html__('Recent Security Events', 'license-server') . '</h3>';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            echo '<p>' . esc_html__('Security events table not found. Events will appear here after the first security incident.', 'license-server') . '</p>';
            echo '</div>';
            return;
        }

        // Get recent events
        $events = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50",
            ARRAY_A
        );

        if (empty($events)) {
            echo '<p>' . esc_html__('No security events recorded yet.', 'license-server') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('Date', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Event', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Severity', 'license-server') . '</th>';
            echo '<th>' . esc_html__('IP Address', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Endpoint', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Details', 'license-server') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($events as $event) {
                echo '<tr>';
                echo '<td>' . esc_html(date('Y-m-d H:i:s', strtotime($event['created_at']))) . '</td>';
                echo '<td><code>' . esc_html($event['event']) . '</code></td>';
                echo '<td><span class="lsr-severity lsr-severity-' . esc_attr($event['severity']) . '">' . esc_html($event['severity']) . '</span></td>';
                echo '<td><code>' . esc_html($event['ip']) . '</code></td>';
                echo '<td>' . esc_html($event['endpoint'] ?: '-') . '</td>';
                echo '<td>';
                if (!empty($event['data'])) {
                    echo '<details><summary>' . esc_html__('Show Details', 'license-server') . '</summary>';
                    echo '<pre style="font-size: 11px; max-width: 400px; overflow-x: auto;">' . esc_html(wp_json_encode(json_decode($event['data']), JSON_PRETTY_PRINT)) . '</pre>';
                    echo '</details>';
                } else {
                    echo '-';
                }
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }

        echo '</div>';
        
        // Add CSS for severity indicators
        echo '<style>
        .lsr-severity { padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .lsr-severity-low { background: #d1ecf1; color: #0c5460; }
        .lsr-severity-medium { background: #fff3cd; color: #856404; }
        .lsr-severity-high { background: #f8d7da; color: #721c24; }
        .lsr-severity-critical { background: #721c24; color: white; }
        </style>';
    }

    /**
     * Render security settings tab.
     */
    private static function renderSecuritySettingsTab(): void
    {
        echo '<div class="lsr-security-settings">';
        echo '<form method="post">';
        wp_nonce_field('lsr_security_action', 'lsr_security_nonce');
        echo '<input type="hidden" name="action" value="save_settings">';
        
        echo '<table class="form-table">';
        
        // Rate limiting settings
        echo '<tr><th colspan="2"><h3>' . esc_html__('Rate Limiting', 'license-server') . '</h3></th></tr>';
        
        echo '<tr>';
        echo '<th><label for="rate_limit_requests">' . esc_html__('Requests per window', 'license-server') . '</label></th>';
        echo '<td><input type="number" id="rate_limit_requests" name="rate_limit_requests" value="' . esc_attr(get_option('lsr_rate_limit_requests', 60)) . '" min="1" max="1000"> ' . esc_html__('requests', 'license-server') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th><label for="rate_limit_window">' . esc_html__('Time window', 'license-server') . '</label></th>';
        echo '<td><input type="number" id="rate_limit_window" name="rate_limit_window" value="' . esc_attr(get_option('lsr_rate_limit_window', 300)) . '" min="60" max="3600"> ' . esc_html__('seconds', 'license-server') . '</td>';
        echo '</tr>';
        
        // Logging settings
        echo '<tr><th colspan="2"><h3>' . esc_html__('Logging', 'license-server') . '</h3></th></tr>';
        
        $loggingOptions = [
            'lsr_enable_api_logging' => __('API Event Logging', 'license-server'),
            'lsr_enable_security_logging' => __('Security Event Logging', 'license-server'),
            'lsr_enable_validation_logging' => __('License Validation Logging', 'license-server'),
            'lsr_enable_cron_logging' => __('Cron Job Logging', 'license-server')
        ];
        
        foreach ($loggingOptions as $option => $label) {
            echo '<tr>';
            echo '<th><label for="' . esc_attr($option) . '">' . esc_html($label) . '</label></th>';
            echo '<td><input type="checkbox" id="' . esc_attr($option) . '" name="' . esc_attr($option) . '" value="1" ' . checked(get_option($option, true), true, false) . '></td>';
            echo '</tr>';
        }
        
        // Notification settings
        echo '<tr><th colspan="2"><h3>' . esc_html__('Notifications', 'license-server') . '</h3></th></tr>';
        
        echo '<tr>';
        echo '<th><label for="lsr_notify_on_block">' . esc_html__('Email on IP block', 'license-server') . '</label></th>';
        echo '<td><input type="checkbox" id="lsr_notify_on_block" name="lsr_notify_on_block" value="1" ' . checked(get_option('lsr_notify_on_block', false), true, false) . '></td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th><label for="lsr_email_security_reports">' . esc_html__('Weekly security reports', 'license-server') . '</label></th>';
        echo '<td><input type="checkbox" id="lsr_email_security_reports" name="lsr_email_security_reports" value="1" ' . checked(get_option('lsr_email_security_reports', false), true, false) . '></td>';
        echo '</tr>';
        
        echo '</table>';
        
        submit_button(__('Save Security Settings', 'license-server'));
        echo '</form>';
        echo '</div>';
    }

    /**
     * Render reports tab.
     */
    private static function renderReportsTab(): void
    {
        echo '<div class="lsr-reports">';
        
        // Generate report form
        echo '<div class="lsr-generate-report">';
        echo '<h3>' . esc_html__('Generate Security Report', 'license-server') . '</h3>';
        echo '<form method="post">';
        wp_nonce_field('lsr_security_action', 'lsr_security_nonce');
        echo '<input type="hidden" name="action" value="generate_report">';
        echo '<p>' . esc_html__('Generate a comprehensive security report for the last 7 days.', 'license-server') . '</p>';
        submit_button(__('Generate Report', 'license-server'), 'secondary');
        echo '</form>';
        echo '</div>';
        
        // Last report
        $lastReport = get_option('lsr_last_security_report');
        if ($lastReport) {
            echo '<div class="lsr-last-report">';
            echo '<h3>' . esc_html__('Latest Security Report', 'license-server') . '</h3>';
            echo '<div class="lsr-report-summary">';
            echo '<p><strong>' . esc_html__('Generated:', 'license-server') . '</strong> ' . esc_html($lastReport['generated_at']) . '</p>';
            echo '<p><strong>' . esc_html__('Period:', 'license-server') . '</strong> ' . esc_html($lastReport['period_start']) . ' - ' . esc_html($lastReport['period_end']) . '</p>';
            echo '<p><strong>' . esc_html__('Total Events:', 'license-server') . '</strong> ' . esc_html($lastReport['total_events']) . '</p>';
            echo '<p><strong>' . esc_html__('Blocked IPs:', 'license-server') . '</strong> ' . count($lastReport['blocked_ips']) . '</p>';
            
            if (!empty($lastReport['top_events'])) {
                echo '<h4>' . esc_html__('Top Events:', 'license-server') . '</h4>';
                echo '<ul>';
                foreach ($lastReport['top_events'] as $event) {
                    echo '<li>' . esc_html($event['event']) . ': ' . esc_html($event['count']) . ' times</li>';
                }
                echo '</ul>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * Render a statistics card.
     *
     * @param string $title
     * @param int $value
     * @param string $icon
     * @param string $color
     */
    private static function renderStatCard(string $title, int $value, string $icon, string $color): void
    {
        echo '<div class="lsr-stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); text-align: center;">';
        echo '<div class="dashicons ' . esc_attr($icon) . '" style="font-size: 32px; color: ' . esc_attr($color) . '; margin-bottom: 10px;"></div>';
        echo '<div class="lsr-stat-value" style="font-size: 24px; font-weight: bold; color: #333;">' . esc_html($value) . '</div>';
        echo '<div class="lsr-stat-title" style="color: #666; font-size: 14px;">' . esc_html($title) . '</div>';
        echo '</div>';
    }

    /**
     * Render recent security events.
     */
    private static function renderRecentEvents(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            echo '<p>' . esc_html__('No events to display.', 'license-server') . '</p>';
            return;
        }

        $events = $wpdb->get_results(
            "SELECT event, ip, created_at FROM {$table} ORDER BY created_at DESC LIMIT 10",
            ARRAY_A
        );

        if (empty($events)) {
            echo '<p>' . esc_html__('No recent events.', 'license-server') . '</p>';
            return;
        }

        echo '<table class="widefat">';
        echo '<thead><tr><th>Event</th><th>IP</th><th>Time</th></tr></thead>';
        echo '<tbody>';
        foreach ($events as $event) {
            echo '<tr>';
            echo '<td><code>' . esc_html($event['event']) . '</code></td>';
            echo '<td><code>' . esc_html($event['ip']) . '</code></td>';
            echo '<td>' . esc_html(human_time_diff(strtotime($event['created_at']))) . ' ago</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Render system status information.
     *
     * @param array $cronStatus
     */
    private static function renderSystemStatus(array $cronStatus): void
    {
        echo '<table class="widefat">';
        echo '<tbody>';
        
        echo '<tr>';
        echo '<td><strong>' . esc_html__('Cron System', 'license-server') . '</strong></td>';
        echo '<td>' . ($cronStatus['cron_enabled'] ? '<span style="color: green;">✓ Active</span>' : '<span style="color: red;">✗ Disabled</span>') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td><strong>' . esc_html__('Last Maintenance', 'license-server') . '</strong></td>';
        echo '<td>' . ($cronStatus['last_maintenance_run'] ? human_time_diff($cronStatus['last_maintenance_run']) . ' ago' : 'Never') . '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<td><strong>' . esc_html__('Security Cleanup', 'license-server') . '</strong></td>';
        echo '<td>' . ($cronStatus['last_security_cleanup'] ? 'Next: ' . date('Y-m-d H:i:s', $cronStatus['last_security_cleanup']) : 'Not scheduled') . '</td>';
        echo '</tr>';
        
        echo '</tbody></table>';
    }

    // Helper methods for blocked IPs management

    private static function handleBlockIp(): string
    {
        $ip = sanitize_text_field($_POST['ip_address'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? 'Manual block');

        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return '<div class="notice notice-error"><p>' . esc_html__('Invalid IP address.', 'license-server') . '</p></div>';
        }

        Middleware::blockIp($ip, $reason);
        return '<div class="notice notice-success"><p>' . sprintf(__('IP %s has been blocked.', 'license-server'), esc_html($ip)) . '</p></div>';
    }

    private static function handleUnblockIp(): string
    {
        $ip = sanitize_text_field($_POST['ip_address'] ?? '');
        Middleware::unblockIp($ip);
        return '<div class="notice notice-success"><p>' . sprintf(__('IP %s has been unblocked.', 'license-server'), esc_html($ip)) . '</p></div>';
    }

    private static function handleClearEvents(): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';
        $cutoffDate = date('Y-m-d H:i:s', time() - (30 * 24 * 60 * 60));
        
        $deleted = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE created_at < %s", $cutoffDate)
        );

        return '<div class="notice notice-success"><p>' . sprintf(__('Deleted %d old security events.', 'license-server'), (int)$deleted) . '</p></div>';
    }

    private static function handleSaveSettings(): string
    {
        $settings = [
            'lsr_rate_limit_requests' => (int) ($_POST['rate_limit_requests'] ?? 60),
            'lsr_rate_limit_window' => (int) ($_POST['rate_limit_window'] ?? 300),
            'lsr_enable_api_logging' => !empty($_POST['lsr_enable_api_logging']),
            'lsr_enable_security_logging' => !empty($_POST['lsr_enable_security_logging']),
            'lsr_enable_validation_logging' => !empty($_POST['lsr_enable_validation_logging']),
            'lsr_enable_cron_logging' => !empty($_POST['lsr_enable_cron_logging']),
            'lsr_notify_on_block' => !empty($_POST['lsr_notify_on_block']),
            'lsr_email_security_reports' => !empty($_POST['lsr_email_security_reports'])
        ];

        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }

        return '<div class="notice notice-success"><p>' . esc_html__('Security settings saved.', 'license-server') . '</p></div>';
    }

    private static function handleGenerateReport(): string
    {
        // This would trigger the report generation
        do_action('lsr_weekly_security_report');
        return '<div class="notice notice-success"><p>' . esc_html__('Security report generated.', 'license-server') . '</p></div>';
    }

    private static function getIpBlockDate(string $ip): string
    {
        // This would need to be stored when blocking IPs
        return 'Recent';
    }

    private static function getIpEventCount(string $ip): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';
        
        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return '0';
        }

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE ip = %s AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)",
                $ip
            )
        );

        return (string) ($count ?: '0');
    }
}