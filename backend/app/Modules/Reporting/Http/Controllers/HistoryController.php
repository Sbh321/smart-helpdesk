<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controllers;

use App\Models\User;
use App\Modules\Reporting\Domain\History\AsOfView;
use App\Modules\Reporting\Domain\History\ChangeReplayer;
use App\Modules\Reporting\Http\Resources\AsOfResource;
use App\Modules\Reporting\Http\Resources\EntityChangeResource;
use App\Modules\Reporting\Models\EntityChange;
use App\Modules\Reporting\Support\HistorySubjects;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Change log and point-in-time view of a recorded record (ADR-0022 §5,
 * docs/05-algorithms/history-and-time-analytics.md §3). `{type}` is the table name, for example
 * `tickets`, `contacts` or `organizations`.
 */
#[Group('History')]
final class HistoryController
{
    /** Newest change first, 50 a page. */
    #[QueryParameter('cursor', 'The `next_cursor` of the previous page.', type: 'string')]
    public function index(Request $request, string $type, string $id): AnonymousResourceCollection
    {
        $this->authorize($request, $type);

        $changes = EntityChange::query()->where('entity_type', $type)->where('entity_id', $id)
            ->orderByDesc('version')->cursorPaginate(50);
        abort_if($changes->isEmpty() && $request->query('cursor') === null && ! $this->current($type, $id), 404);

        return EntityChangeResource::collection($changes);
    }

    /**
     * The record's recorded attributes at an instant, and which of them differ from now. `exists` is
     * false for an instant before the record was created (or after it was deleted).
     */
    #[QueryParameter('at', 'ISO 8601 instant.', required: true, type: 'string')]
    public function asOf(Request $request, string $type, string $id, ChangeReplayer $replayer): AsOfResource
    {
        $this->authorize($request, $type);

        $at = $request->query('at');
        try {
            $instant = CarbonImmutable::parse(is_string($at) ? $at : '');
        } catch (Throwable) {
            throw ValidationException::withMessages(['at' => 'Give an ISO 8601 date and time.']);
        }
        if (! is_string($at) || $at === '') {
            throw ValidationException::withMessages(['at' => 'Give an ISO 8601 date and time.']);
        }

        $current = $this->current($type, $id);
        $changes = EntityChange::query()->where('entity_type', $type)->where('entity_id', $id)->orderBy('version')->get();
        abort_if($current === null && $changes->isEmpty(), 404);

        $then = $replayer->asOf($current, $changes->map(fn (EntityChange $change) => $change->toRecordedChange())->all(), $instant);
        $hidden = array_flip(HistorySubjects::hiddenColumns($type));
        $then = $then === null ? null : array_diff_key($then, $hidden);
        $now = $current === null ? null : array_diff_key($current, $hidden);

        $differences = [];
        foreach (array_unique([...array_keys($then ?? []), ...array_keys($now ?? [])]) as $attribute) {
            $before = $then[$attribute] ?? null;
            $after = $now[$attribute] ?? null;
            if ($before !== $after) {
                $differences[$attribute] = ['then' => $before, 'now' => $after];
            }
        }

        /** @var array<string, array{then: mixed, now: mixed}> $differences */
        return new AsOfResource(new AsOfView(
            $type,
            $id,
            $instant->utc()->toIso8601ZuluString('microsecond'),
            $then,
            $differences,
            $changes->filter(fn (EntityChange $change): bool => $change->occurred_at > $instant)->count(),
        ));
    }

    private function authorize(Request $request, string $type): void
    {
        abort_unless(HistorySubjects::exists($type), 404);
        /** @var User $user */
        $user = $request->user();
        if (! HistorySubjects::allows($user, $type)) {
            throw new AuthorizationException;
        }
    }

    /**
     * The current row as the capture trigger sees it (`to_jsonb`), so current and recorded values share
     * one format; null when the record does not exist (any more) in this workspace.
     *
     * @return array<string, mixed>|null
     */
    private function current(string $type, string $id): ?array
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $id)) {
            return null;
        }
        // $type is one of the fixed HistorySubjects keys, never user text.
        $row = DB::selectOne(
            sprintf('SELECT to_jsonb(t) AS row FROM %s t WHERE t.tenant_id = ? AND t.id = ?', $type),
            [tenant()?->getTenantKey(), $id],
        );

        return $row === null ? null : (array) json_decode((string) $row->row, true);
    }
}
