<?php
namespace MyShop\LicenseServer\Admin;

/**
 * Fixed Admin Menu Registration
 * 
 * @package MyShop\LicenseServer
 */
class Menu
{
    /**
     * Initialize menu registration
     */
    public static function init(): void
    {
        // Rejestruj menu później, z priorytetem 99
        add_action('admin_menu', [self::class, 'registerMenu'], 99);
        
        // Dodaj dodatkowe sprawdzenie uprawnień
        add_action('admin_init', [self::class, 'checkCapabilities']);
    }

    /**
     * Register admin menu items
     */
    public static function registerMenu(): void
    {
        // Debug: sprawdź czy użytkownik ma uprawnienia
        if (!current_user_can('manage_options')) {
            error_log('[License Server] User does not have manage_options capability');
            return;
        }

        // Główne menu License Server
        $main_page = add_menu_page(
            __('License Server', 'license-server'),
            __('License Server', 'license-server'),
            'manage_options',
            'lsr-licenses',
            [self::class, 'renderLicensesPage'],
            'dashicons-admin-network',
            58
        );
        
        // Debug: log if menu was added
        if ($main_page) {
            error_log('[License Server] Main menu added successfully: ' . $main_page);
        } else {
            error_log('[License Server] Failed to add main menu');
        }

        // Podmenu - Licencje
        add_submenu_page(
            'lsr-licenses',
            __('Licencje', 'license-server'),
            __('Licencje', 'license-server'),
            'manage_options',
            'lsr-licenses',
            [self::class, 'renderLicensesPage']
        );

        // Podmenu - Wydania
        add_submenu_page(
            'lsr-licenses',
            __('Wydania', 'license-server'),
            __('Wydania', 'license-server'),
            'manage_options',
            'lsr-releases',
            [self::class, 'renderReleasesPage']
        );

        // Podmenu - Ustawienia
        add_submenu_page(
            'lsr-licenses',
            __('Ustawienia', 'license-server'),
            __('Ustawienia', 'license-server'),
            'manage_options',
            'lsr-settings',
            [self::class, 'renderSettingsPage']
        );

        // Podmenu - Aktywacje
        add_submenu_page(
            'lsr-licenses',
            __('Aktywacje', 'license-server'),
            __('Aktywacje', 'license-server'),
            'manage_options',
            'lsr-activations',
            [self::class, 'renderActivationsPage']
        );

        // Debug menu - tylko dla super admina
        if (is_super_admin()) {
            add_submenu_page(
                'lsr-licenses',
                __('Debug', 'license-server'),
                __('Debug', 'license-server'),
                'manage_options',
                'lsr-debug',
                [self::class, 'renderDebugPage']
            );
        }
    }

    /**
     * Check and fix capabilities
     */
    public static function checkCapabilities(): void
    {
        // Upewnij się, że administrator ma wszystkie potrzebne uprawnienia
        $role = get_role('administrator');
        if ($role && !$role->has_cap('manage_options')) {
            $role->add_cap('manage_options');
            error_log('[License Server] Added manage_options capability to administrator role');
        }
    }

