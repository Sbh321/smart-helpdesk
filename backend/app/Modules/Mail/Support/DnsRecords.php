<?php

declare(strict_types=1);

namespace App\Modules\Mail\Support;

/**
 * The records that let other servers accept the platform's mail (ADR-0018 §4, production.md §DNS):
 * MX for inbound mail, SPF, the DKIM public key of the bundled server, DMARC. The same values are
 * printed by infra/scripts/mail-init.sh. Pure: the caller passes the configuration.
 */
final readonly class DnsRecords
{
    public function __construct(
        private string $domain,
        private string $mxHost,
        private ?string $spfInclude = null,
        private string $dmarcPolicy = 'none',
        private ?string $dkimSelector = null,
        private ?string $dkimPublicKey = null,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('helpdesk.hosts.mail'),
            (string) config('helpdesk.mail.mx_host'),
            self::optional(config('helpdesk.mail.spf_include')),
            self::optional(config('helpdesk.mail.dmarc_policy')) ?? 'none',
            self::optional(config('helpdesk.mail.dkim_selector')),
            self::optional(config('helpdesk.mail.dkim_public_key')),
        );
    }

    /** @return list<DnsRecord> */
    public function all(): array
    {
        $include = $this->spfInclude === null ? '' : " include:{$this->spfInclude}";
        $dkimReady = $this->dkimSelector !== null && $this->dkimPublicKey !== null;

        return [
            new DnsRecord('MX', $this->domain, "10 {$this->mxHost}", 'Receives replies and new requests for the intake addresses.'),
            new DnsRecord('TXT', $this->domain, "v=spf1 mx{$include} -all", 'SPF: the servers allowed to send for the domain.'),
            new DnsRecord(
                'TXT',
                $dkimReady ? "{$this->dkimSelector}._domainkey.{$this->domain}" : "<selector>._domainkey.{$this->domain}",
                $dkimReady
                    ? "v=DKIM1; k=rsa; h=sha256; p={$this->dkimPublicKey}"
                    : 'Run infra/scripts/mail-init.sh and set MAIL_DKIM_SELECTOR and MAIL_DKIM_PUBLIC_KEY.',
                'DKIM: the public key that verifies the signature on every outgoing mail.',
                $dkimReady,
            ),
            new DnsRecord(
                'TXT',
                "_dmarc.{$this->domain}",
                "v=DMARC1; p={$this->dmarcPolicy}; rua=mailto:postmaster@{$this->domain}",
                'DMARC: what receivers do with mail that fails SPF and DKIM, and where they report.',
            ),
        ];
    }

    private static function optional(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
