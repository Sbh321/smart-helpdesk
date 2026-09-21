<?php

declare(strict_types=1);

namespace App\Modules\Media\Http\Requests;

use App\Modules\Media\Support\AllowedMedia;
use App\Modules\Media\Support\MediaFilename;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class UploadIntentRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:'.AllowedMedia::maxBytes()],
            // Advisory: browsers send '' for types they do not know; the stored type comes from the extension.
            'mime' => ['present', 'nullable', 'string', 'max:127'],
            // Where the file starts: the Tickets folder, or Branding for a workspace logo (settings.manage).
            'purpose' => ['sometimes', 'string', Rule::in(['attachment', 'branding']), function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === 'branding' && $this->user()?->can('settings.manage') !== true) {
                    $fail('Only workspace administrators can upload branding files.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return ['size.max' => 'Files can be at most '.(int) floor(AllowedMedia::maxBytes() / 1048576).' MB.'];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['filename', 'mime'])) {
                return;
            }
            $extension = AllowedMedia::extensionFor(MediaFilename::sanitise((string) $this->input('filename')));
            if ($extension === null) {
                $validator->errors()->add('filename', 'This file type is not allowed.');
            } elseif (! AllowedMedia::acceptsDeclared($extension, (string) $this->input('mime'))) {
                $validator->errors()->add('mime', 'The file type does not match the file name.');
            }
        }];
    }
}