    /**
     * Render licenses page with error handling
     */
    public static function renderLicensesPage(): void
    {
        // Sprawdź uprawnienia jeszcze raz
        if (!current_user_can('manage_options')) {
            wp_die(
                esc_html__('Nie masz uprawnień dostępu do tej strony.', 'license-server'),
                esc_html__('Brak uprawnień', 'license-server'),
                ['response' => 403]
            );
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Licencje', 'license-server') . '</h1>';
        
        // Spróbuj załadować właściwy screen jeśli istnieje
        if (class_exists('\MyShop\LicenseServer\Admin\Screens\LicensesScreen')) {
            try {
                \MyShop\LicenseServer\Admin\Screens\LicensesScreen::render();
            } catch (\Exception $e) {
                echo '<div class="notice notice-error"><p>';
                echo esc_html__('Błąd ładowania ekranu licencji: ', 'license-server');
                echo esc_html($e->getMessage());
                echo '</p></div>';
                
                // Pokaż podstawową listę
                self::renderBasicLicensesList();
            }
        } else {
            // Fallback - podstawowa lista
            self::renderBasicLicensesList();
        }
        
        echo '</div>';
    }

    /**
     * Render basic licenses list as fallback
     */
    private static function renderBasicLicensesList(): void
    {
        global $wpdb;
        
        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Wyświetlam uproszczoną listę licencji (tryb awaryjny)', 'license-server');
        echo '</p></div>';
        
        // Sprawdź czy tabela istnieje
        $table_name = $wpdb->prefix . 'lsr_licenses';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('Tabela licencji nie istnieje! Proszę zreaktywować wtyczkę.', 'license-server');
            echo '</p></div>';
            return;
        }
        
        // Pobierz licencje
        $licenses = $wpdb->get_results("
            SELECT l.*, u.user_email, u.display_name 
            FROM {$table_name} l
            LEFT JOIN {$wpdb->users} u ON l.user_id = u.ID
            ORDER BY l.created_at DESC
            LIMIT 50
        ");
        
        if (empty($licenses)) {
            echo '<p>' . esc_html__('Brak licencji w systemie.', 'license-server') . '</p>';
            return;
        }
        
        // Wyświetl tabelę
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . esc_html__('Klucz licencji', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Użytkownik', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Produkt', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Status', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Utworzono', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Wygasa', 'license-server') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($licenses as $license) {
            $product = wc_get_product($license->product_id);
            $product_name = $product ? $product->get_name() : 'ID: ' . $license->product_id;
            
            echo '<tr>';
            echo '<td>' . esc_html($license->id) . '</td>';
            echo '<td><code>' . esc_html($license->license_key) . '</code></td>';
            echo '<td>' . esc_html($license->display_name ?: $license->user_email ?: '-') . '</td>';
            echo '<td>' . esc_html($product_name) . '</td>';
            echo '<td><span class="license-status license-status-' . esc_attr($license->status) . '">' . 
                 esc_html($license->status) . '</span></td>';
            echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($license->created_at))) . '</td>';
            echo '<td>' . esc_html($license->expires_at ? 
                 date_i18n(get_option('date_format'), strtotime($license->expires_at)) : '-') . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        // CSS dla statusów
        echo '<style>
            .license-status { padding: 3px 8px; border-radius: 3px; font-size: 12px; }
            .license-status-active { background: #d4edda; color: #155724; }
            .license-status-expired { background: #f8d7da; color: #721c24; }
            .license-status-suspended { background: #fff3cd; color: #856404; }
            .license-status-revoked { background: #d6d8db; color: #383d41; }
        </style>';
    }

    /**
     * Render releases page
     */
    public static function renderReleasesPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Brak uprawnień dostępu do tej strony.', 'license-server'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Wydania', 'license-server') . '</h1>';
        
        if (class_exists('\MyShop\LicenseServer\Admin\Screens\ReleasesScreen')) {
            \MyShop\LicenseServer\Admin\Screens\ReleasesScreen::render();
        } else {
            echo '<p>' . esc_html__('Ekran wydań nie jest jeszcze zaimplementowany.', 'license-server') . '</p>';
        }
        
        echo '</div>';
    }

    /**
     * Render settings page
     */
    public static function renderSettingsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Brak uprawnień dostępu do tej strony.', 'license-server'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Ustawienia License Server', 'license-server') . '</h1>';
        
        if (class_exists('\MyShop\LicenseServer\Admin\Screens\SettingsScreen')) {
            \MyShop\LicenseServer\Admin\Screens\SettingsScreen::render();
        } else {
            // Podstawowy formularz ustawień
            echo '<form method="post" action="options.php">';
            settings_fields('lsr_settings');
            do_settings_sections('lsr_settings');
            submit_button();
            echo '</form>';
        }
        
        echo '</div>';
    }

    /**
     * Render activations page
     */
    public static function renderActivationsPage(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Brak uprawnień dostępu do tej strony.', 'license-server'));
        }
        
        global $wpdb;
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Aktywacje', 'license-server') . '</h1>';
        
