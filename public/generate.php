<?php
// Builds the PDF (or ZIP of PDFs) for the chosen records and streams it back.
define('NO_CSP', true);
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db, PdfRenderer};

$user = Auth::require();
if (!is_post()) {
    redirect('batches.php');
}
csrf_verify();

function fail(string $msg, int $batch): void
{
    flash('danger', $msg);
    redirect($batch ? 'batch.php?id=' . $batch : 'batches.php');
}

$batchId = (int) input('batch');
$batch = Db::one('SELECT * FROM batches WHERE id = ?', [$batchId]);
$tpl = $batch ? CertTemplate::find((int) $batch['template_id']) : null;
if (!$batch || !$tpl) {
    fail('Batch not found.', 0);
}

$issue = (string) input('issue_date');
$issue = preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue) && checkdate((int) substr($issue, 5, 2), (int) substr($issue, 8, 2), (int) substr($issue, 0, 4)) ? $issue : date('Y-m-d');
$serial = max(0, (int) input('serial_start', 1));
$mode = in_array(input('mode'), ['view', 'download', 'zip'], true) ? input('mode') : 'view';
$cap = (int) config('max_pdf_records', 1000);
if ($mode === 'zip') {
    $cap = min($cap, 500);
}

if (input('scope') === 'selected') {
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    if (!$ids) {
        fail('Select at least one record, or choose “All”.', $batchId);
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $rows = Db::all("SELECT id, seq, data_json FROM records WHERE batch_id = ? AND id IN ($in) ORDER BY seq", array_merge([$batchId], $ids));
} else {
    $where = 'batch_id = ?';
    $params = [$batchId];
    $q = trim((string) input('q'));
    if ($q !== '') {
        $where .= " AND data_json LIKE ? ESCAPE '!'";
        $params[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    }
    $rows = Db::all("SELECT id, seq, data_json FROM records WHERE $where ORDER BY seq LIMIT " . ($cap + 1), $params);
}
if (!$rows) {
    fail('There are no records to generate.', $batchId);
}
if (count($rows) > $cap) {
    fail("That is more than $cap certificates for one " . ($mode === 'zip' ? 'ZIP' : 'PDF') . ". Use search to narrow it down and generate in parts.", $batchId);
}

$records = array_map(fn ($r) => ['seq' => (int) $r['seq'], 'data' => json_decode($r['data_json'], true) ?: []], $rows);
$ctx = ['issue_date' => $issue, 'serial_start' => $serial];

try {
    $base = slugify($tpl->name . ' ' . $batch['name']);
    if ($mode === 'zip') {
        $zipPath = ROOT . '/storage/tmp/' . bin2hex(random_bytes(8)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Could not create the ZIP file.');
        }
        foreach ($records as $i => $rec) {
            $label = '';
            foreach ($tpl->fields as $f) {
                if ($f['type'] === 'text' && trim($rec['data'][$f['key']] ?? '') !== '') {
                    $label = slugify($rec['data'][$f['key']]);
                    break;
                }
            }
            $zip->addFromString(sprintf('%03d-%s.pdf', $rec['seq'], $label ?: 'certificate'), PdfRenderer::renderOne($tpl, $rec, $ctx, $i));
        }
        $zip->close();
        $out = file_get_contents($zipPath);
        @unlink($zipPath);
        header('Content-Type: application/zip');
        header("Content-Disposition: attachment; filename=\"$base.zip\"");
    } else {
        $out = PdfRenderer::render($tpl, $records, $ctx);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . "; filename=\"$base.pdf\"");
    }
} catch (Throwable $e) {
    error_log('CertGen generate failed: ' . $e->getMessage());
    fail('Could not generate the certificates: ' . $e->getMessage(), $batchId);
}

Db::run('INSERT INTO generation_log (batch_id, template_name, user_name, record_count, mode, created_at) VALUES (?,?,?,?,?,?)',
    [$batchId, $tpl->name, $user['username'], count($records), $mode, now()]);
header('Content-Length: ' . strlen($out));
echo $out;
