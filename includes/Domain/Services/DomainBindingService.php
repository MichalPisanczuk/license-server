<?php
namespace MyShop\LicenseServer\Domain\Services;

/**
 * Serwis odpowiedzialny za wiązanie licencji z domeną.
 *
 * W tym przykładzie logika została umieszczona w LicenseService, dlatego ta klasa jest jedynie szkieletem.
 */
class DomainBindingService
{
    public function bindLicenseToDomain(int $licenseId, string $domain): bool
    {
        // Tutaj możesz zaimplementować dodatkową logikę wiązania licencji z domeną
        return true;
    }
}
