<?php

declare(strict_types=1);

namespace App\Modules\Media\Console;

use App\Modules\Media\Actions\PurgeMedia;
use App\Modules\Media\Exceptions\MediaInUse;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Throwable;

final class CleanupMedia extends Command
{
    private const PENDING_HOURS = 1;

    private const TRASH_DAYS = 30;

    protected $signature = 'media:cleanup';

    protected $description = 'Remove stale pending uploads and staged objects, and purge unlinked trash older than 30 days';

    public function handle(PurgeMedia $purge, MediaStorage $storage, Clock $clock): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        $failures = 0;
        foreach (Tenant::active()->cursor() as $tenant) {
            try {
                $tenant->run(function () use ($purge, $storage, $clock): void {
                    $now = $clock->now();
                    $stale = MediaItem::query()
                        ->whereIn('state', ['pending', 'failed'])
                        ->where('created_at', '<', $now->subHours(self::PENDING_HOURS));
                    $trash = MediaItem::query()
                        ->where('state', 'trashed')
                        ->where('trashed_at', '<', $now->subDays(self::TRASH_DAYS))
                        ->whereDoesntHave('links');

                    foreach ([$stale, $trash] as $query) {
                        foreach ($query->orderBy('id')->pluck('id') as $id) {
                            try {
                                $purge((string) $id);
                            } catch (MediaInUse) {
                                // Linked between the query and the lock; it stays until it is unlinked.
                            }
                        }
                    }

                    // Objects without a pending row: abandoned PUTs and replays of a signed URL.
                    $orphans = $storage->staleStagedKeys($now->subHours(self::PENDING_HOURS)->getTimestamp());
                    if ($orphans !== []) {
                        $storage->delete($orphans);
                    }
                });
            } catch (Throwable $exception) {
                // One workspace (or an unreachable bucket) must not stop the others.
                $failures++;
                Log::error('media.cleanup.failed', ['tenant_id' => $tenant->getTenantKey(), 'error' => $exception->getMessage()]);
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
