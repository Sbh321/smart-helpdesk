<?php

declare(strict_types=1);

use App\Modules\Mail\Support\DnsRecord;
use App\Modules\Mail\Support\DnsRecords;

// The records Settings → Email lists and mail-init.sh prints (production.md §DNS).

it('lists MX, SPF, DKIM and DMARC for the mail domain', function (): void {
    $records = (new DnsRecords('shp.example', 'mail.shp.example', null, 'none', 'v1-rsa-20260921', 'MIIBkey'))->all();

    expect(array_map(fn (DnsRecord $record): array => [$record->type, $record->name, $record->value, $record->ready], $records))->toBe([
        ['MX', 'shp.example', '10 mail.shp.example', true],
        ['TXT', 'shp.example', 'v=spf1 mx -all', true],
        ['TXT', 'v1-rsa-20260921._domainkey.shp.example', 'v=DKIM1; k=rsa; h=sha256; p=MIIBkey', true],
        ['TXT', '_dmarc.shp.example', 'v=DMARC1; p=none; rua=mailto:postmaster@shp.example', true],
    ])->and($records[0]->purpose)->not->toBe('');
});

it('adds the relay provider to SPF and uses the configured DMARC policy', function (): void {
    $records = (new DnsRecords('shp.example', 'mail.shp.example', 'amazonses.com', 'quarantine', 's1', 'KEY'))->all();

    expect($records[1]->value)->toBe('v=spf1 mx include:amazonses.com -all')
        ->and($records[3]->value)->toBe('v=DMARC1; p=quarantine; rua=mailto:postmaster@shp.example');
});

it('marks the DKIM record as not ready until mail-init.sh has printed the key', function (?string $selector, ?string $key): void {
    $dkim = (new DnsRecords('shp.example', 'mail.shp.example', null, 'none', $selector, $key))->all()[2];

    expect($dkim->ready)->toBeFalse()
        ->and($dkim->name)->toBe('<selector>._domainkey.shp.example')
        ->and($dkim->value)->toContain('mail-init.sh');
})->with([
    'nothing' => [null, null],
    'selector only' => ['s1', null],
    'key only' => [null, 'KEY'],
]);
