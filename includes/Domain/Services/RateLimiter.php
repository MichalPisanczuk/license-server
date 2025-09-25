<?php
namespace MyShop\LicenseServer\Domain\Services;

/**
 * Rate limiter using WordPress transients with sliding window algorithm.
 * 
 * Provides protection against API abuse by limiting requests per IP/identifier
 * within configurable time windows.
 */
class RateLimiter
{
    /** @var string Transient prefix for rate limit data */
    private const TRANSIENT_PREFIX = 'lsr_rate_limit_';
    
    /** @var string Transient prefix for blocked IPs */
    private const BLOCK_PREFIX = 'lsr_blocked_';

    /** @var int Default rate limit (requests per window) */
    private const DEFAULT_LIMIT = 60;
    
    /** @var int Default time window in seconds */
    private const DEFAULT_WINDOW = 300; // 5 minutes
    
    /** @var int How long to block after exceeding limits */
    private const BLOCK_DURATION = 900; // 15 minutes

    /**
     * Check if request should be allowed based on rate limiting rules.
     * 
     * @param string $identifier Unique identifier (usually IP address)
     * @param int $limit Maximum requests allowed in window
     * @param int $window Time window in seconds
     * @return bool True if request should be allowed
     */
    public function allowRequest(string $identifier, int $limit = self::DEFAULT_LIMIT, int $window = self::DEFAULT_WINDOW): bool
    {
        // Sanitize identifier
        $identifier = $this->sanitizeIdentifier($identifier);
        
        // Check if IP is currently blocked
        if ($this->isBlocked($identifier)) {
            $this->logRateLimit($identifier, 'blocked_request', $limit, $window);
            return false;
        }

        // Get current request count for this identifier
        $currentCount = $this->getCurrentRequestCount($identifier, $window);
        
        // Check if limit exceeded
        if ($currentCount >= $limit) {
            // Block the identifier for repeated violations
            $this->blockIdentifier($identifier);
            $this->logRateLimit($identifier, 'limit_exceeded', $limit, $window, $currentCount);
            return false;
        }

        // Increment request count
        $this->incrementRequestCount($identifier, $window);
        
        // Log if approaching limit (at 80%)
        if ($currentCount >= ($limit * 0.8)) {
            $this->logRateLimit($identifier, 'approaching_limit', $limit, $window, $currentCount + 1);
        }

        return true;
    }

    /**
     * Check if an identifier is currently blocked.
     * 
     * @param string $identifier
     * @return bool
     */
    public function isBlocked(string $identifier): bool
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $blockKey = self::BLOCK_PREFIX . $identifier;
        
