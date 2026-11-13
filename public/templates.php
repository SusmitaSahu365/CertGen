<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db, Presets};

$user = Auth::require();
$isAdmin = $user['role'] === 'admin';

if (is_post()) {
    Auth::requireAdmin();
    csrf_verify();
    $action = (string) input('action');
    if ($action === 'delete') {
        $id = (int) input('id');
        try {
            CertTemplate::delete($id);
            flash('success', 'Template deleted.');
        } catch (PDOException $e) {
            flash('danger', 'This template still has batches using it. Delete those batches first.');
        }
    } elseif ($action === 'preset') {
        $t = Presets::install((string) input('slug'));
        flash($t ? 'success' : 'danger', $t ? "Added \"{$t->name}\"." : 'Unknown preset.');
    } elseif ($action === 'duplicate') {
        $src = CertTemplate::find((int) input('id'));
        if ($src) {
            $src->id = null;
            $src->name = mb_substr($src->name, 0, 140) . ' (copy)';
            $src->save();
            flash('success', 'Template duplicated. Open it to make changes.');
        }
    }
    redirect('templates.php');
}

$templates = CertTemplate::all();
$counts = [];
foreach (Db::all('SELECT template_id, COUNT(*) AS n FROM batches GROUP BY template_id') as $r) {
    $counts[(int) $r['template_id']] = (int) $r['n'];
}
$have = array_map(fn ($t) => $t->name, $templates);
$missing = array_filter(Presets::all(), fn ($p) => !in_array($p['name'], $have, true));

layout_top('Templates', 'templates');
?>
<div class="page-title">
  <div><h1>Templates</h1><p>A template defines the data fields and the printed layout of one kind of certificate.</p></div>
<?php if ($isAdmin): ?><a class="btn btn-primary" href="template_edit.php">New template</a><?php endif; ?>
</div>

<?php if (!$templates): ?>
  <div class="panel"><div class="empty">No templates yet.<?= $isAdmin ? ' Add a preset below, or create your own.' : ' Ask an administrator to add one.' ?></div></div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($templates as $t): ?>
  <div class="col-md-6 col-xl-4"><div class="panel h-100 d-flex flex-column">
    <div class="panel-body flex-grow-1">
      <h2 class="mb-1"><?= e($t->name) ?></h2>
      <p class="muted mb-2"><?= e($t->description ?: 'No description.') ?></p>
      <div class="hint"><?= count($t->fields) ?> fields · <?= (int) ($counts[$t->id] ?? 0) ?> batch<?= ($counts[$t->id] ?? 0) === 1 ? '' : 'es' ?></div>
    </div>
    <div class="panel-body border-top d-flex flex-wrap gap-2">
      <a class="btn btn-sm btn-primary" href="batch_new.php?template=<?= (int) $t->id ?>">Use</a>
      <a class="btn btn-sm btn-outline-secondary" href="template_sample.php?id=<?= (int) $t->id ?>&format=xlsx">Sample sheet</a>
<?php if ($isAdmin): ?>
      <a class="btn btn-sm btn-outline-secondary" href="template_edit.php?id=<?= (int) $t->id ?>">Edit</a>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $t->id ?>">
        <button class="btn btn-sm btn-outline-secondary" name="action" value="duplicate">Duplicate</button>
        <button class="btn btn-sm btn-outline-danger" name="action" value="delete" data-confirm="Delete the template &quot;<?= e($t->name) ?>&quot;?">Delete</button></form>
<?php endif; ?>
    </div>
  </div></div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isAdmin && $missing): ?>
<div class="panel mt-4"><div class="panel-head"><h3>Built-in presets</h3></div>
  <div class="panel-body d-flex flex-wrap gap-3">
<?php foreach ($missing as $slug => $p): ?>
    <form method="post" class="d-flex align-items-center gap-3"><?= csrf_field() ?><input type="hidden" name="slug" value="<?= e($slug) ?>">
      <div><strong><?= e($p['name']) ?></strong><div class="hint"><?= e($p['description']) ?></div></div>
      <button class="btn btn-sm btn-outline-primary" name="action" value="preset">Add</button></form>
<?php endforeach; ?>
  </div></div>
<?php endif; ?>
<?php layout_bottom();
