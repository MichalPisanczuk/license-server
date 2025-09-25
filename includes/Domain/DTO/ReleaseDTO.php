<?php
namespace MyShop\LicenseServer\Domain\DTO;

/**
 * Obiekt przenoszący dane wydania wtyczki.
 */
class ReleaseDTO
{
    public int $id;
    public int $product_id;
    public string $slug;
    public string $version;
    public string $zip_path;
    public string $checksum_sha256;
    public ?string $min_php;
    public ?string $min_wp;
    public ?string $changelog;
    public string $released_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
