<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Mail\Listeners\SendPublicReplyToContact;
use App\Modules\Tickets\Events\CommentAdded;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;

final class MailServiceProvider extends ModuleServiceProvider
{
    protected function bootModule(): void
    {
        // After-commit event: the requester is mailed only once the reply is stored.
        Event::listen(CommentAdded::class, SendPublicReplyToContact::class);
    }
}
