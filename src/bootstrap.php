<?php
/**
 * Included at the top of every page (and by the CLI scripts and tests).
 */
define('ROOT', dirname(__DIR__));

if (PHP_VERSION_ID < 80000) {
    exit('CertGen needs PHP 8.0 or newer. You are running ' . PHP_VERSION . '.');
}

// Third-party libraries (FPDF, PhpSpreadsheet) come from Composer.
$autoload = ROOT . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('<h3>Dependencies are not installed.</h3><p>Open a terminal in the project folder and run <code>composer install</code>, then reload this page.</p>');
}
require $autoload;

// Our own classes (works even if Composer's classmap is stale).
spl_autoload_register(function (string $class): void {
    if (strncmp($class, 'CertGen\\', 8) === 0) {
        $file = ROOT . '/src/' . str_replace('\\', '/', substr($class, 8)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require ROOT . '/src/helpers.php';

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

if (PHP_SAPI !== 'cli') {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    if (!defined('NO_CSP')) {
        header("Content-Security-Policy: default-src 'self'; "
            . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
            . "font-src https://fonts.gstatic.com https://cdn.jsdelivr.net; "
            . "script-src 'self' https://cdn.jsdelivr.net; img-src 'self' data:; "
            . "form-action 'self'; frame-ancestors 'none'");
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('certgen');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

foreach (['tmp', 'uploads'] as $dir) {
    if (!is_dir(ROOT . "/storage/$dir")) {
        @mkdir(ROOT . "/storage/$dir", 0775, true);
    }
}

if (!CertGen\Db::ready()) {
    CertGen\Db::migrate();
}
