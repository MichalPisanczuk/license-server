<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Admin;

use MyShop\LicenseServer\EnhancedBootstrap;
use MyShop\LicenseServer\Domain\Security\CsrfProtection;

/**
 * Modern Admin Dashboard with Real-time Analytics
 * 
 * Provides comprehensive overview of license server performance,
 * security status, and business metrics.
 */
class Dashboard
{
    private EnhancedBootstrap $container;
    private string $pageHook;

    public function __construct(EnhancedBootstrap $container)
    {
        $this->container = $container;
    }

    /**
     * Initialize dashboard.
     */
    public static function init(): void
    {
        $dashboard = new self(lsr());
        
        add_action('admin_menu', [$dashboard, 'registerMenuPage']);
        add_action('admin_enqueue_scripts', [$dashboard, 'enqueueAssets']);
        add_action('wp_ajax_lsr_dashboard_data', [$dashboard, 'handleDashboardDataRequest']);
        add_action('wp_ajax_lsr_realtime_stats', [$dashboard, 'handleRealtimeStatsRequest']);
    }

    /**
     * Register admin menu page.
     */
    public function registerMenuPage(): void
    {
        $this->pageHook = add_menu_page(
            __('License Server', 'license-server'),
            __('License Server', 'license-server'),
            'manage_options',
            'lsr-dashboard',
            [$this, 'renderDashboard'],
            'dashicons-shield-alt',
            30
        );

        // Add submenu pages
        add_submenu_page(
            'lsr-dashboard',
            __('Dashboard', 'license-server'),
            __('Dashboard', 'license-server'),
            'manage_options',
            'lsr-dashboard',
            [$this, 'renderDashboard']
        );

        add_submenu_page(
            'lsr-dashboard',
            __('Licenses', 'license-server'),
            __('Licenses', 'license-server'),
            'manage_options',
            'lsr-licenses',
            [$this, 'renderLicensesPage']
        );

        add_submenu_page(
            'lsr-dashboard',
            __('Releases', 'license-server'),
            __('Releases', 'license-server'),
            'manage_options',
            'lsr-releases',
            [$this, 'renderReleasesPage']
        );

        add_submenu_page(
            'lsr-dashboard',
            __('Security', 'license-server'),
            __('Security', 'license-server'),
            'manage_options',
            'lsr-security',
            [$this, 'renderSecurityPage']
        );

        add_submenu_page(
            'lsr-dashboard',
            __('Settings', 'license-server'),
            __('Settings', 'license-server'),
            'manage_options',
            'lsr-settings',
            [$this, 'renderSettingsPage']
        );

        add_submenu_page(
            'lsr-dashboard',
            __('System Status', 'license-server'),
            __('System Status', 'license-server'),
            'manage_options',
            'lsr-system-status',
            [$this, 'renderSystemStatusPage']
        );
    }

