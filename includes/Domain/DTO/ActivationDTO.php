<?php
namespace MyShop\LicenseServer\Domain\DTO;

/**
 * Obiekt przenoszący dane aktywacji licencji.
 */
class ActivationDTO
{
    public int $id;
    public int $license_id;
    public string $domain;
    public ?string $ip_hash;
    public string $activated_at;
    public string $last_seen_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
