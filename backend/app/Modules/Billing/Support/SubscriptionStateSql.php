<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

use App\Modules\Billing\Enums\SubscriptionState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * The SQL twin of `SubscriptionStatus` for lists and counts: a CASE over `subscriptions` (alias `s`)
 * and `plans` (alias `p`), left-joined so a workspace without a subscription reads `none`.
 */
final class SubscriptionStateSql
{
    /**
     * @return array{0: string, 1: list<string>}
     */
    public static function expression(CarbonImmutable $now, int $graceDays): array
    {
        $sql = "CASE WHEN s.id IS NULL THEN 'none'"
            ." WHEN s.ends_at > ? THEN CASE WHEN p.kind = 'trial' THEN 'trialing' ELSE 'active' END"
            ." WHEN s.ends_at + make_interval(days => ?) > ? THEN 'grace'"
            ." ELSE 'expired' END";
        $at = $now->utc()->toIso8601String();

        return [$sql, [$at, (string) $graceDays, $at]];
    }

    /**
     * Joins the subscription and plan onto a `tenants` query.
     *
     * @template TQuery of Builder<*>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function joinOnto(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        return $query
            ->leftJoin('subscriptions as s', 's.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans as p', 'p.id', '=', 's.plan_id');
    }

    /**
     * Keeps the rows in one of the given states (the joins must be in place).
     *
     * @param  Builder<*>|QueryBuilder  $query
     * @param  list<SubscriptionState>  $states
     */
    public static function whereState(Builder|QueryBuilder $query, array $states, CarbonImmutable $now, int $graceDays): void
    {
        [$sql, $bindings] = self::expression($now, $graceDays);
        $placeholders = implode(', ', array_fill(0, count($states), '?'));
        $query->whereRaw("({$sql}) IN ({$placeholders})", [...$bindings, ...array_map(fn (SubscriptionState $state): string => $state->value, $states)]);
    }
}
