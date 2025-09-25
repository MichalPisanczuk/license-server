<?php
/**
 * Deinstalacja wtyczki – usuwanie tabel i opcji.
 *
 * Ten plik uruchamiany jest, gdy użytkownik całkowicie usuwa wtyczkę z WordPressa.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Usuń własne tabele z bazy (opcjonalne – w produkcji zastanów się nad konsekwencjami)
global $wpdb;
$licensesTable    = $wpdb->prefix . 'lsr_licenses';
$activationsTable = $wpdb->prefix . 'lsr_activations';
$releasesTable    = $wpdb->prefix . 'lsr_releases';

$wpdb->query("DROP TABLE IF EXISTS {$licensesTable}");
$wpdb->query("DROP TABLE IF EXISTS {$activationsTable}");
$wpdb->query("DROP TABLE IF EXISTS {$releasesTable}");

// Usuń opcje
delete_option('lsr_schema_version');
delete_option('lsr_signing_secret');
