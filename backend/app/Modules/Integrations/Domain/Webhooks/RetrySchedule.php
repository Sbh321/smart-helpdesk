<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Domain\Webhooks;

use Carbon\CarbonImmutable;
use Closure;

/**
 * Backoff of failed deliveries (docs/07-api/webhooks.md §Delivery): after the first attempt fails,
 * retries follow at +1 min, +5 min, +30 min, +2 h and +12 h, each with ±jitter. When the retry at
 * +12 h fails too (six attempts in the sequence) the delivery is `dead`.
 */
final readonly class RetrySchedule
{
    /** Seconds to wait after the n-th failed attempt of a sequence (index 0 = first attempt). */
    public const DELAYS = [60, 300, 1800, 7200, 43200];

    /** One first attempt plus one retry per delay. */
    public const MAX_ATTEMPTS = 6;

    /**
     * @param  float  $jitter  fraction of the delay, e.g. 0.2 for ±20 %
     * @param  (Closure(int, int): int)|null  $random  inclusive random integer source, for tests
     */
    public function __construct(private float $jitter = 0.2, private ?Closure $random = null) {}

    /**
     * When to try again after `$failedAttempts` failed attempts in the current sequence, or null when
     * the delivery is dead.
     */
    public function nextAttemptAt(int $failedAttempts, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($failedAttempts < 1 || $failedAttempts >= self::MAX_ATTEMPTS) {
            return null;
        }

        return $now->addSeconds($this->delaySeconds($failedAttempts));
    }

    public function delaySeconds(int $failedAttempts): int
    {
        $base = self::DELAYS[$failedAttempts - 1];
        $spread = (int) round($base * $this->jitter);
        if ($spread === 0) {
            return $base;
        }

        $random = $this->random ?? random_int(...);

        return $base + $random(-$spread, $spread);
    }
}
