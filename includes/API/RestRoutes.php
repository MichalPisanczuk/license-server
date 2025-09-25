<?php
namespace MyShop\LicenseServer\API;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use MyShop\LicenseServer\API\LicenseController;
use MyShop\LicenseServer\API\UpdateController;
use MyShop\LicenseServer\API\Middleware;
use MyShop\LicenseServer\Domain\Services\RateLimiter;
use function MyShop\LicenseServer\lsr;

/**
 * Secure REST API routes with proper validation, rate limiting and permissions.
 */
class RestRoutes
{
    /**
     * Register all secure routes in myshop/v1 namespace.
     */
    public function register(): void
    {
        // License activation endpoint
        register_rest_route('myshop/v1', '/license/activate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [new LicenseController(), 'activate'],
            'permission_callback' => [$this, 'validateApiPermission'],
            'args'                => [
                'license_key' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateLicenseKey'],
                    'sanitize_callback' => 'sanitize_text_field',
                    'description'       => 'License key to activate'
                ],
                'domain' => [
                    'required'          => true,
                    'type'              => 'string', 
                    'validate_callback' => [$this, 'validateDomain'],
                    'sanitize_callback' => [$this, 'sanitizeDomain'],
                    'description'       => 'Domain to activate license on'
                ]
            ]
        ]);

        // License validation endpoint (heartbeat)
        register_rest_route('myshop/v1', '/license/validate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [new LicenseController(), 'validate'],
            'permission_callback' => [$this, 'validateApiPermission'],
            'args'                => [
                'license_key' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateLicenseKey'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'domain' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateDomain'], 
                    'sanitize_callback' => [$this, 'sanitizeDomain'],
                ]
            ]
        ]);

        // Update check endpoint
        register_rest_route('myshop/v1', '/updates/check', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [new UpdateController(), 'check'],
            'permission_callback' => [$this, 'validateApiPermission'],
            'args'                => [
                'license_key' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateLicenseKey'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'slug' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateSlug'],
                    'sanitize_callback' => 'sanitize_title',
                ],
                'version' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateVersion'],
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'domain' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateDomain'],
                    'sanitize_callback' => [$this, 'sanitizeDomain'],
                ]
            ]
        ]);

        // File download endpoint (signed URLs only)
        register_rest_route('myshop/v1', '/updates/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [new UpdateController(), 'download'],
            'permission_callback' => [$this, 'validateSignedUrl'],
            'args'                => [
                'license_id' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => [$this, 'validatePositiveInt'],
                ],
                'release_id' => [
                    'required'          => true,
                    'type'              => 'integer', 
                    'validate_callback' => [$this, 'validatePositiveInt'],
                ],
                'expires' => [
                    'required'          => true,
                    'type'              => 'integer',
                    'validate_callback' => [$this, 'validateTimestamp'],
                ],
                'sig' => [
                    'required'          => true,
                    'type'              => 'string',
                    'validate_callback' => [$this, 'validateSignature'],
                    'sanitize_callback' => 'sanitize_text_field',
                ]
            ]
        ]);
    }

    /**
     * Main API permission callback with rate limiting and security checks.
     * 
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function validateApiPermission(WP_REST_Request $request)
    {
        // Get client IP for rate limiting
        $clientIp = $this->getClientIp($request);
        
        // Apply rate limiting
        /** @var RateLimiter $rateLimiter */
        $rateLimiter = lsr(RateLimiter::class);
        if (!$rateLimiter->allowRequest($clientIp, 60, 300)) { // 60 requests per 5 minutes
            return new WP_Error(
                'rate_limit_exceeded',
                __('Too many requests. Please try again later.', 'license-server'),
                ['status' => 429]
            );
        }

        // Block suspicious requests
        if ($this->isBlockedUserAgent($request)) {
            $this->logSecurityEvent('blocked_user_agent', $clientIp, $request);
            return new WP_Error(
                'blocked_request',
                __('Request blocked.', 'license-server'),
                ['status' => 403]
            );
        }

        // Additional middleware checks
        if (!Middleware::passesSecurityChecks($request, $clientIp)) {
            return new WP_Error(
                'security_check_failed', 
                __('Security validation failed.', 'license-server'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Special permission callback for signed URL download endpoint.
     * 
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function validateSignedUrl(WP_REST_Request $request)
    {
        // Rate limiting for downloads (more restrictive)
        $clientIp = $this->getClientIp($request);
        
        /** @var RateLimiter $rateLimiter */
        $rateLimiter = lsr(RateLimiter::class);
        if (!$rateLimiter->allowRequest('download_' . $clientIp, 10, 300)) { // 10 downloads per 5 minutes
            return new WP_Error(
                'download_rate_limit_exceeded',
                __('Too many download requests. Please try again later.', 'license-server'),
                ['status' => 429]
            );
        }

        return true; // Signature validation happens in controller
    }

    /**
     * Validate license key format.
     * 
     * @param string $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateLicenseKey($value, $request, $param)
    {
        if (empty($value)) {
            return new WP_Error(
                'invalid_license_key',
                __('License key cannot be empty.', 'license-server')
            );
        }

        // License keys should be 32 char hex strings
        if (!preg_match('/^[a-f0-9]{32}$/i', $value)) {
            return new WP_Error(
                'invalid_license_key_format',
                __('Invalid license key format.', 'license-server')
            );
        }

        return true;
    }

    /**
     * Validate domain format.
     * 
     * @param string $value
     * @param WP_REST_Request $request  
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateDomain($value, $request, $param)
    {
        if (empty($value)) {
            return new WP_Error(
                'invalid_domain',
                __('Domain cannot be empty.', 'license-server')
            );
        }

        // Remove protocol if present
        $domain = preg_replace('/^https?:\/\//', '', $value);
        $domain = preg_replace('/\/.*$/', '', $domain); // Remove path
        
        // Basic domain validation
        if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?)*$/', $domain)) {
            return new WP_Error(
                'invalid_domain_format',
                __('Invalid domain format.', 'license-server')
            );
        }

        // Block obviously fake domains
        $blockedPatterns = [
            'example.com',
            'test.com', 
            'localhost',
            '127.0.0.1',
            '.*\.local$',
            '.*\.test$'
        ];

        foreach ($blockedPatterns as $pattern) {
            if (preg_match("/$pattern/i", $domain)) {
                // Allow if it's in developer domains list
                $devDomains = get_option('lsr_developer_domains', '');
                if (!$this->isDeveloperDomain($domain, $devDomains)) {
                    return new WP_Error(
                        'blocked_domain',
                        __('Domain not allowed for production licenses.', 'license-server')
                    );
                }
            }
        }

        return true;
    }

    /**
     * Validate plugin slug format.
     * 
     * @param string $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateSlug($value, $request, $param)
    {
        if (empty($value)) {
            return new WP_Error(
                'invalid_slug',
                __('Plugin slug cannot be empty.', 'license-server')
            );
        }

        // WordPress plugin slug format
        if (!preg_match('/^[a-z0-9-]+$/', $value)) {
            return new WP_Error(
                'invalid_slug_format',
                __('Invalid plugin slug format.', 'license-server')
            );
        }

        if (strlen($value) > 50) {
            return new WP_Error(
                'slug_too_long',
                __('Plugin slug too long.', 'license-server')
            );
        }

        return true;
    }

    /**
     * Validate semantic version format.
     * 
     * @param string $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateVersion($value, $request, $param)
    {
        if (empty($value)) {
            return new WP_Error(
                'invalid_version',
                __('Version cannot be empty.', 'license-server')
            );
        }

        // Semantic versioning pattern (major.minor.patch with optional pre-release)
        if (!preg_match('/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/', $value)) {
            return new WP_Error(
                'invalid_version_format',
                __('Invalid version format. Use semantic versioning (e.g., 1.2.3).', 'license-server')
            );
        }

        return true;
    }

    /**
     * Validate positive integer.
     * 
     * @param mixed $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validatePositiveInt($value, $request, $param)
    {
        if (!is_numeric($value) || (int)$value <= 0) {
            return new WP_Error(
                'invalid_integer',
                sprintf(__('%s must be a positive integer.', 'license-server'), $param)
            );
        }
        return true;
    }

    /**
     * Validate timestamp.
     * 
     * @param mixed $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateTimestamp($value, $request, $param)
    {
        if (!is_numeric($value) || (int)$value <= 0) {
            return new WP_Error(
                'invalid_timestamp',
                __('Invalid timestamp.', 'license-server')
            );
        }

        // Check if timestamp is not too far in the past or future
        $now = time();
        $timestamp = (int)$value;
        
        if ($timestamp < $now - 86400) { // More than 1 day in the past
            return new WP_Error(
                'expired_timestamp',
                __('Timestamp expired.', 'license-server')
            );
        }

        if ($timestamp > $now + 86400) { // More than 1 day in the future
            return new WP_Error(
                'future_timestamp',
                __('Invalid timestamp.', 'license-server')
            );
        }

        return true;
    }

    /**
     * Validate HMAC signature format.
     * 
     * @param string $value
     * @param WP_REST_Request $request
     * @param string $param
     * @return bool|WP_Error
     */
    public function validateSignature($value, $request, $param)
    {
        if (empty($value)) {
            return new WP_Error(
                'invalid_signature',
                __('Signature cannot be empty.', 'license-server')
            );
        }

        // SHA256 HMAC is 64 hex characters
        if (!preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return new WP_Error(
                'invalid_signature_format',
                __('Invalid signature format.', 'license-server')
            );
        }

        return true;
    }

    /**
     * Sanitize domain input.
     * 
     * @param string $value
     * @return string
     */
    public function sanitizeDomain($value)
    {
        // Remove protocol
        $domain = preg_replace('/^https?:\/\//', '', $value);
        // Remove www prefix
        $domain = preg_replace('/^www\./', '', $domain);
        // Remove path and query
        $domain = preg_replace('/\/.*$/', '', $domain);
        // Convert to lowercase
        $domain = strtolower($domain);
        
        return sanitize_text_field($domain);
    }

    /**
     * Get client IP address from request.
     * 
     * @param WP_REST_Request $request
     * @return string
     */
    private function getClientIp(WP_REST_Request $request): string
    {
        // Check for IP from various headers (load balancers, proxies)
        $headers = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP', 
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // X-Forwarded-For can contain multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Check if user agent is blocked.
     * 
     * @param WP_REST_Request $request
     * @return bool
     */
    private function isBlockedUserAgent(WP_REST_Request $request): bool
    {
        $userAgent = $request->get_header('User-Agent');
        
        if (empty($userAgent)) {
            return true; // Block empty user agents
        }

        $blockedPatterns = [
            'bot', 'crawler', 'spider', 'scraper',
            'python', 'curl', 'wget', 'libwww'
        ];

        foreach ($blockedPatterns as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if domain is in developer domains list.
     * 
     * @param string $domain
     * @param string $devDomains
     * @return bool
     */
    private function isDeveloperDomain(string $domain, string $devDomains): bool
    {
        if (empty($devDomains)) {
            return false;
        }

        $patterns = preg_split('/[\r\n,]+/', $devDomains);
        
        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) {
                continue;
            }

            if ($domain === $pattern || fnmatch($pattern, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log security events.
     * 
     * @param string $event
     * @param string $ip
     * @param WP_REST_Request $request
     */
    private function logSecurityEvent(string $event, string $ip, WP_REST_Request $request): void
    {
        if (!get_option('lsr_enable_logging', false)) {
            return;
        }

        $logData = [
            'event' => $event,
            'ip' => $ip,
            'user_agent' => $request->get_header('User-Agent'),
            'endpoint' => $request->get_route(),
            'timestamp' => current_time('mysql', 1)
        ];

        error_log('[License Server Security] ' . wp_json_encode($logData));
    }
}