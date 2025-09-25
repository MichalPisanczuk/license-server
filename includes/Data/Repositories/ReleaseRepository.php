<?php
namespace MyShop\LicenseServer\Data\Repositories;

use wpdb;

/**
 * Repozytorium obsługujące wydania (releases) wtyczek.
 */
class ReleaseRepository
{
    /** @var string */
    private string $table;
    /** @var wpdb */
    private wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'lsr_releases';
    }

    /**
     * Pobierz najnowsze wydanie dla produktu o danym slugu.
     * Sortowanie po released_at malejąco.
     *
     * @param int $productId
     * @param string $slug
     * @return array<string,mixed>|null
     */
    public function getLatestRelease(int $productId, string $slug): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE product_id = %d AND slug = %s ORDER BY released_at DESC LIMIT 1",
                $productId,
                $slug
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Pobierz wydanie po ID.
     *
     * @param int $id
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d LIMIT 1", $id),
            ARRAY_A
        );
        return $row ?: null;
    }

    /**
     * Dodaj nowe wydanie (ZIP) do bazy.
     *
     * @param array<string,mixed> $data
     * @return int
     */
    public function create(array $data): int
    {
        $defaults = [
            'product_id'    => 0,
            'slug'          => '',
            'version'       => '',
            'zip_path'      => '',
            'checksum_sha256' => '',
            'min_php'       => null,
            'min_wp'        => null,
            'changelog'     => null,
            'released_at'   => current_time('mysql', 1),
        ];
        $data = array_merge($defaults, $data);
        $this->db->insert($this->table, $data);
        return (int) $this->db->insert_id;
    }

    /**
     * Pobierz wszystkie wydania wtyczek.
     *
     * Zwraca tablicę tablic asocjacyjnych reprezentujących każdy rekord. Dane są
     * sortowane malejąco według daty wydania, aby najnowsze wydania były na górze listy.
     *
     * @return array<int,array<string,mixed>>
     */
    public function findAll(): array
    {
        return $this->db->get_results(
            "SELECT * FROM {$this->table} ORDER BY released_at DESC",
            ARRAY_A
        ) ?: [];
    }
}
