<?php
namespace MyShop\LicenseServer\Domain\Services;

use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;

/**
 * Logika biznesowa dotycząca wydań wtyczek.
 */
class ReleaseService
{
    private ReleaseRepository $releases;

    public function __construct(ReleaseRepository $releases)
    {
        $this->releases = $releases;
    }

    /**
     * Pobierz najnowsze wydanie dla produktu i sluga.
     */
    public function getLatest(int $productId, string $slug): ?array
    {
        return $this->releases->getLatestRelease($productId, $slug);
    }
}
