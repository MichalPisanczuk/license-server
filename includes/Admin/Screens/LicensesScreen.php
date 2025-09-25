<?php
namespace MyShop\LicenseServer\Admin\Screens;

use MyShop\LicenseServer\Admin\LicensesTable;

/**
 * Ekran listy licencji w panelu admina.
 */
class LicensesScreen
{
    public static function render(): void
    {
        $table = new LicensesTable();
        $table->prepare_items();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Licencje', 'license-server') . '</h1>';
        $table->display();
        echo '</div>';
    }
}
