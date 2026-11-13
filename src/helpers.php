<?php
/**
 * Small global helpers used by every page.
 */

use CertGen\Auth;
use CertGen\Db;

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        $file = ROOT . '/config/config.php';
        $cfg = require (is_file($file) ? $file : ROOT . '/config/config.example.php');
    }
    if ($key === null) {
        return $cfg;
    }
    $v = $cfg;
    foreach (explode('.', $key) as $part) {
        if (!is_array($v) || !array_key_exists($part, $v)) {
            return $default;
        }
        $v = $v[$part];
    }
    return $v;
}

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

// ---- flash messages ------------------------------------------------------

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- CSRF ----------------------------------------------------------------

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): void
{
    $sent = $_POST['_csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(csrf_token(), $sent)) {
        http_response_code(419);
        exit('Your session expired or the form was invalid. Go back, refresh the page and try again.');
    }
}

// ---- settings (key/value table) -------------------------------------------

function settings_all(bool $refresh = false): array
{
    static $cache = null;
    if ($cache === null || $refresh) {
        $cache = [];
        foreach (Db::all('SELECT k, v FROM settings') as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache;
}

function setting(string $key, string $default = ''): string
{
    $all = settings_all();
    return isset($all[$key]) && $all[$key] !== '' ? $all[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    Db::run('DELETE FROM settings WHERE k = ?', [$key]);
    Db::run('INSERT INTO settings (k, v) VALUES (?, ?)', [$key, $value]);
    settings_all(true);
}

// ---- dates ---------------------------------------------------------------

/** Format an ISO (Y-m-d) date; returns the input unchanged if it is not a valid date. */
function fmt_date(?string $iso, ?string $format = null): string
{
    $iso = (string) $iso;
    if ($iso === '') {
        return '';
    }
    $format = $format ?: setting('date_format', 'd/m/Y');
    $d = DateTime::createFromFormat('Y-m-d', substr($iso, 0, 10));
    $err = DateTime::getLastErrors();
    if (!$d || ($err && ($err['warning_count'] || $err['error_count']))) {
        return $iso;
    }
    return $d->format($format);
}

function slugify(string $s, string $sep = '-'): string
{
    $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
    $s = strtolower(preg_replace('/[^A-Za-z0-9]+/', $sep, $s));
    return trim($s, $sep) ?: 'file';
}

// ---- layout --------------------------------------------------------------

function layout_top(string $title, string $active = ''): void
{
    $user = Auth::user();
    $app = config('app_name', 'CertGen');
    $nav = [
        'index'     => ['Dashboard', 'index.php'],
        'batches'   => ['Batches', 'batches.php'],
        'templates' => ['Templates', 'templates.php'],
    ];
    if ($user && $user['role'] === 'admin') {
        $nav['users'] = ['Users', 'users.php'];
        $nav['settings'] = ['Settings', 'settings.php'];
    }
    $nav['help'] = ['Help', 'help.php'];
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<?php if (config('demo_mode')): ?>
<div class="demo-banner">Demo mode: use sample data only. Every PDF is watermarked SAMPLE.</div>
<?php endif; ?>
<?php if ($user): ?>
<nav class="topbar navbar navbar-expand-lg">
  <div class="container-xl">
    <a class="navbar-brand brand" href="index.php"><?= e($app) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-label="Menu">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php foreach ($nav as $key => [$label, $href]): ?>
          <li class="nav-item"><a class="nav-link<?= $active === $key ? ' active' : '' ?>" href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="d-flex align-items-center gap-3 user-box">
        <a class="nav-link p-0" href="account.php"><?= e($user['username']) ?><?= $user['role'] === 'admin' ? ' (admin)' : '' ?></a>
        <form method="post" action="logout.php" class="m-0"><?= csrf_field() ?><button class="btn btn-sm btn-outline-light">Sign out</button></form>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="container-xl py-4">
<?php foreach (take_flash() as [$type, $msg]): ?>
  <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
    <?= e($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; ?>
<?php
}

function layout_bottom(): void
{
    ?>
</main>
<footer class="container-xl pb-4 small text-muted">
  <?= e(config('app_name', 'CertGen')) ?> · open-source bulk certificate generator
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
<?php
}

// ---- split-screen layout for login / setup ---------------------------------

function auth_top(string $title): void
{
    $app = config('app_name', 'CertGen');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($app) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-art" aria-hidden="true">
    <div class="specimen"><div class="inside">
      <div class="org">Your Institution Name</div>
      <div class="ttl">BONAFIDE CERTIFICATE</div>
      <p>This is to certify that <span class="tok">{{full_name|upper}}</span> is a bonafide student of this institution, studying in <span class="tok">{{year}}</span> of <span class="tok">{{course}}</span>.</p>
      <p>Issued on <span class="tok">{{issue_date}}</span> for the purpose of <span class="tok">{{purpose}}</span>.</p>
      <div class="sig"><span>Prepared by</span><span>Authorised signatory</span></div>
    </div></div>
  </div>
  <div class="auth-form"><div class="inner">
    <h1 class="brand mb-1"><?= e($app) ?></h1>
    <p class="muted mb-4">Design a certificate once. Upload a spreadsheet. Download hundreds of PDFs.</p>
<?php foreach (take_flash() as [$type, $msg]): ?>
    <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
<?php endforeach;
}

function auth_bottom(): void
{
    echo "  </div></div>\n</div>\n</body>\n</html>\n";
}
