<?php

namespace Database\Seeders\Support;

use RuntimeException;

final class SqlDatasetParser
{
    /**
     * @return list<list<mixed>>
     */
    public function rows(
        string $path,
        string $insertPrefix,
        string $terminator,
    ): array {
        $sql = file_get_contents($path);

        if (! is_string($sql)) {
            throw new RuntimeException("Dataset tidak dapat dibaca: {$path}");
        }

        $insertAt = strpos($sql, $insertPrefix);

        if ($insertAt === false) {
            throw new RuntimeException(
                "Statement dataset tidak ditemukan: {$insertPrefix}",
            );
        }

        $valuesAt = strpos($sql, 'VALUES', $insertAt);

        if ($valuesAt === false) {
            throw new RuntimeException(
                "VALUES dataset tidak ditemukan: {$insertPrefix}",
            );
        }

        $valuesAt += strlen('VALUES');
        $endAt = strpos($sql, $terminator, $valuesAt);

        if ($endAt === false) {
            throw new RuntimeException(
                "Terminator dataset tidak ditemukan: {$terminator}",
            );
        }

        return $this->parseTuples(substr(
            $sql,
            $valuesAt,
            $endAt - $valuesAt,
        ));
    }

    /** @return list<list<mixed>> */
    private function parseTuples(string $values): array
    {
        $rows = [];
        $length = strlen($values);
        $inString = false;
        $depth = 0;
        $tuple = '';

        for ($index = 0; $index < $length; $index++) {
            $character = $values[$index];

            if ($character === "'") {
                if ($inString
                    && $index + 1 < $length
                    && $values[$index + 1] === "'") {
                    if ($depth > 0) {
                        $tuple .= "''";
                    }

                    $index++;

                    continue;
                }

                $inString = ! $inString;

                if ($depth > 0) {
                    $tuple .= $character;
                }

                continue;
            }

            if (! $inString && $character === '(') {
                $depth++;

                if ($depth > 1) {
                    $tuple .= $character;
                }

                continue;
            }

            if (! $inString && $character === ')') {
                $depth--;

                if ($depth < 0) {
                    throw new RuntimeException('Tuple dataset tidak seimbang.');
                }

                if ($depth === 0) {
                    $rows[] = $this->parseFields($tuple);
                    $tuple = '';
                } else {
                    $tuple .= $character;
                }

                continue;
            }

            if ($depth > 0) {
                $tuple .= $character;
            }
        }

        if ($inString || $depth !== 0) {
            throw new RuntimeException('Statement dataset tidak lengkap.');
        }

        return $rows;
    }

    /** @return list<mixed> */
    private function parseFields(string $tuple): array
    {
        $fields = [];
        $field = '';
        $length = strlen($tuple);
        $inString = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $tuple[$index];

            if ($character === "'") {
                if ($inString
                    && $index + 1 < $length
                    && $tuple[$index + 1] === "'") {
                    $field .= "''";
                    $index++;

                    continue;
                }

                $inString = ! $inString;
                $field .= $character;

                continue;
            }

            if (! $inString && $character === ',') {
                $fields[] = $this->decodeValue($field);
                $field = '';

                continue;
            }

            $field .= $character;
        }

        if ($inString) {
            throw new RuntimeException('String dataset tidak lengkap.');
        }

        $fields[] = $this->decodeValue($field);

        return $fields;
    }

    private function decodeValue(string $value): mixed
    {
        $value = trim($value);

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (strlen($value) >= 2
            && $value[0] === "'"
            && $value[strlen($value) - 1] === "'") {
            return str_replace("''", "'", substr($value, 1, -1));
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return $value;
    }
}
