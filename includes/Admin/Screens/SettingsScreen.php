<?php
namespace MyShop\LicenseServer\Admin\Screens;

/**
 * Ekran ustawień globalnych w panelu admina.
 */
class SettingsScreen
{
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Nie masz uprawnień do przeglądania tej strony.', 'license-server'));
        }
        $message = '';
        // Obsługa zapisu ustawień
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Sprawdź nonce
            if (isset($_POST['lsr_settings_nonce']) && check_admin_referer('lsr_save_settings', 'lsr_settings_nonce')) {
                $ttl = isset($_POST['lsr_signed_url_ttl']) ? (int) $_POST['lsr_signed_url_ttl'] : 300;
                if ($ttl < 60) {
                    $ttl = 60;
                }
                $domains = isset($_POST['lsr_developer_domains']) ? trim((string) wp_unslash($_POST['lsr_developer_domains'])) : '';
                update_option('lsr_signed_url_ttl', $ttl);
                update_option('lsr_developer_domains', $domains);
                $message = '<div class="notice notice-success"><p>' . esc_html__('Ustawienia zapisane.', 'license-server') . '</p></div>';
            } else {
                $message = '<div class="notice notice-error"><p>' . esc_html__('Błędny nonce.', 'license-server') . '</p></div>';
            }
        }
        // Pobierz bieżące ustawienia
        $currentTtl = (int) get_option('lsr_signed_url_ttl', 300);
        if ($currentTtl < 60) {
            $currentTtl = 60;
        }
        $currentDomains = (string) get_option('lsr_developer_domains', "localhost\nlocal\ntest");
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Ustawienia License Server', 'license-server') . '</h1>';
        if ($message) {
            echo $message;
        }
        echo '<form method="post">';
        wp_nonce_field('lsr_save_settings', 'lsr_settings_nonce');
        echo '<table class="form-table">';
        // TTL
        echo '<tr><th scope="row"><label for="lsr_signed_url_ttl">' . esc_html__('Czas ważności podpisanego linku (sekundy)', 'license-server') . '</label></th>';
        echo '<td><input name="lsr_signed_url_ttl" id="lsr_signed_url_ttl" type="number" min="60" step="1" value="' . esc_attr($currentTtl) . '" class="small-text" />';
        echo '<p class="description">' . esc_html__('Określ, jak długo (w sekundach) link do pobrania jest ważny. Minimalnie 60 sekund.', 'license-server') . '</p>';
        echo '</td></tr>';
        // Developer domains
        echo '<tr><th scope="row"><label for="lsr_developer_domains">' . esc_html__('Domeny developerskie', 'license-server') . '</label></th>';
        echo '<td><textarea name="lsr_developer_domains" id="lsr_developer_domains" rows="5" class="large-text">' . esc_textarea($currentDomains) . '</textarea>';
        echo '<p class="description">' . esc_html__('Jedna domena na linię lub oddzielona przecinkami. Licencja nie będzie liczyła aktywacji dla tych domen (np. localhost, *.local, *.test).', 'license-server') . '</p>';
        echo '</td></tr>';
        echo '</table>';
        submit_button(__('Zapisz zmiany', 'license-server'));
        echo '</form>';
        echo '</div>';
    }
}
