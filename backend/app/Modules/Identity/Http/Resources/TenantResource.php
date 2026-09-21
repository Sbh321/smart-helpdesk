<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Settings\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The workspace as the SPA needs it: enough to render the shell and compare the URL segment.
 *
 * @mixin Tenant
 */
final class TenantResource extends JsonResource
{
    /**
     * @return array{id: string, slug: string, name: string, status: string, timezone: string, settings_version: int, branding: array{primary: string|null, logo_url: string|null, logo_dark_url: string|null}, features: array{realtime: bool, exports: bool}}
     */
    public function toArray(Request $request): array
    {
        /** @var Settings $settings */
        $settings = app(Settings::class);
        $branding = $settings->section('branding');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'status' => $this->status->value,
            'timezone' => $this->timezone,
            // What every user needs from the workspace settings; the rest is behind settings.manage.
            'settings_version' => (int) $settings->version(),
            'branding' => [
                'primary' => is_string($branding['primary'] ?? null) ? $branding['primary'] : null,
                'logo_url' => $this->logoUrl($branding['logo_media_id'] ?? null),
                'logo_dark_url' => $this->logoUrl($branding['logo_dark_media_id'] ?? null),
            ],
            'features' => [
                'realtime' => (bool) $settings->get('features.realtime', false),
                'exports' => (bool) $settings->get('features.exports', true),
            ],
        ];
    }

    /** The media download route redirects to a short-lived signed URL, so the link itself never expires. */
    private function logoUrl(mixed $mediaId): ?string
    {
        return is_string($mediaId) ? route('media.download', ['media' => $mediaId]) : null;
    }
}
