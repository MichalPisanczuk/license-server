<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Services;

/**
 * Advanced Cache Service with multiple backend support and statistics.
 * 
 * Provides a unified caching interface with support for WordPress transients,
 * Redis, and Memcached backends with automatic failover.
 */
class CacheService
{
    /** @var string Cache key prefix */
    private const KEY_PREFIX = 'lsr_';
    
    /** @var string Default cache group */
    private const DEFAULT_GROUP = 'license_server';
    
    /** @var bool Whether caching is enabled */
    private bool $enabled;
    
    /** @var string Cache backend type */
    private string $backend;
    
    /** @var mixed Backend instance */
    private $backendInstance;
    
    /** @var array Cache statistics */
    private array $stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'deletes' => 0,
        'errors' => 0
    ];
    
    /** @var array Supported backends */
    private array $supportedBackends = ['wordpress', 'redis', 'memcached'];

    public function __construct(bool $enabled = true, string $backend = 'wordpress')
    {
        $this->enabled = $enabled;
        $this->backend = in_array($backend, $this->supportedBackends) ? $backend : 'wordpress';
        
        if ($this->enabled) {
            $this->initializeBackend();
        }
    }

    /**
     * Get value from cache.
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return mixed|false Value on success, false on failure
     */
    public function get(string $key, string $group = self::DEFAULT_GROUP)
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $cacheKey = $this->buildKey($key, $group);
            $value = $this->backendGet($cacheKey);
            
            if ($value !== false) {
                $this->stats['hits']++;
                return $this->unserializeValue($value);
            } else {
                $this->stats['misses']++;
                return false;
            }
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Get error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Set value in cache.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $expiration Expiration in seconds (0 = no expiration)
     * @param string $group Cache group
     * @return bool True on success, false on failure
     */
    public function set(string $key, $value, int $expiration = 3600, string $group = self::DEFAULT_GROUP): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $cacheKey = $this->buildKey($key, $group);
            $serializedValue = $this->serializeValue($value);
            
            $result = $this->backendSet($cacheKey, $serializedValue, $expiration);
            
            if ($result) {
                $this->stats['sets']++;
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Set error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete value from cache.
     *
     * @param string $key Cache key
     * @param string $group Cache group
     * @return bool True on success, false on failure
     */
    public function delete(string $key, string $group = self::DEFAULT_GROUP): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $cacheKey = $this->buildKey($key, $group);
            $result = $this->backendDelete($cacheKey);
            
            if ($result) {
                $this->stats['deletes']++;
            } else {
                $this->stats['errors']++;
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Delete error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get multiple values from cache.
     *
     * @param array $keys Array of cache keys
     * @param string $group Cache group
     * @return array Key-value pairs (missing keys will have false values)
     */
    public function getMultiple(array $keys, string $group = self::DEFAULT_GROUP): array
    {
        if (!$this->enabled || empty($keys)) {
            return array_fill_keys($keys, false);
        }

        $results = [];
        $cacheKeys = [];
        
        foreach ($keys as $key) {
            $cacheKey = $this->buildKey($key, $group);
            $cacheKeys[$cacheKey] = $key;
        }

        try {
            $cached = $this->backendGetMultiple(array_keys($cacheKeys));
            
            foreach ($cacheKeys as $cacheKey => $originalKey) {
                if (isset($cached[$cacheKey]) && $cached[$cacheKey] !== false) {
                    $results[$originalKey] = $this->unserializeValue($cached[$cacheKey]);
                    $this->stats['hits']++;
                } else {
                    $results[$originalKey] = false;
                    $this->stats['misses']++;
                }
            }
            
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Get multiple error: ' . $e->getMessage());
            $results = array_fill_keys($keys, false);
        }

        return $results;
    }

    /**
     * Set multiple values in cache.
     *
     * @param array $data Key-value pairs to cache
     * @param int $expiration Expiration in seconds
     * @param string $group Cache group
     * @return bool True if all sets succeeded, false otherwise
     */
    public function setMultiple(array $data, int $expiration = 3600, string $group = self::DEFAULT_GROUP): bool
    {
        if (!$this->enabled || empty($data)) {
            return false;
        }

        $success = true;
        
        foreach ($data as $key => $value) {
            if (!$this->set($key, $value, $expiration, $group)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Increment numeric value in cache.
     *
     * @param string $key Cache key
     * @param int $step Increment step
     * @param string $group Cache group
     * @return int|false New value on success, false on failure
     */
    public function increment(string $key, int $step = 1, string $group = self::DEFAULT_GROUP)
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $cacheKey = $this->buildKey($key, $group);
            return $this->backendIncrement($cacheKey, $step);
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Increment error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrement numeric value in cache.
     *
     * @param string $key Cache key
     * @param int $step Decrement step
     * @param string $group Cache group
     * @return int|false New value on success, false on failure
     */
    public function decrement(string $key, int $step = 1, string $group = self::DEFAULT_GROUP)
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            $cacheKey = $this->buildKey($key, $group);
            return $this->backendDecrement($cacheKey, $step);
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Decrement error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cache entries for a specific group.
     *
     * @param string $group Cache group to clear
     * @return bool True on success, false on failure
     */
    public function clearGroup(string $group = self::DEFAULT_GROUP): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            return $this->backendClearGroup($group);
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Clear group error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Clear all cache entries.
     *
     * @return bool True on success, false on failure
     */
    public function flush(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            return $this->backendFlush();
        } catch (\Exception $e) {
            $this->stats['errors']++;
            error_log('[License Server Cache] Flush error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get or set value with callback.
     *
     * @param string $key Cache key
     * @param callable $callback Callback to generate value if not cached
     * @param int $expiration Cache expiration in seconds
     * @param string $group Cache group
     * @return mixed Cached or generated value
     */
    public function remember(string $key, callable $callback, int $expiration = 3600, string $group = self::DEFAULT_GROUP)
    {
        $value = $this->get($key, $group);
        
        if ($value !== false) {
            return $value;
        }
        
        $value = $callback();
        $this->set($key, $value, $expiration, $group);
        
        return $value;
    }

    /**
     * Check if cache is enabled.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get current backend name.
     *
     * @return string
     */
    public function getBackend(): string
    {
        return $this->backend;
    }

    /**
     * Get cache statistics.
     *
     * @return array
     */
    public function getStats(): array
    {
        return $this->stats;
    }

    /**
     * Get cache hit rate.
     *
     * @return float Hit rate as percentage
     */
    public function getHitRate(): float
    {
        $total = $this->stats['hits'] + $this->stats['misses'];
        
        if ($total === 0) {
            return 0.0;
        }
        
        return round(($this->stats['hits'] / $total) * 100, 2);
    }

    /**
     * Reset cache statistics.
     */
    public function resetStats(): void
    {
        $this->stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'deletes' => 0,
            'errors' => 0
        ];
    }

    /**
     * Perform cache cleanup operations.
     *
     * @return bool True on success, false on failure
     */
    public function cleanup(): bool
    {
        if (!$this->enabled) {
            return false;
        }

        try {
            // For WordPress transients, clean expired entries
            if ($this->backend === 'wordpress') {
                global $wpdb;
                
                // Clean expired transients
                $wpdb->query("
                    DELETE t1, t2 FROM {$wpdb->options} t1
                    LEFT JOIN {$wpdb->options} t2 ON t2.option_name = CONCAT('_transient_timeout_', SUBSTRING(t1.option_name, 12))
                    WHERE t1.option_name LIKE '_transient_%'
                    AND t2.option_value < UNIX_TIMESTAMP()
                ");
                
                // Clean orphaned timeout entries
                $wpdb->query("
                    DELETE FROM {$wpdb->options}
                    WHERE option_name LIKE '_transient_timeout_%'
                    AND SUBSTRING(option_name, 20) NOT IN (
                        SELECT SUBSTRING(option_name, 12) FROM {$wpdb->options}
                        WHERE option_name LIKE '_transient_%'
                    )
                ");
            }
            
            return true;
            
        } catch (\Exception $e) {
            error_log('[License Server Cache] Cleanup error: ' . $e->getMessage());
            return false;
        }
    }

    // Private backend methods...

    private function initializeBackend(): void
    {
        switch ($this->backend) {
            case 'redis':
                $this->initializeRedis();
                break;
            case 'memcached':
                $this->initializeMemcached();
                break;
            case 'wordpress':
            default:
                // WordPress transients don't need initialization
                $this->backendInstance = null;
                break;
        }
    }

    private function initializeRedis(): void
    {
        if (!extension_loaded('redis')) {
            error_log('[License Server Cache] Redis extension not available, falling back to WordPress');
            $this->backend = 'wordpress';
            return;
        }

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->select(1); // Use database 1 for license server
            $this->backendInstance = $redis;
        } catch (\Exception $e) {
            error_log('[License Server Cache] Redis connection failed: ' . $e->getMessage() . ', falling back to WordPress');
            $this->backend = 'wordpress';
            $this->backendInstance = null;
        }
    }

    private function initializeMemcached(): void
    {
        if (!extension_loaded('memcached')) {
            error_log('[License Server Cache] Memcached extension not available, falling back to WordPress');
            $this->backend = 'wordpress';
            return;
        }

        try {
            $memcached = new \Memcached();
            $memcached->addServer('127.0.0.1', 11211);
            $this->backendInstance = $memcached;
        } catch (\Exception $e) {
            error_log('[License Server Cache] Memcached connection failed: ' . $e->getMessage() . ', falling back to WordPress');
            $this->backend = 'wordpress';
            $this->backendInstance = null;
        }
    }

    private function buildKey(string $key, string $group): string
    {
        return self::KEY_PREFIX . $group . '_' . md5($key);
    }

    private function backendGet(string $key)
    {
        switch ($this->backend) {
            case 'redis':
                return $this->backendInstance ? $this->backendInstance->get($key) : false;
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->get($key) : false;
            case 'wordpress':
            default:
                return get_transient($key);
        }
    }

    private function backendSet(string $key, $value, int $expiration): bool
    {
        switch ($this->backend) {
            case 'redis':
                if (!$this->backendInstance) return false;
                return $expiration > 0 
                    ? $this->backendInstance->setex($key, $expiration, $value)
                    : $this->backendInstance->set($key, $value);
            case 'memcached':
                if (!$this->backendInstance) return false;
                return $this->backendInstance->set($key, $value, $expiration > 0 ? time() + $expiration : 0);
            case 'wordpress':
            default:
                return set_transient($key, $value, $expiration);
        }
    }

    private function backendDelete(string $key): bool
    {
        switch ($this->backend) {
            case 'redis':
                return $this->backendInstance ? (bool) $this->backendInstance->del($key) : false;
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->delete($key) : false;
            case 'wordpress':
            default:
                return delete_transient($key);
        }
    }

    private function backendGetMultiple(array $keys): array
    {
        switch ($this->backend) {
            case 'redis':
                if (!$this->backendInstance) return [];
                $values = $this->backendInstance->mget($keys);
                return array_combine($keys, $values);
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->getMulti($keys) : [];
            case 'wordpress':
            default:
                $results = [];
                foreach ($keys as $key) {
                    $results[$key] = get_transient($key);
                }
                return $results;
        }
    }

    private function backendIncrement(string $key, int $step = 1)
    {
        switch ($this->backend) {
            case 'redis':
                return $this->backendInstance ? $this->backendInstance->incrBy($key, $step) : false;
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->increment($key, $step) : false;
            case 'wordpress':
            default:
                $value = (int) get_transient($key);
                $newValue = $value + $step;
                set_transient($key, $newValue, 3600);
                return $newValue;
        }
    }

    private function backendDecrement(string $key, int $step = 1)
    {
        switch ($this->backend) {
            case 'redis':
                return $this->backendInstance ? $this->backendInstance->decrBy($key, $step) : false;
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->decrement($key, $step) : false;
            case 'wordpress':
            default:
                $value = (int) get_transient($key);
                $newValue = max(0, $value - $step);
                set_transient($key, $newValue, 3600);
                return $newValue;
        }
    }

    private function backendClearGroup(string $group): bool
    {
        switch ($this->backend) {
            case 'redis':
                if (!$this->backendInstance) return false;
                $pattern = self::KEY_PREFIX . $group . '_*';
                $keys = $this->backendInstance->keys($pattern);
                return empty($keys) || $this->backendInstance->del($keys) > 0;
            case 'memcached':
                // Memcached doesn't support pattern deletion, so we flush all
                return $this->backendInstance ? $this->backendInstance->flush() : false;
            case 'wordpress':
            default:
                global $wpdb;
                $pattern = self::KEY_PREFIX . $group . '_%';
                return $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        '_transient_' . $pattern,
                        '_transient_timeout_' . $pattern
                    )
                ) !== false;
        }
    }

    private function backendFlush(): bool
    {
        switch ($this->backend) {
            case 'redis':
                return $this->backendInstance ? $this->backendInstance->flushDB() : false;
            case 'memcached':
                return $this->backendInstance ? $this->backendInstance->flush() : false;
            case 'wordpress':
            default:
                global $wpdb;
                return $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                        '_transient_' . self::KEY_PREFIX . '%',
                        '_transient_timeout_' . self::KEY_PREFIX . '%'
                    )
                ) !== false;
        }
    }

    private function serializeValue($value): string
    {
        return is_scalar($value) ? (string) $value : serialize($value);
    }

    private function unserializeValue(string $value)
    {
        // Try to unserialize, if it fails return as string
        $unserialized = @unserialize($value);
        return $unserialized !== false ? $unserialized : $value;
    }
}