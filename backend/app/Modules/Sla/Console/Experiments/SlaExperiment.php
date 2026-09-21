<?php

declare(strict_types=1);

namespace App\Modules\Sla\Console\Experiments;

use App\Modules\Sla\Contracts\BusinessCalendar;
use App\Modules\Sla\Contracts\SlaStrategy;
use App\Modules\Sla\Domain\Calendar\TwentyFourSevenCalendar;
use App\Modules\Sla\Domain\Calendar\WorkingHoursCalendar;
use App\Modules\Sla\Domain\Timer\SlaEventType;
use App\Modules\Sla\Domain\Timer\TimerData;
use App\Modules\Sla\Domain\Timer\TimerKind;
use App\Modules\Sla\Domain\Timer\TimerOutcome;
use App\Support\Experiments\Experiment;
use App\Support\Experiments\ExperimentContext;
use App\Support\Time\FrozenClock;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * E4 — SLA scenario verification (evaluation-methodology.md §4): each hand-written timeline runs
 * through the `SlaStrategy` contract with a frozen clock; expected and actual deadlines, states and
 * notification counts are tabulated (table T8).
 */
final class SlaExperiment implements Experiment
{
    private const string FORMAT = 'Y-m-d H:i';

    public function key(): string
    {
        return 'e4';
    }

    public function title(): string
    {
        return 'SLA scenario verification';
    }

    public function run(ExperimentContext $context): array
    {
        $clock = new FrozenClock;
        $warningFraction = (float) config('helpdesk.sla.warning_fraction', 0.75);
        $strategy = $context->strategy('sla', SlaStrategy::class, ['clock' => $clock, 'warningFraction' => $warningFraction]);

        $rows = [];
        $name = $version = '';
        foreach ($context->datasetJson('sla-scenarios.json')['timelines'] as $timeline) {
            $calendar = self::calendar($timeline['calendar']);
            $timezone = $timeline['timezone'];
            $timer = null;
            $counts = ['warning' => 0, 'breached' => 0];

            foreach ($timeline['steps'] as $step) {
                $instants = $step['do'] === 'checks'
                    ? self::every($step['from'], $step['to'], $step['every_minutes'], $timezone)
                    : [CarbonImmutable::parse($step['at'], $timezone)];

                foreach ($instants as $instant) {
                    $clock->set($instant);
                    $outcome = $this->apply($strategy, $step, $timeline, $timer, $calendar);
                    $timer = $outcome->timer;
                    [$name, $version] = [$outcome->strategy, $outcome->strategyVersion];
                    $counts['warning'] += $outcome->has(SlaEventType::Warning) ? 1 : 0;
                    $counts['breached'] += $outcome->has(SlaEventType::Breached) ? 1 : 0;
                }
            }

            if ($timer === null) {
                throw new LogicException("Timeline {$timeline['id']} never starts a timer.");
            }
            $expected = $timeline['expected'];
            $actual = [
                'state' => $timer->state->value,
                'warning_at' => $timer->warningAt->setTimezone($timezone)->format(self::FORMAT),
                'due_at' => $timer->dueAt->setTimezone($timezone)->format(self::FORMAT),
                'warnings' => $counts['warning'],
                'breaches' => $counts['breached'],
            ];

            $rows[] = [
                'id' => $timeline['id'],
                'title' => $timeline['title'],
                'calendar' => $timeline['calendar']['type'],
                'timer' => $timeline['timer'],
                'expected_state' => $expected['state'],
                'actual_state' => $actual['state'],
                'expected_warning_at' => $expected['warning_at'],
                'actual_warning_at' => $actual['warning_at'],
                'expected_due_at' => $expected['due_at'],
                'actual_due_at' => $actual['due_at'],
                'expected_warnings' => $expected['warnings'],
                'actual_warnings' => $actual['warnings'],
                'expected_breaches' => $expected['breaches'],
                'actual_breaches' => $actual['breaches'],
                'match' => $actual === array_intersect_key($expected, $actual),
            ];
        }

        $context->output->csv('t8-sla-scenarios.csv', $rows);
        $context->output->json('summary.json', [
            'timelines' => count($rows),
            'matching' => count(array_filter($rows, fn (array $row): bool => $row['match'])),
            'mismatches' => array_column(array_filter($rows, fn (array $row): bool => ! $row['match']), 'id'),
        ]);

        return [
            'strategy' => ['name' => $name, 'version' => $version, 'class' => $strategy::class],
            'settings' => ['warning_fraction' => $warningFraction, 'clock' => 'frozen; set to each step time'],
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $timeline
     */
    private function apply(SlaStrategy $strategy, array $step, array $timeline, ?TimerData $timer, BusinessCalendar $calendar): TimerOutcome
    {
        $target = 60 * (int) ($step['target_minutes'] ?? $timeline['target_minutes']);
        if ($step['do'] === 'start') {
            return $strategy->start(TimerKind::from($timeline['timer']), $target, $calendar);
        }
        if ($timer === null) {
            throw new LogicException("Timeline {$timeline['id']}: '{$step['do']}' before 'start'.");
        }

        return match ($step['do']) {
            'pause' => $strategy->pause($timer),
            'resume' => $strategy->resume($timer, $calendar),
            'complete' => $strategy->complete($timer, $calendar),
            'cancel' => $strategy->cancel($timer),
            'recompute' => $strategy->recompute($timer, $target, $calendar),
            'check', 'checks' => $strategy->check($timer),
            default => throw new LogicException("Unknown step '{$step['do']}' in timeline {$timeline['id']}."),
        };
    }

    /**
     * @param  array{type: string, timezone?: string, hours?: array<string, list<array{string, string}>>, holidays?: list<string>}  $calendar
     */
    private static function calendar(array $calendar): BusinessCalendar
    {
        return $calendar['type'] === '24x7'
            ? new TwentyFourSevenCalendar
            : new WorkingHoursCalendar((string) $calendar['timezone'], $calendar['hours'] ?? [], $calendar['holidays'] ?? []);
    }

    /**
     * @return list<CarbonImmutable>
     */
    private static function every(string $from, string $to, int $minutes, string $timezone): array
    {
        $instants = [];
        $end = CarbonImmutable::parse($to, $timezone);
        for ($at = CarbonImmutable::parse($from, $timezone); $at <= $end; $at = $at->addMinutes($minutes)) {
            $instants[] = $at;
        }

        return $instants;
    }
}
