<?php

declare(strict_types=1);

namespace App\Modules\Mail\Console;

use App\Modules\Mail\Domain\EmailAddress;
use App\Modules\Mail\Domain\ParsedEmail;
use App\Modules\Mail\Support\MimeParser;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/**
 * `just mail-inject` / `just mail-reply` (development, docs/09-infrastructure/local-development.md):
 * delivers a message to the bundled mail server over SMTP on port 25, the way mail from the internet
 * arrives, so it takes the real path (catch-all into `inbound@`, spam filter, IMAP fetch). With
 * `--reply` the file is a message we sent (for example a public reply taken from Mailpit) and the
 * command sends the contact's answer to it: From the original recipient, To its Reply-To, with
 * In-Reply-To and References, the reply text on top and the original quoted below.
 */
final class InjectInboundMail extends Command
{
    protected $signature = 'mail:inject
        {file=- : An .eml file, or - for standard input}
        {--reply : Answer the message in the file instead of delivering it as is}
        {--body=Thanks, that fixed it. : The text of the reply (with --reply)}
        {--from= : Sender of the reply (default: the first To of the original)}
        {--host= : SMTP host of the mail server (default MAIL_INBOUND_HOST)}
        {--port=25 : SMTP port}';

    protected $description = 'Deliver a message (or a reply to one) to the inbound mail server over SMTP';

    public function handle(MimeParser $parser): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('mail:inject is a development tool.');

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');
        $raw = $file === '-' ? (string) stream_get_contents(STDIN) : (string) @file_get_contents($file);
        if (trim($raw) === '') {
            $this->components->error('No message to inject.');

            return self::INVALID;
        }

        $original = $parser->parse($raw);
        if ($this->option('reply')) {
            $sender = is_string($this->option('from')) ? new EmailAddress($this->option('from')) : ($original->to[0] ?? null);
            $replyTo = $original->firstHeader('reply-to');
            preg_match('/[^\s<>,;"]+@[^\s<>,;"]+/', (string) $replyTo, $match);
            $recipient = $match[0] ?? $original->from?->address;
            if ($sender === null || $recipient === null) {
                $this->components->error('The original has no recipient to reply as, or no address to reply to.');

                return self::INVALID;
            }

            $raw = $this->reply($original, $sender, $recipient, (string) $this->option('body'));
            $envelopeFrom = $sender->address;
            $recipients = [$recipient];
        } else {
            $envelopeFrom = $original->from === null ? 'sender@example.test' : $original->from->address;
            $recipients = $original->recipients();
        }

        if ($recipients === []) {
            $this->components->error('The message names no recipient.');

            return self::INVALID;
        }

        $raw = (string) preg_replace("/\r?\n/", "\r\n", $raw);
        $transport = new EsmtpTransport((string) ($this->option('host') ?: config('helpdesk.mail.inbound.host')), (int) $this->option('port'), false);
        $transport->setAutoTls(false);
        $transport->send(new RawMessage($raw), new Envelope(new Address($envelopeFrom), array_map(fn (string $to): Address => new Address($to), $recipients)));

        $this->components->info(sprintf('Delivered %s to %s; mail:fetch-inbound picks it up within a minute.', $this->option('reply') ? 'a reply' : 'the message', implode(', ', $recipients)));

        return self::SUCCESS;
    }

    private function reply(ParsedEmail $original, EmailAddress $sender, string $recipient, string $body): string
    {
        $quoted = implode("\n", array_map(fn (string $line): string => '> '.$line, explode("\n", trim((string) $original->text))));
        $date = $original->sentAt?->format('D, j M Y \a\t H:i') ?? 'an earlier date';
        $author = $original->from === null ? 'Support' : ($original->from->name ?? $original->from->address);
        $references = array_values(array_unique([...$original->references, ...($original->messageId === null ? [] : [$original->messageId])]));

        $email = (new Email)
            ->from(new Address($sender->address, (string) $sender->name))
            ->to($recipient)
            ->subject(str_starts_with(strtolower($original->subject), 're:') ? $original->subject : 'Re: '.$original->subject)
            ->text("{$body}\n\nOn {$date}, {$author} wrote:\n{$quoted}\n");
        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', Str::uuid7()->toString().'@'.$sender->domain());
        if ($original->messageId !== null) {
            $headers->addIdHeader('In-Reply-To', $original->messageId);
            $headers->addIdHeader('References', $references);
        }

        return $email->toString();
    }
}
