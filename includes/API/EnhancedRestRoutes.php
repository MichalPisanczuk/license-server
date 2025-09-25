<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\API;

use WP_REST_Server;
use WP_REST_Request;
use WP_Error;
use MyShop\LicenseServer\EnhancedBootstrap;
use MyShop\LicenseServer\Domain\Security\CsrfProtection;
use MyShop\LicenseServer\Domain\Exceptions\{
    SecurityException,
    RateLimitExceededException,
    ValidationException
};

/**
 * Enhanced REST API Routes with comprehensive security and validation.
 */
class EnhancedRestRoutes
{
    private EnhancedBootstrap $container;
    private string $namespace = 'myshop/v1';
    
    /** @var array Route definitions with security levels */
    private array $routes = [];

    public function __construct(EnhancedBootstrap $container)
    {
        $this->container = $container;
        $this->defineRoutes();
    }

    /**
     * Register all API routes.
     */
    public function register(): void
    {
        foreach ($this->routes as $route => $config) {
            register_rest_route(
                $this->namespace,
                $route,
                $config['options']
            );
        }
    }

    /**
     * Define all API routes with their configurations.
     */
    private function defineRoutes(): void
    {
        // License activation endpoint
        $this->routes['/license/activate'] = [
            'security_level' => 'high',
            'options' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleLicenseActivation'],
                'permission_callback' => [$this, 'validateHighSecurityRequest'],
                'args' => [
                    'license_key' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateLicenseKeyFormat'],
                        'sanitize_callback' => [$this, 'sanitizeLicenseKey'],
                        'description' => 'License key to activate'
                    ],
                    'domain' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateDomainFormat'],
                        'sanitize_callback' => [$this, 'sanitizeDomain'],
                        'description' => 'Domain to activate license on'
                    ],
                    'site_info' => [
                        'required' => false,
                        'type' => 'object',
                        'validate_callback' => [$this, 'validateSiteInfo'],
                        'description' => 'Additional site information'
                    ]
                ]
            ]
        ];

        // License validation endpoint (heartbeat)
        $this->routes['/license/validate'] = [
            'security_level' => 'medium',
            'options' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleLicenseValidation'],
                'permission_callback' => [$this, 'validateMediumSecurityRequest'],
                'args' => [
                    'license_key' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateLicenseKeyFormat'],
                        'sanitize_callback' => [$this, 'sanitizeLicenseKey']
                    ],
                    'domain' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateDomainFormat'],
                        'sanitize_callback' => [$this, 'sanitizeDomain']
                    ]
                ]
            ]
        ];

        // License deactivation endpoint
        $this->routes['/license/deactivate'] = [
            'security_level' => 'high',
            'options' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleLicenseDeactivation'],
                'permission_callback' => [$this, 'validateHighSecurityRequest'],
                'args' => [
                    'license_key' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateLicenseKeyFormat'],
                        'sanitize_callback' => [$this, 'sanitizeLicenseKey']
                    ],
                    'domain' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateDomainFormat'],
                        'sanitize_callback' => [$this, 'sanitizeDomain']
                    ],
                    'reason' => [
                        'required' => false,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                        'description' => 'Reason for deactivation'
                    ]
                ]
            ]
        ];

        // Updates check endpoint
        $this->routes['/updates/check'] = [
            'security_level' => 'medium',
            'options' => [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handleUpdatesCheck'],
                'permission_callback' => [$this, 'validateMediumSecurityRequest'],
                'args' => [
                    'license_key' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateLicenseKeyFormat'],
                        'sanitize_callback' => [$this, 'sanitizeLicenseKey']
                    ],
                    'slug' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateSlugFormat'],
                        'sanitize_callback' => 'sanitize_title'
                    ],
                    'version' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateVersionFormat'],
                        'sanitize_callback' => [$this, 'sanitizeVersion']
                    ],
                    'domain' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateDomainFormat'],
                        'sanitize_callback' => [$this, 'sanitizeDomain']
                    ]
                ]
            ]
        ];

        // Download endpoint (signed URLs only)
        $this->routes['/updates/download'] = [
            'security_level' => 'signed',
            'options' => [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handleFileDownload'],
                'permission_callback' => [$this, 'validateSignedUrlRequest'],
                'args' => [
                    'license_id' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => [$this, 'validatePositiveInteger']
                    ],
                    'release_id' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => [$this, 'validatePositiveInteger']
                    ],
                    'expires' => [
                        'required' => true,
                        'type' => 'integer',
                        'validate_callback' => [$this, 'validateTimestamp']
                    ],
                    'sig' => [
                        'required' => true,
                        'type' => 'string',
                        'validate_callback' => [$this, 'validateSignature'],
                        'sanitize_callback' => 'sanitize_text_field'
                    ]
                ]
            ]
        ];

        // User licenses endpoint (authenticated)
        $this->routes['/user/licenses'] = [
            'security_level' => 'authenticated',
            'options' => [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handleUserLicenses'],
                'permission_callback' => [$this, 'validateAuthenticatedRequest'],
                'args' => [
                    'page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 1,
                        'validate_callback' => [$this, 'validatePositiveInteger']
                    ],
                    'per_page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 10,
                        'validate_callback' => function ($value) {
                            return $this->validatePositiveInteger($value) && $value <= 50;
                        }
                    ]
                ]
            ]
        ];

        // System status endpoint (admin only)
        $this->routes['/admin/status'] = [
            'security_level' => 'admin',
            'options' => [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'handleSystemStatus'],
                'permission_callback' => [$this, 'validateAdminRequest']
            ]
        ];
    }

    // Route handlers...

    public function handleLicenseActivation(WP_REST_Request $request)
    {
        return $this->container->get('license_controller')->activate($request);
    }

    public function handleLicenseValidation(WP_REST_Request $request)
    {
        return $this->container->get('license_controller')->validate($request);
    }

    public function handleLicenseDeactivation(WP_REST_Request $request)
    {
        return $this->container->get('license_controller')->deactivate($request);
    }

    public function handleUpdatesCheck(WP_REST_Request $request)
    {
        return $this->container->get('updates_controller')->check($request);
    }

    public function handleFileDownload(WP_REST_Request $request)
    {
        return $this->container->get('updates_controller')->download($request);
    }

    public function handleUserLicenses(WP_REST_Request $request)
    {
        return $this->container->get('license_controller')->getUserLicenses($request);
    }

    public function handleSystemStatus(WP_REST_Request $request)
    {
        try {
            $status = [
                'version' => LSR_VERSION,
                'php_version' => PHP_VERSION,
                'wordpress_version' => get_bloginfo('version'),
                'database' => $this->container->get('config')->get('schema_version'),
                'cache' => $this->getCacheStatus(),
                'security' => $this->getSecurityStatus(),
                'timestamp' => current_time('mysql', 1)
            ];

            return new \WP_REST_Response($status, 200);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'error' => true,
                'message' => 'Failed to get system status'
            ], 500);
        }
    }

    // Permission callbacks for different security levels...

    public function validateHighSecurityRequest(WP_REST_Request $request)
    {
        try {
            return $this->performSecurityValidation($request, 'high');
        } catch (SecurityException $e) {
            return new WP_Error($e->getErrorCode(), $e->getUserMessage(), ['status' => 403]);
        } catch (RateLimitExceededException $e) {
            return new WP_Error($e->getErrorCode(), $e->getUserMessage(), ['status' => 429]);
        }
    }

    public function validateMediumSecurityRequest(WP_REST_Request $request)
    {
        try {
            return $this->performSecurityValidation($request, 'medium');
        } catch (SecurityException $e) {
            return new WP_Error($e->getErrorCode(), $e->getUserMessage(), ['status' => 403]);
        } catch (RateLimitExceededException $e) {
            return new WP_Error($e->getErrorCode(), $e->getUserMessage(), ['status' => 429]);
        }
    }

    public function validateSignedUrlRequest(WP_REST_Request $request)
    {
        try {
            $signedUrlService = $this->container->get('signed_url_service');
            return $signedUrlService->validateSignedRequest($request);
        } catch (\Exception $e) {
            return new WP_Error('invalid_signature', 'Invalid or expired signature', ['status' => 403]);
        }
    }

    public function validateAuthenticatedRequest(WP_REST_Request $request)
    {
        if (!is_user_logged_in()) {
            return new WP_Error('authentication_required', 'Authentication required', ['status' => 401]);
        }
        
        return true;
    }

    public function validateAdminRequest(WP_REST_Request $request)
    {
        if (!current_user_can('manage_options')) {
            return new WP_Error('insufficient_permissions', 'Administrator access required', ['status' => 403]);
        }
        
        return true;
    }

    // Security validation...

    private function performSecurityValidation(WP_REST_Request $request, string $level): bool
    {
        $clientIp = $this->getClientIp($request);
        
        // Rate limiting
        $this->enforceRateLimit($request, $clientIp);
        
        // Security checks based on level
        switch ($level) {
            case 'high':
                $this->performHighSecurityChecks($request, $clientIp);
                break;
            case 'medium':
                $this->performMediumSecurityChecks($request, $clientIp);
                break;
        }
        
        return true;
    }

    private function performHighSecurityChecks(WP_REST_Request $request, string $clientIp): void
    {
        // Check for blocked IPs
        if ($this->isIpBlocked($clientIp)) {
            throw new SecurityException('IP address is blocked', 'ip_blocked');
        }
        
        // User agent validation
        $userAgent = $request->get_header('User-Agent');
        if (empty($userAgent) || $this->isSuspiciousUserAgent($userAgent)) {
            throw new SecurityException('Invalid or suspicious user agent', 'suspicious_user_agent');
        }
        
        // Content-Type validation for POST requests
        if ($request->get_method() === 'POST') {
            $contentType = $request->get_header('Content-Type');
            if (empty($contentType)) {
                throw new SecurityException('Missing Content-Type header', 'missing_content_type');
            }
        }
        
        // Honeypot fields
        if ($request->get_param('email') || $request->get_param('website') || $request->get_param('url')) {
            throw new SecurityException('Honeypot field detected', 'honeypot_triggered');
        }
        
        // Check for common attack patterns
        $this->checkForAttackPatterns($request);
    }

    private function performMediumSecurityChecks(WP_REST_Request $request, string $clientIp): void
    {
        // Basic IP blocking
        if ($this->isIpBlocked($clientIp)) {
            throw new SecurityException('IP address is blocked', 'ip_blocked');
        }
        
        // Basic user agent check
        $userAgent = $request->get_header('User-Agent');
        if (empty($userAgent)) {
            throw new SecurityException('User agent required', 'user_agent_required');
        }
    }

    private function enforceRateLimit(WP_REST_Request $request, string $clientIp): void
    {
        $rateLimiter = $this->container->get('rate_limiter');
        $endpoint = $request->get_route();
        
        // Different limits for different endpoints
        $limits = [
            '/license/activate' => ['requests' => 10, 'window' => 300],
            '/license/validate' => ['requests' => 60, 'window' => 300],
            '/license/deactivate' => ['requests' => 20, 'window' => 300],
            '/updates/check' => ['requests' => 120, 'window' => 300],
            'default' => ['requests' => 30, 'window' => 300]
        ];
        
        $limit = $limits[$endpoint] ?? $limits['default'];
        
        if (!$rateLimiter->allowRequest($clientIp, $limit['requests'], $limit['window'])) {
            throw new RateLimitExceededException(
                'Rate limit exceeded for this endpoint',
                'rate_limit_endpoint',
                429,
                ['endpoint' => $endpoint, 'limit' => $limit]
            );
        }
    }

    // Validation callbacks...

    public function validateLicenseKeyFormat($value, WP_REST_Request $request, string $key): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        // Basic format check (will be validated more thoroughly in the service)
        return !empty($value) && strlen($value) >= 10 && strlen($value) <= 100;
    }

    public function validateDomainFormat($value, WP_REST_Request $request, string $key): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        // Remove protocol and path
        $domain = preg_replace('#^https?://#', '', $value);
        $domain = preg_replace('#/.*$#', '', $domain);
        
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    public function validateSlugFormat($value, WP_REST_Request $request, string $key): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        return preg_match('/^[a-z0-9\-]+$/', $value) === 1;
    }

    public function validateVersionFormat($value, WP_REST_Request $request, string $key): bool
    {
        if (!is_string($value)) {
            return false;
        }
        
        return preg_match('/^\d+\.\d+(\.\d+)?(-[a-z0-9\-]+)?$/', $value) === 1;
    }

    public function validatePositiveInteger($value, WP_REST_Request $request, string $key): bool
    {
        return is_numeric($value) && (int) $value > 0;
    }

    public function validateTimestamp($value, WP_REST_Request $request, string $key): bool
    {
        return is_numeric($value) && (int) $value > time() - 86400; // Not older than 24 hours
    }

    public function validateSignature($value, WP_REST_Request $request, string $key): bool
    {
        return is_string($value) && !empty($value) && ctype_xdigit($value);
    }

    public function validateSiteInfo($value, WP_REST_Request $request, string $key): bool
    {
        if (!is_array($value)) {
            return false;
        }
        
        // Optional validation for site info structure
        $allowedKeys = ['wp_version', 'php_version', 'plugin_version', 'theme', 'plugins_active'];
        
        foreach ($value as $infoKey => $infoValue) {
            if (!in_array($infoKey, $allowedKeys)) {
                return false;
            }
        }
        
        return true;
    }

    // Sanitization callbacks...

    public function sanitizeLicenseKey(string $value): string
    {
        return strtoupper(trim(sanitize_text_field($value)));
    }

    public function sanitizeDomain(string $value): string
    {
        $domain = strtolower(trim($value));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain);
        return sanitize_text_field($domain);
    }

    public function sanitizeVersion(string $value): string
    {
        return trim(sanitize_text_field($value));
    }

    // Helper methods...

    private function getClientIp(WP_REST_Request $request): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
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

    private function isIpBlocked(string $ip): bool
    {
        // Check transient blocked IPs
        $blockedIps = get_transient('lsr_blocked_ips') ?: [];
        if (in_array($ip, $blockedIps)) {
            return true;
        }
        
        // Check failed attempts
        $failedAttempts = get_transient('lsr_failed_attempts_' . md5($ip)) ?: 0;
        $maxAttempts = $this->container->get('config')->get('max_failed_attempts', 10);
        
        return $failedAttempts >= $maxAttempts;
    }

    private function isSuspiciousUserAgent(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return true;
        }
        
        $suspiciousPatterns = [
            '/bot/i', '/crawler/i', '/spider/i', '/scraper/i',
            '/python/i', '/curl/i', '/wget/i', '/libwww/i'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }
        
        return false;
    }

    private function checkForAttackPatterns(WP_REST_Request $request): void
    {
        $params = $request->get_params();
        $suspiciousPatterns = [
            '/<script/i',
            '/javascript:/i',
            '/onload=/i',
            '/onclick=/i',
            '/union.*select/i',
            '/drop.*table/i',
            '/exec.*xp_/i'
        ];
        
        foreach ($params as $key => $value) {
            if (is_string($value)) {
                foreach ($suspiciousPatterns as $pattern) {
                    if (preg_match($pattern, $value)) {
                        throw new SecurityException(
                            'Malicious pattern detected',
                            'malicious_pattern',
                            0,
                            ['pattern' => $pattern, 'field' => $key]
                        );
                    }
                }
            }
        }
    }

    private function getCacheStatus(): array
    {
        try {
            $cache = $this->container->get('cache_service');
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
        $config = $this->container->get('config');
        
        return [
            'https_enabled' => is_ssl(),
            'security_mode' => $config->get('security_mode'),
            'rate_limiting' => true,
            'ip_blocking' => $config->get('max_failed_attempts') > 0,
            'csrf_protection' => true
        ];
    }
}