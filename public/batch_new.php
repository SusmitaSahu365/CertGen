<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate};

Auth::require();

$templates = CertTemplate::all();
if (!$templates) {
    flash('warning', 'Create or add a template before uploading data.');
    redirect('templates.php');
}

// tidy abandoned uploads
foreach (glob(ROOT . '/storage/tmp/*.{csv,xlsx,xls}', GLOB_BRACE) ?: [] as $old) {
    if (filemtime($old) < time() - 7200) {
        @unlink($old);
    }
}

$selected = (int) input('template');
if (is_post()) {
    csrf_verify();
    $tpl = CertTemplate::find((int) input('template'));
    $name = trim((string) input('name'));
    $f = $_FILES['sheet'] ?? null;
    $maxBytes = (int) config('max_upload_mb', 5) * 1024 * 1024;
    $ext = $f ? strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) : '';

    if (!$tpl) {
        flash('danger', 'Choose a template.');
    } elseif ($name === '') {
        flash('danger', 'Give the batch a name.');
    } elseif (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        flash('danger', 'Choose a spreadsheet to upload.');
    } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > $maxBytes) {
        flash('danger', 'That file is too large (limit ' . config('max_upload_mb', 5) . ' MB).');
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        flash('danger', 'Upload failed (error code ' . (int) $f['error'] . '). Please try again.');
    } elseif (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
        flash('danger', 'Upload an Excel (.xlsx, .xls) or CSV file.');
    } else {
        $token = bin2hex(random_bytes(16));
        move_uploaded_file($f['tmp_name'], ROOT . "/storage/tmp/$token.$ext");
        $_SESSION['imports'][$token] = ['template' => $tpl->id, 'name' => mb_substr($name, 0, 200), 'orig' => basename($f['name']), 'ext' => $ext];
        redirect('import.php?t=' . $token);
    }
    $selected = (int) input('template');
}

layout_top('New batch', 'batches');
?>
<div class="page-title"><div><h1>New batch</h1><p>Upload a spreadsheet. You will match its columns to the template on the next screen before anything is saved.</p></div></div>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" enctype="multipart/form-data" class="panel">
      <?= csrf_field() ?>
      <div class="panel-body">
        <div class="mb-3"><label class="form-label" for="tplSelect">Template</label>
          <select class="form-select" name="template" id="tplSelect" required>
            <option value="">Choose…</option>
<?php foreach ($templates as $t): ?><option value="<?= (int) $t->id ?>" <?= $selected === $t->id ? 'selected' : '' ?>><?= e($t->name) ?></option><?php endforeach; ?>
          </select></div>
        <div class="mb-3"><label class="form-label" for="name">Batch name</label>
          <input class="form-control" id="name" name="name" required maxlength="200" value="<?= e(input('name')) ?>" placeholder="e.g. Leaving certificates, Class of 2026"></div>
        <div class="mb-1"><label class="form-label" for="sheet">Spreadsheet</label>
          <input class="form-control" type="file" id="sheet" name="sheet" accept=".xlsx,.xls,.csv" required>
          <div class="hint">Excel or CSV. The first row must be column headings. Up to <?= (int) config('max_import_rows', 5000) ?> rows, <?= (int) config('max_upload_mb', 5) ?> MB.</div></div>
      </div>
      <div class="panel-body border-top"><button class="btn btn-primary">Upload and continue</button></div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="panel"><div class="panel-head"><h3>Not sure how to lay out the sheet?</h3></div>
      <div class="panel-body">
        <p class="mb-2">Download a sheet whose headings already match the template you chose:</p>
        <a class="btn btn-sm btn-outline-secondary sample-link" data-format="xlsx" href="#">Excel sample</a>
        <a class="btn btn-sm btn-outline-secondary sample-link" data-format="csv" href="#">CSV sample</a>
        <hr>
        <ul class="hint ps-3 mb-0">
          <li>Dates: write them as 31/12/2005 (day first) or use real Excel dates.</li>
          <li>Keep numbers like enrollment numbers as plain text or numbers; leading zeros are kept if the cell is text.</li>
          <li>Column order does not matter, and extra columns can be ignored.</li>
        </ul>
      </div></div>
  </div>
</div>
<?php layout_bottom();
