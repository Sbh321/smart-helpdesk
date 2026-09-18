<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A label shared by tickets, contacts and organisations (docs/04-domain/contacts.md).
 * It lives in Contacts because Tickets may depend on Contacts but not the other way round.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $color
 */
final class Tag extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * Finds or creates tags by name inside the current tenant, in the order given.
     *
     * @param  list<string>  $names
     * @return list<self>
     */
    public static function findOrCreateMany(array $names): array
    {
        $tags = [];

        foreach (array_unique(array_map('trim', $names)) as $name) {
            if ($name === '') {
                continue;
            }

            $tags[] = self::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }

        return $tags;
    }
}
