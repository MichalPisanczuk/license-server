<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Data;

use MyShop\LicenseServer\Domain\Services\SecureLicenseKeyService;

/**
 * Enhanced Database Migrations for License Server
 * 
 * Includes security improvements, proper indexing, and data integrity features.
 */
class EnhancedMigrations
{
    /** @var string Current schema version */
    private const SCHEMA_VERSION = '2.0';
    
    /** @var string Option name for schema version */
    private const VERSION_OPTION = 'lsr_schema_version';

    /**
     * Run all migrations to current version.
     */
    public static function run(): void
    {
        $currentVersion = get_option(self::VERSION_OPTION, '0.0');
        
        try {
            if (version_compare($currentVersion, '2.0', '<')) {
                self::migrateToVersion2();
            }
            
            // Update version after successful migration
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
            
            error_log('[License Server] Database migrated to version ' . self::SCHEMA_VERSION);
            
        } catch (\Exception $e) {
            error_log('[License Server] Migration failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Migrate to version 2.0 (enhanced security and performance).
     */
    private static function migrateToVersion2(): void
    {
        global $wpdb;
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $wpdb->query('START TRANSACTION');
        
        try {
            // Create enhanced tables
            self::createEnhancedLicensesTable();
            self::createEnhancedActivationsTable();
            self::createEnhancedReleasesTable();
            self::createSecurityTables();
            self::createPerformanceTables();
            
            // Migrate existing data if needed
            self::migrateExistingData();
            
            // Create indexes for performance
            self::createIndexes();
            
            // Setup default configuration
            self::setupDefaultConfiguration();
            
            $wpdb->query('COMMIT');
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Create enhanced licenses table with security improvements.
     */
    private static function createEnhancedLicensesTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_licenses';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED DEFAULT NULL,
            subscription_id BIGINT(20) UNSIGNED DEFAULT NULL,
            
            -- Security: Store only hashed license keys, never plain text
            license_key_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hash of license key',
            license_key_verification CHAR(64) NOT NULL COMMENT 'Verification hash for API lookups',
            
            status ENUM('active', 'inactive', 'expired', 'suspended') NOT NULL DEFAULT 'active',
            expires_at DATETIME NULL COMMENT 'License expiration date',
            grace_until DATETIME NULL COMMENT 'Grace period end date',
            max_activations INT(11) UNSIGNED NULL COMMENT 'Maximum allowed activations (NULL = unlimited)',
            
            -- Metadata
            notes TEXT NULL COMMENT 'Admin notes',
            metadata JSON NULL COMMENT 'Additional license metadata',
            
            -- Security tracking
            created_ip VARCHAR(45) NULL COMMENT 'IP address when license was created',
            last_validation_at DATETIME NULL COMMENT 'Last successful validation',
            failed_attempts INT DEFAULT 0 COMMENT 'Failed validation attempts',
            
            -- Timestamps
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_license_hash (license_key_hash),
            UNIQUE KEY uk_verification_hash (license_key_verification),
            KEY idx_user_id (user_id),
            KEY idx_product_id (product_id),
            KEY idx_order_id (order_id),
            KEY idx_subscription_id (subscription_id),
            KEY idx_status (status),
            KEY idx_expires_at (expires_at),
            KEY idx_last_validation (last_validation_at),
            KEY idx_created_at (created_at),
            
            -- Foreign key constraints
            CONSTRAINT fk_license_user FOREIGN KEY (user_id) REFERENCES {$wpdb->users}(ID) ON DELETE CASCADE,
            CONSTRAINT fk_license_order FOREIGN KEY (order_id) REFERENCES {$wpdb->prefix}posts(ID) ON DELETE SET NULL
        ) {$charset}";

        dbDelta($sql);
    }

    /**
     * Create enhanced activations table.
     */
    private static function createEnhancedActivationsTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_activations';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            license_id BIGINT(20) UNSIGNED NOT NULL,
            domain VARCHAR(255) NOT NULL COMMENT 'Normalized domain name',
            
            -- Security tracking
            ip_hash CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of IP address',
            user_agent_hash CHAR(64) DEFAULT NULL COMMENT 'SHA-256 hash of user agent',
            fingerprint_hash CHAR(64) DEFAULT NULL COMMENT 'Browser fingerprint hash',
            
            -- Activity tracking
            activated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            validation_count INT(11) UNSIGNED DEFAULT 0 COMMENT 'Number of successful validations',
            failed_validations INT(11) UNSIGNED DEFAULT 0 COMMENT 'Failed validation attempts',
            
            -- Status
            is_active BOOLEAN DEFAULT TRUE,
            deactivated_at DATETIME NULL,
            deactivated_reason VARCHAR(100) NULL,
            
            -- Metadata
            metadata JSON NULL COMMENT 'Additional activation data',
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_license_domain (license_id, domain),
            KEY idx_license_id (license_id),
            KEY idx_domain (domain),
            KEY idx_last_seen (last_seen_at),
            KEY idx_is_active (is_active),
            KEY idx_validation_count (validation_count),
            
            CONSTRAINT fk_activation_license FOREIGN KEY (license_id) REFERENCES {$wpdb->prefix}lsr_licenses(id) ON DELETE CASCADE
        ) {$charset}";

        dbDelta($sql);
    }

    /**
     * Create enhanced releases table.
     */
    private static function createEnhancedReleasesTable(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_releases';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            slug VARCHAR(100) NOT NULL COMMENT 'Plugin slug',
            version VARCHAR(50) NOT NULL,
            
            -- File information
            file_path VARCHAR(500) NOT NULL COMMENT 'Relative path to ZIP file',
            file_size BIGINT UNSIGNED NOT NULL COMMENT 'File size in bytes',
            file_hash CHAR(64) NOT NULL COMMENT 'SHA-256 hash of file',
            
            -- Release information
            release_notes TEXT NULL,
            minimum_wp_version VARCHAR(20) NULL,
            minimum_php_version VARCHAR(20) NULL,
            tested_up_to VARCHAR(20) NULL,
            
            -- Security
            is_security_release BOOLEAN DEFAULT FALSE,
            requires_force_update BOOLEAN DEFAULT FALSE,
            
            -- Status
            is_active BOOLEAN DEFAULT TRUE,
            is_beta BOOLEAN DEFAULT FALSE,
            download_count INT UNSIGNED DEFAULT 0,
            
            -- Metadata
            metadata JSON NULL,
            
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_product_slug_version (product_id, slug, version),
            KEY idx_product_id (product_id),
            KEY idx_slug (slug),
            KEY idx_version (version),
            KEY idx_is_active (is_active),
            KEY idx_created_at (created_at),
            
            CONSTRAINT fk_release_product FOREIGN KEY (product_id) REFERENCES {$wpdb->prefix}posts(ID) ON DELETE CASCADE
        ) {$charset}";

        dbDelta($sql);
    }

