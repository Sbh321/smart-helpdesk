<?php

declare(strict_types=1);

namespace App\Support\Experiments;

use RuntimeException;

/**
 * Writes one experiment's tables into its result folder (`experiments/results/v1/<experiment>/`):
 * CSV for tables and plot series, JSON for summaries, and `run.json` last.
 * Floats are written as given; experiments round them with {@see Metrics::round()} first.
 */
final class ExperimentOutput
{
    /** @var list<string> */
    private array $files = [];

    public function __construct(public readonly string $directory)
    {
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the result folder {$directory}.");
        }
    }

    /**
     * @param  list<array<string, scalar|null>>  $rows  every row has the same keys; they become the header
     */
    public function csv(string $name, array $rows): void
    {
        $handle = fopen('php://temp', 'w+');
        if ($handle === false) {
            throw new RuntimeException('Cannot open a temporary stream.');
        }

        if ($rows !== []) {
            fputcsv($handle, array_keys($rows[0]), ',', '"', '', "\n");
        }
        foreach ($rows as $row) {
            fputcsv($handle, array_map(self::cell(...), array_values($row)), ',', '"', '', "\n");
        }

        rewind($handle);
        $this->write($name, (string) stream_get_contents($handle));
        fclose($handle);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function json(string $name, array $data): void
    {
        $this->write($name, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return list<string> the files written so far, in order */
    public function files(): array
    {
        return $this->files;
    }

    private function write(string $name, string $contents): void
    {
        if (file_put_contents($this->directory.'/'.$name, $contents) === false) {
            throw new RuntimeException("Cannot write {$name}.");
        }
        $this->files[] = $name;
    }

    private static function cell(bool|float|int|string|null $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }
}
