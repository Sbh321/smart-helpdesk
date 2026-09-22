<?php

declare(strict_types=1);

namespace App\Modules\Audit\Queries;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Readable names for the actors and subjects of one page of audit entries: one query per kind of
 * record on the page, inside the current workspace (explicit tenant predicate and row-level security).
 * A record that no longer exists has no name; the viewer then shows its type and a short id.
 */
final class AuditNames
{
    /**
     * Subject type (snake-case class name) → table and the SQL expression that names a row. Types that
     * are not listed (tenant settings, platform records) have no name.
     */
    private const SUBJECTS = [
        'user' => ['users', 'name'],
        'role' => ['roles', 'name'],
        'api_client' => ['oauth_clients', 'name'],
        'webhook_subscription' => ['webhook_subscriptions', 'name'],
        'sla_policy' => ['sla_policies', 'name'],
        'ticket' => ['tickets', "'#' || number"],
        'contact' => ['contacts', 'name'],
        'organization' => ['organizations', 'name'],
        'team' => ['teams', 'name'],
        'category' => ['categories', 'name'],
        'skill' => ['skills', 'name'],
        'agent_profile' => ['agent_profiles', '(SELECT u.name FROM users u WHERE u.id = agent_profiles.user_id)'],
    ];

    /** @var array<string, array<string, string>> type → id → name */
    private array $names = [];

    /**
     * @param  iterable<AuditLog>  $entries
     */
    public function __construct(iterable $entries, private readonly string $tenantId)
    {
        $wanted = [];
        foreach ($entries as $entry) {
            if ($entry->actor_id !== null && $entry->actor_type === ActorType::User) {
                $wanted['user'][] = $entry->actor_id;
            }
            if ($entry->actor_id !== null && $entry->actor_type === ActorType::ApiClient) {
                $wanted['api_client'][] = $entry->actor_id;
            }
            if ($entry->subject_type !== null && $entry->subject_id !== null && isset(self::SUBJECTS[$entry->subject_type])) {
                $wanted[$entry->subject_type][] = $entry->subject_id;
            }
        }

        foreach ($wanted as $type => $ids) {
            $this->names[$type] = $this->lookup($type, array_values(array_unique($ids)));
        }
    }

    public function actor(AuditLog $entry): ?string
    {
        return match ($entry->actor_type) {
            ActorType::User => $this->names['user'][$entry->actor_id] ?? null,
            ActorType::ApiClient => $this->names['api_client'][$entry->actor_id] ?? null,
            default => null,
        };
    }

    public function subject(AuditLog $entry): ?string
    {
        return $entry->subject_type === null ? null : ($this->names[$entry->subject_type][$entry->subject_id] ?? null);
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private function lookup(string $type, array $ids): array
    {
        [$table, $label] = self::SUBJECTS[$type];

        // Both come from the constant above, never from the request. Global roles have no tenant.
        $rows = DB::table($table)
            ->selectRaw(sprintf('id::text AS id, (%s)::text AS label', $label))
            ->whereIn('id', $ids)
            ->where(fn ($query) => $type === 'role'
                ? $query->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id')
                : $query->where('tenant_id', $this->tenantId))
            ->get();

        $names = [];
        foreach ($rows as $row) {
            if ($row->label !== null && $row->label !== '') {
                $names[(string) $row->id] = (string) $row->label;
            }
        }

        return $names;
    }
}
