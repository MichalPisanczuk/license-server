<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Data\Contracts;

/**
 * Base Repository Interface
 * 
 * Defines common repository operations for all entities.
 */
interface RepositoryInterface
{
    /**
     * Create a new entity.
     *
     * @param array $data Entity data
     * @return int Created entity ID
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function create(array $data): int;

    /**
     * Find entity by ID.
     *
     * @param int $id Entity ID
     * @return array|null Entity data or null if not found
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function findById(int $id): ?array;

    /**
     * Update entity by ID.
     *
     * @param int $id Entity ID
     * @param array $data Update data
     * @return bool True if updated, false if not found
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function update(int $id, array $data): bool;

    /**
     * Delete entity by ID.
     *
     * @param int $id Entity ID
     * @return bool True if deleted, false if not found
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function delete(int $id): bool;

    /**
     * Check if entity exists by ID.
     *
     * @param int $id Entity ID
     * @return bool
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function exists(int $id): bool;

    /**
     * Get total count of entities.
     *
     * @param array $criteria Optional filter criteria
     * @return int
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function count(array $criteria = []): int;

    /**
     * Find entities with pagination.
     *
     * @param int $limit Limit
     * @param int $offset Offset
     * @param array $criteria Filter criteria
     * @param array $orderBy Order by clauses
     * @return array
     * @throws \MyShop\LicenseServer\Domain\Exceptions\DatabaseException
     */
    public function findWithPagination(
        int $limit = 20,
        int $offset = 0,
        array $criteria = [],
        array $orderBy = ['id' => 'DESC']
    ): array;
}

/**
 * License Repository Interface
 */
interface LicenseRepositoryInterface extends RepositoryInterface
{
    /**
     * Find license by key hash.
     *
     * @param string $keyHash License key hash
     * @return array|null
     */
    public function findByKeyHash(string $keyHash): ?array;

    /**
     * Find licenses by user ID.
     *
     * @param int $userId User ID
     * @return array
     */
    public function findByUser(int $userId): array;

    /**
     * Find license by order and product.
     *
     * @param int $orderId Order ID
     * @param int $productId Product ID
     * @return array|null
     */
    public function findByOrderAndProduct(int $orderId, int $productId): ?array;

    /**
     * Check if license exists for order and product.
     *
     * @param int $orderId Order ID
     * @param int $productId Product ID
     * @return bool
     */
    public function existsByOrderAndProduct(int $orderId, int $productId): bool;

    /**
     * Find licenses by subscription ID.
     *
     * @param int $subscriptionId Subscription ID
     * @return array
     */
    public function findBySubscriptionId(int $subscriptionId): array;

    /**
     * Find expired licenses.
     *
     * @param bool $includeGracePeriod Include licenses in grace period
     * @return array
     */
    public function findExpiredLicenses(bool $includeGracePeriod = false): array;

    /**
     * Update license status in batch.
     *
     * @param array $licenseIds License IDs
     * @param string $status New status
     * @return int Number of updated licenses
     */
    public function batchUpdateStatus(array $licenseIds, string $status): int;

    /**
     * Get license statistics.
     *
     * @param array $criteria Optional filter criteria
     * @return array
     */
    public function getStatistics(array $criteria = []): array;
}

/**
 * Activation Repository Interface
 */
interface ActivationRepositoryInterface extends RepositoryInterface
{
    /**
     * Record activation for license and domain.
     *
     * @param int $licenseId License ID
     * @param string $domain Domain
     * @param string|null $ipHash IP hash
     * @param string|null $userAgent User agent
     * @return void
     */
    public function recordActivation(
        int $licenseId,
        string $domain,
        ?string $ipHash = null,
        ?string $userAgent = null
    ): void;

    /**
     * Find activations by license ID.
     *
     * @param int $licenseId License ID
     * @param bool $activeOnly Only active activations
     * @return array
     */
    public function findByLicense(int $licenseId, bool $activeOnly = true): array;

    /**
     * Count active activations for license.
     *
     * @param int $licenseId License ID
     * @return int
     */
    public function countActiveActivations(int $licenseId): int;

    /**
     * Check if domain is active for license.
     *
     * @param int $licenseId License ID
     * @param string $domain Domain
     * @return bool
     */
    public function isDomainActive(int $licenseId, string $domain): bool;

    /**
     * Deactivate domain for license.
     *
     * @param int $licenseId License ID
     * @param string $domain Domain
     * @param string|null $reason Deactivation reason
     * @return bool
     */
    public function deactivateDomain(int $licenseId, string $domain, ?string $reason = null): bool;

    /**
     * Update last seen timestamp for activation.
     *
     * @param int $licenseId License ID
     * @param string $domain Domain
     * @param string|null $ipHash IP hash
     * @return void
     */
    public function updateLastSeen(int $licenseId, string $domain, ?string $ipHash = null): void;

    /**
     * Get activation statistics.
     *
     * @param array $criteria Filter criteria
     * @return array
     */
    public function getActivationStats(array $criteria = []): array;

    /**
     * Clean up old inactive activations.
     *
     * @param int $daysOld Days old threshold
     * @return int Number of cleaned up records
     */
    public function cleanupOldActivations(int $daysOld = 90): int;
}

/**
 * Release Repository Interface
 */
interface ReleaseRepositoryInterface extends RepositoryInterface
{
    /**
     * Find latest release for product and slug.
     *
     * @param int $productId Product ID
     * @param string $slug Plugin slug
     * @param bool $activeOnly Only active releases
     * @param bool $includeBeta Include beta releases
     * @return array|null
     */
    public function findLatestRelease(
        int $productId,
        string $slug,
        bool $activeOnly = true,
        bool $includeBeta = false
    ): ?array;

    /**
     * Find releases by product and slug.
     *
     * @param int $productId Product ID
     * @param string $slug Plugin slug
     * @param int $limit Limit
     * @param bool $activeOnly Only active releases
     * @return array
     */
    public function findByProductAndSlug(
        int $productId,
        string $slug,
        int $limit = 10,
        bool $activeOnly = true
    ): array;

    /**
     * Check if version exists for product/slug.
     *
     * @param int $productId Product ID
     * @param string $slug Plugin slug
     * @param string $version Version
     * @return bool
     */
    public function versionExists(int $productId, string $slug, string $version): bool;

    /**
     * Get release by file hash.
     *
     * @param string $fileHash File hash
     * @return array|null
     */
    public function findByFileHash(string $fileHash): ?array;

    /**
     * Update download count.
     *
     * @param int $releaseId Release ID
     * @param int $increment Increment amount
     * @return bool
     */
    public function incrementDownloadCount(int $releaseId, int $increment = 1): bool;

    /**
     * Get release statistics.
     *
     * @param array $criteria Filter criteria
     * @return array
     */
    public function getReleaseStats(array $criteria = []): array;

    /**
     * Clean up old beta releases.
     *
     * @param int $keepCount Number of beta releases to keep
     * @return int Number of cleaned up releases
     */
    public function cleanupOldBetaReleases(int $keepCount = 5): int;
}