<?php
/**
 * Dependency-free unit tests.   php tests/run.php
 * (tests/smoke.sh covers the full web workflow.)
 */
$tmpDb = sys_get_temp_dir() . '/certgen-test-' . getmypid() . '.sqlite';
putenv('DB_PATH=' . $tmpDb);
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{CertTemplate, Db, Importer, PdfRenderer, Placeholder, Presets};

$pass = 0;
$fail = 0;
function t(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok   $name\n";
    } else {
        $fail++;
        echo "  FAIL $name" . ($detail ? "  ($detail)" : '') . "\n";
    }
}
function eq(string $name, $expected, $actual): void
{
    t($name, $expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}

echo "Placeholder\n";
$v = ['name' => ' asha patil ', 'g' => 'female', 'd' => '2005-01-12', 'empty' => ''];
$types = ['d' => 'date'];
eq('plain value', 'x  asha patil  y', Placeholder::render('x {{name}} y', $v));
eq('upper + trim chain', 'ASHA PATIL', Placeholder::render('{{name|trim|upper}}', $v));
eq('title case', 'Asha Patil', Placeholder::render('{{name|trim|title}}', $v));
eq('map matches case-insensitively', 'She', Placeholder::render('{{g|map:M=He,FEMALE=She,*=They}}', $v));
eq('map falls back to *', 'They', Placeholder::render('{{g|map:M=He,F=She,*=They}}', $v));
eq('map without * keeps value', 'female', Placeholder::render('{{g|map:M=He}}', $v));
eq('date field auto-formats', '12/01/2005', Placeholder::render('{{d}}', $v, $types));
eq('date with custom format', '12 January 2005', Placeholder::render('{{d|date:d F Y}}', $v, $types));
eq('date format honours setting', '12-01-2005', Placeholder::render('{{d}}', $v, $types, 'd-m-Y'));
eq('date_words', 'Twelfth of January Two Thousand and Five', Placeholder::render('{{d|date_words}}', $v, $types));
eq('date_words 1999', 'Thirty First of December Nineteen Hundred and Ninety Nine', Placeholder::render('{{x|date_words}}', ['x' => '1999-12-31']));
eq('date_words 2000 has no "and"', 'First of March Two Thousand', Placeholder::render('{{x|date_words}}', ['x' => '2000-03-01']));
eq('invalid date left as-is', 'not-a-date', Placeholder::render('{{x|date_words}}', ['x' => 'not-a-date']));
eq('default filter', '-', Placeholder::render('{{empty|default:-}}', $v));
eq('unknown field prints blank', 'a--b', Placeholder::render('a-{{nope}}-b', $v));
eq('unknown filter ignored', ' asha patil ', Placeholder::render('{{name|whatever}}', $v));
eq('braces without a valid name are untouched', '{{ 1bad }}', Placeholder::render('{{ 1bad }}', $v));
eq('keysIn finds each key once', ['a', 'b'], Placeholder::keysIn('{{a}} {{b|upper}} {{a}}'));
eq('placeholder text is not re-interpreted', '{{name}}', Placeholder::render('{{x}}', ['x' => '{{name}}', 'name' => 'SHOULD NOT APPEAR']));

echo "Importer: dates\n";
eq('ISO', '2005-06-12', Importer::parseDate('2005-06-12'));
eq('day-first slashes', '2005-06-12', Importer::parseDate('12/06/2005'));
eq('dashes', '2005-06-12', Importer::parseDate('12-06-2005'));
eq('dots', '2005-06-12', Importer::parseDate('12.06.2005'));
eq('text month', '2005-06-12', Importer::parseDate('12 Jun 2005'));
eq('impossible date rejected', null, Importer::parseDate('31/02/2005'));
eq('garbage rejected', null, Importer::parseDate('yesterday'));
eq('two-digit year rejected (ambiguous)', null, Importer::parseDate('12/06/05'));

echo "Importer: auto-mapping\n";
$fields = Presets::all()['bonafide']['fields'];
$map = Importer::autoMap(['Full Name', 'gender', 'Enrollment No', 'ROLL NO.', 'DOB', 'Date of Birth', 'Junk'], $fields);
eq('exact label', 'full_name', $map[0]);
eq('by key', 'gender', $map[1]);
eq('punctuation ignored', 'enrollment_no', $map[2]);
eq('case ignored', 'roll_no', $map[3]);
eq('unrelated column unmapped', null, $map[6]);
eq('too-short abbreviation is not guessed', null, $map[4]);
eq('full label still maps', 'date_of_birth', $map[5]);

echo "Importer: CSV and Excel\n";
$csv = sys_get_temp_dir() . '/cg-test.csv';
file_put_contents($csv, "\xEF\xBB\xBFFull Name;Date of Birth;Course\r\nAsha Patil;12/06/2005;Civil\r\n;;\r\nRavi Nair;2004-01-31;Mech\r\n");
$r = Importer::read($csv, 'csv');
eq('BOM stripped, semicolon detected', ['Full Name', 'Date of Birth', 'Course'], $r['headers']);
eq('blank rows dropped', 2, count($r['rows']));
file_put_contents($csv, "Name,Note\n\"Müller, Jörg\",\"he said \"\"hi\"\"\"\n");
$r = Importer::read($csv, 'csv');
eq('quoted commas and UTF-8', 'Müller, Jörg', $r['rows'][0][0]);
eq('escaped quotes', 'he said "hi"', $r['rows'][0][1]);
file_put_contents($csv, "Name\n" . mb_convert_encoding("José", 'Windows-1252', 'UTF-8') . "\n");
eq('Windows-1252 CSV converted', 'José', Importer::read($csv, 'csv')['rows'][0][0]);

$xl = sys_get_temp_dir() . '/cg-test.xlsx';
$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->fromArray(['Full Name', 'Date of Birth', 'Roll No.', 'Score'], null, 'A1');
$ws->setCellValue('A2', 'Asha Patil');
$ws->setCellValue('B2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(new DateTime('2005-06-12')));
$ws->getStyle('B2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
$ws->setCellValue('C2', 34);
$ws->setCellValue('D2', 7.5);
$ws->setCellValue('A3', 'Ravi Nair');
$ws->setCellValueExplicit('C3', '007', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($xl);
$r = Importer::read($xl, 'xlsx');
eq('real Excel date becomes ISO', '2005-06-12', $r['rows'][0][1]);
eq('whole number has no ".0"', '34', $r['rows'][0][2]);
eq('decimal kept', '7.5', $r['rows'][0][3]);
eq('text keeps leading zeros', '007', $r['rows'][1][2]);

echo "Importer: validation\n";
$fields = [
    ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
    ['key' => 'dob', 'label' => 'DOB', 'type' => 'date', 'required' => false],
    ['key' => 'roll', 'label' => 'Roll', 'type' => 'number', 'required' => false],
];
$rows = [['Asha', '12/06/2005', '5'], ['', '01/01/2005', '1'], ['Ravi', '99/99/2005', '2'], ['Meera', '', 'abc'], ['Tina', '2005-02-03', '1,200'], ['Rosh', 'x', '']];
$res = Importer::buildRecords($rows, [0 => 'name', 1 => 'dob', 2 => 'roll'], $fields);
eq('only valid rows imported', ['Asha', 'Tina'], array_column($res['records'], 'name'));
eq('4 problem rows reported', 4, count($res['errors']));
t('error cites the spreadsheet row number', str_contains($res['errors'][0], 'Row 3'), $res['errors'][0]);
eq('date normalised to ISO', '2005-06-12', $res['records'][0]['dob']);
$res = Importer::buildRecords([['Zoë ऑ']], [0 => 'name'], $fields);
t('warns about characters the PDF font cannot print', count($res['warnings']) === 1);

echo "Template editing\n";
$post = ['name' => 'Test', 'f_label' => ['Full Name', 'Date Of Birth', ''], 'f_key' => ['', 'dob', ''], 'f_type' => ['text', 'date', 'text'], 'f_req' => ['1', '0', '0'],
    'b_type' => ['heading', 'text', 'bogus'], 'b_a' => ['HELLO', 'Hi {{full_name}} {{ghost}}', 'x'], 'b_b' => ['', '', ''], 'b_align' => ['C', 'Z', 'L'], 'b_size' => ['', '', ''], 'b_bold' => ['1', '0', '0'],
    'o_font' => 'Comic Sans', 'o_base_size' => '99'];
[$tpl, $errs, $warns] = CertTemplate::fromPost($post);
eq('no errors for a valid form', [], $errs);
eq('key generated from label', 'full_name', $tpl->fields[0]['key']);
eq('explicit key kept', 'dob', $tpl->fields[1]['key']);
eq('blank field rows dropped', 2, count($tpl->fields));
eq('unknown block type dropped', 2, count($tpl->blocks));
eq('bad alignment falls back', 'L', $tpl->blocks[1]['align']);
eq('unknown font falls back', 'Times', $tpl->options['font']);
eq('text size is clamped', 18, $tpl->options['base_size']);
t('warns about unknown placeholder', count($warns) === 1 && str_contains($warns[0], 'ghost'));
$post['f_key'] = ['serial_no', 'dob', ''];
[, $errs] = CertTemplate::fromPost($post);
t('reserved key rejected', count($errs) === 1 && str_contains($errs[0], 'reserved'));
$post['f_key'] = ['same', 'same', ''];
[, $errs] = CertTemplate::fromPost($post);
t('duplicate keys rejected', count($errs) === 1);
[, $errs] = CertTemplate::fromPost(['name' => '']);
t('empty form gives errors', count($errs) >= 3);

echo "Database + PDF\n";
$lc = Presets::install('leaving');
$bf = Presets::install('bonafide');
eq('presets saved', 2, count(CertTemplate::all()));
$round = CertTemplate::find($lc->id);
eq('template round-trips through the DB', $lc->fields, $round->fields);
eq('blocks round-trip', $lc->blocks, $round->blocks);
$batch = Db::insert('INSERT INTO batches (template_id, name, created_by, created_at) VALUES (?,?,?,?)', [$lc->id, 'b', null, now()]);
Db::insert('INSERT INTO records (batch_id, seq, data_json, created_at, updated_at) VALUES (?,?,?,?,?)', [$batch, 1, '{}', now(), now()]);
try {
    CertTemplate::delete($lc->id);
    t('cannot delete a template that has batches', false);
} catch (PDOException $e) {
    t('cannot delete a template that has batches', true);
}
Db::run('DELETE FROM batches WHERE id = ?', [$batch]);
eq('deleting a batch cascades to its records', 0, (int) Db::val('SELECT COUNT(*) FROM records'));

$recs = [];
for ($i = 1; $i <= 3; $i++) {
    $d = $bf->sampleData();
    $d['full_name'] = "Person $i";
    $recs[] = ['seq' => $i, 'data' => $d];
}
$pdf = PdfRenderer::render($bf, $recs, ['issue_date' => '2026-01-05', 'serial_start' => 10]);
t('PDF header', str_starts_with($pdf, '%PDF'));
eq('one page per record', 3, preg_match_all('#/Type /Page\b#', $pdf));
$one = PdfRenderer::renderOne($lc, ['seq' => 1, 'data' => $lc->sampleData()], ['issue_date' => '2026-01-05', 'serial_start' => 1], 0);
eq('Leaving certificate fits on one page', 1, preg_match_all('#/Type /Page\b#', $one));
$long = $lc->sampleData();
foreach ($long as $k => $_) {
    $long[$k] = str_repeat('Very long value ', 12);
}
$many = PdfRenderer::render($lc, [['seq' => 1, 'data' => $long]]);
t('very long values still produce a valid PDF', str_starts_with($many, '%PDF'));
t('empty record list still produces a valid PDF', str_starts_with(PdfRenderer::render($lc, []), '%PDF'));
$uni = $bf->sampleData();
$uni['full_name'] = 'Zoë ऑ Åke – “quoted”';
t('non-Latin text does not crash the renderer', str_starts_with(PdfRenderer::render($bf, [['seq' => 1, 'data' => $uni]]), '%PDF'));

Db::reset();
@unlink($tmpDb);
@unlink($csv);
@unlink($xl);
echo "\npassed: $pass  failed: $fail\n";
exit($fail ? 1 : 0);
