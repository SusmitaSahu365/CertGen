<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, Db};

Auth::requireAdmin();

$id = (int) input('id');
$existing = $id ? CertTemplate::find($id) : null;
if ($id && !$existing) {
    flash('danger', 'Template not found.');
    redirect('templates.php');
}

$errors = [];
$warnings = [];
if (is_post()) {
    csrf_verify();
    [$t, $errors, $warnings] = CertTemplate::fromPost($_POST, $existing);
    if (!$errors) {
        $t->save();
        flash($warnings ? 'warning' : 'success', 'Template saved.' . ($warnings ? ' ' . implode(' ', $warnings) : ''));
        redirect('template_edit.php?id=' . $t->id);
    }
} else {
    $t = $existing ?: new CertTemplate();
    if (!$existing) {
        $t->options = CertTemplate::defaultOptions();
        $t->fields = [['key' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true]];
        $t->blocks = [
            ['type' => 'heading', 'a' => 'CERTIFICATE', 'b' => '', 'align' => 'C', 'size' => 20, 'bold' => true],
            ['type' => 'text', 'a' => 'This is to certify that **{{full_name}}** ...', 'b' => '', 'align' => 'L', 'size' => 0, 'bold' => false],
        ];
    }
}

$inUse = $t->id ? (int) Db::val('SELECT COUNT(*) FROM records r JOIN batches b ON b.id = r.batch_id WHERE b.template_id = ?', [$t->id]) : 0;

function field_row(array $f): string
{
    ob_start(); ?>
<div class="editor-row fields-row">
  <input class="form-control f-label" name="f_label[]" value="<?= e($f['label']) ?>" placeholder="Label, e.g. Full Name" aria-label="Field label">
  <input class="form-control f-key" name="f_key[]" value="<?= e($f['key']) ?>" data-auto="<?= $f['key'] !== '' ? '0' : '1' ?>" placeholder="key" aria-label="Field key">
  <select class="form-select" name="f_type[]" aria-label="Type">
<?php foreach (CertTemplate::FIELD_TYPES as $ty): ?><option value="<?= $ty ?>" <?= $f['type'] === $ty ? 'selected' : '' ?>><?= ucfirst($ty) ?></option><?php endforeach; ?>
  </select>
  <select class="form-select" name="f_req[]" aria-label="Required"><option value="0">Optional</option><option value="1" <?= !empty($f['required']) ? 'selected' : '' ?>>Required</option></select>
  <div class="row-tools"><button type="button" class="btn btn-outline-secondary" data-move="up" title="Move up">↑</button><button type="button" class="btn btn-outline-secondary" data-move="down" title="Move down">↓</button><button type="button" class="btn btn-outline-danger" data-remove title="Remove">×</button></div>
</div>
<?php return ob_get_clean();
}

