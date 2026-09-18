<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Queries;

use App\Modules\Contacts\Http\Requests\IndexContactsRequest;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The contact list (docs/07-api/pagination-filtering.md). The tenant scope applies to every query;
 * search is a trigram-backed ILIKE on name and email, ranked by similarity when no sort is given.
 */
final readonly class ContactListQuery
{
    public function __construct(private IndexContactsRequest $request) {}

    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    public function paginate(): LengthAwarePaginator
    {
        $query = Contact::query()->with(['organization', 'tags']);

        $this->applyArchived($query);
        $this->applyOrganizations($query);
        $this->applyTags($query);
        $this->applySearch($query);

        foreach ($this->request->sortColumns() as [$column, $direction]) {
            // Unset timestamps sort last whichever direction is chosen.
            $query->orderByRaw(sprintf('%s %s NULLS LAST', $column, $direction));
        }

        return $query->orderBy('id')->paginate($this->request->perPage())->withQueryString();
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyArchived(Builder $query): void
    {
        $archived = $this->request->filterValues('archived')[0] ?? 'false';

        match ($archived) {
            'true' => $query->whereNotNull('archived_at'),
            'all' => null,
            default => $query->whereNull('archived_at'),
        };
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyOrganizations(Builder $query): void
    {
        $values = $this->request->filterValues('organization_id');

        if ($values === []) {
            return;
        }

        $ids = array_values(array_diff($values, ['none']));
        $includeNone = in_array('none', $values, true);

        $query->where(function (Builder $inner) use ($ids, $includeNone): void {
            if ($ids !== []) {
                $inner->whereIn('organization_id', $ids);
            }

            if ($includeNone) {
                $inner->orWhereNull('organization_id');
            }
        });
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applyTags(Builder $query): void
    {
        $slugs = $this->request->filterValues('tag');

        if ($slugs !== []) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->whereIn('slug', $slugs));
        }
    }

    /**
     * @param  Builder<Contact>  $query
     */
    private function applySearch(Builder $query): void
    {
        $search = $this->request->search();

        if ($search === null) {
            return;
        }

        $pattern = '%'.addcslashes($search, '%_\\').'%';

        $query->where(fn (Builder $inner) => $inner
            ->where('name', 'ilike', $pattern)
            ->orWhere('email', 'ilike', $pattern));

        if (! $this->request->hasExplicitSort()) {
            $query->reorder()->orderByRaw('greatest(similarity(name, ?), similarity(email, ?)) DESC', [$search, $search]);
        }
    }
}
