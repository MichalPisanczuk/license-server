<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Services;

use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use MyShop\LicenseServer\Data\Repositories\ActivationRepository;
use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;
use MyShop\LicenseServer\Domain\Services\SecureLicenseKeyService;
use MyShop\LicenseServer\Domain\ValueObjects\LicenseKey;
use MyShop\LicenseServer\Domain\Exceptions\{
    LicenseNotFoundException,
    LicenseExpiredException,
    LicenseInactiveException,
    ActivationLimitExceededException,
    DomainNotAuthorizedException,
    DatabaseException,
    ValidationException
};

/**
 * Enhanced License Service with proper error handling, transactions, and security.
 */
class EnhancedLicenseService
{
    private LicenseRepository $licenses;
    private ActivationRepository $activations;
    private ReleaseRepository $releases;
    private SecureLicenseKeyService $keyService;

    public function __construct(
        LicenseRepository $licenses,
        ActivationRepository $activations,
        ReleaseRepository $releases,
        SecureLicenseKeyService $keyService
    ) {
        $this->licenses = $licenses;
        $this->activations = $activations;
        $this->releases = $releases;
        $this->keyService = $keyService;
    }

    /**
     * Generate licenses for completed order with database transaction.
     *
     * @param \WC_Order $order
     * @return array Created license data
     * @throws DatabaseException
     */
    public function generateLicensesForOrder(\WC_Order $order): array
    {
        global $wpdb;
        
        try {
            $wpdb->query('START TRANSACTION');
            
            $createdLicenses = [];
            $items = $order->get_items();
            $userId = $order->get_user_id();
            $orderId = $order->get_id();

            foreach ($items as $item) {
                $productId = (int) $item->get_product_id();
                
                // Check if product is licensed
                if (!$this->isProductLicensed($productId)) {
                    continue;
                }
                
                // Skip if license already exists
                if ($this->licenses->existsByOrderAndProduct($orderId, $productId)) {
                    continue;
                }
                
                $licenseData = $this->createLicenseForProduct($userId, $productId, $orderId, $order);
                $createdLicenses[] = $licenseData;
            }
            
            $wpdb->query('COMMIT');
            
            // Send notifications after successful transaction
            foreach ($createdLicenses as $license) {
                $this->sendLicenseNotification($license, $order);
            }
            
            return $createdLicenses;
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw new DatabaseException(
                'Failed to generate licenses for order: ' . $e->getMessage(),
                'license_generation_failed',
                0,
                ['order_id' => $orderId],
                LOG_ERR,
                $e
            );
        }
    }

    /**
     * Activate license for domain with comprehensive validation.
     *
     * @param string $licenseKeyString
     * @param string $domain
     * @param string|null $ipAddress
     * @param string|null $userAgent
     * @return array Activation result
     * @throws LicenseNotFoundException|LicenseExpiredException|LicenseInactiveException|ActivationLimitExceededException
     */
    public function activateLicense(
        string $licenseKeyString,
        string $domain,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        global $wpdb;
        
        try {
            // Validate and create license key object
            $licenseKey = new LicenseKey($licenseKeyString);
            
            $wpdb->query('START TRANSACTION');
            
            // Find license by key hash
            $license = $this->findLicenseByKey($licenseKey);
            
            // Validate license status
            $this->validateLicenseForActivation($license, $domain);
            
            // Check activation limits
            $this->checkActivationLimits($license['id'], $license['max_activations']);
            
            // Normalize domain
            $normalizedDomain = $this->normalizeDomain($domain);
            
            // Record activation
            $ipHash = $ipAddress ? hash('sha256', $ipAddress . $this->getIpSalt()) : null;
            $this->activations->recordActivation(
                (int) $license['id'],
                $normalizedDomain,
                $ipHash,
                $userAgent
            );
            
            // Update license last_seen
            $this->licenses->update((int) $license['id'], [
                'last_seen_at' => current_time('mysql', 1)
            ]);
            
            $wpdb->query('COMMIT');
            
            $remainingActivations = $this->calculateRemainingActivations(
                $license['max_activations'],
                $license['id']
            );
            
            return [
                'success' => true,
                'status' => $license['status'],
                'expires_at' => $license['expires_at'],
                'remaining_activations' => $remainingActivations,
                'domain' => $normalizedDomain,
                'message' => sprintf(
                    __('License successfully activated on %s', 'license-server'),
                    $normalizedDomain
                )
            ];
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            
            if ($e instanceof LicenseNotFoundException || 
                $e instanceof LicenseExpiredException ||
                $e instanceof LicenseInactiveException ||
                $e instanceof ActivationLimitExceededException) {
                throw $e;
            }
            
            throw new DatabaseException(
                'License activation failed: ' . $e->getMessage(),
                'activation_database_error',
                0,
                [
                    'domain' => $domain,
                    'license_key_hash' => hash('sha256', $licenseKeyString)
                ],
                LOG_ERR,
                $e
            );
        }
    }

