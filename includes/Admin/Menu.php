<?php
namespace MyShop\LicenseServer\Admin;

/**
 * Rejestracja elementów menu w panelu administratora WordPress.
 */
class Menu
{
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerMenu']);
    }

    public static function registerMenu(): void
    {
        // Główne menu License Server
        add_menu_page(
            __('License Server', 'license-server'),
            __('License Server', 'license-server'),
            'manage_options',
            'lsr-licenses',
            [self::class, 'renderLicensesPage'],
            'dashicons-admin-network',
            58
        );
        // Podstrony
        add_submenu_page('lsr-licenses', __('Licencje', 'license-server'), __('Licencje', 'license-server'), 'manage_options', 'lsr-licenses', [self::class, 'renderLicensesPage']);
        add_submenu_page('lsr-licenses', __('Wydania', 'license-server'), __('Wydania', 'license-server'), 'manage_options', 'lsr-releases', [self::class, 'renderReleasesPage']);
        add_submenu_page('lsr-licenses', __('Ustawienia', 'license-server'), __('Ustawienia', 'license-server'), 'manage_options', 'lsr-settings', [self::class, 'renderSettingsPage']);
    }

    public static function renderLicensesPage(): void
    {
        // Deleguj do klasy ekranu
        \MyShop\LicenseServer\Admin\Screens\LicensesScreen::render();
    }
    public static function renderReleasesPage(): void
    {
        \MyShop\LicenseServer\Admin\Screens\ReleasesScreen::render();
    }
    public static function renderSettingsPage(): void
    {
        \MyShop\LicenseServer\Admin\Screens\SettingsScreen::render();
    }
}
