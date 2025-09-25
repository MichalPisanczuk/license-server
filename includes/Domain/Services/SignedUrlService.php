<?php
namespace MyShop\LicenseServer\Domain\Services;

/**
 * Serwis odpowiedzialny za generowanie i weryfikację podpisanych linków do pobierania plików.
 */
class SignedUrlService
{
    /**
     * Zwrotny czas wygaśnięcia w sekundach (domyślnie 300 sekund).
     *
     * @var int
     */
    private int $ttl;

    public function __construct(int $ttl = 300)
    {
        $this->ttl = $ttl;
    }

    /**
     * Wygeneruj podpisany URL do pobrania pliku.
     *
     * @param int $licenseId
     * @param int $releaseId
     * @return array{url:string,expires:int}
     */
    public function generate(int $licenseId, int $releaseId): array
    {
        $expires = time() + $this->ttl;
        $secret  = $this->getSecret();
        $data    = $licenseId . '|' . $releaseId . '|' . $expires;
        $sig     = hash_hmac('sha256', $data, $secret);
        $url     = add_query_arg([
            'license_id' => $licenseId,
            'release_id' => $releaseId,
            'expires'    => $expires,
            'sig'        => $sig,
        ], rest_url('myshop/v1/updates/download'));
        return ['url' => $url, 'expires' => $expires];
    }

    /**
     * Zweryfikuj podpisany URL.
     *
     * @param int $licenseId
     * @param int $releaseId
     * @param int $expires
     * @param string $signature
     * @return bool
     */
    public function verify(int $licenseId, int $releaseId, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false;
        }
        $secret = $this->getSecret();
        $data   = $licenseId . '|' . $releaseId . '|' . $expires;
        $expected = hash_hmac('sha256', $data, $secret);
        // Użyj bezpiecznego porównania
        return hash_equals($expected, $signature);
    }

    /**
     * Wyczyść wygasłe linki. W tym prostym wydaniu nie musimy nic robić – weryfikacja sprawdza timestamp.
     */
    public function cleanupExpired(): void
    {
        // Brak trwałych wpisów – wszystko weryfikujemy dynamicznie.
    }

    /**
     * Pobierz lub wygeneruj sekret używany do podpisywania linków.
     *
     * @return string
     */
    private function getSecret(): string
    {
        $secret = get_option('lsr_signing_secret');
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            update_option('lsr_signing_secret', $secret, false);
        }
        return $secret;
    }
}
