<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Data\Repositories;

use MyShop\LicenseServer\Data\Contracts\LicenseRepositoryInterface;
use MyShop\LicenseServer\Domain\Exceptions\DatabaseException;

/**
 * Enhanced License Repository with comprehensive functionality.
 */
class EnhancedLicenseRepository implements LicenseRepositoryInterface
{
    private string $table;
    private \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'lsr_licenses';
    }

    public function create(array $data): int
    {
        try {
            $defaults = [
                'user_id' => 0,
                'product_id' => 0,
                'order_id' => null,
                'subscription_id' => null,
                'license_key_hash' => '',
                'license_key_verification' => '',
                'status' => 'active',
                'expires_at' => null,
                'grace_until' => null,
                'max_activations' => null,
                'notes' => null,
                'metadata' => null,
                'created_ip' => null,
                'last_validation_at' => null,
                'failed_attempts' => 0,
                'created_at' => current_time('mysql', 1),
                'updated_at' => current_time('mysql', 1)
            ];

            $licenseData = array_merge($defaults, $data);

            // JSON encode metadata if it's an array
            if (is_array($licenseData['metadata'])) {
                $licenseData['metadata'] = wp_json_encode($licenseData['metadata']);
            }

            $result = $this->db->insert($this->table, $licenseData);

            if ($result === false) {
                throw new DatabaseException(
                    'Failed to create license: ' . $this->db->last_error,
                    'license_create_failed',
                    0,
                    ['data' => $data]
                );
            }

            return (int) $this->db->insert_id;

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'License creation failed: ' . $e->getMessage(),
                'license_create_error',
                0,
                ['data' => $data],
                LOG_ERR,
                $e
            );
        }
    }

    public function findById(int $id): ?array
    {
        try {
            $license = $this->db->get_row(
                $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
                ARRAY_A
            );

            return $license ? $this->transformLicenseData($license) : null;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find license by ID: ' . $e->getMessage(),
                'license_find_error',
                0,
                ['id' => $id],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findByKeyHash(string $keyHash): ?array
    {
        try {
            $license = $this->db->get_row(
                $this->db->prepare(
                    "SELECT * FROM {$this->table} WHERE license_key_hash = %s LIMIT 1",
                    $keyHash
                ),
                ARRAY_A
            );

            return $license ? $this->transformLicenseData($license) : null;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find license by key hash: ' . $e->getMessage(),
                'license_find_by_hash_error',
                0,
                ['key_hash' => substr($keyHash, 0, 8) . '...'],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findByUser(int $userId): array
    {
        try {
            $licenses = $this->db->get_results(
                $this->db->prepare("SELECT * FROM {$this->table} WHERE user_id = %d ORDER BY created_at DESC", $userId),
                ARRAY_A
            );

            return array_map([$this, 'transformLicenseData'], $licenses ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find licenses by user: ' . $e->getMessage(),
                'license_find_by_user_error',
                0,
                ['user_id' => $userId],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findByOrderAndProduct(int $orderId, int $productId): ?array
    {
        try {
            $license = $this->db->get_row(
                $this->db->prepare(
                    "SELECT * FROM {$this->table} WHERE order_id = %d AND product_id = %d LIMIT 1",
                    $orderId,
                    $productId
                ),
                ARRAY_A
            );

            return $license ? $this->transformLicenseData($license) : null;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find license by order and product: ' . $e->getMessage(),
                'license_find_by_order_product_error',
                0,
                ['order_id' => $orderId, 'product_id' => $productId],
                LOG_WARNING,
                $e
            );
        }
    }

    public function existsByOrderAndProduct(int $orderId, int $productId): bool
    {
        try {
            $count = (int) $this->db->get_var(
                $this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->table} WHERE order_id = %d AND product_id = %d",
                    $orderId,
                    $productId
                )
            );

            return $count > 0;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to check license existence: ' . $e->getMessage(),
                'license_exists_check_error',
                0,
                ['order_id' => $orderId, 'product_id' => $productId],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findBySubscriptionId(int $subscriptionId): array
    {
        try {
            $licenses = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table} WHERE subscription_id = %d ORDER BY created_at DESC",
                    $subscriptionId
                ),
                ARRAY_A
            );

            return array_map([$this, 'transformLicenseData'], $licenses ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find licenses by subscription: ' . $e->getMessage(),
                'license_find_by_subscription_error',
                0,
                ['subscription_id' => $subscriptionId],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findExpiredLicenses(bool $includeGracePeriod = false): array
    {
        try {
            if ($includeGracePeriod) {
                $query = "SELECT * FROM {$this->table} 
                         WHERE (expires_at IS NOT NULL AND expires_at <= NOW()) 
                         OR (grace_until IS NOT NULL AND grace_until <= NOW())
                         ORDER BY expires_at ASC";
            } else {
                $query = "SELECT * FROM {$this->table} 
                         WHERE expires_at IS NOT NULL 
                         AND expires_at <= NOW() 
                         AND (grace_until IS NULL OR grace_until <= NOW())
                         ORDER BY expires_at ASC";
            }

            $licenses = $this->db->get_results($query, ARRAY_A);

            return array_map([$this, 'transformLicenseData'], $licenses ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find expired licenses: ' . $e->getMessage(),
                'license_find_expired_error',
                0,
                ['include_grace' => $includeGracePeriod],
                LOG_WARNING,
                $e
            );
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            if (empty($data)) {
                return false;
            }

            $data['updated_at'] = current_time('mysql', 1);

            // Handle metadata JSON encoding
            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $data['metadata'] = wp_json_encode($data['metadata']);
            }

            $result = $this->db->update(
                $this->table,
                $data,
                ['id' => $id],
                $this->getFieldFormats($data),
                ['%d']
            );

            if ($result === false) {
                throw new DatabaseException(
                    'Failed to update license: ' . $this->db->last_error,
                    'license_update_failed',
                    0,
                    ['id' => $id, 'data' => $data]
                );
            }

            return $result > 0;

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'License update failed: ' . $e->getMessage(),
                'license_update_error',
                0,
                ['id' => $id, 'data' => $data],
                LOG_ERR,
                $e
            );
        }
    }

    public function batchUpdateStatus(array $licenseIds, string $status): int
    {
        try {
            if (empty($licenseIds)) {
                return 0;
            }

            $placeholders = implode(',', array_fill(0, count($licenseIds), '%d'));
            $query = $this->db->prepare(
                "UPDATE {$this->table} 
                 SET status = %s, updated_at = NOW() 
                 WHERE id IN ({$placeholders})",
                array_merge([$status], $licenseIds)
            );

            $result = $this->db->query($query);

            if ($result === false) {
                throw new DatabaseException(
                    'Batch status update failed: ' . $this->db->last_error,
                    'license_batch_update_failed',
                    0,
                    ['license_ids' => $licenseIds, 'status' => $status]
                );
            }

            return (int) $result;

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'Batch status update error: ' . $e->getMessage(),
                'license_batch_update_error',
                0,
                ['license_ids' => $licenseIds, 'status' => $status],
                LOG_ERR,
                $e
            );
        }
    }

    public function delete(int $id): bool
    {
        try {
            $result = $this->db->delete(
                $this->table,
                ['id' => $id],
                ['%d']
            );

            return $result > 0;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'License deletion failed: ' . $e->getMessage(),
                'license_delete_error',
                0,
                ['id' => $id],
                LOG_ERR,
                $e
            );
        }
    }

    public function exists(int $id): bool
    {
        try {
            $count = (int) $this->db->get_var(
                $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE id = %d", $id)
            );

            return $count > 0;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'License existence check failed: ' . $e->getMessage(),
                'license_exists_error',
                0,
                ['id' => $id],
                LOG_WARNING,
                $e
            );
        }
    }

    public function count(array $criteria = []): int
    {
        try {
            $where = $this->buildWhereClauses($criteria);
            $query = "SELECT COUNT(*) FROM {$this->table}" . $where['clause'];

            if (!empty($where['values'])) {
                $query = $this->db->prepare($query, ...$where['values']);
            }

            return (int) $this->db->get_var($query);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'License count failed: ' . $e->getMessage(),
                'license_count_error',
                0,
                ['criteria' => $criteria],
                LOG_WARNING,
                $e
            );
        }
    }

    public function findWithPagination(
        int $limit = 20,
        int $offset = 0,
        array $criteria = [],
        array $orderBy = ['id' => 'DESC']
    ): array {
        try {
            $where = $this->buildWhereClauses($criteria);
            $orderClause = $this->buildOrderClause($orderBy);

            $query = "SELECT * FROM {$this->table}{$where['clause']}{$orderClause} LIMIT %d OFFSET %d";
            $values = array_merge($where['values'], [$limit, $offset]);

            $results = $this->db->get_results(
                $this->db->prepare($query, ...$values),
                ARRAY_A
            );

            return array_map([$this, 'transformLicenseData'], $results ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'License pagination query failed: ' . $e->getMessage(),
                'license_pagination_error',
                0,
                ['limit' => $limit, 'offset' => $offset, 'criteria' => $criteria],
                LOG_WARNING,
                $e
            );
        }
    }

    public function getStatistics(array $criteria = []): array
    {
        try {
            $where = $this->buildWhereClauses($criteria);
            $baseQuery = "FROM {$this->table}" . $where['clause'];
            $values = $where['values'];

            // Total licenses
            $total = (int) $this->db->get_var(
                $this->db->prepare("SELECT COUNT(*) {$baseQuery}", ...$values)
            );

            // Status breakdown
            $statusQuery = "SELECT status, COUNT(*) as count {$baseQuery} GROUP BY status";
            $statusResults = $this->db->get_results(
                $this->db->prepare($statusQuery, ...$values),
                ARRAY_A
            );

            $statusCounts = [];
            foreach ($statusResults ?: [] as $row) {
                $statusCounts[$row['status']] = (int) $row['count'];
            }

            // Expired licenses (including grace period)
            $expiredQuery = "SELECT COUNT(*) {$baseQuery} 
                           AND (expires_at IS NOT NULL AND expires_at <= NOW())";
            $expired = (int) $this->db->get_var(
                $this->db->prepare($expiredQuery, ...$values)
            );

            // Licenses in grace period
            $graceQuery = "SELECT COUNT(*) {$baseQuery} 
                         AND expires_at <= NOW() 
                         AND grace_until IS NOT NULL 
                         AND grace_until > NOW()";
            $inGrace = (int) $this->db->get_var(
                $this->db->prepare($graceQuery, ...$values)
            );

            // Recent activations (last 7 days)
            $recentQuery = "SELECT COUNT(*) {$baseQuery} 
                          AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
            $recentActivations = (int) $this->db->get_var(
                $this->db->prepare($recentQuery, ...$values)
            );

            return [
                'total' => $total,
                'by_status' => $statusCounts,
                'expired' => $expired,
                'in_grace_period' => $inGrace,
                'recent_activations' => $recentActivations,
                'active_percentage' => $total > 0 ? round(($statusCounts['active'] ?? 0) / $total * 100, 2) : 0
            ];

        } catch (\Exception $e) {
            throw new DatabaseException(
                'License statistics query failed: ' . $e->getMessage(),
                'license_stats_error',
                0,
                ['criteria' => $criteria],
                LOG_WARNING,
                $e
            );
        }
    }

    // Private helper methods...

    private function transformLicenseData(array $license): array
    {
        // Decode JSON metadata
        if (!empty($license['metadata'])) {
            $decoded = json_decode($license['metadata'], true);
            $license['metadata'] = is_array($decoded) ? $decoded : [];
        } else {
            $license['metadata'] = [];
        }

        // Convert numeric strings to integers
        $numericFields = ['id', 'user_id', 'product_id', 'order_id', 'subscription_id', 'max_activations', 'failed_attempts'];
        foreach ($numericFields as $field) {
            if (isset($license[$field]) && $license[$field] !== null) {
                $license[$field] = (int) $license[$field];
            }
        }

        return $license;
    }

    private function buildWhereClauses(array $criteria): array
    {
        if (empty($criteria)) {
            return ['clause' => '', 'values' => []];
        }

        $conditions = [];
        $values = [];

        foreach ($criteria as $field => $value) {
            if ($value === null) {
                $conditions[] = "{$field} IS NULL";
            } elseif (is_array($value)) {
                $placeholders = implode(',', array_fill(0, count($value), '%s'));
                $conditions[] = "{$field} IN ({$placeholders})";
                $values = array_merge($values, $value);
            } else {
                $conditions[] = "{$field} = %s";
                $values[] = $value;
            }
        }

        $clause = ' WHERE ' . implode(' AND ', $conditions);

        return ['clause' => $clause, 'values' => $values];
    }

    private function buildOrderClause(array $orderBy): string
    {
        if (empty($orderBy)) {
            return ' ORDER BY id DESC';
        }

        $orderParts = [];
        foreach ($orderBy as $field => $direction) {
            $direction = strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';
            $orderParts[] = "{$field} {$direction}";
        }

        return ' ORDER BY ' . implode(', ', $orderParts);
    }

    private function getFieldFormats(array $data): array
    {
        $formats = [];
        $stringFields = ['license_key_hash', 'license_key_verification', 'status', 'notes', 'metadata', 'created_ip'];
        $intFields = ['user_id', 'product_id', 'order_id', 'subscription_id', 'max_activations', 'failed_attempts'];
        $dateFields = ['expires_at', 'grace_until', 'last_validation_at', 'created_at', 'updated_at'];

        foreach ($data as $field => $value) {
            if (in_array($field, $stringFields)) {
                $formats[] = '%s';
            } elseif (in_array($field, $intFields)) {
                $formats[] = '%d';
            } elseif (in_array($field, $dateFields)) {
                $formats[] = '%s';
            } else {
                $formats[] = '%s'; // Default to string
            }
        }

        return $formats;
    }
}