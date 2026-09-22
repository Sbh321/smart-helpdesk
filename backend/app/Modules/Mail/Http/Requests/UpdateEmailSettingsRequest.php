<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Requests;

use App\Modules\Mail\MailServiceProvider;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateEmailSettingsRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Display name on mail to contacts; null or empty restores "<Workspace> Support".
            'sender_name' => ['sometimes', ...MailServiceProvider::senderNameRules()],
            // Mail from an unknown sender to the intake address creates a contact (false: it is rejected).
            'create_contacts' => ['sometimes', 'boolean'],
            // A contact created from email joins the organisation whose domain matches its address.
            'match_organisation_domain' => ['sometimes', 'boolean'],
        ];
    }
}
