<?php

declare(strict_types=1);

namespace App\Modules\Demo\Seeding;

use App\Modules\Automation\Console\Experiments\Datasets\TicketTextGenerator;
use App\Support\Experiments\SeededRandom;
use LogicException;

/**
 * The scripted timeline of the demo workspace (roadmap/11-demo-dataset.md), pure and seeded: the same
 * seed gives the same tickets, texts, contacts, priorities and steps on every machine. Times are whole
 * minutes before "now" (`ago`), so the replay can put them on any day.
 *
 * Live tickets (the last 30 days) are numbered 1001–1120 in creation order, and their status, age,
 * priority and category counts are exact by construction (the table in the dataset page). History
 * tickets (days −90 to −30) come before them and are all closed. Priorities are not stored here as
 * levels: each ticket gets the impact and urgency that make the real PriorityStrategy compute the
 * wanted level for its contact's tier, so the "Why?" panel is genuine.
 *
 * @phpstan-type Step array{ago: int, do: string, text?: string}
 * @phpstan-type TicketSpec array{key: string, number: int, title: string, description: string, category: string, contact: string, impact: int, urgency: int, level: string, status: string, ago: int, via: string, creator: string|null, steps: list<Step>}
 */
final class DemoPlan
{
    public const int DAY = 1440;

    /** Live window and history window, minutes before now. */
    public const int LIVE_DAYS = 30;

    public const int HISTORY_DAYS = 90;

    /** Meera edits the SLA policy here; tickets created before it run on the provisioning defaults. */
    public const int POLICY_EDIT_AGO = 30 * self::DAY + 60;

    /** Hooli becomes premium here. */
    public const int HOOLI_UPGRADE_AGO = 20 * self::DAY;

    /** Priya turns automatic assignment off for a triage review, and back on shortly before now. */
    public const int ASSIGNMENT_OFF_AGO = 23 * 60 + 5;

    public const int ASSIGNMENT_ON_AGO = 12;

    /** Target counts of the live tickets (the dataset page's distribution table). */
    public const array STATUS_TARGETS = ['open' => 18, 'assigned' => 22, 'in_progress' => 30, 'pending' => 12, 'resolved' => 24, 'closed' => 14];

    public const array PRIORITY_TARGETS = ['P1' => 10, 'P2' => 30, 'P3' => 55, 'P4' => 25];

    public const array CATEGORY_TARGETS = ['billing' => 22, 'refund' => 14, 'technical' => 34, 'network' => 18, 'account' => 20, 'onboarding' => 12];

    /**
     * Age buckets of the live tickets: [first number, last number, oldest ago, youngest ago] and the
     * statuses of the generated (non-anchor) tickets in each bucket.
     */
    private const array BUCKETS = [
        '7-30d' => [1001, 1025, 43140, 10085, ['resolved' => 11, 'closed' => 14]],
        '3-7d' => [1026, 1060, 10075, 4322, ['in_progress' => 12, 'pending' => 7, 'resolved' => 9]],
        '1-3d' => [1061, 1095, 4318, 1445, ['assigned' => 16, 'in_progress' => 6, 'pending' => 3]],
        '0-1d' => [1096, 1120, 1380, 15, ['open' => 18, 'assigned' => 1]],
    ];

