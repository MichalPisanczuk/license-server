<?php
namespace MyShop\LicenseServer\Admin\Screens;

/**
 * Ekran listy wydań w panelu admina.
 */
class ReleasesScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nie masz uprawnień do przeglądania tej strony.', 'license-server'));
        }
        $message = '';
        // Obsługa dodawania nowego wydania
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lsr_release_action']) && $_POST['lsr_release_action'] === 'add') {
            // Sprawdź nonce
            if (!isset($_POST['lsr_release_nonce']) || !check_admin_referer('lsr_add_release', 'lsr_release_nonce')) {
                $message = '<div class="notice notice-error"><p>' . esc_html__('Błędny nonce.', 'license-server') . '</p></div>';
            } else {
                $productId = isset($_POST['lsr_product_id']) ? (int) $_POST['lsr_product_id'] : 0;
                $slug      = isset($_POST['lsr_slug']) ? sanitize_title($_POST['lsr_slug']) : '';
                $version   = isset($_POST['lsr_version']) ? sanitize_text_field($_POST['lsr_version']) : '';
                $minPhp    = isset($_POST['lsr_min_php']) ? sanitize_text_field($_POST['lsr_min_php']) : '';
                $minWp     = isset($_POST['lsr_min_wp']) ? sanitize_text_field($_POST['lsr_min_wp']) : '';
                $changelog = isset($_POST['lsr_changelog']) ? wp_kses_post($_POST['lsr_changelog']) : '';
                // Waliduj podstawowe pola
                if (!$productId || !$slug || !$version || empty($_FILES['lsr_zip']['name'])) {
                    $message = '<div class="notice notice-error"><p>' . esc_html__('Wypełnij wszystkie wymagane pola.', 'license-server') . '</p></div>';
                } else {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                    $uploadedFile = $_FILES['lsr_zip'];
                    $uploadOverrides = ['test_form' => false];
                    $movefile = wp_handle_upload($uploadedFile, $uploadOverrides);
                    if ($movefile && !isset($movefile['error'])) {
                        // Przenieś plik do katalogu releases
                        $storageDir = LSR_DIR . 'storage/releases/';
                        if (!is_dir($storageDir)) {
                            wp_mkdir_p($storageDir);
                        }
                        $basename = basename($movefile['file']);
                        $target   = $storageDir . $basename;
                        // Upewnij się, że nazwa pliku jest unikalna
                        $i = 1;
                        $baseNoExt = pathinfo($basename, PATHINFO_FILENAME);
                        $ext = pathinfo($basename, PATHINFO_EXTENSION);
                        while (file_exists($target)) {
                            $basename = $baseNoExt . '-' . $i . '.' . $ext;
                            $target = $storageDir . $basename;
                            $i++;
                        }
                        if (!rename($movefile['file'], $target)) {
                            $message = '<div class="notice notice-error"><p>' . esc_html__('Nie można przenieść pliku do katalogu docelowego.', 'license-server') . '</p></div>';
                        } else {
                            // Oblicz sumę kontrolną SHA256
                            $checksum = hash_file('sha256', $target);
                            // Zapisz do bazy danych
                            $repo = \MyShop\LicenseServer\lsr(\MyShop\LicenseServer\Data\Repositories\ReleaseRepository::class);
                            if ($repo) {
                                $repo->create([
                                    'product_id'      => $productId,
                                    'slug'            => $slug,
                                    'version'         => $version,
                                    'zip_path'        => $basename,
                                    'checksum_sha256' => $checksum,
                                    'min_php'         => $minPhp ?: null,
                                    'min_wp'          => $minWp ?: null,
                                    'changelog'       => $changelog ?: null,
                                ]);
                                $message = '<div class="notice notice-success"><p>' . esc_html__('Wydanie zostało dodane.', 'license-server') . '</p></div>';
                            }
                        }
                    } else {
                        $errorMsg = isset($movefile['error']) ? $movefile['error'] : __('Błąd przesyłania pliku.', 'license-server');
                        $message = '<div class="notice notice-error"><p>' . esc_html($errorMsg) . '</p></div>';
                    }
                }
            }
        }
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wydania', 'license-server') . '</h1>';
        // Wyświetl komunikat, jeśli istnieje
        if ($message) {
            echo $message;
        }
        // Formularz dodawania wydania
        echo '<h2>' . esc_html__('Dodaj nowe wydanie', 'license-server') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data">';
        // Nonce
        wp_nonce_field('lsr_add_release', 'lsr_release_nonce');
        echo '<table class="form-table">';
        // Lista produktów z metą _lsr_is_licensed
        $products = wc_get_products([
            'limit'      => -1,
            'status'     => 'publish',
            'meta_key'   => '_lsr_is_licensed',
            'meta_value' => 'yes',
        ]);
        echo '<tr><th><label for="lsr_product_id">' . esc_html__('Produkt', 'license-server') . ' *</label></th><td>';
        echo '<select id="lsr_product_id" name="lsr_product_id" required>';
        echo '<option value="">' . esc_html__('Wybierz produkt', 'license-server') . '</option>';
        foreach ($products as $product) {
            echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name()) . '</option>';
        }
        echo '</select></td></tr>';
        // Slug
        echo '<tr><th><label for="lsr_slug">' . esc_html__('Slug wtyczki', 'license-server') . ' *</label></th><td>';
        echo '<input type="text" id="lsr_slug" name="lsr_slug" required class="regular-text" />';
        echo '<p class="description">' . esc_html__('Nazwa folderu wtyczki, np. moja-wtyczka.', 'license-server') . '</p>';
        echo '</td></tr>';
        // Version
        echo '<tr><th><label for="lsr_version">' . esc_html__('Wersja', 'license-server') . ' *</label></th><td>';
        echo '<input type="text" id="lsr_version" name="lsr_version" required class="regular-text" />';
        echo '</td></tr>';
        // ZIP file
        echo '<tr><th><label for="lsr_zip">' . esc_html__('Plik ZIP', 'license-server') . ' *</label></th><td>';
        echo '<input type="file" id="lsr_zip" name="lsr_zip" accept=".zip" required />';
        echo '</td></tr>';
        // Min PHP
        echo '<tr><th><label for="lsr_min_php">' . esc_html__('Minimalna wersja PHP', 'license-server') . '</label></th><td>';
        echo '<input type="text" id="lsr_min_php" name="lsr_min_php" class="regular-text" placeholder="7.4" />';
        echo '</td></tr>';
        // Min WP
        echo '<tr><th><label for="lsr_min_wp">' . esc_html__('Minimalna wersja WP', 'license-server') . '</label></th><td>';
        echo '<input type="text" id="lsr_min_wp" name="lsr_min_wp" class="regular-text" placeholder="6.0" />';
        echo '</td></tr>';
        // Changelog
        echo '<tr><th><label for="lsr_changelog">' . esc_html__('Changelog', 'license-server') . '</label></th><td>';
        echo '<textarea id="lsr_changelog" name="lsr_changelog" rows="5" class="large-text"></textarea>';
        echo '</td></tr>';
        echo '</table>';
        echo '<input type="hidden" name="lsr_release_action" value="add" />';
        submit_button(__('Dodaj wydanie', 'license-server'));
        echo '</form>';
        // Lista wydań
        echo '<h2>' . esc_html__('Lista wydań', 'license-server') . '</h2>';
        $repo = \MyShop\LicenseServer\lsr(\MyShop\LicenseServer\Data\Repositories\ReleaseRepository::class);
        $releases = $repo ? $repo->findAll() : [];
        if (empty($releases)) {
            echo '<p>' . esc_html__('Brak wydań.', 'license-server') . '</p>';
        } else {
            echo '<table class="widefat striped">';
            echo '<thead><tr>';
            echo '<th>' . esc_html__('ID', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Produkt', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Slug', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Wersja', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Plik', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Data wydania', 'license-server') . '</th>';
            echo '</tr></thead><tbody>';
            foreach ($releases as $release) {
                $product = wc_get_product($release['product_id']);
                $productName = $product ? $product->get_name() : ('#' . $release['product_id']);
                echo '<tr>';
                echo '<td>' . (int) $release['id'] . '</td>';
                echo '<td>' . esc_html($productName) . '</td>';
                echo '<td>' . esc_html($release['slug']) . '</td>';
                echo '<td>' . esc_html($release['version']) . '</td>';
                echo '<td>' . esc_html($release['zip_path']) . '</td>';
                echo '<td>' . esc_html($release['released_at']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }
}
