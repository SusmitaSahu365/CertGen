<?php
// Download a blank spreadsheet whose columns match a template's fields.
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate};

Auth::require();
$t = CertTemplate::find((int) input('id'));
if (!$t) {
    http_response_code(404);
    exit('Template not found.');
}
$headers = array_map(fn ($f) => $f['label'], $t->fields);
$example = array_values($t->sampleData());
$base = slugify($t->name) . '-sample';

if (input('format') === 'csv' || !class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$base.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '\\');
    fputcsv($out, $example, ',', '"', '\\');
    exit;
}

$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$ws = $ss->getActiveSheet();
$ws->setTitle('Data');
$ws->fromArray([$headers, $example], null, 'A1', true);
$ws->getStyle('A1:' . $ws->getHighestColumn() . '1')->getFont()->setBold(true);
foreach (range(1, count($headers)) as $i) {
    $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment; filename=\"$base.xlsx\"");
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
