<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sign-in for devices without a keyboard (Android TV): the device asks for a pair of codes, shows the short one, and a
 * signed-in user approves it on a phone or computer. Grants live in the cache only and die after TTL seconds.
 * The long `device_code` stays on the device; only its hash is stored, so the cache never holds a usable secret.
 */
class DeviceGrants
{
    public const TTL = 600;

    public const INTERVAL = 5;

    /** No 0/O, 1/I/L or U: the code is read from a TV and typed on a phone. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';

    /** @return array{device_code: string, user_code: string} */
    public static function start(string $deviceName): array
    {
        $deviceCode = Str::random(48);
        $userCode = collect(range(1, 8))->map(fn () => self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)])->implode('');
        $userCode = substr($userCode, 0, 4).'-'.substr($userCode, 4);

        $key = hash('sha256', $deviceCode);
        Cache::put(self::grantKey($key), ['user_code' => $userCode, 'device_name' => $deviceName, 'user_id' => null], self::TTL);
        Cache::put(self::codeKey($userCode), $key, self::TTL);

        return ['device_code' => $deviceCode, 'user_code' => $userCode];
    }

    public static function normalize(string $userCode): string
    {
        $plain = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $userCode));

        return strlen($plain) === 8 ? substr($plain, 0, 4).'-'.substr($plain, 4) : $plain;
    }

    /** Approve the grant behind a user code for this user. False when the code is unknown or expired. */
    public static function approve(string $userCode, int $userId): bool
    {
        $userCode = self::normalize($userCode);
        $key = Cache::get(self::codeKey($userCode));
        $grant = $key ? Cache::get(self::grantKey($key)) : null;

        if (! $grant || $grant['user_id'] !== null) {
            return false;
        }

        $grant['user_id'] = $userId;
        Cache::put(self::grantKey($key), $grant, self::TTL);
        Cache::forget(self::codeKey($userCode));

        return true;
    }

    /**
     * What the device finds when it asks: null = unknown or expired, the grant with `user_id` null = still waiting,
     * or the approved grant, which is removed so the token is handed out once.
     *
     * @return array{user_code: string, device_name: string, user_id: int|null}|null
     */
    public static function poll(string $deviceCode): ?array
    {
        $key = hash('sha256', $deviceCode);
        $grant = Cache::get(self::grantKey($key));

        if ($grant && $grant['user_id'] !== null) {
            Cache::forget(self::grantKey($key));
        }

        return $grant;
    }

    private static function grantKey(string $hash): string
    {
        return 'device-grant:'.$hash;
    }

    private static function codeKey(string $userCode): string
    {
        return 'device-code:'.$userCode;
    }
}