    /**
     * Anchor tickets and the three near-breach tickets (1062–1064: generated texts, fixed timelines,
     * resolution due 25–35 minutes after "now"): number => [ago, status, level, steps]. An anchor's
     * level is what the strategy computes from the catalogue's impact, urgency and tier.
     */
    private const array SCRIPTED = [
        1031 => [9600, 'in_progress', 'P3', [[9590, 'attach'], [9570, 'reply'], [9550, 'start'], [9540, 'note'], [9500, 'pend'], [2800, 'contact'], [2790, 'note']]],
        1032 => [9592, 'in_progress', 'P3', [[9560, 'reply'], [9540, 'start'], [9530, 'note', 'Same symptom as #1031; following the same fix.'], [9500, 'pend'], [2600, 'contact']]],
        1040 => [8400, 'resolved', 'P3', [[8370, 'reply'], [8350, 'start'], [7000, 'resolve', 'Regenerated the invoice PDF; the new copy renders correctly.'], [6900, 'contact', 'Thanks, the PDF opens now.']]],
        1041 => [8380, 'in_progress', 'P3', [[8350, 'reply'], [8340, 'start'], [8330, 'note', 'Probably the same renderer bug as #1040.'], [8300, 'pend'], [2200, 'contact']]],
        1055 => [6000, 'in_progress', 'P2', [[5960, 'reply'], [5950, 'start'], [5900, 'pend'], [700, 'contact'], [650, 'note']]],
        1056 => [5985, 'pending', 'P3', [[5950, 'reply'], [5940, 'start'], [5900, 'pend']]],
        1060 => [4325, 'resolved', 'P3', [[4300, 'reply'], [4290, 'start'], [3000, 'resolve', 'Refunded the duplicate charge of 12 Sep.'], [2900, 'contact', 'Refund received, thank you.']]],
        1061 => [4318, 'resolved', 'P3', [[4290, 'reply'], [4285, 'note', 'Same duplicate charge as #1060.'], [4280, 'start'], [2990, 'resolve', 'Refunded the second charge.']]],
        1062 => [4295, 'in_progress', 'P3', [[4235, 'reply'], [4225, 'start']]],
        1063 => [4290, 'in_progress', 'P3', [[4240, 'reply'], [4230, 'start'], [4000, 'note']]],
        1064 => [4285, 'in_progress', 'P3', [[4245, 'reply'], [4235, 'start']]],
        1072 => [3600, 'in_progress', 'P2', [[3560, 'reply'], [3550, 'start'], [3500, 'pend'], [800, 'contact']]],
        1073 => [3590, 'assigned', 'P3', [[3560, 'reply']]],
        1080 => [2880, 'in_progress', 'P2', [[2850, 'reply'], [2840, 'start'], [2830, 'note', 'Reproduced on staging with v2.3.1.'], [2800, 'pend'], [600, 'contact']]],
        1081 => [2865, 'pending', 'P2', [[2840, 'reply'], [2830, 'start'], [2800, 'pend']]],
        1090 => [2400, 'resolved', 'P1', [[2395, 'attach'], [2385, 'reply'], [2380, 'start'], [2300, 'note', 'Load balancer health checks failing after the certificate rotation.'], [2250, 'resolve', 'Rolled back the certificate rotation; the dashboard is reachable again.'], [2200, 'contact', 'Confirmed, everyone is back in.']]],
        1095 => [1800, 'assigned', 'P4', [[1700, 'reply']]],
        1101 => [1200, 'in_progress', 'P1', [[1195, 'triage'], [1185, 'reply'], [1180, 'start'], [1100, 'note', 'The payment provider confirms high latency in the EU region.']]],
        1102 => [1080, 'assigned', 'P3', [[1070, 'triage']]],
        1103 => [960, 'in_progress', 'P3', [[950, 'triage'], [420, 'escalate'], [410, 'reply'], [400, 'start']]],
        1104 => [460, 'assigned', 'P1', [[455, 'triage'], [440, 'reply'], [400, 'note', 'The seat count sync job failed last night; investigating.']]],
        1105 => [85, 'assigned', 'P2', [[80, 'triage']]],
        1110 => [70, 'in_progress', 'P3', [[68, 'triage'], [65, 'reply'], [55, 'resolve', 'The refund was re-sent to the card on file.'], [40, 'contact', 'Still nothing on our statement.'], [30, 'reopen'], [25, 'note', 'Checking the refund reference with the bank.']]],
    ];

    private const array REPLIES = [
        'Thanks for reporting this. We are looking into it now.',
        'We have reproduced the problem and are working on a fix.',
        'Could you send us a screenshot and the time it happened?',
        'Thank you. I have passed this to the right team and will update you shortly.',
        'We are checking the logs for your account.',
    ];

