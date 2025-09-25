<?php
namespace MyShop\LicenseServer\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use MyShop\LicenseServer\Domain\Services\LicenseService;
use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;
use MyShop\LicenseServer\Data\Repositories\EnhancedLicenseRepository;
use MyShop\LicenseServer\Domain\Services\SignedUrlService;
use function MyShop\LicenseServer\lsr;

/**
 * Secure update controller with proper file handling and security.
 */
class UpdateController
{
    /** @var int Maximum file size for downloads (100MB) */
    private const MAX_FILE_SIZE = 104857600;
    
    /** @var array Allowed file extensions */
    private const ALLOWED_EXTENSIONS = ['zip'];

    /**
     * Check for available updates.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function check(WP_REST_Request $request)
    {
        try {
            $licenseKey = $request->get_param('license_key');
            $slug = $request->get_param('slug');
            $version = $request->get_param('version');
            $domain = $request->get_param('domain');
            $clientIp = $this->getClientIp($request);

            $this->logApiEvent('update_check_attempt', [
                'license_key_hash' => hash('sha256', $licenseKey),
                'slug' => $slug,
                'current_version' => $version,
                'domain' => $domain,
                'ip' => $clientIp
            ]);

            // Validate license first
            /** @var LicenseService $licenseService */
            $licenseService = lsr(LicenseService::class);
            if (!$licenseService) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('Update service temporarily unavailable.', 'license-server'),
                    503
                );
            }

            $validation = $licenseService->validateLicense($licenseKey, $domain);
            
            if (!$validation['success']) {
                $this->logApiEvent('update_check_failed', [
                    'reason' => $validation['reason'],
                    'status' => $validation['status'],
                    'license_key_hash' => hash('sha256', $licenseKey)
                ]);

                return new WP_REST_Response([
                    'ok' => false,
                    'reason' => $validation['reason'],
                    'status' => $validation['status'],
                    'message' => $this->getLicenseErrorMessage($validation['reason'])
                ], 200);
            }

            $productId = (int) $validation['product_id'];
            $licenseId = (int) $validation['license_id'];

            // Check if product slug matches
            if (!$this->validateProductSlug($productId, $slug)) {
                $this->logApiEvent('update_check_slug_mismatch', [
                    'product_id' => $productId,
                    'provided_slug' => $slug,
                    'license_key_hash' => hash('sha256', $licenseKey)
                ]);

                return new WP_REST_Response([
                    'ok' => false,
                    'reason' => 'slug_mismatch',
                    'message' => __('Plugin slug does not match license.', 'license-server')
                ], 200);
            }

            // Get latest release
            /** @var ReleaseRepository $releases */
            $releases = lsr(ReleaseRepository::class);
            if (!$releases) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('Release service unavailable.', 'license-server'),
                    503
                );
            }

            $release = $releases->getLatestRelease($productId, $slug);
            
            if (!$release) {
                return new WP_REST_Response([
                    'ok' => false,
                    'reason' => 'no_release',
                    'message' => __('No releases available for this product.', 'license-server')
                ], 200);
            }

            // Compare versions
            if (!$this->isNewerVersion($release['version'], $version)) {
                return new WP_REST_Response([
                    'ok' => false,
                    'reason' => 'up_to_date',
                    'current_version' => $release['version'],
                    'message' => __('Plugin is up to date.', 'license-server')
                ], 200);
            }

            // Verify release file exists and is safe
            if (!$this->validateReleaseFile($release)) {
                $this->logApiEvent('update_check_file_missing', [
                    'release_id' => $release['id'],
                    'zip_path' => $release['zip_path'],
                    'product_id' => $productId
                ]);

                return $this->errorResponse(
                    'release_unavailable',
                    __('Release file temporarily unavailable.', 'license-server'),
                    503
                );
            }

            // Generate signed download URL
            /** @var SignedUrlService $signed */
            $signed = lsr(SignedUrlService::class);
            if (!$signed) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('Download service unavailable.', 'license-server'),
                    503
                );
            }

            $signedData = $signed->generate($licenseId, (int) $release['id']);

            $this->logApiEvent('update_check_success', [
                'license_id' => $licenseId,
                'release_id' => $release['id'],
                'old_version' => $version,
                'new_version' => $release['version'],
                'slug' => $slug
            ]);

            // Success response with update info
            return new WP_REST_Response([
                'ok' => true,
                'new_version' => $release['version'],
                'package' => $signedData['url'],
                'expires' => $signedData['expires'],
                'tested' => $release['min_wp'] ?? null,
                'requires' => $release['min_wp'] ?? null,
                'requires_php' => $release['min_php'] ?? null,
                'changelog' => $release['changelog'] ?? null,
                'download_size' => $this->getFileSize($release['zip_path']),
                'last_updated' => $release['released_at']
            ], 200);

        } catch (\Exception $e) {
            $this->logApiEvent('update_check_error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'slug' => $slug ?? 'unknown',
                'ip' => $clientIp ?? 'unknown'
            ]);

            return $this->errorResponse(
                'internal_error',
                __('Update check failed. Please try again later.', 'license-server'),
                500
            );
        }
    }

    /**
     * Download release file with security validation.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|void
     */
    public function download(WP_REST_Request $request)
    {
        try {
            $licenseId = (int) $request->get_param('license_id');
            $releaseId = (int) $request->get_param('release_id');
            $expires = (int) $request->get_param('expires');
            $signature = $request->get_param('sig');
            $clientIp = $this->getClientIp($request);

            $this->logApiEvent('download_attempt', [
                'license_id' => $licenseId,
                'release_id' => $releaseId,
                'expires' => $expires,
                'ip' => $clientIp,
                'user_agent' => $request->get_header('User-Agent')
            ]);

            // Verify signed URL
            /** @var SignedUrlService $signed */
            $signed = lsr(SignedUrlService::class);
            if (!$signed || !$signed->verify($licenseId, $releaseId, $expires, $signature)) {
                $this->logApiEvent('download_invalid_signature', [
                    'license_id' => $licenseId,
                    'release_id' => $releaseId,
                    'ip' => $clientIp
                ]);

                return new WP_REST_Response([
                    'error' => 'invalid_signature',
                    'message' => __('Invalid or expired download link.', 'license-server')
                ], 403);
            }

            // Get and validate license
            /** @var LicenseRepository $licenseRepo */
            $licenseRepo = lsr(EnhancedLicenseRepository::class);
            if (!$licenseRepo) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('License service unavailable.', 'license-server'),
                    503
                );
            }

            $license = $this->getLicenseById($licenseId);
            if (!$license) {
                $this->logApiEvent('download_license_not_found', [
                    'license_id' => $licenseId,
                    'ip' => $clientIp
                ]);

                return new WP_REST_Response([
                    'error' => 'license_not_found', 
                    'message' => __('License not found.', 'license-server')
                ], 404);
            }

            // Validate license status and expiry
            if (!$this->isLicenseValidForDownload($license)) {
                $this->logApiEvent('download_license_invalid', [
                    'license_id' => $licenseId,
                    'status' => $license['status'],
                    'expires_at' => $license['expires_at'],
                    'ip' => $clientIp
                ]);

                return new WP_REST_Response([
                    'error' => 'license_invalid',
                    'message' => __('License is not valid for downloads.', 'license-server')
                ], 403);
            }

            // Get and validate release
            /** @var ReleaseRepository $releases */
            $releases = lsr(ReleaseRepository::class);
            if (!$releases) {
                return $this->errorResponse(
                    'service_unavailable',
                    __('Release service unavailable.', 'license-server'),
                    503
                );
            }

            $release = $releases->findById($releaseId);
            if (!$release) {
                return new WP_REST_Response([
                    'error' => 'release_not_found',
                    'message' => __('Release not found.', 'license-server')
                ], 404);
            }

            // Verify release belongs to license product
            if ((int) $license['product_id'] !== (int) $release['product_id']) {
                $this->logApiEvent('download_product_mismatch', [
                    'license_id' => $licenseId,
                    'license_product' => $license['product_id'],
                    'release_product' => $release['product_id'],
                    'ip' => $clientIp
                ]);

                return new WP_REST_Response([
                    'error' => 'product_mismatch',
                    'message' => __('Release does not match license product.', 'license-server')
                ], 403);
            }

            // Get file path and validate
            $filePath = $this->getSecureFilePath($release['zip_path']);
            if (!$filePath) {
                return new WP_REST_Response([
                    'error' => 'file_not_found',
                    'message' => __('Release file not found.', 'license-server')
                ], 404);
            }

            // Final security checks on file
            if (!$this->validateFileForDownload($filePath)) {
                $this->logApiEvent('download_file_invalid', [
                    'release_id' => $releaseId,
                    'file_path' => $release['zip_path'],
                    'ip' => $clientIp
                ]);

                return new WP_REST_Response([
                    'error' => 'file_unavailable',
                    'message' => __('File temporarily unavailable.', 'license-server')
                ], 503);
            }

            // Log successful download
            $this->logApiEvent('download_success', [
                'license_id' => $licenseId,
                'release_id' => $releaseId,
                'product_id' => $license['product_id'],
                'version' => $release['version'],
                'file_size' => filesize($filePath),
                'ip' => $clientIp
            ]);

            // Stream file securely
            $this->streamFile($filePath, $release);

        } catch (\Exception $e) {
            $this->logApiEvent('download_error', [
                'error' => $e->getMessage(),
                'license_id' => $licenseId ?? 0,
                'release_id' => $releaseId ?? 0,
                'ip' => $clientIp ?? 'unknown'
            ]);

            return $this->errorResponse(
                'download_failed',
                __('Download failed. Please try again.', 'license-server'),
                500
            );
        }
    }

    /**
     * Check if version A is newer than version B.
     *
     * @param string $versionA
     * @param string $versionB
     * @return bool
     */
    private function isNewerVersion(string $versionA, string $versionB): bool
    {
        return version_compare($versionA, $versionB, '>');
    }

    /**
     * Validate that product slug matches the licensed product.
     *
     * @param int $productId
     * @param string $slug
     * @return bool
     */
    private function validateProductSlug(int $productId, string $slug): bool
    {
        $productSlug = get_post_meta($productId, '_lsr_slug', true);
        return !empty($productSlug) && $productSlug === $slug;
    }

    /**
     * Validate release file exists and is secure.
     *
     * @param array $release
     * @return bool
     */
    private function validateReleaseFile(array $release): bool
    {
        $filePath = $this->getSecureFilePath($release['zip_path']);
        
        if (!$filePath || !file_exists($filePath)) {
            return false;
        }

        // Check file size
        $fileSize = filesize($filePath);
        if ($fileSize === false || $fileSize > self::MAX_FILE_SIZE) {
            return false;
        }

        // Verify checksum if available
        if (!empty($release['checksum_sha256'])) {
            $actualChecksum = hash_file('sha256', $filePath);
            if ($actualChecksum !== $release['checksum_sha256']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get secure file path, preventing directory traversal.
     *
     * @param string $relativePath
     * @return string|null
     */
    private function getSecureFilePath(string $relativePath): ?string
    {
        // Remove any directory traversal attempts
        $relativePath = str_replace(['../', '..\\', '../', '..\\'], '', $relativePath);
        $relativePath = ltrim($relativePath, '/\\');
        
        // Validate file extension
        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS)) {
            return null;
        }

        $basePath = LSR_DIR . 'storage/releases/';
        $fullPath = $basePath . $relativePath;
        
        // Ensure file is within allowed directory
        $realBasePath = realpath($basePath);
        $realFullPath = realpath($fullPath);
        
        if (!$realFullPath || strpos($realFullPath, $realBasePath) !== 0) {
            return null;
        }
        
        return $realFullPath;
    }

    /**
     * Get license by ID.
     *
     * @param int $licenseId
     * @return array|null
     */
    private function getLicenseById(int $licenseId): ?array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_licenses';
        
        $license = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $licenseId),
            ARRAY_A
        );
        
        return $license ?: null;
    }

    /**
     * Check if license is valid for downloads.
     *
     * @param array $license
     * @return bool
     */
    private function isLicenseValidForDownload(array $license): bool
    {
        // Check status
        if ($license['status'] !== 'active') {
            return false;
        }

        $now = time();
        
        // Check expiration
        if ($license['expires_at'] !== null) {
            $expiresAt = strtotime($license['expires_at']);
            if ($expiresAt < $now) {
                // Check grace period
                if ($license['grace_until'] !== null) {
                    $graceUntil = strtotime($license['grace_until']);
                    return $graceUntil >= $now;
                }
                return false;
            }
        }

        return true;
    }

    /**
     * Validate file for download security.
     *
     * @param string $filePath
     * @return bool
     */
    private function validateFileForDownload(string $filePath): bool
    {
        // File must exist and be readable
        if (!is_file($filePath) || !is_readable($filePath)) {
            return false;
        }

        // Check MIME type
        $mimeType = mime_content_type($filePath);
        if ($mimeType !== 'application/zip') {
            return false;
        }

        // Additional security check - ensure it's actually a ZIP file
        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }
        
        $header = fread($handle, 4);
        fclose($handle);
        
        // ZIP file magic bytes
        $zipMagic = "\x50\x4b\x03\x04";
        return $header === $zipMagic;
    }

    /**
     * Stream file to client with proper headers.
     *
     * @param string $filePath
     * @param array $release
     */
    private function streamFile(string $filePath, array $release): void
    {
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }

        $filename = basename($filePath);
        $fileSize = filesize($filePath);
        
        // Set headers
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        header('Pragma: no-cache');
        
        // Security headers
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        // Stream the file in chunks to handle large files
        $chunkSize = 8192; // 8KB chunks
        $handle = fopen($filePath, 'rb');
        
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, $chunkSize);
                flush();
            }
            fclose($handle);
        }
        
        exit;
    }

    /**
     * Get file size in human readable format.
     *
     * @param string $relativePath
     * @return string|null
     */
    private function getFileSize(string $relativePath): ?string
    {
        $filePath = $this->getSecureFilePath($relativePath);
        if (!$filePath || !file_exists($filePath)) {
            return null;
        }
        
        $size = filesize($filePath);
        return $size ? size_format($size) : null;
    }

    /**
     * Get client IP from request.
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
     * Get error message for license validation failures.
     *
     * @param string $reason
     * @return string
     */
    private function getLicenseErrorMessage(string $reason): string
    {
        $messages = [
            'not_found' => __('License not found.', 'license-server'),
            'inactive' => __('License is inactive.', 'license-server'),
            'expired' => __('License has expired.', 'license-server'),
            'payment_failed' => __('License suspended due to payment issues.', 'license-server'),
            'cancelled' => __('License was cancelled.', 'license-server')
        ];

        return $messages[$reason] ?? __('License validation failed.', 'license-server');
    }

    /**
     * Create standardized error response.
     *
     * @param string $code
     * @param string $message
     * @param int $statusCode
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
     * Log API events.
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
            'component' => 'UpdateController',
            'event' => $event,
            'timestamp' => current_time('mysql', 1),
            'request_id' => uniqid('lsr_', true)
        ], $data);

        error_log('[License Server API] ' . wp_json_encode($logData));
    }
}