    /**
     * Validate existing license (heartbeat).
     *
     * @param string $licenseKeyString
     * @param string $domain
     * @param string|null $ipAddress
     * @return array Validation result
     * @throws LicenseNotFoundException|DomainNotAuthorizedException
     */
    public function validateLicense(
        string $licenseKeyString,
        string $domain,
        ?string $ipAddress = null
    ): array {
        try {
            $licenseKey = new LicenseKey($licenseKeyString);
            $license = $this->findLicenseByKey($licenseKey);
            $normalizedDomain = $this->normalizeDomain($domain);
            
            // Check if domain is activated for this license
            if (!$this->isDomainActivated($license['id'], $normalizedDomain)) {
                throw new DomainNotAuthorizedException(
                    "Domain {$normalizedDomain} is not activated for this license",
                    'domain_not_activated',
                    0,
                    ['domain' => $normalizedDomain]
                );
            }
            
            // Update last seen
            $ipHash = $ipAddress ? hash('sha256', $ipAddress . $this->getIpSalt()) : null;
            $this->activations->updateLastSeen($license['id'], $normalizedDomain, $ipHash);
            
            // Check current status
            $currentStatus = $this->getCurrentLicenseStatus($license);
            
            return [
                'success' => true,
                'status' => $currentStatus['status'],
                'expires_at' => $license['expires_at'],
                'grace_until' => $license['grace_until'],
                'reason' => $currentStatus['reason'] ?? null,
                'message' => $this->getStatusMessage($currentStatus['status'])
            ];
            
        } catch (LicenseNotFoundException | DomainNotAuthorizedException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new DatabaseException(
                'License validation failed: ' . $e->getMessage(),
                'validation_error',
                0,
                ['domain' => $domain],
                LOG_WARNING,
                $e
            );
        }
    }

