<?php

declare(strict_types=1);

namespace App\Modules\Mail;

use App\Modules\Mail\Console\FetchInboundMail;
use App\Modules\Mail\Console\InjectInboundMail;
use App\Modules\Mail\Console\SendTestMail;
use App\Modules\Mail\Contracts\InboundMailbox;
use App\Modules\Mail\Listeners\SendPublicReplyToContact;
use App\Modules\Mail\Support\ImapInboundMailbox;
use App\Modules\Tenancy\Settings\ArraySection;
use App\Modules\Tenancy\Settings\SettingsRegistry;
use App\Modules\Tickets\Events\CommentAdded;
use App\Support\Modules\ModuleServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schedule;

final class MailServiceProvider extends ModuleServiceProvider
{
    /**
     * A display name, not an address: no line breaks, angle brackets or quotes (header injection and
     * look-alike addresses).
     *
     * @return list<string>
     */
    public static function senderNameRules(): array
    {
        return ['nullable', 'string', 'max:80', 'not_regex:/[\r\n<>"@\\\\]/'];
    }

    public function register(): void
    {
        // The IMAP mailbox of the bundled server; tests bind an in-memory mailbox instead.
        $this->app->bind(InboundMailbox::class, function (): InboundMailbox {
            /** @var array{host: string, port: int, encryption: string, validate_cert: bool, username: string, password: string|null, folders: list<string>, processed_folder: string, failed_folder: string} $config */
            $config = (array) config('helpdesk.mail.inbound');

            return new ImapInboundMailbox($config);
        });
    }

    protected function bootModule(): void
    {
        // Settings → Email (GET/PATCH /v1/settings/email, mail.manage); the section keeps versioning and audit.
        $this->app->make(SettingsRegistry::class)->register(new ArraySection(
            'email',
            // Inbound (M3-19): unknown senders to the intake address become contacts; a new contact joins
            // the organisation whose domain matches its address.
            ['sender_name' => null, 'create_contacts' => true, 'match_organisation_domain' => true],
            [
                'sender_name' => self::senderNameRules(),
                'create_contacts' => ['required', 'boolean'],
                'match_organisation_domain' => ['required', 'boolean'],
            ],
        ));

        // After-commit event: the requester is mailed only once the reply is stored.
        Event::listen(CommentAdded::class, SendPublicReplyToContact::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SendTestMail::class, FetchInboundMail::class, InjectInboundMail::class]);
            // The command is a no-op unless MAIL_INBOUND_ENABLED=true (docs/11-operations/scheduler.md).
            Schedule::command('mail:fetch-inbound')->everyMinute()->onOneServer()->withoutOverlapping(10);
        }
    }
}
