#!/bin/bash
# fix-license-server.sh

echo "🔧 Naprawiam License Server..."

# 1. Usuń stare pliki
echo "❌ Usuwam duplikaty..."
rm -f includes/bootstrap.php
rm -f includes/Data/Migrations.php
rm -f includes/API/RestRoutes.php
rm -f includes/API/LicenseController.php

# 2. Popraw importy w plikach
echo "✏️ Poprawiam importy..."
find . -name "*.php" -type f -exec sed -i \
  -e 's/use MyShop\\LicenseServer\\Data\\Repositories\\LicenseRepository;/use MyShop\\LicenseServer\\Data\\Repositories\\EnhancedLicenseRepository;/g' \
  -e 's/use MyShop\\LicenseServer\\Data\\Repositories\\ActivationRepository;/use MyShop\\LicenseServer\\Data\\Repositories\\EnhancedActivationRepository;/g' \
  -e 's/LicenseRepository::class/EnhancedLicenseRepository::class/g' \
  -e 's/ActivationRepository::class/EnhancedActivationRepository::class/g' \
  {} \;

# 3. Zamień error_log na logger
echo "📝 Zamieniam error_log na logger..."
find . -name "*.php" -type f -exec sed -i \
  "s/error_log(\['\[\]License Server\['\]\] \. /lsr('logger')->info(/g" \
  {} \;

echo "✅ Gotowe! Sprawdź zmiany i przetestuj wtyczkę."