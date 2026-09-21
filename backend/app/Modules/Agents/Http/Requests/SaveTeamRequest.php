<?php

declare(strict_types=1);

namespace App\Modules\Agents\Http\Requests;

use App\Modules\Agents\Models\Team;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class SaveTeamRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';
        $team = $this->route('team');

        return [
            'name' => [$required, 'string', 'max:80', function (string $attribute, mixed $value, Closure $fail) use ($team): void {
                $taken = is_string($value) && Team::query()
                    ->whereRaw('lower(name) = lower(?)', [$value])
                    ->when($team instanceof Team, fn ($query) => $query->whereKeyNot($team->id))
                    ->exists();
                if ($taken) {
                    $fail('A team with this name already exists in this workspace.');
                }
            }],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
