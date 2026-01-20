<?php

namespace App\Services\MDVR;

use Illuminate\Support\Facades\Storage;

class AuthCodeStore
{
    /**
     * Get existing auth code or generate a new one
     */
    public static function get(string $terminalId): string
    {
        $path = "mdvr/auth/{$terminalId}.auth";

        if (Storage::exists($path)) {
            return trim(Storage::get($path));
        }

        // Generate new unique auth code (16 chars hex)
        $authCode = bin2hex(random_bytes(8));

        // Persist
        Storage::put($path, $authCode);

        return $authCode;
    }

    /**
     * Validate incoming auth code against stored one
     */
    public static function validate(string $terminalId, string $authCode): bool
    {
        $storedCode = self::get($terminalId);

        // Strict comparison
        return $storedCode === $authCode;
    }
}
