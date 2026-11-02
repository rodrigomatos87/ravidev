<?php

declare(strict_types=1);

/**
 * UUID Helper Functions
 */

if (!function_exists('generateUuid')) {
    /**
     * Generate a UUID v4
     */
    function generateUuid(): string
    {
        $data = random_bytes(16);
        
        // Set version to 0100 (UUID v4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

if (!function_exists('isValidUuid')) {
    /**
     * Validate a UUID string
     */
    function isValidUuid(string $uuid): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid) === 1;
    }
}

if (!function_exists('uuidToBytes')) {
    /**
     * Convert UUID string to binary
     */
    function uuidToBytes(string $uuid): string
    {
        return hex2bin(str_replace('-', '', $uuid));
    }
}

if (!function_exists('bytesToUuid')) {
    /**
     * Convert binary to UUID string
     */
    function bytesToUuid(string $bytes): string
    {
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}

if (!function_exists('shortUuid')) {
    /**
     * Generate a short unique identifier (not a true UUID)
     */
    function shortUuid(int $length = 8): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $result = '';
        $max = strlen($chars) - 1;
        
        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, $max)];
        }
        
        return $result;
    }
}