    /**
     * Deactivate license from domain.
     *
     * @param string $licenseKeyString
     * @param string $domain
     * @return array
     * @throws LicenseNotFoundException|DomainNotAuthorizedException
     */
    public function deactivateLicense(string $licenseKeyString, string $domain): array
    {
        global $wpdb;
        
        try {
            $wpdb->query('START TRANSACTION');
            
            $licenseKey = new LicenseKey($licenseKeyString);
            $license = $this->findLicenseByKey($licenseKey);
            $normalizedDomain = $this->normalizeDomain($domain);
            
            if (!$this->isDomainActivated($license['id'], $normalizedDomain)) {
                throw new DomainNotAuthorizedException(
                    "Domain {$normalizedDomain} is not activated for this license"
                );
            }
            
            $this->activations->deactivateDomain($license['id'], $normalizedDomain);
            
            $wpdb->query('COMMIT');
            
            return [
                'success' => true,
                'message' => sprintf(
                    __('License deactivated from %s', 'license-server'),
                    $normalizedDomain
                ),
                'remaining_activations' => $this->calculateRemainingActivations(
                    $license['max_activations'],
                    $license['id']
                )
            ];
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Get license details for user.
     *
     * @param int $userId
     * @return array
     */
    public function getUserLicenses(int $userId): array
    {
        try {
            $licenses = $this->licenses->findByUser($userId);
            $result = [];
            
            foreach ($licenses as $license) {
                $activations = $this->activations->findByLicense($license['id']);
                $currentStatus = $this->getCurrentLicenseStatus($license);
                
                $result[] = [
                    'id' => $license['id'],
                    'product_id' => $license['product_id'],
                    'product_name' => get_the_title($license['product_id']),
                    'license_key_masked' => $this->maskLicenseKey($license['license_key_hash']),
                    'status' => $currentStatus['status'],
                    'expires_at' => $license['expires_at'],
                    'max_activations' => $license['max_activations'],
                    'active_domains' => array_column($activations, 'domain'),
                    'remaining_activations' => $this->calculateRemainingActivations(
                        $license['max_activations'],
                        $license['id']
                    ),
                    'created_at' => $license['created_at']
                ];
            }
            
            return $result;
            
        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to retrieve user licenses: ' . $e->getMessage(),
                'user_licenses_error',
                0,
                ['user_id' => $userId],
                LOG_WARNING,
                $e
            );
        }
    }

    // Private methods...

    private function createLicenseForProduct(int $userId, int $productId, int $orderId, \WC_Order $order): array
    {
        // Get product license settings
        $maxActivations = get_post_meta($productId, '_lsr_max_activations', true);
        $maxActivations = $maxActivations ? (int) $maxActivations : null;
        
        $validityDays = get_post_meta($productId, '_lsr_validity_days', true);
        $validityDays = $validityDays ? (int) $validityDays : null;
        
        // Generate secure license key
        $licenseKey = $this->keyService->generateSecureKey($productId, $userId);
        $keyHash = $this->keyService->hashLicenseKey($licenseKey->getValue());
        
        // Determine expiration and subscription
        $subscriptionId = null;
        $expiresAt = null;
        $graceUntil = null;
        
        if (class_exists('WC_Subscriptions')) {
            $subscriptions = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
            if (!empty($subscriptions)) {
                $subscription = array_shift($subscriptions);
                $subscriptionId = $subscription->get_id();
                $endDate = $subscription->get_date('end');
                if ($endDate) {
                    $expiresAt = date('Y-m-d H:i:s', strtotime($endDate));
                    $graceDays = get_option('lsr_grace_period_days', 7);
                    $graceUntil = date('Y-m-d H:i:s', strtotime($endDate . ' + ' . $graceDays . ' days'));
                }
            }
        } elseif ($validityDays) {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . $validityDays . ' days'));
        }
        
        // Create license record
        $licenseData = [
            'user_id' => $userId,
            'product_id' => $productId,
            'order_id' => $orderId,
            'subscription_id' => $subscriptionId,
            'license_key_hash' => $keyHash['hash'],
            'license_key_verification' => $keyHash['verification_hash'],
            'status' => 'active',
            'expires_at' => $expiresAt,
            'grace_until' => $graceUntil,
            'max_activations' => $maxActivations,
            'created_at' => current_time('mysql', 1)
        ];
        
        $licenseId = $this->licenses->create($licenseData);
        $licenseData['id'] = $licenseId;
        $licenseData['license_key_plain'] = $licenseKey->getValue(); // Only for email
        
