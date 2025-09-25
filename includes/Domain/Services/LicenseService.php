<?php
namespace MyShop\LicenseServer\Domain\Services;

use MyShop\LicenseServer\Data\Repositories\LicenseRepository;
use MyShop\LicenseServer\Data\Repositories\ActivationRepository;
use MyShop\LicenseServer\Data\Repositories\ReleaseRepository;
use function MyShop\LicenseServer\normalize_domain;
use function MyShop\LicenseServer\generate_license_key;
use function MyShop\LicenseServer\hash_ip;

/**
 * Logika biznesowa licencji – tworzenie, aktywacja, walidacja, statusy.
 */
class LicenseService
{
    private LicenseRepository $licenses;
    private ActivationRepository $activations;
    private ReleaseRepository $releases;

    public function __construct(LicenseRepository $licenses, ActivationRepository $activations, ReleaseRepository $releases)
    {
        $this->licenses    = $licenses;
        $this->activations = $activations;
        $this->releases    = $releases;
    }

    /**
     * Utwórz licencje dla zrealizowanego zamówienia.
     * Sprawdza meta produktu `_is_licensed` i tworzy licencję tylko dla takich produktów.
     *
     * @param \WC_Order $order
     * @return void
     */
    public function generateLicensesForOrder(\WC_Order $order): void
    {
        $items = $order->get_items();
        $userId = $order->get_user_id();
        $orderId = $order->get_id();
        foreach ($items as $item) {
            $productId = (int) $item->get_product_id();
            // Sprawdź meta produktu
            $isLicensed = get_post_meta($productId, '_lsr_is_licensed', true);
            if ($isLicensed !== 'yes') {
                continue;
            }
            // Jeśli licencja dla tego zamówienia i produktu już istnieje – pomiń
            if ($this->licenses->existsByOrderAndProduct($orderId, $productId)) {
                continue;
            }
            // Parametry licencji z metadanych produktu
            $maxActivations = get_post_meta($productId, '_lsr_max_activations', true);
            $maxActivations = $maxActivations ? (int) $maxActivations : null;
            // Jeżeli korzystamy z subskrypcji, pobierz ID subskrypcji
            $subscriptionId = null;
            if (class_exists('WC_Subscriptions')) {
                $subscriptions = wcs_get_subscriptions_for_order($order, ['order_type' => 'any']);
                if (!empty($subscriptions)) {
                    /** @var \WC_Subscription $sub */
                    $sub = array_shift($subscriptions);
                    $subscriptionId = $sub->get_id();
                }
            }
            // Generuj klucz
            $licenseKey = generate_license_key();
            // Ustal status i datę ważności: jeśli subskrypcja, wygaśnięcie = end date
            $status     = 'active';
            $expiresAt  = null;
            $graceUntil = null;
            if ($subscriptionId) {
                $sub = wcs_get_subscription($subscriptionId);
                $expiresAt = $sub ? $sub->get_time('end') : null;
                // Status licencji zależy od statusu subskrypcji
                $subStatus = $sub ? $sub->get_status() : 'cancelled';
                if (!in_array($subStatus, ['active', 'pending-cancel'], true)) {
                    $status = 'inactive';
                }
            }
            // Stwórz licencję
            $this->licenses->create([
                'user_id'         => $userId,
                'product_id'      => $productId,
                'order_id'        => $orderId,
                'subscription_id' => $subscriptionId,
                'license_key'     => $licenseKey,
                'status'          => $status,
                'expires_at'      => $expiresAt ? date('Y-m-d H:i:s', $expiresAt) : null,
                'grace_until'     => $graceUntil,
                'max_activations' => $maxActivations,
            ]);
        }
    }

