<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Support\AuditEntryView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit entry (docs/04-domain/audit.md): who (`actor_type`, `actor_id`, and `actor_name` when
 * the actor is a user or API client of this workspace), did what (`action`) to which record
 * (`subject_type`, `subject_id`, `subject_name` when it still exists), and the recorded `changes`:
 * `{field: {old, new}}`, `{old: {…}, new: {…}}`, `{before: …, after: …}` or free-form context.
 *
 * @mixin AuditEntryView
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array{id: string, action: string, actor_type: ActorType, actor_id: string|null, actor_name: string|null, subject_type: string|null, subject_id: string|null, subject_name: string|null, changes: object, ip_address: string|null, user_agent: string|null, request_id: string|null, created_at: string}
     */
    public function toArray(Request $request): array
    {
        /** @var AuditEntryView $view */
        $view = $this->resource;
        $entry = $view->entry;

        return [
            'id' => $entry->id,
            'action' => $entry->action,
            'actor_type' => $entry->actor_type,
            'actor_id' => $entry->actor_id,
            'actor_name' => $view->actorName,
            'subject_type' => $entry->subject_type,
            'subject_id' => $entry->subject_id,
            'subject_name' => $view->subjectName,
            /** @var array<string, mixed> */
            'changes' => (object) $entry->changes,
            'ip_address' => $entry->ip_address,
            'user_agent' => $entry->user_agent,
            'request_id' => $entry->request_id,
            'created_at' => $entry->created_at->toIso8601ZuluString('microsecond'),
        ];
    }
}
