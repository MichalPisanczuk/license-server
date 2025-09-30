<?php
/**
 * License Server Debug & Fix Tool
 * 
 * Umieść ten plik w głównym katalogu wtyczki license-server
 * i odwiedź: /wp-admin/admin.php?page=lsr-debug-fix
 * 
 * @package MyShop\LicenseServer
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class License_Server_Debug_Fix
 */
class License_Server_Debug_Fix {
    
    /**
     * Initialize debug tool
     */
    public static function init() {
        // Dodaj ukryte menu debugowania
        add_action('admin_menu', [__CLASS__, 'add_debug_menu'], 9999);
        
        // Hook do naprawy problemów
        add_action('admin_init', [__CLASS__, 'maybe_fix_issues']);
    }
    
    /**
     * Add debug menu
     */
    public static function add_debug_menu() {
        add_submenu_page(
            null, // hidden menu
            'License Server Debug & Fix',
            'LSR Debug Fix',
            'manage_options',
            'lsr-debug-fix',
            [__CLASS__, 'render_debug_page']
        );
    }
    
    /**
     * Render debug page
     */
    public static function render_debug_page() {
        // Sprawdź uprawnienia
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień');
        }
        
        $current_user = wp_get_current_user();
        global $wpdb;
        
        ?>
        <div class="wrap">
            <h1>License Server - Debug & Fix Tool</h1>
            
            <?php
            // Wykonaj naprawy jeśli przesłano formularz
            if (isset($_POST['fix_issues']) && wp_verify_nonce($_POST['_wpnonce'], 'lsr_debug_fix')) {
                self::fix_all_issues();
                echo '<div class="notice notice-success"><p>✅ Problemy zostały naprawione!</p></div>';
            }
            ?>
            
            <!-- Informacje o użytkowniku -->
            <h2>👤 Informacje o użytkowniku</h2>
            <table class="widefat">
                <tr>
                    <td><strong>Użytkownik:</strong></td>
                    <td><?php echo esc_html($current_user->user_login); ?> (ID: <?php echo $current_user->ID; ?>)</td>
                </tr>
                <tr>
                    <td><strong>Email:</strong></td>
                    <td><?php echo esc_html($current_user->user_email); ?></td>
                </tr>
                <tr>
                    <td><strong>Role:</strong></td>
                    <td><?php echo implode(', ', $current_user->roles); ?></td>
                </tr>
                <tr>
                    <td><strong>Czy jest adminem:</strong></td>
                    <td><?php echo current_user_can('manage_options') ? '✅ TAK' : '❌ NIE'; ?></td>
                </tr>
                <tr>
                    <td><strong>Czy jest super adminem:</strong></td>
                    <td><?php echo is_super_admin() ? '✅ TAK' : '❌ NIE'; ?></td>
                </tr>
            </table>
            
            <!-- Sprawdzenie uprawnień -->
            <h2>🔐 Sprawdzenie uprawnień</h2>
            <table class="widefat">
                <?php
                $capabilities_to_check = [
                    'manage_options' => 'Zarządzanie opcjami',
                    'manage_woocommerce' => 'Zarządzanie WooCommerce',
                    'edit_shop_orders' => 'Edycja zamówień',
                    'administrator' => 'Administrator (role)',
                ];
                
                foreach ($capabilities_to_check as $cap => $label) {
                    $has_cap = current_user_can($cap);
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($label) . ' (' . $cap . '):</strong></td>';
                    echo '<td>' . ($has_cap ? '✅ TAK' : '❌ NIE') . '</td>';
                    echo '</tr>';
                }
                ?>
            </table>
            
            <!-- Sprawdzenie tabel -->
            <h2>🗄️ Sprawdzenie tabel bazy danych</h2>
            <table class="widefat">
                <?php
                $tables = [
                    'lsr_licenses' => 'Licencje',
                    'lsr_activations' => 'Aktywacje',
                    'lsr_releases' => 'Wydania',
                    'lsr_signed_urls' => 'Podpisane URLe'
                ];
                
                foreach ($tables as $table => $label) {
                    $full_table = $wpdb->prefix . $table;
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
                    
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($label) . ' (' . $full_table . '):</strong></td>';
                    if ($exists) {
                        $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table");
                        echo '<td>✅ Istnieje (' . $count . ' rekordów)</td>';
                    } else {
                        echo '<td>❌ Brak tabeli</td>';
                    }
                    echo '</tr>';
                }
                ?>
            </table>
            
            <!-- Sprawdzenie menu -->
            <h2>📋 Sprawdzenie menu administracyjnego</h2>
            <table class="widefat">
                <?php
                global $menu, $submenu;
                $found_menu = false;
                
