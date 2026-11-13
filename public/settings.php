<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\Auth;

Auth::requireAdmin();

$formats = ['d/m/Y' => '31/12/2025', 'd-m-Y' => '31-12-2025', 'd M Y' => '31 Dec 2025', 'd F Y' => '31 December 2025', 'Y-m-d' => '2025-12-31', 'm/d/Y' => '12/31/2025'];

if (is_post()) {
    csrf_verify();
    foreach (['org_name' => 150, 'org_address' => 250, 'org_contact' => 250, 'place' => 100, 'watermark' => 30] as $k => $max) {
        set_setting($k, mb_substr(trim((string) input($k)), 0, $max));
    }
    $fmt = (string) input('date_format');
    set_setting('date_format', isset($formats[$fmt]) ? $fmt : 'd/m/Y');

    if (input('remove_logo') === '1') {
        $old = setting('logo_file');
        if ($old) {
            @unlink(ROOT . '/storage/uploads/' . basename($old));
        }
        set_setting('logo_file', '');
    }
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['logo'];
        $info = @getimagesize($f['tmp_name']);
        $ext = $info ? [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2]] ?? null : null;
        if (!$ext || $f['size'] > 2 * 1024 * 1024) {
            flash('danger', 'The logo must be a PNG or JPG under 2 MB. Other settings were saved.');
        } else {
            $old = setting('logo_file');
            $name = 'logo-' . bin2hex(random_bytes(4)) . '.' . $ext;
            move_uploaded_file($f['tmp_name'], ROOT . '/storage/uploads/' . $name);
            if ($old) {
                @unlink(ROOT . '/storage/uploads/' . basename($old));
            }
            set_setting('logo_file', $name);
        }
    } elseif (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] !== UPLOAD_ERR_NO_FILE) {
        flash('danger', 'The logo could not be uploaded (error ' . (int) $_FILES['logo']['error'] . ').');
    }
    flash('success', 'Settings saved.');
    redirect('settings.php');
}

layout_top('Settings', 'settings');
?>
<div class="page-title"><div><h1>Settings</h1><p>Used in the header and footer of every certificate.</p></div></div>
<form method="post" enctype="multipart/form-data" class="panel" style="max-width:720px">
  <?= csrf_field() ?>
  <div class="panel-body">
    <div class="mb-3"><label class="form-label">Institution / organisation name</label>
      <input class="form-control" name="org_name" value="<?= e(setting('org_name')) ?>"></div>
    <div class="mb-3"><label class="form-label">Address line</label>
      <input class="form-control" name="org_address" value="<?= e(setting('org_address')) ?>"></div>
    <div class="mb-3"><label class="form-label">Contact line</label>
      <input class="form-control" name="org_contact" value="<?= e(setting('org_contact')) ?>" placeholder="Phone, email, website">
      <div class="hint">Shown under the address. Leave blank to hide.</div></div>
    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="form-label">Place of issue</label>
        <input class="form-control" name="place" value="<?= e(setting('place')) ?>"><div class="hint">Printed next to the date, and available as <code class="ph">{{place}}</code>.</div></div>
      <div class="col-md-6"><label class="form-label">Date format</label>
        <select class="form-select" name="date_format">
<?php foreach ($formats as $f => $example): ?>
          <option value="<?= e($f) ?>" <?= setting('date_format', 'd/m/Y') === $f ? 'selected' : '' ?>><?= e($example) ?></option>
<?php endforeach; ?>
        </select></div>
    </div>
    <div class="mb-3"><label class="form-label">Watermark text (optional)</label>
      <input class="form-control" name="watermark" maxlength="30" value="<?= e(setting('watermark')) ?>" placeholder="e.g. DRAFT or SAMPLE">
      <div class="hint">Printed faintly across every page. Handy while testing.</div></div>
    <div class="mb-1"><label class="form-label">Logo (PNG or JPG, max 2 MB)</label>
<?php if (setting('logo_file')): ?>
      <div class="mb-2"><img src="media.php" alt="Current logo" style="max-height:70px" class="border rounded p-1 bg-white">
        <label class="ms-3"><input type="checkbox" name="remove_logo" value="1"> Remove logo</label></div>
<?php endif; ?>
      <input class="form-control" type="file" name="logo" accept="image/png,image/jpeg"></div>
  </div>
  <div class="panel-body border-top"><button class="btn btn-primary">Save settings</button></div>
</form>
<?php layout_bottom();
