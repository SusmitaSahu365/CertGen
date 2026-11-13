<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Db};

$user = Auth::require();

if (is_post()) {
    csrf_verify();
    $row = Db::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    $new = (string) input('new_password');
    if (!password_verify((string) input('current_password'), $row['password_hash'])) {
        flash('danger', 'Your current password is not correct.');
    } elseif ($p = Auth::passwordProblem($new)) {
        flash('danger', $p);
    } elseif ($new !== (string) input('new_password2')) {
        flash('danger', 'The new passwords do not match.');
    } else {
        Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
    }
    redirect('account.php');
}

layout_top('My account');
?>
<div class="page-title"><div><h1>My account</h1><p>Signed in as <strong><?= e($user['username']) ?></strong> (<?= e($user['role']) ?>)</p></div></div>
<div class="panel" style="max-width:520px">
  <div class="panel-head"><h2>Change password</h2></div>
  <form method="post" class="panel-body">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label">Current password</label><input class="form-control" type="password" name="current_password" required></div>
    <div class="mb-3"><label class="form-label">New password</label><input class="form-control" type="password" name="new_password" required minlength="8"></div>
    <div class="mb-3"><label class="form-label">Repeat new password</label><input class="form-control" type="password" name="new_password2" required></div>
    <button class="btn btn-primary">Update password</button>
  </form>
</div>
<?php layout_bottom();
