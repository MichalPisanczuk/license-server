<?php
namespace MyShop\LicenseServer\Account;

/**
 * Rejestracja niestandardowych endpointów w sekcji „Moje konto”.
 */
class Endpoints
{
    public static function init(): void
    {
        add_action('init', [self::class, 'addEndpoint']);
        add_filter('query_vars', [self::class, 'addQueryVars']);
        add_action('woocommerce_account_my-licenses_endpoint', [self::class, 'render']);
    }

    public static function addEndpoint(): void
    {
        add_rewrite_endpoint('my-licenses', EP_ROOT | EP_PAGES);
    }

    public static function addQueryVars(array $vars): array
    {
        $vars[] = 'my-licenses';
        return $vars;
    }

    public static function render(): void
    {
        // Prosta delegacja do szablonu
        include_once plugin_dir_path(__FILE__) . '../../templates/account/licenses-table.php';
    }
}
