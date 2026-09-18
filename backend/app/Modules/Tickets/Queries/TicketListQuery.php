<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Queries;

use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Http\Requests\IndexTicketsRequest;
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

    public function __construct(private IndexTicketsRequest $request) {}

    /**
     * @return LengthAwarePaginator<int, Ticket>
     */
    public function paginate(): LengthAwarePaginator
    {
        $query = Ticket::query()->with($this->request->includes());

        $this->applyFilters($query);
        $ranked = $this->applySearch($query);

        if (! $ranked || $this->request->hasExplicitSort()) {
            foreach ($this->request->sortColumns() as [$column, $direction]) {
                $expression = $column === 'priority_level' ? self::EFFECTIVE_PRIORITY : $column;
                $query->orderByRaw("{$expression} {$direction}");
            }
        }

        return $query->orderBy('id')->paginate($this->request->perPage())->withQueryString();
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    private function applyFilters(Builder $query): void
    {
        $status = $this->request->filterValues('status');
        if ($status !== []) {
            if (in_array('active', $status, true)) {
                $status = [...array_diff($status, ['active']), ...array_map(fn (TicketStatus $s): string => $s->value, TicketStatus::active())];
            }
            $query->whereIn('status', array_values(array_unique($status)));
        }

        $priority = $this->request->filterValues('priority');
        if ($priority !== []) {
            $query->whereIn(DB::raw(self::EFFECTIVE_PRIORITY), $priority);
        }

        $this->applyNullableIds($query, 'assigned_agent_id', $this->request->filterValues('assignee_id'), 'unassigned');
        $this->applyNullableIds($query, 'team_id', $this->request->filterValues('team_id'), 'none');

        foreach (['category_id', 'organization_id', 'contact_id', 'impact', 'urgency', 'number'] as $column) {
            $values = $this->request->filterValues($column);
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }

        $tags = $this->request->filterValues('tag');
        if ($tags !== []) {
            $query->whereHas('tags', fn (Builder $tag) => $tag->whereIn('slug', $tags));
        }

        $range = $this->request->filterValues('created_between');
        if (count($range) === 2) {
            // Dates are in the workspace time zone, inclusive at both ends.
            $zone = (string) (tenant('timezone') ?? 'UTC');
            $from = CarbonImmutable::parse($range[0], $zone)->startOfDay()->utc();
            $to = CarbonImmutable::parse($range[1], $zone)->addDay()->startOfDay()->utc();
            $query->where('created_at', '>=', $from)->where('created_at', '<', $to);
        }

        $since = $this->request->filterValues('updated_since')[0] ?? null;
        if ($since !== null) {
            $query->where('updated_at', '>=', CarbonImmutable::parse($since)->utc());
        }
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
        $search = $this->request->search();

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

        if ($this->request->hasExplicitSort()) {
            return true;
        }

        $query->orderByRaw("ts_rank_cd(search_vector, websearch_to_tsquery('english', ?)) DESC", [$search])
            ->orderByDesc('created_at');

        return true;
    }
}
