<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

use App\Support\Mail\PlatformSender;

/**
 * What Settings → Email shows: the workspace's mail identity, the platform sender used for mail to
 * agents, the DNS records of the mail domain and the settings version.
 */
final readonly class EmailSettingsView
{
    /**
     * @param  list<DnsRecord>  $dnsRecords
     */
    public function __construct(
        public MailIdentity $identity,
        public PlatformSender $platformSender,
        public array $dnsRecords,
        public int $version,
        public bool $createContacts = true,
        public bool $matchOrganisationDomain = true,
    ) {}
}
