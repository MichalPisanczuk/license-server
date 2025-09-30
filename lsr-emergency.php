<?php
/**
 * LICENSE SERVER EMERGENCY FIX
 * 
 * INSTRUKCJA:
 * 1. Umieść ten plik w: wp-content/plugins/license-server/lsr-emergency.php
 * 2. Otwórz w przeglądarce: https://localhost/pisanczuk/wp-content/plugins/license-server/lsr-emergency.php
 * 3. Postępuj według instrukcji na ekranie
 */

// Załaduj WordPress
$wp_load_paths = [
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
    __DIR__ . '/../wp-load.php',
    __DIR__ . '/wp-load.php',
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die('❌ Nie mogę załadować WordPress. Sprawdź ścieżkę do wp-load.php');
}

// Sprawdź czy użytkownik jest zalogowany
if (!is_user_logged_in()) {
    auth_redirect();
    exit;
}

// Podstawowe zabezpieczenie - tylko admin
if (!current_user_can('administrator')) {
    die('❌ Musisz być administratorem aby użyć tego narzędzia.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>License Server - Emergency Fix</title>
    <meta charset="UTF-8">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f1f1f1;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.13);
        }
        h1 {
            color: #d63638;
            border-bottom: 3px solid #d63638;
            padding-bottom: 10px;
        }
        h2 {
            color: #1d2327;
            margin-top: 30px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        .status {
            padding: 10px;
            margin: 10px 0;
            border-left: 4px solid;
        }
        .status.success {
            background: #d4edda;
            border-color: #28a745;
            color: #155724;
        }
        .status.error {
            background: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }
        .status.warning {
            background: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }
        .status.info {
            background: #d1ecf1;
            border-color: #17a2b8;
            color: #0c5460;
        }
        button {
            background: #2271b1;
            color: white;
            padding: 10px 20px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            margin: 10px 0;
        }
        button:hover {
            background: #135e96;
        }
        button.danger {
            background: #d63638;
        }
        button.danger:hover {
            background: #b32d2e;
        }
        code {
            background: #f0f0f0;
            padding: 2px 5px;
            font-family: Consolas, Monaco, monospace;
        }
        pre {
            background: #282c34;
            color: #abb2bf;
            padding: 15px;
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background: #f5f5f5;
        }
        .success-icon { color: #28a745; }
        .error-icon { color: #dc3545; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚨 License Server - Emergency Fix Tool</h1>
        
        <?php
        global $wpdb, $current_user;
        
        // Wykonaj naprawę jeśli kliknięto przycisk
        if (isset($_POST['action'])) {
            $action = $_POST['action'];
            
            echo '<div class="status info"><strong>Wykonuję: ' . esc_html($action) . '</strong></div>';
            
            switch ($action) {
                case 'fix_permissions':
                    fix_permissions();
                    break;
                case 'create_tables':
                    create_tables();
                    break;
                case 'register_menu':
                    register_menu();
                    break;
                case 'full_fix':
                    fix_permissions();
                    create_tables();
                    register_menu();
                    clear_cache();
                    break;
            }
            
            echo '<div class="status success"><strong>✅ Operacja zakończona!</strong> <a href="">Odśwież stronę</a></div>';
        }
        ?>
        
        <h2>👤 Informacje o użytkowniku</h2>
        <table>
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
        </table>
        
        <h2>🔐 Status uprawnień</h2>
        <table>
            <?php
            $caps_to_check = [
                'manage_options' => 'Zarządzanie opcjami (wymagane)',
                'administrator' => 'Rola administratora',
                'manage_woocommerce' => 'Zarządzanie WooCommerce',
            ];
            
            foreach ($caps_to_check as $cap => $desc) {
                $has_cap = current_user_can($cap);
                echo '<tr>';
                echo '<td><strong>' . esc_html($desc) . ':</strong></td>';
                echo '<td>' . ($has_cap ? 
                    '<span class="success-icon">✅ TAK</span>' : 
                    '<span class="error-icon">❌ NIE</span>') . '</td>';
                echo '</tr>';
            }
            ?>
        </table>
        
        <h2>🗄️ Status tabel bazy danych</h2>
        <table>
            <?php
            $tables = [
                'lsr_licenses' => 'Tabela licencji',
                'lsr_activations' => 'Tabela aktywacji',
                'lsr_releases' => 'Tabela wydań',
                'lsr_signed_urls' => 'Tabela podpisanych URLi'
            ];
            
            $missing_tables = false;
            foreach ($tables as $table => $desc) {
                $full_table = $wpdb->prefix . $table;
                $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table'") === $full_table;
                
                if (!$exists) $missing_tables = true;
                
                echo '<tr>';
                echo '<td><strong>' . esc_html($desc) . ':</strong></td>';
                echo '<td>';
                if ($exists) {
                    $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table");
                    echo '<span class="success-icon">✅ Istnieje</span> (' . $count . ' rekordów)';
                } else {
                    echo '<span class="error-icon">❌ Brak tabeli</span>';
                }
                echo '</td>';
                echo '</tr>';
            }
            ?>
        </table>
        
        <h2>🔧 Narzędzia naprawcze</h2>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="fix_permissions">
            <button type="submit">🔐 Napraw uprawnienia</button>
        </form>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="create_tables">
            <button type="submit" <?php echo !$missing_tables ? 'disabled' : ''; ?>>
                🗄️ Utwórz brakujące tabele
            </button>
        </form>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="register_menu">
            <button type="submit">📋 Zarejestruj menu</button>
        </form>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="action" value="full_fix">
            <button type="submit" class="danger">🚀 PEŁNA NAPRAWA</button>
        </form>
        
        <h2>🔗 Linki bezpośrednie</h2>
        <p>Spróbuj wejść bezpośrednio:</p>
        <ul>
            <li><a href="<?php echo admin_url('admin.php?page=lsr-licenses'); ?>" target="_blank">
                → Panel licencji
            </a></li>
            <li><a href="<?php echo admin_url('admin.php?page=lsr-releases'); ?>" target="_blank">
                → Panel wydań
            </a></li>
            <li><a href="<?php echo admin_url('admin.php?page=lsr-settings'); ?>" target="_blank">
                → Ustawienia
            </a></li>
        </ul>
        
        <h2>💻 Kod do wykonania ręcznie</h2>
        <p>Jeśli powyższe nie działa, wykonaj ten kod w <strong>functions.php</strong> motywu lub przez wtyczkę Code Snippets:</p>
        <pre>
// License Server - Manual Fix
add_action('init', function() {
    // 1. Napraw uprawnienia
    $role = get_role('administrator');
    if ($role) {
        $role->add_cap('manage_options');
        $role->add_cap('manage_woocommerce');
    }
    
    // 2. Dodaj awaryjne menu
    add_action('admin_menu', function() {
        add_menu_page(
            'License Server FIX',
            'License Server',
            'administrator', // używamy 'administrator' zamiast 'manage_options'
            'lsr-licenses-fix',
            function() {
                echo '&lt;div class="wrap"&gt;';
                echo '&lt;h1&gt;License Server&lt;/h1&gt;';
                echo '&lt;p&gt;Menu awaryjne działa!&lt;/p&gt;';
                
                // Tutaj możesz dodać własną logikę
                global $wpdb;
                $licenses = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lsr_licenses LIMIT 10");
                
                if ($licenses) {
                    echo '&lt;h2&gt;Ostatnie licencje:&lt;/h2&gt;';
                    echo '&lt;ul&gt;';
                    foreach ($licenses as $lic) {
                        echo '&lt;li&gt;' . esc_html($lic->license_key) . '&lt;/li&gt;';
                    }
                    echo '&lt;/ul&gt;';
                }
                
                echo '&lt;/div&gt;';
            },
            'dashicons-admin-network',
            90
        );
    }, 9999);
});
        </pre>
        
        <h2>📞 Kontakt z supportem</h2>
        <div class="status info">
            <p>Jeśli problem nadal występuje, wyślij poniższe informacje do supportu:</p>
            <pre><?php
            echo "WordPress: " . get_bloginfo('version') . "\n";
            echo "PHP: " . phpversion() . "\n";
            echo "MySQL: " . $wpdb->db_version() . "\n";
            echo "WooCommerce: " . (defined('WC_VERSION') ? WC_VERSION : 'Nie zainstalowane') . "\n";
            echo "User ID: " . $current_user->ID . "\n";
            echo "User Roles: " . implode(', ', $current_user->roles) . "\n";
            echo "Site URL: " . site_url() . "\n";
            echo "Plugin Path: " . plugin_dir_path(__FILE__) . "\n";
            ?></pre>
        </div>
    </div>
</body>
</html>

<?php
// Funkcje pomocnicze

function fix_permissions() {
    $role = get_role('administrator');
    if ($role) {
        $capabilities = [
            'manage_options',
            'manage_woocommerce',
            'edit_shop_orders',
            'view_admin_dashboard'
        ];
        
        foreach ($capabilities as $cap) {
            $role->add_cap($cap);
            echo '<div class="status success">✅ Dodano uprawnienie: ' . $cap . '</div>';
        }
    }
    
    // Napraw również dla current user
    $user = wp_get_current_user();
    if ($user) {
        $user->add_cap('manage_options');
        echo '<div class="status success">✅ Dodano manage_options dla użytkownika: ' . $user->user_login . '</div>';
    }
}

function create_tables() {
    global $wpdb;
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // Tabela licencji
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_licenses (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        license_key VARCHAR(255) NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        product_id BIGINT UNSIGNED NOT NULL,
        order_id BIGINT UNSIGNED NOT NULL,
        subscription_id BIGINT UNSIGNED DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT 'active',
        max_activations INT DEFAULT NULL,
        expires_at DATETIME DEFAULT NULL,
        grace_until DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY license_key (license_key),
        KEY user_id (user_id),
        KEY product_id (product_id),
        KEY order_id (order_id),
        KEY subscription_id (subscription_id),
        KEY status (status)
    ) $charset_collate;";
    
    dbDelta($sql);
    echo '<div class="status success">✅ Utworzono/zaktualizowano tabelę lsr_licenses</div>';
    
    // Tabela aktywacji
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_activations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        license_id BIGINT UNSIGNED NOT NULL,
        domain VARCHAR(255) NOT NULL,
        ip_hash VARCHAR(64) DEFAULT NULL,
        activated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_check_at DATETIME DEFAULT NULL,
        deactivated_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY license_id (license_id),
        KEY domain (domain)
    ) $charset_collate;";
    
    dbDelta($sql);
    echo '<div class="status success">✅ Utworzono/zaktualizowano tabelę lsr_activations</div>';
    
    // Tabela wydań
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_releases (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        product_id BIGINT UNSIGNED NOT NULL,
        slug VARCHAR(100) NOT NULL,
        version VARCHAR(20) NOT NULL,
        changelog TEXT,
        download_url VARCHAR(500),
        min_php VARCHAR(20) DEFAULT NULL,
        min_wp VARCHAR(20) DEFAULT NULL,
        released_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY product_id (product_id),
        KEY slug (slug),
        KEY version (version)
    ) $charset_collate;";
    
    dbDelta($sql);
    echo '<div class="status success">✅ Utworzono/zaktualizowano tabelę lsr_releases</div>';
    
    // Tabela podpisanych URLi
    $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}lsr_signed_urls (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        signature VARCHAR(255) NOT NULL,
        license_id BIGINT UNSIGNED NOT NULL,
        purpose VARCHAR(50) DEFAULT 'download',
        expires_at DATETIME NOT NULL,
        used_at DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY signature (signature),
        KEY license_id (license_id),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    
    dbDelta($sql);
    echo '<div class="status success">✅ Utworzono/zaktualizowano tabelę lsr_signed_urls</div>';
}

function register_menu() {
    // Wymuś reset opcji transient
    delete_transient('lsr_menu_registered');
    
    // Dodaj prostą flagę
    update_option('lsr_force_menu_registration', true);
    
    echo '<div class="status success">✅ Wymuszono ponowną rejestrację menu</div>';
    echo '<div class="status info">ℹ️ Menu powinno pojawić się po odświeżeniu panelu admina</div>';
}

function clear_cache() {
    // Wyczyść cache WordPress
    wp_cache_flush();
    
    // Wyczyść rewrite rules
    flush_rewrite_rules();
    
    // Wyczyść transients
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_site_transient_%'");
    
    echo '<div class="status success">✅ Wyczyszczono cache WordPress</div>';
}
?>