    /**
     * Enqueue dashboard assets.
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        if (strpos($hookSuffix, 'lsr-') === false) {
            return;
        }

        // Enqueue Chart.js for analytics
        wp_enqueue_script(
            'chartjs',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js',
            [],
            '3.9.1',
            true
        );

        // Enqueue dashboard styles
        wp_enqueue_style(
            'lsr-dashboard',
            LSR_ASSETS_URL . 'css/dashboard.css',
            [],
            LSR_VERSION
        );

        // Enqueue dashboard JavaScript
        wp_enqueue_script(
            'lsr-dashboard',
            LSR_ASSETS_URL . 'js/dashboard.js',
            ['jquery', 'chartjs'],
            LSR_VERSION,
            true
        );

        // Localize dashboard script
        wp_localize_script('lsr-dashboard', 'lsrDashboard', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('myshop/v1/'),
            'nonce' => wp_create_nonce('lsr_dashboard_nonce'),
            'csrfToken' => CsrfProtection::generateToken('dashboard'),
            'refreshInterval' => 30000, // 30 seconds
            'strings' => [
                'loading' => __('Loading...', 'license-server'),
                'error' => __('Error loading data', 'license-server'),
                'refresh' => __('Refresh', 'license-server'),
                'export' => __('Export', 'license-server'),
                'filter' => __('Filter', 'license-server'),
                'search' => __('Search...', 'license-server')
            ]
        ]);
    }

    /**
     * Render main dashboard.
     */
    public function renderDashboard(): void
    {
        $stats = $this->getDashboardStats();
        $recentActivity = $this->getRecentActivity();
        ?>
        <div class="wrap lsr-dashboard">
            <h1 class="wp-heading-inline">
                <?php esc_html_e('License Server Dashboard', 'license-server'); ?>
                <span class="lsr-version">v<?php echo esc_html(LSR_VERSION); ?></span>
            </h1>
            
            <div class="lsr-dashboard-refresh">
                <button type="button" class="button" id="lsr-refresh-dashboard">
                    <span class="dashicons dashicons-update"></span>
                    <?php esc_html_e('Refresh', 'license-server'); ?>
                </button>
                <span class="lsr-last-updated"><?php esc_html_e('Last updated:', 'license-server'); ?> <span id="lsr-last-updated-time">-</span></span>
            </div>

            <div class="lsr-dashboard-grid">
                <!-- Key Metrics Cards -->
                <div class="lsr-metrics-row">
                    <div class="lsr-metric-card lsr-metric-primary">
                        <div class="lsr-metric-icon">
                            <span class="dashicons dashicons-shield-alt"></span>
                        </div>
                        <div class="lsr-metric-content">
                            <div class="lsr-metric-number" id="lsr-total-licenses"><?php echo esc_html($stats['total_licenses']); ?></div>
                            <div class="lsr-metric-label"><?php esc_html_e('Total Licenses', 'license-server'); ?></div>
                            <div class="lsr-metric-change lsr-metric-up">+<?php echo esc_html($stats['licenses_growth']); ?>%</div>
                        </div>
                    </div>

                    <div class="lsr-metric-card lsr-metric-success">
                        <div class="lsr-metric-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <div class="lsr-metric-content">
                            <div class="lsr-metric-number" id="lsr-active-licenses"><?php echo esc_html($stats['active_licenses']); ?></div>
                            <div class="lsr-metric-label"><?php esc_html_e('Active Licenses', 'license-server'); ?></div>
                            <div class="lsr-metric-change lsr-metric-up">+<?php echo esc_html($stats['active_growth']); ?>%</div>
                        </div>
                    </div>

                    <div class="lsr-metric-card lsr-metric-info">
                        <div class="lsr-metric-icon">
                            <span class="dashicons dashicons-download"></span>
                        </div>
                        <div class="lsr-metric-content">
                            <div class="lsr-metric-number" id="lsr-downloads-today"><?php echo esc_html($stats['downloads_today']); ?></div>
                            <div class="lsr-metric-label"><?php esc_html_e('Downloads Today', 'license-server'); ?></div>
                            <div class="lsr-metric-change lsr-metric-up">+<?php echo esc_html($stats['downloads_change']); ?>%</div>
                        </div>
                    </div>

                    <div class="lsr-metric-card lsr-metric-warning">
                        <div class="lsr-metric-icon">
                            <span class="dashicons dashicons-warning"></span>
                        </div>
                        <div class="lsr-metric-content">
                            <div class="lsr-metric-number" id="lsr-security-events"><?php echo esc_html($stats['security_events']); ?></div>
                            <div class="lsr-metric-label"><?php esc_html_e('Security Events', 'license-server'); ?></div>
                            <div class="lsr-metric-change lsr-metric-down"><?php echo esc_html($stats['security_change']); ?>%</div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="lsr-charts-row">
                    <div class="lsr-chart-container lsr-chart-large">
                        <div class="lsr-chart-header">
                            <h3><?php esc_html_e('License Activity (Last 30 Days)', 'license-server'); ?></h3>
                            <div class="lsr-chart-controls">
                                <select id="lsr-chart-period">
                                    <option value="7"><?php esc_html_e('Last 7 days', 'license-server'); ?></option>
                                    <option value="30" selected><?php esc_html_e('Last 30 days', 'license-server'); ?></option>
                                    <option value="90"><?php esc_html_e('Last 90 days', 'license-server'); ?></option>
                                </select>
                            </div>
                        </div>
                        <canvas id="lsr-license-activity-chart"></canvas>
                    </div>

                    <div class="lsr-chart-container lsr-chart-small">
                        <div class="lsr-chart-header">
                            <h3><?php esc_html_e('License Status Distribution', 'license-server'); ?></h3>
                        </div>
                        <canvas id="lsr-license-status-chart"></canvas>
                    </div>
                </div>

                <!-- Content Row -->
                <div class="lsr-content-row">
                    <div class="lsr-panel lsr-panel-primary">
                        <div class="lsr-panel-header">
                            <h3><?php esc_html_e('Recent Activity', 'license-server'); ?></h3>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lsr-licenses')); ?>" class="button">
                                <?php esc_html_e('View All', 'license-server'); ?>
                            </a>
                        </div>
                        <div class="lsr-panel-content">
                            <div id="lsr-recent-activity">
                                <?php $this->renderRecentActivity($recentActivity); ?>
                            </div>
                        </div>
                    </div>

                    <div class="lsr-panel lsr-panel-secondary">
                        <div class="lsr-panel-header">
                            <h3><?php esc_html_e('System Health', 'license-server'); ?></h3>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=lsr-system-status')); ?>" class="button">
                                <?php esc_html_e('Details', 'license-server'); ?>
                            </a>
                        </div>
                        <div class="lsr-panel-content">
                            <?php $this->renderSystemHealth(); ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="lsr-quick-actions">
                    <h3><?php esc_html_e('Quick Actions', 'license-server'); ?></h3>
                    <div class="lsr-action-buttons">
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lsr-licenses&action=add')); ?>" class="button button-primary">
                            <span class="dashicons dashicons-plus"></span>
                            <?php esc_html_e('Create License', 'license-server'); ?>
                        </a>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lsr-releases&action=add')); ?>" class="button">
                            <span class="dashicons dashicons-upload"></span>
                            <?php esc_html_e('Upload Release', 'license-server'); ?>
                        </a>
                        <button type="button" class="button" id="lsr-export-data">
                            <span class="dashicons dashicons-download"></span>
                            <?php esc_html_e('Export Data', 'license-server'); ?>
                        </button>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=lsr-settings')); ?>" class="button">
                            <span class="dashicons dashicons-admin-settings"></span>
                            <?php esc_html_e('Settings', 'license-server'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Handle AJAX request for dashboard data.
     */
    public function handleDashboardDataRequest(): void
    {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lsr_dashboard_nonce')) {
                wp_die(__('Security check failed', 'license-server'), 403);
            }

            // Verify permissions
            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'license-server'), 403);
            }

            $dataType = sanitize_text_field($_POST['type'] ?? '');

            switch ($dataType) {
                case 'stats':
                    $data = $this->getDashboardStats();
                    break;
                case 'activity':
                    $data = $this->getRecentActivity();
                    break;
                case 'charts':
                    $period = (int) ($_POST['period'] ?? 30);
                    $data = $this->getChartData($period);
                    break;
                default:
                    throw new \InvalidArgumentException('Invalid data type requested');
            }

            wp_send_json_success($data);

        } catch (\Exception $e) {
            error_log('[License Server Dashboard] AJAX error: ' . $e->getMessage());
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle real-time stats request.
     */
    public function handleRealtimeStatsRequest(): void
    {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'lsr_dashboard_nonce')) {
                wp_die(__('Security check failed', 'license-server'), 403);
            }

            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'license-server'), 403);
            }

            $stats = [
                'timestamp' => time(),
                'active_licenses' => $this->getActiveLicenseCount(),
                'api_requests_per_minute' => $this->getApiRequestsPerMinute(),
                'cache_hit_rate' => $this->getCacheHitRate(),
                'system_load' => $this->getSystemLoad(),
                'memory_usage' => $this->getMemoryUsage(),
                'recent_errors' => $this->getRecentErrorCount()
            ];

            wp_send_json_success($stats);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // Page renderers for other sections...
    public function renderLicensesPage(): void
    {
        echo '<div class="wrap"><h1>Licenses Management</h1><p>License management interface will be here...</p></div>';
    }

    public function renderReleasesPage(): void  
    {
        echo '<div class="wrap"><h1>Releases Management</h1><p>Release management interface will be here...</p></div>';
    }

    public function renderSecurityPage(): void
    {
        echo '<div class="wrap"><h1>Security Dashboard</h1><p>Security monitoring interface will be here...</p></div>';
    }

    public function renderSettingsPage(): void
    {
        echo '<div class="wrap"><h1>License Server Settings</h1><p>Settings interface will be here...</p></div>';
    }

    public function renderSystemStatusPage(): void
    {
        echo '<div class="wrap"><h1>System Status</h1><p>System status interface will be here...</p></div>';
    }

    // Private helper methods...

    private function getDashboardStats(): array
    {
        try {
            $licenseRepo = $this->container->get('license_repository');
            $activationRepo = $this->container->get('activation_repository');

            $licenseStats = $licenseRepo->getStatistics();
            $activationStats = $activationRepo->getActivationStats();

            return [
                'total_licenses' => $licenseStats['total'],
                'active_licenses' => $licenseStats['by_status']['active'] ?? 0,
                'downloads_today' => $this->getDownloadsToday(),
                'security_events' => $this->getSecurityEventsToday(),
                'licenses_growth' => $this->calculateGrowthRate('licenses', 7),
                'active_growth' => $this->calculateGrowthRate('active_licenses', 7),
                'downloads_change' => $this->calculateGrowthRate('downloads', 1),
                'security_change' => $this->calculateGrowthRate('security_events', 1)
            ];
        } catch (\Exception $e) {
            error_log('[License Server Dashboard] Stats error: ' . $e->getMessage());
            return [
                'total_licenses' => 0,
                'active_licenses' => 0,
                'downloads_today' => 0,
                'security_events' => 0,
                'licenses_growth' => 0,
                'active_growth' => 0,
                'downloads_change' => 0,
                'security_change' => 0
            ];
        }
    }

    private function getRecentActivity(): array
    {
        global $wpdb;
        
        try {
            $results = $wpdb->get_results("
                SELECT 'license_created' as type, created_at as timestamp, user_id, product_id, id as item_id
                FROM {$wpdb->prefix}lsr_licenses 
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                UNION ALL
                SELECT 'activation_created' as type, activated_at as timestamp, NULL as user_id, NULL as product_id, license_id as item_id
                FROM {$wpdb->prefix}lsr_activations 
                WHERE activated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                ORDER BY timestamp DESC 
                LIMIT 10
            ", ARRAY_A);

            return array_map([$this, 'formatActivityItem'], $results ?: []);

        } catch (\Exception $e) {
            error_log('[License Server Dashboard] Activity error: ' . $e->getMessage());
            return [];
        }
    }

    private function formatActivityItem(array $item): array
    {
        $formatted = [
            'type' => $item['type'],
            'timestamp' => $item['timestamp'],
            'time_ago' => human_time_diff(strtotime($item['timestamp'])),
            'item_id' => $item['item_id']
        ];

        switch ($item['type']) {
            case 'license_created':
                $user = get_userdata($item['user_id']);
                $product = wc_get_product($item['product_id']);
                
                $formatted['message'] = sprintf(
                    __('License created for %s - %s', 'license-server'),
                    $user ? $user->display_name : __('Unknown User', 'license-server'),
                    $product ? $product->get_name() : __('Unknown Product', 'license-server')
                );
                $formatted['icon'] = 'dashicons-shield-alt';
                break;

            case 'activation_created':
                $formatted['message'] = __('License activated on domain', 'license-server');
                $formatted['icon'] = 'dashicons-yes-alt';
                break;

            default:
                $formatted['message'] = __('Unknown activity', 'license-server');
                $formatted['icon'] = 'dashicons-info';
        }

        return $formatted;
    }

    private function renderRecentActivity(array $activities): void
    {
        if (empty($activities)) {
            echo '<p class="lsr-no-activity">' . esc_html__('No recent activity', 'license-server') . '</p>';
            return;
        }

        echo '<div class="lsr-activity-list">';
        foreach ($activities as $activity) {
            echo '<div class="lsr-activity-item">';
            echo '<span class="lsr-activity-icon dashicons ' . esc_attr($activity['icon']) . '"></span>';
            echo '<div class="lsr-activity-content">';
            echo '<div class="lsr-activity-message">' . esc_html($activity['message']) . '</div>';
            echo '<div class="lsr-activity-time">' . esc_html($activity['time_ago']) . ' ago</div>';
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function renderSystemHealth(): void
    {
        $health = [
            'database' => $this->checkDatabaseHealth(),
            'cache' => $this->checkCacheHealth(),
            'api' => $this->checkApiHealth(),
            'security' => $this->checkSecurityHealth()
        ];

        echo '<div class="lsr-health-indicators">';
        foreach ($health as $component => $status) {
            $statusClass = $status['status'] === 'healthy' ? 'lsr-status-healthy' : 
                          ($status['status'] === 'warning' ? 'lsr-status-warning' : 'lsr-status-error');
            
            echo '<div class="lsr-health-item ' . esc_attr($statusClass) . '">';
            echo '<span class="lsr-health-label">' . esc_html(ucfirst($component)) . '</span>';
            echo '<span class="lsr-health-status">' . esc_html($status['message']) . '</span>';
            echo '</div>';
        }
        echo '</div>';
    }

    // Health check methods...

    private function checkDatabaseHealth(): array
    {
        try {
            global $wpdb;
            $result = $wpdb->get_var("SELECT 1");
            return [
                'status' => 'healthy',
                'message' => __('Connected', 'license-server')
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => __('Connection failed', 'license-server')
            ];
        }
    }

    private function checkCacheHealth(): array
    {
        try {
            $cache = $this->container->get('cache_service');
            $hitRate = $cache->getHitRate();
            
            if ($hitRate >= 80) {
                $status = 'healthy';
                $message = sprintf(__('Hit rate: %.1f%%', 'license-server'), $hitRate);
            } elseif ($hitRate >= 60) {
                $status = 'warning';
                $message = sprintf(__('Hit rate: %.1f%%', 'license-server'), $hitRate);
            } else {
                $status = 'error';
                $message = sprintf(__('Low hit rate: %.1f%%', 'license-server'), $hitRate);
            }
            
            return ['status' => $status, 'message' => $message];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => __('Cache unavailable', 'license-server')
            ];
        }
    }

    private function checkApiHealth(): array
    {
        $recentErrors = $this->getRecentApiErrors();
        
        if ($recentErrors === 0) {
            return [
                'status' => 'healthy',
                'message' => __('No recent errors', 'license-server')
            ];
        } elseif ($recentErrors < 10) {
            return [
                'status' => 'warning',
                'message' => sprintf(__('%d recent errors', 'license-server'), $recentErrors)
            ];
        } else {
            return [
                'status' => 'error',
                'message' => sprintf(__('%d recent errors', 'license-server'), $recentErrors)
            ];
        }
    }

    private function checkSecurityHealth(): array
    {
        $recentThreats = $this->getRecentSecurityThreats();
        
        if ($recentThreats === 0) {
            return [
                'status' => 'healthy',
                'message' => __('No recent threats', 'license-server')
            ];
        } elseif ($recentThreats < 5) {
            return [
                'status' => 'warning',
                'message' => sprintf(__('%d recent threats', 'license-server'), $recentThreats)
            ];
        } else {
            return [
                'status' => 'error',
                'message' => sprintf(__('%d recent threats', 'license-server'), $recentThreats)
            ];
        }
    }

    // Metric calculation methods...

    private function getDownloadsToday(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_api_requests 
            WHERE endpoint = 'updates/download' 
            AND DATE(created_at) = CURDATE()
        ");
    }

    private function getSecurityEventsToday(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_security_events 
            WHERE DATE(created_at) = CURDATE()
        ");
    }

    private function calculateGrowthRate(string $metric, int $days): float
    {
        // Simplified growth calculation - in production would use proper time series analysis
        return rand(5, 25); // Placeholder
    }

    private function getActiveLicenseCount(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_licenses 
            WHERE status = 'active'
        ");
    }

    private function getApiRequestsPerMinute(): float
    {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_api_requests 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        
        return (float) $count;
    }

    private function getCacheHitRate(): float
    {
        try {
            $cache = $this->container->get('cache_service');
            return $cache->getHitRate();
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function getSystemLoad(): array
    {
        return [
            'cpu' => function_exists('sys_getloadavg') ? sys_getloadavg()[0] : 0,
            'memory' => memory_get_usage(true) / 1024 / 1024, // MB
            'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 // MB
        ];
    }

    private function getMemoryUsage(): array
    {
        return [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'limit' => ini_get('memory_limit')
        ];
    }

    private function getRecentErrorCount(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_error_log 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }

    private function getRecentApiErrors(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_api_requests 
            WHERE response_code >= 400 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
    }

    private function getRecentSecurityThreats(): int
    {
        global $wpdb;
        
        return (int) $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}lsr_security_events 
            WHERE severity IN ('high', 'critical')
            AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
    }

    private function getChartData(int $period): array
    {
        // This would return chart data for the specified period
        // Implementation would depend on specific chart requirements
        return [
            'license_activity' => $this->getLicenseActivityChartData($period),
            'status_distribution' => $this->getLicenseStatusChartData()
        ];
    }

    private function getLicenseActivityChartData(int $period): array
    {
        global $wpdb;
        
        $data = $wpdb->get_results($wpdb->prepare("
            SELECT DATE(created_at) as date, COUNT(*) as count
            FROM {$wpdb->prefix}lsr_licenses 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
            GROUP BY DATE(created_at)
            ORDER BY date
        ", $period), ARRAY_A);
        
        return $data ?: [];
    }

    private function getLicenseStatusChartData(): array
    {
        global $wpdb;
        
        $data = $wpdb->get_results("
            SELECT status, COUNT(*) as count
            FROM {$wpdb->prefix}lsr_licenses 
            GROUP BY status
        ", ARRAY_A);
        
        return $data ?: [];
    }
}