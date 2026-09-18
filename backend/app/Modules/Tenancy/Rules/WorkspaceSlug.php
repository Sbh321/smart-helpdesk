<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Workspace slug: lower-case letters, digits and single hyphens, 1–63 characters, not a
 * reserved host or path name (ADR-0021). The database has the same checks.
 */
final class WorkspaceSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z0-9](-?[a-z0-9])*$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) > 63 || preg_match(self::PATTERN, $value) !== 1) {
            $fail('The :attribute may only contain lower-case letters, digits and single hyphens (at most 63 characters).');

            return;
        }

        if (in_array($value, (array) config('helpdesk.reserved_slugs'), true)) {
            $fail('The :attribute is reserved.');
        }
    }
}
