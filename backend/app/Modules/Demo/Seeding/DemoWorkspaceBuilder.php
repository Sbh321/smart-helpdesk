<?php

declare(strict_types=1);

namespace App\Modules\Demo\Seeding;

use App\Models\User;
use App\Modules\Agents\Actions\ReplaceAgentShifts;
use App\Modules\Agents\Enums\AgentAvailability;
use App\Modules\Agents\Models\AgentProfile;
use App\Modules\Agents\Models\Skill;
use App\Modules\Agents\Models\Team;
use App\Modules\Audit\Audit;
use App\Modules\Audit\Enums\ActorType;
use App\Modules\Automation\Actions\AssignTicket;
use App\Modules\Automation\Actions\OverrideTicketPriority;
use App\Modules\Billing\Enums\PaymentMethod;
use App\Modules\Billing\Enums\PaymentStatus;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Models\SubscriptionPayment;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Contacts\Actions\SaveContact;
use App\Modules\Contacts\Enums\OrganizationTier;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\Organization;
use App\Modules\Identity\Actions\ChangeUserRoles;
use App\Modules\Identity\Actions\SyncPermissionCatalogue;
use App\Modules\Integrations\Actions\CreateApiClient;
use App\Modules\Integrations\Actions\RecordDeliveryOutcome;
use App\Modules\Integrations\Actions\SaveWebhookSubscription;
use App\Modules\Integrations\Domain\Webhooks\DeliveryState;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Webhooks\DeliveryAttempt;
use App\Modules\Media\Actions\CompleteUpload;
use App\Modules\Media\Actions\RegisterUpload;
use App\Modules\Media\Jobs\GenerateImageVariants;
use App\Modules\Media\Models\Mediable;
use App\Modules\Media\Models\MediaItem;
use App\Modules\Media\Support\MediaKeys;
use App\Modules\Media\Support\MediaStorage;
use App\Modules\Notifications\Channels\TenantDatabaseChannel;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Notifications\SlaBreachNotice;
use App\Modules\Notifications\Notifications\SlaWarningNotice;
use App\Modules\Notifications\Notifications\TicketAssignedToYou;
use App\Modules\Notifications\Notifications\TicketEscalated;
use App\Modules\Notifications\Notifications\TicketNotification;
use App\Modules\Platform\Actions\ProvisionTenant;
use App\Modules\Platform\Models\PlatformUser;
use App\Modules\Sla\Jobs\EvaluateSlaTimers;
use App\Modules\Sla\Models\SlaPolicy;
use App\Modules\Tenancy\Bootstrappers\RlsTenancyBootstrapper;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantCounter;
use App\Modules\Tenancy\Settings\Settings;
use App\Modules\Tickets\Actions\AddComment;
use App\Modules\Tickets\Actions\AutoCloseTicket;
use App\Modules\Tickets\Actions\CreateTicket;
use App\Modules\Tickets\Actions\TransitionTicket;
use App\Modules\Tickets\Domain\Priority;
use App\Modules\Tickets\Domain\TicketStatus;
use App\Modules\Tickets\Models\Category;
use App\Modules\Tickets\Models\Ticket;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * Builds the demo workspaces (roadmap/11-demo-dataset.md) by replaying DemoPlan through the real
 * actions under a frozen clock that moves step by step, so history, SLA timers, assignment and
 * priority explanations, duplicate suggestions, intervals and snapshots are what the application
 * itself produced. Runs on the application's database role, inside each workspace (row-level
 * security); nothing here reads or writes another workspace.
 *
 * Side effects that would leave the machine during the replay (mail, broadcasts, webhook HTTP calls,
 * report refresh jobs, notifications) are queued on a discarding connection; notifications are then
 * written for a curated set of recent events, webhook delivery outcomes are recorded through the real
 * outcome action, and `reports:rebuild` recomputes the read models once at the end.
 */
final class DemoWorkspaceBuilder
{
    private const string DISCARD_QUEUE = 'demo-replay-discard';

    private ReplayClock $clock;

    private CarbonImmutable $now;

    /** @var array<string, User> email => user of the workspace being built */
    private array $users = [];

    /** @var array<string, string> agent profile id => email */
    private array $agentEmails = [];

    /** @var array<string, string> key => id */
    private array $categories = [];

    /** @var array<string, string> key => id */
    private array $teams = [];

    /** @var array<string, string> key => id */
    private array $organisations = [];

    /** @var array<string, string> name => id */
    private array $contacts = [];

    /** @var array<string, string> plan key => ticket id */
    private array $tickets = [];

    /** @var array<string, int> */
    private array $stats = ['fallback_assignments' => 0, 'sweeps' => 0, 'attachments' => 0, 'attachments_skipped' => 0];

    private ?string $apiClientId = null;

    private ?string $apiClientSecret = null;

    public function __construct(private readonly Container $container) {}

