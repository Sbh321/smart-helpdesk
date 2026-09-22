<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Controllers;

use App\Modules\Mail\Http\Requests\UpdateEmailSettingsRequest;
use App\Modules\Mail\Http\Resources\EmailSettingsResource;
use App\Modules\Mail\Support\DnsRecords;
use App\Modules\Mail\Support\EmailSettingsView;
use App\Modules\Mail\Support\WorkspaceMailIdentity;
use App\Modules\Tenancy\Settings\Settings;
use App\Support\Mail\PlatformSender;
use Dedoc\Scramble\Attributes\Group;

#[Group('Settings')]
final class EmailSettingsController
{
    public function __construct(private readonly Settings $settings, private readonly WorkspaceMailIdentity $identity) {}

    /**
     * Email settings.
     *
     * The workspace's sender identity, intake address and the DNS records of the mail domain.
     */
    public function show(): EmailSettingsResource
    {
        return $this->view();
    }

    /**
     * Update email settings.
     *
     * Changes the display name on mail to contacts and the inbound rules for unknown senders; send only
     * the fields to change. Stored in the `email` settings section: the write bumps the settings
     * version and is audited as `settings.updated`.
     */
    public function update(UpdateEmailSettingsRequest $request): EmailSettingsResource
    {
        $input = [];
        if ($request->has('sender_name')) {
            $name = $request->validated('sender_name');
            $input['sender_name'] = is_string($name) && trim($name) !== '' ? trim($name) : null;
        }
        foreach (['create_contacts', 'match_organisation_domain'] as $flag) {
            if ($request->has($flag)) {
                $input[$flag] = $request->boolean($flag);
            }
        }
        if ($input !== []) {
            $this->settings->update('email', $input);
        }

        return $this->view();
    }

    private function view(): EmailSettingsResource
    {
        $identity = $this->identity->current();

        return new EmailSettingsResource(new EmailSettingsView(
            $identity,
            PlatformSender::onBehalfOf($identity->workspaceName),
            DnsRecords::fromConfig()->all(),
            $this->settings->version(),
            (bool) $this->settings->get('email.create_contacts', true),
            (bool) $this->settings->get('email.match_organisation_domain', true),
        ));
    }
}
