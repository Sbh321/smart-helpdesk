<?php

declare(strict_types=1);

namespace App\Modules\Automation\Console\Experiments;

use App\Modules\Automation\Domain\Assignment\FairnessIndex;

/**
 * Spread of the agents' load at one instant (E1): standard deviation, maximum and minimum of open
 * tickets, Jain's index over open tickets and over utilisation (open ÷ capacity), and the highest utilisation.
 */
final class LoadMeasures
{
    /**
     * @param  array<string, int>  $open  agent => open tickets
     * @param  array<string, int>  $capacity  agent => capacity
     * @return array{std_open: float, max_open: int, min_open: int, jain_open: float, jain_utilisation: float, max_utilisation: float}
     */
    public static function of(array $open, array $capacity): array
    {
        $counts = array_values($open);
        $utilisation = [];
        foreach ($open as $agent => $tickets) {
            $utilisation[] = $capacity[$agent] === 0 ? 0.0 : (float) $tickets / $capacity[$agent];
        }

        return [
            'std_open' => FairnessIndex::standardDeviation($counts),
            'max_open' => $counts === [] ? 0 : max($counts),
            'min_open' => $counts === [] ? 0 : min($counts),
            'jain_open' => FairnessIndex::jain($counts),
            'jain_utilisation' => FairnessIndex::jain($utilisation),
            'max_utilisation' => $utilisation === [] ? 0.0 : max($utilisation),
        ];
    }
}
