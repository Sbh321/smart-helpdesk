<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Settings\Sections;

use App\Modules\Tenancy\Settings\SettingsSection;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

/**
 * Primary colour and logos (docs/06-design-system/tokens.md §Tenant branding). Logos are media items
 * of the workspace, uploaded through the normal intent/complete flow into the Branding folder.
 */
final class BrandingSection implements SettingsSection
{
    /** The lightest and darkest foreground the SPA can put on the primary colour. */
    private const array FOREGROUNDS = ['#ffffff', '#0a0a0a'];

    public function key(): string
    {
        return 'branding';
    }

    public function defaults(): array
    {
        return ['primary' => null, 'logo_media_id' => null, 'logo_dark_media_id' => null];
    }

    public function rules(): array
    {
        // Checked by table name: Tenancy is below Media and must not import it.
        $image = Rule::exists('media_items', 'id')->where(function (Builder $query): void {
            $query->where('tenant_id', tenant()?->getTenantKey())->where('state', 'ready')->where('mime_type', 'like', 'image/%');
        });

        return [
            // MVP-SHORTCUT: hex only; V1: V1-PL-14 oklch() colours.
            'primary' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo_media_id' => ['nullable', 'uuid', $image],
            'logo_dark_media_id' => ['nullable', 'uuid', $image],
        ];
    }

    public function check(array $values): array
    {
        $primary = $values['primary'] ?? null;
        if (! is_string($primary)) {
            return [];
        }

        $best = max(array_map(fn (string $foreground): float => self::contrast($primary, $foreground), self::FOREGROUNDS));

        return $best >= 4.5 ? [] : ['primary' => 'Neither light nor dark text reaches a 4.5:1 contrast on this colour.'];
    }

    /** WCAG 2.x contrast ratio of two #rrggbb colours. */
    public static function contrast(string $first, string $second): float
    {
        [$lighter, $darker] = [max(self::luminance($first), self::luminance($second)), min(self::luminance($first), self::luminance($second))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(
            function (string $pair): float {
                $value = hexdec($pair) / 255;

                return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            },
            str_split(ltrim($hex, '#'), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
