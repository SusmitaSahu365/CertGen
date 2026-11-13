<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Db};

Auth::require();

$nTemplates = (int) Db::val('SELECT COUNT(*) FROM templates');
$nBatches = (int) Db::val('SELECT COUNT(*) FROM batches');
$nRecords = (int) Db::val('SELECT COUNT(*) FROM records');
$nIssued = (int) Db::val('SELECT COALESCE(SUM(record_count), 0) FROM generation_log');
$recent = Db::all('SELECT b.id, b.name, b.created_at, t.name AS tname,
        (SELECT COUNT(*) FROM records r WHERE r.batch_id = b.id) AS n
    FROM batches b JOIN templates t ON t.id = b.template_id ORDER BY b.id DESC LIMIT 5');
$log = Db::all('SELECT * FROM generation_log ORDER BY id DESC LIMIT 6');

layout_top('Dashboard', 'index');
?>
<div class="page-title">
  <div><h1>Dashboard</h1>
  <p><?= e(setting('org_name', 'Set your institution name under Settings')) ?></p></div>
</div>

<div class="rail mb-4">
  <a class="rail-step" href="templates.php">
    <span class="n">Step 1</span><h3>Choose a template</h3>
    <p>Pick Leaving or Bonafide, or design your own fields and layout.</p>
    <div class="count"><?= $nTemplates ?> template<?= $nTemplates === 1 ? '' : 's' ?></div>
  </a>
  <a class="rail-step" href="batch_new.php">
    <span class="n">Step 2</span><h3>Upload a sheet</h3>
    <p>Excel or CSV. Columns are matched to template fields for you.</p>
    <div class="count"><?= $nBatches ?> batch<?= $nBatches === 1 ? '' : 'es' ?></div>
  </a>
  <a class="rail-step" href="batches.php">
    <span class="n">Step 3</span><h3>Review records</h3>
    <p>Search, correct, add or remove rows before anything is printed.</p>
    <div class="count"><?= $nRecords ?> record<?= $nRecords === 1 ? '' : 's' ?></div>
  </a>
  <a class="rail-step" href="batches.php">
    <span class="n">Step 4</span><h3>Generate PDFs</h3>
    <p>One combined PDF, or a ZIP with a separate file per person.</p>
    <div class="count"><?= $nIssued ?> certificate<?= $nIssued === 1 ? '' : 's' ?> issued</div>
  </a>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><h2>Recent batches</h2><a class="btn btn-sm btn-primary" href="batch_new.php">New batch</a></div>
<?php if (!$recent): ?>
      <div class="empty">No batches yet. Upload your first spreadsheet to get started.</div>
<?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>Batch</th><th>Template</th><th>Records</th><th>Created</th></tr></thead>
        <tbody>
<?php foreach ($recent as $b): ?>
          <tr><td><a href="batch.php?id=<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a></td>
            <td><?= e($b['tname']) ?></td><td><?= (int) $b['n'] ?></td>
            <td class="muted"><?= e(fmt_date($b['created_at'], 'd M Y')) ?></td></tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><h2>Issue log</h2></div>
<?php if (!$log): ?>
      <div class="empty">Nothing generated yet.</div>
<?php else: ?>
      <div class="table-responsive"><table class="table">
        <thead><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
<?php foreach ($log as $l): ?>
          <tr><td class="muted"><?= e(date('d M, H:i', strtotime($l['created_at']))) ?></td>
            <td><?= e($l['user_name']) ?></td>
            <td><?= (int) $l['record_count'] ?> × <?= e($l['template_name']) ?></td></tr>
<?php endforeach; ?>
        </tbody></table></div>
<?php endif; ?>
    </div>
  </div>
</div>
<?php layout_bottom();
