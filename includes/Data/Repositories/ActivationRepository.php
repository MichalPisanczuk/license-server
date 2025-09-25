<?php
namespace MyShop\LicenseServer\Data\Repositories;

use wpdb;

/**
 * Repozytorium obsługujące aktywacje licencji.
 */
class ActivationRepository
{
    /** @var string */
    private string $table;
    /** @var wpdb */
    private wpdb $db;

    public function __construct()
    {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table = $wpdb->prefix . 'lsr_activations';
    }

    /**
     * Dodaj lub zaktualizuj aktywację licencji dla domeny.
     *
     * Jeśli rekord dla (license_id, domain) istnieje, aktualizuje last_seen_at i ip_hash. Inaczej tworzy.
     *
     * @param int $licenseId
     * @param string $domain
     * @param string|null $ipHash
     * @return void
     */
    public function recordActivation(int $licenseId, string $domain, ?string $ipHash): void
    {
        // Sprawdź czy istnieje
        $exists = $this->db->get_row(
            $this->db->prepare("SELECT id FROM {$this->table} WHERE license_id = %d AND domain = %s LIMIT 1", $licenseId, $domain),
            ARRAY_A
        );
        if ($exists) {
            $this->db->update(
                $this->table,
                [
                    'ip_hash'     => $ipHash,
                    'last_seen_at'=> current_time('mysql', 1),
                ],
                ['id' => (int) $exists['id']]
            );
        } else {
            $this->db->insert(
                $this->table,
                [
                    'license_id'  => $licenseId,
                    'domain'      => $domain,
                    'ip_hash'     => $ipHash,
                    'activated_at'=> current_time('mysql', 1),
                    'last_seen_at'=> current_time('mysql', 1),
                ]
            );
        }
    }

    /**
     * Policz liczbę aktywacji dla danej licencji.
     *
     * @param int $licenseId
     * @return int
     */
    public function countActivations(int $licenseId): int
    {
        $count = $this->db->get_var(
            $this->db->prepare("SELECT COUNT(*) FROM {$this->table} WHERE license_id = %d", $licenseId)
        );
        return (int) $count;
    }
}
