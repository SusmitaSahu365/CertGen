<?php
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Db};

$me = Auth::requireAdmin();

if (is_post()) {
    csrf_verify();
    $action = (string) input('action');
    $id = (int) input('id');

    if ($action === 'add') {
        $u = trim((string) input('username'));
        $pw = (string) input('password');
        $role = input('role') === 'admin' ? 'admin' : 'staff';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $u)) {
            flash('danger', 'Username must be 3-50 characters: letters, numbers, dot, dash or underscore.');
        } elseif ($p = Auth::passwordProblem($pw)) {
            flash('danger', $p);
        } elseif (Db::val('SELECT COUNT(*) FROM users WHERE username = ?', [$u])) {
            flash('danger', 'That username is already taken.');
        } else {
            Auth::createUser($u, $pw, $role);
            flash('success', "User \"$u\" created.");
        }
    } elseif ($action === 'reset') {
        $pw = (string) input('password');
        if ($p = Auth::passwordProblem($pw)) {
            flash('danger', $p);
        } else {
            Db::run('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
            flash('success', 'Password reset.');
        }
    } elseif ($action === 'role' || $action === 'delete') {
        $target = Db::one('SELECT * FROM users WHERE id = ?', [$id]);
        $admins = (int) Db::val("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        if (!$target) {
            flash('danger', 'User not found.');
        } elseif ($target['role'] === 'admin' && $admins <= 1) {
            flash('danger', 'There must always be at least one administrator.');
        } elseif ($action === 'delete' && $id === (int) $me['id']) {
            flash('danger', 'You cannot delete your own account.');
        } elseif ($action === 'delete') {
            Db::run('DELETE FROM users WHERE id = ?', [$id]);
            flash('success', 'User deleted.');
        } else {
            Db::run('UPDATE users SET role = ? WHERE id = ?', [$target['role'] === 'admin' ? 'staff' : 'admin', $id]);
            flash('success', 'Role changed.');
        }
    }
    redirect('users.php');
}

$users = Db::all('SELECT id, username, role, created_at FROM users ORDER BY username');
layout_top('Users', 'users');
?>
<div class="page-title"><div><h1>Users</h1><p>Staff can import data and generate certificates. Administrators can also edit templates, settings and users.</p></div></div>
<div class="row g-4">
  <div class="col-lg-8"><div class="panel"><div class="table-responsive"><table class="table">
    <thead><tr><th>Username</th><th>Role</th><th>Created</th><th></th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
      <tr>
        <td><?= e($u['username']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
        <td><?= e($u['role']) ?></td>
        <td class="muted"><?= e(fmt_date($u['created_at'], 'd M Y')) ?></td>
        <td class="text-end">
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="btn btn-sm btn-outline-secondary" name="action" value="role" data-confirm="Change this user's role?">Make <?= $u['role'] === 'admin' ? 'staff' : 'admin' ?></button></form>
          <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" name="action" value="delete" data-confirm="Delete user <?= e($u['username']) ?>?">Delete</button></form>
        </td>
      </tr>
<?php endforeach; ?>
    </tbody></table></div></div></div>
  <div class="col-lg-4">
    <div class="panel mb-4"><div class="panel-head"><h3>Add a user</h3></div>
      <form method="post" class="panel-body"><?= csrf_field() ?><input type="hidden" name="action" value="add">
        <div class="mb-2"><label class="form-label">Username</label><input class="form-control" name="username" required></div>
        <div class="mb-2"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
        <div class="mb-3"><label class="form-label">Role</label><select class="form-select" name="role"><option value="staff">Staff</option><option value="admin">Administrator</option></select></div>
        <button class="btn btn-primary">Create user</button></form></div>
    <div class="panel"><div class="panel-head"><h3>Reset a password</h3></div>
      <form method="post" class="panel-body"><?= csrf_field() ?><input type="hidden" name="action" value="reset">
        <div class="mb-2"><label class="form-label">User</label><select class="form-select" name="id">
<?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"><?= e($u['username']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">New password</label><input class="form-control" type="password" name="password" required minlength="8"></div>
        <button class="btn btn-outline-primary">Reset password</button></form></div>
  </div>
</div>
<?php layout_bottom();
