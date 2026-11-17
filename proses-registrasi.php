<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

function fail($msg) {
    header('Location: registrasi-klinik.php?error=' . urlencode($msg));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: registrasi-klinik.php');
    exit;
}

// Teruskan data ke registrasi-klinik.php untuk pemrosesan terpadu
require __DIR__ . '/registrasi-klinik.php';
