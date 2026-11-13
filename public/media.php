<?php
// Serves the uploaded logo (stored outside the web root).
define('NO_CSP', true);
require __DIR__ . '/../src/bootstrap.php';

$file = setting('logo_file');
$path = $file ? ROOT . '/storage/uploads/' . basename($file) : '';
if (!$path || !is_file($path)) {
    http_response_code(404);
    exit;
}
$mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg'][strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=300');
readfile($path);
