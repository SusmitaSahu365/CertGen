<?php
require __DIR__ . '/../src/bootstrap.php';

if (is_post()) {
    csrf_verify();
    CertGen\Auth::logout();
}
redirect('login.php');