    /**
     * Create security monitoring tables.
     */
    private static function createSecurityTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Security events table
        $securityTable = $wpdb->prefix . 'lsr_security_events';
        $sql = "CREATE TABLE {$securityTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(50) NOT NULL,
            severity ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            license_id BIGINT(20) UNSIGNED NULL,
            user_id BIGINT(20) UNSIGNED NULL,
            details JSON NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_event_type (event_type),
            KEY idx_severity (severity),
            KEY idx_ip_address (ip_address),
            KEY idx_created_at (created_at),
            KEY idx_license_id (license_id),
            
            CONSTRAINT fk_security_license FOREIGN KEY (license_id) REFERENCES {$wpdb->prefix}lsr_licenses(id) ON DELETE SET NULL
        ) {$charset}";
        dbDelta($sql);

        // API request log table
        $apiLogTable = $wpdb->prefix . 'lsr_api_requests';
        $sql = "CREATE TABLE {$apiLogTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            endpoint VARCHAR(100) NOT NULL,
            method VARCHAR(10) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent_hash CHAR(64) NULL,
            license_key_hash CHAR(64) NULL,
            response_code INT NOT NULL,
            response_time DECIMAL(8,3) NOT NULL COMMENT 'Response time in milliseconds',
            request_size INT UNSIGNED NULL,
            response_size INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_endpoint (endpoint),
            KEY idx_ip_address (ip_address),
            KEY idx_response_code (response_code),
            KEY idx_created_at (created_at),
            KEY idx_license_hash (license_key_hash)
        ) {$charset}";
        dbDelta($sql);

        // Error log table
        $errorTable = $wpdb->prefix . 'lsr_error_log';
        $sql = "CREATE TABLE {$errorTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            exception_class VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            error_code VARCHAR(100),
            file VARCHAR(500),
            line INT,
            context LONGTEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            KEY idx_error_code (error_code),
            KEY idx_created_at (created_at),
            KEY idx_exception_class (exception_class)
        ) {$charset}";
        dbDelta($sql);
    }

    /**
     * Create performance optimization tables.
     */
    private static function createPerformanceTables(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // Cache table for frequently accessed data
        $cacheTable = $wpdb->prefix . 'lsr_cache';
        $sql = "CREATE TABLE {$cacheTable} (
            cache_key VARCHAR(255) NOT NULL,
            cache_value LONGTEXT NOT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (cache_key),
            KEY idx_expires_at (expires_at)
        ) {$charset}";
        dbDelta($sql);

        // Statistics table for reporting
        $statsTable = $wpdb->prefix . 'lsr_statistics';
        $sql = "CREATE TABLE {$statsTable} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_type VARCHAR(50) NOT NULL,
            stat_key VARCHAR(100) NOT NULL,
            stat_value DECIMAL(15,4) NOT NULL,
            metadata JSON NULL,
            period_start DATETIME NOT NULL,
            period_end DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            
            PRIMARY KEY (id),
            UNIQUE KEY uk_stat_period (stat_type, stat_key, period_start, period_end),
            KEY idx_stat_type (stat_type),
            KEY idx_period_start (period_start)
        ) {$charset}";
        dbDelta($sql);
    }

    /**
     * Migrate existing data from old structure to new.
     */
    private static function migrateExistingData(): void
    {
        global $wpdb;
        
        $oldLicensesTable = $wpdb->prefix . 'lsr_licenses';
        
        // Check if old table exists and has old structure
        $hasOldStructure = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = '{$oldLicensesTable}' 
            AND COLUMN_NAME = 'license_key'
        ");
        
        if ($hasOldStructure) {
            // Migrate plain text license keys to hashed format
            $oldLicenses = $wpdb->get_results("SELECT * FROM {$oldLicensesTable} WHERE license_key_hash IS NULL");
            
            if (!empty($oldLicenses)) {
                $keyService = new SecureLicenseKeyService();
                
                foreach ($oldLicenses as $license) {
                    if (!empty($license->license_key)) {
                        try {
                            $hashes = $keyService->hashLicenseKey($license->license_key);
                            
                            $wpdb->update(
                                $oldLicensesTable,
                                [
                                    'license_key_hash' => $hashes['hash'],
                                    'license_key_verification' => $hashes['verification_hash'],
                                    'license_key' => null // Clear plain text key
                                ],
                                ['id' => $license->id]
                            );
                        } catch (\Exception $e) {
                            error_log("Failed to migrate license {$license->id}: " . $e->getMessage());
                        }
                    }
                }
            }
            
            // Remove license_key column if it exists
            $wpdb->query("ALTER TABLE {$oldLicensesTable} DROP COLUMN IF EXISTS license_key");
        }
    }

    /**
     * Create additional performance indexes.
     */
    private static function createIndexes(): void
    {
        global $wpdb;
        
        $indexes = [
            // Composite indexes for common queries
            "CREATE INDEX IF NOT EXISTS idx_license_status_expires ON {$wpdb->prefix}lsr_licenses (status, expires_at)",
            "CREATE INDEX IF NOT EXISTS idx_license_product_status ON {$wpdb->prefix}lsr_licenses (product_id, status)",
            "CREATE INDEX IF NOT EXISTS idx_activation_domain_active ON {$wpdb->prefix}lsr_activations (domain, is_active)",
            "CREATE INDEX IF NOT EXISTS idx_release_slug_active ON {$wpdb->prefix}lsr_releases (slug, is_active, version)",
        ];
        
        foreach ($indexes as $sql) {
            $wpdb->query($sql);
        }
    }

    /**
     * Setup default configuration options.
     */
    private static function setupDefaultConfiguration(): void
    {
        $defaults = [
            'lsr_signed_url_ttl' => 300, // 5 minutes
            'lsr_grace_period_days' => 7,
            'lsr_enable_api_logging' => true,
            'lsr_max_failed_attempts' => 10,
            'lsr_security_mode' => 'standard',
            'lsr_cache_enabled' => true,
            'lsr_cache_ttl' => 3600, // 1 hour
            'lsr_cleanup_interval' => 'daily',
            'lsr_enable_statistics' => true,
        ];
        
        foreach ($defaults as $option => $value) {
            if (get_option($option) === false) {
                add_option($option, $value);
            }
        }
        
        // Generate security keys if they don't exist
        if (!get_option('lsr_encryption_key')) {
            update_option('lsr_encryption_key', bin2hex(random_bytes(32)));
        }
        
        if (!get_option('lsr_hash_salt')) {
            update_option('lsr_hash_salt', bin2hex(random_bytes(32)));
        }
        
        if (!get_option('lsr_csrf_signing_secret')) {
            update_option('lsr_csrf_signing_secret', bin2hex(random_bytes(32)));
        }
    }

    /**
     * Get migration status information.
     *
     * @return array
     */
    public static function getStatus(): array
    {
        global $wpdb;
        
        $currentVersion = get_option(self::VERSION_OPTION, '0.0');
        $targetVersion = self::SCHEMA_VERSION;
        
        $tables = [
            'licenses' => $wpdb->prefix . 'lsr_licenses',
            'activations' => $wpdb->prefix . 'lsr_activations',
            'releases' => $wpdb->prefix . 'lsr_releases',
            'security_events' => $wpdb->prefix . 'lsr_security_events',
            'api_requests' => $wpdb->prefix . 'lsr_api_requests',
            'error_log' => $wpdb->prefix . 'lsr_error_log',
            'cache' => $wpdb->prefix . 'lsr_cache',
            'statistics' => $wpdb->prefix . 'lsr_statistics'
        ];
        
        $tableStatus = [];
        foreach ($tables as $name => $table) {
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
            $rowCount = $exists ? (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}") : 0;
            
            $tableStatus[$name] = [
                'exists' => $exists,
                'row_count' => $rowCount,
                'table_name' => $table
            ];
        }
        
        return [
            'current_version' => $currentVersion,
            'target_version' => $targetVersion,
            'is_up_to_date' => version_compare($currentVersion, $targetVersion, '>='),
            'tables' => $tableStatus
        ];
    }

    /**
     * Clean uninstall - remove all data and options.
     */
    public static function cleanUninstall(): void
    {
        global $wpdb;
        
        // Drop all tables
        $tables = [
            $wpdb->prefix . 'lsr_licenses',
            $wpdb->prefix . 'lsr_activations', 
            $wpdb->prefix . 'lsr_releases',
            $wpdb->prefix . 'lsr_security_events',
            $wpdb->prefix . 'lsr_api_requests',
            $wpdb->prefix . 'lsr_error_log',
            $wpdb->prefix . 'lsr_cache',
            $wpdb->prefix . 'lsr_statistics'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS {$table}");
        }
        
        // Remove all options
        $options = [
            'lsr_schema_version',
            'lsr_encryption_key',
            'lsr_hash_salt',
            'lsr_csrf_signing_secret',
            'lsr_signed_url_ttl',
            'lsr_grace_period_days',
            'lsr_enable_api_logging',
            'lsr_max_failed_attempts',
            'lsr_security_mode',
            'lsr_cache_enabled',
            'lsr_cache_ttl',
            'lsr_cleanup_interval',
            'lsr_enable_statistics'
        ];
        
        foreach ($options as $option) {
            delete_option($option);
        }
        
        error_log('[License Server] Clean uninstall completed');
    }
}