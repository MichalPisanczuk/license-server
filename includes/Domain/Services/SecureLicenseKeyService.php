<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\Services;

use MyShop\LicenseServer\Domain\Exceptions\LicenseKeyGenerationException;
use MyShop\LicenseServer\Domain\ValueObjects\LicenseKey;

/**
 * Secure License Key Generation and Management Service
 * 
 * Handles cryptographically secure license key generation, validation,
 * and secure storage with proper hashing.
 */
class SecureLicenseKeyService
{
    /** @var string Encryption algorithm */
    private const ENCRYPTION_METHOD = 'AES-256-GCM';
    
    /** @var int License key length */
    private const KEY_LENGTH = 32;
    
    /** @var string Pattern for license key validation */
    private const KEY_PATTERN = '/^[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}$/';
    
    /** @var int Maximum generation attempts */
    private const MAX_GENERATION_ATTEMPTS = 10;

    private string $encryptionKey;
    private string $hashSalt;

    public function __construct()
    {
        $this->encryptionKey = $this->getOrCreateEncryptionKey();
        $this->hashSalt = $this->getOrCreateHashSalt();
    }

    /**
     * Generate cryptographically secure license key.
     *
     * @param int|null $productId Product ID for additional entropy
     * @param int|null $userId User ID for additional entropy
     * @return LicenseKey
     * @throws LicenseKeyGenerationException
     */
    public function generateSecureKey(?int $productId = null, ?int $userId = null): LicenseKey
    {
        $attempts = 0;
        
        while ($attempts < self::MAX_GENERATION_ATTEMPTS) {
            try {
                $rawKey = $this->generateRawKey($productId, $userId);
                $formattedKey = $this->formatKey($rawKey);
                
                // Ensure uniqueness
                if ($this->isKeyUnique($formattedKey)) {
                    return new LicenseKey($formattedKey);
                }
                
                $attempts++;
            } catch (\Exception $e) {
                throw new LicenseKeyGenerationException(
                    'Failed to generate secure license key: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
        
        throw new LicenseKeyGenerationException(
            'Failed to generate unique license key after ' . self::MAX_GENERATION_ATTEMPTS . ' attempts'
        );
    }

    /**
     * Hash license key for secure storage.
     *
     * @param string $licenseKey
     * @return array ['hash' => string, 'verification_hash' => string]
     */
    public function hashLicenseKey(string $licenseKey): array
    {
        // Primary hash for database storage (one-way)
        $primaryHash = hash_hmac('sha256', $licenseKey, $this->hashSalt);
        
        // Verification hash (for API lookups)
        $verificationHash = hash_hmac('sha256', $primaryHash, $this->encryptionKey);
        
        return [
            'hash' => $primaryHash,
            'verification_hash' => $verificationHash
        ];
    }

    /**
     * Verify license key against stored hash.
     *
     * @param string $licenseKey
     * @param string $storedHash
     * @return bool
     */
    public function verifyLicenseKey(string $licenseKey, string $storedHash): bool
    {
        $computedHash = hash_hmac('sha256', $licenseKey, $this->hashSalt);
        return hash_equals($storedHash, $computedHash);
    }

    /**
     * Encrypt sensitive license data.
     *
     * @param string $data
     * @return string Base64 encoded encrypted data with IV
     * @throws LicenseKeyGenerationException
     */
    public function encryptLicenseData(string $data): string
    {
        try {
            $iv = random_bytes(openssl_cipher_iv_length(self::ENCRYPTION_METHOD));
            $tag = '';
            
            $encrypted = openssl_encrypt(
                $data,
                self::ENCRYPTION_METHOD,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($encrypted === false) {
                throw new LicenseKeyGenerationException('Encryption failed');
            }
            
            return base64_encode($iv . $tag . $encrypted);
        } catch (\Exception $e) {
            throw new LicenseKeyGenerationException('Failed to encrypt license data: ' . $e->getMessage());
        }
    }

    /**
     * Decrypt license data.
     *
     * @param string $encryptedData Base64 encoded encrypted data
     * @return string
     * @throws LicenseKeyGenerationException
     */
    public function decryptLicenseData(string $encryptedData): string
    {
        try {
            $data = base64_decode($encryptedData);
            $ivLength = openssl_cipher_iv_length(self::ENCRYPTION_METHOD);
            
            $iv = substr($data, 0, $ivLength);
            $tag = substr($data, $ivLength, 16);
            $encrypted = substr($data, $ivLength + 16);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                self::ENCRYPTION_METHOD,
                $this->encryptionKey,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
            
            if ($decrypted === false) {
                throw new LicenseKeyGenerationException('Decryption failed');
            }
            
            return $decrypted;
        } catch (\Exception $e) {
            throw new LicenseKeyGenerationException('Failed to decrypt license data: ' . $e->getMessage());
        }
    }

    /**
     * Validate license key format.
     *
     * @param string $licenseKey
     * @return bool
     */
    public function isValidKeyFormat(string $licenseKey): bool
    {
        return preg_match(self::KEY_PATTERN, $licenseKey) === 1;
    }

    /**
     * Generate raw cryptographic key material.
     *
     * @param int|null $productId
     * @param int|null $userId
     * @return string
     * @throws \Exception
     */
    private function generateRawKey(?int $productId = null, ?int $userId = null): string
    {
        // Gather entropy sources
        $entropy = [
            random_bytes(16), // Primary entropy
            microtime(true), // Timestamp
            getmypid(), // Process ID
            memory_get_usage(), // Memory usage
            $productId ?? 0,
            $userId ?? 0,
            $_SERVER['SERVER_NAME'] ?? 'unknown',
            wp_get_session_token() // WordPress session token if available
        ];
        
        $entropyString = serialize($entropy);
        
        // Generate secure random bytes
        $randomBytes = random_bytes(self::KEY_LENGTH);
        
        // Combine entropy with random bytes
        $combined = hash('sha256', $entropyString . $randomBytes, true);
        
        return bin2hex(substr($combined, 0, 16));
    }

    /**
     * Format raw key into readable format.
     *
     * @param string $rawKey
     * @return string
     */
    private function formatKey(string $rawKey): string
    {
        $key = strtoupper($rawKey);
        
        // Format as XXXX-XXXX-XXXX-XXXX
        return sprintf(
            '%s-%s-%s-%s',
            substr($key, 0, 8),
            substr($key, 8, 8),
            substr($key, 16, 8),
            substr($key, 24, 8)
        );
    }

    /**
     * Check if generated key is unique in database.
     *
     * @param string $licenseKey
     * @return bool
     */
    private function isKeyUnique(string $licenseKey): bool
    {
        global $wpdb;
        
        $hash = hash_hmac('sha256', $licenseKey, $this->hashSalt);
        $table = $wpdb->prefix . 'lsr_licenses';
        
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE license_key_hash = %s",
                $hash
            )
        );
        
        return (int)$exists === 0;
    }

    /**
     * Get or create encryption key.
     *
     * @return string
     */
    private function getOrCreateEncryptionKey(): string
    {
        $key = get_option('lsr_encryption_key');
        
        if (empty($key) || strlen($key) < 32) {
            $key = bin2hex(random_bytes(32));
            update_option('lsr_encryption_key', $key);
        }
        
        return $key;
    }

    /**
     * Get or create hash salt.
     *
     * @return string
     */
    private function getOrCreateHashSalt(): string
    {
        $salt = get_option('lsr_hash_salt');
        
        if (empty($salt) || strlen($salt) < 32) {
            $salt = bin2hex(random_bytes(32));
            update_option('lsr_hash_salt', $salt);
        }
        
        return $salt;
    }

    /**
     * Securely wipe sensitive data from memory.
     *
     * @param string $data
     */
    public static function secureWipe(string &$data): void
    {
        if (function_exists('sodium_memzero')) {
            sodium_memzero($data);
        } else {
            // Fallback for older PHP versions
            $length = strlen($data);
            for ($i = 0; $i < $length; $i++) {
                $data[$i] = "\0";
            }
        }
    }
}