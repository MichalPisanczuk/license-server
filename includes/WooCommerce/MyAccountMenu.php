<?php
namespace MyShop\LicenseServer\WooCommerce;

use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use function MyShop\LicenseServer\lsr;

/**
 * Modyfikuje menu „Moje konto” WooCommerce, dodając sekcję Licencje i ukrywając Subskrypcje dla użytkowników bez subskrypcji.
 */
class MyAccountMenu
{
    public static function init(): void
    {
        // Dodaj endpoint 'licenses'
        add_action('init', [self::class, 'addEndpoint']);
        // Dodaj do query vars
        add_filter('query_vars', [self::class, 'addQueryVars']);
        // Dodaj pozycję menu
        add_filter('woocommerce_account_menu_items', [self::class, 'addMenuItem'], 30);
        // Wyświetl zawartość endpointu
        add_action('woocommerce_account_licenses_endpoint', [self::class, 'renderEndpoint']);
        // Ukryj menu 'Subskrypcje' jeśli brak subskrypcji
        add_filter('woocommerce_account_menu_items', [self::class, 'maybeHideSubscriptions'], 20);
        // Blokuj bezpośredni dostęp do endpointu subskrypcji, jeśli brak subskrypcji
        add_action('template_redirect', [self::class, 'protectSubscriptions']);
    }

    public static function addEndpoint(): void
    {
        add_rewrite_endpoint('licenses', EP_ROOT | EP_PAGES);
    }

    public static function addQueryVars(array $vars): array
    {
        $vars[] = 'licenses';
        return $vars;
    }

    /**
     * Dodaj menu „Licencje” po sekcji 'downloads'.
     *
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public static function addMenuItem(array $items): array
    {
        // Pokaż tylko gdy użytkownik ma licencję
        if (!is_user_logged_in()) {
            return $items;
        }
        $userId = get_current_user_id();
        /** @var LicenseRepository $repo */
        $repo = lsr(LicenseRepository::class);
        $licenses = $repo->findByUser($userId);
        if (empty($licenses)) {
            return $items;
        }
        $newItems = [];
        foreach ($items as $key => $label) {
            $newItems[$key] = $label;
            if ($key === 'downloads') {
                $newItems['licenses'] = __('Licencje', 'license-server');
            }
        }
        return $newItems;
    }

    /**
     * Wyświetl tabelę licencji na stronie konta.
     */
    public static function renderEndpoint(): void
    {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Musisz być zalogowany, aby zobaczyć swoje licencje.', 'license-server') . '</p>';
            return;
        }
        $userId = get_current_user_id();
        /** @var LicenseRepository $repo */
        $repo = lsr(LicenseRepository::class);
        $licenses = $repo->findByUser($userId);
        if (empty($licenses)) {
            echo '<p>' . esc_html__('Nie masz żadnych aktywnych licencji.', 'license-server') . '</p>';
            return;
        }
        echo '<h3>' . esc_html__('Twoje licencje', 'license-server') . '</h3>';
        echo '<table class="shop_table shop_table_responsive account-orders-table"><thead><tr>';
        echo '<th>' . esc_html__('Produkt', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Klucz licencji', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Status', 'license-server') . '</th>';
        echo '<th>' . esc_html__('Wygasa', 'license-server') . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($licenses as $license) {
            $product = wc_get_product($license['product_id']);
            echo '<tr>';
            echo '<td>' . esc_html($product ? $product->get_name() : ('ID #' . $license['product_id'])) . '</td>';
            echo '<td>' . esc_html($license['license_key']) . '</td>';
            echo '<td>' . esc_html($license['status']) . '</td>';
            echo '<td>' . esc_html($license['expires_at'] ?: __('Nigdy', 'license-server')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Ukryj 'Subskrypcje', gdy użytkownik nie ma żadnych subskrypcji (z modułu Woo Subscriptions).
     *
     * @param array<string,string> $items
     * @return array<string,string>
     */
    public static function maybeHideSubscriptions(array $items): array
    {
        if (!is_user_logged_in()) {
            return $items;
        }
        // Woo Subscriptions generuje klucz 'subscriptions'
        if (isset($items['subscriptions']) && !self::userHasSubscription()) {
            unset($items['subscriptions']);
        }
        return $items;
    }

    /**
     * Zablokuj dostęp do /my-account/subscriptions, jeśli użytkownik nie ma subskrypcji.
     */
    public static function protectSubscriptions(): void
    {
        if (is_account_page() && isset($GLOBALS['wp']->query_vars['subscriptions'])) {
            if (!self::userHasSubscription()) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                nocache_headers();
            }
        }
    }

    /**
     * Czy użytkownik ma aktywne subskrypcje?
     *
     * @return bool
     */
    private static function userHasSubscription(): bool
    {
        if (!class_exists('WC_Subscriptions') || !is_user_logged_in()) {
            return false;
        }
        $subs = wcs_get_users_subscriptions(get_current_user_id());
        return !empty($subs);
    }
}
