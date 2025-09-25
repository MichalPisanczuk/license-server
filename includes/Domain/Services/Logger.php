<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Services;

/**
 * Unified logging service for License Server
 */
class Logger
{
    private const LOG_PREFIX = '[License Server]';
    private bool $apiLogging;
    private bool $securityLogging;
    private bool $cronLogging;
    private bool $validationLogging;

    public function __construct()
    {
        $this->apiLogging = (bool) get_option('lsr_enable_api_logging', true);
        $this->securityLogging = (bool) get_option('lsr_enable_security_logging', true);
        $this->cronLogging = (bool) get_option('lsr_enable_cron_logging', true);
        $this->validationLogging = (bool) get_option('lsr_enable_validation_logging', false);
    }

    public function log(string $message, string $context = 'general', string $level = 'info', array $data = []): void
    {
        // Check if context logging is enabled
        if (!$this->shouldLog($context)) {
            return;
        }

        $logEntry = sprintf(
            "%s [%s] [%s] %s",
            self::LOG_PREFIX,
            strtoupper($level),
            $context,
            $message
        );

        if (!empty($data)) {
            $logEntry .= ' | Data: ' . wp_json_encode($data);
        }

        error_log($logEntry);

        // Store critical errors in database
        if ($level === 'error' || $level === 'critical') {
            $this->storeInDatabase($message, $context, $level, $data);
        }
    }

    private function shouldLog(string $context): bool
    {
        return match($context) {
            'api' => $this->apiLogging,
            'security' => $this->securityLogging,
            'cron' => $this->cronLogging,
            'validation' => $this->validationLogging,
            default => true
        };
    }

    private function storeInDatabase(string $message, string $context, string $level, array $data): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_error_log';

        $wpdb->insert($table, [
            'exception_class' => $context,
            'message' => $message,
            'error_code' => $level,
            'context' => wp_json_encode($data),
            'created_at' => current_time('mysql', 1)
        ]);
    }

    // Helper methods for different log levels
    public function info(string $message, string $context = 'general', array $data = []): void
    {
        $this->log($message, $context, 'info', $data);
    }

    public function warning(string $message, string $context = 'general', array $data = []): void
    {
        $this->log($message, $context, 'warning', $data);
    }

    public function error(string $message, string $context = 'general', array $data = []): void
    {
        $this->log($message, $context, 'error', $data);
    }

    public function critical(string $message, string $context = 'general', array $data = []): void
    {
        $this->log($message, $context, 'critical', $data);
    }
}