<?php
namespace MyShop\LicenseServer\Data;

/**
 * Database migrations for License Server including security tables.
 * 
 * Handles creation and updates of all database tables needed for
 * license management, security monitoring, and API event tracking.
 */
class Migrations
{
    /**
     * Current schema version. Increment when making schema changes.
     */
    private const SCHEMA_VERSION = '1.1';

    /**
     * Execute all migrations (create/update tables).
     */
    public static function run(): void
    {
        $currentVersion = get_option('lsr_schema_version', '0.0');
        
        if (version_compare($currentVersion, self::SCHEMA_VERSION, '<')) {
            self::createTables();
            self::createIndexes();
            self::seedDefaultData();
            
            // Update schema version
            update_option('lsr_schema_version', self::SCHEMA_VERSION);
            
            error_log('[License Server] Database schema updated to version ' . self::SCHEMA_VERSION);
        }
    }

    /**
     * Create all required tables.
     */
    private static function createTables(): void
    {
        global $wpdb;
        $charsetCollate = $wpdb->get_charset_collate();

        $sql = self::getLicensesTableSQL($charsetCollate);
        $sql .= self::getActivationsTableSQL($charsetCollate);
        $sql .= self::getReleasesTableSQL($charsetCollate);
        $sql .= self::getSecurityEventsTableSQL($charsetCollate);
        $sql .= self::getApiEventsTableSQL($charsetCollate);
        $sql .= self::getSecurityReportsTableSQL($charsetCollate);

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Get licenses table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getLicensesTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_licenses';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            subscription_id BIGINT(20) UNSIGNED DEFAULT NULL,
            license_key VARCHAR(64) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL,
            grace_until DATETIME NULL,
            max_activations INT(11) UNSIGNED NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY license_key (license_key),
            KEY user_id (user_id),
            KEY product_id (product_id),
            KEY subscription_id (subscription_id),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) {$charsetCollate};\n";
    }

    /**
     * Get activations table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getActivationsTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_activations';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL,
            ip_hash CHAR(64) DEFAULT NULL,
            user_agent_hash CHAR(64) DEFAULT NULL,
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            validation_count INT(11) UNSIGNED DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            PRIMARY KEY (id),
            UNIQUE KEY license_domain (license_id, domain),
            KEY license_id (license_id),
            KEY domain (domain),
            KEY last_seen_at (last_seen_at),
            KEY is_active (is_active),
            CONSTRAINT fk_activation_license FOREIGN KEY (license_id) REFERENCES {$wpdb->prefix}lsr_licenses(id) ON DELETE CASCADE
        ) {$charsetCollate};\n";
    }

    /**
     * Get releases table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getReleasesTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_releases';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            slug VARCHAR(191) NOT NULL,
            version VARCHAR(30) NOT NULL,
            zip_path VARCHAR(500) NOT NULL,
            checksum_sha256 CHAR(64) NOT NULL,
            file_size BIGINT(20) UNSIGNED DEFAULT NULL,
            min_php VARCHAR(10) DEFAULT NULL,
            min_wp VARCHAR(10) DEFAULT NULL,
            tested_wp VARCHAR(10) DEFAULT NULL,
            changelog LONGTEXT DEFAULT NULL,
            download_count INT(11) UNSIGNED DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            released_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY slug (slug),
            KEY version (version),
            KEY slug_version (slug, version),
            KEY released_at (released_at),
            KEY is_active (is_active)
        ) {$charsetCollate};\n";
    }

    /**
     * Get security events table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getSecurityEventsTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(50) NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
            ip VARCHAR(45) NOT NULL,
            user_agent TEXT,
            endpoint VARCHAR(255),
            license_key_hash CHAR(64) DEFAULT NULL,
            domain VARCHAR(255) DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event (event),
            KEY severity (severity),
            KEY ip (ip),
            KEY endpoint (endpoint),
            KEY license_key_hash (license_key_hash),
            KEY domain (domain),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY event_severity (event, severity),
            KEY ip_created (ip, created_at)
        ) {$charsetCollate};\n";
    }

    /**
     * Get API events table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getApiEventsTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_api_events';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(50) NOT NULL,
            component VARCHAR(50) NOT NULL,
            endpoint VARCHAR(255),
            method VARCHAR(10),
            ip VARCHAR(45),
            user_agent TEXT,
            license_id BIGINT(20) UNSIGNED DEFAULT NULL,
            license_key_hash CHAR(64) DEFAULT NULL,
            domain VARCHAR(255) DEFAULT NULL,
            response_code INT(11) UNSIGNED DEFAULT NULL,
            response_time_ms DECIMAL(10,3) DEFAULT NULL,
            data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event (event),
            KEY component (component),
            KEY endpoint (endpoint),
            KEY method (method),
            KEY ip (ip),
            KEY license_id (license_id),
            KEY license_key_hash (license_key_hash),
            KEY domain (domain),
            KEY response_code (response_code),
            KEY created_at (created_at),
            KEY event_component (event, component),
            KEY ip_created (ip, created_at)
        ) {$charsetCollate};\n";
    }

    /**
     * Get security reports table SQL.
     *
     * @param string $charsetCollate
     * @return string
     */
    private static function getSecurityReportsTableSQL(string $charsetCollate): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_reports';

        return "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            report_type VARCHAR(50) NOT NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            total_events INT(11) UNSIGNED DEFAULT 0,
            blocked_ips INT(11) UNSIGNED DEFAULT 0,
            failed_attempts INT(11) UNSIGNED DEFAULT 0,
            report_data LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY report_type (report_type),
            KEY period_start (period_start),
            KEY period_end (period_end),
            KEY created_at (created_at)
        ) {$charsetCollate};\n";
    }

    /**
     * Create additional indexes for performance.
     */
    private static function createIndexes(): void
    {
        global $wpdb;

        // Composite indexes for common queries
        $indexes = [
            // Licenses table
            $wpdb->prefix . 'lsr_licenses' => [
                'idx_user_status' => ['user_id', 'status'],
                'idx_product_status' => ['product_id', 'status'],
                'idx_status_expires' => ['status', 'expires_at']
            ],
            
            // Activations table
            $wpdb->prefix . 'lsr_activations' => [
                'idx_license_active' => ['license_id', 'is_active'],
                'idx_domain_active' => ['domain', 'is_active']
            ],
            
            // Security events table
            $wpdb->prefix . 'lsr_security_events' => [
                'idx_ip_severity_created' => ['ip', 'severity', 'created_at'],
                'idx_event_created' => ['event', 'created_at']
            ],
            
            // API events table  
            $wpdb->prefix . 'lsr_api_events' => [
                'idx_component_event_created' => ['component', 'event', 'created_at'],
                'idx_ip_endpoint_created' => ['ip', 'endpoint', 'created_at']
            ]
        ];

        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $indexName => $columns) {
                self::createIndexIfNotExists($table, $indexName, $columns);
            }
        }
    }

    /**
     * Create index if it doesn't exist.
     *
     * @param string $table
     * @param string $indexName
     * @param array $columns
     */
    private static function createIndexIfNotExists(string $table, string $indexName, array $columns): void
    {
        global $wpdb;

        // Check if index exists
        $indexExists = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW INDEX FROM {$table} WHERE Key_name = %s",
                $indexName
            )
        );

        if (!$indexExists) {
            $columnsList = implode(', ', $columns);
            $wpdb->query("CREATE INDEX {$indexName} ON {$table} ({$columnsList})");
        }
    }

    /**
     * Seed default data and settings.
     */
    private static function seedDefaultData(): void
    {
        $defaultOptions = [
            'lsr_signed_url_ttl' => 300,
            'lsr_developer_domains' => "localhost\nlocal\ntest\n*.local\n*.test\n*.dev",
            'lsr_enable_api_logging' => true,
            'lsr_enable_security_logging' => true,
            'lsr_enable_cron_logging' => true,
            'lsr_enable_validation_logging' => false,
            'lsr_max_download_attempts' => 10,
            'lsr_rate_limit_window' => 300,
            'lsr_rate_limit_requests' => 60,
            'lsr_block_duration' => 3600,
            'lsr_enable_security_reports' => false,
            'lsr_email_security_reports' => false,
            'lsr_notify_on_block' => false,
            'lsr_blocked_ips' => [],
            'lsr_security_level' => 'medium'
        ];

        foreach ($defaultOptions as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }

        // Generate signing secret if not exists
        if (!get_option('lsr_signing_secret')) {
            $secret = bin2hex(random_bytes(32));
            add_option('lsr_signing_secret', $secret);
        }

        // Generate API salt if not exists
        if (!get_option('lsr_api_salt')) {
            $salt = bin2hex(random_bytes(16));
            add_option('lsr_api_salt', $salt);
        }
    }

    /**
     * Get current schema version.
     *
     * @return string
     */
    public static function getCurrentVersion(): string
    {
        return get_option('lsr_schema_version', '0.0');
    }

    /**
     * Get target schema version.
     *
     * @return string
     */
    public static function getTargetVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    /**
     * Check if migration is needed.
     *
     * @return bool
     */
    public static function isMigrationNeeded(): bool
    {
        return version_compare(self::getCurrentVersion(), self::SCHEMA_VERSION, '<');
    }

    /**
     * Get migration status information.
     *
     * @return array
     */
    public static function getMigrationStatus(): array
    {
        global $wpdb;

        $status = [
            'current_version' => self::getCurrentVersion(),
            'target_version' => self::SCHEMA_VERSION,
            'migration_needed' => self::isMigrationNeeded(),
            'tables' => []
        ];

        $tables = [
            'licenses' => $wpdb->prefix . 'lsr_licenses',
            'activations' => $wpdb->prefix . 'lsr_activations',
            'releases' => $wpdb->prefix . 'lsr_releases',
            'security_events' => $wpdb->prefix . 'lsr_security_events',
            'api_events' => $wpdb->prefix . 'lsr_api_events',
            'security_reports' => $wpdb->prefix . 'lsr_security_reports'
        ];

        foreach ($tables as $name => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
            $rowCount = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;
            
            $status['tables'][$name] = [
                'table_name' => $table,
                'exists' => $exists,
                'row_count' => $rowCount
            ];
        }

        return $status;
    }

    /**
     * Rollback to previous schema version (if needed).
     * 
     * WARNING: This will drop security tables and lose data!
     */
    public static function rollback(): void
    {
        global $wpdb;

        // Only rollback security tables, keep core license data
        $securityTables = [
            $wpdb->prefix . 'lsr_security_events',
            $wpdb->prefix . 'lsr_api_events', 
            $wpdb->prefix . 'lsr_security_reports'
        ];

        foreach ($securityTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        // Rollback schema version
        update_option('lsr_schema_version', '1.0');

        error_log('[License Server] Database schema rolled back to version 1.0');
    }

    /**
     * Clean install - drop all tables and recreate.
     * 
     * WARNING: This will delete ALL license server data!
     */
    public static function cleanInstall(): void
    {
        global $wpdb;

        $allTables = [
            $wpdb->prefix . 'lsr_licenses',
            $wpdb->prefix . 'lsr_activations',
            $wpdb->prefix . 'lsr_releases',
            $wpdb->prefix . 'lsr_security_events',
            $wpdb->prefix . 'lsr_api_events',
            $wpdb->prefix . 'lsr_security_reports'
        ];

        foreach ($allTables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }

        // Reset schema version and options
        delete_option('lsr_schema_version');
        delete_option('lsr_signing_secret');
        delete_option('lsr_api_salt');

        // Recreate everything
        self::run();

        error_log('[License Server] Clean installation completed');
    }
}