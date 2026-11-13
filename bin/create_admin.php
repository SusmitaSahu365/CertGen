<?php
// Create (or reset) an administrator from the command line.
//   php bin/create_admin.php <username> <password>
require __DIR__ . '/../src/bootstrap.php';

use CertGen\{Auth, Db};

[$self, $user, $pass] = $argv + [null, null, null];
if (!$user || !$pass) {
    fwrite(STDERR, "Usage: php bin/create_admin.php <username> <password>\n");
    exit(1);
}
if ($p = Auth::passwordProblem($pass)) {
    fwrite(STDERR, $p . "\n");
    exit(1);
}
if (Db::val('SELECT COUNT(*) FROM users WHERE username = ?', [$user])) {
    Db::run('UPDATE users SET password_hash = ?, role = ? WHERE username = ?', [password_hash($pass, PASSWORD_DEFAULT), 'admin', $user]);
    echo "Updated administrator \"$user\".\n";
} else {
    Auth::createUser($user, $pass, 'admin');
    echo "Created administrator \"$user\".\n";
}
