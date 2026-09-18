<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Concerns;

use App\Modules\Contacts\Models\Tag;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Polymorphic tags through `taggables`. The pivot carries `tenant_id` so the table can be scoped
 * and protected like every other tenant table.
 */
trait HasTags
{
    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->orderBy('tags.name');
    }

    /**
     * Replaces the tags with the given names, creating unknown tags in the current tenant.
     *
     * @param  list<string>  $names
     */
    public function syncTagNames(array $names): void
    {
        // The pivot row carries the tenant like every tenant table; it is written here rather than
        // with withPivotValue(), which would break eager loading on a blank model instance.
        $pivot = [];
        foreach (Tag::findOrCreateMany($names) as $tag) {
            $pivot[$tag->id] = ['tenant_id' => $this->getAttribute('tenant_id')];
        }

        $this->tags()->sync($pivot);
        $this->unsetRelation('tags');
    }
}
