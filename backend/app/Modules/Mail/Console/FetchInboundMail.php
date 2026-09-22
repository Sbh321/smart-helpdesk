<?php

declare(strict_types=1);

namespace App\Modules\Mail\Console;

use App\Modules\Mail\Actions\ProcessInboundEmail;
use App\Modules\Mail\Contracts\InboundMailbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Every minute: drains the inbound mailbox (docs/11-operations/scheduler.md, docs/04-domain/email.md
 * §Inbound pipeline). Each message is processed inline and then moved to `Processed`, whatever its
 * outcome (comment, ticket, ignored, unrouted, rejected or an already-logged duplicate); a message
 * that throws is reported and moved to `Failed`, and the run goes on with the next one. Off unless
 * `MAIL_INBOUND_ENABLED=true`, because the mail server runs only in the `mail` profile.
 */
final class FetchInboundMail extends Command
{
    protected $signature = 'mail:fetch-inbound {--limit= : Messages to handle in this run (default helpdesk.mail.inbound.batch_size)}';

    protected $description = 'Fetch inbound email over IMAP and turn it into comments and tickets';

    public function handle(InboundMailbox $mailbox, ProcessInboundEmail $process): int
    {
        if (! (bool) config('helpdesk.mail.inbound.enabled')) {
            $this->components->info('Inbound email is off (MAIL_INBOUND_ENABLED=false).');

            return self::SUCCESS;
        }

        $limit = max(1, (int) ($this->option('limit') ?? config('helpdesk.mail.inbound.batch_size', 50)));
        $counts = [];

        try {
            foreach ($mailbox->fetch($limit) as $message) {
                try {
                    $state = $process($message->raw)->state;
                    $mailbox->markProcessed($message);
                } catch (Throwable $exception) {
                    report($exception);
                    Log::error('mail.inbound.failed', ['folder' => $message->folder, 'uid' => $message->uid, 'error' => $exception::class]);
                    $state = 'failed';

                    try {
                        $mailbox->markFailed($message);
                    } catch (Throwable $moveFailure) {
                        report($moveFailure);
                    }
                }

                $counts[$state] = ($counts[$state] ?? 0) + 1;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->components->error('The inbound mailbox is unreachable: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            $mailbox->close();
        }

        ksort($counts);
        $summary = $counts === [] ? 'nothing waiting' : implode(', ', array_map(fn (string $state, int $count): string => "{$count} {$state}", array_keys($counts), $counts));
        $this->components->info("Inbound email: {$summary}.");

        return ($counts['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