    private const array NOTES = [
        'Checked the logs; nothing unusual on our side yet.',
        'Asked the customer for more details.',
        'Similar reports this week; watching for a pattern.',
        'Escalated internally to the product team.',
        'Workaround shared; waiting for a permanent fix.',
    ];

    private const array CONTACT_REPLIES = [
        'Here are the details you asked for.',
        'It happened again this morning.',
        'Attached the information; please have a look.',
        'Any update on this?',
    ];

    private const array RESOLUTIONS = [
        'Fixed the configuration; please try again.',
        'The problem is solved after the latest update.',
        'We corrected the settings on your account.',
        'Applied the fix and confirmed it works.',
        'Explained the steps; the customer confirmed it works.',
    ];

    private const array TIER_BY_ORGANISATION = ['globex' => 'enterprise', 'initech' => 'premium', 'umbrella' => 'enterprise', 'hooli' => 'standard', 'stark' => 'premium', 'wayne' => 'standard'];

    private SeededRandom $random;

    private TicketTextGenerator $texts;

    public function __construct(public readonly int $seed = 2026, public readonly int $historyTickets = 180)
    {
        $this->random = new SeededRandom($seed);
        $this->texts = new TicketTextGenerator;
    }

    /**
     * All Acme tickets, oldest first (history, then live 1001–1120).
     *
     * @return list<TicketSpec>
     */
    public function tickets(): array
    {
        return [...$this->history(), ...$this->live()];
    }

    public function firstNumber(): int
    {
        return DemoCatalogue::FIRST_LIVE_NUMBER - $this->historyTickets;
    }

    /**
     * Resolution and first-response minutes for a level at a moment (the policy edit on day −30).
     *
     * @return array{int, int} [first response, resolution]
     */
    public static function targets(string $level, int $ago): array
    {
        $defaults = ['P1' => [30, 240], 'P2' => [60, 480], 'P3' => [240, 1440], 'P4' => [480, 4320]];

        return $ago > self::POLICY_EDIT_AGO ? $defaults[$level] : DemoCatalogue::SLA_TARGETS[$level];
    }

    /** The tier of a contact's organisation when a ticket is created `ago` minutes before now. */
    public static function tierAt(string $contact, int $ago): string
    {
        $organisation = self::organisationAt($contact, $ago);
        if ($organisation === null) {
            return 'standard';
        }
        if ($organisation === 'hooli' && $ago <= self::HOOLI_UPGRADE_AGO) {
            return 'premium';
        }

        return self::TIER_BY_ORGANISATION[$organisation];
    }

    /** Rahul Verma moves from Initech to Hooli on day −25 and to Stark Logistics on day −12. */
    public static function organisationAt(string $contact, int $ago): ?string
    {
        if ($contact === DemoCatalogue::MOVING_CONTACT) {
            return match (true) {
                $ago > 25 * self::DAY => 'initech',
                $ago > 12 * self::DAY => 'hooli',
                default => 'stark',
            };
        }
        foreach (DemoCatalogue::CONTACTS as $organisation => $names) {
            if (in_array($contact, $names, true)) {
                return $organisation === '' ? null : $organisation;
            }
        }

        throw new LogicException("Unknown demo contact {$contact}.");
    }

    /**
     * The impact and urgency pairs for which the baseline priority formula (default weights, age 0)
     * gives the level for the tier (docs/05-algorithms/priority-scoring.md).
     *
     * @return list<array{int, int}>
     */
    public static function inputsFor(string $level, string $tier): array
    {
        $tierValue = ['standard' => 0.0, 'premium' => 0.5, 'enterprise' => 1.0][$tier];
        $pairs = [];
        for ($impact = 1; $impact <= 4; $impact++) {
            for ($urgency = 1; $urgency <= 4; $urgency++) {
                $score = round(round(100 * (0.40 * ($impact - 1) / 3 + 0.35 * ($urgency - 1) / 3 + 0.15 * $tierValue), 6), 1);
                $computed = match (true) {
                    $score >= 75 => 'P1',
                    $score >= 50 => 'P2',
                    $score >= 25 => 'P3',
                    default => 'P4',
                };
                if ($computed === $level) {
                    $pairs[] = [$impact, $urgency];
                }
            }
        }

        return $pairs;
    }

