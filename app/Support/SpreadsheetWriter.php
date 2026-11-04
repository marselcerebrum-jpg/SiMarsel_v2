<?php

namespace App\Support;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes titled sections of rows to a file: one sheet per section in XLSX,
 * or every section one after another (title row, rows, blank row) in CSV.
 * Rows may be any iterable, so a large section can be streamed instead of held in memory.
 */
class SpreadsheetWriter
{
    /**
     * @param  array<string, iterable<list<string|int|float|null>>>  $sections  Section title => rows.
     */
    public function xlsx(array $sections, string $path): void
    {
        $writer = new XlsxWriter;
        $writer->openToFile($path);

        foreach (array_keys($sections) as $index => $title) {
            $sheet = $index === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($title);

            foreach ($sections[$title] as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();
    }

    /**
     * @param  array<string, iterable<list<string|int|float|null>>>  $sections  Section title => rows.
     */
    public function csv(array $sections, string $path): void
    {
        $writer = new CsvWriter;
        $writer->openToFile($path);

        foreach ($sections as $title => $rows) {
            $writer->addRow(Row::fromValues([$title]));

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }

            $writer->addRow(Row::fromValues([]));
        }

        $writer->close();
    }
}
