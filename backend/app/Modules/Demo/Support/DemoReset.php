<?php

declare(strict_types=1);

namespace App\Modules\Demo\Support;

use App\Modules\Demo\Seeding\DemoCatalogue;
use App\Modules\Demo\Seeding\DemoWorkspaceBuilder;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Telescope\Telescope;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Drops the demo workspaces (`acme`, `globex`) and builds them again (roadmap/11-demo-dataset.md).
 * No other workspace is read or written: each demo workspace is deleted by its id (the foreign keys
 * cascade from `tenants`), and the replay runs inside the new workspaces only.
 */
final readonly class DemoReset
{
    public function __construct(private DemoWorkspaceBuilder $builder, private Clock $clock, private MediaStorage $media) {}

    /**
     * Why this instance must not be reset, or null when it may. Production refuses unless the
     * instance is declared a demo (DEMO_INSTANCE=true) and the caller passed --force.
     */
    public static function refusal(bool $force): ?string
    {
        if (! app()->isProduction()) {
            return null;
        }
        if (! (bool) config('helpdesk.demo.instance')) {
            return 'This is a production instance. Set DEMO_INSTANCE=true only on an instance that exists to be demonstrated.';
        }

        return $force ? null : 'This production instance is a demo instance; pass --force to reset its demo workspaces.';
    }

    /**
     * @return array{acme: string, globex: string, api_client_id: string|null, api_client_secret: string|null, stats: array<string, int>, first_number: int, seconds: array<string, float>}
     */
    public function __invoke(?int $seed = null, ?int $historyTickets = null, bool $attachments = true, bool $rebuildReports = true): array
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        $seconds = [];
        $started = hrtime(true);

        foreach (array_keys(DemoCatalogue::WORKSPACES) as $slug) {
            $this->drop($slug);
        }
        $seconds['drop'] = $this->lap($started);

        $result = $this->builder->build(
            CarbonImmutable::instance($this->clock->now()),
            $seed ?? (int) config('helpdesk.demo.seed', 2026),
            $historyTickets ?? (int) config('helpdesk.demo.history_tickets', 180),
            $attachments,
        );
        $seconds['replay'] = $this->lap($started);

        if ($rebuildReports) {
            foreach (array_keys(DemoCatalogue::WORKSPACES) as $slug) {
                Artisan::call('reports:rebuild', ['--tenant' => $slug]);
            }
        }
        $seconds['reports'] = $this->lap($started);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->clearMailpit();

        return [...$result, 'seconds' => $seconds];
    }

    private function drop(string $slug): void
    {
        $tenant = Tenant::findBySlug($slug);
        if ($tenant === null) {
            return;
        }
        $id = (string) $tenant->getKey();

        // Signed-in browsers of the old workspace sign in again.
        DB::table('sessions')->where('tenant_id', $id)->delete();

        // Stored files live under tenants/{id}/ on the media disk; best effort, the rows go below.
        try {
            $this->media->disk()->deleteDirectory('tenants/'.$id);
        } catch (Throwable $exception) {
            Log::warning('Demo reset: could not remove the workspace files.', ['tenant' => $id, 'error' => $exception->getMessage()]);
        }

        // Every application-plane table references tenants ON DELETE CASCADE; referential actions are
        // not filtered by row-level security, and the change-capture trigger skips rows of a deleted
        // tenant. The tenant row itself is a control-plane row the application role may delete.
        DB::table('tenants')->where('id', $id)->delete();
    }

    /** Development only: the Compose Mailpit starts empty after a reset. */
    private function clearMailpit(): void
    {
        if (app()->isProduction() || app()->runningUnitTests() || config('mail.mailers.smtp.host') !== 'mailpit') {
            return;
        }
        try {
            Http::timeout(2)->delete('http://mailpit:8025/api/v1/messages');
        } catch (Throwable) {
            // Mailpit is optional.
        }
    }

    private function lap(int|float &$started): float
    {
        $now = hrtime(true);
        $seconds = round(($now - $started) / 1e9, 2);
        $started = $now;

        return $seconds;
    }
}
