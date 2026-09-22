<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What Echo (pusher-js) sends to authorise a private channel.
 */
final class AuthorizeChannelRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // The Pusher socket id of this browser connection, e.g. `123456.7890123`.
            'socket_id' => ['required', 'string', 'regex:/^\d+\.\d+$/'],
            // `private-tenants.{tenant}.tickets`, `…tickets.{ticket}`, `…tickets.{ticket}.internal`, `…users.{user}`.
            'channel_name' => ['required', 'string', 'max:200', 'starts_with:private-tenants.'],
        ];
    }
}
