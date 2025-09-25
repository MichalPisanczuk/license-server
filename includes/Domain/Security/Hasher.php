<?php
namespace MyShop\LicenseServer\Domain\Security;

/**
 * Narzędzia do tworzenia i weryfikacji HMAC.
 */
class Hasher
{
    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?: (string) get_option('lsr_signing_secret');
    }

    public function make(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret);
    }

    public function verify(string $data, string $signature): bool
    {
        $expected = $this->make($data);
        return hash_equals($expected, $signature);
    }
}
