<?php

declare(strict_types=1);

namespace App\Support\Settings;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide settings (`platform_settings`, one JSON object per key), read by any module and
 * written from the console (ADR-0025). Values merge over the defaults given by the caller, so a key
 * missing from the table still reads sensibly. Cached for a minute; writes forget the cache.
 */
final class PlatformSettings
{
    private const CACHE_SECONDS = 60;

    /**
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public function get(string $key, array $defaults = []): array
    {
        /** @var array<string, mixed> $stored */
        $stored = Cache::remember(self::cacheKey($key), self::CACHE_SECONDS, function () use ($key): array {
            $value = DB::table('platform_settings')->where('key', $key)->value('value');
            $decoded = is_string($value) ? json_decode($value, true) : null;

            return is_array($decoded) ? $decoded : [];
        });

        return array_replace($defaults, $stored);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function put(string $key, array $value): void
    {
        DB::table('platform_settings')->upsert(
            [['key' => $key, 'value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => now()]],
            ['key'],
            ['value', 'updated_at'],
        );
        Cache::forget(self::cacheKey($key));
    }

    private static function cacheKey(string $key): string
    {
        return "platform-settings:{$key}";
    }
}
