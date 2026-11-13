<?php
namespace CertGen;

/**
 * Resolves {{field}} and {{field|filter|filter:arg}} inside template text.
 *
 * Filters: upper, lower, title, trim, default:TEXT, date[:FORMAT], date_words,
 *          map:A=X,B=Y,*=FALLBACK   (case-insensitive match on the value)
 */
class Placeholder
{
    private const PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*((?:\|[^{}|]*)*)\s*\}\}/u';

    /** Names of every {{placeholder}} used in a piece of text. */
    public static function keysIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $m);
        return array_values(array_unique($m[1]));
    }

    /**
     * @param array $vars    key => raw value (dates as Y-m-d)
     * @param array $types   key => 'text'|'date'|'number'
     */
    public static function render(string $tpl, array $vars, array $types = [], string $dateFmt = 'd/m/Y'): string
    {
        return preg_replace_callback(self::PATTERN, function (array $m) use ($vars, $types, $dateFmt) {
            $key = $m[1];
            $val = isset($vars[$key]) && is_scalar($vars[$key]) ? (string) $vars[$key] : '';
            $filters = $m[2] === '' ? [] : array_map('trim', explode('|', substr($m[2], 1)));

            $names = array_map(fn ($f) => strtolower(explode(':', $f, 2)[0]), $filters);
            $hasDateFilter = (bool) array_intersect($names, ['date', 'date_words']);
            if (($types[$key] ?? '') === 'date' && !$hasDateFilter) {
                $val = self::formatDate($val, $dateFmt);
            }

            foreach ($filters as $f) {
                $parts = explode(':', $f, 2);
                $name = strtolower(trim($parts[0]));
                $arg = $parts[1] ?? null;
                $val = self::apply($name, $arg, $val, $dateFmt);
            }
            return $val;
        }, $tpl) ?? $tpl;
    }

    private static function apply(string $name, ?string $arg, string $val, string $dateFmt): string
    {
        switch ($name) {
            case 'upper':
                return mb_strtoupper($val);
            case 'lower':
                return mb_strtolower($val);
            case 'title':
                return mb_convert_case($val, MB_CASE_TITLE);
            case 'trim':
                return trim($val);
            case 'default':
                return $val === '' ? (string) $arg : $val;
            case 'date':
                return self::formatDate($val, $arg !== null && $arg !== '' ? $arg : $dateFmt);
            case 'date_words':
                return DateWords::format($val);
            case 'map':
                $fallback = $val;
                $needle = mb_strtolower(trim($val));
                foreach (explode(',', (string) $arg) as $pair) {
                    $kv = explode('=', $pair, 2);
                    if (count($kv) !== 2) {
                        continue;
                    }
                    $k = trim($kv[0]);
                    if ($k === '*') {
                        $fallback = trim($kv[1]);
                    } elseif (mb_strtolower($k) === $needle) {
                        return trim($kv[1]);
                    }
                }
                return $fallback;
        }
        return $val; // unknown filter: ignore
    }

    public static function formatDate(string $iso, string $format): string
    {
        if ($iso === '') {
            return '';
        }
        $d = \DateTime::createFromFormat('Y-m-d', substr($iso, 0, 10));
        $err = \DateTime::getLastErrors();
        if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) {
            return $iso;
        }
        return $d->format($format);
    }
}

/** 2005-01-12 -> "Twelfth of January Two Thousand and Five" (as printed on many certificates). */
class DateWords
{
    private const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
        'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    private const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    private const ORDINALS = ['', 'First', 'Second', 'Third', 'Fourth', 'Fifth', 'Sixth', 'Seventh', 'Eighth', 'Ninth',
        'Tenth', 'Eleventh', 'Twelfth', 'Thirteenth', 'Fourteenth', 'Fifteenth', 'Sixteenth', 'Seventeenth',
        'Eighteenth', 'Nineteenth', 'Twentieth', 'Twenty First', 'Twenty Second', 'Twenty Third', 'Twenty Fourth',
        'Twenty Fifth', 'Twenty Sixth', 'Twenty Seventh', 'Twenty Eighth', 'Twenty Ninth', 'Thirtieth', 'Thirty First'];

    public static function format(string $iso): string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $iso;
        }
        $month = date('F', mktime(0, 0, 0, (int) $m[2], 1, 2000));
        return self::ORDINALS[(int) $m[3]] . ' of ' . $month . ' ' . self::year((int) $m[1]);
    }

    private static function below100(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }
        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }

    private static function year(int $y): string
    {
        if ($y >= 2000 && $y < 2100) {
            $base = 'Two Thousand';
        } elseif ($y >= 1000) {
            $base = self::below100(intdiv($y, 100)) . ' Hundred';
        } else {
            return (string) $y;
        }
        $rest = $y % 100;
        return $rest === 0 ? $base : $base . ' and ' . self::below100($rest);
    }
}
