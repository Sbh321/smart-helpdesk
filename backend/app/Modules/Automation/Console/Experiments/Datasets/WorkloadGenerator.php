<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Datasets;

use App\Support\Experiments\SeededRandom;

/**
 * The E1 workload (docs/05-algorithms/evaluation-methodology.md §2): 8 agents with skills and
 * capacities 5–12, 6 categories, 500 tickets whose inter-arrival and handling times are exponential.
 * The tickets also carry impact, urgency, tier and a waiting time, which E3 uses for its 200 tickets.
 */
final class WorkloadGenerator
{
    public const int AGENTS = 8;

    public const int TICKETS = 500;

    /** Mean seconds between two arrivals (about 30 tickets an hour). */
    public const int MEAN_INTERARRIVAL_S = 120;

    /** Mean seconds an agent needs to resolve a ticket (90 minutes). */
    public const int MEAN_HANDLING_S = 5400;

    public const array SKILLS = ['account', 'billing', 'hardware', 'network', 'technical'];

    /** category => [required skill (null: any agent), share of the tickets] */
    public const array CATEGORIES = [
        'billing' => ['billing', 0.20],
        'technical' => ['technical', 0.25],
        'account' => ['account', 0.15],
        'network' => ['network', 0.15],
        'hardware' => ['hardware', 0.10],
        'general' => [null, 0.15],
    ];

    private const array TIERS = ['standard' => 0.6, 'premium' => 0.3, 'enterprise' => 0.1];

    /**
     * @return array{agents: list<array{id: string, capacity: int, skills: list<string>}>, categories: list<array{id: string, required_skills: list<string>, share: float}>, tickets: list<array{id: string, arrival_s: int, category: string, handling_s: int, impact: int, urgency: int, tier: string, waited_h: float}>}
     */
    public function generate(int $seed): array
    {
        $random = new SeededRandom($seed);

        return ['agents' => $this->agents($random), 'categories' => $this->categories(), 'tickets' => $this->tickets($random)];
    }

    /**
     * @return list<array{id: string, capacity: int, skills: list<string>}>
     */
    private function agents(SeededRandom $random): array
    {
        $agents = [];
        for ($i = 1; $i <= self::AGENTS; $i++) {
            $skills = array_slice($random->shuffle(self::SKILLS), 0, $random->int(2, 3));
            $agents[] = ['id' => sprintf('agent-%02d', $i), 'capacity' => $random->int(5, 12), 'skills' => $skills];
        }

        // Every skill needs at least two agents, or a category could never be served fairly.
        foreach (self::SKILLS as $skill) {
            while (count(array_filter($agents, fn (array $agent): bool => in_array($skill, $agent['skills'], true))) < 2) {
                $fewest = null;
                foreach ($agents as $index => $agent) {
                    if (! in_array($skill, $agent['skills'], true) && ($fewest === null || count($agent['skills']) < count($agents[$fewest]['skills']))) {
                        $fewest = $index;
                    }
                }
                $agents[(int) $fewest]['skills'][] = $skill;
            }
        }

        foreach ($agents as &$agent) {
            sort($agent['skills']);
        }

        return $agents;
    }

    /**
     * @return list<array{id: string, required_skills: list<string>, share: float}>
     */
    private function categories(): array
    {
        $categories = [];
        foreach (self::CATEGORIES as $id => [$skill, $share]) {
            $categories[] = ['id' => $id, 'required_skills' => $skill === null ? [] : [$skill], 'share' => $share];
        }

        return $categories;
    }

    /**
     * @return list<array{id: string, arrival_s: int, category: string, handling_s: int, impact: int, urgency: int, tier: string, waited_h: float}>
     */
    private function tickets(SeededRandom $random): array
    {
        $shares = array_map(fn (array $category): float => $category[1], self::CATEGORIES);
        $tickets = [];
        $clock = 0.0;

        for ($i = 1; $i <= self::TICKETS; $i++) {
            $clock += $random->exponential(self::MEAN_INTERARRIVAL_S);
            $tickets[] = [
                'id' => sprintf('T%04d', $i),
                'arrival_s' => (int) round($clock),
                'category' => $random->weighted($shares),
                'handling_s' => max(60, (int) round($random->exponential(self::MEAN_HANDLING_S))),
                'impact' => $random->int(1, 4),
                'urgency' => $random->int(1, 4),
                'tier' => $random->weighted(self::TIERS),
                'waited_h' => round($random->float() * 96, 1),
            ];
        }

        return $tickets;
    }
}
