<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\MediaFilename;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

final class UpdateMediaRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'folder_id' => ['sometimes', 'nullable', 'uuid'],
            'tags' => ['sometimes', 'array', 'max:20'],
            // At least one letter or digit, so every tag has a slug.
            'tags.*' => ['string', 'max:40', 'regex:/[\pL\pN]/u'],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach ((array) $this->input('tags', []) as $index => $tag) {
                if (is_string($tag) && Str::slug($tag) === '') {
                    $validator->errors()->add("tags.{$index}", 'Use tag names containing Latin letters or numbers.');
                }
            }
            if (! $this->has('name') || $validator->errors()->has('name')) {
                return;
            }
            $media = $this->route('media');
            $name = MediaFilename::sanitise((string) $this->input('name'));
            if ($name === '') {
                $validator->errors()->add('name', 'Enter a file name.');
            } elseif ($media instanceof MediaItem && ! $this->keepsType($name, $media)) {
                // The stored type never changes, so a download must not claim another one.
                $validator->errors()->add('name', 'The file extension cannot be changed.');
            }
        }];
    }

    private function keepsType(string $name, MediaItem $media): bool
    {
        $extension = AllowedMedia::extensionFor($name);

        // jpg ↔ jpeg is fine; png → pdf is not.
        return $extension !== null && AllowedMedia::mimeFor($extension) === $media->mime_type;
    }

    /** The sanitised name, or null when the request does not rename. */
    public function sanitisedName(): ?string
    {
        return $this->has('name') ? MediaFilename::sanitise((string) $this->input('name')) : null;
    }
}
