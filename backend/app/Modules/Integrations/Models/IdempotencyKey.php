<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The first response to an `Idempotency-Key` of one API client (docs/07-api/conventions.md
 * §Idempotency). Only the SHA-256 of the key is stored; rows live 24 hours.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $client_id
 * @property string $key_hash
 * @property string $request_hash
 * @property string $route
 * @property int $response_status
 * @property array<string, mixed> $response_body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $expires_at
 */
#[UseFactory(IdempotencyKeyFactory::class)]
final class IdempotencyKey extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['id', 'tenant_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
