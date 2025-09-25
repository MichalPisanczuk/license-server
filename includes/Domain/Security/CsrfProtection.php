<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Security;

use MyShop\LicenseServer\Domain\Exceptions\CsrfException;

/**
 * CSRF Protection Service
 * 
 * Provides Cross-Site Request Forgery protection for forms and AJAX requests.
 * Uses WordPress nonce system with additional security layers.
 */
class CsrfProtection
{
    /** @var string Nonce action prefix */
    private const NONCE_PREFIX = 'lsr_';
    
    /** @var int Nonce lifetime (12 hours) */
    private const NONCE_LIFETIME = 43200;
    
    /** @var string Session token key */
    private const SESSION_TOKEN_KEY = 'lsr_session_token';

    /**
     * Generate CSRF token for given action.
     *
     * @param string $action Action identifier
     * @param int|null $userId User ID (defaults to current user)
     * @return string
     */
    public static function generateToken(string $action, ?int $userId = null): string
    {
        $userId = $userId ?? get_current_user_id();
        $fullAction = self::NONCE_PREFIX . $action;
        
        // Create WordPress nonce
        $nonce = wp_create_nonce($fullAction);
        
        // Add additional entropy
        $sessionToken = self::getOrCreateSessionToken();
        $timestamp = time();
        
        // Create enhanced token
        $tokenData = [
            'nonce' => $nonce,
            'user_id' => $userId,
            'action' => $action,
            'timestamp' => $timestamp,
            'session' => substr($sessionToken, 0, 8) // First 8 chars only
        ];
        
        $signature = self::signTokenData($tokenData);
        $tokenData['signature'] = $signature;
        
        return base64_encode(wp_json_encode($tokenData));
    }

    /**
     * Verify CSRF token.
     *
     * @param string $token Token to verify
     * @param string $action Expected action
     * @param int|null $userId Expected user ID
     * @return bool
     * @throws CsrfException
     */
    public static function verifyToken(string $token, string $action, ?int $userId = null): bool
    {
        try {
            $tokenData = json_decode(base64_decode($token), true);
            
            if (!is_array($tokenData) || !self::hasRequiredFields($tokenData)) {
                throw new CsrfException('Invalid token format', 'invalid_csrf_format');
            }
            
            // Verify signature first
            $expectedSignature = self::signTokenData($tokenData, false);
            if (!hash_equals($expectedSignature, $tokenData['signature'])) {
                throw new CsrfException('Token signature verification failed', 'csrf_signature_invalid');
            }
            
            // Verify action
            if ($tokenData['action'] !== $action) {
                throw new CsrfException('Token action mismatch', 'csrf_action_mismatch', 0, [
                    'expected' => $action,
                    'actual' => $tokenData['action']
                ]);
            }
            
            // Verify user
            $currentUserId = $userId ?? get_current_user_id();
            if ((int)$tokenData['user_id'] !== $currentUserId) {
                throw new CsrfException('Token user mismatch', 'csrf_user_mismatch');
            }
            
            // Verify WordPress nonce
            $fullAction = self::NONCE_PREFIX . $action;
            if (!wp_verify_nonce($tokenData['nonce'], $fullAction)) {
                throw new CsrfException('WordPress nonce verification failed', 'csrf_nonce_invalid');
            }
            
            // Verify timestamp (not older than nonce lifetime)
            if (time() - $tokenData['timestamp'] > self::NONCE_LIFETIME) {
                throw new CsrfException('Token expired', 'csrf_token_expired');
            }
            
            // Verify session token
            $currentSessionToken = self::getOrCreateSessionToken();
            $expectedSessionPrefix = substr($currentSessionToken, 0, 8);
            if ($tokenData['session'] !== $expectedSessionPrefix) {
                throw new CsrfException('Session token mismatch', 'csrf_session_invalid');
            }
            
            return true;
            
        } catch (CsrfException $e) {
            // Re-throw CSRF exceptions
            throw $e;
        } catch (\Exception $e) {
            // Wrap other exceptions
            throw new CsrfException('Token verification failed: ' . $e->getMessage(), 'csrf_verification_error', 0, [], $e);
        }
    }

    /**
     * Get CSRF token from request.
     *
     * @param \WP_REST_Request|\WP_Query|array $request
     * @return string|null
     */
    public static function getTokenFromRequest($request): ?string
    {
        // Try different sources
        $sources = [];
        
        if ($request instanceof \WP_REST_Request) {
            $sources[] = $request->get_header('X-CSRF-Token');
            $sources[] = $request->get_param('_csrf_token');
        } elseif (is_array($request)) {
            $sources[] = $request['_csrf_token'] ?? null;
            $sources[] = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        } else {
            $sources[] = $_POST['_csrf_token'] ?? null;
            $sources[] = $_GET['_csrf_token'] ?? null;
            $sources[] = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        }
        
        foreach ($sources as $token) {
            if (!empty($token) && is_string($token)) {
                return $token;
            }
        }
        
        return null;
    }

