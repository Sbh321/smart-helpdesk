<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use App\Support\Http\Requests\ListRequest;

/**
 * `GET /v1/media` (docs/07-api/pagination-filtering.md): sort by name, size_bytes or created_at;
 * filter by folder, type group, tag, uploader, trash state and creation date; search the name.
 */
final class IndexMediaRequest extends ListRequest
{
    public const TYPE_GROUPS = ['image', 'document', 'text', 'archive'];

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    protected function sortable(): array
    {
        return ['name', 'size_bytes', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        return [
            // A folder id, or `none` for items outside every folder.
            'folder' => ['regex:/^(none|'.self::UUID.')$/'],
            'type' => ['in:'.implode(',', self::TYPE_GROUPS)],
            'tag' => ['string', 'max:40'],
            'uploader' => ['regex:/^'.self::UUID.'$/'],
            // `false` (default) lists ready items, `true` lists the trash.
            'trashed' => ['in:true,false'],
            'created_between' => ['date_format:Y-m-d'],
            // MVP-SHORTCUT: the first SPA build sends these two names; V1: drop them once the media library is reworked (m2-review frontend rework).
            'folder_id' => ['regex:/^'.self::UUID.'$/'],
            'state' => ['in:ready,trashed'],
        ];
    }

    public function trashed(): bool
    {
        $explicit = $this->filterValues('trashed')[0] ?? null;

        return $explicit !== null ? $explicit === 'true' : ($this->filterValues('state')[0] ?? 'ready') === 'trashed';
    }

    /** @return list<string> */
    public function folders(): array
    {
        return [...$this->filterValues('folder'), ...$this->filterValues('folder_id')];
    }
}
