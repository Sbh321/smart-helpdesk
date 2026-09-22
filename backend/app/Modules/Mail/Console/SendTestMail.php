<?php

declare(strict_types=1);

namespace App\Modules\Mail\Console;

use App\Modules\Mail\Support\TicketThread;
use App\Support\Mail\PlatformSender;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * `just mail-send-test <to>`: sends one message shaped like ticket mail (From, Reply-To, Message-ID,
 * In-Reply-To, References, List-Unsubscribe) through the configured mailer, so an operator can check
 * delivery, the DKIM signature and the headers (docs/09-infrastructure/production.md §Mail).
 */
final class SendTestMail extends Command
{
    protected $signature = 'mail:send-test {to : Recipient address} {--workspace= : Workspace named in the sender ("<Workspace> via Smart Helpdesk")}';

    protected $description = 'Send a test message with the ticket mail headers through the configured mailer';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("Not an email address: {$to}");

            return self::INVALID;
        }

        $thread = TicketThread::for((string) Str::uuid7());
        $sender = PlatformSender::onBehalfOf((string) $this->option('workspace'));
        $sentAt = now()->toIso8601String();

        Mail::raw(
            "This is a test message from Smart Helpdesk, sent at {$sentAt}.\n\n"
            ."It carries the headers of ticket mail. If it arrived, outbound mail works; check the\n"
            ."DKIM-Signature and Authentication-Results headers for dkim=pass and spf=pass.\n",
            function (Message $message) use ($to, $sender, $thread): void {
                $message->to($to)
                    ->from($sender->address, $sender->name)
                    ->replyTo($thread->replyTo())
                    ->subject('Smart Helpdesk test message');
                $thread->applyConversation($message->getSymfonyMessage()->getHeaders(), 1, (string) Str::uuid7());
            },
        );

        $mailer = (string) config('mail.default');
        $this->info(sprintf(
            'Sent to %s through the "%s" mailer%s.',
            $to,
            $mailer,
            $mailer === 'smtp' ? sprintf(' (%s:%s)', config('mail.mailers.smtp.host'), config('mail.mailers.smtp.port')) : '',
        ));

        return self::SUCCESS;
    }
}