function block_row(array $b): string
{
    ob_start(); ?>
<div class="editor-row blocks-row" data-type="<?= e($b['type']) ?>">
  <select class="form-select b-type" name="b_type[]" aria-label="Block type">
<?php foreach (['heading' => 'Heading', 'text' => 'Paragraph', 'kv' => 'Label : value', 'row' => 'Two columns', 'spacer' => 'Space', 'rule' => 'Line'] as $k => $lbl): ?><option value="<?= $k ?>" <?= $b['type'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
  <input class="form-control ph-target b-a" name="b_a[]" value="<?= e($b['a']) ?>" placeholder="Text, label or left column" aria-label="Main text">
  <input class="form-control ph-target b-b" name="b_b[]" value="<?= e($b['b']) ?>" placeholder="Value or right column" aria-label="Second text">
  <select class="form-select" name="b_align[]" aria-label="Alignment">
<?php foreach (['L' => 'Left', 'C' => 'Centre', 'R' => 'Right', 'J' => 'Justify'] as $k => $lbl): ?><option value="<?= $k ?>" <?= $b['align'] === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
  </select>
  <input class="form-control" type="number" min="0" max="60" name="b_size[]" value="<?= (int) $b['size'] ?: '' ?>" placeholder="Size" aria-label="Font size or space height">
  <select class="form-select" name="b_bold[]" aria-label="Bold"><option value="0">Normal</option><option value="1" <?= !empty($b['bold']) ? 'selected' : '' ?>>Bold</option></select>
  <div class="row-tools"><button type="button" class="btn btn-outline-secondary" data-move="up" title="Move up">↑</button><button type="button" class="btn btn-outline-secondary" data-move="down" title="Move down">↓</button><button type="button" class="btn btn-outline-danger" data-remove title="Remove">×</button></div>
</div>
<?php return ob_get_clean();
}

$blank = ['key' => '', 'label' => '', 'type' => 'text', 'required' => false];
$blankBlock = ['type' => 'text', 'a' => '', 'b' => '', 'align' => 'L', 'size' => 0, 'bold' => false];

layout_top($t->id ? 'Edit template' : 'New template', 'templates');
?>
<div class="page-title">
  <div><h1><?= $t->id ? 'Edit template' : 'New template' ?></h1>
  <p>Define the data fields, then lay out the page using <code class="ph">{{field_key}}</code> placeholders.</p></div>
  <a class="btn btn-outline-secondary" href="templates.php">Back to templates</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><strong>Not saved:</strong><ul class="mb-0"><li><?= implode('</li><li>', array_map('e', $errors)) ?></li></ul></div><?php endif; ?>
<?php if ($inUse): ?><div class="alert alert-info">This template already has <?= $inUse ?> record(s). If you rename a field's <em>key</em>, existing records lose that field's data. Changing labels or layout is always safe.</div><?php endif; ?>

<form method="post" id="editor">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= (int) $t->id ?>">

  <div class="panel mb-4"><div class="panel-body row g-3">
    <div class="col-md-5"><label class="form-label">Template name</label><input class="form-control" name="name" value="<?= e($t->name) ?>" required></div>
    <div class="col-md-7"><label class="form-label">Description</label><input class="form-control" name="description" value="<?= e($t->description) ?>" placeholder="What is this certificate for?"></div>
  </div></div>

  <div class="panel mb-4">
    <div class="panel-head"><div><h2>1. Fields</h2><div class="hint">One field per column in your spreadsheet. The key is what you write inside <code class="ph">{{ }}</code>.</div></div>
      <button type="button" class="btn btn-sm btn-outline-primary" data-add="tpl-field" data-into="#fields">Add field</button></div>
    <div class="panel-body" id="fields">
<?php foreach ($t->fields as $f) { echo field_row($f); } ?>
    </div>
  </div>

  <div class="panel mb-4">
    <div class="panel-head"><div><h2>2. Page layout</h2><div class="hint">Blocks print top to bottom. Click a placeholder to insert it into the box you last used. <code class="ph">**bold**</code> makes part of a line bold.</div></div>
      <button type="button" class="btn btn-sm btn-outline-primary" data-add="tpl-block" data-into="#blocks">Add block</button></div>
    <div class="panel-body border-bottom">
      <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="hint me-1">Fields:</span>
<?php foreach ($t->fields as $f): ?><button type="button" class="chip" data-insert="{{<?= e($f['key']) ?>}}">{{<?= e($f['key']) ?>}}</button><?php endforeach; ?>
        <span class="hint ms-2 me-1">Built in:</span>
<?php foreach (['serial_no', 'record_no', 'issue_date', 'today', 'org_name', 'place'] as $k): ?><button type="button" class="chip" data-insert="{{<?= $k ?>}}">{{<?= $k ?>}}</button><?php endforeach; ?>
      </div>
      <div class="hint mt-2">Filters: <code class="ph">{{name|upper}}</code> <code class="ph">{{dob|date_words}}</code> <code class="ph">{{dob|date:d F Y}}</code> <code class="ph">{{gender|map:M=He,F=She,*=They}}</code>. See Help for the full list.</div>
    </div>
    <div class="panel-body" id="blocks">
<?php foreach ($t->blocks as $b) { echo block_row($b); } ?>
    </div>
    <div class="panel-body border-top hint">Size is the font size in points (for Space: the height in millimetres). Leave blank for the default. “Justify” does not support <code class="ph">**bold**</code>.</div>
  </div>

  <div class="panel mb-4">
    <div class="panel-head"><h2>3. Page options</h2></div>
    <div class="panel-body row g-3">
      <div class="col-md-3"><label class="form-label">Font</label><select class="form-select" name="o_font">
<?php foreach (CertTemplate::FONTS as $f): ?><option <?= $t->options['font'] === $f ? 'selected' : '' ?>><?= $f ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Default text size (pt)</label><input class="form-control" type="number" min="8" max="18" name="o_base_size" value="<?= (int) $t->options['base_size'] ?>"></div>
      <div class="col-md-6 d-flex align-items-end gap-4 flex-wrap">
        <label><input type="checkbox" name="o_show_header" value="1" <?= $t->options['show_header'] ? 'checked' : '' ?>> Institution header</label>
        <label><input type="checkbox" name="o_border" value="1" <?= $t->options['border'] ? 'checked' : '' ?>> Page border</label>
        <label><input type="checkbox" name="o_place_date" value="1" <?= $t->options['place_date'] ? 'checked' : '' ?>> Place and date line</label>
      </div>
      <div class="col-md-12"><label class="form-label">Footer note</label><input class="form-control ph-target" name="o_footer_note" value="<?= e($t->options['footer_note']) ?>" placeholder="Optional line above the signatures"></div>
      <div class="col-md-12"><label class="form-label">Signature labels</label><input class="form-control" name="o_signatures" value="<?= e($t->options['signatures']) ?>">
        <div class="hint">Comma separated. One signature line is drawn for each. Leave blank for none.</div></div>
    </div>
  </div>

  <div class="d-flex gap-2 flex-wrap">
    <button class="btn btn-primary">Save template</button>
    <button class="btn btn-outline-secondary" formaction="template_preview.php" formtarget="_blank">Preview PDF with sample data</button>
  </div>
</form>

<template id="tpl-field"><?= field_row($blank) ?></template>
<template id="tpl-block"><?= block_row($blankBlock) ?></template>
<?php layout_bottom();
