<?php
namespace CertGen;

/** FPDF plus a rotated-text helper (for the watermark) and UTF-8 -> Latin-1 conversion. */
class CertPdf extends \FPDF
{
    private float $angle = 0;

    /** FPDF's built-in fonts only cover Windows-1252, so convert and substitute "?" for anything else. */
    public function t(string $s): string
    {
        return mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    }

    public function rotate(float $angle, float $x, float $y): void
    {
        if ($this->angle != 0) {
            $this->_out('Q');
        }
        $this->angle = $angle;
        if ($angle != 0) {
            $a = $angle * M_PI / 180;
            $c = cos($a);
            $s = sin($a);
            $cx = $x * $this->k;
            $cy = ($this->h - $y) * $this->k;
            $this->_out(sprintf('q %.5F %.5F %.5F %.5F %.2F %.2F cm 1 0 0 1 %.2F %.2F cm', $c, $s, -$s, $c, $cx, $cy, -$cx, -$cy));
        }
    }

    public function _endpage(): void
    {
        if ($this->angle != 0) {
            $this->angle = 0;
            $this->_out('Q');
        }
        parent::_endpage();
    }
}

class PdfRenderer
{
    private const MARGIN = 18;
    private const PT = 0.3528;   // 1 point in mm

    public static function hasUnprintable(string $s): bool
    {
        return mb_convert_encoding(mb_convert_encoding($s, 'Windows-1252', 'UTF-8'), 'UTF-8', 'Windows-1252') !== $s;
    }

