<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Db};

Auth::require();

if (is_post() && input('action') === 'delete') {
    csrf_verify();
    $id = (int) input('id');
    $b = Db::one('SELECT name FROM batches WHERE id = ?', [$id]);
    if ($b) {
        Db::run('DELETE FROM batches WHERE id = ?', [$id]);   // records are removed by ON DELETE CASCADE
        flash('success', 'Batch "' . $b['name'] . '" and its records were deleted.');
    }
    redirect('batches.php');
}

$batches = Db::all('SELECT b.id, b.name, b.created_at, t.name AS tname, u.username,
        (SELECT COUNT(*) FROM records r WHERE r.batch_id = b.id) AS n
    FROM batches b JOIN templates t ON t.id = b.template_id
    LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC');

layout_top('Batches', 'batches');
?>
<div class="page-title">
  <div><h1>Batches</h1><p>A batch is one uploaded spreadsheet, for example “Class of 2026 leaving certificates”.</p></div>
  <a class="btn btn-primary" href="batch_new.php">New batch</a>
</div>
<div class="panel">
<?php if (!$batches): ?>
  <div class="empty">No batches yet.<br><a class="btn btn-primary mt-3" href="batch_new.php">Upload a spreadsheet</a></div>
<?php else: ?>
  <div class="table-responsive"><table class="table">
    <thead><tr><th>Batch</th><th>Template</th><th>Records</th><th>Created</th><th>By</th><th></th></tr></thead><tbody>
<?php foreach ($batches as $b): ?>
    <tr>
      <td><a href="batch.php?id=<?= (int) $b['id'] ?>"><?= e($b['name']) ?></a></td>
      <td><?= e($b['tname']) ?></td><td><?= (int) $b['n'] ?></td>
      <td class="muted"><?= e(fmt_date($b['created_at'], 'd M Y, H:i')) ?></td>
      <td class="muted"><?= e($b['username'] ?? '-') ?></td>
      <td class="text-end">
        <a class="btn btn-sm btn-outline-primary" href="batch.php?id=<?= (int) $b['id'] ?>">Open</a>
        <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
          <button class="btn btn-sm btn-outline-danger" name="action" value="delete" data-confirm="Delete this batch and all <?= (int) $b['n'] ?> records in it? This cannot be undone.">Delete</button></form>
      </td>
    </tr>
<?php endforeach; ?>
    </tbody></table></div>
<?php endif; ?>
</div>
<?php layout_bottom();
