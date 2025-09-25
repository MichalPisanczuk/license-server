<?php
/**
 * Front-end template for rendering a user's licenses table.
 *
 * This template can be included by a shortcode or endpoint to display license details.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!is_user_logged_in()) {
    echo '<p>' . esc_html__('Musisz być zalogowany, aby zobaczyć swoje licencje.', 'license-server') . '</p>';
    return;
}

// Pobierz licencje użytkownika z repozytorium
$repo = MyShop\LicenseServer\lsr(MyShop\LicenseServer\Data\Repositories\LicenseRepository::class);
$licenses = $repo->findByUser(get_current_user_id());

if (empty($licenses)) {
    echo '<p>' . esc_html__('Nie masz żadnych licencji.', 'license-server') . '</p>';
    return;
}

echo '<table class="lsr-license-table"><thead><tr>';
echo '<th>' . esc_html__('Produkt', 'license-server') . '</th>';
echo '<th>' . esc_html__('Klucz licencji', 'license-server') . '</th>';
echo '<th>' . esc_html__('Status', 'license-server') . '</th>';
echo '<th>' . esc_html__('Wygasa', 'license-server') . '</th>';
echo '</tr></thead><tbody>';
foreach ($licenses as $license) {
    $product = wc_get_product($license['product_id']);
    echo '<tr>';
    echo '<td>' . esc_html($product ? $product->get_name() : ('#' . $license['product_id'])) . '</td>';
    echo '<td>' . esc_html($license['license_key']) . '</td>';
    echo '<td>' . esc_html($license['status']) . '</td>';
    echo '<td>' . esc_html($license['expires_at'] ?: __('Nigdy', 'license-server')) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';
