<?php
namespace MyShop\LicenseServer\Http;

use MyShop\LicenseServer\Domain\Services\SignedUrlService;
use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;
use function MyShop\LicenseServer\lsr;

/**
 * Handler odpowiedzialny za streamowanie plików ZIP po weryfikacji podpisu.
 */
class DownloadHandler
{
    public function handle(int $licenseId, int $releaseId, int $expires, string $sig)
    {
        /** @var SignedUrlService $signed */
        $signed = lsr(SignedUrlService::class);
        if (!$signed->verify($licenseId, $releaseId, $expires, $sig)) {
            wp_die(__('Podpis niepoprawny lub link wygasł.', 'license-server'), __('Błąd pobierania', 'license-server'), 403);
        }
        /** @var LicenseRepository $licenses */
        $licenses = lsr(LicenseRepository::class);
        /** @var ReleaseRepository $releases */
        $releases = lsr(ReleaseRepository::class);
        $license = $licenses->findByKey($this->getLicenseKeyById($licenseId));
        $release = $releases->findById($releaseId);
        if (!$license || !$release) {
            wp_die(__('Nie znaleziono pliku.', 'license-server'), __('Błąd pobierania', 'license-server'), 404);
        }
        // Przykład weryfikacji produktu (patrz UpdateController)
        if ((int) $license['product_id'] !== (int) $release['product_id']) {
            wp_die(__('Niezgodność produktu.', 'license-server'), __('Błąd pobierania', 'license-server'), 403);
        }
        $path = LSR_DIR . 'storage/releases/' . ltrim($release['zip_path'], '/');
        if (!file_exists($path)) {
            wp_die(__('Plik nie istnieje.', 'license-server'), __('Błąd pobierania', 'license-server'), 404);
        }
        // Streamuj plik
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        readfile($path);
        exit;
    }

    /**
     * Helper: pobierz klucz licencji po ID.
     */
    private function getLicenseKeyById(int $licenseId): ?string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_licenses';
        $key   = $wpdb->get_var($wpdb->prepare("SELECT license_key FROM {$table} WHERE id = %d", $licenseId));
        return $key ?: null;
    }
}