    /**
     * Middleware for REST API CSRF protection.
     *
     * @param \WP_REST_Request $request
     * @param string $action
     * @return void
     * @throws CsrfException
     */
    public static function verifyRestRequest(\WP_REST_Request $request, string $action): void
    {
        // Skip verification for GET requests (should be idempotent)
        if ($request->get_method() === 'GET') {
            return;
        }
        
        $token = self::getTokenFromRequest($request);
        
        if (empty($token)) {
            throw new CsrfException('CSRF token missing', 'csrf_token_missing');
        }
        
        self::verifyToken($token, $action);
    }

    /**
     * Generate hidden form field with CSRF token.
     *
     * @param string $action
     * @param bool $echo
     * @return string
     */
    public static function formField(string $action, bool $echo = true): string
    {
        $token = self::generateToken($action);
        $field = sprintf(
            '<input type="hidden" name="_csrf_token" value="%s" />',
            esc_attr($token)
        );
        
        if ($echo) {
            echo $field;
        }
        
        return $field;
    }

    /**
     * Generate JavaScript object with CSRF token.
     *
     * @param string $action
     * @return string
     */
    public static function getJavaScriptObject(string $action): string
    {
        $token = self::generateToken($action);
        
        return wp_json_encode([
            'token' => $token,
            'action' => $action,
            'header_name' => 'X-CSRF-Token'
        ]);
    }

    /**
     * Refresh CSRF token (for AJAX operations).
     *
     * @param string $action
     * @return array
     */
    public static function refreshToken(string $action): array
    {
        return [
            'success' => true,
            'token' => self::generateToken($action),
            'action' => $action
        ];
    }

    /**
     * Check if token data has all required fields.
     *
     * @param array $tokenData
     * @return bool
     */
    private static function hasRequiredFields(array $tokenData): bool
    {
        $required = ['nonce', 'user_id', 'action', 'timestamp', 'session', 'signature'];
        
        foreach ($required as $field) {
            if (!isset($tokenData[$field])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Sign token data.
     *
     * @param array $tokenData
     * @param bool $excludeSignature
     * @return string
     */
    private static function signTokenData(array $tokenData, bool $excludeSignature = true): string
    {
        $dataToSign = $tokenData;
        
        if ($excludeSignature) {
            unset($dataToSign['signature']);
        }
        
        ksort($dataToSign); // Ensure consistent order
        $serialized = serialize($dataToSign);
        
        $secret = self::getSigningSecret();
        return hash_hmac('sha256', $serialized, $secret);
    }

    /**
     * Get or create signing secret.
     *
     * @return string
     */
    private static function getSigningSecret(): string
    {
        $secret = get_option('lsr_csrf_signing_secret');
        
        if (empty($secret)) {
            $secret = bin2hex(random_bytes(32));
            update_option('lsr_csrf_signing_secret', $secret);
        }
        
        return $secret;
    }

    /**
     * Get or create session token.
     *
     * @return string
     */
    private static function getOrCreateSessionToken(): string
    {
        // Try to get from user session first
        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            $userToken = get_user_meta($userId, self::SESSION_TOKEN_KEY, true);
            
            if (!empty($userToken)) {
                return $userToken;
            }
        }
        
        // Try to get from PHP session
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionToken = $_SESSION[self::SESSION_TOKEN_KEY] ?? '';
            if (!empty($sessionToken)) {
                return $sessionToken;
            }
        }
        
        // Generate new token
        $newToken = bin2hex(random_bytes(16));
        
        // Store in user meta if logged in
        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            update_user_meta($userId, self::SESSION_TOKEN_KEY, $newToken);
        }
        
        // Store in session if available
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION[self::SESSION_TOKEN_KEY] = $newToken;
        }
        
        return $newToken;
    }

    /**
     * Clear session token (on logout).
     *
     * @param int|null $userId
     */
    public static function clearSessionToken(?int $userId = null): void
    {
        $userId = $userId ?? get_current_user_id();
        
        if ($userId > 0) {
            delete_user_meta($userId, self::SESSION_TOKEN_KEY);
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset($_SESSION[self::SESSION_TOKEN_KEY]);
        }
    }

    /**
     * Generate double-submit cookie for additional protection.
     *
     * @param string $action
     * @return string Cookie value
     */
    public static function setDoubleCookie(string $action): string
    {
        $value = bin2hex(random_bytes(16));
        $cookieName = 'lsr_csrf_' . md5($action);
        
        setcookie(
            $cookieName,
            $value,
            time() + self::NONCE_LIFETIME,
            '/',
            '',
            is_ssl(),
            true // HTTP only
        );
        
        return $value;
    }

    /**
     * Verify double-submit cookie.
     *
     * @param string $action
     * @param string $expectedValue
     * @return bool
     */
    public static function verifyDoubleCookie(string $action, string $expectedValue): bool
    {
        $cookieName = 'lsr_csrf_' . md5($action);
        $cookieValue = $_COOKIE[$cookieName] ?? '';
        
        return !empty($cookieValue) && hash_equals($cookieValue, $expectedValue);
    }
}