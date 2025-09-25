<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MyShop\LicenseServer\Domain\Services\EnhancedLicenseService;
use MyShop\LicenseServer\Domain\Security\CsrfProtection;
use MyShop\LicenseServer\Domain\Exceptions\{
    LicenseServerException,
    ValidationException,
    SecurityException,
    RateLimitExceededException
};

/**
 * Enhanced License Controller with comprehensive security and error handling.
 */
class EnhancedLicenseController
{
    private EnhancedLicenseService $licenseService;
    
    /** @var array Rate limiting configuration */
    private array $rateLimits = [
        'activate' => ['requests' => 10, 'window' => 300], // 10 requests per 5 minutes
        'validate' => ['requests' => 60, 'window' => 300], // 60 requests per 5 minutes
        'deactivate' => ['requests' => 20, 'window' => 300] // 20 requests per 5 minutes
    ];

    public function __construct(EnhancedLicenseService $licenseService)
    {
        $this->licenseService = $licenseService;
    }

    /**
     * Handle license activation request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function activate(WP_REST_Request $request)
    {
        $startTime = microtime(true);
        $clientIp = $this->getClientIp($request);
        
        try {
            // Rate limiting
            $this->enforceRateLimit('activate', $clientIp);
            
            // Security checks
            $this->performSecurityChecks($request, $clientIp);
            
            // Extract and validate parameters
            $licenseKey = $this->sanitizeParam($request->get_param('license_key'), 'license_key');
            $domain = $this->sanitizeParam($request->get_param('domain'), 'domain');
            
            // Additional validation
            $this->validateActivationRequest($licenseKey, $domain);
            
            // Log activation attempt
            $this->logApiRequest('activate', $request, $clientIp, $startTime);
            
            // Perform activation
            $result = $this->licenseService->activateLicense(
                $licenseKey,
                $domain,
                $clientIp,
                $request->get_header('User-Agent')
            );
            
            // Log successful activation
            $this->logSecurityEvent('license_activated', 'low', $clientIp, [
                'domain' => $domain,
                'license_key_hash' => hash('sha256', $licenseKey)
            ]);
            
            return $this->successResponse($result, 200);
            
        } catch (LicenseServerException $e) {
            $this->logApiError('activate', $e, $request, $clientIp);
            return $this->errorResponse($e);
        } catch (\Exception $e) {
            $this->logCriticalError('activate', $e, $request, $clientIp);
            return $this->errorResponse(new SecurityException(
                'Activation request failed due to internal error',
                'internal_error'
            ));
        } finally {
            $this->recordApiMetrics('activate', microtime(true) - $startTime);
        }
    }

    /**
     * Handle license validation request (heartbeat).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function validate(WP_REST_Request $request)
    {
        $startTime = microtime(true);
        $clientIp = $this->getClientIp($request);
        
        try {
            // Rate limiting (more lenient for validation)
            $this->enforceRateLimit('validate', $clientIp);
            
            // Basic security checks (less strict for heartbeat)
            $this->performBasicSecurityChecks($request, $clientIp);
            
            $licenseKey = $this->sanitizeParam($request->get_param('license_key'), 'license_key');
            $domain = $this->sanitizeParam($request->get_param('domain'), 'domain');
            
            $this->validateValidationRequest($licenseKey, $domain);
            
            $result = $this->licenseService->validateLicense($licenseKey, $domain, $clientIp);
            
            // Only log failed validations to reduce noise
            if (!$result['success'] || $result['status'] !== 'active') {
                $this->logSecurityEvent('license_validation_warning', 'medium', $clientIp, [
                    'domain' => $domain,
                    'status' => $result['status'],
                    'reason' => $result['reason'] ?? null
                ]);
            }
            
            return $this->successResponse($result, 200);
            
        } catch (LicenseServerException $e) {
            $this->logApiError('validate', $e, $request, $clientIp);
            return $this->errorResponse($e);
        } catch (\Exception $e) {
            $this->logCriticalError('validate', $e, $request, $clientIp);
            return $this->errorResponse(new SecurityException(
                'Validation request failed',
                'validation_error'
            ));
        } finally {
            $this->recordApiMetrics('validate', microtime(true) - $startTime);
        }
    }

    /**
     * Handle license deactivation request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function deactivate(WP_REST_Request $request)
    {
        $startTime = microtime(true);
        $clientIp = $this->getClientIp($request);
        
        try {
            $this->enforceRateLimit('deactivate', $clientIp);
            $this->performSecurityChecks($request, $clientIp);
            
            $licenseKey = $this->sanitizeParam($request->get_param('license_key'), 'license_key');
            $domain = $this->sanitizeParam($request->get_param('domain'), 'domain');
            
            $this->validateDeactivationRequest($licenseKey, $domain);
            
            $result = $this->licenseService->deactivateLicense($licenseKey, $domain);
            
            $this->logSecurityEvent('license_deactivated', 'low', $clientIp, [
                'domain' => $domain,
                'license_key_hash' => hash('sha256', $licenseKey)
            ]);
            
            return $this->successResponse($result, 200);
            
        } catch (LicenseServerException $e) {
            $this->logApiError('deactivate', $e, $request, $clientIp);
            return $this->errorResponse($e);
        } catch (\Exception $e) {
            $this->logCriticalError('deactivate', $e, $request, $clientIp);
            return $this->errorResponse(new SecurityException(
                'Deactivation request failed',
                'deactivation_error'
            ));
        } finally {
            $this->recordApiMetrics('deactivate', microtime(true) - $startTime);
        }
    }

    /**
     * Get user licenses (authenticated endpoint).
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function getUserLicenses(WP_REST_Request $request)
    {
        try {
            // Require authentication for this endpoint
            if (!is_user_logged_in()) {
                throw new SecurityException('Authentication required', 'auth_required');
            }
            
            $userId = get_current_user_id();
            $licenses = $this->licenseService->getUserLicenses($userId);
            
            return $this->successResponse(['licenses' => $licenses], 200);
            
        } catch (LicenseServerException $e) {
            return $this->errorResponse($e);
        } catch (\Exception $e) {
            return $this->errorResponse(new SecurityException(
                'Failed to retrieve user licenses',
                'user_licenses_error'
            ));
        }
    }

    // Private security and validation methods...

    private function enforceRateLimit(string $action, string $clientIp): void
    {
        $config = $this->rateLimits[$action] ?? ['requests' => 30, 'window' => 300];
        $key = "lsr_rate_limit_{$action}_" . md5($clientIp);
        
        $requests = (int) get_transient($key);
        
        if ($requests >= $config['requests']) {
            throw new RateLimitExceededException(
                "Rate limit exceeded for action: {$action}",
                'rate_limit_' . $action,
                429,
                [
                    'action' => $action,
                    'limit' => $config['requests'],
                    'window' => $config['window']
                ]
            );
        }
        
        set_transient($key, $requests + 1, $config['window']);
    }

    private function performSecurityChecks(WP_REST_Request $request, string $clientIp): void
    {
        // Check for suspicious patterns
        if ($this->isBlockedIp($clientIp)) {
            throw new SecurityException('IP address is blocked', 'ip_blocked');
        }
        
        if ($this->isSuspiciousUserAgent($request->get_header('User-Agent'))) {
            throw new SecurityException('Suspicious user agent detected', 'suspicious_user_agent');
        }
        
        if ($this->hasInvalidHeaders($request)) {
            throw new SecurityException('Invalid request headers', 'invalid_headers');
        }
        
        // Honeypot detection
        if ($request->get_param('email') || $request->get_param('website')) {
            throw new SecurityException('Honeypot field filled', 'honeypot_triggered');
        }
    }

    private function performBasicSecurityChecks(WP_REST_Request $request, string $clientIp): void
    {
        // Lighter security checks for heartbeat requests
        if ($this->isBlockedIp($clientIp)) {
            throw new SecurityException('IP address is blocked', 'ip_blocked');
        }
    }

    private function validateActivationRequest(string $licenseKey, string $domain): void
    {
        if (empty($licenseKey) || strlen($licenseKey) < 10) {
            throw new ValidationException('Invalid license key format', 'invalid_license_key_format');
        }
        
        if (empty($domain) || !$this->isValidDomain($domain)) {
            throw new ValidationException('Invalid domain format', 'invalid_domain_format');
        }
        
        // Additional business logic validation
        if ($this->isBlockedDomain($domain)) {
            throw new ValidationException('Domain is not allowed', 'domain_blocked');
        }
    }

    private function validateValidationRequest(string $licenseKey, string $domain): void
    {
        if (empty($licenseKey)) {
            throw new ValidationException('License key is required', 'license_key_required');
        }
        
        if (empty($domain)) {
            throw new ValidationException('Domain is required', 'domain_required');
        }
    }

    private function validateDeactivationRequest(string $licenseKey, string $domain): void
    {
        $this->validateActivationRequest($licenseKey, $domain);
    }

    private function sanitizeParam($value, string $type): string
    {
        if (!is_string($value)) {
            throw new ValidationException("Invalid parameter type for {$type}", 'invalid_param_type');
        }
        
        switch ($type) {
            case 'license_key':
                return sanitize_text_field(trim($value));
            case 'domain':
                return strtolower(trim(sanitize_text_field($value)));
            default:
                return sanitize_text_field($value);
        }
    }

    private function isValidDomain(string $domain): bool
    {
        // Remove protocol if present
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#/.*$#', '', $domain); // Remove path
        
        // Basic domain validation
        return filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function isBlockedDomain(string $domain): bool
    {
        $blockedDomains = get_option('lsr_blocked_domains', []);
        
        if (is_string($blockedDomains)) {
            $blockedDomains = array_map('trim', explode("\n", $blockedDomains));
        }
        
        $normalizedDomain = strtolower($domain);
        
        foreach ($blockedDomains as $blocked) {
            if (fnmatch(strtolower($blocked), $normalizedDomain)) {
                return true;
            }
        }
        
        return false;
    }

    private function isBlockedIp(string $ip): bool
    {
        // Check against blocked IP list
        $blockedIps = get_transient('lsr_blocked_ips') ?: [];
        
        if (in_array($ip, $blockedIps)) {
            return true;
        }
        
        // Check against failed attempts
        $failedAttempts = get_transient('lsr_failed_attempts_' . md5($ip)) ?: 0;
        $maxFailedAttempts = get_option('lsr_max_failed_attempts', 10);
        
        return $failedAttempts >= $maxFailedAttempts;
    }

    private function isSuspiciousUserAgent(?string $userAgent): bool
    {
        if (empty($userAgent)) {
            return true;
        }
        
        $suspiciousPatterns = [
            '/bot/i', '/crawler/i', '/spider/i', '/scraper/i',
            '/python/i', '/curl/i', '/wget/i', '/libwww/i',
            '/headless/i', '/phantom/i', '/selenium/i'
        ];
        
        foreach ($suspiciousPatterns as $pattern) {
            if (preg_match($pattern, $userAgent)) {
                return true;
            }
        }
        
        return false;
    }

    private function hasInvalidHeaders(WP_REST_Request $request): bool
    {
        // Check for required headers
        $requiredHeaders = ['User-Agent'];
        
        foreach ($requiredHeaders as $header) {
            if (empty($request->get_header($header))) {
                return true;
            }
        }
        
        // Check for suspicious header combinations
        $contentType = $request->get_header('Content-Type');
        if ($request->get_method() === 'POST' && empty($contentType)) {
            return true;
        }
        
        return false;
    }

    private function getClientIp(WP_REST_Request $request): string
    {
        // Try to get real IP from various headers (in order of trust)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',           // Nginx proxy
            'HTTP_X_FORWARDED_FOR',     // Standard proxy header
            'HTTP_CLIENT_IP',           // Shared internet
            'REMOTE_ADDR'               // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Handle comma-separated IPs (X-Forwarded-For)
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

    private function successResponse(array $data, int $status = 200): WP_REST_Response
    {
        return new WP_REST_Response($data, $status);
    }

    private function errorResponse(LicenseServerException $exception): WP_REST_Response
    {
        $response = new WP_REST_Response(
            $exception->toArray(defined('WP_DEBUG') && WP_DEBUG),
            $this->getHttpStatusFromException($exception)
        );
        
        // Add security headers
        $response->header('X-Content-Type-Options', 'nosniff');
        $response->header('X-Frame-Options', 'DENY');
        
        return $response;
    }

    private function getHttpStatusFromException(LicenseServerException $exception): int
    {
        $statusMap = [
            'rate_limit_exceeded' => 429,
            'auth_required' => 401,
            'ip_blocked' => 403,
            'suspicious_user_agent' => 403,
            'invalid_headers' => 400,
            'honeypot_triggered' => 403,
            'validation_failed' => 400,
            'license_not_found' => 404,
            'license_expired' => 410,
            'activation_limit_exceeded' => 409,
            'domain_not_authorized' => 403,
        ];
        
        return $statusMap[$exception->getErrorCode()] ?? 500;
    }

    // Logging methods...

    private function logApiRequest(string $endpoint, WP_REST_Request $request, string $clientIp, float $startTime): void
    {
        if (!get_option('lsr_enable_api_logging', true)) {
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_api_requests';
        
        $responseTime = (microtime(true) - $startTime) * 1000; // Convert to milliseconds
        
        $wpdb->insert($table, [
            'endpoint' => $endpoint,
            'method' => $request->get_method(),
            'ip_address' => $clientIp,
            'user_agent_hash' => hash('sha256', $request->get_header('User-Agent') ?? ''),
            'response_code' => 200, // Will be updated if there's an error
            'response_time' => $responseTime,
            'created_at' => current_time('mysql', 1)
        ]);
    }

    private function logApiError(string $endpoint, LicenseServerException $exception, WP_REST_Request $request, string $clientIp): void
    {
        $this->logSecurityEvent('api_error', 'medium', $clientIp, [
            'endpoint' => $endpoint,
            'error_code' => $exception->getErrorCode(),
            'message' => $exception->getMessage(),
            'user_agent' => $request->get_header('User-Agent')
        ]);
    }

    private function logCriticalError(string $endpoint, \Exception $exception, WP_REST_Request $request, string $clientIp): void
    {
        $this->logSecurityEvent('critical_error', 'critical', $clientIp, [
            'endpoint' => $endpoint,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine()
        ]);
    }

    private function logSecurityEvent(string $eventType, string $severity, string $clientIp, array $details = []): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_security_events';
        
        $wpdb->insert($table, [
            'event_type' => $eventType,
            'severity' => $severity,
            'ip_address' => $clientIp,
            'details' => wp_json_encode($details),
            'created_at' => current_time('mysql', 1)
        ]);
    }

    private function recordApiMetrics(string $endpoint, float $responseTime): void
    {
        // Store metrics for performance monitoring
        $key = "lsr_metrics_{$endpoint}_" . date('Y-m-d-H');
        $metrics = get_transient($key) ?: ['count' => 0, 'total_time' => 0, 'max_time' => 0];
        
        $metrics['count']++;
        $metrics['total_time'] += $responseTime;
        $metrics['max_time'] = max($metrics['max_time'], $responseTime);
        
        set_transient($key, $metrics, 7200); // 2 hours
    }
}