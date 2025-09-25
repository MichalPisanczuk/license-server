<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Exceptions;

use Exception;
use Throwable;

/**
 * Base License Server Exception
 * 
 * All custom exceptions should extend this base class.
 */
abstract class LicenseServerException extends Exception
{
    protected string $errorCode;
    protected array $context;
    protected int $logLevel;

    /**
     * @param string $message
     * @param string $errorCode
     * @param int $code
     * @param array $context
     * @param int $logLevel
     * @param Throwable|null $previous
     */
    public function __construct(
        string $message = '',
        string $errorCode = '',
        int $code = 0,
        array $context = [],
        int $logLevel = LOG_ERR,
        Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        
        $this->errorCode = $errorCode ?: $this->getDefaultErrorCode();
        $this->context = $context;
        $this->logLevel = $logLevel;
        
        // Auto-log critical exceptions
        if ($this->shouldAutoLog()) {
            $this->logException();
        }
    }

    /**
     * Get the error code for API responses.
     *
     * @return string
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get additional context data.
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get log level for this exception.
     *
     * @return int
     */
    public function getLogLevel(): int
    {
        return $this->logLevel;
    }

    /**
     * Convert exception to array for API responses.
     *
     * @param bool $includeTrace Include stack trace
     * @return array
     */
    public function toArray(bool $includeTrace = false): array
    {
        $data = [
            'error' => true,
            'error_code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context
        ];

        if ($includeTrace && (defined('WP_DEBUG') && WP_DEBUG)) {
            $data['trace'] = $this->getTraceAsString();
        }

        return $data;
    }

    /**
     * Convert to JSON string.
     *
     * @param bool $includeTrace
     * @return string
     */
    public function toJson(bool $includeTrace = false): string
    {
        return wp_json_encode($this->toArray($includeTrace));
    }

    /**
     * Get default error code for this exception type.
     *
     * @return string
     */
    abstract protected function getDefaultErrorCode(): string;

    /**
     * Determine if this exception should be auto-logged.
     *
     * @return bool
     */
    protected function shouldAutoLog(): bool
    {
        return $this->logLevel >= LOG_WARNING;
    }

    /**
     * Log this exception.
     */
    protected function logException(): void
    {
        $logData = [
            'exception' => get_class($this),
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => $this->context,
            'timestamp' => current_time('mysql', 1)
        ];

        error_log('[License Server Exception] ' . wp_json_encode($logData));

        // Store critical errors in database
        if ($this->logLevel >= LOG_ERR) {
            $this->storeCriticalError($logData);
        }
    }

    /**
     * Store critical errors in database for admin review.
     *
     * @param array $logData
     */
    private function storeCriticalError(array $logData): void
    {
        global $wpdb;
        
        $table = $wpdb->prefix . 'lsr_error_log';
        
        // Create table if not exists
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                exception_class VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                error_code VARCHAR(100),
                file VARCHAR(500),
                line INT,
                context LONGTEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_error_code (error_code),
                INDEX idx_created (created_at)
            )
        ");
        
        $wpdb->insert($table, [
            'exception_class' => get_class($this),
            'message' => $this->getMessage(),
            'error_code' => $this->errorCode,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
            'context' => wp_json_encode($logData['context']),
            'created_at' => $logData['timestamp']
        ]);
    }

    /**
     * Create user-friendly message for front-end.
     *
     * @return string
     */
    public function getUserMessage(): string
    {
        // Override in child classes for user-friendly messages
        return __('An error occurred while processing your request.', 'license-server');
    }
}

/**
 * License Key Generation Exception
 */
class LicenseKeyGenerationException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'license_key_generation_failed';
    }

    public function getUserMessage(): string
    {
        return __('Failed to generate license key. Please try again.', 'license-server');
    }
}

/**
 * Invalid License Key Exception
 */
class InvalidLicenseKeyException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'invalid_license_key';
    }

    public function getUserMessage(): string
    {
        return __('The provided license key is invalid.', 'license-server');
    }
}

/**
 * License Not Found Exception
 */
class LicenseNotFoundException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'license_not_found';
    }

    public function getUserMessage(): string
    {
        return __('License not found.', 'license-server');
    }
}

/**
 * License Expired Exception
 */
class LicenseExpiredException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'license_expired';
    }

    public function getUserMessage(): string
    {
        return __('Your license has expired. Please renew to continue receiving updates.', 'license-server');
    }
}

/**
 * Activation Limit Exceeded Exception
 */
class ActivationLimitExceededException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'activation_limit_exceeded';
    }

    public function getUserMessage(): string
    {
        return __('License activation limit exceeded. Please deactivate from other domains first.', 'license-server');
    }
}

/**
 * License Inactive Exception
 */
class LicenseInactiveException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'license_inactive';
    }

    public function getUserMessage(): string
    {
        return __('This license is not active. Please check your subscription status.', 'license-server');
    }
}

/**
 * Domain Not Authorized Exception
 */
class DomainNotAuthorizedException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'domain_not_authorized';
    }

    public function getUserMessage(): string
    {
        return __('This domain is not authorized to use this license.', 'license-server');
    }
}

/**
 * Rate Limit Exceeded Exception
 */
class RateLimitExceededException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'rate_limit_exceeded';
    }

    public function getUserMessage(): string
    {
        return __('Too many requests. Please try again later.', 'license-server');
    }
}

/**
 * Database Exception
 */
class DatabaseException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'database_error';
    }

    public function getUserMessage(): string
    {
        return __('A database error occurred. Please try again later.', 'license-server');
    }
}

/**
 * Validation Exception
 */
class ValidationException extends LicenseServerException
{
    protected function getDefaultErrorCode(): string
    {
        return 'validation_failed';
    }

    public function getUserMessage(): string
    {
        return __('Validation failed. Please check your input and try again.', 'license-server');
    }
}

/**
 * Security Exception
 */
class SecurityException extends LicenseServerException
{
    public function __construct(
        string $message = '',
        string $errorCode = '',
        int $code = 0,
        array $context = [],
        Throwable $previous = null
    ) {
        // Security exceptions always get logged as critical
        parent::__construct($message, $errorCode, $code, $context, LOG_CRIT, $previous);
    }

    protected function getDefaultErrorCode(): string
    {
        return 'security_violation';
    }

    public function getUserMessage(): string
    {
        return __('Security check failed.', 'license-server');
    }
}

/**
 * CSRF Exception
 */
class CsrfException extends SecurityException
{
    protected function getDefaultErrorCode(): string
    {
        return 'csrf_token_invalid';
    }

    public function getUserMessage(): string
    {
        return __('Security token is invalid. Please refresh the page and try again.', 'license-server');
    }
}