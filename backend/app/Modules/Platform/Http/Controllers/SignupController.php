<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Audit\Audit;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Platform\Models\WorkspaceSignup;
use App\Modules\Platform\Notifications\VerifySignup;
use App\Modules\Platform\Support\PlatformException;
use App\Modules\Platform\Support\SignupAddress;
use App\Modules\Platform\Support\SignupSettings;
use App\Support\Errors\ErrorCode;
use App\Support\Time\Clock;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self sign-up for a workspace on the free trial (ADR-0025 §8): a request, an email link, and only then
 * a workspace. Pre-authentication and outside any workspace; throttled; a platform setting closes it.
 */
#[Group('Sign-up')]
final readonly class SignupController
{
    public function __construct(private Clock $clock, private SignupAddress $address, private SignupSettings $settings) {}

    /**
     * Check a workspace address.
     *
     * @unauthenticated
     *
     * @response array{data: array{slug: string, available: bool, reason: 'invalid'|'reserved'|'taken'|null}}
     */
    #[QueryParameter('slug', 'The address to check, as it would appear after the host.', required: true, type: 'string')]
    public function address(Request $request): JsonResponse
    {
        $slug = strtolower(trim($request->string('slug')->value()));
        $result = $this->address->check($slug);

        return new JsonResponse(['data' => [
            'slug' => $slug,
            'available' => $result === 'available',
            'reason' => $result === 'available' ? null : $result,
        ]]);
    }

    /**
     * Sign up for a workspace.
     *
     * Nothing is created yet: the address receives a link, valid for 24 hours, that creates the
     * workspace. Answers 202 whether or not the email is sent (an empty `website` field is expected).
     *
     * @unauthenticated
     */
    public function store(Request $request): JsonResponse
    {
        $this->ensureOpen();
        // The honeypot: people never see this field, form-filling robots do. Their request is accepted
        // and forgotten.
        if ($request->filled('website')) {
            return self::sent();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:filter', 'max:254'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'workspace_name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63'],
            'timezone' => ['sometimes', 'string', 'timezone:all', 'max:64'],
        ]);
        $slug = strtolower($data['slug']);
        $this->ensureAvailable($slug, $data['email']);

        $token = WorkspaceSignup::newToken();
        DB::transaction(function () use ($data, $slug, $token): void {
            // A new request replaces the same person's earlier, unconfirmed ones.
            WorkspaceSignup::query()->whereRaw('lower(email) = lower(?)', [$data['email']])->whereNull('verified_at')->delete();
            WorkspaceSignup::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'workspace_name' => $data['workspace_name'],
                'slug' => $slug,
                'timezone' => $data['timezone'] ?? 'UTC',
                'token_hash' => WorkspaceSignup::hashToken($token),
                'expires_at' => $this->clock->now()->addHours(WorkspaceSignup::HOURS),
            ]);
        });
        Notification::route('mail', $data['email'])->notify(new VerifySignup($token, $data['workspace_name']));

        return self::sent();
    }

    /**
     * Confirm a sign-up: the workspace is created on the free trial.
     *
     * @unauthenticated
     *
     * @response array{data: array{slug: string, name: string, email: string}}
     */
    public function verify(Request $request, ProvisionTenant $provision): JsonResponse
    {
        $this->ensureOpen();
        $data = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $signup = DB::transaction(function () use ($data, $provision): WorkspaceSignup {
            $signup = WorkspaceSignup::query()
                ->where('token_hash', WorkspaceSignup::hashToken($data['token']))
                ->lockForUpdate()
                ->first();
            if ($signup === null || $signup->verified_at !== null || $signup->expires_at->lessThanOrEqualTo($this->clock->now())) {
                throw new PlatformException(ErrorCode::LinkExpired, 'This link has expired or was already used. Sign up again, or sign in if the workspace exists.');
            }
            if ($this->address->check($signup->slug, $signup->email) !== 'available') {
                throw new PlatformException(ErrorCode::LinkExpired, "The address {$signup->slug} was taken in the meantime. Sign up again with another address.");
            }

            $result = $provision(
                $signup->slug,
                $signup->workspace_name,
                $signup->email,
                $signup->name,
                $signup->timezone,
                ownerPasswordHash: $signup->password,
            );
            $signup->forceFill(['verified_at' => $this->clock->now(), 'tenant_id' => $result['tenant']->getKey()])->save();
            Audit::record('tenant.signed_up', $result['tenant'], ['slug' => $signup->slug, 'email' => $signup->email], tenantId: null);

            return $signup;
        });

        return new JsonResponse(['data' => ['slug' => $signup->slug, 'name' => $signup->workspace_name, 'email' => $signup->email]], 201);
    }

    private function ensureOpen(): void
    {
        if (! $this->settings->enabled()) {
            throw new PlatformException(ErrorCode::SignupClosed, 'Sign-up is closed at the moment. Ask for a workspace instead.');
        }
    }

    private function ensureAvailable(string $slug, string $email): void
    {
        $message = match ($this->address->check($slug, $email)) {
            'available' => null,
            'invalid' => 'Use lower-case letters, digits and single hyphens.',
            'reserved' => 'This address is reserved. Choose another.',
            default => 'This address is taken. Choose another.',
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['slug' => $message]);
        }
    }

    private static function sent(): JsonResponse
    {
        return new JsonResponse(['data' => ['status' => 'sent']], 202);
    }
}
