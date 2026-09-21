<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Exports;

use InvalidArgumentException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Streams an `ExportTable` to a local file (ADR-0022 §6): CSV with `fputcsv` (UTF-8 with a byte-order
 * mark so spreadsheet programs detect the encoding), XLSX with openspout. Rows are written as they are
 * produced. Text is always written as text: a CSV value starting with `=`, `+`, `-`, `@`, tab or carriage
 * return gets a leading apostrophe (OWASP CSV injection), and XLSX strings are string cells, never
 * formulas. Returns the number of data rows written (the header is not counted).
 */
final class ExportFileWriter
{
    private const string BOM = "\xEF\xBB\xBF";

    public function write(ExportTable $table, string $format, string $path): int
    {
        return match ($format) {
            'csv' => $this->csv($table, $path),
            'xlsx' => $this->xlsx($table, $path),
            default => throw new InvalidArgumentException("Unknown export format [{$format}]."),
        };
    }

    private function csv(ExportTable $table, string $path): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('The export file could not be opened.');
        }

        try {
            fwrite($handle, self::BOM);
            fputcsv($handle, array_map($this->csvValue(...), $table->headers), ',', '"', '');
            $count = 0;
            foreach ($table->rows as $row) {
                fputcsv($handle, array_map($this->csvValue(...), $row), ',', '"', '');
                $count++;
            }

            return $count;
        } finally {
            fclose($handle);
        }
    }

    private function xlsx(ExportTable $table, string $path): int
    {
        $options = new Options;
        $writer = new Writer($options);
        $writer->openToFile($path);

        try {
            $writer->getCurrentSheet()->setName($this->sheetName($table->title));
            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(fn (string $header): Cell => new StringCell($header, $bold), $table->headers)));
            $count = 0;
            foreach ($table->rows as $row) {
                $writer->addRow(new Row(array_map($this->xlsxCell(...), $row)));
                $count++;
            }

            return $count;
        } finally {
            $writer->close();
        }
    }

    private function csvValue(string|int|float|null $value): string
    {
        if ($value === null) {
            return '';
        }
        if (! is_string($value)) {
            return (string) $value;
        }

        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }

    private function xlsxCell(string|int|float|null $value): Cell
    {
        return match (true) {
            $value === null, $value === '' => new EmptyCell(null),
            is_string($value) => new StringCell($value),
            default => new NumericCell($value),
        };
    }

    /** Excel sheet names: at most 31 characters, none of `[]:*?/\`. */
    private function sheetName(string $title): string
    {
        $name = trim((string) preg_replace('/[\[\]:*?\/\\\\]+/', ' ', $title));

        return mb_substr($name === '' ? 'Export' : $name, 0, 31);
    }
}
