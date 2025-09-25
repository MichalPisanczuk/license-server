<?php
namespace MyShop\LicenseServer\WooCommerce;

use MyShop\LicenseServer\Domain\Services\LicenseService;
use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use function MyShop\LicenseServer\lsr;

/**
 * Obsługuje tworzenie licencji po opłaceniu zamówienia WooCommerce.
 */
class OrderHooks
{
    public static function init(): void
    {
        // Tylko gdy zamówienie zostaje oznaczone jako completed.
        add_action('woocommerce_order_status_completed', [self::class, 'generateLicenses'], 10, 1);
    }

    /**
     * Utwórz licencje dla przedmiotów w zamówieniu.
     *
     * @param int $orderId
     */
    public static function generateLicenses(int $orderId): void
    {
        $order = wc_get_order($orderId);
        if (!$order) {
            return;
        }
        // Upewnij się, że to pierwsze wywołanie dla tego zamówienia (unikaj duplikatów)
        /** @var LicenseRepository $repo */
        $repo = lsr(LicenseRepository::class);
        /** @var LicenseService $service */
        $service = lsr(LicenseService::class);
        $service->generateLicensesForOrder($order);
    }
}
