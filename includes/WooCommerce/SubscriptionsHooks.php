<?php
namespace MyShop\LicenseServer\WooCommerce;

use MyShop\LicenseServer\Data\Repositories\EnhancedLicenseRepository;
use function MyShop\LicenseServer\lsr;

/**
 * Synchronizacja statusu subskrypcji WooCommerce z licencją.
 */
class SubscriptionsHooks
{
    public static function init(): void
    {
        // Zmiana statusu subskrypcji
        add_action('woocommerce_subscription_status_updated', [self::class, 'onStatusUpdated'], 10, 3);
        // Płatność za odnowienie zakończona sukcesem
        add_action('woocommerce_subscription_renewal_payment_complete', [self::class, 'onRenewalPaymentComplete'], 10, 2);
        // Płatność za odnowienie nieudana
        add_action('woocommerce_subscription_renewal_payment_failed', [self::class, 'onRenewalPaymentFailed'], 10, 1);
    }

    /**
     * Aktualizuj status licencji na podstawie statusu subskrypcji.
     *
     * @param \WC_Subscription $subscription
     * @param string           $oldStatus
     * @param string           $newStatus
     */
    public static function onStatusUpdated($subscription, $oldStatus, $newStatus): void
    {
        $subId = $subscription->get_id();
        /** @var LicenseRepository $repo */
        $repo = lsr(EnhancedLicenseRepository::class);
        $license = $repo->findBySubscriptionId($subId);
        if (!$license) {
            return;
        }
        $data = [];
        // Określ status licencji
        if (in_array($newStatus, ['active','pending-cancel'], true)) {
            $data['status'] = 'active';
        } else {
            $data['status'] = 'inactive';
        }
        // Aktualizuj datę wygasania
        $endTime = $subscription->get_time('end');
        if ($endTime) {
            $data['expires_at'] = date('Y-m-d H:i:s', $endTime);
        }
        $repo->update((int) $license['id'], $data);
    }

    /**
     * Płatność odnowienia powiodła się – przedłuż licencję i ustaw status aktywny.
     *
     * @param \WC_Order        $order
     * @param \WC_Subscription $subscription
     */
    public static function onRenewalPaymentComplete($order, $subscription): void
    {
        $subId = $subscription->get_id();
        /** @var LicenseRepository $repo */
        $repo = lsr(EnhancedLicenseRepository::class);
        $license = $repo->findBySubscriptionId($subId);
        if (!$license) {
            return;
        }
        $endTime = $subscription->get_time('end');
        $repo->update((int) $license['id'], [
            'status'     => 'active',
            'expires_at' => $endTime ? date('Y-m-d H:i:s', $endTime) : null,
        ]);
    }

    /**
     * Nieudana płatność odnowienia – ustaw licencję jako nieaktywną.
     *
     * @param \WC_Subscription $subscription
     */
    public static function onRenewalPaymentFailed($subscription): void
    {
        $subId = $subscription->get_id();
        /** @var LicenseRepository $repo */
        $repo = lsr(EnhancedLicenseRepository::class);
        $license = $repo->findBySubscriptionId($subId);
        if (!$license) {
            return;
        }
        $repo->update((int) $license['id'], [
            'status' => 'inactive',
        ]);
    }
}
