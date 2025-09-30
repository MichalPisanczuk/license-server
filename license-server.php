<?php
/**
 * Plugin Name:       License Server
 * Plugin URI:        https://pisanczuk.com/
 * Description:       Advanced license server and update management for WooCommerce with enterprise-grade security, performance optimization, and comprehensive monitoring.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            MMichał Pisańczuk (PISANCZUK.COM)
 * Author URI:        https://pisanczuk.com/
 * Text Domain:       license-server
 * Domain Path:       /languages
 * Network:           false
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * 
 * @package           LicenseServer
 * @version           2.0.0
 * @since             1.0.0
 */

declare(strict_types=1);

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', function() {
    // Force administrator capabilities
    $role = get_role('administrator');
    if ($role && !$role->has_cap('manage_options')) {
        $role->add_cap('manage_options');
    }
    
    // Add emergency access menu
    if (current_user_can('manage_options')) {
        add_menu_page(
            'License Server Emergency',
            'License Server ⚠️',
            'manage_options',
            'lsr-emergency',
            function() {
                echo '<div class="wrap">';
                echo '<h1>License Server - Emergency Access</h1>';
                echo '<p>Menu główne nie załadowało się poprawnie.</p>';
                echo '<h3>Linki bezpośrednie:</h3>';
                echo '<ul>';
                echo '<li><a href="' . admin_url('admin.php?page=lsr-licenses') . '">→ Licencje</a></li>';
                echo '<li><a href="' . admin_url('admin.php?page=lsr-releases') . '">→ Wydania</a></li>';
                echo '<li><a href="' . admin_url('admin.php?page=lsr-settings') . '">→ Ustawienia</a></li>';
                echo '<li><a href="' . admin_url('admin.php?page=lsr-debug-fix') . '">→ Debug & Fix</a></li>';
                echo '</ul>';
                echo '<p><a href="' . admin_url('admin.php?lsr_autofix=1') . '" class="button button-primary">Automatyczna naprawa</a></p>';
                echo '</div>';
            },
            'dashicons-warning',
            99
        );
    }
}, 5);

// Plugin constants
define('LSR_VERSION', '2.0.0');
define('LSR_FILE', __FILE__);
define('LSR_BASENAME', plugin_basename(__FILE__));
define('LSR_DIR', plugin_dir_path(__FILE__));
define('LSR_URL', plugin_dir_url(__FILE__));
define('LSR_ASSETS_URL', LSR_URL . 'assets/');
define('LSR_MIN_PHP', '8.0');
define('LSR_MIN_WP', '6.2');

// Plugin information
define('LSR_PLUGIN_NAME', 'License Server (MyShop) - Enhanced');
define('LSR_PLUGIN_SLUG', 'license-server');
define('LSR_DB_VERSION', '2.0');

/**
 * Initialize autoloading.
 * 
 * Supports both Composer autoloader and custom PSR-4 implementation.
 */
function lsr_initialize_autoloader(): void
{
    $composerAutoloader = LSR_DIR . 'vendor/autoload.php';
    
    if (file_exists($composerAutoloader)) {
        require_once $composerAutoloader;
        return;
    }
    
    // Fallback PSR-4 autoloader
    spl_autoload_register(function (string $class) {
        $prefix = 'MyShop\\LicenseServer\\';
        $baseDir = LSR_DIR . 'includes/';
        
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) !== 0) {
            return;
        }
        
        $relativePath = substr($class, $len);
        $file = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relativePath) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

/**
 * Load plugin text domain for internationalization.
 */
function lsr_load_textdomain(): void
{
    load_plugin_textdomain(
        'license-server',
        false,
        dirname(LSR_BASENAME) . '/languages'
    );
}

/**
 * Check environment requirements.
 * 
 * Validates PHP version, WordPress version, and required dependencies.
 *
 * @return bool True if all requirements are met
 */
