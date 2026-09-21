<?php

declare(strict_types=1);

namespace App\Modules\Tickets\Http\Resources;

use App\Modules\Tickets\Domain\DuplicatePreviewMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DuplicatePreviewMatch */
final class DuplicatePreviewMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ticket_id' => $this->ticketId,
            'number' => $this->number,
            'title' => $this->title,
            'score' => $this->score,
            'shared_words' => $this->sharedWords,
        ];
    }
}
