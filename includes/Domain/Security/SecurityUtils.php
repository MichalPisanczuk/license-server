<?php
namespace MyShop\LicenseServer\Domain\Security;

/**
 * Security utilities for License Server.
 * 
 * Provides centralized security functions for validation, encryption,
 * and threat detection across the application.
 */
class SecurityUtils
{
    /** @var string Encryption method for sensitive data */
    private const ENCRYPTION_METHOD = 'AES-256-GCM';
    
    /** @var int Maximum failed attempts before blocking */
    private const MAX_FAILED_ATTEMPTS = 10;
    
    /** @var int Block duration in seconds (1 hour) */
    private const BLOCK_DURATION = 3600;

    /**
     * Generate cryptographically secure random string.
     *
     * @param int $length
     * @param string $characters
     * @return string
     * @throws \Exception
     */
    public static function generateSecureRandom(int $length = 32, string $characters = '0123456789abcdef'): string
    {
        $randomString = '';
        $charactersLength = strlen($characters);
        
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }
        
        return $randomString;
    }

    /**
     * Generate secure API key.
     *
     * @return string
     * @throws \Exception
     */
    public static function generateApiKey(): string
    {
        return bin2hex(random_bytes(32)); // 64 character hex string
    }

    /**
     * Hash sensitive data with salt.
     *
     * @param string $data
     * @param string|null $salt
     * @return array ['hash' => string, 'salt' => string]
     * @throws \Exception
     */
    public static function hashWithSalt(string $data, ?string $salt = null): array
    {
        if ($salt === null) {
            $salt = bin2hex(random_bytes(16));
        }
        
        $hash = hash_hmac('sha256', $data, $salt);
        
        return [
            'hash' => $hash,
            'salt' => $salt
        ];
    }

    /**
     * Verify hashed data.
     *
     * @param string $data
     * @param string $hash
     * @param string $salt
     * @return bool
     */
    public static function verifyHash(string $data, string $hash, string $salt): bool
    {
        $computedHash = hash_hmac('sha256', $data, $salt);
        return hash_equals($hash, $computedHash);
    }

    /**
     * Encrypt sensitive data.
     *
     * @param string $data
     * @param string $key
     * @return string
     * @throws \Exception
     */
    public static function encrypt(string $data, string $key): string
    {
        if (!in_array(self::ENCRYPTION_METHOD, openssl_get_cipher_methods())) {
            throw new \Exception('Encryption method not available');
        }
        
        $iv = random_bytes(12); // 12 bytes for GCM
        $tag = '';
        
        $encrypted = openssl_encrypt(
            $data,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($encrypted === false) {
            throw new \Exception('Encryption failed');
        }
        
        // Combine IV, tag, and encrypted data
        return base64_encode($iv . $tag . $encrypted);
    }

    /**
     * Decrypt sensitive data.
     *
     * @param string $encryptedData
     * @param string $key
     * @return string
     * @throws \Exception
     */
    public static function decrypt(string $encryptedData, string $key): string
    {
        $data = base64_decode($encryptedData);
        if ($data === false) {
            throw new \Exception('Invalid encrypted data format');
        }
        
        $iv = substr($data, 0, 12);
        $tag = substr($data, 12, 16);
        $encrypted = substr($data, 28);
        
        $decrypted = openssl_decrypt(
            $encrypted,
            self::ENCRYPTION_METHOD,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        
        if ($decrypted === false) {
            throw new \Exception('Decryption failed');
        }
        
        return $decrypted;
    }

    /**
     * Validate domain format and security.
     *
     * @param string $domain
     * @return array ['valid' => bool, 'reason' => string]
     */
    public static function validateDomain(string $domain): array
    {
        // Basic format validation
        if (empty($domain)) {
            return ['valid' => false, 'reason' => 'empty_domain'];
        }
        
        // Remove protocol if present
        $cleanDomain = preg_replace('/^https?:\/\//', '', $domain);
        $cleanDomain = preg_replace('/\/.*$/', '', $cleanDomain);
        $cleanDomain = strtolower($cleanDomain);
        
        // Check for valid domain format
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?)*$/', $cleanDomain)) {
            return ['valid' => false, 'reason' => 'invalid_format'];
        }
        
        // Check for suspicious domains
        $suspiciousDomains = [
            'example.com',
            'test.com',
            'localhost',
            'domain.com',
            'website.com',
            '127.0.0.1',
            '0.0.0.0'
        ];
        
        if (in_array($cleanDomain, $suspiciousDomains)) {
            return ['valid' => false, 'reason' => 'suspicious_domain'];
        }
        
        // Check for malformed domains
        if (strpos($cleanDomain, '..') !== false || 
            strpos($cleanDomain, '.-') !== false || 
            strpos($cleanDomain, '-.') !== false) {
            return ['valid' => false, 'reason' => 'malformed_domain'];
        }
        
        // Check minimum length
        if (strlen($cleanDomain) < 4) {
            return ['valid' => false, 'reason' => 'domain_too_short'];
        }
        
        return ['valid' => true, 'reason' => 'valid'];
    }

    /**
     * Check if IP address is suspicious or blocked.
     *
     * @param string $ip
     * @return array ['blocked' => bool, 'reason' => string]
     */
    public static function checkIpSecurity(string $ip): array
    {
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['blocked' => true, 'reason' => 'invalid_ip'];
        }
        
        // Check against static block list
        $blockedRanges = [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.2.0/24',
            '192.168.0.0/16',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4'
        ];
        
        // Only block private ranges in production
        if (!WP_DEBUG) {
            foreach ($blockedRanges as $range) {
                if (self::ipInRange($ip, $range)) {
                    return ['blocked' => true, 'reason' => 'private_ip'];
                }
            }
        }
        
        // Check dynamic block list
        $blockedIps = get_option('lsr_blocked_ips', []);
        if (in_array($ip, $blockedIps)) {
            return ['blocked' => true, 'reason' => 'manually_blocked'];
        }
        
        // Check failed attempts
        $failedAttempts = get_transient('lsr_failed_attempts_' . md5($ip));
        if ($failedAttempts >= self::MAX_FAILED_ATTEMPTS) {
            return ['blocked' => true, 'reason' => 'too_many_failures'];
        }
        
        return ['blocked' => false, 'reason' => 'allowed'];
    }

    /**
     * Record failed security attempt.
     *
     * @param string $ip
     * @param string $type
     * @param array $context
     */
    public static function recordFailedAttempt(string $ip, string $type, array $context = []): void
    {
        $key = 'lsr_failed_attempts_' . md5($ip);
        $attempts = get_transient($key) ?: 0;
        $attempts++;
        
        set_transient($key, $attempts, self::BLOCK_DURATION);
        
        // Log the attempt
        error_log('[License Server Security] Failed attempt: ' . wp_json_encode([
            'ip' => $ip,
            'type' => $type,
            'attempts' => $attempts,
            'context' => $context,
            'timestamp' => current_time('mysql', 1)
        ]));
        
        // Auto-block after too many attempts
        if ($attempts >= self::MAX_FAILED_ATTEMPTS) {
            self::autoBlockIp($ip, $type);
        }
    }

    /**
     * Clear failed attempts for IP.
     *
     * @param string $ip
     */
    public static function clearFailedAttempts(string $ip): void
    {
        delete_transient('lsr_failed_attempts_' . md5($ip));
    }

    /**
     * Check if request looks like a bot/crawler.
     *
     * @param string $userAgent
     * @param array $headers
     * @return bool
     */
    public static function isBot(string $userAgent, array $headers = []): bool
    {
        if (empty($userAgent)) {
            return true;
        }
        
        $botPatterns = [
            'bot', 'crawler', 'spider', 'scraper', 'parser',
            'python', 'curl', 'wget', 'libwww', 'java',
            'headless', 'phantom', 'selenium', 'chrome-lighthouse'
        ];
        
        $userAgentLower = strtolower($userAgent);
        foreach ($botPatterns as $pattern) {
            if (strpos($userAgentLower, $pattern) !== false) {
                return true;
            }
        }
        
        // Check for missing common browser headers
        $requiredHeaders = ['accept', 'accept-language', 'accept-encoding'];
        foreach ($requiredHeaders as $header) {
            if (empty($headers[$header])) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate CSRF token.
     *
     * @param string $action
     * @param int $userId
     * @return string
     */
    public static function generateCsrfToken(string $action, int $userId = 0): string
    {
        return wp_create_nonce($action . '_' . $userId);
    }

    /**
     * Verify CSRF token.
     *
     * @param string $token
     * @param string $action
     * @param int $userId
     * @return bool
     */
    public static function verifyCsrfToken(string $token, string $action, int $userId = 0): bool
    {
        return wp_verify_nonce($token, $action . '_' . $userId) !== false;
    }

    /**
     * Sanitize and validate license key.
     *
     * @param string $licenseKey
     * @return array ['valid' => bool, 'key' => string, 'reason' => string]
     */
    public static function validateLicenseKey(string $licenseKey): array
    {
        $key = trim($licenseKey);
        
        if (empty($key)) {
            return ['valid' => false, 'key' => '', 'reason' => 'empty_key'];
        }
        
        // Remove any non-hex characters
        $key = preg_replace('/[^a-f0-9]/i', '', $key);
        
        if (strlen($key) !== 32) {
            return ['valid' => false, 'key' => $key, 'reason' => 'invalid_length'];
        }
        
        // Convert to lowercase for consistency
        $key = strtolower($key);
        
        // Check for obviously fake keys
        $fakePatterns = [
            '00000000000000000000000000000000',
            '11111111111111111111111111111111',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'ffffffffffffffffffffffffffffffff'
        ];
        
        if (in_array($key, $fakePatterns)) {
            return ['valid' => false, 'key' => $key, 'reason' => 'fake_key'];
        }
        
        return ['valid' => true, 'key' => $key, 'reason' => 'valid'];
    }

    /**
     * Check if IP is in CIDR range.
     *
     * @param string $ip
     * @param string $cidr
     * @return bool
     */
    private static function ipInRange(string $ip, string $cidr): bool
    {
        list($subnet, $mask) = explode('/', $cidr);
        
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (ip2long($ip) & ~((1 << (32 - $mask)) - 1)) === ip2long($subnet);
        }
        
        return false;
    }

    /**
     * Automatically block IP after too many failed attempts.
     *
     * @param string $ip
     * @param string $reason
     */
    private static function autoBlockIp(string $ip, string $reason): void
    {
        $blockedIps = get_option('lsr_blocked_ips', []);
        
        if (!in_array($ip, $blockedIps)) {
            $blockedIps[] = $ip;
            update_option('lsr_blocked_ips', $blockedIps);
            
            error_log("[License Server Security] Auto-blocked IP {$ip} for {$reason}");
            
            // Notify admin if enabled
            if (get_option('lsr_notify_on_block', false)) {
                self::notifyAdminOfBlock($ip, $reason);
            }
        }
    }

    /**
     * Notify admin of IP block.
     *
     * @param string $ip
     * @param string $reason
     */
    private static function notifyAdminOfBlock(string $ip, string $reason): void
    {
        $adminEmail = get_option('admin_email');
        $subject = '[' . get_bloginfo('name') . '] Security Alert: IP Blocked';
        $message = sprintf(
            "A suspicious IP address has been automatically blocked:\n\n" .
            "IP: %s\n" .
            "Reason: %s\n" .
            "Time: %s\n\n" .
            "You can review and manage blocked IPs in the License Server settings.",
            $ip,
            $reason,
            current_time('mysql')
        );
        
        wp_mail($adminEmail, $subject, $message);
    }

    /**
     * Get security statistics.
     *
     * @return array
     */
    public static function getSecurityStats(): array
    {
        global $wpdb;
        
        $stats = [
            'blocked_ips' => count(get_option('lsr_blocked_ips', [])),
            'failed_attempts_24h' => 0,
            'security_events_24h' => 0
        ];
        
        // Count recent failed attempts (approximation)
        $failedAttemptsPattern = 'lsr_failed_attempts_%';
        $failedCount = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                '_transient_' . $failedAttemptsPattern
            )
        );
        $stats['failed_attempts_24h'] = (int) $failedCount;
        
        // Count security events if table exists
        $eventsTable = $wpdb->prefix . 'lsr_security_events';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$eventsTable}'") === $eventsTable) {
            $stats['security_events_24h'] = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$eventsTable} WHERE created_at > %s",
                    date('Y-m-d H:i:s', time() - 86400)
                )
            );
        }
        
        return $stats;
    }
}