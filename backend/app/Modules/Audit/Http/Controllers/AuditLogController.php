<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers;

use App\Modules\Audit\Http\Requests\IndexAuditLogsRequest;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Audit\Queries\AuditNames;
use App\Modules\Audit\Support\AuditEntryView;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The workspace's security audit log (docs/04-domain/audit.md). Platform-level entries (no tenant)
 * never appear here: the query names the workspace and row-level security hides them as well.
 */
#[Group('Audit')]
final class AuditLogController
{
    /**
     * List audit entries.
     *
     * Newest first, cursor-paginated (default 25, at most 100 a page).
     */
    #[QueryParameter('cursor', 'Opaque cursor from `meta.next_cursor` of the previous page.', type: 'string')]
    #[QueryParameter('per_page', 'Page size, 1 to 100 (default 25).', type: 'integer')]
    #[QueryParameter('filter[action]', 'Actions, comma-separated: exact (`user.invited`) or every action of a subject (`user.*`).', type: 'string')]
    #[QueryParameter('filter[actor_type]', 'Actor types, comma-separated: user, api_client, system, platform_user.', type: 'string')]
    #[QueryParameter('filter[actor_id]', 'Actor ids (user or API client), comma-separated.', type: 'string')]
    #[QueryParameter('filter[subject_type]', 'Subject types, comma-separated, for example `user`, `role`, `webhook_subscription`.', type: 'string')]
    #[QueryParameter('filter[subject_id]', 'Subject ids, comma-separated.', type: 'string')]
    #[QueryParameter('filter[created_between]', '`YYYY-MM-DD,YYYY-MM-DD` in the workspace time zone, inclusive.', type: 'string')]
    public function index(IndexAuditLogsRequest $request): AnonymousResourceCollection
    {
        $tenantId = (string) tenant()?->getTenantKey();
        $query = AuditLog::query()->where('tenant_id', $tenantId);

        $actions = $request->filterValues('action');
        if ($actions !== []) {
            $query->where(function (Builder $query) use ($actions): void {
                foreach ($actions as $action) {
                    str_ends_with($action, '.*')
                        ? $query->orWhere('action', 'like', addcslashes(substr($action, 0, -1), '%_\\').'%')
                        : $query->orWhere('action', $action);
                }
            });
        }
        foreach (['actor_type', 'actor_id', 'subject_type', 'subject_id'] as $column) {
            $values = $request->filterValues($column);
            if ($values !== []) {
                $query->whereIn($column, $values);
            }
        }
        $range = $request->filterValues('created_between');
        if (count($range) === 2) {
            // Dates are in the workspace time zone, inclusive at both ends.
            $zone = (string) (tenant('timezone') ?? 'UTC');
            $query->where('created_at', '>=', CarbonImmutable::parse($range[0], $zone)->startOfDay()->utc())
                ->where('created_at', '<', CarbonImmutable::parse($range[1], $zone)->addDay()->startOfDay()->utc());
        }

        $page = $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($request->perPage())->withQueryString();
        $names = new AuditNames($page->items(), $tenantId);

        return AuditLogResource::collection(
            $page->through(fn (AuditLog $entry): AuditEntryView => new AuditEntryView($entry, $names->actor($entry), $names->subject($entry))),
        );
    }
}
