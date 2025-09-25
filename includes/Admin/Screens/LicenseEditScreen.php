<?php
namespace MyShop\LicenseServer\Admin\Screens;

/**
 * Ekran edycji licencji.
 */
class LicenseEditScreen
{
    public static function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Edytuj licencję', 'license-server') . '</h1>';
        echo '<p>' . esc_html__('Ten ekran powinien zawierać formularz do edycji licencji.', 'license-server') . '</p>';
        echo '</div>';
    }
}
