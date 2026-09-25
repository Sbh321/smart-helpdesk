<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Models\User;
use App\Modules\Billing\Enums\SubscriptionState;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Support\BillingSettings;
use App\Modules\Billing\Support\Subscriptions;
use App\Modules\Billing\Support\SubscriptionStateSql;
use App\Modules\Tenancy\Enums\TenantStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tickets\Models\Ticket;
use App\Support\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The console's start page (ADR-0025 §9): workspaces by state, growth, people and activity inside the
 * workspaces, revenue from approved payments, receipts to review and subscriptions ending soon.
 */
final readonly class PlatformDashboardController
{
    private const WEEKS = 12;

    private const MONTHS = 12;

    private const ENDING_WITHIN_DAYS = 14;

    public function __construct(private Clock $clock, private BillingSettings $settings, private Subscriptions $subscriptions) {}

    /**
     * Show the platform dashboard.
     *
     * @response array{data: array{workspaces: array{total: int, active: int, suspended: int, archived: int, by_subscription: array<string, int>}, new_workspaces: list<array{week: string, console: int, signup: int}>, inside: array{active_users: int, tickets_30d: int, measured_at: string}, revenue: list<array{currency: string, this_month_minor: int, last_month_minor: int, months: list<array{month: string, amount_minor: int}>}>, payments: array{pending: int}, ending_soon: list<array{workspace: array{id: string, slug: string, name: string}, plan: string, state: string, ends_at: string, days_left: int|null}>, recent_workspaces: list<array{id: string, slug: string, name: string, created_at: string|null, source: 'signup'|'console'}>}}
     */
    public function __invoke(): JsonResponse
    {
        $now = $this->clock->now();

        return new JsonResponse(['data' => [
            'workspaces' => $this->workspaces($now),
            'new_workspaces' => $this->newWorkspaces($now),
            'inside' => $this->inside($now),
            'revenue' => $this->revenue($now),
            'payments' => ['pending' => DB::table('subscription_payments')->where('status', 'pending')->count()],
            'ending_soon' => $this->endingSoon($now),
            'recent_workspaces' => $this->recent(),
        ]]);
    }

    /** @return array{total: int, active: int, suspended: int, archived: int, by_subscription: array<string, int>} */
    private function workspaces(CarbonImmutable $now): array
    {
        $byStatus = DB::table('tenants')->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');
        [$state, $bindings] = SubscriptionStateSql::expression($now, $this->settings->graceDays());
        $query = DB::table('tenants')->where('tenants.status', TenantStatus::Active->value);
        SubscriptionStateSql::joinOnto($query);
        // Grouped over a subquery: PostgreSQL treats a parameterised CASE in GROUP BY as a new expression.
        $byState = DB::query()->fromSub($query->selectRaw("({$state}) as state", $bindings), 'states')
            ->select('state', DB::raw('count(*) as n'))->groupBy('state')->pluck('n', 'state');
        $bySubscription = [];
        foreach (SubscriptionState::cases() as $case) {
            $bySubscription[$case->value] = (int) ($byState[$case->value] ?? 0);
        }

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['active'] ?? 0),
            'suspended' => (int) ($byStatus['suspended'] ?? 0),
            'archived' => (int) ($byStatus['archived'] ?? 0),
            'by_subscription' => $bySubscription,
        ];
    }

    /** @return list<array{week: string, console: int, signup: int}> */
    private function newWorkspaces(CarbonImmutable $now): array
    {
        $first = $now->startOfWeek()->subWeeks(self::WEEKS - 1);
        $rows = DB::table('tenants')
            ->leftJoin('workspace_signups', 'workspace_signups.tenant_id', '=', 'tenants.id')
            ->where('tenants.created_at', '>=', $first)
            ->selectRaw("date_trunc('week', tenants.created_at at time zone 'UTC')::date as week, count(*) filter (where workspace_signups.id is null) as console, count(workspace_signups.id) as signup")
            ->groupBy('week')
            ->get()->keyBy(fn (object $row): string => (string) $row->week);

        $weeks = [];
        for ($i = 0; $i < self::WEEKS; $i++) {
            $week = $first->addWeeks($i)->toDateString();
            $weeks[] = ['week' => $week, 'console' => (int) ($rows[$week]->console ?? 0), 'signup' => (int) ($rows[$week]->signup ?? 0)];
        }

        return $weeks;
    }

    /**
     * People and tickets live inside the workspaces, behind row-level security: counted workspace by
     * workspace in its own context and cached for five minutes. MVP-SHORTCUT: one visit per workspace;
     * V1: a platform read model fed by change capture (V1-PL-20).
     *
     * @return array{active_users: int, tickets_30d: int, measured_at: string}
     */
    private function inside(CarbonImmutable $now): array
    {
        /** @var array{active_users: int, tickets_30d: int, measured_at: string} */
        return Cache::remember('platform-dashboard:inside', 300, function () use ($now): array {
            $users = 0;
            $tickets = 0;
            foreach (Tenant::query()->where('status', TenantStatus::Active)->cursor() as $tenant) {
                [$u, $t] = $tenant->run(fn (): array => [
                    User::query()->where('is_active', true)->count(),
                    Ticket::query()->where('created_at', '>=', $now->subDays(30))->count(),
                ]);
                $users += $u;
                $tickets += $t;
            }

            return ['active_users' => $users, 'tickets_30d' => $tickets, 'measured_at' => $now->toIso8601String()];
        });
    }

    /** @return list<array{currency: string, this_month_minor: int, last_month_minor: int, months: list<array{month: string, amount_minor: int}>}> */
    private function revenue(CarbonImmutable $now): array
    {
        $first = $now->startOfMonth()->subMonths(self::MONTHS - 1);
        $rows = DB::table('subscription_payments')
            ->where('status', 'approved')
            ->where('paid_on', '>=', $first->toDateString())
            ->selectRaw("currency, to_char(date_trunc('month', paid_on), 'YYYY-MM') as month, sum(amount_minor) as total")
            ->groupBy('currency', 'month')
            ->get();

        $result = [];
        foreach ($rows->groupBy('currency') as $currency => $group) {
            $byMonth = $group->pluck('total', 'month');
            $months = [];
            for ($i = 0; $i < self::MONTHS; $i++) {
                $month = $first->addMonths($i)->format('Y-m');
                $months[] = ['month' => $month, 'amount_minor' => (int) ($byMonth[$month] ?? 0)];
            }
            $result[] = [
                'currency' => (string) $currency,
                'this_month_minor' => (int) ($byMonth[$now->format('Y-m')] ?? 0),
                'last_month_minor' => (int) ($byMonth[$now->subMonthNoOverflow()->format('Y-m')] ?? 0),
                'months' => $months,
            ];
        }

        return $result;
    }

    /** @return list<array{workspace: array{id: string, slug: string, name: string}, plan: string, state: string, ends_at: string, days_left: int|null}> */
    private function endingSoon(CarbonImmutable $now): array
    {
        $graceDays = $this->settings->graceDays();

        return Subscription::query()->with(['plan', 'tenant'])
            ->whereHas('tenant', fn ($query) => $query->where('status', TenantStatus::Active))
            ->where('ends_at', '<=', $now->addDays(self::ENDING_WITHIN_DAYS))
            ->where('ends_at', '>', $now->subDays($graceDays))
            ->orderBy('ends_at')
            ->limit(10)
            ->get()
            ->map(function (Subscription $subscription): array {
                $status = $this->subscriptions->status($subscription);

                return [
                    'workspace' => ['id' => $subscription->tenant->id, 'slug' => (string) $subscription->tenant->slug, 'name' => (string) $subscription->tenant->name],
                    'plan' => $subscription->plan->name,
                    'state' => $status->state->value,
                    'ends_at' => $subscription->ends_at->toIso8601String(),
                    'days_left' => $status->daysLeft(),
                ];
            })->values()->all();
    }

    /** @return list<array{id: string, slug: string, name: string, created_at: string|null, source: 'signup'|'console'}> */
    private function recent(): array
    {
        return DB::table('tenants')
            ->leftJoin('workspace_signups', 'workspace_signups.tenant_id', '=', 'tenants.id')
            ->orderByDesc('tenants.created_at')
            ->limit(5)
            ->get(['tenants.id', 'tenants.slug', 'tenants.name', 'tenants.created_at', 'workspace_signups.id as signup_id'])
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'created_at' => $row->created_at === null ? null : CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
                'source' => $row->signup_id === null ? 'console' : 'signup',
            ])->values()->all();
    }
}