function lsr_check_requirements(): bool
{
    $errors = [];
    
    // PHP version check
    if (version_compare(PHP_VERSION, LSR_MIN_PHP, '<')) {
        $errors[] = sprintf(
            /* translators: 1: required version, 2: current version */
            __('License Server requires PHP %1$s or higher. Current version: %2$s', 'license-server'),
            LSR_MIN_PHP,
            PHP_VERSION
        );
    }
    
    // WordPress version check
    global $wp_version;
    if (version_compare($wp_version, LSR_MIN_WP, '<')) {
        $errors[] = sprintf(
            /* translators: 1: required version, 2: current version */
            __('License Server requires WordPress %1$s or higher. Current version: %2$s', 'license-server'),
            LSR_MIN_WP,
            $wp_version
        );
    }
    
    // WooCommerce dependency check
    if (!class_exists('WooCommerce')) {
        $errors[] = __('License Server requires WooCommerce to be installed and activated.', 'license-server');
    }
    
    // Required PHP extensions
    $requiredExtensions = ['openssl', 'json', 'hash'];
    $missingExtensions = [];
    
    foreach ($requiredExtensions as $extension) {
        if (!extension_loaded($extension)) {
            $missingExtensions[] = $extension;
        }
    }
    
    if (!empty($missingExtensions)) {
        $errors[] = sprintf(
            /* translators: %s: comma-separated list of missing extensions */
            __('License Server requires the following PHP extensions: %s', 'license-server'),
            implode(', ', $missingExtensions)
        );
    }
    
    // Display errors if any
    if (!empty($errors)) {
        add_action('admin_notices', function () use ($errors) {
            foreach ($errors as $error) {
                echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
            }
        });
        return false;
    }
    
    return true;
}

/**
 * Plugin activation hook.
 * 
 * Handles database creation, default options setup, and cron job registration.
 */
function lsr_activate_plugin(): void
{
    // Check requirements before activation
    if (!lsr_check_requirements()) {
        deactivate_plugins(LSR_BASENAME);
        wp_die(
            esc_html__('Cannot activate License Server due to unmet system requirements.', 'license-server'),
            esc_html__('Activation Error', 'license-server'),
            ['back_link' => true]
        );
        return;
    }
    
    try {
        // Initialize autoloader
        lsr_initialize_autoloader();
        
        // Run database migrations
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        \MyShop\LicenseServer\Data\EnhancedMigrations::run();
        
        // Initialize configuration with defaults
        $config = new \MyShop\LicenseServer\Domain\Services\ConfigurationService();
        $config->initialize();
        
        // Create storage directories
        lsr_create_storage_directories();
        
        // Schedule cron jobs
        lsr_schedule_cron_jobs();
        
        // Set activation timestamp
        update_option('lsr_activated_at', current_time('mysql', 1));
        update_option('lsr_version', LSR_VERSION);
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log successful activation
        error_log('[License Server] Plugin activated successfully, version ' . LSR_VERSION);
        
        // Trigger activation event
        do_action('lsr_plugin_activated', LSR_VERSION);
        
    } catch (\Exception $e) {
        error_log('[License Server] Activation failed: ' . $e->getMessage());
        
        deactivate_plugins(LSR_BASENAME);
        wp_die(
            sprintf(
                /* translators: %s: error message */
                esc_html__('License Server activation failed: %s', 'license-server'),
                esc_html($e->getMessage())
            ),
            esc_html__('Activation Error', 'license-server'),
            ['back_link' => true]
        );
    }
}

/**
 * Plugin deactivation hook.
 * 
 * Cleans up cron jobs and temporary data.
 */
function lsr_deactivate_plugin(): void
{
    try {
        // Remove scheduled cron jobs
        wp_clear_scheduled_hook('lsr_cron_housekeeping');
        wp_clear_scheduled_hook('lsr_daily_cleanup');
        wp_clear_scheduled_hook('lsr_async_event');
        
        // Clear transients
        lsr_clear_plugin_transients();
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log deactivation
        error_log('[License Server] Plugin deactivated');
        
        // Trigger deactivation event
        do_action('lsr_plugin_deactivated', LSR_VERSION);
        
    } catch (\Exception $e) {
        error_log('[License Server] Deactivation error: ' . $e->getMessage());
    }
}

/**
 * Create required storage directories.
 */
function lsr_create_storage_directories(): void
{
    $directories = [
        LSR_DIR . 'storage/',
        LSR_DIR . 'storage/releases/',
        LSR_DIR . 'storage/logs/',
        LSR_DIR . 'storage/cache/',
        LSR_DIR . 'storage/temp/'
    ];
    
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            
            // Add .htaccess for security
            $htaccess = $dir . '.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "deny from all\n");
            }
        }
    }
}

/**
 * Schedule cron jobs.
 */
function lsr_schedule_cron_jobs(): void
{
    // Hourly housekeeping
    if (!wp_next_scheduled('lsr_cron_housekeeping')) {
        wp_schedule_event(time() + 300, 'hourly', 'lsr_cron_housekeeping');
    }
    
    // Daily cleanup
    if (!wp_next_scheduled('lsr_daily_cleanup')) {
        wp_schedule_event(time() + 600, 'daily', 'lsr_daily_cleanup');
    }
}

