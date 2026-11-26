<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

$roleId   = (int)($_POST['role_id'] ?? $_GET['role_id'] ?? 0);
$clinicId = (int)($_POST['clinic_id'] ?? $_GET['clinic_id'] ?? 0);

if ($roleId <= 0 || $clinicId <= 0) {
    header('Location: admin/dashboard.php');
    exit;
}

// Validasi keamanan: pastikan user benar-benar memiliki kombinasi role_id & clinic_id di database
$stmt = $conn->prepare("
    SELECT 
        ur.role_id,
        r.name AS role_name,
        ur.clinic_id,
        c.name AS clinic_name,
        c.clinic_code,
        c.timezone
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    JOIN clinics c ON c.id = ur.clinic_id
    LEFT JOIN user_clinics uc ON uc.user_id = ur.user_id AND uc.clinic_id = ur.clinic_id
    WHERE ur.user_id = ? AND ur.role_id = ? AND ur.clinic_id = ? AND c.is_active = 1
    LIMIT 1
");
$stmt->bind_param('iii', $userId, $roleId, $clinicId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    $_SESSION['flash_error'] = 'Akses peran atau klinik tidak valid.';
    header('Location: admin/dashboard.php');
    exit;
}

// Perbarui session sesuai pilihan baru
$_SESSION['role_id']     = (int)$row['role_id'];
$_SESSION['role_name']   = $row['role_name'];
$_SESSION['clinic_id']   = (int)$row['clinic_id'];
$_SESSION['clinic_name'] = $row['clinic_name'];
$_SESSION['clinic_code'] = $row['clinic_code'];
$_SESSION['timezone']    = $row['timezone'] ?? 'Asia/Jakarta';

// Redirect sesuai peran
$roleLower = strtolower(trim($row['role_name']));
if ($roleLower === 'pharmacist') {
    header('Location: admin/pharmacy-stock.php');
} elseif ($roleLower === 'doctor') {
    header('Location: admin/dashboard.php');
} elseif ($roleLower === 'patient') {
    header('Location: admin/dashboard.php');
} else {
    header('Location: admin/dashboard.php');
}
exit;