        return $licenseData;
    }

    private function findLicenseByKey(LicenseKey $licenseKey): array
    {
        $keyHash = $this->keyService->hashLicenseKey($licenseKey->getValue());
        $license = $this->licenses->findByKeyHash($keyHash['hash']);
        
        if (!$license) {
            throw new LicenseNotFoundException(
                'License not found',
                'license_not_found',
                0,
                ['key_hash' => substr($keyHash['hash'], 0, 8) . '...']
            );
        }
        
        return $license;
    }

    private function validateLicenseForActivation(array $license, string $domain): void
    {
        $currentStatus = $this->getCurrentLicenseStatus($license);
        
        if ($currentStatus['status'] === 'expired') {
            throw new LicenseExpiredException(
                'License has expired',
                'license_expired',
                0,
                ['expires_at' => $license['expires_at']]
            );
        }
        
        if ($currentStatus['status'] === 'inactive') {
            throw new LicenseInactiveException(
                'License is inactive: ' . ($currentStatus['reason'] ?? 'Unknown reason'),
                'license_inactive',
                0,
                ['reason' => $currentStatus['reason']]
            );
        }
    }

    private function checkActivationLimits(int $licenseId, ?int $maxActivations): void
    {
        if ($maxActivations === null) {
            return; // Unlimited
        }
        
        $activeCount = $this->activations->countActiveActivations($licenseId);
        
        if ($activeCount >= $maxActivations) {
            throw new ActivationLimitExceededException(
                "Activation limit of {$maxActivations} exceeded",
                'activation_limit_exceeded',
                0,
                [
                    'max_activations' => $maxActivations,
                    'current_count' => $activeCount
                ]
            );
        }
    }

    private function getCurrentLicenseStatus(array $license): array
    {
        // Check expiration
        if ($license['expires_at'] && strtotime($license['expires_at']) < time()) {
            // Check grace period
            if ($license['grace_until'] && strtotime($license['grace_until']) >= time()) {
                return ['status' => 'grace', 'reason' => 'expired_in_grace'];
            }
            return ['status' => 'expired', 'reason' => 'license_expired'];
        }
        
        // Check subscription status
        if ($license['subscription_id']) {
            $subscription = wcs_get_subscription($license['subscription_id']);
            if ($subscription && !$subscription->has_status(['active', 'pending'])) {
                return ['status' => 'inactive', 'reason' => 'subscription_' . $subscription->get_status()];
            }
        }
        
        return ['status' => $license['status']];
    }

    private function isProductLicensed(int $productId): bool
    {
        return get_post_meta($productId, '_lsr_is_licensed', true) === 'yes';
    }

    private function isDomainActivated(int $licenseId, string $domain): bool
    {
        return $this->activations->isDomainActive($licenseId, $domain);
    }

    private function normalizeDomain(string $domain): string
    {
        $parsed = parse_url($domain);
        $host = $parsed['host'] ?? $domain;
        return strtolower(trim($host, '.'));
    }

    private function calculateRemainingActivations(?int $maxActivations, int $licenseId): ?int
    {
        if ($maxActivations === null) {
            return null; // Unlimited
        }
        
        $used = $this->activations->countActiveActivations($licenseId);
        return max(0, $maxActivations - $used);
    }

    private function maskLicenseKey(string $keyHash): string
    {
        return '****-****-****-' . strtoupper(substr($keyHash, -4));
    }

    private function getStatusMessage(string $status): string
    {
        $messages = [
            'active' => __('License is active', 'license-server'),
            'inactive' => __('License is inactive', 'license-server'),
            'expired' => __('License has expired', 'license-server'),
            'grace' => __('License is in grace period', 'license-server'),
        ];
        
        return $messages[$status] ?? __('Unknown status', 'license-server');
    }

    private function getIpSalt(): string
    {
        $salt = get_option('lsr_ip_hash_salt');
        if (!$salt) {
            $salt = bin2hex(random_bytes(16));
            update_option('lsr_ip_hash_salt', $salt);
        }
        return $salt;
    }

    private function sendLicenseNotification(array $license, \WC_Order $order): void
    {
        // TODO: Implement email notification with license key
        // This should be done via a proper event system
        do_action('lsr_license_generated', $license, $order);
    }
}