                // Szukaj menu License Server
                if (is_array($menu)) {
                    foreach ($menu as $item) {
                        if (isset($item[2]) && strpos($item[2], 'lsr-') === 0) {
                            $found_menu = true;
                            echo '<tr>';
                            echo '<td><strong>Menu główne:</strong></td>';
                            echo '<td>✅ ' . esc_html($item[0]) . ' (' . esc_html($item[2]) . ')</td>';
                            echo '</tr>';
                            
                            // Sprawdź submenu
                            if (isset($submenu[$item[2]])) {
                                foreach ($submenu[$item[2]] as $sub) {
                                    echo '<tr>';
                                    echo '<td><strong>└─ Podmenu:</strong></td>';
                                    echo '<td>' . esc_html($sub[0]) . ' (' . esc_html($sub[2]) . ')</td>';
                                    echo '</tr>';
                                }
                            }
                        }
                    }
                }
                
                if (!$found_menu) {
                    echo '<tr><td colspan="2">❌ <strong>Menu License Server nie zostało znalezione!</strong></td></tr>';
                }
                ?>
            </table>
            
            <!-- Sprawdzenie plików -->
            <h2>📁 Sprawdzenie plików wtyczki</h2>
            <table class="widefat">
                <?php
                $plugin_dir = WP_PLUGIN_DIR . '/license-server/';
                $critical_files = [
                    'license-server.php' => 'Główny plik wtyczki',
                    'includes/bootstrap.php' => 'Bootstrap',
                    'includes/Admin/Menu.php' => 'Menu administracyjne',
                    'includes/Data/Database.php' => 'Obsługa bazy danych',
                ];
                
                foreach ($critical_files as $file => $label) {
                    $full_path = $plugin_dir . $file;
                    $exists = file_exists($full_path);
                    
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($label) . ':</strong></td>';
                    echo '<td>' . ($exists ? '✅ Istnieje' : '❌ Brak pliku') . '</td>';
                    echo '</tr>';
                }
                ?>
            </table>
            
            <!-- Sprawdzenie hooków -->
            <h2>🔗 Sprawdzenie hooków WordPress</h2>
            <table class="widefat">
                <?php
                global $wp_filter;
                $hooks_to_check = [
                    'admin_menu' => 'Rejestracja menu',
                    'admin_init' => 'Inicjalizacja admina',
                    'init' => 'Ogólna inicjalizacja',
                ];
                
                foreach ($hooks_to_check as $hook => $label) {
                    $callbacks = [];
                    if (isset($wp_filter[$hook])) {
                        foreach ($wp_filter[$hook] as $priority => $functions) {
                            foreach ($functions as $function) {
                                $callback_name = 'Unknown';
                                if (isset($function['function'])) {
                                    if (is_array($function['function'])) {
                                        if (is_object($function['function'][0])) {
                                            $callback_name = get_class($function['function'][0]) . '::' . $function['function'][1];
                                        } else {
                                            $callback_name = $function['function'][0] . '::' . $function['function'][1];
                                        }
                                    } else {
                                        $callback_name = $function['function'];
                                    }
                                }
                                
                                // Tylko pokaż te związane z License Server
                                if (stripos($callback_name, 'license') !== false || 
                                    stripos($callback_name, 'lsr') !== false ||
                                    stripos($callback_name, 'myshop') !== false) {
                                    $callbacks[] = $callback_name . ' (priorytet: ' . $priority . ')';
                                }
                            }
                        }
                    }
                    
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($label) . ' (' . $hook . '):</strong></td>';
                    echo '<td>';
                    if (!empty($callbacks)) {
                        echo '✅ ' . count($callbacks) . ' callbacków:<br>';
                        echo '<small>' . implode('<br>', array_map('esc_html', $callbacks)) . '</small>';
                    } else {
                        echo '❌ Brak hooków License Server';
                    }
                    echo '</td>';
                    echo '</tr>';
                }
                ?>
            </table>
            
            <!-- Przycisk naprawy -->
            <h2>🔧 Naprawa problemów</h2>
            <form method="post">
                <?php wp_nonce_field('lsr_debug_fix'); ?>
                <p>Kliknij poniższy przycisk, aby spróbować automatycznie naprawić wykryte problemy:</p>
                <p>
                    <button type="submit" name="fix_issues" class="button button-primary button-large">
                        🔧 Napraw wszystkie problemy
                    </button>
                </p>
            </form>
            
            <hr>
            
