<?php

declare(strict_types=1);

use App\Modules\Automation\Strategies\Baseline\BasicWeightedPriority;
use App\Modules\Automation\Strategies\Baseline\JaccardDuplicates;
use App\Modules\Automation\Strategies\Baseline\LeastLoadedAgent;
use App\Modules\Sla\Strategies\Baseline\SimpleSlaTimer;
use App\Support\Time\Clock;
use Tests\Contracts\AssignmentStrategyContract;
use Tests\Contracts\DuplicateStrategyContract;
use Tests\Contracts\PriorityStrategyContract;
use Tests\Contracts\SlaStrategyContract;

// The academic baselines (ADR-0023) against the shared contract suites. A replacement strategy adds one line per contract.

PriorityStrategyContract::register('BasicWeightedPriority', static fn () => new BasicWeightedPriority);
AssignmentStrategyContract::register('LeastLoadedAgent', static fn () => new LeastLoadedAgent);
DuplicateStrategyContract::register('JaccardDuplicates', static fn () => new JaccardDuplicates);
SlaStrategyContract::register('SimpleSlaTimer', static fn (Clock $clock) => new SimpleSlaTimer($clock));