        $table_name = $wpdb->prefix . 'lsr_activations';
        $activations = $wpdb->get_results("
            SELECT a.*, l.license_key 
            FROM {$table_name} a
            JOIN {$wpdb->prefix}lsr_licenses l ON a.license_id = l.id
            ORDER BY a.activated_at DESC
            LIMIT 100
        ");
        
        if (empty($activations)) {
            echo '<p>' . esc_html__('Brak aktywacji.', 'license-server') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>ID</th>';
            echo '<th>' . esc_html__('Licencja', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Domena', 'license-server') . '</th>';
            echo '<th>' . esc_html__('IP', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Aktywowano', 'license-server') . '</th>';
            echo '<th>' . esc_html__('Ostatnia weryfikacja', 'license-server') . '</th>';
            echo '</tr></thead>';
            echo '<tbody>';
            
            foreach ($activations as $activation) {
                echo '<tr>';
                echo '<td>' . esc_html($activation->id) . '</td>';
                echo '<td><code>' . esc_html(substr($activation->license_key, 0, 8)) . '...</code></td>';
                echo '<td>' . esc_html($activation->domain) . '</td>';
                echo '<td><code>' . esc_html($activation->ip_hash) . '</code></td>';
                echo '<td>' . esc_html(date_i18n(get_option('date_format'), strtotime($activation->activated_at))) . '</td>';
                echo '<td>' . esc_html($activation->last_check_at ? 
                     date_i18n(get_option('date_format'), strtotime($activation->last_check_at)) : '-') . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
        
        echo '</div>';
    }

    /**
     * Render debug page for troubleshooting
     */
    public static function renderDebugPage(): void
    {
        if (!is_super_admin()) {
            wp_die(__('Tylko super admin ma dostęp do tej strony.', 'license-server'));
        }
        
        global $wpdb, $wp_version;
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Debug License Server', 'license-server') . '</h1>';
        
        // System info
        echo '<h2>System Info</h2>';
        echo '<table class="widefat">';
        echo '<tr><td>WordPress Version:</td><td>' . esc_html($wp_version) . '</td></tr>';
        echo '<tr><td>PHP Version:</td><td>' . esc_html(phpversion()) . '</td></tr>';
        echo '<tr><td>WooCommerce Version:</td><td>' . esc_html(WC()->version) . '</td></tr>';
        echo '<tr><td>Current User ID:</td><td>' . get_current_user_id() . '</td></tr>';
        echo '<tr><td>Current User Caps:</td><td><pre>' . print_r(wp_get_current_user()->allcaps, true) . '</pre></td></tr>';
        echo '</table>';
        
        // Database tables
        echo '<h2>Database Tables</h2>';
        $tables = [
            'lsr_licenses',
            'lsr_activations',
            'lsr_releases',
            'lsr_signed_urls'
        ];
        
        echo '<table class="widefat">';
        foreach ($tables as $table) {
            $full_table = $wpdb->prefix . $table;
            $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
            $count = $exists ? $wpdb->get_var("SELECT COUNT(*) FROM $full_table") : 0;
            
            echo '<tr>';
            echo '<td>' . esc_html($full_table) . '</td>';
            echo '<td>' . ($exists ? '✅ Exists' : '❌ Missing') . '</td>';
            echo '<td>' . ($exists ? $count . ' rows' : '-') . '</td>';
            echo '</tr>';
        }
        echo '</table>';
        
        // Plugin status
        echo '<h2>Plugin Status</h2>';
        echo '<table class="widefat">';
        echo '<tr><td>Plugin Version:</td><td>' . (defined('LSR_VERSION') ? LSR_VERSION : 'Not defined') . '</td></tr>';
        echo '<tr><td>Plugin Path:</td><td>' . (defined('LSR_PATH') ? LSR_PATH : 'Not defined') . '</td></tr>';
        echo '<tr><td>Plugin URL:</td><td>' . (defined('LSR_URL') ? LSR_URL : 'Not defined') . '</td></tr>';
        echo '</table>';
        
        echo '</div>';
    }
}