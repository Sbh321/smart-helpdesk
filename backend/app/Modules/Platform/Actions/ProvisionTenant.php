<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Models\User;
use App\Modules\Audit\Audit;
use App\Modules\Billing\Actions\StartSubscription;
use App\Modules\Billing\Models\Plan;
use App\Modules\Identity\Models\Invitation;
use App\Modules\Identity\Notifications\UserInvitation;
use App\Modules\Media\Models\MediaFolder;
use App\Modules\Sla\Actions\EnsureDefaultSlaPolicy;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantCounter;
use App\Modules\Tickets\Models\Category;
use App\Support\Time\Clock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Creates a workspace with everything it needs to be usable (docs/03-architecture/tenancy.md
 * §Provisioning). Idempotent per slug: running it again fills in only what is missing.
 *
 * Roles are global defaults (M1-09); categories and the default SLA policy are seeded here. A new
 * workspace starts on the active trial plan unless a plan is given (ADR-0025 §1).
 */
final readonly class ProvisionTenant
{
    public const INVITATION_HOURS = 48;

    public function __construct(private Clock $clock, private StartSubscription $startSubscription) {}

    /**
     * @return array{tenant: Tenant, owner: User|null, invitation_token: string|null}
     */
    public function __invoke(
        string $slug,
        string $name,
        ?string $ownerEmail = null,
        ?string $ownerName = null,
        string $timezone = 'UTC',
        ?Plan $plan = null,
        int $periods = 1,
        #[SensitiveParameter] ?string $ownerPasswordHash = null,
    ): array {
        $slug = Str::lower($slug);

        return DB::transaction(function () use ($slug, $name, $ownerEmail, $ownerName, $timezone, $plan, $periods, $ownerPasswordHash): array {
            $tenant = Tenant::findBySlug($slug);
            $isNew = $tenant === null;

            if ($tenant === null) {
                $tenant = Tenant::query()->create([
                    'slug' => $slug,
                    'name' => $name,
                    'status' => TenantStatus::Active,
                    'owner_email' => $ownerEmail,
                    'timezone' => $timezone,
                ]);

                // Reload so the database defaults (plan, placement, timestamps) are present.
                $tenant->refresh();
            }

            TenantCounter::query()->firstOrCreate(
                ['tenant_id' => $tenant->getKey()],
                ['next_ticket_number' => 1],
            );

            $tenant->domains()->firstOrCreate(
                ['domain' => config('helpdesk.hosts.app').'/'.$slug],
                ['is_primary' => true],
            );

            $this->seedDefaultCategories($tenant);
            $this->seedDefaultSlaPolicy($tenant);
            $this->seedMediaFolders($tenant);
            ($this->startSubscription)($tenant, $plan, $periods);

            [$owner, $token] = match (true) {
                $ownerEmail === null => [null, null],
                // Self sign-up (ADR-0025 §8): the person proved the address and chose a password.
                $ownerPasswordHash !== null => [$this->activeOwner($tenant, $ownerEmail, $ownerName ?? 'Workspace owner', $ownerPasswordHash), null],
                default => $this->inviteOwner($tenant, $ownerEmail, $ownerName ?? 'Workspace owner'),
            };

            if ($isNew) {
                Audit::record('tenant.created', $tenant, ['slug' => $slug, 'name' => $name], tenantId: null);
            }

            return ['tenant' => $tenant, 'owner' => $owner, 'invitation_token' => $token];
        });
    }

    /**
     * Default categories, so a new workspace can take tickets straight away (config
     * `helpdesk.tickets.default_categories`). Existing names are left alone.
     */
    private function seedDefaultCategories(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            foreach (array_values((array) config('helpdesk.tickets.default_categories')) as $order => $name) {
                Category::query()->firstOrCreate(['name' => (string) $name], ['sort_order' => $order, 'is_active' => true]);
            }
        });
    }

    private function seedDefaultSlaPolicy(Tenant $tenant): void
    {
        $tenant->run(fn () => app(EnsureDefaultSlaPolicy::class)());
    }

    private function seedMediaFolders(Tenant $tenant): void
    {
        $tenant->run(function (): void {
            foreach (['tickets' => 'Tickets', 'email' => 'Email', 'branding' => 'Branding', 'reports' => 'Reports', 'billing' => 'Billing'] as $key => $name) {
                MediaFolder::query()->firstOrCreate(
                    ['system_key' => $key],
                    ['name' => $name, 'parent_id' => null],
                );
            }
        });
    }

    /** An owner who verified their address at sign-up: active, with the password they chose. */
    private function activeOwner(Tenant $tenant, string $email, string $name, string $passwordHash): User
    {
        return $tenant->run(function () use ($email, $name, $passwordHash): User {
            $owner = User::query()->whereRaw('lower(email) = lower(?)', [$email])->first() ?? (new User)->forceFill(['email' => $email, 'preferences' => []]);
            $owner->forceFill([
                'name' => $owner->name ?? $name,
                'password' => $passwordHash,
                'is_active' => true,
                'email_verified_at' => $this->clock->now(),
            ])->save();
            $owner->syncRoles(['owner']);

            return $owner;
        });
    }

    /**
     * @return array{User, string|null}
     */
    private function inviteOwner(Tenant $tenant, string $email, string $name): array
    {
        return $tenant->run(function () use ($tenant, $email, $name): array {
            $owner = User::query()->whereRaw('lower(email) = lower(?)', [$email])->first();

            if ($owner !== null) {
                return [$owner, null];
            }

            $owner = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => null,
                'is_active' => true,
                'preferences' => [],
            ]);

            $token = Invitation::newToken();

            Invitation::query()->create([
                'user_id' => $owner->getKey(),
                'token_hash' => Invitation::hashToken($token),
                'role_names' => ['owner'],
                'expires_at' => $this->clock->now()->addHours(self::INVITATION_HOURS),
            ]);

            $owner->notify(new UserInvitation($token, (string) $tenant->slug, (string) $tenant->name));

            return [$owner, $token];
        });
    }
}
