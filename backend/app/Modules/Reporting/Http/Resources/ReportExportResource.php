<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An export and, once `ready`, where to download it. `download_url` is the Media download route,
 * which checks access and redirects to a five-minute signed URL on each request.
 *
 * @mixin ReportExport
 */
final class ReportExportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $ready = $this->state === 'ready' && $this->mediaItem !== null;

        return [
            'id' => $this->id,
            // A catalogue id (`rpt-t01`) or `tickets-list`.
            'report_key' => $this->report_key,
            /** @var 'csv'|'xlsx' */
            'format' => $this->format,
            /** @var 'queued'|'running'|'ready'|'failed' */
            'state' => $this->state,
            'row_count' => $this->row_count,
            // Why a failed export failed: too_large, quota_exceeded, forbidden or failed.
            'error' => $this->error,
            'media_id' => $ready ? $this->mediaItem->id : null,
            'file_name' => $ready ? $this->mediaItem->name : null,
            'size_bytes' => $ready ? $this->mediaItem->size_bytes : null,
            'download_url' => $ready ? route('media.download', ['media' => $this->mediaItem->id]) : null,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
        ];
    }
}
