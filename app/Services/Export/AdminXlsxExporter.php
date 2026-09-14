<?php

namespace App\Services\Export;

use DateTimeInterface;
use Illuminate\Http\Response;
use InvalidArgumentException;
use Shuchkin\SimpleXLSXGen;

final class AdminXlsxExporter
{
    public const MIME_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     * @param  array<int, int|float>  $columnWidths
     * @param  list<int>  $wrappedColumns  One-based column indexes.
     */
    public function download(
        string $filename,
        string $worksheet,
        array $headers,
        iterable $rows,
        array $columnWidths = [],
        array $wrappedColumns = [],
    ): Response {
        $columnCount = count($headers);

        if ($columnCount === 0) {
            throw new InvalidArgumentException('XLSX export requires headers.');
        }

        $data = [$this->headerRow($headers)];

        foreach ($rows as $row) {
            if (count($row) !== $columnCount) {
                throw new InvalidArgumentException(
                    'Every XLSX row must match the header column count.',
                );
            }

            $data[] = array_map(
                fn (mixed $value, int $index): mixed => $this->cell(
                    $value,
                    in_array($index + 1, $wrappedColumns, true),
                ),
                $row,
                array_keys($row),
            );
        }

        $xlsx = SimpleXLSXGen::fromArray($data, $worksheet)
            ->freezePanes('A2')
            ->autoFilter('A1:'.$this->columnName($columnCount).'1')
            ->setDefaultFont('Arial')
            ->setDefaultFontSize(10);

        foreach ($columnWidths as $column => $width) {
            $xlsx->setColWidth($column, $width);
        }

        $contents = (string) $xlsx;

        if (! str_starts_with($contents, "PK\x03\x04")) {
            throw new InvalidArgumentException('The XLSX workbook could not be generated.');
        }

        return response($contents, 200, [
            'Content-Type' => self::MIME_TYPE,
            'Content-Disposition' => 'attachment; filename="'.$this->safeFilename($filename).'"',
            'Content-Length' => (string) strlen($contents),
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }

    /** @param list<string> $headers */
    private function headerRow(array $headers): array
    {
        return array_map(
            fn (string $header): string => sprintf(
                '<style bgcolor="#171A20" color="#F4F5F7" border="#2A2E36"><b><wraptext><raw>%s</raw></wraptext></b></style>',
                $this->escape($header),
            ),
            $headers,
        );
    }

    private function cell(mixed $value, bool $wrap): mixed
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTimeInterface || is_int($value) || is_float($value)) {
            return $value;
        }

        $text = (string) $value;

        if ($wrap) {
            return '<wraptext><top><raw>'.$this->escape($text).'</raw></top></wraptext>';
        }

        return "\0".$text;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_XML1,
            'UTF-8',
        );
    }

    private function columnName(int $number): string
    {
        $name = '';

        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private function safeFilename(string $filename): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename)
            ?: 'neomatra-export.xlsx';
    }
}
