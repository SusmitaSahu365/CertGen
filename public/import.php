<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db, Importer};

$user = Auth::require();

$token = (string) input('t');
$job = preg_match('/^[a-f0-9]{32}$/', $token) ? ($_SESSION['imports'][$token] ?? null) : null;
$path = $job ? ROOT . "/storage/tmp/$token.{$job['ext']}" : '';
$tpl = $job ? CertTemplate::find((int) $job['template']) : null;
if (!$job || !$tpl || !is_file($path)) {
    flash('danger', 'That upload has expired. Please upload the file again.');
    redirect('batch_new.php');
}

$max = (int) config('max_import_rows', 5000);
try {
    $data = Importer::read($path, $job['ext'], $max);
} catch (Throwable $e) {
    @unlink($path);
    unset($_SESSION['imports'][$token]);
    flash('danger', 'Could not read the file: ' . $e->getMessage());
    redirect('batch_new.php');
}
$headers = $data['headers'];
$rows = $data['rows'];
$truncated = count($rows) > $max;
if ($truncated) {
    $rows = array_slice($rows, 0, $max);
}

$map = Importer::autoMap($headers, $tpl->fields);
$problems = [];

if (is_post()) {
    csrf_verify();
    $map = [];
    $used = [];
    foreach ($headers as $i => $h) {
        $key = (string) ($_POST['map'][$i] ?? '');
        $key = $key !== '' && in_array($key, array_column($tpl->fields, 'key'), true) ? $key : null;
        if ($key !== null && isset($used[$key])) {
            $problems[] = "Two columns are mapped to the same field (\"$key\"). Map each field only once.";
        }
        $used[$key ?? ''] = true;
        $map[$i] = $key;
    }
    foreach ($tpl->fields as $f) {
        if (!empty($f['required']) && !in_array($f['key'], $map, true)) {
            $problems[] = "The required field \"{$f['label']}\" is not mapped to any column.";
        }
    }
    if (!$problems) {
        $res = Importer::buildRecords($rows, $map, $tpl->fields);
        if (!$res['records']) {
            $problems[] = 'No valid rows were found, so nothing was imported.';
            $problems = array_merge($problems, array_slice($res['errors'], 0, 10));
        } else {
            $batchId = Db::tx(function () use ($job, $tpl, $user, $res) {
                $id = Db::insert('INSERT INTO batches (template_id, name, created_by, created_at) VALUES (?,?,?,?)',
                    [$tpl->id, $job['name'], $user['id'], now()]);
                $st = Db::pdo()->prepare('INSERT INTO records (batch_id, seq, data_json, created_at, updated_at) VALUES (?,?,?,?,?)');
                foreach ($res['records'] as $i => $rec) {
                    $st->execute([$id, $i + 1, json_encode($rec, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), now(), now()]);
                }
                return $id;
            });
            @unlink($path);
            unset($_SESSION['imports'][$token]);
            $warnings = $res['warnings'];
            if ($truncated) {
                $warnings[] = "Only the first $max rows were imported.";
            }
            $_SESSION['import_report'][$batchId] = ['errors' => $res['errors'], 'warnings' => $warnings];
            flash('success', count($res['records']) . ' record(s) imported' . ($res['errors'] ? ', ' . count($res['errors']) . ' row(s) skipped.' : '.'));
            redirect('batch.php?id=' . $batchId);
        }
    }
}

layout_top('Match columns', 'batches');
?>
<div class="page-title">
  <div><h1>Match columns</h1>
  <p><strong><?= e($job['orig']) ?></strong> → <?= e($tpl->name) ?> · <?= count($rows) ?> data row<?= count($rows) === 1 ? '' : 's' ?></p></div>
</div>
<?php if ($truncated): ?><div class="alert alert-warning">This file has more than <?= $max ?> rows. Only the first <?= $max ?> will be imported.</div><?php endif; ?>
<?php if ($problems): ?><div class="alert alert-danger"><ul class="mb-0"><li><?= implode('</li><li>', array_map('e', $problems)) ?></li></ul></div><?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <div class="panel mb-4">
    <div class="panel-head"><h2>Which column is which?</h2>
      <span class="hint">We matched what we could by name. Check each row, and change anything that is wrong.</span></div>
    <div class="table-responsive"><table class="table">
      <thead><tr><th>Column in your file</th><th>Example value</th><th>Goes into template field</th></tr></thead><tbody>
<?php foreach ($headers as $i => $h): ?>
      <tr>
        <td><strong><?= e($h !== '' ? $h : '(column ' . ($i + 1) . ', no heading)') ?></strong></td>
        <td class="muted"><?= e(mb_strimwidth((string) ($rows[0][$i] ?? ''), 0, 40, '…')) ?></td>
        <td style="min-width:260px"><select class="form-select" name="map[<?= $i ?>]">
          <option value="">Ignore this column</option>
<?php foreach ($tpl->fields as $f): ?>
          <option value="<?= e($f['key']) ?>" <?= ($map[$i] ?? null) === $f['key'] ? 'selected' : '' ?>><?= e($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?><?= $f['type'] === 'date' ? ' (date)' : '' ?></option>
<?php endforeach; ?>
        </select></td>
      </tr>
<?php endforeach; ?>
      </tbody></table></div>
    <div class="panel-body border-top hint">* required. Rows missing a required value, or with a date that cannot be read, are skipped and listed afterwards. Nothing is saved until you press Import.</div>
  </div>

  <div class="panel mb-4">
    <div class="panel-head"><h3>First rows of your file</h3></div>
    <div class="table-responsive"><table class="table">
      <thead><tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach (array_slice($rows, 0, 5) as $r): ?><tr><?php foreach ($headers as $i => $h): ?><td><?= e(mb_strimwidth((string) ($r[$i] ?? ''), 0, 40, '…')) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table></div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary">Import <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?></button>
    <a class="btn btn-outline-secondary" href="batch_new.php">Cancel</a>
  </div>
</form>
<?php layout_bottom();
