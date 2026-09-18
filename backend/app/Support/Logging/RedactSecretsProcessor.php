<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Backstop that masks secret-looking keys in log context (docs/11-operations/logs.md §Never logged).
 * Call sites must still avoid logging secrets; this only catches mistakes.
 */
final class RedactSecretsProcessor implements ProcessorInterface
{
    public const MASK = '[redacted]';

    private const PATTERN = '/(password|passwd|token|secret|authorization|cookie|api[_-]?key|signature)/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function redact(array $values, int $depth = 0): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $values[$key] = self::MASK;
            } elseif (is_array($value) && $depth < 5) {
                $values[$key] = $this->redact($value, $depth + 1);
            }
        }

        return $values;
    }
}
