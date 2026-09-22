<?php

declare(strict_types=1);

namespace App\Modules\Mail\Enums;

/** What became of an inbound message (docs/04-domain/email.md §Inbound pipeline). */
enum InboundState: string
{
    /** Added as a public comment by the requester. */
    case Comment = 'comment';

    /** Created a new ticket. */
    case Ticket = 'ticket';

    /** Auto-reply, bounce or empty reply: logged, nothing else. */
    case Ignored = 'ignored';

    /** Names no workspace or ticket we know. */
    case Unrouted = 'unrouted';

    /** Reached a workspace but was refused (unknown sender, forged address, closed ticket, too large). */
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Comment => 'Comment',
            self::Ticket => 'New ticket',
            self::Ignored => 'Ignored',
            self::Unrouted => 'Unrouted',
            self::Rejected => 'Rejected',
        };
    }
}
