<?php

namespace App\Modules\Reports\Exports;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class SafeCsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<array-key, list<bool|float|int|string|null>>  $rows
     */
    public function download(array $headings, iterable $rows, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows): void {
            $stream = fopen('php://output', 'wb');

            if ($stream === false) {
                throw new RuntimeException('Unable to open the CSV output stream.');
            }

            try {
                // Excel recognizes the file as UTF-8 without changing the visible data.
                fwrite($stream, "\xEF\xBB\xBF");
                $this->writeRow($stream, $headings);

                foreach ($rows as $row) {
                    $this->writeRow($stream, $row);
                }
            } finally {
                fclose($stream);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @param resource $stream */
    private function writeRow($stream, array $row): void
    {
        fputcsv(
            $stream,
            array_map($this->safeCell(...), array_values($row)),
            ',',
            '"',
            '',
        );
    }

    private function safeCell(bool|float|int|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $value = str_replace("\0", '', $value);

        // Spreadsheet applications can execute values beginning with these
        // characters as formulas. Prefix user-controlled text with an apostrophe
        // so the exported value remains visible but is always treated as text.
        if (preg_match('/^(?:[=+\-@\t\r\n]|[\p{Z}\x00-\x20]+[=+\-@])/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