    /**
     * @return list<TicketSpec>
     */
    private function live(): array
    {
        $contacts = self::contactNames();
        $slots = $this->liveSlots();

        // Categories: the targets minus the anchors, shuffled over the generated slots.
        $bag = DemoPlan::CATEGORY_TARGETS;
        foreach (DemoCatalogue::ANCHORS as $anchor) {
            $bag[$anchor[2]]--;
        }
        $categories = [];
        foreach ($bag as $category => $count) {
            array_push($categories, ...array_fill(0, $count, $category));
        }
        $categories = $this->random->shuffle($categories);

        $levels = $this->levels($slots);
        $apiSlots = array_slice($this->random->shuffle(array_keys(array_filter($slots, fn (array $slot): bool => ! isset(DemoCatalogue::ANCHORS[$slot['number']])))), 0, 20);

        $tickets = [];
        $generated = 0;
        foreach ($slots as $index => $slot) {
            $number = $slot['number'];
            if (isset(DemoCatalogue::ANCHORS[$number])) {
                [$title, $description, $category, $contact, $impact, $urgency] = DemoCatalogue::ANCHORS[$number];
                $tickets[] = $this->ticket("live-{$number}", $number, $title, $description, $category, $contact, $impact, $urgency, $slot['level'], $slot['status'], $slot['ago'], 'ui', $this->creator(), $slot['steps']);

                continue;
            }

            $category = $categories[$generated++];
            $level = $slot['level'] ?? $levels[$index];
            $contact = $this->random->pick($contacts);
            [$impact, $urgency] = $this->random->pick(self::inputsFor($level, self::tierAt($contact, $slot['ago'])));
            $text = $this->texts->ticket($this->random, DemoCatalogue::CATEGORIES[$category][3]);
            $via = in_array($index, $apiSlots, true) ? 'api' : 'ui';
            $steps = $slot['steps'] ?? $this->script($slot['status'], $level, $slot['ago'], $slot['bucket'] === '0-1d');

            $tickets[] = $this->ticket("live-{$number}", $number, $text['title'], $text['description'], $category, $contact, $impact, $urgency, $level, $slot['status'], $slot['ago'], $via, $via === 'api' ? null : $this->creator(), $steps);
        }

        return $tickets;
    }

    /**
     * Number, age, status (and for scripted tickets the level and steps) of every live ticket.
     *
     * @return list<array{number: int, ago: int, status: string, bucket: string, level?: string, steps?: list<Step>}>
     */
    private function liveSlots(): array
    {
        $slots = [];
        foreach (self::BUCKETS as $bucket => [$first, $last, $oldest, $youngest, $statuses]) {
            // Fixed points inside the bucket, then generated ages in the gaps, so numbers follow time.
            $fixed = [$first - 1 => $oldest + 1];
            foreach (self::SCRIPTED as $number => $script) {
                if ($number >= $first && $number <= $last) {
                    $fixed[$number] = $script[0];
                }
            }
            $fixed[$last + 1] = $youngest - 1;
            ksort($fixed);

            $ages = [];
            $points = array_keys($fixed);
            for ($i = 0; $i < count($points) - 1; $i++) {
                [$from, $to] = [$points[$i], $points[$i + 1]];
                $count = $to - $from - 1;
                if ($count <= 0) {
                    continue;
                }
                $gap = $this->distinct($fixed[$to] + 1, $fixed[$from] - 1, $count);
                rsort($gap);
                foreach ($gap as $offset => $ago) {
                    $ages[$from + 1 + $offset] = $ago;
                }
            }

            // Statuses of the generated tickets: the oldest of 7-30d are closed, the rest shuffled.
            $generated = array_values(array_filter(range($first, $last), fn (int $n): bool => ! isset(self::SCRIPTED[$n])));
            $bag = [];
            foreach ($statuses as $status => $count) {
                array_push($bag, ...array_fill(0, $count, $status));
            }
            if (count($bag) !== count($generated)) {
                throw new LogicException("Bucket {$bucket}: {$this->count($bag)} statuses for ".count($generated).' tickets.');
            }
            $bag = $bucket === '7-30d' ? [...array_fill(0, 14, 'closed'), ...array_fill(0, 11, 'resolved')] : $this->random->shuffle($bag);

            foreach (range($first, $last) as $number) {
                if (isset(self::SCRIPTED[$number])) {
                    [$ago, $status, $level, $steps] = self::SCRIPTED[$number];
                    $slots[] = ['number' => $number, 'ago' => $ago, 'status' => $status, 'bucket' => $bucket, 'level' => $level, 'steps' => array_map(self::step(...), $steps)];

                    continue;
                }
                $slots[] = ['number' => $number, 'ago' => $ages[$number], 'status' => (string) array_shift($bag), 'bucket' => $bucket];
            }
        }

        return $slots;
    }

