<?php
namespace MyShop\LicenseServer\API;

use WP_REST_Request;
use WP_Error;

/**
 * Security middleware for License Server API endpoints.
 * 
 * Provides multiple layers of security including request validation,
 * suspicious activity detection, and attack prevention.
 */
class Middleware
{
    /** @var array IP addresses to always block */
    private const BLOCKED_IPS = [];
    
    /** @var array Countries to block (if GeoIP available) */
    private const BLOCKED_COUNTRIES = ['CN', 'RU']; // Example - adjust as needed
    
    /** @var int Maximum request body size in bytes */
    private const MAX_REQUEST_SIZE = 1048576; // 1MB
    
    /** @var array Suspicious URL patterns */
    private const SUSPICIOUS_PATTERNS = [
        '/\.\.\//', // Directory traversal
        '/\/etc\/passwd/', // Linux system files
        '/\/proc\//', // Linux proc filesystem
        '/<script/', // XSS attempts
        '/javascript:/', // XSS attempts
        '/onload=/', // XSS event handlers
        '/union.*select/i', // SQL injection
        '/drop.*table/i', // SQL injection
    ];

    /**
     * Main security validation pipeline.
     * 
     * @param WP_REST_Request $request
     * @param string $clientIp
     * @return bool
     */
    public static function passesSecurityChecks(WP_REST_Request $request, string $clientIp): bool
    {
        // 1. IP-based security checks
        if (!self::validateClientIp($clientIp)) {
            self::logSecurityEvent('blocked_ip', $clientIp, $request);
            return false;
        }

        // 2. Request size validation
        if (!self::validateRequestSize($request)) {
            self::logSecurityEvent('oversized_request', $clientIp, $request);
            return false;
        }

        // 3. Header validation
        if (!self::validateHeaders($request)) {
            self::logSecurityEvent('invalid_headers', $clientIp, $request);
            return false;
        }

        // 4. Content validation for malicious patterns
        if (!self::validateRequestContent($request)) {
            self::logSecurityEvent('malicious_content', $clientIp, $request);
            return false;
        }

        // 5. Behavioral analysis
        if (!self::validateRequestBehavior($request, $clientIp)) {
            self::logSecurityEvent('suspicious_behavior', $clientIp, $request);
            return false;
        }

        // 6. Endpoint-specific validation
        if (!self::validateEndpointSpecific($request)) {
            self::logSecurityEvent('endpoint_validation_failed', $clientIp, $request);
            return false;
        }

        return true;
    }

