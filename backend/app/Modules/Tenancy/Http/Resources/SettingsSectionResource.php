<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Resources;

use App\Modules\Tenancy\Settings\SectionView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One settings section: effective values (defaults with the workspace overrides) and the code
 * defaults, so a form can offer "reset to default".
 *
 * @mixin SectionView
 */
final class SettingsSectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'section' => $this->section,
            'version' => $this->version,
            'values' => $this->values,
            'defaults' => $this->defaults,
        ];
    }
}