    /**
     * Levels of the generated live tickets, so the totals hit PRIORITY_TARGETS exactly. P1 only on done
     * tickets (a P1 open for days would be breached), P4 first where an assigned ticket is too old for a
     * shorter target, and then on the oldest active tickets; P2 never on an assigned ticket older than
     * its resolution target.
     *
     * @param  list<array{number: int, ago: int, status: string, bucket: string, level?: string, steps?: list<Step>}>  $slots
     * @return array<int, string> slot index => level
     */
    private function levels(array $slots): array
    {
        $left = self::PRIORITY_TARGETS;
        $free = [];
        foreach ($slots as $index => $slot) {
            if (isset($slot['level'])) {
                $left[$slot['level']]--;
            } else {
                $free[$index] = $slot;
            }
        }

        $levels = [];
        $take = function (string $level, array $candidates, int $count) use (&$levels, &$free, &$left): void {
            foreach (array_slice($candidates, 0, max(0, $count)) as $index) {
                $levels[$index] = $level;
                unset($free[$index]);
                $left[$level]--;
            }
        };

        $done = array_keys(array_filter($free, fn (array $s): bool => in_array($s['status'], ['resolved', 'closed'], true)));
        $take('P1', $this->random->shuffle($done), $left['P1']);

        $mustBeP4 = array_keys(array_filter($free, fn (array $s): bool => $s['status'] === 'assigned' && $s['ago'] >= DemoCatalogue::SLA_TARGETS['P3'][1] - 60));
        $take('P4', $mustBeP4, $left['P4']);

        $active = array_filter($free, fn (array $s): bool => in_array($s['status'], ['assigned', 'in_progress', 'pending'], true));
        uasort($active, fn (array $a, array $b): int => $b['ago'] <=> $a['ago']);
        $oldest = array_slice(array_keys($active), 0, $left['P4'] + 6);
        $take('P4', $this->random->shuffle($oldest), $left['P4']);

        $p2 = array_keys(array_filter($free, fn (array $s): bool => ! ($s['status'] === 'assigned' && $s['ago'] >= DemoCatalogue::SLA_TARGETS['P2'][1] - 60)));
        $take('P2', $this->random->shuffle($p2), $left['P2']);
        $take('P3', array_keys($free), $left['P3']);

        if ($free !== [] || array_filter($left) !== []) {
            throw new LogicException('Priority targets cannot be met: '.json_encode($left));
        }

        return $levels;
    }

