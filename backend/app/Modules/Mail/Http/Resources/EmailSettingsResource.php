<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Resources;

use App\Modules\Mail\Support\EmailSettingsView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Settings → Email: the workspace's sender identity, intake address and the mail domain's DNS records.
 *
 * @mixin EmailSettingsView
 */
final class EmailSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /** The stored display name; null uses `default_sender_name`. */
            'sender_name' => $this->identity->senderNameSetting,
            'default_sender_name' => $this->identity->defaultSenderName(),
            /** Mail from an unknown sender to the intake address creates a contact; false rejects it. */
            'create_contacts' => $this->createContacts,
            /** A contact created from email joins the organisation whose domain matches its address. */
            'match_organisation_domain' => $this->matchOrganisationDomain,
            /** The From of mail to contacts. */
            'from' => [
                'name' => $this->identity->senderName(),
                'address' => $this->identity->senderAddress(),
            ],
            /** The From of invitations and notifications to agents. */
            'platform_from' => [
                'name' => $this->platformSender->name,
                'address' => $this->platformSender->address,
            ],
            /** Mail to this address becomes a ticket (inbound email). */
            'intake_address' => $this->identity->intakeAddress(),
            /** Reply-To of ticket mail; a reply lands on the ticket. */
            'reply_to_pattern' => $this->identity->replyToPattern(),
            'mail_domain' => $this->identity->domain,
            'dns_records' => DnsRecordResource::collection($this->dnsRecords),
            'version' => $this->version,
        ];
    }
}
