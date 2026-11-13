<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db, Importer};

Auth::require();

$batchId = (int) input('batch');
$batch = Db::one('SELECT * FROM batches WHERE id = ?', [$batchId]);
$tpl = $batch ? CertTemplate::find((int) $batch['template_id']) : null;
if (!$batch || !$tpl) {
    flash('danger', 'Batch not found.');
    redirect('batches.php');
}
$id = (int) input('id');
$rec = $id ? Db::one('SELECT * FROM records WHERE id = ? AND batch_id = ?', [$id, $batchId]) : null;
if ($id && !$rec) {
    flash('danger', 'Record not found.');
    redirect('batch.php?id=' . $batchId);
}
$data = $rec ? (json_decode($rec['data_json'], true) ?: []) : [];

$errors = [];
if (is_post()) {
    csrf_verify();
    $new = [];
    foreach ($tpl->fields as $f) {
        $v = trim((string) ($_POST['f'][$f['key']] ?? ''));
        if ($v !== '' && $f['type'] === 'date') {
            $iso = Importer::parseDate($v);
            if ($iso === null) {
                $errors[] = "\"{$f['label']}\" is not a valid date.";
            }
            $v = $iso ?? $v;
        } elseif ($v !== '' && $f['type'] === 'number' && !is_numeric(str_replace(',', '', $v))) {
            $errors[] = "\"{$f['label']}\" should be a number.";
        }
        if ($v === '' && !empty($f['required'])) {
            $errors[] = "\"{$f['label']}\" is required.";
        }
        $new[$f['key']] = mb_substr($v, 0, 500);
    }
    $data = $new;
    if (!$errors) {
        $json = json_encode($new, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rec) {
            Db::run('UPDATE records SET data_json = ?, updated_at = ? WHERE id = ?', [$json, now(), $id]);
        } else {
            $seq = (int) Db::val('SELECT COALESCE(MAX(seq), 0) FROM records WHERE batch_id = ?', [$batchId]) + 1;
            Db::insert('INSERT INTO records (batch_id, seq, data_json, created_at, updated_at) VALUES (?,?,?,?,?)', [$batchId, $seq, $json, now(), now()]);
        }
        flash('success', $rec ? 'Record updated.' : 'Record added.');
        redirect('batch.php?id=' . $batchId);
    }
}

layout_top($rec ? 'Edit record' : 'Add record', 'batches');
?>
<div class="page-title"><div><h1><?= $rec ? 'Edit record #' . (int) $rec['seq'] : 'Add a record' ?></h1><p><?= e($batch['name']) ?> · <?= e($tpl->name) ?></p></div></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><li><?= implode('</li><li>', array_map('e', $errors)) ?></li></ul></div><?php endif; ?>
<form method="post" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="batch" value="<?= $batchId ?>"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="panel-body row g-3">
<?php foreach ($tpl->fields as $f):
    $v = $data[$f['key']] ?? '';
    $isDate = $f['type'] === 'date' && ($v === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)); ?>
    <div class="col-md-6">
      <label class="form-label" for="f_<?= e($f['key']) ?>"><?= e($f['label']) ?><?= !empty($f['required']) ? ' <span class="text-danger">*</span>' : '' ?></label>
      <input class="form-control" id="f_<?= e($f['key']) ?>" name="f[<?= e($f['key']) ?>]" value="<?= e($v) ?>"
        type="<?= $isDate ? 'date' : 'text' ?>" <?= $f['type'] === 'number' ? 'inputmode="decimal"' : '' ?> <?= !empty($f['required']) ? 'required' : '' ?>>
    </div>
<?php endforeach; ?>
  </div>
  <div class="panel-body border-top d-flex gap-2">
    <button class="btn btn-primary">Save record</button>
    <a class="btn btn-outline-secondary" href="batch.php?id=<?= $batchId ?>">Cancel</a>
  </div>
</form>
<?php layout_bottom();