    /**
     * @return array{acme: string, globex: string, api_client_id: string|null, api_client_secret: string|null, stats: array<string, int>, first_number: int}
     */
    public function build(CarbonImmutable $now, int $seed, int $historyTickets, bool $attachments = true): array
    {
        $this->now = $now->startOfMinute();
        $plan = new DemoPlan($seed, $historyTickets);
        $tickets = $plan->tickets();

        $queue = config('queue.default');
        config([
            'queue.connections.'.self::DISCARD_QUEUE => ['driver' => 'null'],
            'queue.default' => self::DISCARD_QUEUE,
        ]);
        $this->clock = new ReplayClock($this->container, $this->at(DemoPlan::HISTORY_DAYS * DemoPlan::DAY + 180));

        try {
            $this->ensurePlatformAdmin();
            app(SyncPermissionCatalogue::class)();
            $acme = $this->buildAcme($plan, $tickets, $attachments);
            $globex = $this->buildGlobex();
            $this->seedBilling($acme, $globex, $attachments);
            DB::table('tenants')->whereIn('id', [$acme, $globex])->update(['status' => TenantStatus::Active->value]);
        } finally {
            $this->clock->restore();
            config(['queue.default' => $queue]);
            Auth::guard()->forgetUser();
        }

        return [
            'acme' => $acme,
            'globex' => $globex,
            'api_client_id' => $this->apiClientId,
            'api_client_secret' => $this->apiClientSecret,
            'stats' => $this->stats,
            'first_number' => $plan->firstNumber(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tickets  DemoPlan::tickets()
     */
    private function buildAcme(DemoPlan $plan, array $tickets, bool $attachments): string
    {
        $this->resetMaps();
        $categories = [];
        foreach (DemoCatalogue::CATEGORIES as $category) {
            $categories[] = $category[0];
        }
        // Provisioning seeds the categories named in config; the demo's six instead of the defaults.
        $defaults = config('helpdesk.tickets.default_categories');
        config(['helpdesk.tickets.default_categories' => $categories]);
        try {
            $tenant = app(ProvisionTenant::class)(DemoCatalogue::ACME, DemoCatalogue::WORKSPACES[DemoCatalogue::ACME], timezone: DemoCatalogue::TIMEZONE)['tenant'];
        } finally {
            config(['helpdesk.tickets.default_categories' => $defaults]);
        }
        TenantCounter::query()->where('tenant_id', $tenant->id)->update(['next_ticket_number' => $plan->firstNumber()]);
        $this->suspendWhileBuilding($tenant);

        $tenant->run(function () use ($tickets, $attachments): void {
            $this->setUpAcme();

            // One timeline: workspace events and every ticket step, in time order.
            $timeline = [];
            foreach ($this->acmeEvents() as [$ago, $event]) {
                $timeline[] = [$ago, count($timeline), $event];
            }
            foreach ($tickets as $spec) {
                $timeline[] = [$spec['ago'], count($timeline), fn () => $this->createTicket($spec)];
                foreach ($spec['steps'] as $step) {
                    $timeline[] = [$step['ago'], count($timeline), fn () => $this->ticketStep($spec, $step, $attachments)];
                }
            }
            usort($timeline, fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $a[1] <=> $b[1]);

            foreach ($timeline as [$ago, , $event]) {
                $this->advanceTo($this->at($ago));
                $event();
            }

            $this->advanceTo($this->now);
            $this->recordWebhookOutcomes();
            $this->writeNotifications();
        });

        return (string) $tenant->id;
    }

    private function setUpAcme(): void
    {
        $this->as(null, function (): void {
            foreach (DemoCatalogue::SKILLS as $slug => $name) {
                Skill::query()->create(['name' => $name, 'slug' => $slug]);
            }
            foreach (DemoCatalogue::TEAMS as $key => [$name, $description]) {
                $this->teams[$key] = Team::query()->create(['name' => $name, 'description' => $description])->id;
            }
            $skills = Skill::query()->pluck('id', 'slug')->all();
            foreach (DemoCatalogue::CATEGORIES as $key => [$name, $required, $team]) {
                $category = Category::query()->where('name', $name)->firstOrFail();
                $category->forceFill(['default_team_id' => $this->teams[$team]])->save();
                $category->skills()->sync(array_fill_keys(array_map(fn (string $slug): string => $skills[$slug], $required), ['tenant_id' => $category->tenant_id]));
                $this->categories[$key] = $category->id;
            }

            // Staff and agents. Priya starts as an agent; Meera promotes her on day −60.
            foreach (DemoCatalogue::STAFF as $email => [$name, $role]) {
                $this->users[$email] = $this->user($email, $name, $email === 'priya@acme.test' ? 'agent' : $role);
            }
            foreach (DemoCatalogue::AGENTS as $email => [$name]) {
                $this->users[$email] = $this->user($email, $name, 'agent');
            }

            foreach (DemoCatalogue::AGENTS as $email => [, $teams, $levels, $capacity]) {
                // Farid joins Customer Success on day −10.
                $teams = $email === 'farid@acme.test' ? ['technical'] : $teams;
                $profile = $this->agentProfile($email, $capacity, array_map(fn (string $team): string => $this->teams[$team], $teams));
                $profile->skills()->sync(collect($levels)->mapWithKeys(fn (int $level, string $slug): array => [$skills[$slug] => ['tenant_id' => $profile->tenant_id, 'level' => $level]])->all());
            }
            $this->agentProfile('priya@acme.test', 5, []);

            foreach (DemoCatalogue::ORGANISATIONS as $key => [$name, $domain, $tier]) {
                $this->organisations[$key] = Organization::query()->create(['name' => $name, 'domain' => $domain, 'tier' => $tier])->id;
            }
            foreach (DemoCatalogue::CONTACTS as $organisation => $names) {
                foreach ($names as $name) {
                    $domain = $organisation === '' ? 'example.test' : DemoCatalogue::ORGANISATIONS[$organisation][1];
                    $this->contacts[$name] = app(SaveContact::class)([
                        'name' => $name,
                        'email' => Str::lower(str_replace(' ', '.', $name)).'@'.$domain,
                        'organization_id' => $organisation === '' ? null : $this->organisations[$organisation],
                    ])->id;
                }
            }
        });

        // Working weeks (Sunday to Friday, Kathmandu office hours); shifts are informational unless
        // `shifts.enforce` is switched on.
        foreach (DemoCatalogue::AGENTS as $email => $agent) {
            $late = in_array($email, ['deepa@acme.test', 'farid@acme.test'], true);
            $shifts = [];
            foreach ([0, 1, 2, 3, 4, 5] as $weekday) {
                $shifts[] = ['weekday' => $weekday, 'date' => null, 'starts_at' => $late ? '12:00' : '09:00', 'ends_at' => $late ? '20:00' : '17:00', 'is_off' => false];
            }
            $this->as('meera@acme.test', fn () => app(ReplaceAgentShifts::class)($this->profile($email), $shifts));
        }
    }

    /**
     * Workspace-level events of the replay: [minutes ago, callback].
     *
     * @return list<array{int, Closure(): mixed}>
     */
    private function acmeEvents(): array
    {
        $events = [
            [60 * DemoPlan::DAY, fn () => $this->as('meera@acme.test', fn () => app(ChangeUserRoles::class)($this->users['meera@acme.test'], $this->users['priya@acme.test'], ['manager']))],
            [DemoPlan::POLICY_EDIT_AGO, fn () => $this->as('meera@acme.test', fn () => $this->editSlaPolicy())],
            [29 * DemoPlan::DAY, fn () => $this->as('dev@acme.test', function (): void {
                $client = app(CreateApiClient::class)(DemoCatalogue::API_CLIENT, DemoCatalogue::API_CLIENT_SCOPES, $this->users['dev@acme.test']);
                $this->apiClientId = (string) $client->getKey();
                $this->apiClientSecret = $client->plainSecret;
            })],
            [25 * DemoPlan::DAY, fn () => $this->moveContact('hooli')],
            [DemoPlan::HOOLI_UPGRADE_AGO, fn () => $this->as('priya@acme.test', function (): void {
                $organisation = Organization::query()->findOrFail($this->organisations['hooli']);
                $organisation->forceFill(['tier' => OrganizationTier::Premium])->save();
                Audit::record('organization.updated', $organisation, ['tier' => ['old' => 'standard', 'new' => 'premium']]);
            })],
            [12 * DemoPlan::DAY, fn () => $this->moveContact('stark')],
            [10 * DemoPlan::DAY, fn () => $this->as('priya@acme.test', function (): void {
                $this->profile('farid@acme.test')->teams()->attach($this->teams['success'], ['tenant_id' => tenant()?->getTenantKey(), 'joined_at' => $this->clock->now()]);
            })],
            [6 * DemoPlan::DAY, fn () => $this->setAvailability('grace@acme.test', AgentAvailability::Offline)],
            [2 * DemoPlan::DAY, fn () => $this->setAvailability('elena@acme.test', AgentAvailability::Away)],
            [DemoPlan::ASSIGNMENT_OFF_AGO, fn () => $this->as('priya@acme.test', fn () => app(Settings::class)->update('automation.assignment', ['enabled' => false]))],
            [DemoPlan::ASSIGNMENT_ON_AGO, fn () => $this->as('priya@acme.test', fn () => app(Settings::class)->update('automation.assignment', ['enabled' => true]))],
        ];

        // Development only: a subscription to the Compose webhook-echo receiver, early enough that one
        // delivery can use up its retries before now.
        if (! app()->isProduction() && in_array('webhook-echo', (array) config('helpdesk.webhooks.dev_allowed_hosts'), true)) {
            $events[] = [20 * 60, fn () => $this->as('dev@acme.test', function (): void {
                $subscription = app(SaveWebhookSubscription::class)([
                    'name' => 'Ops dashboard (webhook-echo)',
                    'url' => DemoCatalogue::WEBHOOK_URL,
                    'events' => DemoCatalogue::WEBHOOK_EVENTS,
                ], null, $this->users['dev@acme.test']->id);
                $subscription->forceFill(['secret' => (string) config('helpdesk.demo.webhook_secret')])->save();
            })];
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function createTicket(array $spec): void
    {
        $data = [
            'title' => (string) $spec['title'],
            'description' => (string) $spec['description'],
            'contact_id' => $this->contacts[(string) $spec['contact']],
            'category_id' => $this->categories[(string) $spec['category']],
            'impact' => (int) $spec['impact'],
            'urgency' => (int) $spec['urgency'],
        ];
        $creator = is_string($spec['creator']) ? $spec['creator'] : null;
        $ticket = $this->as($creator, fn (): Ticket => $spec['via'] === 'api'
            ? app(CreateTicket::class)($data, null, 'api', $this->apiClientId)
            : app(CreateTicket::class)($data, $creator === null ? null : $this->users[$creator]));

        if ($ticket->number !== $spec['number']) {
            throw new LogicException("Demo ticket {$spec['key']} got number {$ticket->number}, expected {$spec['number']}.");
        }
        if ($ticket->priority_level->value !== $spec['level']) {
            throw new LogicException("Demo ticket {$spec['number']} scored {$ticket->priority_level->value}, expected {$spec['level']}.");
        }
        $this->tickets[(string) $spec['key']] = $ticket->id;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array{ago: int, do: string, text?: string}  $step
     */
    private function ticketStep(array $spec, array $step, bool $attachments): void
    {
        $ticket = Ticket::query()->findOrFail($this->tickets[(string) $spec['key']]);
        $text = $step['text'] ?? null;

        if ($step['do'] === 'triage') {
            $this->triage($ticket);

            return;
        }
        if ($step['do'] === 'escalate') {
            $this->as('priya@acme.test', fn () => app(OverrideTicketPriority::class)($ticket, Priority::P1, 'SLA escalation: first response breached', $this->users['priya@acme.test']));

            return;
        }
        if ($step['do'] === 'autoclose') {
            $this->as(null, fn () => app(AutoCloseTicket::class)($ticket->id, $this->clock->now()));

            return;
        }

        if ($ticket->assigned_agent_id === null) {
            $this->triage($ticket);
            $ticket->refresh();
        }
        $agent = $this->agentEmails[(string) $ticket->assigned_agent_id];
        $user = $this->users[$agent];

        $this->as($agent, function () use ($step, $ticket, $user, $text, $attachments, $spec): void {
            match ($step['do']) {
                'reply' => app(AddComment::class)($ticket, $text ?? 'Thanks for reporting this. We are looking into it now.', 'public', 'user', $user),
                'note' => app(AddComment::class)($ticket, $text ?? 'Checked the logs; nothing unusual yet.', 'internal', 'user', $user),
                'contact' => app(AddComment::class)($ticket, $text ?? 'Here are the details you asked for.', 'public', 'contact', $user),
                'start', 'reopen' => app(TransitionTicket::class)($ticket, TicketStatus::InProgress, null, $user),
                'pend' => app(TransitionTicket::class)($ticket, TicketStatus::Pending, null, $user),
                'resolve' => app(TransitionTicket::class)($ticket, TicketStatus::Resolved, $text ?? 'Fixed.', $user),
                'close' => app(TransitionTicket::class)($ticket, TicketStatus::Closed, null, $user),
                'attach' => $this->attach($ticket, $user, (int) $spec['number'], $attachments),
                default => throw new LogicException("Unknown demo step {$step['do']}."),
            };
        });
    }

    /**
     * What a manager does with an unassigned ticket: the auto-assign button, and when nobody is
     * eligible, a hand pick of the least busy agent with the category's skills.
     */
    private function triage(Ticket $ticket): void
    {
        if ($ticket->assigned_agent_id !== null) {
            return;
        }
        $priya = $this->users['priya@acme.test'];
        $this->as('priya@acme.test', function () use ($ticket, $priya): void {
            $assigned = app(AssignTicket::class)($ticket, null, null, $priya->id);
            if ($assigned->assigned_agent_id !== null) {
                return;
            }
            $required = Category::query()->findOrFail($ticket->category_id)->skills()->pluck('skills.id')->all();
            $agent = AgentProfile::query()->with('skills')->whereIn('id', array_keys($this->agentEmails))->get()
                ->filter(fn (AgentProfile $profile): bool => array_diff($required, $profile->skills->pluck('id')->all()) === [] && $required !== [])
                ->sortBy(fn (AgentProfile $profile): string => sprintf('%05.3f %s', $profile->active_ticket_count / max(1, $profile->capacity), $this->agentEmails[$profile->id]))
                ->first();
            if ($agent === null) {
                throw new LogicException("Nobody can take demo ticket {$ticket->number}.");
            }
            app(AssignTicket::class)($ticket->refresh(), $agent->id, null, $priya->id);
            $this->stats['fallback_assignments']++;
        });
    }

    /**
     * A screenshot and a log file, uploaded through the real intent → object → complete flow and
     * attached to an internal note. Skipped (with a plain note) when object storage is unreachable.
     */
    private function attach(Ticket $ticket, User $user, int $number, bool $attachments): void
    {
        $ids = [];
        if ($attachments) {
            try {
                foreach ($this->attachmentFiles($number) as [$name, $mime, $bytes]) {
                    $intent = app(RegisterUpload::class)($name, strlen($bytes), $mime, $user);
                    $extension = pathinfo($name, PATHINFO_EXTENSION);
                    app(MediaStorage::class)->put(MediaKeys::staging($intent->media_id, $extension), $bytes, $mime);
                    $item = app(CompleteUpload::class)(MediaItem::query()->findOrFail($intent->media_id));
                    if (str_starts_with($mime, 'image/')) {
                        GenerateImageVariants::dispatchSync($item->id);
                    }
                    $ids[] = $item->id;
                    $this->stats['attachments']++;
                }
            } catch (Throwable $exception) {
                Log::warning('Demo seed: attachments skipped, object storage unavailable.', ['error' => $exception->getMessage()]);
                $this->stats['attachments_skipped']++;
                $ids = [];
            }
        }

        app(AddComment::class)($ticket, 'Screenshot and error log from the customer attached.', 'internal', 'user', $user, $ids);
    }

    /**
     * @return list<array{string, string, string}> [file name, MIME type, bytes]
     */
    private function attachmentFiles(int $number): array
    {
        $image = imagecreatetruecolor(960, 540);
        if ($image === false) {
            throw new LogicException('GD cannot create the demo screenshot.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 245, 246, 248));
        imagefilledrectangle($image, 0, 0, 959, 56, (int) imagecolorallocate($image, 37, 99, 235));
        imagefilledrectangle($image, 280, 200, 680, 340, (int) imagecolorallocate($image, 255, 255, 255));
        imagerectangle($image, 280, 200, 680, 340, (int) imagecolorallocate($image, 220, 38, 38));
        $red = (int) imagecolorallocate($image, 220, 38, 38);
        imagestring($image, 5, 300, 240, $number === 1031 ? 'ERR-401: Unauthorized' : '504 Gateway Timeout', $red);
        imagestring($image, 3, 300, 280, 'Ticket #'.$number.' - customer screenshot', (int) imagecolorallocate($image, 80, 80, 80));
        ob_start();
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();

        $log = '';
        for ($line = 0; $line < 120; $line++) {
            $log .= sprintf("2026-09-%02d 10:%02d:%02d ERROR request_id=%08x status=%d path=/portal/login\n", 1 + $line % 28, $line % 60, ($line * 7) % 60, $line * 2654435761 % 4294967296, $number === 1031 ? 401 : 504);
        }

        return [['screenshot.png', 'image/png', $png], ['error.log', 'text/plain', $log]];
    }

    private function editSlaPolicy(): void
    {
        $policy = SlaPolicy::query()->where('is_default', true)->firstOrFail();
        $before = $policy->targets()->get()->mapWithKeys(fn ($target): array => [$target->priority_level->value => [$target->first_response_minutes, $target->resolution_minutes]])->all();
        $policy->version++;
        $policy->save();
        foreach (DemoCatalogue::SLA_TARGETS as $level => [$firstResponse, $resolution]) {
            $policy->targets()->updateOrCreate(['priority_level' => $level], ['first_response_minutes' => $firstResponse, 'resolution_minutes' => $resolution]);
        }
        Audit::record('sla_policy.updated', $policy, ['targets' => ['old' => $before, 'new' => DemoCatalogue::SLA_TARGETS], 'version' => $policy->version]);
    }

    private function moveContact(string $organisation): void
    {
        $this->as('priya@acme.test', function () use ($organisation): void {
            $contact = Contact::query()->findOrFail($this->contacts[DemoCatalogue::MOVING_CONTACT]);
            app(SaveContact::class)(['organization_id' => $this->organisations[$organisation]], $contact);
        });
    }

    private function setAvailability(string $email, AgentAvailability $availability): void
    {
        $this->as('priya@acme.test', function () use ($email, $availability): void {
            $profile = $this->profile($email);
            $before = $profile->only(['user_id', 'capacity', 'availability']);
            $profile->forceFill(['availability' => $availability])->save();
            Audit::record('agent.profile_changed', $profile, ['before' => $before, 'after' => ['availability' => $availability->value]]);
        });
    }

    /**
     * Moves the replay clock to `$instant`, first running the SLA sweep at every moment before it at
     * which a timer warns or breaches, as the minute scheduler would have.
     */
    private function advanceTo(CarbonImmutable $instant): void
    {
        $last = null;
        while (true) {
            $next = DB::table('ticket_sla_timers')
                ->whereIn('state', ['running', 'warning'])
                ->selectRaw("min(CASE WHEN state = 'running' THEN least(warning_at, due_at) ELSE due_at END) AS next")
                ->value('next');
            if ($next === null) {
                break;
            }
            // The scheduler sweeps every minute: the first whole minute after the timer is due.
            $sweepAt = CarbonImmutable::parse((string) $next)->utc()->startOfMinute()->addMinute()->max($this->clock->now());
            if ($sweepAt > $instant || $sweepAt->equalTo($last ?? $instant->addYear())) {
                break;
            }
            $this->clock->set($sweepAt);
            $this->sweep();
            $last = $sweepAt;
        }
        if ($instant > $this->clock->now()) {
            $this->clock->set($instant);
        }
    }

    private function sweep(): void
    {
        $this->stats['sweeps']++;
        $this->as(null, fn () => $this->container->call([new EvaluateSlaTimers((string) tenant()?->getTenantKey()), 'handle']));
    }

    /**
     * Records what the receiver answered for each delivery queued during the replay: the oldest used
     * up all its retries (503), the second failed once and then succeeded, every other one succeeded
     * at once. The attempts go through RecordDeliveryOutcome at their own instants, in time order.
     */
    private function recordWebhookOutcomes(): void
    {
        $deliveries = WebhookDelivery::query()->where('state', DeliveryState::Pending)->orderBy('created_at')->orderBy('id')->get();
        if ($deliveries->isEmpty()) {
            return;
        }

        $attempts = [];
        foreach ($deliveries->values() as $index => $delivery) {
            $at = CarbonImmutable::instance($delivery->created_at)->addSeconds(2);
            $plan = match ($index) {
                0 => array_fill(0, 6, false),
                1 => [false, true],
                default => [true],
            };
            $attempts[] = [$at, $delivery->id, $plan];
        }

        // Each delivery's attempts follow the retry schedule, so they are replayed one at a time.
        $queue = $attempts;
        $order = 0;
        while ($queue !== []) {
            usort($queue, fn (array $a, array $b): int => $a[0] <=> $b[0]);
            [$at, $id, $plan] = array_shift($queue);
            if ($at > $this->now) {
                continue;
            }
            $this->clock->set($at);
            $delivery = WebhookDelivery::query()->findOrFail($id);
            $succeeded = (bool) array_shift($plan);
            app(RecordDeliveryOutcome::class)($delivery, $succeeded
                ? DeliveryAttempt::succeeded(204, null)->withDuration(40 + $order % 30)
                : DeliveryAttempt::failed('http_status', 503, 'Service Unavailable')->withDuration(1200 + $order % 300));
            $order++;
            $delivery->refresh();
            if ($plan !== [] && $delivery->next_attempt_at !== null) {
                $queue[] = [CarbonImmutable::instance($delivery->next_attempt_at), $id, $plan];
            }
        }
        $this->clock->set($this->now);
    }

    /**
     * Notifications for the latest events of each person: their last assignments, SLA warnings and
     * breaches on their tickets, and for Priya the breaches and the escalation. All but the newest two
     * are read.
     */
    private function writeNotifications(): void
    {
        $workspace = (string) tenant()?->getAttribute('name');
        $slug = (string) tenant()?->getAttribute('slug');
        $make = fn (string $class, Ticket $ticket, string $occurrence): TicketNotification => new $class($workspace, $slug, $ticket->id, $ticket->number, $ticket->title, $occurrence);

        $pending = [];
        foreach ($this->agentEmails as $profileId => $email) {
            $assignments = DB::table('ticket_assignments')->where('agent_profile_id', $profileId)
                ->orderByDesc('created_at')->limit(4)->get(['id', 'ticket_id', 'created_at']);
            foreach ($assignments as $row) {
                $pending[] = [$email, CarbonImmutable::parse((string) $row->created_at), TicketAssignedToYou::class, (string) $row->ticket_id, (string) $row->id];
            }
            foreach (['warning' => SlaWarningNotice::class, 'breached' => SlaBreachNotice::class] as $type => $class) {
                $events = DB::table('sla_events')->join('tickets', 'tickets.id', '=', 'sla_events.ticket_id')
                    ->where('tickets.assigned_agent_id', $profileId)->where('sla_events.type', $type)
                    ->orderByDesc('sla_events.created_at')->limit(2)->get(['sla_events.timer_id', 'sla_events.ticket_id', 'sla_events.created_at']);
                foreach ($events as $row) {
                    $pending[] = [$email, CarbonImmutable::parse((string) $row->created_at), $class, (string) $row->ticket_id, (string) $row->timer_id];
                }
            }
        }
        $breaches = DB::table('sla_events')->where('type', 'breached')->orderByDesc('created_at')->limit(3)->get(['timer_id', 'ticket_id', 'created_at']);
        foreach ($breaches as $row) {
            $pending[] = ['priya@acme.test', CarbonImmutable::parse((string) $row->created_at), SlaBreachNotice::class, (string) $row->ticket_id, (string) $row->timer_id];
        }
        $escalated = Ticket::query()->where('number', 1103)->first();
        if ($escalated !== null) {
            $at = DB::table('ticket_events')->where('ticket_id', $escalated->id)->where('type', 'priority_overridden')->value('created_at');
            if ($at !== null) {
                $pending[] = ['priya@acme.test', CarbonImmutable::parse((string) $at), TicketEscalated::class, $escalated->id, 'P3-P1'];
                $pending[] = [$this->agentEmails[(string) $escalated->assigned_agent_id], CarbonImmutable::parse((string) $at), TicketEscalated::class, $escalated->id, 'P3-P1'];
            }
        }

        usort($pending, fn (array $a, array $b): int => $a[1] <=> $b[1]);
        foreach ($pending as [$email, $at, $class, $ticketId, $occurrence]) {
            $this->clock->set($at);
            $ticket = Ticket::query()->findOrFail($ticketId);
            $this->users[$email]->notifyNow($make($class, $ticket, $occurrence), [TenantDatabaseChannel::class]);
        }
        $this->clock->set($this->now);

        foreach (array_unique(array_column($pending, 0)) as $email) {
            $keep = Notification::query()->where('notifiable_id', $this->users[$email]->id)->orderByDesc('created_at')->orderByDesc('id')->limit(2)->pluck('id')->all();
            Notification::query()->where('notifiable_id', $this->users[$email]->id)->whereNotIn('id', $keep)
                ->update(['read_at' => DB::raw("created_at + interval '20 minutes'")]);
        }
    }

    private function buildGlobex(): string
    {
        $this->resetMaps();
        $this->clock->set($this->at(60 * DemoPlan::DAY));
        $tenant = app(ProvisionTenant::class)(DemoCatalogue::GLOBEX, DemoCatalogue::WORKSPACES[DemoCatalogue::GLOBEX], timezone: 'Europe/London')['tenant'];
        $this->suspendWhileBuilding($tenant);

        $tenant->run(function (): void {
            $this->as(null, function (): void {
                $team = Team::query()->create(['name' => 'Store Support', 'description' => 'Tills, stores and loyalty.']);
                foreach (DemoCatalogue::GLOBEX_STAFF as $email => [$name, $role]) {
                    $this->users[$email] = $this->user($email, $name, $role);
                    $this->agentProfile($email, 10, [$team->id]);
                }
                foreach (DemoCatalogue::GLOBEX_CONTACTS as $name) {
                    $this->contacts[$name] = app(SaveContact::class)(['name' => $name, 'email' => Str::lower(str_replace(' ', '.', $name)).'@globex-customers.test'])->id;
                }
            });
            $category = (string) Category::query()->orderBy('sort_order')->value('id');

            $ages = [14000, 11000, 8000, 6000, 4000, 2500, 1500, 500];
            foreach (DemoCatalogue::GLOBEX_TICKETS as $index => [$title, $description, $impact, $urgency]) {
                $this->clock->set($this->at($ages[$index]));
                $ticket = $this->as('sam@globex.test', fn (): Ticket => app(CreateTicket::class)([
                    'title' => $title,
                    'description' => $description,
                    'contact_id' => $this->contacts[DemoCatalogue::GLOBEX_CONTACTS[$index % 5]],
                    'category_id' => $category,
                    'impact' => $impact,
                    'urgency' => $urgency,
                ], $this->users['sam@globex.test']));
                if ($ticket->assigned_agent_id === null) {
                    continue;
                }
                $user = $this->users[$this->agentEmails[(string) $ticket->assigned_agent_id]];
                $this->clock->set($this->at($ages[$index] - 20));
                $this->as($user->email, fn () => app(AddComment::class)($ticket, 'Thanks, we are on it.', 'public', 'user', $user));
                if ($index < 4) {
                    $this->clock->set($this->at($ages[$index] - 300));
                    $this->as($user->email, fn () => app(TransitionTicket::class)($ticket, TicketStatus::Resolved, 'Fixed in store.', $user));
                } elseif ($index < 6) {
                    $this->clock->set($this->at($ages[$index] - 40));
                    $this->as($user->email, fn () => app(TransitionTicket::class)($ticket, TicketStatus::InProgress, null, $user));
                }
            }

            $this->clock->set($this->now);
            if (! app()->isProduction() && in_array('webhook-echo', (array) config('helpdesk.webhooks.dev_allowed_hosts'), true)) {
                $this->as('sam@globex.test', fn () => app(SaveWebhookSubscription::class)([
                    'name' => 'Store systems',
                    'url' => DemoCatalogue::WEBHOOK_URL,
                    'events' => ['ticket.created'],
                ], null, $this->users['sam@globex.test']->id));
            }
            $this->sweep();
        });

        return (string) $tenant->id;
    }

    /**
     * The scheduler's sweeps (SLA, auto-close, ageing, webhooks, snapshots) only visit active workspaces.
     * While the replay writes history with past instants the workspace is suspended, so a real-time sweep
     * cannot act on it halfway; build() activates both workspaces at the end.
     */
    /**
     * Billing (ADR-0025): both workspaces pay for Standard, a year past the reset, so the demo never turns
     * read-only; Acme has two approved monthly payments and one receipt waiting in the console's queue.
     */
    private function seedBilling(string $acmeId, string $globexId, bool $attachments): void
    {
        $standard = Plan::query()->where('code', 'standard')->first();
        if ($standard === null) {
            return;
        }
        // Where to pay, unless the platform already says (a real installation keeps its own text).
        $settings = app(BillingSettings::class);
        if ($settings->paymentInstructions() === '') {
            $settings->setPaymentInstructions("Himalayan Bank, Smart Helpdesk Pvt. Ltd., account 01234567890123\neSewa or Khalti: 9800000000\nPut your workspace address in the remarks.");
        }
        foreach ([$acmeId, $globexId] as $tenantId) {
            Subscription::query()->updateOrCreate(
                ['tenant_id' => $tenantId],
                ['plan_id' => $standard->id, 'ends_at' => $this->now->addYear(), 'reminders' => []],
            );
        }

        $payment = fn (array $attributes): SubscriptionPayment => SubscriptionPayment::query()->create([
            'tenant_id' => $acmeId, 'plan_id' => $standard->id, 'periods' => 1, 'amount_minor' => $standard->price_minor,
            'currency' => $standard->currency, 'method' => PaymentMethod::BankTransfer,
            'submitted_by_name' => 'Meera Joshi', 'submitted_by_email' => 'meera@acme.test', ...$attributes,
        ]);
        foreach ([[70, 'TRX-20260716'], [40, 'TRX-20260815']] as [$daysAgo, $reference]) {
            $paidOn = $this->now->subDays($daysAgo);
            $payment([
                'paid_on' => $paidOn->toDateString(), 'reference' => $reference, 'status' => PaymentStatus::Approved,
                'reviewed_at' => $paidOn->addDay(), 'period_starts_at' => $paidOn, 'period_ends_at' => $paidOn->addMonth(),
            ]);
        }

        // One receipt to review, with its image, when object storage is reachable.
        $receiptId = null;
        $tenant = Tenant::query()->findOrFail($acmeId);
        if ($attachments) {
            try {
                $receiptId = $tenant->run(function (): string {
                    $owner = User::query()->where('email', 'meera@acme.test')->firstOrFail();
                    $bytes = $this->receiptImage();
                    $intent = app(RegisterUpload::class)('receipt-2026-09.png', strlen($bytes), 'image/png', $owner, 'receipt');
                    app(MediaStorage::class)->put(MediaKeys::staging($intent->media_id, 'png'), $bytes, 'image/png');
                    $item = app(CompleteUpload::class)(MediaItem::query()->findOrFail($intent->media_id));
                    GenerateImageVariants::dispatchSync($item->id);

                    return $item->id;
                });
            } catch (Throwable $exception) {
                Log::warning('Demo seed: the receipt was skipped, object storage unavailable.', ['error' => $exception->getMessage()]);
            }
        }
        $pending = $payment([
            'periods' => 3, 'amount_minor' => $standard->price_minor * 3, 'paid_on' => $this->now->subDay()->toDateString(),
            'reference' => 'TRX-20260924', 'note' => 'Three months, paid from the office account.',
            'status' => PaymentStatus::Pending, 'receipt_media_id' => $receiptId,
        ]);
        if ($receiptId !== null) {
            $tenant->run(fn () => Mediable::query()->create([
                'media_item_id' => $receiptId, 'mediable_type' => SubscriptionPayment::MEDIABLE_TYPE,
                'mediable_id' => $pending->id, 'role' => 'receipt', 'created_at' => $this->now,
            ]));
        }
    }

    /** A bank receipt as a PNG: the bank, the amount, the reference. */
    private function receiptImage(): string
    {
        $image = imagecreatetruecolor(600, 800);
        if ($image === false) {
            throw new LogicException('GD cannot create the demo receipt.');
        }
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 599, 90, (int) imagecolorallocate($image, 15, 118, 110));
        $white = (int) imagecolorallocate($image, 255, 255, 255);
        $ink = (int) imagecolorallocate($image, 30, 30, 30);
        imagestring($image, 5, 30, 35, 'HIMALAYAN BANK - PAYMENT RECEIPT', $white);
        $lines = ['Paid to:    Smart Helpdesk', 'From:       Acme Support Pvt. Ltd.', 'Amount:     NPR 7,500.00', 'Reference:  TRX-20260924', 'For:        Standard plan, 3 months', 'Status:     Successful'];
        foreach ($lines as $index => $line) {
            imagestring($image, 5, 40, 150 + $index * 50, $line, $ink);
        }
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }

    private function suspendWhileBuilding(Tenant $tenant): void
    {
        DB::table('tenants')->where('id', $tenant->id)->update(['status' => TenantStatus::Suspended->value]);
    }

    /**
     * The Platform Super Admin of the first demo step. Created when missing; outside production its
     * password is also set to the demo password, so the demo can sign in whatever a developer set before.
     * A production instance keeps its platform admin's password.
     */
    private function ensurePlatformAdmin(): void
    {
        $admin = PlatformUser::query()->whereRaw('lower(email) = ?', [DemoCatalogue::PLATFORM_ADMIN])->first();
        if ($admin !== null) {
            if (! app()->isProduction()) {
                $admin->forceFill(['password' => (string) config('helpdesk.demo.password')])->save();
            }

            return;
        }
        $admin = PlatformUser::query()->create(['name' => 'Sam Platform', 'email' => DemoCatalogue::PLATFORM_ADMIN, 'password' => (string) config('helpdesk.demo.password')]);
        Audit::record('platform_user.created', $admin, tenantId: null, actorType: ActorType::System);
    }

    private function user(string $email, string $name, string $role): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => (string) config('helpdesk.demo.password'),
            'is_active' => true,
            'preferences' => [],
        ]);
        $user->forceFill(['email_verified_at' => $this->clock->now()])->save();
        $user->syncRoles([$role]);

        return $user;
    }

    /** @param list<string> $teamIds */
    private function agentProfile(string $email, int $capacity, array $teamIds): AgentProfile
    {
        $profile = AgentProfile::query()->create([
            'user_id' => $this->users[$email]->id,
            'capacity' => $capacity,
            'availability' => AgentAvailability::Available,
            'active_ticket_count' => 0,
        ]);
        foreach ($teamIds as $teamId) {
            $profile->teams()->attach($teamId, ['tenant_id' => $profile->tenant_id, 'joined_at' => $this->clock->now()]);
        }
        $this->agentEmails[$profile->id] = $email;

        return $profile;
    }

    private function profile(string $email): AgentProfile
    {
        $id = array_search($email, $this->agentEmails, true);

        return AgentProfile::query()->findOrFail($id);
    }

    /**
     * Runs `$callback` as the workspace user with that email (null: the system), so audit rows and
     * change capture name the right actor.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function as(?string $email, Closure $callback): mixed
    {
        $guard = Auth::guard();
        if ($email === null) {
            $guard->forgetUser();
        } else {
            $guard->setUser($this->users[$email]);
        }
        app(RlsTenancyBootstrapper::class)->refreshActor();
        app(PermissionRegistrar::class)->setPermissionsTeamId(tenant()?->getTenantKey());

        try {
            return $callback();
        } finally {
            $guard->forgetUser();
            app(RlsTenancyBootstrapper::class)->refreshActor();
        }
    }

    private function at(int $minutesAgo): CarbonImmutable
    {
        return $this->now->subMinutes($minutesAgo);
    }

    private function resetMaps(): void
    {
        $this->users = [];
        $this->agentEmails = [];
        $this->categories = [];
        $this->teams = [];
        $this->organisations = [];
        $this->contacts = [];
        $this->tickets = [];
    }
}
