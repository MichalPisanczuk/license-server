<?php
namespace MyShop\LicenseServer\Domain\DTO;

/**
 * Obiekt przenoszący dane licencji.
 */
class LicenseDTO
{
    public int $id;
    public int $user_id;
    public int $product_id;
    public ?int $order_id;
    public ?int $subscription_id;
    public string $license_key;
    public string $status;
    public ?string $expires_at;
    public ?string $grace_until;
    public ?int $max_activations;
    public string $created_at;
    public string $updated_at;

    public function __construct(array $data)
    {
        foreach ($data as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}
