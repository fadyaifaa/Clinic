<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Wajib login: user yang belum login diarahkan ke halaman login
if (empty($_SESSION['user_id']) || empty($_SESSION['clinic_id'])) {
    header('Location: ../login.php?error=auth_required');
    exit;
}

if (empty($_SESSION['role_name'])) {
    $_SESSION['role_name'] = 'admin';
}
