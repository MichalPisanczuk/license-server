<?php
namespace MyShop\LicenseServer;

/**
 * Znormalizuj nazwę domeny lub adres URL. Usuwa 'www.' i sprowadza do małych liter.
 *
 * @param string $url URL lub domena
 * @return string
 */
function normalize_domain(string $url): string
{
    // Jeżeli nie ma schematu, dodaj http, żeby parse_url zadziałało.
    if (!str_contains($url, '://')) {
        $url = 'http://' . $url;
    }
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) {
        return strtolower(trim($url));
    }
    // Usuwamy prefiks www.
    $host = preg_replace('/^www\./i', '', $host);
    return strtolower($host);
}

/**
 * Wygeneruj losowy, unikatowy klucz licencji.
 *
 * @return string
 * @throws \Exception
 */
function generate_license_key(): string
{
    // 32 znakowy hex string (128-bitowy)
    return bin2hex(random_bytes(16));
}

/**
 * Oblicz hash IP klienta (np. dla prywatności). Używamy sha256.
 *
 * @param string $ip
 * @return string
 */
function hash_ip(string $ip): string
{
    return hash('sha256', $ip);
}
