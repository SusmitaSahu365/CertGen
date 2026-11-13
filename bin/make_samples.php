<?php
// Regenerates the fake sample spreadsheets in /samples from the built-in presets.
//   php bin/make_samples.php
// All names and numbers are invented.
require __DIR__ . '/../src/bootstrap.php';

use CertGen\Presets;

$first = ['Aarav', 'Diya', 'Kabir', 'Meera', 'Rohan', 'Isha', 'Vihaan', 'Anaya', 'Arjun', 'Saanvi', 'Reyansh', 'Myra'];
$last = ['Sharma', 'Patil', 'Verma', 'Nair', 'Kulkarni', 'Iyer', 'Gupta', 'Deshmukh', 'Reddy', 'Joshi', 'Menon', 'Shah'];
$mothers = ['Sunita', 'Kavita', 'Lata', 'Anita', 'Rekha', 'Seema', 'Usha', 'Neha', 'Pooja', 'Geeta', 'Manju', 'Radha'];
$courses = ['Computer Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Information Technology'];

function sheet(string $name, array $headers, array $rows): void
{
    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ws = $ss->getActiveSheet();
    $ws->fromArray(array_merge([$headers], $rows), null, 'A1', true);
    $ws->getStyle('A1:' . $ws->getHighestColumn() . '1')->getFont()->setBold(true);
    foreach (range(1, count($headers)) as $i) {
        $ws->getColumnDimensionByColumn($i)->setAutoSize(true);
    }
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save(__DIR__ . "/../samples/$name.xlsx");

    $fh = fopen(__DIR__ . "/../samples/$name.csv", 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $headers, ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($fh, $r, ',', '"', '\\');
    }
    fclose($fh);
}

$p = Presets::all();
$labels = fn ($slug) => array_column($p[$slug]['fields'], 'label');

$lc = [];
$bf = [];
for ($i = 0; $i < 12; $i++) {
    $name = $first[$i] . ' ' . $last[($i * 5) % 12];
    $dob = sprintf('200%d-%02d-%02d', 4 + $i % 3, 1 + ($i * 7) % 12, 1 + ($i * 11) % 27);
    $course = $courses[$i % 4];
    $lc[] = [
        'DEMO-' . (101 + $i), $name, $mothers[$i], 'Hindu', $i % 3 ? '-' : 'Maratha', 'Indian', '2101' . (160000 + $i * 7),
        'Sample City', $dob, 'Sample High School', '2021-07-0' . (1 + $i % 5), 'Good', 'Excellent', '2024-06-15',
        $course, 'Third Year', 'Course completed', 'Passed with distinction',
    ];
    $bf[] = [$name, $i % 2 ? 'F' : 'M', '2101' . (160000 + $i * 7), 10 + $i, 240000 + $i * 13, $course, 'Second Year', '2025-2026', $dob, 'Scholarship application'];
}
sheet('leaving_certificate_sample', $labels('leaving'), $lc);
sheet('bonafide_sample', $labels('bonafide'), $bf);
echo "Wrote samples/leaving_certificate_sample.(xlsx|csv) and samples/bonafide_sample.(xlsx|csv)\n";