    /**
     * Build one PDF (a page per record).
     * @param array[] $records each: ['seq'=>int, 'data'=>array]
     * @param array $ctx issue_date (Y-m-d), serial_start (int)
     */
    public static function render(CertTemplate $t, array $records, array $ctx = []): string
    {
        $pdf = new CertPdf('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(self::MARGIN, self::MARGIN, self::MARGIN);
        $pdf->SetTitle($pdf->t($t->name), false);
        $pdf->SetCreator('CertGen');

        $ctx += ['issue_date' => date('Y-m-d'), 'serial_start' => 1];
        foreach (array_values($records) as $i => $rec) {
            self::certificate($pdf, $t, $rec, $ctx, $i);
        }
        if (!$records) {
            $pdf->AddPage();
        }
        return $pdf->Output('S');
    }

    /** One PDF containing a single record (used for ZIP downloads). */
    public static function renderOne(CertTemplate $t, array $record, array $ctx, int $index): string
    {
        $ctx['serial_start'] = ($ctx['serial_start'] ?? 1) + $index;
        return self::render($t, [$record], $ctx);
    }

    private static function vars(CertTemplate $t, array $rec, array $ctx, int $index): array
    {
        return $rec['data'] + [
            'serial_no'   => (string) ($ctx['serial_start'] + $index),
            'record_no'   => (string) ($rec['seq'] ?? $index + 1),
            'issue_date'  => $ctx['issue_date'],
            'today'       => date('Y-m-d'),
            'org_name'    => setting('org_name'),
            'org_address' => setting('org_address'),
            'org_contact' => setting('org_contact'),
            'place'       => setting('place'),
        ];
    }

    private static function certificate(CertPdf $pdf, CertTemplate $t, array $rec, array $ctx, int $index): void
    {
        $o = $t->options;
        $types = $t->fieldTypes() + ['issue_date' => 'date', 'today' => 'date'];
        $vars = self::vars($t, $rec, $ctx, $index);
        $dateFmt = setting('date_format', 'd/m/Y');
        $r = fn (string $s) => Placeholder::render($s, $vars, $types, $dateFmt);

        $hasSigs = trim($o['signatures']) !== '';
        $hasNote = trim($o['footer_note']) !== '';
        $bottom = 297 - ($o['border'] ? 19 : 14);
        $reserve = 12 + ($hasSigs ? 18 : 0) + ($o['place_date'] ? 10 : 0) + ($hasNote ? 10 : 0);
        $limit = $bottom - $reserve;

        $y = self::startPage($pdf, $t, $o);
        $first = true;
        foreach ($t->blocks as $b) {
            if ($pdf->GetY() > $limit) {
                self::startPage($pdf, $t, $o, false);
            }
            self::block($pdf, $o, $b, $r);
        }

        // ---- footer: note, place/date, signatures (pinned to the bottom) ----
        $w = 210 - 2 * self::MARGIN;
        $sigLine = $bottom - 8;
        $y = $sigLine - ($o['place_date'] ? 12 : 0) - ($hasNote ? 9 : 0);
        if ($hasNote) {
            $pdf->SetFont($o['font'], 'I', 9);
            $pdf->SetXY(self::MARGIN, $y);
            $pdf->Cell($w, 5, $pdf->t($r($o['footer_note'])), 0, 1, 'C');
            $y += 9;
        }
        if ($o['place_date']) {
            $pdf->SetFont($o['font'], '', 10);
            $pdf->SetXY(self::MARGIN, $y);
            $place = $r('{{place}}');
            $pdf->Cell($w / 2, 5, $pdf->t('Place: ' . $place), 0, 0, 'L');
            $pdf->Cell($w / 2, 5, $pdf->t('Date: ' . $r('{{issue_date}}')), 0, 1, 'R');
        }
        if ($hasSigs) {
            $labels = array_values(array_filter(array_map('trim', explode(',', $o['signatures'])), 'strlen'));
            $n = count($labels);
            $col = $w / $n;
            $pdf->SetFont($o['font'], '', 9);
            $pdf->SetDrawColor(80, 80, 80);
            $pdf->SetLineWidth(0.2);
            foreach ($labels as $i => $label) {
                $x = self::MARGIN + $i * $col;
                $pdf->Line($x + 4, $sigLine, $x + $col - 4, $sigLine);
                $pdf->SetXY($x, $sigLine + 1);
                $pdf->Cell($col, 5, $pdf->t($label), 0, 0, 'C');
            }
        }
    }

    /** Add a page, draw watermark/border/header. Returns the Y where content starts. */
    private static function startPage(CertPdf $pdf, CertTemplate $t, array $o, bool $withHeader = true): float
    {
        $pdf->AddPage();

        $mark = trim(setting('watermark'));
        if ($mark === '' && config('demo_mode')) {
            $mark = 'SAMPLE';
        }
        if ($mark !== '') {
            $pdf->SetFont('Helvetica', 'B', 70);
            $pdf->SetTextColor(226, 226, 226);
            $pdf->rotate(45, 105, 148);
            $w = $pdf->GetStringWidth($pdf->t($mark));
            $pdf->Text(105 - $w / 2, 160, $pdf->t($mark));
            $pdf->rotate(0, 0, 0);
            $pdf->SetTextColor(0, 0, 0);
        }

        if ($o['border']) {
            $pdf->SetDrawColor(40, 40, 40);
            $pdf->SetLineWidth(0.6);
            $pdf->Rect(8, 8, 194, 281);
            $pdf->SetLineWidth(0.2);
            $pdf->Rect(9.6, 9.6, 190.8, 277.8);
        }

        $y = $o['border'] ? 16 : 14;
        $pdf->SetXY(self::MARGIN, $y);
        if (!$o['show_header'] || !$withHeader) {
            $pdf->SetY($y + 2);
            return $y + 2;
        }

        $logo = setting('logo_file');
        $logoPath = $logo ? ROOT . '/storage/uploads/' . basename($logo) : '';
        $hasLogo = $logoPath && is_file($logoPath);
        $textX = self::MARGIN;
        $textW = 210 - 2 * self::MARGIN;
        if ($hasLogo) {
            $pdf->Image($logoPath, self::MARGIN, $y, 0, 22);
            $textX = self::MARGIN + 26;
            $textW = 210 - self::MARGIN - $textX;
        }
        $cursor = $y;
        $name = setting('org_name', 'Your Institution Name');
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetFont($o['font'], 'B', 17);
        $pdf->SetXY($textX, $cursor);
        $pdf->MultiCell($textW, 7.5, $pdf->t($name), 0, 'C');
        $pdf->SetFont($o['font'], '', 9.5);
        foreach ([setting('org_address'), setting('org_contact')] as $line) {
            if (trim($line) !== '') {
                $pdf->SetX($textX);
                $pdf->MultiCell($textW, 4.6, $pdf->t($line), 0, 'C');
            }
        }
        $endY = max($pdf->GetY(), $hasLogo ? $y + 22 : 0) + 2;
        $pdf->SetDrawColor(40, 40, 40);
        $pdf->SetLineWidth(0.4);
        $pdf->Line(self::MARGIN, $endY, 210 - self::MARGIN, $endY);
        $pdf->SetLineWidth(0.2);
        $pdf->SetXY(self::MARGIN, $endY + 4);
        return $endY + 4;
    }

    // ---- block layout ------------------------------------------------------

    private static function block(CertPdf $pdf, array $o, array $b, callable $r): void
    {
        $font = $o['font'];
        $w = 210 - 2 * self::MARGIN;
        $size = $b['size'] ?: $o['base_size'];
        $lh = $size * self::PT * 1.55;
        $pdf->SetX(self::MARGIN);
        $pdf->SetTextColor(0, 0, 0);

        switch ($b['type']) {
            case 'spacer':
                $pdf->Ln($b['size'] ?: 6);
                return;

            case 'rule':
                $y = $pdf->GetY() + 1.5;
                $pdf->SetDrawColor(120, 120, 120);
                $pdf->Line(self::MARGIN, $y, 210 - self::MARGIN, $y);
                $pdf->Ln(4);
                return;

            case 'heading':
                $size = $b['size'] ?: 18;
                $pdf->SetFont($font, 'B', $size);
                $pdf->Ln(1);
                $pdf->MultiCell($w, $size * self::PT * 1.4, $pdf->t($r($b['a'])), 0, $b['align'] ?: 'C');
                $pdf->Ln(1.5);
                return;

            case 'text':
                $text = $r($b['a']);
                if ($b['align'] === 'J') {
                    $pdf->SetFont($font, $b['bold'] ? 'B' : '', $size);
                    $pdf->MultiCell($w, $lh, $pdf->t(str_replace('**', '', $text)), 0, 'J');
                } else {
                    self::rich($pdf, $font, $size, $lh, $text, $b['align'], $b['bold'], $w);
                }
                $pdf->Ln($lh * 0.35);
                return;

            case 'kv':
                $label = $r($b['a']);
                $value = $r($b['b']);
                $text = ($label !== '' ? $label . ': ' : '') . '**' . $value . '**';
                self::rich($pdf, $font, $size, $lh, $text, 'L', $b['bold'], $w);
                $pdf->Ln($lh * 0.35);
                return;

            case 'row':
                $left = $r($b['a']);
                $right = $r($b['b']);
                $wl = self::richWidth($pdf, $font, $size, $left, $b['bold']);
                $wr = self::richWidth($pdf, $font, $size, $right, $b['bold']);
                $y = $pdf->GetY();
                if ($b['align'] === 'R') {
                    $fits = $wl + $wr + 6 <= $w;
                    $xr = self::MARGIN + $w - $wr - 2.5;   // slack: FPDF Write() also reserves its 1mm cell margins
                } else {
                    $split = $w * 0.55;
                    $fits = $wl <= $split - 3 && $wr <= $w - $split;
                    $xr = self::MARGIN + $split;
                }
                self::richWrite($pdf, $font, $size, $lh, $left, $b['bold']);
                if ($fits) {
                    $pdf->SetXY($xr, $y);
                    self::richWrite($pdf, $font, $size, $lh, $right, $b['bold']);
                    $pdf->Ln($lh);
                } else {                               // too wide for one line: stack them
                    $pdf->Ln($lh);
                    $pdf->SetX(self::MARGIN);
                    self::richWrite($pdf, $font, $size, $lh, $right, $b['bold']);
                    $pdf->Ln($lh);
                }
                $pdf->Ln($lh * 0.35);
                return;
        }
    }

    /** Split "a **b** c" into [[text, bold], ...]. */
    private static function segments(string $s): array
    {
        $out = [];
        foreach (explode('**', $s) as $i => $part) {
            if ($part !== '') {
                $out[] = [$part, $i % 2 === 1];
            }
        }
        return $out;
    }

    private static function richWidth(CertPdf $pdf, string $font, float $size, string $text, bool $allBold): float
    {
        $w = 0;
        foreach (self::segments($text) as [$seg, $bold]) {
            $pdf->SetFont($font, ($bold || $allBold) ? 'B' : '', $size);
            $w += $pdf->GetStringWidth($pdf->t($seg));
        }
        return $w;
    }

    /** Write bold/normal segments on the current line (wraps at the right margin). */
    private static function richWrite(CertPdf $pdf, string $font, float $size, float $lh, string $text, bool $allBold): void
    {
        foreach (self::segments($text) as [$seg, $bold]) {
            $pdf->SetFont($font, ($bold || $allBold) ? 'B' : '', $size);
            $pdf->Write($lh, $pdf->t($seg));
        }
    }

    private static function rich(CertPdf $pdf, string $font, float $size, float $lh, string $text, string $align, bool $bold, float $w): void
    {
        $tw = self::richWidth($pdf, $font, $size, $text, $bold);
        $pdf->SetX(self::MARGIN);
        if ($tw <= $w) {
            if ($align === 'C') {
                $pdf->SetX(self::MARGIN + ($w - $tw) / 2);
            } elseif ($align === 'R') {
                $pdf->SetX(self::MARGIN + $w - $tw - 2.5);
            }
        }
        self::richWrite($pdf, $font, $size, $lh, $text, $bold);
        $pdf->Ln($lh);
    }
}
