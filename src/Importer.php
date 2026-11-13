<?php
namespace CertGen;

class Importer
{
    /**
     * Read the first sheet of a CSV / XLSX / XLS file.
     * @return array{headers:string[], rows:array<int,string[]>} rows exclude the header; blank rows removed
     */
    public static function read(string $path, string $ext, int $maxRows = 5000): array
    {
        $ext = strtolower($ext);
        $all = $ext === 'csv' ? self::readCsv($path, $maxRows + 1) : self::readExcel($path, $maxRows + 1);
        $headers = array_map(fn ($h) => trim((string) $h), array_shift($all) ?? []);
        $rows = [];
        foreach ($all as $r) {
            if (trim(implode('', $r)) === '') {
                continue;
            }
            $rows[] = $r;
        }
        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function readCsv(string $path, int $max): array
    {
        $raw = file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);               // strip BOM
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');  // Excel "CSV" on Windows
        }
        $firstLine = strtok($raw, "\n") ?: '';
        $delims = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($delims);
        $delim = array_key_first($delims);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $out = [];
        while (($row = fgetcsv($fh, 0, $delim, '"', '\\')) !== false && count($out) < $max + 1) {
            $out[] = array_map(fn ($c) => trim((string) $c), $row);
        }
        fclose($fh);
        return $out;
    }

    private static function readExcel(string $path, int $max): array
    {
        $io = '\\PhpOffice\\PhpSpreadsheet\\IOFactory';
        if (!class_exists($io)) {
            throw new \RuntimeException('Excel support needs the PhpSpreadsheet library. Run "composer install", or upload a CSV file instead.');
        }
        $reader = $io::createReaderForFile($path);
        $sheet = $reader->load($path)->getActiveSheet();
        $lastRow = min($sheet->getHighestDataRow(), $max + 1);
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $out = [];
        for ($r = 1; $r <= $lastRow; $r++) {
            $row = [];
            for ($c = 1; $c <= $lastCol; $c++) {
                $row[] = self::cellText($sheet->getCell([$c, $r]));
            }
            $out[] = $row;
        }
        return $out;
    }

    private static function cellText($cell): string
    {
        $v = $cell->isFormula() ? $cell->getCalculatedValue() : $cell->getValue();
        if ($v === null) {
            return '';
        }
        if (is_numeric($v) && \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
            return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($v)->format('Y-m-d');
        }
        if ($v instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
            $v = $v->getPlainText();
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        if (is_float($v) && floor($v) == $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }
        return trim((string) $v);
    }

    private static function norm(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s));
    }

    /**
     * Guess which template field each spreadsheet column belongs to.
     * @return array<int,string|null> column index => field key (or null)
     */
    public static function autoMap(array $headers, array $fields): array
    {
        $byNorm = [];
        foreach ($fields as $f) {
            $byNorm[self::norm($f['key'])] = $f['key'];
            $byNorm[self::norm($f['label'])] = $f['key'];
        }
        $map = [];
        $taken = [];
        foreach ($headers as $i => $h) {
            $n = self::norm($h);
            $key = $byNorm[$n] ?? null;
            if ($key === null && $n !== '') {          // looser: one name contains the other
                foreach ($byNorm as $fn => $fk) {
                    if (strlen($fn) >= 4 && strlen($n) >= 4 && (str_contains($fn, $n) || str_contains($n, $fn))) {
                        $key = $fk;
                        break;
                    }
                }
            }
            if ($key !== null && isset($taken[$key])) {
                $key = null;
            }
            if ($key !== null) {
                $taken[$key] = true;
            }
            $map[$i] = $key;
        }
        return $map;
    }

    /** Parse a date typed in a spreadsheet. Day-first (13/02/2005) is assumed for slashes. */
    public static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (($ts = strtotime($s)) !== false && preg_match('/[a-z]/i', $s) && preg_match('/\d{4}/', $s)) {
            return date('Y-m-d', $ts);                  // "12 Jan 2005", "January 12, 2005"
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Convert spreadsheet rows to validated records.
     * @param array<int,string|null> $map column index => field key
     * @return array{records:array[], errors:string[], warnings:string[]}
     */
    public static function buildRecords(array $rows, array $map, array $fields): array
    {
        $byKey = [];
        foreach ($fields as $f) {
            $byKey[$f['key']] = $f;
        }
        $records = [];
        $errors = [];
        $unprintable = 0;

        foreach ($rows as $n => $row) {
            $line = $n + 2;                              // +1 header, +1 for 1-based
            $rec = [];
            $rowErr = [];
            foreach ($fields as $f) {
                $rec[$f['key']] = '';
            }
            foreach ($map as $col => $key) {
                if ($key === null || !isset($byKey[$key])) {
                    continue;
                }
                $val = trim((string) ($row[$col] ?? ''));
                $f = $byKey[$key];
                if ($val !== '' && $f['type'] === 'date') {
                    $iso = self::parseDate($val);
                    if ($iso === null) {
                        $rowErr[] = "\"{$f['label']}\" is not a valid date (\"$val\")";
                    }
                    $val = $iso ?? $val;
                } elseif ($val !== '' && $f['type'] === 'number' && !is_numeric(str_replace(',', '', $val))) {
                    $rowErr[] = "\"{$f['label']}\" should be a number (\"$val\")";
                }
                $rec[$key] = $val;
                if ($val !== '' && PdfRenderer::hasUnprintable($val)) {
                    $unprintable++;
                }
            }
            foreach ($fields as $f) {
                if (!empty($f['required']) && $rec[$f['key']] === '') {
                    $rowErr[] = "\"{$f['label']}\" is required";
                }
            }
            if ($rowErr) {
                $errors[] = "Row $line skipped: " . implode('; ', $rowErr);
            } else {
                $records[] = $rec;
            }
        }
        $warnings = [];
        if ($unprintable) {
            $warnings[] = "$unprintable cell(s) contain characters outside the Latin-1 range (for example Devanagari). The built-in PDF fonts cannot print them; they will show as \"?\".";
        }
        return ['records' => $records, 'errors' => $errors, 'warnings' => $warnings];
    }
}
