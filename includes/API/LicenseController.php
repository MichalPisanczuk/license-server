<?php
namespace MyShop\LicenseServer\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MyShop\LicenseServer\Domain\Services\LicenseService;
use MyShop\LicenseServer\API\ResponseFormatter;
use function MyShop\LicenseServer\lsr;

/**
 * Secure license controller with proper error handling and logging.
 */
class LicenseController
{
    /**
     * Handle license activation request.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function activate(WP_REST_Request $request)
    {
        try {
            // Extract validated parameters (already sanitized by REST validation)
            $licenseKey = $request->get_param('license_key');
            $domain = $request->get_param('domain');
            
            // Get client IP for tracking
            $clientIp = $this->getClientIp($request);
            
            // Log activation attempt
            $this->logApiEvent('activation_attempt', [
                'license_key_hash' => hash('sha256', $licenseKey), // Don't log actual key
                'domain' => $domain,
                'ip' => $clientIp,
                'user_agent' => $request->get_header('User-Agent')
            ]);

            /** @var LicenseService $service */
            $service = lsr(LicenseService::class);
            if (!$service) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('License service is temporarily unavailable.', 'license-server'),
                    500
                );
            }

            // Attempt activation
            $result = $service->activateLicense($licenseKey, $domain, $clientIp);
            
            if (!$result['success']) {
                // Log failed activation
                $this->logApiEvent('activation_failed', [
                    'reason' => $result['reason'],
                    'license_key_hash' => hash('sha256', $licenseKey),
                    'domain' => $domain,
                    'ip' => $clientIp
                ]);

                return $this->errorResponse(
                    $result['reason'],
                    $this->getErrorMessage($result['reason']),
                    $this->getErrorStatusCode($result['reason'])
                );
            }

            // Log successful activation
            $this->logApiEvent('activation_success', [
                'license_id' => $result['license_id'],
                'domain' => $domain,
                'ip' => $clientIp
            ]);

            // Format success response
            $response = [
                'ok' => true,
                'status' => $result['status'],
                'expires_at' => $result['expires_at'],
                'message' => sprintf(
                    __('License activated successfully on %s', 'license-server'),
                    $domain
                )
            ];

            // Add remaining activations if applicable
            if (isset($result['remaining_activations'])) {
                $response['remaining_activations'] = $result['remaining_activations'];
            }

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            // Log unexpected errors
            $this->logApiEvent('activation_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'domain' => $domain ?? 'unknown',
                'ip' => $clientIp ?? 'unknown'
            ]);

            return $this->errorResponse(
                'internal_error',
                __('An unexpected error occurred. Please try again later.', 'license-server'),
                500
            );
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
        try {
            $licenseKey = $request->get_param('license_key');
            $domain = $request->get_param('domain');
            $clientIp = $this->getClientIp($request);

            // Log validation attempt (less verbose than activation)
            if (get_option('lsr_enable_validation_logging', false)) {
                $this->logApiEvent('validation_attempt', [
                    'license_key_hash' => hash('sha256', $licenseKey),
                    'domain' => $domain,
                    'ip' => $clientIp
                ]);
            }

            /** @var LicenseService $service */
            $service = lsr(LicenseService::class);
            if (!$service) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('License service is temporarily unavailable.', 'license-server'),
                    500
                );
            }

            $result = $service->validateLicense($licenseKey, $domain);
            
            if (!$result['success']) {
                // Only log validation failures for inactive licenses (not expired)
                if ($result['reason'] !== 'expired') {
                    $this->logApiEvent('validation_failed', [
                        'reason' => $result['reason'],
                        'status' => $result['status'],
                        'license_key_hash' => hash('sha256', $licenseKey),
                        'domain' => $domain
                    ]);
                }

                return new WP_REST_Response([
                    'ok' => false,
                    'status' => $result['status'],
                    'reason' => $result['reason'],
                    'message' => $this->getErrorMessage($result['reason']),
                    'expires_at' => $result['expires_at'] ?? null
                ], 200); // Return 200 for validation responses
            }

            // Success response
            $response = [
                'ok' => true,
                'status' => $result['status'],
                'reason' => $result['reason'],
                'expires_at' => $result['expires_at']
            ];

            // Add grace period info if applicable
            if (isset($result['grace_until'])) {
                $response['grace_until'] = $result['grace_until'];
            }

            return new WP_REST_Response($response, 200);

        } catch (\Exception $e) {
            $this->logApiEvent('validation_error', [
                'error' => $e->getMessage(),
                'domain' => $domain ?? 'unknown',
                'ip' => $clientIp ?? 'unknown'
            ]);

            return $this->errorResponse(
                'internal_error',
                __('Validation service temporarily unavailable.', 'license-server'),
                500
            );
        }
    }

    /**
     * Get client IP from various headers.
     *
     * @param WP_REST_Request $request
     * @return string
     */
    private function getClientIp(WP_REST_Request $request): string
    {
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
                
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                
                // In development, allow private IPs
                if (WP_DEBUG && filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Create standardized error response.
     *
     * @param string $code Error code
     * @param string $message Error message
     * @param int $statusCode HTTP status code
     * @return WP_REST_Response
     */
    private function errorResponse(string $code, string $message, int $statusCode = 400): WP_REST_Response
    {
        return new WP_REST_Response([
            'ok' => false,
            'error_code' => $code,
            'message' => $message,
            'timestamp' => current_time('mysql', 1)
        ], $statusCode);
    }

    /**
     * Get human-readable error message for error codes.
     *
     * @param string $reason
     * @return string
     */
    private function getErrorMessage(string $reason): string
    {
        $messages = [
            'not_found' => __('License key not found.', 'license-server'),
            'inactive' => __('License is inactive. Please contact support.', 'license-server'),
            'expired' => __('License has expired. Please renew your subscription.', 'license-server'),
            'activation_limit' => __('License activation limit exceeded. Deactivate other sites first.', 'license-server'),
            'invalid_domain' => __('Domain not allowed for this license.', 'license-server'),
            'payment_failed' => __('License suspended due to payment issues. Please update your payment method.', 'license-server'),
            'cancelled' => __('License cancelled. Please purchase a new license.', 'license-server'),
            'on_hold' => __('License on hold. Please contact support.', 'license-server'),
            'grace' => __('License in grace period. Please renew soon.', 'license-server')
        ];

        return $messages[$reason] ?? __('License validation failed.', 'license-server');
    }

    /**
     * Get appropriate HTTP status code for error reasons.
     *
     * @param string $reason
     * @return int
     */
    private function getErrorStatusCode(string $reason): int
    {
        $codes = [
            'not_found' => 404,
            'inactive' => 403,
            'expired' => 403,
            'activation_limit' => 429,
            'invalid_domain' => 400,
            'payment_failed' => 402,
            'cancelled' => 410,
            'on_hold' => 423,
            'service_unavailable' => 503,
            'internal_error' => 500
        ];

        return $codes[$reason] ?? 400;
    }

    /**
     * Log API events for monitoring and debugging.
     *
     * @param string $event
     * @param array $data
     */
    private function logApiEvent(string $event, array $data = []): void
    {
        if (!get_option('lsr_enable_api_logging', true)) {
            return;
        }

        $logData = array_merge([
            'component' => 'LicenseController',
            'event' => $event,
            'timestamp' => current_time('mysql', 1),
            'request_id' => uniqid('lsr_', true)
        ], $data);

        error_log('[License Server API] ' . wp_json_encode($logData));
        
        // Store critical events in database for admin review
        $criticalEvents = ['activation_failed', 'validation_failed', 'activation_error', 'validation_error'];
        if (in_array($event, $criticalEvents)) {
            $this->storeApiEvent($logData);
        }
    }

    /**
     * Store API events in database for admin monitoring.
     *
     * @param array $logData
     */
    private function storeApiEvent(array $logData): void
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lsr_api_events';
        
        // Create table if not exists (should be in migrations)
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event VARCHAR(50) NOT NULL,
                component VARCHAR(50) NOT NULL,
                ip VARCHAR(45),
                data LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_event (event),
                INDEX idx_component (component),
                INDEX idx_created (created_at)
            )
        ");
        
        $wpdb->insert($table, [
            'event' => $logData['event'],
            'component' => $logData['component'],
            'ip' => $logData['ip'] ?? null,
            'data' => wp_json_encode($logData),
            'created_at' => $logData['timestamp']
        ]);
    }

    /**
     * Check if license key exists (for validation purposes).
     *
     * @param string $licenseKey
     * @return bool
     */
    private function licenseExists(string $licenseKey): bool
    {
        /** @var \MyShop\LicenseServer\Data\Repositories\LicenseRepository $repo */
        $repo = lsr(\MyShop\LicenseServer\Data\Repositories\LicenseRepository::class);
        
        if (!$repo) {
            return false;
        }

        $license = $repo->findByKey($licenseKey);
        return $license !== null;
    }

    /**
     * Sanitize and validate license key format.
     *
     * @param string $licenseKey
     * @return string|null
     */
    private function sanitizeLicenseKey(string $licenseKey): ?string
    {
        $key = sanitize_text_field($licenseKey);
        
        // Must be 32 character hex string
        if (!preg_match('/^[a-f0-9]{32}$/i', $key)) {
            return null;
        }
        
        return strtolower($key);
    }
}