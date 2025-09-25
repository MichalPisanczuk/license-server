<?php
namespace MyShop\LicenseServer\WooCommerce;

/**
 * Klasa dodająca pola produktu potrzebne do licencjonowania.
 */
class ProductFlags
{
    public static function init(): void
    {
        // Dodaj pola w edycji produktu
        add_action('woocommerce_product_options_general_product_data', [self::class, 'renderFields']);
        // Zapisz pola
        add_action('woocommerce_admin_process_product_object', [self::class, 'saveFields']);
    }

    /**
     * Wyświetl pola w panelu produktu.
     */
    public static function renderFields(): void
    {
        echo '<div class="options_group show_if_simple show_if_virtual">';
        // Checkbox – czy produkt jest licencjonowany
        woocommerce_wp_checkbox([
            'id'    => '_lsr_is_licensed',
            'label' => __('Produkt licencjonowany (License Server)', 'license-server'),
            'value' => get_post_meta(get_the_ID(), '_lsr_is_licensed', true) === 'yes' ? 'yes' : 'no',
            'desc_tip' => true,
            'description' => __('Zaznacz, jeśli ten produkt wymaga licencji i aktualizacji.', 'license-server'),
        ]);
        // Liczba maksymalnych aktywacji
        woocommerce_wp_text_input([
            'id'          => '_lsr_max_activations',
          'label'       => __('Maksymalne aktywacje', 'license-server'),
            'type'        => 'number',
            'custom_attributes' => [
                'min'  => '0',
                'step' => '1',
            ],
            'value'       => get_post_meta(get_the_ID(), '_lsr_max_activations', true),
            'desc_tip'    => true,
            'description' => __('Ile unikalnych domen może użyć jednej licencji (0 = bez limitu).', 'license-server'),
        ]);
        // Slug wtyczki (folder wtyczki), potrzebny do aktualizacji
        woocommerce_wp_text_input([
            'id'          => '_lsr_slug',
            'label'       => __('Slug wtyczki', 'license-server'),
            'type'        => 'text',
            'value'       => get_post_meta(get_the_ID(), '_lsr_slug', true),
            'desc_tip'    => true,
            'description' => __('Nazwa folderu wtyczki (slug), np. moja-wtyczka.', 'license-server'),
        ]);
        echo '</div>';
    }

    /**
     * Zapisz wartości z metaboxa produktu.
     *
     * @param \WC_Product $product
     */
    public static function saveFields($product): void
    {
        $productId = $product->get_id();
        $isLicensed = isset($_POST['_lsr_is_licensed']) && $_POST['_lsr_is_licensed'] === 'yes' ? 'yes' : 'no';
        update_post_meta($productId, '_lsr_is_licensed', $isLicensed);
        $maxAct = isset($_POST['_lsr_max_activations']) ? (int) $_POST['_lsr_max_activations'] : '';
        if ($maxAct) {
            update_post_meta($productId, '_lsr_max_activations', $maxAct);
        } else {
            delete_post_meta($productId, '_lsr_max_activations');
        }
        $slug = isset($_POST['_lsr_slug']) ? sanitize_title($_POST['_lsr_slug']) : '';
        if ($slug) {
            update_post_meta($productId, '_lsr_slug', $slug);
        } else {
            delete_post_meta($productId, '_lsr_slug');
        }
    }
}