/**
 * Clear plugin-related transients.
 */
function lsr_clear_plugin_transients(): void
{
    global $wpdb;
    
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_lsr_%' 
         OR option_name LIKE '_transient_timeout_lsr_%'"
    );
}

/**
 * Initialize the plugin.
 * 
 * This is the main entry point after all plugins are loaded.
 */
function lsr_initialize_plugin(): void
{
    // Check requirements
    if (!lsr_check_requirements()) {
        return;
    }
    
    try {
        // Initialize autoloader
        lsr_initialize_autoloader();
        
        // Load text domain
        lsr_load_textdomain();
        
        // Initialize the enhanced bootstrap
        $bootstrap = \MyShop\LicenseServer\EnhancedBootstrap::instance();
        $bootstrap->initialize();
        
        // Register global helper function
        $GLOBALS['lsr_container'] = $bootstrap;
        
    } catch (\Exception $e) {
        error_log('[License Server] Initialization failed: ' . $e->getMessage());
        
        // Show admin notice
        add_action('admin_notices', function () use ($e) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('License Server failed to initialize: ', 'license-server');
            echo esc_html($e->getMessage());
            echo '</p></div>';
        });
    }
}

/**
 * Plugin upgrade handler.
 * 
 * Handles version upgrades and migrations.
 */
function lsr_handle_upgrade(): void
{
    $currentVersion = get_option('lsr_version', '0.0.0');
    
    if (version_compare($currentVersion, LSR_VERSION, '<')) {
        try {
            // Run upgrade routine
            lsr_upgrade_from_version($currentVersion);
            
            // Update version
            update_option('lsr_version', LSR_VERSION);
            
            error_log("[License Server] Upgraded from {$currentVersion} to " . LSR_VERSION);
            
        } catch (\Exception $e) {
            error_log('[License Server] Upgrade failed: ' . $e->getMessage());
        }
    }
}

/**
 * Handle version-specific upgrades.
 *
 * @param string $fromVersion Version upgrading from
 */
function lsr_upgrade_from_version(string $fromVersion): void
{
    if (version_compare($fromVersion, '2.0.0', '<')) {
        // Major upgrade to v2.0.0
        \MyShop\LicenseServer\Data\EnhancedMigrations::run();
        
        // Initialize new configuration system
        $config = new \MyShop\LicenseServer\Domain\Services\ConfigurationService();
        $config->initialize();
        
        // Create new storage directories
        lsr_create_storage_directories();
    }
}

/**
 * Global helper function to access the service container.
 *
 * @param string|null $serviceId Service identifier
 * @return mixed Service instance or container
 */
function lsr(?string $serviceId = null)
{
    static $container = null;
    
    if ($container === null) {
        $container = $GLOBALS['lsr_container'] ?? null;
        
        if (!$container) {
            throw new \RuntimeException('License Server container not initialized');
        }
    }
    
    return $serviceId ? $container->get($serviceId) : $container;
}

/**
 * Add plugin action links.
 *
 * @param array $links Existing links
 * @return array Modified links
 */
function lsr_add_plugin_action_links(array $links): array
{
    $customLinks = [
        '<a href="' . esc_url(admin_url('admin.php?page=lsr-settings')) . '">' 
            . esc_html__('Settings', 'license-server') . '</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=lsr-licenses')) . '">' 
            . esc_html__('Licenses', 'license-server') . '</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=lsr-releases')) . '">' 
            . esc_html__('Releases', 'license-server') . '</a>',
        '<a href="' . esc_url(admin_url('admin.php?page=lsr-system-status')) . '">' 
            . esc_html__('System Status', 'license-server') . '</a>',
    ];
    
    return array_merge($customLinks, $links);
}

/**
 * Add plugin row meta links.
 *
 * @param array $links Existing links
 * @param string $file Plugin file
 * @return array Modified links
 */
function lsr_add_plugin_row_meta(array $links, string $file): array
{
    if ($file !== LSR_BASENAME) {
        return $links;
    }
    
    $metaLinks = [
        '<a href="https://example.com/docs/license-server" target="_blank">' 
            . esc_html__('Documentation', 'license-server') . '</a>',
        '<a href="https://example.com/support" target="_blank">' 
            . esc_html__('Support', 'license-server') . '</a>',
        '<a href="https://github.com/example/license-server" target="_blank">' 
            . esc_html__('GitHub', 'license-server') . '</a>',
    ];
    
    return array_merge($links, $metaLinks);
}

