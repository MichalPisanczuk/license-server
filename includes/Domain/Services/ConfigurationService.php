<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Services;

/**
 * Configuration Management Service
 * 
 * Centralized configuration management with validation, caching, and type safety.
 */
class ConfigurationService
{
    /** @var string Option prefix */
    private const OPTION_PREFIX = 'lsr_';
    
    /** @var array Configuration cache */
    private array $cache = [];
    
    /** @var array Default configuration values */
    private array $defaults = [
        // Security settings
        'encryption_key' => '',
        'hash_salt' => '',
        'csrf_signing_secret' => '',
        'max_failed_attempts' => 10,
        'block_duration' => 3600, // 1 hour
        'security_mode' => 'standard', // standard, strict, paranoid
        
        // API settings
        'signed_url_ttl' => 300, // 5 minutes
        'api_rate_limit_requests' => 60,
        'api_rate_limit_window' => 300, // 5 minutes
        'enable_api_logging' => true,
        'api_timeout' => 30,
        
        // License settings
        'grace_period_days' => 7,
        'default_max_activations' => null,
        'auto_deactivate_expired' => true,
        'license_key_format' => 'standard', // standard, compact, custom
        
        // Cache settings
        'cache_enabled' => true,
        'cache_ttl' => 3600, // 1 hour
        'cache_backend' => 'wordpress', // wordpress, redis, memcached
        
        // Cleanup settings
        'cleanup_interval' => 'daily',
        'keep_logs_days' => 30,
        'keep_security_events_days' => 90,
        'keep_api_requests_days' => 7,
        
        // Performance settings
        'enable_statistics' => true,
        'enable_performance_monitoring' => false,
        'query_cache_enabled' => true,
        
        // Email settings
        'email_notifications_enabled' => true,
        'admin_email_alerts' => true,
        'security_alert_threshold' => 'medium',
        
        // Debug settings
        'debug_mode' => false,
        'verbose_logging' => false,
        'enable_profiling' => false
    ];
    
    /** @var array Configuration validation rules */
    private array $validation = [
        'signed_url_ttl' => ['type' => 'int', 'min' => 60, 'max' => 3600],
        'max_failed_attempts' => ['type' => 'int', 'min' => 1, 'max' => 100],
        'block_duration' => ['type' => 'int', 'min' => 300, 'max' => 86400],
        'grace_period_days' => ['type' => 'int', 'min' => 0, 'max' => 90],
        'api_rate_limit_requests' => ['type' => 'int', 'min' => 10, 'max' => 1000],
        'api_rate_limit_window' => ['type' => 'int', 'min' => 60, 'max' => 3600],
        'cache_ttl' => ['type' => 'int', 'min' => 60, 'max' => 86400],
        'keep_logs_days' => ['type' => 'int', 'min' => 1, 'max' => 365],
        'keep_security_events_days' => ['type' => 'int', 'min' => 30, 'max' => 730],
        'keep_api_requests_days' => ['type' => 'int', 'min' => 1, 'max' => 90],
        'security_mode' => ['type' => 'enum', 'values' => ['standard', 'strict', 'paranoid']],
        'cache_backend' => ['type' => 'enum', 'values' => ['wordpress', 'redis', 'memcached']],
        'cleanup_interval' => ['type' => 'enum', 'values' => ['hourly', 'daily', 'weekly']],
        'license_key_format' => ['type' => 'enum', 'values' => ['standard', 'compact', 'custom']],
        'security_alert_threshold' => ['type' => 'enum', 'values' => ['low', 'medium', 'high', 'critical']]
    ];

    /**
     * Get configuration value.
     *
     * @param string $key Configuration key (without prefix)
     * @param mixed $default Default value if not set
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        // Check cache first
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        
        // Get from database
        $optionName = self::OPTION_PREFIX . $key;
        $value = get_option($optionName, null);
        
        // Use provided default, then class default, then null
        if ($value === null) {
            $value = $default ?? $this->defaults[$key] ?? null;
        }
        
        // Type casting and validation
        $value = $this->castValue($key, $value);
        
        // Cache the value
        $this->cache[$key] = $value;
        
        return $value;
    }

    /**
     * Set configuration value.
     *
     * @param string $key Configuration key (without prefix)
     * @param mixed $value Configuration value
     * @param bool $validate Whether to validate the value
     * @return bool
     * @throws \InvalidArgumentException If validation fails
     */
    public function set(string $key, $value, bool $validate = true): bool
    {
        if ($validate) {
            $this->validateValue($key, $value);
        }
        
        $optionName = self::OPTION_PREFIX . $key;
        $result = update_option($optionName, $value);
        
        // Update cache
        if ($result) {
            $this->cache[$key] = $value;
            
            // Trigger action for configuration changes
            do_action('lsr_config_updated', $key, $value, $this->get($key, null));
        }
        
        return $result;
    }