    /**
     * Validate client IP address.
     * 
     * @param string $clientIp
     * @return bool
     */
    private static function validateClientIp(string $clientIp): bool
    {
        // Block invalid IPs
        if (!filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return false;
        }

        // Block private/reserved IP ranges in production
        if (!WP_DEBUG && !filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            // Allow if it's in developer domains (local development)
            $devDomains = get_option('lsr_developer_domains', '');
            if (empty($devDomains)) {
                return false;
            }
        }

        // Block specific IPs
        if (in_array($clientIp, self::BLOCKED_IPS)) {
            return false;
        }

        // Check against dynamic block list
        $blockedIps = get_option('lsr_blocked_ips', []);
        if (in_array($clientIp, $blockedIps)) {
            return false;
        }

        // GeoIP blocking (if GeoIP extension available)
        if (function_exists('geoip_country_code_by_name')) {
            $country = geoip_country_code_by_name($clientIp);
            if ($country && in_array($country, self::BLOCKED_COUNTRIES)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate request size to prevent DoS attacks.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateRequestSize(WP_REST_Request $request): bool
    {
        $bodySize = strlen(wp_json_encode($request->get_body_params()));
        
        return $bodySize <= self::MAX_REQUEST_SIZE;
    }

    /**
     * Validate HTTP headers for security issues.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateHeaders(WP_REST_Request $request): bool
    {
        // Require User-Agent header
        $userAgent = $request->get_header('User-Agent');
        if (empty($userAgent)) {
            return false;
        }

        // Block obviously malicious User-Agents
        $maliciousAgents = [
            'sqlmap',
            'nikto',
            'nessus',
            'burpsuite',
            'w3af',
            'havij',
            'netsparker'
        ];

        foreach ($maliciousAgents as $agent) {
            if (stripos($userAgent, $agent) !== false) {
                return false;
            }
        }

        // Validate Content-Type for POST requests
        if ($request->get_method() === 'POST') {
            $contentType = $request->get_header('Content-Type');
            if (!empty($contentType) && !self::isValidContentType($contentType)) {
                return false;
            }
        }

        // Check for suspicious headers
        $suspiciousHeaders = [
            'X-Forwarded-Host',
            'X-Original-URL',
            'X-Rewrite-URL'
        ];

        foreach ($suspiciousHeaders as $header) {
            if ($request->get_header($header)) {
                // Log but don't block - these can be legitimate
                self::logSecurityEvent('suspicious_header_' . strtolower($header), '', $request);
            }
        }

        return true;
    }

    /**
     * Validate request content for malicious patterns.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateRequestContent(WP_REST_Request $request): bool
    {
        $params = $request->get_params();
        
        foreach ($params as $key => $value) {
            if (is_string($value) && self::containsSuspiciousPattern($value)) {
                return false;
            }
            
            if (is_array($value)) {
                foreach ($value as $subValue) {
                    if (is_string($subValue) && self::containsSuspiciousPattern($subValue)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Validate request behavior patterns.
     * 
     * @param WP_REST_Request $request
     * @param string $clientIp
     * @return bool
     */
    private static function validateRequestBehavior(WP_REST_Request $request, string $clientIp): bool
    {
        $endpoint = $request->get_route();
        
        // Check for rapid sequential requests (possible automation)
        $lastRequestTime = get_transient('lsr_last_request_' . md5($clientIp));
        $currentTime = microtime(true);
        
        if ($lastRequestTime !== false) {
            $timeDiff = $currentTime - $lastRequestTime;
            
            // If less than 100ms between requests, likely automated
            if ($timeDiff < 0.1) {
                return false;
            }
        }
        
        set_transient('lsr_last_request_' . md5($clientIp), $currentTime, 60);

        // Check for endpoint scanning behavior
        $endpointAttempts = get_transient('lsr_endpoint_attempts_' . md5($clientIp)) ?: [];
        $endpointAttempts[] = $endpoint;
        
        // Keep only last 10 attempts
        $endpointAttempts = array_slice($endpointAttempts, -10);
        set_transient('lsr_endpoint_attempts_' . md5($clientIp), $endpointAttempts, 300);
        
        // If hitting many different endpoints rapidly, suspicious
        $uniqueEndpoints = array_unique($endpointAttempts);
        if (count($uniqueEndpoints) > 5 && count($endpointAttempts) > 8) {
            return false;
        }

        return true;
    }

    /**
     * Endpoint-specific validation rules.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateEndpointSpecific(WP_REST_Request $request): bool
    {
        $endpoint = $request->get_route();
        
        switch ($endpoint) {
            case '/myshop/v1/license/activate':
                return self::validateActivationRequest($request);
                
            case '/myshop/v1/license/validate':
                return self::validateValidationRequest($request);
                
            case '/myshop/v1/updates/check':
                return self::validateUpdateCheckRequest($request);
                
            case '/myshop/v1/updates/download':
                return self::validateDownloadRequest($request);
                
            default:
                return true;
        }
    }

    /**
     * Validate license activation request.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateActivationRequest(WP_REST_Request $request): bool
    {
        $licenseKey = $request->get_param('license_key');
        $domain = $request->get_param('domain');
        
        // Check for activation flooding from same IP
        $ip = self::getClientIp($request);
        $activationCount = get_transient('lsr_activations_' . md5($ip)) ?: 0;
        
        if ($activationCount > 5) { // Max 5 activations per hour per IP
            return false;
        }
        
        set_transient('lsr_activations_' . md5($ip), $activationCount + 1, 3600);

        return true;
    }

    /**
     * Validate license validation request.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateValidationRequest(WP_REST_Request $request): bool
    {
        // Validation requests should be less frequent
        $ip = self::getClientIp($request);
        $validationCount = get_transient('lsr_validations_' . md5($ip)) ?: 0;
        
        if ($validationCount > 50) { // Max 50 validations per hour per IP
            return false;
        }
        
        set_transient('lsr_validations_' . md5($ip), $validationCount + 1, 3600);

        return true;
    }

    /**
     * Validate update check request.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateUpdateCheckRequest(WP_REST_Request $request): bool
    {
        $version = $request->get_param('version');
        
        // Block obviously fake version numbers
        if (preg_match('/[^0-9.-]/', $version)) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate download request.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private static function validateDownloadRequest(WP_REST_Request $request): bool
    {
        // Downloads should be less frequent per IP
        $ip = self::getClientIp($request);
        $downloadCount = get_transient('lsr_downloads_' . md5($ip)) ?: 0;
        
        if ($downloadCount > 10) { // Max 10 downloads per hour per IP
            return false;
        }
        
        set_transient('lsr_downloads_' . md5($ip), $downloadCount + 1, 3600);

        return true;
    }

    /**
     * Check if string contains suspicious patterns.
     * 
     * @param string $value
     * @return bool
     */
    private static function containsSuspiciousPattern(string $value): bool
    {
        foreach (self::SUSPICIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Validate Content-Type header.
     * 
     * @param string $contentType
     * @return bool
     */
    private static function isValidContentType(string $contentType): bool
    {
        $allowedTypes = [
            'application/json',
            'application/x-www-form-urlencoded',
            'multipart/form-data'
        ];
        
        foreach ($allowedTypes as $type) {
            if (strpos($contentType, $type) === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get client IP from request.
     * 
     * @param WP_REST_Request $request
     * @return string
     */
    private static function getClientIp(WP_REST_Request $request): string
    {
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Log security events.
     * 
     * @param string $event
     * @param string $clientIp
     * @param WP_REST_Request $request
     */
    private static function logSecurityEvent(string $event, string $clientIp, WP_REST_Request $request): void
    {
        if (!get_option('lsr_enable_security_logging', true)) {
            return;
        }

        $logData = [
            'component' => 'SecurityMiddleware',
            'event' => $event,
            'ip' => $clientIp,
            'user_agent' => $request->get_header('User-Agent'),
            'endpoint' => $request->get_route(),
            'method' => $request->get_method(),
            'params' => array_keys($request->get_params()),
            'timestamp' => current_time('mysql', 1)
        ];

        error_log('[License Server Security] ' . wp_json_encode($logData));
        
        // Also store in database for admin review if severe
        $severeEvents = ['blocked_ip', 'malicious_content', 'suspicious_behavior'];
        if (in_array($event, $severeEvents)) {
            self::storeSecurityEvent($logData);
        }
    }

    /**
     * Store severe security events in database.
     * 
     * @param array $logData
     */
    private static function storeSecurityEvent(array $logData): void
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lsr_security_events';
        
        // Create table if not exists
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                user_agent TEXT,
                endpoint VARCHAR(255),
                data LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event_ip (event, ip),
                INDEX idx_created (created_at)
            )
        ");
        
        $wpdb->insert($table, [
            'event' => $logData['event'],
            'ip' => $logData['ip'],
            'user_agent' => $logData['user_agent'],
            'endpoint' => $logData['endpoint'],
            'data' => wp_json_encode($logData),
            'created_at' => $logData['timestamp']
        ]);
    }

    /**
     * Get blocked IPs list from database/options.
     * 
     * @return array
     */
    public static function getBlockedIps(): array
    {
        return get_option('lsr_blocked_ips', []);
    }

    /**
     * Add IP to blocked list.
     * 
     * @param string $ip
     * @param string $reason
     */
    public static function blockIp(string $ip, string $reason = ''): void
    {
        $blocked = self::getBlockedIps();
        if (!in_array($ip, $blocked)) {
            $blocked[] = $ip;
            update_option('lsr_blocked_ips', $blocked);
            
            error_log("[License Server] Blocked IP {$ip}: {$reason}");
        }
    }

    /**
     * Remove IP from blocked list.
     * 
     * @param string $ip
     */
    public static function unblockIp(string $ip): void
    {
        $blocked = self::getBlockedIps();
        $key = array_search($ip, $blocked);
        if ($key !== false) {
            unset($blocked[$key]);
            update_option('lsr_blocked_ips', array_values($blocked));
            
            error_log("[License Server] Unblocked IP {$ip}");
        }
    }
}