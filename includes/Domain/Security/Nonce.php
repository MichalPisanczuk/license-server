<?php
namespace MyShop\LicenseServer\Domain\Security;

/**
 * Prosta klasa do generowania i weryfikacji tokenów jednorazowych (nonce).
 */
class Nonce
{
    public static function create(string $action): string
    {
        return wp_create_nonce($action);
    }

    public static function verify(string $nonce, string $action): bool
    {
        return wp_verify_nonce($nonce, $action) === 1;
    }
}
