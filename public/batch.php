<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db};

Auth::require();

$id = (int) input('id');
$batch = Db::one('SELECT * FROM batches WHERE id = ?', [$id]);
$tpl = $batch ? CertTemplate::find((int) $batch['template_id']) : null;
if (!$batch || !$tpl) {
    flash('danger', 'Batch not found.');
    redirect('batches.php');
}

// ---- bulk delete -----------------------------------------------------------
if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    if (!$ids) {
        flash('warning', 'Select at least one record first.');
    } else {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $n = Db::run("DELETE FROM records WHERE batch_id = ? AND id IN ($in)", array_merge([$id], $ids));
        flash('success', "$n record(s) deleted.");
    }
    redirect('batch.php?id=' . $id . '&q=' . urlencode((string) input('q')));
}

// ---- search + pagination ---------------------------------------------------
$q = trim((string) input('q'));
$where = 'batch_id = ?';
$params = [$id];
if ($q !== '') {
    $where .= " AND data_json LIKE ? ESCAPE '!'";
    $params[] = '%' . strtr($q, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
}
$total = (int) Db::val("SELECT COUNT(*) FROM records WHERE $where", $params);
$perPage = 50;
$pages = max(1, (int) ceil($total / $perPage));
$page = min($pages, max(1, (int) input('page', 1)));
$offset = ($page - 1) * $perPage;
$rows = Db::all("SELECT id, seq, data_json FROM records WHERE $where ORDER BY seq LIMIT $perPage OFFSET $offset", $params);
$allCount = (int) Db::val('SELECT COUNT(*) FROM records WHERE batch_id = ?', [$id]);

$shown = array_slice($tpl->fields, 0, 8);
$hidden = count($tpl->fields) - count($shown);
$report = $_SESSION['import_report'][$id] ?? null;
unset($_SESSION['import_report'][$id]);

$qs = fn (array $extra = []) => http_build_query(array_filter(['id' => $id, 'q' => $q] + $extra, fn ($v) => $v !== '' && $v !== null));

layout_top($batch['name'], 'batches');
?>
<div class="page-title">
  <div><h1><?= e($batch['name']) ?></h1>
  <p><?= e($tpl->name) ?> · <?= $allCount ?> record<?= $allCount === 1 ? '' : 's' ?> · created <?= e(fmt_date($batch['created_at'], 'd M Y')) ?></p></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="record_edit.php?batch=<?= $id ?>">Add a record</a>
    <a class="btn btn-outline-secondary" href="batches.php">All batches</a></div>
</div>

<?php if ($report && ($report['errors'] || $report['warnings'])): ?>
<div class="alert alert-warning">
<?php foreach ($report['warnings'] as $w): ?><div><?= e($w) ?></div><?php endforeach; ?>
<?php if ($report['errors']): ?>
  <details class="mt-1"><summary><strong><?= count($report['errors']) ?> row(s) were skipped. Show details</strong></summary>
    <ul class="mb-0 mt-2"><?php foreach (array_slice($report['errors'], 0, 50) as $er): ?><li><?= e($er) ?></li><?php endforeach; ?>
    <?php if (count($report['errors']) > 50): ?><li>…and <?= count($report['errors']) - 50 ?> more.</li><?php endif; ?></ul></details>
<?php endif; ?>
</div>
<?php endif; ?>

<form method="get" class="d-flex gap-2 mb-3" role="search">
  <input type="hidden" name="id" value="<?= $id ?>">
  <input class="form-control" style="max-width:380px" name="q" value="<?= e($q) ?>" placeholder="Search any field…" aria-label="Search records">
  <button class="btn btn-outline-secondary">Search</button>
<?php if ($q !== ''): ?><a class="btn btn-link" href="batch.php?id=<?= $id ?>">Clear</a><?php endif; ?>
</form>

<form method="post" action="batch.php?id=<?= $id ?>" id="records-form" class="panel">
  <?= csrf_field() ?>
  <input type="hidden" name="batch" value="<?= $id ?>">
  <input type="hidden" name="q" value="<?= e($q) ?>">
<?php if (!$rows): ?>
  <div class="empty"><?= $q !== '' ? 'No records match your search.' : 'This batch has no records.' ?></div>
<?php else: ?>
  <div class="table-responsive"><table class="table records">
    <thead><tr>
      <th style="width:36px"><input type="checkbox" class="form-check-input js-select-all" aria-label="Select all on this page"></th>
      <th>#</th>
<?php foreach ($shown as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?>
<?php if ($hidden > 0): ?><th class="muted">+<?= $hidden ?> more</th><?php endif; ?>
      <th></th>
    </tr></thead><tbody>
<?php foreach ($rows as $r): $d = json_decode($r['data_json'], true) ?: []; ?>
    <tr>
      <td><input type="checkbox" class="form-check-input js-row-check" name="ids[]" value="<?= (int) $r['id'] ?>" aria-label="Select record <?= (int) $r['seq'] ?>"></td>
      <td class="muted"><?= (int) $r['seq'] ?></td>
<?php foreach ($shown as $f): ?><td class="cell" title="<?= e($d[$f['key']] ?? '') ?>"><?= e($f['type'] === 'date' ? fmt_date($d[$f['key']] ?? '') : ($d[$f['key']] ?? '')) ?></td><?php endforeach; ?>
<?php if ($hidden > 0): ?><td></td><?php endif; ?>
      <td class="text-end"><a href="record_edit.php?batch=<?= $id ?>&id=<?= (int) $r['id'] ?>">Edit</a></td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>

<?php if ($pages > 1): ?>
  <div class="panel-body border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="hint">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> records<?= $q !== '' ? ' match' : '' ?>)</span>
    <div class="btn-group">
      <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="batch.php?<?= e($qs(['page' => $page - 1])) ?>">Previous</a>
      <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="batch.php?<?= e($qs(['page' => $page + 1])) ?>">Next</a>
    </div>
  </div>
<?php endif; ?>

  <div class="sticky-bar">
    <span class="hint" id="selCount">None selected</span>
    <button class="btn btn-sm btn-outline-danger needs-selection" name="action" value="delete" data-confirm="Delete the selected records?" disabled>Delete selected</button>
  </div>
<?php endif; ?>
</form>

<?php if ($rows): ?>
<form method="post" action="generate.php" class="panel mt-4" id="generate-form">
  <?= csrf_field() ?>
  <input type="hidden" name="batch" value="<?= $id ?>">
  <input type="hidden" name="q" value="<?= e($q) ?>">
  <div class="panel-head"><h2>Generate certificates</h2><span class="hint">Tick records above to use “Selected”, otherwise all matching records are used.</span></div>
  <div class="panel-body row g-3 align-items-end">
    <div class="col-md-3"><label class="form-label">Issue date</label><input class="form-control" type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-3"><label class="form-label">First serial no.</label><input class="form-control" type="number" min="0" name="serial_start" value="1" required>
      <div class="hint"><code class="ph">{{serial_no}}</code> counts up from here.</div></div>
    <div class="col-md-6">
      <label class="form-label d-block">Records</label>
      <label class="me-3"><input type="radio" name="scope" value="selected" id="scopeSelected" disabled> Selected (<span id="selN">0</span>)</label>
      <label><input type="radio" name="scope" value="all" id="scopeAll" checked> All <?= $total ?> <?= $q !== '' ? 'matching' : '' ?></label>
    </div>
  </div>
  <div class="panel-body border-top d-flex flex-wrap gap-2">
    <button class="btn btn-primary" name="mode" value="view" formtarget="_blank">Open PDF</button>
    <button class="btn btn-outline-primary" name="mode" value="download">Download PDF</button>
    <button class="btn btn-outline-primary" name="mode" value="zip">Download ZIP (one PDF per person)</button>
  </div>
</form>
<?php endif; ?>
<?php layout_bottom();