            <!-- Instrukcje ręcznej naprawy -->
            <h2>📝 Instrukcje ręcznej naprawy</h2>
            <div class="card">
                <h3>1. Sprawdź czy wtyczka jest aktywna:</h3>
                <pre>
// W konsoli WordPress lub przez wp-cli:
wp plugin list --status=active | grep license-server

// Lub w PHP:
if (is_plugin_active('license-server/license-server.php')) {
    echo "Wtyczka jest aktywna";
}
                </pre>
                
                <h3>2. Ręczne dodanie uprawnień:</h3>
                <pre>
// Dodaj to do functions.php lub wykonaj raz:
$role = get_role('administrator');
if ($role) {
    $role->add_cap('manage_options');
    $role->add_cap('manage_woocommerce');
}
                </pre>
                
                <h3>3. Wymuś ponowną rejestrację menu:</h3>
                <pre>
// Dodaj do głównego pliku wtyczki:
add_action('admin_menu', function() {
    remove_menu_page('lsr-licenses');
    \MyShop\LicenseServer\Admin\Menu::registerMenu();
}, 9999);
                </pre>
                
                <h3>4. Link bezpośredni do stron:</h3>
                <p>Spróbuj wejść bezpośrednio przez te linki:</p>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=lsr-licenses'); ?>">Licencje</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=lsr-releases'); ?>">Wydania</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=lsr-settings'); ?>">Ustawienia</a></li>
                </ul>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Fix all detected issues
     */
    public static function fix_all_issues() {
        global $wpdb;
        
        // 1. Napraw uprawnienia administratora
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('manage_options');
            $role->add_cap('manage_woocommerce');
            $role->add_cap('edit_shop_orders');
        }
        
        // 2. Wyczyść cache menu
        wp_cache_delete('alloptions', 'options');
        
        // 3. Wymuś ponowną inicjalizację menu
        remove_action('admin_menu', ['MyShop\LicenseServer\Admin\Menu', 'registerMenu']);
        add_action('admin_menu', function() {
            if (class_exists('MyShop\LicenseServer\Admin\Menu')) {
                MyShop\LicenseServer\Admin\Menu::registerMenu();
            }
        }, 99);
        
        // 4. Sprawdź i utwórz brakujące tabele
        $tables = [
            'lsr_licenses' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_licenses (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    license_key VARCHAR(255) NOT NULL,
                    user_id BIGINT UNSIGNED DEFAULT NULL,
                    product_id BIGINT UNSIGNED NOT NULL,
                    order_id BIGINT UNSIGNED NOT NULL,
                    status VARCHAR(50) NOT NULL DEFAULT 'active',
                    max_activations INT DEFAULT NULL,
                    expires_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY license_key (license_key),
                    KEY user_id (user_id),
                    KEY product_id (product_id),
                    KEY order_id (order_id),
                    KEY status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'lsr_activations' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_activations (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    license_id BIGINT UNSIGNED NOT NULL,
                    domain VARCHAR(255) NOT NULL,
                    ip_hash VARCHAR(64) DEFAULT NULL,
                    activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_check_at DATETIME DEFAULT NULL,
                    PRIMARY KEY (id),
                    KEY license_id (license_id),
                    KEY domain (domain)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'lsr_releases' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_releases (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    product_id BIGINT UNSIGNED NOT NULL,
                    version VARCHAR(20) NOT NULL,
                    changelog TEXT,
                    download_url VARCHAR(500),
                    released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY product_id (product_id),
                    KEY version (version)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ",
            'lsr_signed_urls' => "
                CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_signed_urls (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    signature VARCHAR(255) NOT NULL,
                    license_id BIGINT UNSIGNED NOT NULL,
                    expires_at DATETIME NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY signature (signature),
                    KEY license_id (license_id),
                    KEY expires_at (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            "
        ];
        
        foreach ($tables as $table_name => $create_sql) {
            $wpdb->query($create_sql);
        }
        
        // 5. Wymuś flush rewrite rules
        flush_rewrite_rules();
        
        // 6. Zapisz opcję że naprawy zostały wykonane
        update_option('lsr_debug_fixes_applied', current_time('mysql'));
    }
    
    /**
     * Maybe fix issues automatically
     */
    public static function maybe_fix_issues() {
        // Auto-fix tylko jeśli jest parametr w URL
        if (isset($_GET['lsr_autofix']) && current_user_can('manage_options')) {
            self::fix_all_issues();
            wp_redirect(admin_url('admin.php?page=lsr-licenses&fixed=1'));
            exit;
        }
    }
}

// Inicjalizacja
License_Server_Debug_Fix::init();