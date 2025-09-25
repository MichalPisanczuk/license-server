<?php
namespace MyShop\LicenseServer;

use MyShop\LicenseServer\API\RestRoutes;
use MyShop\LicenseServer\WooCommerce\OrderHooks;
use MyShop\LicenseServer\WooCommerce\SubscriptionsHooks;
use MyShop\LicenseServer\WooCommerce\ProductFlags;
use MyShop\LicenseServer\WooCommerce\MyAccountMenu;

/**
 * Główny bootstrap wtyczki. Tworzy i rejestruje wszystkie usługi oraz hooki.
 */
class Bootstrap
{
    /** @var self|null */
    private static ?Bootstrap $instance = null;
    /** @var array<string,object> Kontener prostych serwisów */
    private array $services = [];

    /**
     * Prywatny konstruktor. Tworzy instancje serwisów i rejestruje hooki.
     */
    private function __construct()
    {
        // Inicjalizacja serwisów domenowych i repozytoriów
        $this->initServices();
        // Rejestracja hooków WooCommerce
        $this->registerWooHooks();
        // Rejestracja REST API
        $this->registerRest();
        // Rejestracja My Account menu
        $this->registerMyAccountHooks();
    }

    /**
     * Zainicjuj instancję Bootstrapa.
     *
     * @return static
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Pobierz serwis z kontenera.
     *
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class)
    {
        return $this->services[$class] ?? null;
    }

    /**
     * Inicjalizuje podstawowe serwisy i repozytoria.
     */
    private function initServices(): void
    {
        // Repozytoria
        $this->services[\MyShop\LicenseServer\Data\Repositories\LicenseRepository::class] = new \MyShop\LicenseServer\Data\Repositories\LicenseRepository();
        $this->services[\MyShop\LicenseServer\Data\Repositories\ActivationRepository::class] = new \MyShop\LicenseServer\Data\Repositories\ActivationRepository();
        $this->services[\MyShop\LicenseServer\Data\Repositories\ReleaseRepository::class] = new \MyShop\LicenseServer\Data\Repositories\ReleaseRepository();
        // Serwisy
        // TTL dla podpisanych URL-i można skonfigurować w ustawieniach wtyczki (lsr_signed_url_ttl).
        $ttl = (int) get_option('lsr_signed_url_ttl', 300);
        $this->services[\MyShop\LicenseServer\Domain\Services\SignedUrlService::class] = new \MyShop\LicenseServer\Domain\Services\SignedUrlService($ttl);
        $this->services[\MyShop\LicenseServer\Domain\Services\LicenseService::class] = new \MyShop\LicenseServer\Domain\Services\LicenseService(
            $this->services[\MyShop\LicenseServer\Data\Repositories\LicenseRepository::class],
            $this->services[\MyShop\LicenseServer\Data\Repositories\ActivationRepository::class],
            $this->services[\MyShop\LicenseServer\Data\Repositories\ReleaseRepository::class]
        );
        // Serwis wydawnictw
        $this->services[\MyShop\LicenseServer\Domain\Services\ReleaseService::class] = new \MyShop\LicenseServer\Domain\Services\ReleaseService(
            $this->services[\MyShop\LicenseServer\Data\Repositories\ReleaseRepository::class]
        );
        // Prosty limiter i serwis wiązania domeny – dostępne w kontenerze
        $this->services[\MyShop\LicenseServer\Domain\Services\RateLimiter::class] = new \MyShop\LicenseServer\Domain\Services\RateLimiter();
        $this->services[\MyShop\LicenseServer\Domain\Services\DomainBindingService::class] = new \MyShop\LicenseServer\Domain\Services\DomainBindingService();
    }

    /**
     * Rejestruje hooki WooCommerce.
     */
    private function registerWooHooks(): void
    {
        // Dodaj własne pola produktu (czy jest licencjonowany, limit aktywacji, slug wtyczki)
        ProductFlags::init();
        // Hooki do tworzenia licencji po złożeniu zamówienia
        OrderHooks::init();
        // Hooki synchronizujące statusy subskrypcji z licencjami
        if (class_exists('WC_Subscriptions')) {
            SubscriptionsHooks::init();
        }
    }

    /**
     * Rejestruje trasy REST API.
     */
    private function registerRest(): void
    {
        add_action('rest_api_init', function () {
            (new RestRoutes())->register();
        });
    }

    /**
     * Rejestruje modyfikacje w menu „Moje konto” i shortcode licencji.
     */
    private function registerMyAccountHooks(): void
    {
        MyAccountMenu::init();
        // Zarejestruj dodatkowe składniki: endpoint i shortcode licencji
        \MyShop\LicenseServer\Account\Endpoints::init();
        \MyShop\LicenseServer\Account\Shortcodes::init();
        // Zarejestruj menu administratora
        \MyShop\LicenseServer\Admin\Menu::init();
        // Zarejestruj zadania cykliczne
        \MyShop\LicenseServer\Cron\Heartbeat::init();
    }
}