    /**
     * Steps of a generated ticket so it ends in `$status` with SLA timers that are mostly on time:
     * a first reply inside the first-response target, and a pause while waiting for the requester
     * when the ticket would otherwise outlive its resolution target. About one ticket in eight is
     * "slow" and breaches, as in a real queue.
     *
     * @return list<Step>
     */
    private function script(string $status, string $level, int $ago, bool $triaged): array
    {
        if ($status === 'open') {
            return [];
        }

        [$firstResponse, $resolution] = self::targets($level, $ago);
        $slow = $this->random->chance(0.12);
        $steps = [];
        $cursor = $ago;
        $at = function (int $minutes) use (&$cursor): int {
            $cursor = max(2, min($cursor - 1, $minutes));

            return $cursor;
        };

        if ($triaged) {
            $steps[] = ['ago' => $at($cursor - $this->random->int(2, 8)), 'do' => 'triage'];
        }
        $steps[] = ['ago' => $at($cursor - (int) round($this->random->float() * 0.5 * min($firstResponse, max(4, $cursor - 2))) - 1), 'do' => 'reply', 'text' => $this->random->pick(self::REPLIES)];
        if ($status === 'assigned') {
            if ($this->random->chance(0.3) && $cursor > 10) {
                $steps[] = ['ago' => $at($cursor - $this->random->int(3, 30)), 'do' => 'note', 'text' => $this->random->pick(self::NOTES)];
            }

            return $steps;
        }

        $steps[] = ['ago' => $at($cursor - $this->random->int(2, 30)), 'do' => 'start'];
        if ($this->random->chance(0.35) && $cursor > 20) {
            $steps[] = ['ago' => $at($cursor - $this->random->int(5, 60)), 'do' => 'note', 'text' => $this->random->pick(self::NOTES)];
        }

        if ($status === 'pending') {
            // Paused before 70 % of the target is used, and still pending now.
            $steps[] = ['ago' => $at(max($ago - (int) (0.7 * $resolution), $cursor - $this->random->int(10, 120))), 'do' => 'pend'];

            return $steps;
        }

        if ($status === 'in_progress') {
            if ($ago > 0.8 * $resolution && ! $slow && $cursor > 60) {
                $pause = $ago - (int) (0.6 * $resolution);
                $pendAt = $at($cursor - $this->random->int(10, 60));
                $steps[] = ['ago' => $pendAt, 'do' => 'pend'];
                $steps[] = ['ago' => $at(max(5, $pendAt - $pause)), 'do' => 'contact', 'text' => $this->random->pick(self::CONTACT_REPLIES)];
            }

            return $steps;
        }

        // resolved or closed
        $spend = (int) round(($slow ? 1.05 + 0.5 * $this->random->float() : 0.15 + 0.7 * $this->random->float()) * $resolution);
        $resolveAt = $ago - $spend;
        if ($status === 'resolved') {
            // Still resolved now: within the seven days after which the scheduler closes it.
            $resolveAt = min($resolveAt, 7 * self::DAY - 180);
        }
        $steps[] = ['ago' => $at(max(20, $resolveAt)), 'do' => 'resolve', 'text' => $this->random->pick(self::RESOLUTIONS)];
        $resolvedAgo = $cursor;

        if ($this->random->chance(0.06) && $resolvedAgo > 3 * self::DAY) {
            // Reopened once: the requester says it is not fixed, the agent works on it again.
            $steps[] = ['ago' => $at($cursor - $this->random->int(60, 600)), 'do' => 'contact', 'text' => 'It is broken again.'];
            $steps[] = ['ago' => $at($cursor - $this->random->int(10, 60)), 'do' => 'reopen'];
            $steps[] = ['ago' => $at($cursor - $this->random->int(60, (int) (0.5 * $resolution) + 61)), 'do' => 'resolve', 'text' => $this->random->pick(self::RESOLUTIONS)];
            $resolvedAgo = $cursor;
        }

        if ($status === 'closed') {
            if ($this->random->chance(0.5) || $resolvedAgo - 7 * self::DAY < 5) {
                $steps[] = ['ago' => $at($cursor - $this->random->int(30, 600)), 'do' => 'contact', 'text' => 'Confirmed, thank you.'];
                $steps[] = ['ago' => $at($cursor - $this->random->int(10, 120)), 'do' => 'close'];
            } else {
                $steps[] = ['ago' => $at($resolvedAgo - 7 * self::DAY), 'do' => 'autoclose'];
            }
        } elseif ($this->random->chance(0.4) && $cursor > 30) {
            $steps[] = ['ago' => $at($cursor - $this->random->int(10, 300)), 'do' => 'contact', 'text' => 'Thanks, that fixed it.'];
        }

        return $steps;
    }

