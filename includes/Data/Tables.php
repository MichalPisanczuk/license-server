<?php
namespace MyShop\LicenseServer\Data;

/**
 * Definicje nazw i wersji tabel używanych w License Server.
 */
class Tables
{
    public const LICENSES    = 'lsr_licenses';
    public const ACTIVATIONS = 'lsr_activations';
    public const RELEASES    = 'lsr_releases';

    public static function fullName(string $table, ?\wpdb $db = null): string
    {
        global $wpdb;
        $db = $db ?: $wpdb;
        return $db->prefix . $table;
    }
}
