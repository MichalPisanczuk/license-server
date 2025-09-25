<?php
declare(strict_types=1);

namespace MyShop\LicenseServer;

use MyShop\LicenseServer\Domain\Exceptions\DatabaseException;

/**
 * Enhanced Bootstrap with proper dependency injection and service management.
 * 
 * This class follows the Single Responsibility Principle and provides
 * proper service container functionality with lazy loading.
 */
class EnhancedBootstrap
{
    /** @var self|null Singleton instance */
    private static ?EnhancedBootstrap $instance = null;
    
    /** @var array Service definitions */
    private array $services = [];
    
    /** @var array Instantiated services cache */
    private array $instances = [];
    
    /** @var bool Whether services are initialized */
    private bool $initialized = false;

    /**
     * Private constructor for singleton pattern.
     */
    private function __construct()
    {
        // Services will be initialized on demand
    }

    /**
     * Get singleton instance.
     *
     * @return static
     */
    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Initialize the License Server application.
     * 
     * This method should be called from the main plugin file.
     */
    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        try {
            // Register services
            $this->registerServices();
            
            // Register hooks
            $this->registerHooks();
            
            // Register REST API
            $this->registerRestApi();
            
            // Register admin functionality
            $this->registerAdmin();
            
            // Register frontend functionality
            $this->registerFrontend();
            
            // Register cron jobs
            $this->registerCronJobs();
            
            $this->initialized = true;
            
            do_action('lsr_bootstrap_initialized');
            
        } catch (\Exception $e) {
            // Log initialization error
            error_log('[License Server Bootstrap] Initialization failed: ' . $e->getMessage());
            
            // Show admin notice
            add_action('admin_notices', function () use ($e) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('License Server initialization failed: ', 'license-server');
                echo esc_html($e->getMessage());
                echo '</p></div>';
            });
        }
    }

    /**
     * Get service from container.
     *
     * @template T
     * @param class-string<T> $serviceId Service identifier
     * @return T Service instance
     * @throws \InvalidArgumentException If service is not registered
     */
    public function get(string $serviceId)
    {
        if (!isset($this->services[$serviceId])) {
            throw new \InvalidArgumentException("Service '{$serviceId}' is not registered.");
        }

        // Return cached instance if available
        if (isset($this->instances[$serviceId])) {
            return $this->instances[$serviceId];
        }

        // Create instance
        $definition = $this->services[$serviceId];
        
        if (is_callable($definition)) {
            $instance = $definition($this);
        } elseif (is_string($definition) && class_exists($definition)) {
            $instance = new $definition();
        } else {
            throw new \RuntimeException("Invalid service definition for '{$serviceId}'.");
        }

        // Cache instance
        $this->instances[$serviceId] = $instance;

        return $instance;
    }

    /**
     * Register a service in the container.
     *
     * @param string $serviceId Service identifier
     * @param callable|string $definition Service definition (callable factory or class name)
     */
    public function register(string $serviceId, $definition): void
    {
        $this->services[$serviceId] = $definition;
        
        // Clear cached instance if exists
        unset($this->instances[$serviceId]);
    }

    /**
     * Check if service is registered.
     *
     * @param string $serviceId Service identifier
     * @return bool
     */
    public function has(string $serviceId): bool
    {
        return isset($this->services[$serviceId]);
    }

    /**
     * Get all registered service IDs.
     *
     * @return array
     */
    public function getServiceIds(): array
    {
        return array_keys($this->services);
    }

    /**
     * Clear service instances cache.
     * 
     * Useful for testing or plugin reloading scenarios.
     */
    public function clearInstances(): void
    {
        $this->instances = [];
    }

    /**
     * Register all core services with dependency injection.
     */
    private function registerServices(): void
    {
        // Configuration service
        $this->register('config', function () {
            return new \MyShop\LicenseServer\Domain\Services\ConfigurationService();
        });

        // Security services
        $this->register('license_key_service', function () {
            return new \MyShop\LicenseServer\Domain\Services\SecureLicenseKeyService();
        });

        $this->register('csrf_protection', function () {
            return new \MyShop\LicenseServer\Domain\Security\CsrfProtection();
        });

        // Repository services
        $this->register('license_repository', function () {
            return new \MyShop\LicenseServer\Data\Repositories\EnhancedLicenseRepository();
        });

        $this->register('activation_repository', function () {
            return new \MyShop\LicenseServer\Data\Repositories\EnhancedActivationRepository();
        });

        $this->register('release_repository', function () {
            return new \MyShop\LicenseServer\Data\Repositories\ReleaseRepository();
        });

        // Domain services
        $this->register('license_service', function ($container) {
            return new \MyShop\LicenseServer\Domain\Services\EnhancedLicenseService(
                $container->get('license_repository'),
                $container->get('activation_repository'),
                $container->get('release_repository'),
                $container->get('license_key_service')
            );
        });

        $this->register('release_service', function ($container) {
            return new \MyShop\LicenseServer\Domain\Services\ReleaseService(
                $container->get('release_repository')
            );
        });

        // Utility services
        $this->register('signed_url_service', function ($container) {
            $ttl = $container->get('config')->get('signed_url_ttl', 300);
            return new \MyShop\LicenseServer\Domain\Services\SignedUrlService($ttl);
        });

        $this->register('rate_limiter', function () {
            return new \MyShop\LicenseServer\Domain\Services\RateLimiter();
        });

        $this->register('domain_binding_service', function () {
            return new \MyShop\LicenseServer\Domain\Services\DomainBindingService();
        });

        // API Controllers
        $this->register('license_controller', function ($container) {
            return new \MyShop\LicenseServer\API\EnhancedLicenseController(
                $container->get('license_service')
            );
        });

        $this->register('updates_controller', function ($container) {
            return new \MyShop\LicenseServer\API\UpdateController(
                $container->get('license_service'),
                $container->get('release_service'),
                $container->get('signed_url_service')
            );
        });

        // Event system
        $this->register('event_dispatcher', function () {
            return new \MyShop\LicenseServer\Domain\Events\EventDispatcher();
        });

        // Cache service
        $this->register('cache_service', function ($container) {
            return new \MyShop\LicenseServer\Domain\Services\CacheService(
                $container->get('config')->get('cache_enabled', true)
            );
        });

        do_action('lsr_register_services', $this);
    }

    /**
     * Register WordPress hooks for WooCommerce integration.
     */
    private function registerHooks(): void
    {
        // WooCommerce product meta fields
        add_action('init', function () {
            \MyShop\LicenseServer\WooCommerce\ProductFlags::init();
        });

        // Order completion hooks
        add_action('init', function () {
            \MyShop\LicenseServer\WooCommerce\OrderHooks::init();
        });

        // Subscription hooks (if WC Subscriptions is active)
        if (class_exists('WC_Subscriptions')) {
            add_action('init', function () {
                \MyShop\LicenseServer\WooCommerce\SubscriptionsHooks::init();
            });
        }

        // User account integration
        add_action('init', function () {
            \MyShop\LicenseServer\WooCommerce\MyAccountMenu::init();
            \MyShop\LicenseServer\Account\Endpoints::init();
            \MyShop\LicenseServer\Account\Shortcodes::init();
        });

        // Security hooks
        add_action('wp_login', [$this, 'onUserLogin'], 10, 2);
        add_action('wp_logout', [$this, 'onUserLogout']);
        
        // Cleanup hooks
        add_action('lsr_daily_cleanup', [$this, 'performDailyCleanup']);
    }

    /**
     * Register REST API routes.
     */
    private function registerRestApi(): void
    {
        add_action('rest_api_init', function () {
            try {
                $routes = new \MyShop\LicenseServer\API\EnhancedRestRoutes($this);
                $routes->register();
            } catch (\Exception $e) {
                error_log('[License Server] REST API registration failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Register admin functionality.
     */
    private function registerAdmin(): void
    {
        if (!is_admin()) {
            return;
        }

        add_action('admin_menu', function () {
            \MyShop\LicenseServer\Admin\Menu::init();
        });

        add_action('admin_init', function () {
            \MyShop\LicenseServer\Admin\Settings::init();
        });

        add_action('admin_enqueue_scripts', function ($hook_suffix) {
            $this->enqueueAdminAssets($hook_suffix);
        });

        // AJAX handlers
        add_action('wp_ajax_lsr_refresh_csrf_token', [$this, 'handleCsrfTokenRefresh']);
        add_action('wp_ajax_lsr_test_api_endpoints', [$this, 'handleApiEndpointTest']);
        add_action('wp_ajax_lsr_system_status', [$this, 'handleSystemStatus']);
    }

    /**
     * Register frontend functionality.
     */
    private function registerFrontend(): void
    {
        if (is_admin()) {
            return;
        }

        // Frontend shortcodes
        add_action('init', function () {
            \MyShop\LicenseServer\Frontend\Shortcodes::init();
        });

        // Frontend styles (if needed)
        add_action('wp_enqueue_scripts', function () {
            if (is_account_page()) {
                wp_enqueue_style(
                    'lsr-frontend',
                    LSR_ASSETS_URL . 'css/frontend.css',
                    [],
                    LSR_VERSION
                );
            }
        });
    }

    /**
     * Register cron jobs for maintenance tasks.
     */
    private function registerCronJobs(): void
    {
        // Schedule daily cleanup if not already scheduled
        if (!wp_next_scheduled('lsr_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'lsr_daily_cleanup');
        }

        // Schedule hourly housekeeping if not already scheduled
        if (!wp_next_scheduled('lsr_cron_housekeeping')) {
            wp_schedule_event(time() + 300, 'hourly', 'lsr_cron_housekeeping');
        }

        // Housekeeping tasks
        add_action('lsr_cron_housekeeping', function () {
            try {
                // Clean expired signed URLs
                $this->get('signed_url_service')->cleanupExpired();
                
                // Clean old API request logs (keep 30 days)
                $this->cleanupApiLogs(30);
                
                // Clean old security events (keep 90 days)
                $this->cleanupSecurityEvents(90);
                
            } catch (\Exception $e) {
                error_log('[License Server] Housekeeping failed: ' . $e->getMessage());
            }
        });
    }

    /**
     * Handle user login - clear session token for CSRF.
     *
     * @param string $userLogin User login
     * @param \WP_User $user User object
     */
    public function onUserLogin(string $userLogin, \WP_User $user): void
    {
        try {
            $this->get('csrf_protection')::clearSessionToken($user->ID);
        } catch (\Exception $e) {
            error_log('[License Server] User login hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Handle user logout - clear session tokens.
     */
    public function onUserLogout(): void
    {
        try {
            $this->get('csrf_protection')::clearSessionToken();
        } catch (\Exception $e) {
            error_log('[License Server] User logout hook failed: ' . $e->getMessage());
        }
    }

    /**
     * Perform daily cleanup tasks.
     */
    public function performDailyCleanup(): void
    {
        try {
            // Clean old inactive activations
            $activationRepo = $this->get('activation_repository');
            $cleaned = $activationRepo->cleanupOldActivations(90);
            
            if ($cleaned > 0) {
                error_log("[License Server] Daily cleanup: removed {$cleaned} old activations");
            }

            // Clean old error logs
            $this->cleanupErrorLogs(30);
            
            // Clean cache if enabled
            if ($this->get('config')->get('cache_enabled', true)) {
                $this->get('cache_service')->cleanup();
            }

            do_action('lsr_daily_cleanup_completed');

        } catch (\Exception $e) {
            error_log('[License Server] Daily cleanup failed: ' . $e->getMessage());
        }
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hookSuffix Admin page hook suffix
     */
    private function enqueueAdminAssets(string $hookSuffix): void
    {
        // Only load on License Server admin pages
        if (strpos($hookSuffix, 'lsr-') === false) {
            return;
        }

        wp_enqueue_style(
            'lsr-admin',
            LSR_ASSETS_URL . 'css/admin.css',
            [],
            LSR_VERSION
        );

        wp_enqueue_script(
            'lsr-admin',
            LSR_ASSETS_URL . 'js/admin.js',
            ['jquery'],
            LSR_VERSION,
            true
        );

        // Localize script with CSRF token and API endpoints
        wp_localize_script('lsr-admin', 'lsrAdmin', [
            'csrf_token' => $this->get('csrf_protection')::generateToken('admin_ajax'),
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('myshop/v1/'),
            'nonces' => [
                'refresh_token' => wp_create_nonce('lsr_refresh_token'),
                'test_api' => wp_create_nonce('lsr_test_api'),
                'system_status' => wp_create_nonce('lsr_system_status')
            ]
        ]);
    }

    /**
     * Handle CSRF token refresh AJAX request.
     */
    public function handleCsrfTokenRefresh(): void
    {
        try {
            if (!check_ajax_referer('lsr_refresh_token', 'nonce', false)) {
                wp_die(__('Security check failed', 'license-server'), 403);
            }

            $action = sanitize_text_field($_POST['action_name'] ?? 'default');
            $token = $this->get('csrf_protection')::generateToken($action);

            wp_send_json_success(['token' => $token]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle API endpoint test AJAX request.
     */
    public function handleApiEndpointTest(): void
    {
        try {
            if (!check_ajax_referer('lsr_test_api', 'nonce', false)) {
                wp_die(__('Security check failed', 'license-server'), 403);
            }

            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'license-server'), 403);
            }

            $results = [];
            $endpoints = [
                'license/activate' => rest_url('myshop/v1/license/activate'),
                'license/validate' => rest_url('myshop/v1/license/validate'),
                'updates/check' => rest_url('myshop/v1/updates/check'),
                'updates/download' => rest_url('myshop/v1/updates/download')
            ];

            foreach ($endpoints as $name => $url) {
                $results[$name] = [
                    'url' => $url,
                    'accessible' => $this->testEndpointAccessibility($url)
                ];
            }

            wp_send_json_success(['endpoints' => $results]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle system status AJAX request.
     */
    public function handleSystemStatus(): void
    {
        try {
            if (!check_ajax_referer('lsr_system_status', 'nonce', false)) {
                wp_die(__('Security check failed', 'license-server'), 403);
            }

            if (!current_user_can('manage_options')) {
                wp_die(__('Insufficient permissions', 'license-server'), 403);
            }

            $status = [
                'database' => $this->getDatabaseStatus(),
                'cache' => $this->getCacheStatus(),
                'security' => $this->getSecurityStatus(),
                'performance' => $this->getPerformanceStatus()
            ];

            wp_send_json_success(['status' => $status]);

        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    // Private helper methods...

    private function testEndpointAccessibility(string $url): bool
    {
        $response = wp_remote_head($url, ['timeout' => 5]);
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) !== 404;
    }

    private function getDatabaseStatus(): array
    {
        return \MyShop\LicenseServer\Data\EnhancedMigrations::getStatus();
    }

    private function getCacheStatus(): array
    {
        try {
            $cache = $this->get('cache_service');
            return [
                'enabled' => $cache->isEnabled(),
                'backend' => $cache->getBackend(),
                'hit_rate' => $cache->getHitRate()
            ];
        } catch (\Exception $e) {
            return ['enabled' => false, 'error' => $e->getMessage()];
        }
    }

    private function getSecurityStatus(): array
    {
        return [
            'https_enabled' => is_ssl(),
            'csrf_protection' => true,
            'encryption_keys' => !empty(get_option('lsr_encryption_key')),
            'failed_attempts_blocking' => get_option('lsr_max_failed_attempts', 10) > 0
        ];
    }

    private function getPerformanceStatus(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false
        ];
    }

    private function cleanupApiLogs(int $daysOld): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_api_requests';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at <= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $daysOld
            )
        );
    }

    private function cleanupSecurityEvents(int $daysOld): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at <= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $daysOld
            )
        );
    }

    private function cleanupErrorLogs(int $daysOld): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_error_log';
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at <= DATE_SUB(NOW(), INTERVAL %d DAY)",
                $daysOld
            )
        );
    }
}