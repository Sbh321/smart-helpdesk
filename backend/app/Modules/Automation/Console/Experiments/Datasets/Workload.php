<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments\Datasets;

use RuntimeException;

/**
 * The E1 workload as read back from `experiments/datasets/v1/workload/`.
 */
final readonly class Workload
{
    /**
     * @param  list<array{id: string, capacity: int, skills: list<string>}>  $agents
     * @param  array<string, list<string>>  $categorySkills  category => required skills
     * @param  list<array{id: string, arrival_s: int, category: string, handling_s: int, impact: int, urgency: int, tier: string, waited_h: float}>  $tickets  in arrival order
     */
    public function __construct(
        public array $agents,
        public array $categorySkills,
        public array $tickets,
    ) {}

    public static function fromFolder(string $folder): self
    {
        /** @var list<array{id: string, capacity: int, skills: list<string>}> $agents */
        $agents = self::json("{$folder}/agents.json");
        $categorySkills = [];
        foreach (self::json("{$folder}/categories.json") as $category) {
            $categorySkills[(string) $category['id']] = array_values($category['required_skills']);
        }

        $tickets = [];
        $rows = file("{$folder}/tickets.csv", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows === false) {
            throw new RuntimeException("Cannot read {$folder}/tickets.csv.");
        }
        $header = str_getcsv((string) array_shift($rows), ',', '"', '');
        foreach ($rows as $row) {
            $ticket = array_combine($header, str_getcsv($row, ',', '"', ''));
            $tickets[] = [
                'id' => (string) $ticket['id'],
                'arrival_s' => (int) $ticket['arrival_s'],
                'category' => (string) $ticket['category'],
                'handling_s' => (int) $ticket['handling_s'],
                'impact' => (int) $ticket['impact'],
                'urgency' => (int) $ticket['urgency'],
                'tier' => (string) $ticket['tier'],
                'waited_h' => (float) $ticket['waited_h'],
            ];
        }
        usort($tickets, fn (array $a, array $b): int => $a['arrival_s'] <=> $b['arrival_s'] ?: strcmp($a['id'], $b['id']));

        return new self($agents, $categorySkills, $tickets);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function json(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new RuntimeException("Dataset file {$path} is missing; run experiment:generate-workload.");
        }

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}
