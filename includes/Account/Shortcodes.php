<?php
namespace MyShop\LicenseServer\Account;

/**
 * Rejestracja shortcode, który wyświetla listę licencji.
 */
class Shortcodes
{
    public static function init(): void
    {
        add_shortcode('myshop_licenses_table', [self::class, 'renderLicensesTable']);
    }

    public static function renderLicensesTable(): string
    {
        ob_start();
        include plugin_dir_path(__FILE__) . '../../templates/account/licenses-table.php';
        return ob_get_clean();
    }
}
