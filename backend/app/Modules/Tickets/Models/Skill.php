<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal skill table so categories can require skills. The Agents module takes it over and adds
 * agent skills in M2-02.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 */
final class Skill extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'tenant_id'];
}
