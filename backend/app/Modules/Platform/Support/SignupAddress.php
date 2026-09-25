<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Models\WorkspaceSignup;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Rules\WorkspaceSlug;
use App\Support\Time\Clock;

/**
 * Whether a workspace address can be signed up for (ADR-0025 §8): well formed, not reserved, not a
 * workspace, and not held by someone else's sign-up that is still waiting for its email link.
 */
final readonly class SignupAddress
{
    public function __construct(private Clock $clock) {}

    /** @return 'available'|'invalid'|'reserved'|'taken' */
    public function check(string $slug, ?string $email = null): string
    {
        if (strlen($slug) > 63 || preg_match(WorkspaceSlug::PATTERN, $slug) !== 1) {
            return 'invalid';
        }
        if (in_array($slug, (array) config('helpdesk.reserved_slugs'), true)) {
            return 'reserved';
        }
        if (Tenant::findBySlug($slug) !== null) {
            return 'taken';
        }
        $held = WorkspaceSignup::query()
            ->where('slug', $slug)
            ->whereNull('verified_at')
            ->where('expires_at', '>', $this->clock->now())
            ->when($email !== null, fn ($query) => $query->whereRaw('lower(email) <> lower(?)', [$email]))
            ->exists();

        return $held ? 'taken' : 'available';
    }
}
