<?php

declare(strict_types=1);

namespace App\Modules\Mail\Contracts;

use App\Modules\Mail\Support\FetchedMessage;

/**
 * The inbound mailbox `mail:fetch-inbound` drains (docs/04-domain/email.md §Inbound pipeline). The
 * production implementation speaks IMAP to the bundled server (`Support\ImapInboundMailbox`); tests
 * bind an in-memory one, so no test talks to a real server.
 */
interface InboundMailbox
{
    /**
     * Up to `$limit` messages waiting in the configured folders, oldest first.
     *
     * @return iterable<FetchedMessage>
     */
    public function fetch(int $limit): iterable;

    /** Moves a handled message (whatever its outcome) to the Processed folder. */
    public function markProcessed(FetchedMessage $message): void;

    /** Moves a message that could not be handled to the Failed folder, for a person to look at. */
    public function markFailed(FetchedMessage $message): void;

    /** Ends the session. */
    public function close(): void;
}