/**
 * Handle plugin uninstall (when file uninstall.php is called).
 */
function lsr_handle_uninstall(): void
{
    // This function is called from uninstall.php
    if (class_exists('\MyShop\LicenseServer\Data\EnhancedMigrations')) {
        \MyShop\LicenseServer\Data\EnhancedMigrations::cleanUninstall();
    }
}

/**
 * Security: Prevent directory browsing and direct file access.
 */
function lsr_add_security_headers(): void
{
    if (!is_admin() && strpos($_SERVER['REQUEST_URI'], '/wp-content/plugins/' . LSR_PLUGIN_SLUG) !== false) {
        // Prevent direct access to plugin files
        if (!defined('WPINC')) {
            http_response_code(403);
            exit('Direct access forbidden.');
        }
    }
}

// Hook registration
register_activation_hook(__FILE__, 'lsr_activate_plugin');
register_deactivation_hook(__FILE__, 'lsr_deactivate_plugin');

// Initialize plugin after all plugins are loaded
add_action('plugins_loaded', 'lsr_initialize_plugin', 5);

// Handle upgrades
add_action('plugins_loaded', 'lsr_handle_upgrade', 6);

// Plugin links
add_filter('plugin_action_links_' . LSR_BASENAME, 'lsr_add_plugin_action_links');
add_filter('plugin_row_meta', 'lsr_add_plugin_row_meta', 10, 2);

// Security headers
add_action('init', 'lsr_add_security_headers', 1);

// Text domain loading
add_action('init', 'lsr_load_textdomain');

// Admin-only functionality
if (is_admin()) {
    // Check for required updates on admin pages
    add_action('admin_init', function () {
        lsr_handle_upgrade();
    });
    
    // Add admin notices for important information
    add_action('admin_notices', function () {
        // Show upgrade notice if needed
        $currentVersion = get_option('lsr_version', '0.0.0');
        if (version_compare($currentVersion, LSR_VERSION, '<')) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('License Server database update required. Please visit any admin page to complete the update.', 'license-server');
            echo '</p></div>';
        }
    });
}

// CLI support (if WP-CLI is available)
if (defined('WP_CLI') && WP_CLI) {
    // Register CLI commands when WP-CLI is available
    add_action('plugins_loaded', function () {
        if (class_exists('\WP_CLI')) {
            try {
                \WP_CLI::add_command('lsr', '\MyShop\LicenseServer\CLI\LicenseServerCommand');
            } catch (\Exception $e) {
                error_log('[License Server] Failed to register CLI commands: ' . $e->getMessage());
            }
        }
    }, 20);
}

// Development and debugging hooks
if (defined('WP_DEBUG') && WP_DEBUG) {
    // Additional debugging information in development
    add_action('wp_footer', function () {
        if (current_user_can('manage_options')) {
            echo '<!-- License Server Debug Info: Version ' . esc_html(LSR_VERSION) . ' -->';
        }
    });
}

// Compatibility checks
add_action('admin_init', function () {
    // Check for conflicting plugins
    $conflictingPlugins = [
        'old-license-server/license-server.php',
        'competitor-license/main.php'
    ];
    
    foreach ($conflictingPlugins as $plugin) {
        if (is_plugin_active($plugin)) {
            add_action('admin_notices', function () use ($plugin) {
                echo '<div class="notice notice-error"><p>';
                echo sprintf(
                    /* translators: %s: conflicting plugin name */
                    esc_html__('License Server detected a conflicting plugin: %s. Please deactivate it to avoid issues.', 'license-server'),
                    esc_html($plugin)
                );
                echo '</p></div>';
            });
        }
    }
});

// Performance monitoring (if enabled)
if (defined('LSR_PERFORMANCE_MONITORING') && LSR_PERFORMANCE_MONITORING) {
    add_action('shutdown', function () {
        $stats = [
            'memory_peak' => memory_get_peak_usage(true),
            'memory_current' => memory_get_usage(true),
            'time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
        ];
        
        error_log('[License Server Performance] ' . wp_json_encode($stats));
    });
}

// Final initialization check
add_action('init', function () {
    // Verify that the plugin initialized correctly
    if (!isset($GLOBALS['lsr_container'])) {
        error_log('[License Server] Warning: Plugin container not initialized properly');
    }
}, 999);