    /**
     * Aktywuj licencję dla danej domeny.
     * Sprawdza limit aktywacji i status licencji.
     *
     * @param string $licenseKey
     * @param string $domain
     * @param string|null $ip
     * @return array<string,mixed>
     */
    public function activateLicense(string $licenseKey, string $domain, ?string $ip): array
    {
        $license = $this->licenses->findByKey($licenseKey);
        if (!$license) {
            return ['success' => false, 'reason' => 'not_found'];
        }
        // Normalizuj domenę
        $normalized = normalize_domain($domain);
        $now = time();
        // Sprawdź datę ważności
        if ($license['expires_at'] !== null && strtotime($license['expires_at']) < $now) {
            // Możemy sprawdzić okres karencji
            if ($license['grace_until'] && strtotime($license['grace_until']) >= $now) {
                // wciąż w grace, status = grace
                $status = 'grace';
            } else {
                // Licencja wygasła
                return ['success' => false, 'reason' => 'expired'];
            }
        }
        // Sprawdź status licencji
        if ($license['status'] !== 'active') {
            return ['success' => false, 'reason' => 'inactive'];
        }
        // Sprawdź limit aktywacji. Jeśli domena należy do listy developerskiej, limit nie jest liczony.
        $max = $license['max_activations'];
        if ($max !== null) {
            // Odczytaj listę domen developerskich z opcji. Akceptuje przecinki lub nowe linie.
            $devList = get_option('lsr_developer_domains', '');
            $isDev   = false;
            if (!empty($devList)) {
                $patterns = preg_split('/[\r\n,]+/', (string) $devList) ?: [];
                foreach ($patterns as $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern === '') {
                        continue;
                    }
                    // Jeśli domena jest dokładnie taka sama jak wzorzec albo kończy się na ".{wzorzec}", uznaj ją za developerską.
                    if ($normalized === $pattern ||
                        (strlen($normalized) > strlen($pattern) + 1 && substr($normalized, -strlen($pattern) - 1) === '.' . $pattern)
                    ) {
                        $isDev = true;
                        break;
                    }
                }
            }
            if (!$isDev) {
                $count  = $this->activations->countActivations((int) $license['id']);
                $exists = $this->activationExists((int) $license['id'], $normalized);
                if (!$exists && $count >= $max) {
                    return ['success' => false, 'reason' => 'activation_limit'];
                }
            }
        }
        // Dodaj lub aktualizuj aktywację
        $ipHash = $ip ? hash_ip($ip) : null;
        $this->activations->recordActivation((int) $license['id'], $normalized, $ipHash);
        return [
            'success'    => true,
            'license_id' => (int) $license['id'],
            'status'     => $license['status'],
            'expires_at' => $license['expires_at'],
        ];
    }

    /**
     * Waliduj licencję (używane przez heartbeat klienta). Aktualizuje last_seen_at dla domeny.
     *
     * @param string $licenseKey
     * @param string $domain
     * @return array<string,mixed>
     */
    public function validateLicense(string $licenseKey, string $domain): array
    {
        $license = $this->licenses->findByKey($licenseKey);
        if (!$license) {
            return ['success' => false, 'reason' => 'not_found'];
        }
        $now = time();
        $status = $license['status'];
        $reason = 'ok';
        // Sprawdź wygaśnięcie
        if ($license['expires_at'] !== null && strtotime($license['expires_at']) < $now) {
            if ($license['grace_until'] && strtotime($license['grace_until']) >= $now) {
                $status = 'grace';
                $reason = 'grace';
            } else {
                $status = 'inactive';
                $reason = 'expired';
            }
        }
        if ($license['status'] !== 'active') {
            $status = 'inactive';
            if ($reason === 'ok') {
                $reason = 'inactive';
            }
        }
        // Zaktualizuj last_seen dla domeny
        $normalized = normalize_domain($domain);
        $this->activations->recordActivation((int) $license['id'], $normalized, null);
        return [
            'success'    => $status === 'active' || $status === 'grace',
            'status'     => $status,
            'reason'     => $reason,
            'expires_at' => $license['expires_at'],
            'product_id' => (int) $license['product_id'],
            'license_id' => (int) $license['id'],
        ];
    }

    /**
     * Sprawdź, czy aktywacja dla domeny istnieje.
     *
     * @param int $licenseId
     * @param string $domain
     * @return bool
     */
    private function activationExists(int $licenseId, string $domain): bool
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lsr_activations';
        $row   = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE license_id = %d AND domain = %s", $licenseId, $domain)
        );
        return (bool) $row;
    }
}
