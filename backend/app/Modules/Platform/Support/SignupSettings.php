<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Support\Settings\PlatformSettings;

/** Whether self sign-up is open (ADR-0025 §8); a platform setting, on by default. */
final readonly class SignupSettings
{
    public function __construct(private PlatformSettings $settings) {}

    public function enabled(): bool
    {
        return (bool) $this->settings->get('signup', ['enabled' => true])['enabled'];
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->put('signup', ['enabled' => $enabled]);
    }
}
