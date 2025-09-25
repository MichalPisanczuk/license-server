<?php
namespace MyShop\LicenseServer\Admin;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Przykładowa tabela licencji oparta o WP_List_Table.
 *
 * Ta klasa jest szkieletem i nie implementuje pełnej funkcjonalności. W produkcji możesz zastąpić ją własną implementacją.
 */
class LicensesTable extends \WP_List_Table
{
    /** @var array */
    private array $items = [];

    public function __construct()
    {
        parent::__construct([
            'singular' => 'license',
            'plural'   => 'licenses',
            'ajax'     => false,
        ]);
    }

    public function prepare_items(): void
    {
        // Pobierz dane z repozytorium i przygotuj je do wyświetlenia
        $repo          = \MyShop\LicenseServer\lsr(\MyShop\LicenseServer\Data\Repositories\EnhancedLicenseRepository::class);
        $activationRepo= \MyShop\LicenseServer\lsr(\MyShop\LicenseServer\Data\Repositories\EnhancedActivationRepository::class);
        $items = [];
        if ($repo) {
            $licenses = $repo->findAll();
            foreach ($licenses as $license) {
                // Pobierz nazwę produktu
                $productName = '';
                $product = wc_get_product($license['product_id']);
                if ($product) {
                    $productName = $product->get_name();
                }
                // Liczba aktywacji
                $activationCount = 0;
                if ($activationRepo) {
                    $activationCount = $activationRepo->countActivations((int) $license['id']);
                }
                $items[] = [
                    'id'              => $license['id'],
                    'license_key'     => $license['license_key'],
                    'product'         => $productName ?: ('#' . $license['product_id']),
                    'user'            => $license['user_id'],
                    'status'          => $license['status'],
                    'expires_at'      => $license['expires_at'] ?: __('Nigdy', 'license-server'),
                    'max_activations' => $license['max_activations'] === null ? __('Bez limitu', 'license-server') : $license['max_activations'],
                    'activations'     => $activationCount,
                ];
            }
        }
        $this->items = $items;
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    public function get_columns(): array
    {
        return [
            'license_key'     => __('Klucz', 'license-server'),
            'product'         => __('Produkt', 'license-server'),
            'user'            => __('Użytkownik', 'license-server'),
            'status'          => __('Status', 'license-server'),
            'expires_at'      => __('Wygasa', 'license-server'),
            'max_activations' => __('Limit', 'license-server'),
            'activations'     => __('Aktywacje', 'license-server'),
        ];
    }

    public function column_default($item, $column_name)
    {
        return $item[$column_name] ?? '';
    }

    public function get_items(): array
    {
        return $this->items;
    }
}
