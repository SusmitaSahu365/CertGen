<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\Auth;

if (Auth::needsSetup()) {
    redirect('setup.php');
}
if (Auth::user()) {
    redirect('index.php');
}

$error = '';
if (is_post()) {
    csrf_verify();
    $r = Auth::attempt(trim((string) input('username')), (string) input('password'));
    if ($r === true) {
        $next = (string) input('next');
        // only ever redirect to one of our own pages
        redirect(preg_match('/^[a-z_]+\.php$/', $next) ? $next : 'index.php');
    }
    $error = $r;
}

auth_top('Sign in');
?>
    <h2 class="h5 mb-3">Sign in</h2>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e(input('next')) ?>">
      <div class="mb-3"><label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" value="<?= e(input('username')) ?>" required autofocus></div>
      <div class="mb-4"><label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required></div>
      <button class="btn btn-primary w-100">Sign in</button>
    </form>
<?php auth_bottom();
