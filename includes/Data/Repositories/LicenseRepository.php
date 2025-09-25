<?php
namespace MyShop\LicenseServer\Data\Repositories;

use wpdb;

/**
 * Repozytorium do obsługi tabeli licencji.
 */
class LicenseRepository
{
    /** @var string */
    private string $table;
    /** @var wpdb */
    private wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'lsr_licenses';
    }

    /**
     * Utwórz nową licencję.
     *
     * @param array<string,mixed> $data
     * @return int ID nowej licencji
     */
    public function create(array $data): int
    {
        $defaults = [
            'user_id'        => 0,
            'product_id'     => 0,
            'order_id'       => null,
            'subscription_id'=> null,
            'license_key'    => '',
            'status'         => 'active',
            'expires_at'     => null,
            'grace_until'    => null,
            'max_activations'=> null,
            'created_at'     => current_time('mysql', 1),
            'updated_at'     => current_time('mysql', 1),
        ];
        $data = array_merge($defaults, $data);
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id;
    }

    /**
     * Znajdź licencję po kluczu.
     *
     * @param string $licenseKey
     * @return array<string,mixed>|null
     */
    public function findByKey(string $licenseKey): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE license_key = %s LIMIT 1", $licenseKey),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Znajdź licencję powiązaną z danym subscription_id.
     *
     * @param int $subscriptionId
     * @return array<string,mixed>|null
     */
    public function findBySubscriptionId(int $subscriptionId): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE subscription_id = %d LIMIT 1", $subscriptionId),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Aktualizuj licencję.
     *
     * @param int $licenseId
     * @param array<string,mixed> $data
     * @return bool
     */
    public function update(int $licenseId, array $data): bool
    {
        if (empty($data)) {
            return false;
        }
        $data['updated_at'] = current_time('mysql', 1);
        $where = ['id' => $licenseId];
        return (bool) $this->db->update($this->table, $data, $where);
    }

    /**
     * Pobierz licencje użytkownika.
     *
     * @param int $userId
     * @return array<int,array<string,mixed>>
     */
    public function findByUser(int $userId): array
    {
        return $this->db->get_results(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE user_id = %d", $userId),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Pobierz wszystkie licencje.
     *
     * Funkcja zwraca wszystkie rekordy z tabeli licencji uporządkowane malejąco po ID.
     * Może być używana do wyświetlania listy licencji w panelu administratora.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(): array
    {
        return $this->db->get_results(
            "SELECT * FROM {$this->table} ORDER BY id DESC",
            ARRAY_A
        ) ?: [];
    }

    /**
     * Sprawdź, czy licencja dla danego produktu i zamówienia istnieje.
     *
     * @param int $orderId
     * @param int $productId
     * @return bool
     */
    public function existsByOrderAndProduct(int $orderId, int $productId): bool
    {
        $row = $this->db->get_var(
            $this->db->prepare(
                "SELECT id FROM {$this->table} WHERE order_id = %d AND product_id = %d LIMIT 1",
                $orderId,
                $productId
            )
        );
        return !empty($row);
    }
}