    /**
     * Closed tickets of days −90 to −30, spread evenly, numbered before the live ones.
     *
     * @return list<TicketSpec>
     */
    private function history(): array
    {
        $ages = $this->distinct(self::LIVE_DAYS * self::DAY + 120, self::HISTORY_DAYS * self::DAY - 60, $this->historyTickets);
        rsort($ages);
        $contacts = self::contactNames();
        $categoryWeights = self::CATEGORY_TARGETS;
        $levelWeights = ['P1' => 8, 'P2' => 25, 'P3' => 45, 'P4' => 22];

        $tickets = [];
        foreach ($ages as $offset => $ago) {
            $category = $this->random->weighted($categoryWeights);
            $level = $this->random->weighted($levelWeights);
            $contact = $this->random->pick($contacts);
            [$impact, $urgency] = $this->random->pick(self::inputsFor($level, self::tierAt($contact, $ago)));
            $text = $this->texts->ticket($this->random, DemoCatalogue::CATEGORIES[$category][3]);
            $number = $this->firstNumber() + $offset;
            $tickets[] = $this->ticket("history-{$number}", $number, $text['title'], $text['description'], $category, $contact, $impact, $urgency, $level, 'closed', $ago, 'ui', $this->creator(), $this->script('closed', $level, $ago, false));
        }

        return $tickets;
    }

    private function creator(): string
    {
        return $this->random->chance(0.3) ? 'priya@acme.test' : (string) $this->random->pick(array_keys(DemoCatalogue::AGENTS));
    }

    /**
     * @param  list<Step>  $steps
     * @return TicketSpec
     */
    private function ticket(string $key, int $number, string $title, string $description, string $category, string $contact, int $impact, int $urgency, string $level, string $status, int $ago, string $via, ?string $creator, array $steps): array
    {
        $previous = $ago;
        foreach ($steps as $step) {
            if ($step['ago'] >= $previous || $step['ago'] < 1) {
                throw new LogicException("Ticket {$number}: step {$step['do']} at {$step['ago']} is not after {$previous}.");
            }
            $previous = $step['ago'];
        }

        return compact('key', 'number', 'title', 'description', 'category', 'contact', 'impact', 'urgency', 'level', 'status', 'ago', 'via', 'creator', 'steps');
    }

    /**
     * @param  array{0: int, 1: string, 2?: string}  $step
     * @return Step
     */
    private static function step(array $step): array
    {
        return isset($step[2]) ? ['ago' => $step[0], 'do' => $step[1], 'text' => $step[2]] : ['ago' => $step[0], 'do' => $step[1]];
    }

    /** @return non-empty-list<string> */
    private static function contactNames(): array
    {
        $names = [];
        foreach (DemoCatalogue::CONTACTS as $group) {
            array_push($names, ...$group);
        }

        return $names;
    }

    /**
     * `$count` different whole numbers between `$min` and `$max` (both included).
     *
     * @return list<int>
     */
    private function distinct(int $min, int $max, int $count): array
    {
        if ($max - $min + 1 < $count) {
            throw new LogicException("No room for {$count} tickets between {$min} and {$max} minutes ago.");
        }
        $picked = [];
        while (count($picked) < $count) {
            $picked[$this->random->int($min, $max)] = true;
        }

        return array_keys($picked);
    }

    /** @param list<string> $bag */
    private function count(array $bag): int
    {
        return count($bag);
    }
}