        return get_transient($blockKey) !== false;
    }

    /**
     * Block an identifier for the configured duration.
     * 
     * @param string $identifier
     * @param int $duration Block duration in seconds (optional)
     */
    public function blockIdentifier(string $identifier, int $duration = self::BLOCK_DURATION): void
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $blockKey = self::BLOCK_PREFIX . $identifier;
        
        set_transient($blockKey, time(), $duration);
        
        $this->logRateLimit($identifier, 'blocked_identifier', 0, $duration);
    }

    /**
     * Manually unblock an identifier.
     * 
     * @param string $identifier
     */
    public function unblockIdentifier(string $identifier): void
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $blockKey = self::BLOCK_PREFIX . $identifier;
        
        delete_transient($blockKey);
        
        $this->logRateLimit($identifier, 'unblocked_identifier');
    }

    /**
     * Get current request count for identifier within window.
     * 
     * @param string $identifier
     * @param int $window
     * @return int
     */
    public function getCurrentRequestCount(string $identifier, int $window): int
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $transientKey = self::TRANSIENT_PREFIX . $identifier;
        
        $data = get_transient($transientKey);
        
        if ($data === false) {
            return 0;
        }

        // Clean old entries outside the window
        $cutoffTime = time() - $window;
        $validRequests = array_filter($data['requests'], function($timestamp) use ($cutoffTime) {
            return $timestamp > $cutoffTime;
        });

        // Update transient with cleaned data
        if (count($validRequests) !== count($data['requests'])) {
            $data['requests'] = array_values($validRequests);
            set_transient($transientKey, $data, $window);
        }

        return count($validRequests);
    }

    /**
     * Increment request count for identifier.
     * 
     * @param string $identifier
     * @param int $window
     */
    private function incrementRequestCount(string $identifier, int $window): void
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $transientKey = self::TRANSIENT_PREFIX . $identifier;
        $now = time();
        
        $data = get_transient($transientKey);
        
        if ($data === false) {
            $data = [
                'requests' => [],
                'first_request' => $now
            ];
        }

        // Add current request timestamp
        $data['requests'][] = $now;
        
        // Clean old entries
        $cutoffTime = $now - $window;
        $data['requests'] = array_filter($data['requests'], function($timestamp) use ($cutoffTime) {
            return $timestamp > $cutoffTime;
        });
        
        // Reindex array
        $data['requests'] = array_values($data['requests']);
        
        // Store for the duration of the window
        set_transient($transientKey, $data, $window);
    }

    /**
     * Get rate limit statistics for an identifier.
     * 
     * @param string $identifier
     * @param int $window
     * @return array
     */
    public function getStats(string $identifier, int $window = self::DEFAULT_WINDOW): array
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        $transientKey = self::TRANSIENT_PREFIX . $identifier;
        
        $data = get_transient($transientKey);
        $currentCount = $this->getCurrentRequestCount($identifier, $window);
        $isBlocked = $this->isBlocked($identifier);
        
        return [
            'identifier' => $identifier,
            'current_requests' => $currentCount,
            'is_blocked' => $isBlocked,
            'window_seconds' => $window,
            'first_request' => $data ? $data['first_request'] : null,
            'requests_timeline' => $data ? $data['requests'] : []
        ];
    }

    /**
     * Clean up expired rate limit data.
     * Called by cron job to prevent database bloat.
     */
    public function cleanup(): void
    {
        global $wpdb;
        
        // WordPress doesn't provide a direct way to list transients by prefix
        // So we'll rely on transient expiration and manual cleanup if needed
        
        // Get all transient option names with our prefix
        $transientPattern = '_transient_' . self::TRANSIENT_PREFIX . '%';
        $blockPattern = '_transient_' . self::BLOCK_PREFIX . '%';
        
        $transients = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $transientPattern,
                $blockPattern
            ),
            ARRAY_A
        );

        $cleaned = 0;
        foreach ($transients as $transient) {
            $optionName = $transient['option_name'];
            $transientName = str_replace('_transient_', '', $optionName);
            
            // Check if transient still exists (not expired)
            if (get_transient($transientName) === false) {
                delete_option($optionName);
                delete_option('_transient_timeout_' . $transientName);
                $cleaned++;
            }
        }
        
        if ($cleaned > 0) {
            error_log("[License Server] Rate limiter cleanup: removed {$cleaned} expired transients");
        }
    }

    /**
     * Get comprehensive rate limiting statistics.
     * 
     * @return array
     */
    public function getGlobalStats(): array
    {
        global $wpdb;
        
        $transientPattern = '_transient_' . self::TRANSIENT_PREFIX . '%';
        $blockPattern = '_transient_' . self::BLOCK_PREFIX . '%';
        
        $activeTransients = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $transientPattern
            )
        );
        
        $blockedIps = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $blockPattern  
            )
        );
        
        return [
            'active_rate_limits' => (int) $activeTransients,
            'blocked_identifiers' => (int) $blockedIps,
            'default_limit' => self::DEFAULT_LIMIT,
            'default_window' => self::DEFAULT_WINDOW,
            'block_duration' => self::BLOCK_DURATION
        ];
    }

    /**
     * Reset rate limiting data for specific identifier.
     * 
     * @param string $identifier
     */
    public function resetIdentifier(string $identifier): void
    {
        $identifier = $this->sanitizeIdentifier($identifier);
        
        delete_transient(self::TRANSIENT_PREFIX . $identifier);
        delete_transient(self::BLOCK_PREFIX . $identifier);
        
        $this->logRateLimit($identifier, 'reset_limits');
    }

    /**
     * Check if current environment supports rate limiting.
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        // Check if we can use transients
        return function_exists('get_transient') && function_exists('set_transient');
    }

    /**
     * Sanitize identifier for safe use in transient keys.
     * 
     * @param string $identifier
     * @return string
     */
    private function sanitizeIdentifier(string $identifier): string
    {
        // Remove any non-alphanumeric characters except dots and dashes
        $identifier = preg_replace('/[^a-zA-Z0-9.-]/', '_', $identifier);
        
        // Limit length to prevent issues with transient key length limits
        return substr($identifier, 0, 40);
    }

    /**
     * Log rate limiting events.
     * 
     * @param string $identifier
     * @param string $event
     * @param int $limit
     * @param int $window
     * @param int $currentCount
     */
    private function logRateLimit(string $identifier, string $event, int $limit = 0, int $window = 0, int $currentCount = 0): void
    {
        // Only log if logging is enabled in settings
        if (!get_option('lsr_enable_logging', false)) {
            return;
        }

        $logData = [
            'component' => 'RateLimiter',
            'event' => $event,
            'identifier' => $identifier,
            'limit' => $limit,
            'window' => $window,
            'current_count' => $currentCount,
            'timestamp' => current_time('mysql', 1)
        ];

        error_log('[License Server] ' . wp_json_encode($logData));
    }
}