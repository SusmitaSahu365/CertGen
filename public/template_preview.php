<?php
// Renders the template currently in the editor (unsaved) with sample data.
define('NO_CSP', true);
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, CertTemplate, PdfRenderer};

Auth::requireAdmin();
if (!is_post()) {
    redirect('templates.php');
}
csrf_verify();
[$t, $errors] = CertTemplate::fromPost($_POST);
if ($errors) {
    layout_top('Preview');
    echo '<div class="alert alert-warning"><strong>Fix these first:</strong><ul class="mb-0"><li>' . implode('</li><li>', array_map('e', $errors)) . '</li></ul></div>';
    layout_bottom();
    exit;
}
$pdf = PdfRenderer::render($t, [['seq' => 1, 'data' => $t->sampleData()]], ['issue_date' => date('Y-m-d')]);
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="preview.pdf"');
echo $pdf;
