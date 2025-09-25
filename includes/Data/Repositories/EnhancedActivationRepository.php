<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Data\Repositories;

use MyShop\LicenseServer\Data\Contracts\ActivationRepositoryInterface;
use MyShop\LicenseServer\Domain\Exceptions\DatabaseException;

/**
 * Enhanced Activation Repository with comprehensive activation management.
 */
class EnhancedActivationRepository implements ActivationRepositoryInterface
{
    private string $table;
    private \wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'lsr_activations';
    }

    public function create(array $data): int
    {
        try {
            $defaults = [
                'license_id' => 0,
                'domain' => '',
                'ip_hash' => null,
                'user_agent_hash' => null,
                'fingerprint_hash' => null,
                'activated_at' => current_time('mysql', 1),
                'last_seen_at' => current_time('mysql', 1),
                'validation_count' => 0,
                'failed_validations' => 0,
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_reason' => null,
                'metadata' => null
            ];

            $activationData = array_merge($defaults, $data);

            // JSON encode metadata if it's an array
            if (is_array($activationData['metadata'])) {
                $activationData['metadata'] = wp_json_encode($activationData['metadata']);
            }

            $result = $this->db->insert($this->table, $activationData);

            if ($result === false) {
                throw new DatabaseException(
                    'Failed to create activation: ' . $this->db->last_error,
                    'activation_create_failed',
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
                'Activation creation failed: ' . $e->getMessage(),
                'activation_create_error',
                0,
                ['data' => $data],
                LOG_ERR,
                $e
            );
        }
    }

    public function recordActivation(
        int $licenseId,
        string $domain,
        ?string $ipHash = null,
        ?string $userAgent = null
    ): void {
        try {
            // Check if activation already exists
            $existing = $this->db->get_row(
                $this->db->prepare(
                    "SELECT id, is_active FROM {$this->table} WHERE license_id = %d AND domain = %s LIMIT 1",
                    $licenseId,
                    $domain
                ),
                ARRAY_A
            );

            $userAgentHash = $userAgent ? hash('sha256', $userAgent) : null;

            if ($existing) {
                // Update existing activation
                $updateData = [
                    'ip_hash' => $ipHash,
                    'user_agent_hash' => $userAgentHash,
                    'last_seen_at' => current_time('mysql', 1),
                    'validation_count' => 'validation_count + 1',
                    'is_active' => true,
                    'deactivated_at' => null,
                    'deactivated_reason' => null
                ];

                // Special handling for validation_count increment
                $query = "UPDATE {$this->table} SET 
                         ip_hash = %s, 
                         user_agent_hash = %s, 
                         last_seen_at = %s,
                         validation_count = validation_count + 1,
                         is_active = 1,
                         deactivated_at = NULL,
                         deactivated_reason = NULL
                         WHERE id = %d";

                $result = $this->db->query(
                    $this->db->prepare(
                        $query,
                        $ipHash,
                        $userAgentHash,
                        current_time('mysql', 1),
                        $existing['id']
                    )
                );

                if ($result === false) {
                    throw new DatabaseException(
                        'Failed to update activation: ' . $this->db->last_error,
                        'activation_update_failed'
                    );
                }
            } else {
                // Create new activation
                $this->create([
                    'license_id' => $licenseId,
                    'domain' => $domain,
                    'ip_hash' => $ipHash,
                    'user_agent_hash' => $userAgentHash,
                    'validation_count' => 1
                ]);
            }

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'Recording activation failed: ' . $e->getMessage(),
                'activation_record_error',
                0,
                ['license_id' => $licenseId, 'domain' => $domain],
                LOG_ERR,
                $e
            );
        }
    }

    public function findByLicense(int $licenseId, bool $activeOnly = true): array
    {
        try {
            $whereClause = "WHERE license_id = %d";
            $params = [$licenseId];

            if ($activeOnly) {
                $whereClause .= " AND is_active = 1";
            }

            $activations = $this->db->get_results(
                $this->db->prepare(
                    "SELECT * FROM {$this->table} {$whereClause} ORDER BY last_seen_at DESC",
                    ...$params
                ),
                ARRAY_A
            );

            return array_map([$this, 'transformActivationData'], $activations ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find activations by license: ' . $e->getMessage(),
                'activation_find_by_license_error',
                0,
                ['license_id' => $licenseId, 'active_only' => $activeOnly],
                LOG_WARNING,
                $e
            );
        }
    }

    public function countActiveActivations(int $licenseId): int
    {
        try {
            return (int) $this->db->get_var(
                $this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND is_active = 1",
                    $licenseId
                )
            );

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to count active activations: ' . $e->getMessage(),
                'activation_count_error',
                0,
                ['license_id' => $licenseId],
                LOG_WARNING,
                $e
            );
        }
    }

    public function isDomainActive(int $licenseId, string $domain): bool
    {
        try {
            $count = (int) $this->db->get_var(
                $this->db->prepare(
                    "SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d AND domain = %s AND is_active = 1",
                    $licenseId,
                    $domain
                )
            );

            return $count > 0;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to check domain activation status: ' . $e->getMessage(),
                'activation_domain_check_error',
                0,
                ['license_id' => $licenseId, 'domain' => $domain],
                LOG_WARNING,
                $e
            );
        }
    }

    public function deactivateDomain(int $licenseId, string $domain, ?string $reason = null): bool
    {
        try {
            $result = $this->db->update(
                $this->table,
                [
                    'is_active' => false,
                    'deactivated_at' => current_time('mysql', 1),
                    'deactivated_reason' => $reason
                ],
                [
                    'license_id' => $licenseId,
                    'domain' => $domain
                ],
                ['%d', '%s', '%s'],
                ['%d', '%s']
            );

            if ($result === false) {
                throw new DatabaseException(
                    'Failed to deactivate domain: ' . $this->db->last_error,
                    'activation_deactivate_failed',
                    0,
                    ['license_id' => $licenseId, 'domain' => $domain]
                );
            }

            return $result > 0;

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'Domain deactivation failed: ' . $e->getMessage(),
                'activation_deactivate_error',
                0,
                ['license_id' => $licenseId, 'domain' => $domain, 'reason' => $reason],
                LOG_ERR,
                $e
            );
        }
    }

    public function updateLastSeen(int $licenseId, string $domain, ?string $ipHash = null): void
    {
        try {
            $updateData = [
                'last_seen_at' => current_time('mysql', 1)
            ];

            if ($ipHash !== null) {
                $updateData['ip_hash'] = $ipHash;
            }

            // Also increment validation count
            $query = "UPDATE {$this->table} SET 
                     last_seen_at = %s" . 
                     ($ipHash ? ", ip_hash = %s" : "") . ", 
                     validation_count = validation_count + 1
                     WHERE license_id = %d AND domain = %s AND is_active = 1";

            $params = [$updateData['last_seen_at']];
            if ($ipHash) {
                $params[] = $ipHash;
            }
            $params[] = $licenseId;
            $params[] = $domain;

            $result = $this->db->query(
                $this->db->prepare($query, ...$params)
            );

            if ($result === false) {
                throw new DatabaseException(
                    'Failed to update last seen: ' . $this->db->last_error,
                    'activation_update_last_seen_failed'
                );
            }

        } catch (\Exception $e) {
            if ($e instanceof DatabaseException) {
                throw $e;
            }
            throw new DatabaseException(
                'Update last seen failed: ' . $e->getMessage(),
                'activation_last_seen_error',
                0,
                ['license_id' => $licenseId, 'domain' => $domain],
                LOG_WARNING,
                $e
            );
        }
    }

    public function getActivationStats(array $criteria = []): array
    {
        try {
            $where = $this->buildWhereClauses($criteria);
            $baseQuery = "FROM {$this->table}" . $where['clause'];
            $values = $where['values'];

            // Total activations
            $total = (int) $this->db->get_var(
                $this->db->prepare("SELECT COUNT(*) {$baseQuery}", ...$values)
            );

            // Active activations
            $activeQuery = "SELECT COUNT(*) {$baseQuery}" . 
                          ($where['clause'] ? " AND is_active = 1" : " WHERE is_active = 1");
            $active = (int) $this->db->get_var(
                $this->db->prepare($activeQuery, ...$values)
            );

            // Recent activations (last 24 hours)
            $recentQuery = "SELECT COUNT(*) {$baseQuery}" .
                          ($where['clause'] ? " AND activated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)" : 
                                            " WHERE activated_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $recent = (int) $this->db->get_var(
                $this->db->prepare($recentQuery, ...$values)
            );

            // Average validations per activation
            $avgValidationsQuery = "SELECT AVG(validation_count) {$baseQuery}";
            $avgValidations = round((float) $this->db->get_var(
                $this->db->prepare($avgValidationsQuery, ...$values)
            ), 2);

            // Top domains by activation count
            $topDomainsQuery = "SELECT domain, COUNT(*) as count {$baseQuery} 
                              GROUP BY domain ORDER BY count DESC LIMIT 10";
            $topDomains = $this->db->get_results(
                $this->db->prepare($topDomainsQuery, ...$values),
                ARRAY_A
            );

            return [
                'total' => $total,
                'active' => $active,
                'inactive' => $total - $active,
                'recent_24h' => $recent,
                'avg_validations' => $avgValidations,
                'top_domains' => $topDomains ?: [],
                'active_percentage' => $total > 0 ? round($active / $total * 100, 2) : 0
            ];

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Activation statistics query failed: ' . $e->getMessage(),
                'activation_stats_error',
                0,
                ['criteria' => $criteria],
                LOG_WARNING,
                $e
            );
        }
    }

    public function cleanupOldActivations(int $daysOld = 90): int
    {
        try {
            $result = $this->db->query(
                $this->db->prepare(
                    "DELETE FROM {$this->table} 
                     WHERE is_active = 0 
                     AND deactivated_at IS NOT NULL 
                     AND deactivated_at <= DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $daysOld
                )
            );

            return (int) $result;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Activation cleanup failed: ' . $e->getMessage(),
                'activation_cleanup_error',
                0,
                ['days_old' => $daysOld],
                LOG_ERR,
                $e
            );
        }
    }

    // Base interface methods...

    public function findById(int $id): ?array
    {
        try {
            $activation = $this->db->get_row(
                $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
                ARRAY_A
            );

            return $activation ? $this->transformActivationData($activation) : null;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Failed to find activation by ID: ' . $e->getMessage(),
                'activation_find_by_id_error',
                0,
                ['id' => $id],
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

            return $result > 0;

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Activation update failed: ' . $e->getMessage(),
                'activation_update_error',
                0,
                ['id' => $id, 'data' => $data],
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
                'Activation deletion failed: ' . $e->getMessage(),
                'activation_delete_error',
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
                'Activation existence check failed: ' . $e->getMessage(),
                'activation_exists_error',
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
                'Activation count failed: ' . $e->getMessage(),
                'activation_count_error',
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

            return array_map([$this, 'transformActivationData'], $results ?: []);

        } catch (\Exception $e) {
            throw new DatabaseException(
                'Activation pagination query failed: ' . $e->getMessage(),
                'activation_pagination_error',
                0,
                ['limit' => $limit, 'offset' => $offset, 'criteria' => $criteria],
                LOG_WARNING,
                $e
            );
        }
    }

    // Private helper methods...

    private function transformActivationData(array $activation): array
    {
        // Decode JSON metadata
        if (!empty($activation['metadata'])) {
            $decoded = json_decode($activation['metadata'], true);
            $activation['metadata'] = is_array($decoded) ? $decoded : [];
        } else {
            $activation['metadata'] = [];
        }

        // Convert numeric strings to integers
        $numericFields = ['id', 'license_id', 'validation_count', 'failed_validations'];
        foreach ($numericFields as $field) {
            if (isset($activation[$field])) {
                $activation[$field] = (int) $activation[$field];
            }
        }

        // Convert boolean fields
        $activation['is_active'] = (bool) $activation['is_active'];

        return $activation;
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

        return ['clause' => ' WHERE ' . implode(' AND ', $conditions), 'values' => $values];
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
        $stringFields = ['domain', 'ip_hash', 'user_agent_hash', 'fingerprint_hash', 'deactivated_reason', 'metadata'];
        $intFields = ['license_id', 'validation_count', 'failed_validations'];
        $dateFields = ['activated_at', 'last_seen_at', 'deactivated_at'];
        $boolFields = ['is_active'];

        foreach ($data as $field => $value) {
            if (in_array($field, $stringFields)) {
                $formats[] = '%s';
            } elseif (in_array($field, $intFields)) {
                $formats[] = '%d';
            } elseif (in_array($field, $dateFields)) {
                $formats[] = '%s';
            } elseif (in_array($field, $boolFields)) {
                $formats[] = '%d';
            } else {
                $formats[] = '%s'; // Default to string
            }
        }

        return $formats;
    }
}