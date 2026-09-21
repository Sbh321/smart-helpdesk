<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A PostgreSQL `text[]` column as a list of strings. Values are written as a quoted array literal,
 * so commas, quotes and braces inside an element cannot break the literal.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresTextArray implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '{}' || $value === '') {
            return [];
        }

        $items = str_getcsv(substr($value, 1, -1), ',', '"', '\\');

        return array_map(fn (?string $item): string => (string) $item, $items);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $items = array_map(
            fn (string $item): string => '"'.addcslashes($item, '"\\').'"',
            $value,
        );

        return '{'.implode(',', $items).'}';
    }
}