    /**
     * Set multiple configuration values.
     *
     * @param array $values Key-value pairs
     * @param bool $validate Whether to validate values
     * @return array Results for each key
     */
    public function setMultiple(array $values, bool $validate = true): array
    {
        $results = [];
        
        foreach ($values as $key => $value) {
            try {
                $results[$key] = $this->set($key, $value, $validate);
            } catch (\Exception $e) {
                $results[$key] = false;
                error_log("[License Server Config] Failed to set {$key}: " . $e->getMessage());
            }
        }
        
        return $results;
    }

    /**
     * Delete configuration value.
     *
     * @param string $key Configuration key (without prefix)
     * @return bool
     */
    public function delete(string $key): bool
    {
        $optionName = self::OPTION_PREFIX . $key;
        $result = delete_option($optionName);
        
        // Remove from cache
        unset($this->cache[$key]);
        
        if ($result) {
            do_action('lsr_config_deleted', $key);
        }
        
        return $result;
    }

    /**
     * Check if configuration key exists.
     *
     * @param string $key Configuration key (without prefix)
     * @return bool
     */
    public function has(string $key): bool
    {
        $optionName = self::OPTION_PREFIX . $key;
        return get_option($optionName, null) !== null;
    }

    /**
     * Get all configuration values.
     *
     * @param bool $includeDefaults Include default values for unset keys
     * @return array
     */
    public function getAll(bool $includeDefaults = true): array
    {
        $config = [];
        $keys = $includeDefaults ? array_keys($this->defaults) : [];
        
        // Add keys that exist in database
        global $wpdb;
        $existingKeys = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                self::OPTION_PREFIX . '%'
            )
        );
        
        foreach ($existingKeys as $optionName) {
            $key = str_replace(self::OPTION_PREFIX, '', $optionName);
            $keys[] = $key;
        }
        
        $keys = array_unique($keys);
        
        foreach ($keys as $key) {
            $config[$key] = $this->get($key);
        }
        
        return $config;
    }

    /**
     * Reset configuration to defaults.
     *
     * @param array $keys Specific keys to reset (empty = all)
     * @return bool
     */
    public function resetToDefaults(array $keys = []): bool
    {
        if (empty($keys)) {
            $keys = array_keys($this->defaults);
        }
        
        $success = true;
        
        foreach ($keys as $key) {
            if (isset($this->defaults[$key])) {
                if (!$this->set($key, $this->defaults[$key], false)) {
                    $success = false;
                }
            }
        }
        
        return $success;
    }

    /**
     * Validate configuration value.
     *
     * @param string $key Configuration key
     * @param mixed $value Value to validate
     * @throws \InvalidArgumentException If validation fails
     */
    private function validateValue(string $key, $value): void
    {
        if (!isset($this->validation[$key])) {
            return; // No validation rules for this key
        }
        
        $rules = $this->validation[$key];
        
        // Type validation
        if (isset($rules['type'])) {
            switch ($rules['type']) {
                case 'int':
                    if (!is_int($value) && !ctype_digit((string)$value)) {
                        throw new \InvalidArgumentException("Configuration '{$key}' must be an integer.");
                    }
                    $value = (int) $value;
                    break;
                    
                case 'string':
                    if (!is_string($value)) {
                        throw new \InvalidArgumentException("Configuration '{$key}' must be a string.");
                    }
                    break;
                    
                case 'bool':
                    if (!is_bool($value)) {
                        throw new \InvalidArgumentException("Configuration '{$key}' must be a boolean.");
                    }
                    break;
                    
                case 'array':
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException("Configuration '{$key}' must be an array.");
                    }
                    break;
                    
                case 'enum':
                    if (!in_array($value, $rules['values'])) {
                        $allowed = implode(', ', $rules['values']);
                        throw new \InvalidArgumentException("Configuration '{$key}' must be one of: {$allowed}.");
                    }
                    break;
            }
        }
        
        // Range validation for integers
        if (isset($rules['min']) && $value < $rules['min']) {
            throw new \InvalidArgumentException("Configuration '{$key}' must be at least {$rules['min']}.");
        }
        
        if (isset($rules['max']) && $value > $rules['max']) {
            throw new \InvalidArgumentException("Configuration '{$key}' cannot exceed {$rules['max']}.");
        }
        
        // String length validation
        if (isset($rules['minlength']) && strlen($value) < $rules['minlength']) {
            throw new \InvalidArgumentException("Configuration '{$key}' must be at least {$rules['minlength']} characters.");
        }
        
        if (isset($rules['maxlength']) && strlen($value) > $rules['maxlength']) {
            throw new \InvalidArgumentException("Configuration '{$key}' cannot exceed {$rules['maxlength']} characters.");
        }
    }

    /**
     * Cast value to appropriate type.
     *
     * @param string $key Configuration key
     * @param mixed $value Value to cast
     * @return mixed
     */
    private function castValue(string $key, $value)
    {
        if (!isset($this->validation[$key]['type'])) {
            return $value;
        }
        
        switch ($this->validation[$key]['type']) {
            case 'int':
                return (int) $value;
            case 'bool':
                return (bool) $value;
            case 'string':
                return (string) $value;
            case 'array':
                return is_array($value) ? $value : [];
            default:
                return $value;
        }
    }

    /**
     * Get configuration schema for form generation.
     *
     * @return array
     */
    public function getSchema(): array
    {
        $schema = [];
        
        foreach ($this->defaults as $key => $defaultValue) {
            $schema[$key] = [
                'default' => $defaultValue,
                'current' => $this->get($key),
                'validation' => $this->validation[$key] ?? []
            ];
        }
        
        return $schema;
    }

    /**
     * Initialize default configuration values.
     * 
     * Should be called during plugin activation.
     */
    public function initialize(): void
    {
        foreach ($this->defaults as $key => $value) {
            if (!$this->has($key)) {
                $this->set($key, $value, false);
            }
        }
        
        // Generate security keys if they don't exist
        $this->generateSecurityKeys();
    }

    /**
     * Generate security keys if they don't exist.
     */
    private function generateSecurityKeys(): void
    {
        $securityKeys = ['encryption_key', 'hash_salt', 'csrf_signing_secret'];
        
        foreach ($securityKeys as $key) {
            if (empty($this->get($key))) {
                $this->set($key, bin2hex(random_bytes(32)), false);
            }
        }
    }

    /**
     * Clear configuration cache.
     */
    public function clearCache(): void
    {
        $this->cache = [];
        do_action('lsr_config_cache_cleared');
    }

    /**
     * Get configuration grouped by category.
     *
     * @return array
     */
    public function getGrouped(): array
    {
        $grouped = [
            'security' => [],
            'api' => [],
            'license' => [],
            'cache' => [],
            'cleanup' => [],
            'performance' => [],
            'email' => [],
            'debug' => []
        ];
        
        foreach ($this->defaults as $key => $value) {
            $category = $this->getKeyCategory($key);
            $grouped[$category][$key] = $this->get($key);
        }
        
        return $grouped;
    }

    /**
     * Get category for configuration key.
     *
     * @param string $key Configuration key
     * @return string
     */
    private function getKeyCategory(string $key): string
    {
        $categories = [
            'security' => ['encryption_key', 'hash_salt', 'csrf_signing_secret', 'max_failed_attempts', 'block_duration', 'security_mode'],
            'api' => ['signed_url_ttl', 'api_rate_limit_requests', 'api_rate_limit_window', 'enable_api_logging', 'api_timeout'],
            'license' => ['grace_period_days', 'default_max_activations', 'auto_deactivate_expired', 'license_key_format'],
            'cache' => ['cache_enabled', 'cache_ttl', 'cache_backend', 'query_cache_enabled'],
            'cleanup' => ['cleanup_interval', 'keep_logs_days', 'keep_security_events_days', 'keep_api_requests_days'],
            'performance' => ['enable_statistics', 'enable_performance_monitoring'],
            'email' => ['email_notifications_enabled', 'admin_email_alerts', 'security_alert_threshold'],
            'debug' => ['debug_mode', 'verbose_logging', 'enable_profiling']
        ];
        
        foreach ($categories as $category => $keys) {
            if (in_array($key, $keys)) {
                return $category;
            }
        }
        
        return 'misc';
    }

    /**
     * Export configuration for backup.
     *
     * @param bool $includeSecrets Include security keys
     * @return array
     */
    public function export(bool $includeSecrets = false): array
    {
        $config = $this->getAll(false);
        
        if (!$includeSecrets) {
            $secretKeys = ['encryption_key', 'hash_salt', 'csrf_signing_secret'];
            foreach ($secretKeys as $key) {
                unset($config[$key]);
            }
        }
        
        return [
            'version' => LSR_VERSION,
            'exported_at' => current_time('mysql', 1),
            'config' => $config
        ];
    }

    /**
     * Import configuration from backup.
     *
     * @param array $data Export data
     * @param bool $overwrite Overwrite existing values
     * @return array Import results
     */
    public function import(array $data, bool $overwrite = false): array
    {
        if (!isset($data['config']) || !is_array($data['config'])) {
            throw new \InvalidArgumentException('Invalid configuration data format.');
        }
        
        $results = [];
        
        foreach ($data['config'] as $key => $value) {
            if (!$overwrite && $this->has($key)) {
                $results[$key] = 'skipped';
                continue;
            }
            
            try {
                if ($this->set($key, $value)) {
                    $results[$key] = 'success';
                } else {
                    $results[$key] = 'failed';
                }
            } catch (\Exception $e) {
                $results[$key] = 'error: ' . $e->getMessage();
            }
        }
        
        return $results;
    }
}