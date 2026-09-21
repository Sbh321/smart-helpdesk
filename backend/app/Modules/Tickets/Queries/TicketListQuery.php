<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Queries;

use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The ticket list, the reference collection query (docs/07-api/pagination-filtering.md).
 * The tenant scope applies to every query; every sort ends with `id` so pages are stable.
 */
final readonly class TicketListQuery
{
    /** Effective level: a manual override wins over the computed level. */
    private const EFFECTIVE_PRIORITY = 'coalesce(priority_override_level, priority_level)';

    /**
     * The resolution timer of the ticket's latest cycle. Read by table name: Tickets does not import the
     * Sla module, which depends on Tickets (docs/03-architecture/backend.md).
     */
    private const RESOLUTION_TIMER = "SELECT %s FROM ticket_sla_timers t WHERE t.tenant_id = tickets.tenant_id AND t.ticket_id = tickets.id AND t.kind = 'resolution' ORDER BY t.cycle DESC LIMIT 1";

    public function __construct(private TicketListCriteria $criteria) {}

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage)->withQueryString();
    }

    /**
     * The filtered, searched and sorted list without paging; every sort ends with `id`.
     *
     * @return Builder<Ticket>
     */
    public function query(): Builder
    {
        // The latest resolution timer's state and due time travel with each row (list-only fields).
        $query = Ticket::query()->with($this->criteria->includes)
            ->select('tickets.*')
            ->selectRaw('('.sprintf(self::RESOLUTION_TIMER, 't.state').') AS sla_state')
            ->selectRaw('('.sprintf(self::RESOLUTION_TIMER, "CASE WHEN t.state IN ('running', 'warning', 'breached', 'paused') THEN t.due_at END").') AS sla_due_at');

        $this->applyFilters($query);
        $ranked = $this->applySearch($query);

        if (! $ranked || $this->criteria->explicitSort) {
            foreach ($this->criteria->sort as [$column, $direction]) {
                $expression = match ($column) {
                    'priority_level' => self::EFFECTIVE_PRIORITY,
                    // Tickets without a running resolution timer go last either way.
                    'sla_due_at' => '('.sprintf(self::RESOLUTION_TIMER, "CASE WHEN t.state IN ('running', 'warning', 'breached', 'paused') THEN t.due_at END").')',
                    default => $column,
                };
                $nulls = $column === 'sla_due_at' ? ' NULLS LAST' : '';
                $query->orderByRaw("{$expression} {$direction}{$nulls}");
            }
        }

        return $query->orderBy('id');
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $status = $this->criteria->filterValues('status');
        if ($status !== []) {
            if (in_array('active', $status, true)) {
                $status = [...array_diff($status, ['active']), ...array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::active())];
            }
            $query->whereIn('status', array_values(array_unique($status)));
        }

        $priority = $this->criteria->filterValues('priority');
        if ($priority !== []) {
            $query->whereIn(DB::raw(self::EFFECTIVE_PRIORITY), $priority);
        }

        $this->applyNullableIds($query, 'assigned_agent_id', $this->assignees(), 'unassigned');
        $this->applyNullableIds($query, 'team_id', $this->criteria->filterValues('team_id'), 'none');

        foreach (['category_id', 'organization_id', 'contact_id', 'impact', 'urgency', 'number'] as $column) {
            $values = $this->criteria->filterValues($column);
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        $tags = $this->criteria->filterValues('tag');
        if ($tags !== []) {
            $query->whereHas('tags', fn (Builder $tag) => $tag->whereIn('slug', $tags));
        }

        $range = $this->criteria->filterValues('created_between');
        if (count($range) === 2) {
            // Dates are in the workspace time zone, inclusive at both ends.
            $zone = (string) (tenant('timezone') ?? 'UTC');
            $from = CarbonImmutable::parse($range[0], $zone)->startOfDay()->utc();
            $to = CarbonImmutable::parse($range[1], $zone)->addDay()->startOfDay()->utc();
            $query->where('created_at', '>=', $from)->where('created_at', '<', $to);
        }

        $slaStates = $this->criteria->filterValues('sla_state');
        if ($slaStates !== []) {
            $placeholders = implode(', ', array_fill(0, count($slaStates), '?'));
            $query->whereRaw('('.sprintf(self::RESOLUTION_TIMER, 't.state').") IN ({$placeholders})", $slaStates);
        }

        if ($this->criteria->filterValues('has_duplicate_suggestion') === ['true']) {
            $query->whereExists(fn ($exists) => $exists->selectRaw('1')->from('ticket_duplicate_suggestions as s')
                ->whereColumn('s.tenant_id', 'tickets.tenant_id')->whereColumn('s.ticket_id', 'tickets.id')
                ->where('s.decision', 'pending'));
        }

        $since = $this->criteria->filterValues('updated_since')[0] ?? null;
        if ($since !== null) {
            $query->where('updated_at', '>=', CarbonImmutable::parse($since)->utc());
        }
    }

    /**
     * `me` is the signed-in user's Agent profile; a user without one matches no ticket for it.
     *
     * @return list<string>
     */
    private function assignees(): array
    {
        $values = $this->criteria->filterValues('assignee_id');
        if (! in_array('me', $values, true)) {
            return $values;
        }

        $mine = $this->criteria->userId === null ? null : AgentProfile::query()->where('user_id', $this->criteria->userId)->value('id');
        $values = array_values(array_diff($values, ['me']));

        // The nil UUID matches nothing, so "me" never widens the filter when there is no profile.
        return [...$values, is_string($mine) ? $mine : '00000000-0000-0000-0000-000000000000'];
    }

    /**
     * @param  Builder<Ticket>  $query
     * @param  list<string>  $values
     */
    private function applyNullableIds(Builder $query, string $column, array $values, string $nullToken): void
    {
        if ($values === []) {
            return;
        }

        $ids = array_values(array_diff($values, [$nullToken]));
        $includeNull = in_array($nullToken, $values, true);

        $query->where(function (Builder $inner) use ($column, $ids, $includeNull): void {
            if ($ids !== []) {
                $inner->whereIn($column, $ids);
            }
            if ($includeNull) {
                $inner->orWhereNull($column);
            }
        });
    }

    /**
     * Full-text search on the stored vector, the ticket number, and a trigram title match for
     * short terms (ADR-0011). Returns true when results are ordered by relevance.
     *
     * @param  Builder<Ticket>  $query
     */
    private function applySearch(Builder $query): bool
    {
        $search = $this->criteria->search;

        if ($search === null) {
            return false;
        }

        $short = count(preg_split('/\s+/', $search) ?: []) < 3;
        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function (Builder $inner) use ($search, $short, $pattern): void {
            $inner->whereRaw("search_vector @@ websearch_to_tsquery('english', ?)", [$search]);

            if (ctype_digit($search)) {
                $inner->orWhere('number', (int) $search);
            }

            if ($short) {
                $inner->orWhere('title', 'ilike', $pattern);
            }
        });

        if ($this->criteria->explicitSort) {
            return true;
        }

        $query->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('english', ?)) DESC", [$search])
            ->orderByDesc('created_at');

        return true;
    }
}
