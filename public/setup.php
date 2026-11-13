<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Presets};

// First-run only: once any user exists this page is closed.
if (!Auth::needsSetup()) {
    redirect('login.php');
}

$error = '';
if (is_post()) {
    csrf_verify();
    $username = trim((string) input('username'));
    $pass = (string) input('password');
    $org = trim((string) input('org_name'));
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.';
    } elseif ($p = Auth::passwordProblem($pass)) {
        $error = $p;
    } elseif ($pass !== (string) input('password2')) {
        $error = 'The two passwords do not match.';
    } else {
        Auth::createUser($username, $pass, 'admin');
        if ($org !== '') {
            set_setting('org_name', mb_substr($org, 0, 150));
        }
        foreach (array_keys(Presets::all()) as $slug) {
            Presets::install($slug);
        }
        Auth::attempt($username, $pass);
        flash('success', 'Welcome! Your account is ready and the Leaving and Bonafide templates are installed. Add your logo and address under Settings.');
        redirect('index.php');
    }
}

auth_top('Set up');
?>
    <h2 class="h5 mb-3">Create the administrator account</h2>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="org_name">Institution / organisation name</label>
        <input class="form-control" id="org_name" name="org_name" value="<?= e(input('org_name')) ?>" placeholder="Shown at the top of every certificate"></div>
      <div class="mb-3"><label class="form-label" for="username">Admin username</label>
        <input class="form-control" id="username" name="username" value="<?= e(input('username')) ?>" required></div>
      <div class="mb-3"><label class="form-label" for="password">Password</label>
        <input class="form-control" type="password" id="password" name="password" required minlength="8">
        <div class="hint">At least 8 characters.</div></div>
      <div class="mb-4"><label class="form-label" for="password2">Repeat password</label>
        <input class="form-control" type="password" id="password2" name="password2" required></div>
      <button class="btn btn-primary w-100">Create account and continue</button>
    </form>
<?php auth_bottom();
