<?php

declare(strict_types=1);

namespace App\Modules\Identity\Jobs;

use App\Models\User;
use App\Modules\Identity\Notifications\WorkspaceReminder;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Notification;

/**
 * The workspace finder (M5-02, docs/07-api/authentication.md §Workspace finder).
 *
 * A central job: it visits every active workspace inside that workspace's own tenancy context, so
 * row-level security stays in force and no query ever reads users across tenants. It mails the address
 * once, listing each workspace where it belongs to an active user, and sends nothing when there are none.
 *
 * MVP-SHORTCUT: one query per active workspace, fine for tens of workspaces; V1: a central, RLS-exempt
 * address directory kept in step with the users table (V1-ID-01).
 */
final class SendWorkspaceReminder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $email)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $workspaces = [];

        foreach (Tenant::active()->orderBy('name')->cursor() as $tenant) {
            $belongs = (bool) $tenant->run(fn (): bool => User::query()
                ->whereRaw('lower(email) = lower(?)', [$this->email])
                ->where('is_active', true)
                ->exists());

            if ($belongs) {
                $workspaces[] = ['name' => (string) $tenant->name, 'slug' => (string) $tenant->slug];
            }
        }

        if ($workspaces === []) {
            return;
        }

        Notification::route('mail', $this->email)->notify(new WorkspaceReminder($workspaces));
    }
}
