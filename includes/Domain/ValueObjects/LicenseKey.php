<?php
declare(strict_types=1);

namespace MyShop\LicenseServer\Domain\ValueObjects;

use MyShop\LicenseServer\Domain\Exceptions\InvalidLicenseKeyException;

/**
 * License Key Value Object
 * 
 * Immutable value object representing a license key with validation.
 */
final class LicenseKey
{
    private const VALID_PATTERN = '/^[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}-[A-Z0-9]{8}$/';
    private const KEY_PARTS = 4;
    private const PART_LENGTH = 8;

    private string $value;

    /**
     * @param string $key
     * @throws InvalidLicenseKeyException
     */
    public function __construct(string $key)
    {
        $normalizedKey = $this->normalize($key);
        
        if (!$this->isValid($normalizedKey)) {
            throw new InvalidLicenseKeyException("Invalid license key format: {$key}");
        }
        
        $this->value = $normalizedKey;
    }

    /**
     * Get the license key value.
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Get the license key as string.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Check if two license keys are equal.
     *
     * @param LicenseKey $other
     * @return bool
     */
    public function equals(LicenseKey $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Get masked version for display (show only first and last parts).
     *
     * @return string
     */
    public function getMasked(): string
    {
        $parts = explode('-', $this->value);
        
        if (count($parts) !== self::KEY_PARTS) {
            return '****-****-****-****';
        }
        
        return sprintf(
            '%s-****-****-%s',
            $parts[0],
            $parts[3]
        );
    }

    /**
     * Get the first part of the key (for indexing).
     *
     * @return string
     */
    public function getPrefix(): string
    {
        return substr($this->value, 0, 8);
    }

    /**
     * Get the last part of the key (for verification).
     *
     * @return string
     */
    public function getSuffix(): string
    {
        return substr($this->value, -8);
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'full' => $this->value,
            'masked' => $this->getMasked(),
            'prefix' => $this->getPrefix(),
            'suffix' => $this->getSuffix(),
            'parts' => explode('-', $this->value)
        ];
    }

    /**
     * Create from array (for unserialization).
     *
     * @param array $data
     * @return static
     * @throws InvalidLicenseKeyException
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['full'])) {
            throw new InvalidLicenseKeyException('Missing full license key in array data');
        }
        
        return new self($data['full']);
    }

    /**
     * Normalize license key (uppercase, remove spaces, add dashes).
     *
     * @param string $key
     * @return string
     */
    private function normalize(string $key): string
    {
        // Remove all non-alphanumeric characters
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $key);
        
        if ($clean === null) {
            return '';
        }
        
        // Convert to uppercase
        $clean = strtoupper($clean);
        
        // Add dashes every 8 characters
        if (strlen($clean) === 32) {
            return sprintf(
                '%s-%s-%s-%s',
                substr($clean, 0, 8),
                substr($clean, 8, 8),
                substr($clean, 16, 8),
                substr($clean, 24, 8)
            );
        }
        
        return $clean;
    }

    /**
     * Validate license key format.
     *
     * @param string $key
     * @return bool
     */
    private function isValid(string $key): bool
    {
        // Check pattern
        if (preg_match(self::VALID_PATTERN, $key) !== 1) {
            return false;
        }
        
        // Additional validation: ensure no sequential characters
        $parts = explode('-', $key);
        
        foreach ($parts as $part) {
            if ($this->hasSequentialChars($part) || $this->hasRepeatingChars($part)) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Check for sequential characters (weak keys).
     *
     * @param string $part
     * @return bool
     */
    private function hasSequentialChars(string $part): bool
    {
        $sequentialCount = 0;
        
        for ($i = 0; $i < strlen($part) - 1; $i++) {
            $current = ord($part[$i]);
            $next = ord($part[$i + 1]);
            
            if (abs($current - $next) === 1) {
                $sequentialCount++;
                if ($sequentialCount >= 3) { // More than 3 sequential chars
                    return true;
                }
            } else {
                $sequentialCount = 0;
            }
        }
        
        return false;
    }

    /**
     * Check for too many repeating characters.
     *
     * @param string $part
     * @return bool
     */
    private function hasRepeatingChars(string $part): bool
    {
        $charCounts = array_count_values(str_split($part));
        
        foreach ($charCounts as $count) {
            if ($count > 4) { // More than 4 of the same character
                return true;
            }
        }
        
        return false;
    }

    /**
     * Generate checksum for the license key.
     *
     * @return string
     */
    public function getChecksum(): string
    {
        return hash('crc32', $this->value);
    }

    /**
     * Verify checksum.
     *
     * @param string $expectedChecksum
     * @return bool
     */
    public function verifyChecksum(string $expectedChecksum): bool
    {
        return hash_equals($this->getChecksum(), $expectedChecksum);
    }
}