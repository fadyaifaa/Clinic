<?php
session_start();

// 1. Data login (Bypass sementara jika sistem auth belum siap)
if (!isset($_SESSION['clinic_id'])) {
    $_SESSION['clinic_id']   = 1; 
    $_SESSION['clinic_name'] = 'Klinik Sehat Bersama';
    $_SESSION['clinic_code'] = 'klinik-sehat';
    $_SESSION['user_name']   = 'Dahlia Cahya';
    $_SESSION['user_id']     = 1;
}

// 2. Panggil koneksi database
require_once __DIR__ . '/../config/database.php';

// 3. Fungsi Helper Mandiri
function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

function fetchCount($conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? (int)$result->fetch_row()[0] : 0;
}

$id = (int)$_SESSION['clinic_id'];
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

// Pastikan tabel staff_verification_requests tersedia
$conn->query("
    CREATE TABLE IF NOT EXISTS staff_verification_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        clinic_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NULL,
        name VARCHAR(150) NOT NULL,
        email VARCHAR(190) NOT NULL,
        phone VARCHAR(30) NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'Dokter',
        specialization VARCHAR(120) NULL DEFAULT 'Umum',
        credential_1 VARCHAR(100) NULL,
        credential_2 VARCHAR(100) NULL,
        status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
        invited_by BIGINT UNSIGNED NULL,
        invite_token VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX (clinic_id),
        INDEX (email),
        INDEX (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Sinkronkan user_clinics dengan user_roles klinik jika ada akun staf yang belum tercatat di user_clinics
$conn->query("
    INSERT IGNORE INTO user_clinics (user_id, clinic_id, is_primary) 
    SELECT DISTINCT ur.user_id, ur.clinic_id, 1 
    FROM user_roles ur 
    LEFT JOIN user_clinics uc ON uc.user_id = ur.user_id AND uc.clinic_id = ur.clinic_id 
    WHERE ur.clinic_id = {$id} AND uc.user_id IS NULL
");

// 4. PENANGANAN FORM POST (UNDANG STAF, VERIFIKASI, EDIT PERAN, AKTIF/NONAKTIFKAN)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- A. UNDANG STAF VIA EMAIL ---
    if ($action === 'invite_staff') {
        $email = trim($_POST['email'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? 'Dokter');
        $specialization = trim($_POST['specialization'] ?? 'Umum');
        $credential_1 = trim($_POST['credential_1'] ?? '');
        $credential_2 = trim($_POST['credential_2'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($email === '') {
            $_SESSION['flash_error'] = 'Alamat email staf wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Format email tidak valid.';
        } else {
            // Cek apakah email sudah menjadi staf aktif di klinik ini
            $stmtCek = $conn->prepare("
                SELECT u.id 
                FROM users u 
                JOIN user_clinics uc ON uc.user_id = u.id 
                WHERE u.email = ? AND uc.clinic_id = ?
            ");
            $stmtCek->bind_param('si', $email, $id);
            $stmtCek->execute();
            if ($stmtCek->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = "Pengguna dengan email '{$email}' sudah terdaftar sebagai staf aktif di klinik ini.";
            } else {
                if ($name === '') {
                    $nameParts = explode('@', $email);
                    $name = ucwords(str_replace(['.', '_', '-'], ' ', $nameParts[0]));
                }

                $inviteToken = bin2hex(random_bytes(16));

                $stmtIns = $conn->prepare("
                    INSERT INTO staff_verification_requests 
                    (clinic_id, name, email, phone, role, specialization, credential_1, credential_2, status, invited_by, invite_token, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)
                ");
                $stmtIns->bind_param('isssssssiss', $id, $name, $email, $phone, $role, $specialization, $credential_1, $credential_2, $currentUserId, $inviteToken, $notes);
                
                if ($stmtIns->execute()) {
                    $reqId = $stmtIns->insert_id;

                    // Audit Log & Notification
                    $logDetails = json_encode(['email' => $email, 'name' => $name, 'role' => $role, 'invite_token' => $inviteToken]);
                    $stmtLog = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'INVITE_STAFF_EMAIL', 'staff_verification_requests', ?, ?)");
                    if ($stmtLog) {
                        $stmtLog->bind_param('iiis', $id, $currentUserId, $reqId, $logDetails);
                        $stmtLog->execute();
                    }

                    $notifMsg = "Undangan telah dikirimkan ke {$email} sebagai {$role}. Menunggu pendaftaran mandiri.";
                    $stmtNotif = $conn->prepare("INSERT INTO notifications (user_id, title, message, notification_type, related_type, related_id) VALUES (?, 'Undangan Staf Terkirim', ?, 'staff_invitation', 'staff_verification_requests', ?)");
                    if ($stmtNotif) {
                        $stmtNotif->bind_param('isi', $currentUserId, $notifMsg, $reqId);
                        $stmtNotif->execute();
                    }

                    $_SESSION['flash_success'] = "Undangan staf berhasil dikirimkan ke {$email}. Permintaan bergabung kini tersimpan pada tab Permintaan Verifikasi.";
                } else {
                    $_SESSION['flash_error'] = "Gagal mengirimkan undangan: " . $conn->error;
                }
            }
        }
        header("Location: staff-management.php?tab=verify");
        exit;
    }

    // --- B. VERIFIKASI & TERIMA STAF ---
    if ($action === 'approve_verification') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $assignedRole = trim($_POST['assigned_role'] ?? 'doctor'); // admin, doctor, pharmacist
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        $stmtReq = $conn->prepare("SELECT * FROM staff_verification_requests WHERE id = ? AND clinic_id = ? AND status = 'pending'");
        $stmtReq->bind_param('ii', $requestId, $id);
        $stmtReq->execute();
        $req = $stmtReq->get_result()->fetch_assoc();

        if (!$req) {
            $_SESSION['flash_error'] = 'Data permintaan verifikasi tidak ditemukan atau sudah diproses.';
        } else {
            $conn->begin_transaction();
            try {
                // Cari atau buat akun user
                $stmtUser = $conn->prepare("SELECT id FROM users WHERE email = ?");
                $stmtUser->bind_param('s', $req['email']);
                $stmtUser->execute();
                $existingUser = $stmtUser->get_result()->fetch_assoc();

                if ($existingUser) {
                    $userId = (int)$existingUser['id'];
                    $stmtUpdUser = $conn->prepare("UPDATE users SET is_active = 1, phone = COALESCE(NULLIF(phone, ''), ?) WHERE id = ?");
                    $stmtUpdUser->bind_param('si', $req['phone'], $userId);
                    $stmtUpdUser->execute();
                } else {
                    $defaultPass = password_hash('Klinik123!', PASSWORD_DEFAULT);
                    $stmtNewUser = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
                    $stmtNewUser->bind_param('ssss', $req['name'], $req['email'], $req['phone'], $defaultPass);
                    $stmtNewUser->execute();
                    $userId = $stmtNewUser->insert_id;
                }

                // Petakan role_id (1: admin, 3: doctor, 4: pharmacist)
                $roleId = 3;
                if ($assignedRole === 'admin') $roleId = 1;
                elseif ($assignedRole === 'pharmacist') $roleId = 4;
                elseif ($assignedRole === 'doctor') $roleId = 3;

                // Hubungkan ke user_clinics
                $stmtUc = $conn->prepare("
                    INSERT INTO user_clinics (user_id, clinic_id, is_primary) 
                    VALUES (?, ?, ?) 
                    ON DUPLICATE KEY UPDATE is_primary = VALUES(is_primary)
                ");
                $stmtUc->bind_param('iii', $userId, $id, $isPrimary);
                $stmtUc->execute();

                // Hubungkan ke user_roles
                $stmtUr = $conn->prepare("
                    INSERT INTO user_roles (user_id, role_id, clinic_id) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)
                ");
                $stmtUr->bind_param('iii', $userId, $roleId, $id);
                $stmtUr->execute();

                // Simpan profil dokter atau apoteker
                if ($roleId === 3) {
                    $str = $req['credential_1'] ?: 'STR-' . rand(100000, 999999);
                    $spec = $req['specialization'] ?: 'Umum';
                    $stmtDoc = $conn->prepare("
                        INSERT INTO doctors (user_id, license_number, specialization, is_active)
                        VALUES (?, ?, ?, 1)
                        ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), specialization = VALUES(specialization), is_active = 1
                    ");
                    $stmtDoc->bind_param('iss', $userId, $str, $spec);
                    $stmtDoc->execute();
                } elseif ($roleId === 4) {
                    $stra = $req['credential_1'] ?: 'STRA-' . rand(100000, 999999);
                    $stmtPharm = $conn->prepare("
                        INSERT INTO pharmacists (user_id, license_number, is_active)
                        VALUES (?, ?, 1)
                        ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), is_active = 1
                    ");
                    $stmtPharm->bind_param('is', $userId, $stra);
                    $stmtPharm->execute();
                }

                // Update status permohonan
                $stmtApprove = $conn->prepare("UPDATE staff_verification_requests SET status = 'approved', user_id = ? WHERE id = ?");
                $stmtApprove->bind_param('ii', $userId, $requestId);
                $stmtApprove->execute();

                // Catat Audit Log
                $logDetails = json_encode(['request_id' => $requestId, 'user_id' => $userId, 'role' => $assignedRole]);
                $stmtLog = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'APPROVE_STAFF_VERIFICATION', 'users', ?, ?)");
                if ($stmtLog) {
                    $stmtLog->bind_param('iiis', $id, $currentUserId, $userId, $logDetails);
                    $stmtLog->execute();
                }

                $conn->commit();
                $_SESSION['flash_success'] = "Staf {$req['name']} berhasil diverifikasi dan hak akses klinik telah aktif.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Gagal memverifikasi staf: " . $e->getMessage();
            }
        }
        header("Location: staff-management.php?tab=active");
        exit;
    }

    // --- C. TOLAK PERMINTAAN VERIFIKASI ---
    if ($action === 'reject_verification') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $reason = trim($_POST['reject_reason'] ?? 'Kredensial belum memenuhi persyaratan.');

        $stmtReq = $conn->prepare("SELECT name FROM staff_verification_requests WHERE id = ? AND clinic_id = ? AND status = 'pending'");
        $stmtReq->bind_param('ii', $requestId, $id);
        $stmtReq->execute();
        $req = $stmtReq->get_result()->fetch_assoc();

        if ($req) {
            $stmtRej = $conn->prepare("UPDATE staff_verification_requests SET status = 'rejected', notes = ? WHERE id = ?");
            $stmtRej->bind_param('si', $reason, $requestId);
            $stmtRej->execute();

            $logDetails = json_encode(['request_id' => $requestId, 'reason' => $reason]);
            $stmtLog = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'REJECT_STAFF_VERIFICATION', 'staff_verification_requests', ?, ?)");
            if ($stmtLog) {
                $stmtLog->bind_param('iiis', $id, $currentUserId, $requestId, $logDetails);
                $stmtLog->execute();
            }

            $_SESSION['flash_success'] = "Pengajuan akses staf atas nama '{$req['name']}' telah ditolak.";
        } else {
            $_SESSION['flash_error'] = 'Data permohonan verifikasi tidak ditemukan.';
        }
        header("Location: staff-management.php?tab=verify");
        exit;
    }

    // --- D. EDIT PERAN & KREDENSIAL STAF ---
    if ($action === 'edit_staff_role') {
        $staffUserId = (int)($_POST['user_id'] ?? 0);
        $roleId = (int)($_POST['role_id'] ?? 3);
        $phone = trim($_POST['phone'] ?? '');
        $specialization = trim($_POST['specialization'] ?? 'Umum');
        $strNumber = trim($_POST['str_number'] ?? '');
        $straNumber = trim($_POST['stra_number'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        if ($staffUserId <= 0) {
            $_SESSION['flash_error'] = 'ID staf tidak valid.';
        } else {
            $conn->begin_transaction();
            try {
                // Update tabel users
                $stmtUpdUser = $conn->prepare("UPDATE users SET phone = ?, is_active = ? WHERE id = ?");
                $stmtUpdUser->bind_param('sii', $phone, $isActive, $staffUserId);
                $stmtUpdUser->execute();

                // Update primary clinic di user_clinics
                $stmtUpdUc = $conn->prepare("UPDATE user_clinics SET is_primary = ? WHERE user_id = ? AND clinic_id = ?");
                $stmtUpdUc->bind_param('iii', $isPrimary, $staffUserId, $id);
                $stmtUpdUc->execute();

                // Update peran di user_roles
                $stmtDelUr = $conn->prepare("DELETE FROM user_roles WHERE user_id = ? AND clinic_id = ?");
                $stmtDelUr->bind_param('ii', $staffUserId, $id);
                $stmtDelUr->execute();

                $stmtInsUr = $conn->prepare("INSERT INTO user_roles (user_id, role_id, clinic_id) VALUES (?, ?, ?)");
                $stmtInsUr->bind_param('iii', $staffUserId, $roleId, $id);
                $stmtInsUr->execute();

                // Update profil medis spesifik
                if ($roleId === 3) {
                    $stmtDoc = $conn->prepare("
                        INSERT INTO doctors (user_id, license_number, specialization, is_active)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), specialization = VALUES(specialization), is_active = VALUES(is_active)
                    ");
                    $stmtDoc->bind_param('issi', $staffUserId, $strNumber, $specialization, $isActive);
                    $stmtDoc->execute();
                } elseif ($roleId === 4) {
                    $stmtPharm = $conn->prepare("
                        INSERT INTO pharmacists (user_id, license_number, is_active)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE license_number = VALUES(license_number), is_active = VALUES(is_active)
                    ");
                    $stmtPharm->bind_param('isi', $staffUserId, $straNumber, $isActive);
                    $stmtPharm->execute();
                }

                // Catat audit log
                $logDetails = json_encode(['user_id' => $staffUserId, 'new_role_id' => $roleId, 'is_active' => $isActive]);
                $stmtLog = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'UPDATE_STAFF_ROLE', 'users', ?, ?)");
                if ($stmtLog) {
                    $stmtLog->bind_param('iiis', $id, $currentUserId, $staffUserId, $logDetails);
                    $stmtLog->execute();
                }

                $conn->commit();
                $_SESSION['flash_success'] = 'Peran dan data kredensial staf berhasil diperbarui.';
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Gagal memperbarui data staf: ' . $e->getMessage();
            }
        }
        header("Location: staff-management.php?tab=active");
        exit;
    }

    // --- E. AKTIFKAN / NONAKTIFKAN STAF ---
    if ($action === 'toggle_staff_status') {
        $staffUserId = (int)($_POST['user_id'] ?? 0);
        $targetStatus = (int)($_POST['target_status'] ?? 0);

        if ($staffUserId <= 0) {
            $_SESSION['flash_error'] = 'ID staf tidak valid.';
        } elseif ($staffUserId === $currentUserId && $targetStatus === 0) {
            $_SESSION['flash_error'] = 'Anda tidak dapat menonaktifkan akun Anda sendiri.';
        } else {
            $stmtUpd = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmtUpd->bind_param('ii', $targetStatus, $staffUserId);
            if ($stmtUpd->execute()) {
                $statusText = $targetStatus === 1 ? 'diaktifkan kembali' : 'dinonaktifkan';
                
                $logDetails = json_encode(['target_user_id' => $staffUserId, 'new_status' => $targetStatus]);
                $stmtLog = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'TOGGLE_STAFF_STATUS', 'users', ?, ?)");
                if ($stmtLog) {
                    $stmtLog->bind_param('iiis', $id, $currentUserId, $staffUserId, $logDetails);
                    $stmtLog->execute();
                }

                $_SESSION['flash_success'] = "Status akun staf berhasil {$statusText}.";
            } else {
                $_SESSION['flash_error'] = "Gagal memperbarui status: " . $conn->error;
            }
        }
        header("Location: staff-management.php?tab=active");
        exit;
    }
}

// 5. FLASH NOTIFICATION
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 6. STATISTIK STAF DINAMIS
$totalStaf = fetchCount($conn, "SELECT COUNT(DISTINCT user_id) FROM user_clinics WHERE clinic_id=?", $id);
$stafAktif = fetchCount($conn, "SELECT COUNT(DISTINCT uc.user_id) FROM user_clinics uc JOIN users u ON u.id = uc.user_id WHERE uc.clinic_id=? AND u.is_active=1", $id);
$stafNonaktif = fetchCount($conn, "SELECT COUNT(DISTINCT uc.user_id) FROM user_clinics uc JOIN users u ON u.id = uc.user_id WHERE uc.clinic_id=? AND u.is_active=0", $id);

// 7. PERMINTAAN VERIFIKASI (TAB 2)
$sql_pending = "
    SELECT id, clinic_id, user_id, name, email, phone, role, specialization, 
           credential_1, credential_2, status, created_at,
           DATE_FORMAT(created_at, '%d %b %Y') as date,
           DATE_FORMAT(created_at, '%H:%i WIB') as time
    FROM staff_verification_requests 
    WHERE clinic_id = ? AND status = 'pending'
    ORDER BY created_at DESC
";
$stmt_p = $conn->prepare($sql_pending);
$stmt_p->bind_param('i', $id);
$stmt_p->execute();
$pending_list = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
$pendingVerifikasi = count($pending_list);

// 8. DAFTAR STAF AKTIF / TERDAFTAR (TAB 1)
$sql_staff = "
    SELECT 
        u.id, 
        u.name, 
        u.email, 
        u.phone, 
        u.is_active,
        IF(u.is_active = 1, 'active', 'inactive') AS status, 
        u.last_login_at AS last_login,
        MIN(r.id) AS role_id,
        GROUP_CONCAT(DISTINCT r.name ORDER BY r.id SEPARATOR ', ') AS role_names,
        MAX(d.specialization) AS specialization,
        MAX(d.license_number) AS str_number, 
        NULL AS sip_number, 
        MAX(a.license_number) AS stra_number, 
        NULL AS sipa_number,
        MAX(IFNULL(uc.is_primary, 0)) AS is_primary,
        MAX(uc.joined_at) AS joined_at
    FROM user_clinics uc
    JOIN users u ON u.id = uc.user_id
    JOIN user_roles ur ON ur.user_id = u.id AND ur.clinic_id = uc.clinic_id
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN doctors d ON d.user_id = u.id
    LEFT JOIN pharmacists a ON a.user_id = u.id
    WHERE uc.clinic_id = ?
    GROUP BY u.id
    ORDER BY u.name ASC
";
$stmt = $conn->prepare($sql_staff);
$stmt->bind_param('i', $id);
$stmt->execute();
$staff_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

function getStatusBadge($status) {
    if (strtolower($status) === 'active' || strtolower($status) === 'aktif') {
        return '<span class="status-badge status-green">Aktif</span>';
    } else {
        return '<span class="status-badge status-red">Nonaktif</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manajemen Staf & Akses - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css">
  <link rel="stylesheet" href="../assets/css/staff.css"> 
  <style>
    .d-none { display: none !important; }
  </style>
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <header class="topbar">
    <div class="topbar-brand">
      <div class="logo-icon"><div class="logo-inner"></div></div>
      <div class="brand-info">
        <div class="brand-name"><?= h($_SESSION['clinic_name']) ?></div>
        <div class="brand-meta">
          <span class="badge-blue">klinik-sehat</span>
          <span class="meta-text">Tenant tunggal · <?= h($_SESSION['clinic_code'] ?? 'klinik') ?>.platform.com</span>
        </div>
      </div>
    </div>
    
    <div class="flex-spacer"></div>
    
    <div class="search-bar">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      <input type="text" id="topSearchInput" placeholder="Cari nama, email, atau telepon" class="search-input" onkeyup="syncTopSearch(this.value)">
    </div>
    
    <div class="datetime-widget">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
      <span id="clock-top"></span>
    </div>
    
    <button class="notif-btn">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
      <span class="notif-dot"></span>
    </button>
    
    <div class="user-profile">
      <div class="avatar"><?= h(strtoupper(substr($_SESSION['user_name'], 0, 1))) ?></div>
      <div class="user-info">
        <span class="user-name"><?= h($_SESSION['user_name']) ?></span>
        <span class="user-role">Admin Klinik</span>
      </div>
      <a href="../logout.php"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></a>
    </div>
  </header>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($_SESSION['clinic_name'])) ?></span>
        <span class="sidebar-title">Manajemen internal</span>
      </div>
      
      <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
          Dashboard Utama
        </a>
        <a href="patient-registration.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          Pendaftaran & Pasien
        </a>
        <a href="staff-management.php" class="nav-item active">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          Manajemen Staf & Akses
        </a>
        <a href="master-data.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          Master Data Klinik
        </a>
        <a href="pharmacy-stock.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          Farmasi & Stok
        </a>
        <a href="laporan-operasional.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          Laporan Operasional
        </a>
        <a href="pengaturan-klinik.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
          Pengaturan Klinik
        </a>
      </nav>
      
      <div class="flex-spacer"></div>
      
      <div class="system-status">
        <div class="status-header">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          <span>Sistem beroperasi normal</span>
        </div>
        <div class="status-meta" id="clock-sidebar"></div>
      </div>
      
      <a href="#" class="help-center-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        Pusat Bantuan
      </a>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <main class="content-area">
      
      <!-- Page Header -->
      <div class="page-heading">
        <div class="breadcrumb"><?= h($_SESSION['clinic_name']) ?> / Manajemen Staf & Akses</div>
        <div class="page-title-row">
          <div class="page-title-group">
            <h1>Manajemen Staf & Akses</h1>
            <p>Kelola permintaan bergabung, verifikasi staf, dan hak akses khusus klinik ini. Staf mendaftar mandiri, admin meninjau kredensial, lalu memberi akses setelah disetujui.</p>
          </div>
          <button type="button" class="btn-outline-blue" onclick="openInviteModal()">+ Undang Staf via Email</button>
        </div>
      </div>

      <!-- Flash Notification Alerts -->
      <?php if ($flashSuccess): ?>
        <div class="alert-banner alert-success" id="flashAlert">
          <div class="alert-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span><?= h($flashSuccess) ?></span>
          </div>
          <button type="button" class="alert-close" onclick="document.getElementById('flashAlert').remove()" aria-label="Tutup">&times;</button>
        </div>
      <?php endif; ?>

      <?php if ($flashError): ?>
        <div class="alert-banner alert-danger" id="flashAlert">
          <div class="alert-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span><?= h($flashError) ?></span>
          </div>
          <button type="button" class="alert-close" onclick="document.getElementById('flashAlert').remove()" aria-label="Tutup">&times;</button>
        </div>
      <?php endif; ?>

      <!-- Quick Stats -->
      <div class="stats-grid-small">
        <div class="stat-card-small">
          <div class="stat-val"><?= number_format($totalStaf, 0, ',', '.') ?></div>
          <div class="stat-label">Total staf terdaftar</div>
        </div>
        <div class="stat-card-small">
          <div class="stat-val"><?= number_format($stafAktif, 0, ',', '.') ?></div>
          <div class="stat-label">Akun aktif</div>
        </div>
        <div class="stat-card-small">
          <div class="stat-val"><?= number_format($stafNonaktif, 0, ',', '.') ?></div>
          <div class="stat-label">Akun nonaktif</div>
        </div>
        <div class="stat-card-small">
          <div class="stat-val" id="pendingStatCount"><?= $pendingVerifikasi ?></div>
          <div class="badge-pending">Permintaan Pendataan (Pending)</div>
        </div>
      </div>

      <!-- Main Panel: Staf -->
      <div class="data-panel">
        
        <!-- Tab Sub-Nav inside Panel -->
        <div class="panel-tab-nav">
          <a href="#" class="ptab-item active" id="tab-active" onclick="switchTab(event, 'active')">
            Staf Aktif <span class="badge-blue-pill" id="badgeCountActive"><?= number_format(count($staff_list), 0, ',', '.') ?></span>
          </a>
          <a href="#" class="ptab-item" id="tab-verify" onclick="switchTab(event, 'verify')">
            Permintaan Verifikasi <span class="badge-orange-pill" id="badgeCountVerify"><?= $pendingVerifikasi ?></span>
          </a>
        </div>

        <!-- ================= TAB 1: STAF AKTIF ================= -->
        <div id="content-active">
          <div class="panel-filters staff-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 310px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchStaff" placeholder="Cari nama, email, atau telepon" class="search-input" onkeyup="filterStaffTable()">
              </div>
              <select id="filterRole" class="filter-select" onchange="filterStaffTable()">
                <option value="">Peran: Semua</option>
                <option value="Admin">Admin Klinik</option>
                <option value="Dokter">Dokter</option>
                <option value="Apoteker">Apoteker</option>
              </select>
              <select id="filterStatus" class="filter-select" onchange="filterStaffTable()">
                <option value="">Status: Semua</option>
                <option value="Aktif">Status: Aktif</option>
                <option value="Nonaktif">Status: Nonaktif</option>
              </select>
              <div class="flex-spacer"></div>
              <div class="filter-info-right">
                Dokter · Apoteker · Hak Akses · Verifikasi
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table staff-table" id="tableStaffActive">
              <thead>
                <tr>
                  <th width="25%">Staf</th>
                  <th width="25%">Peran & Spesialisasi</th>
                  <th width="20%">SIP/STR & Kontak</th>
                  <th width="15%">Status & Login</th>
                  <th width="15%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$staff_list): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data staf terdaftar di klinik ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($staff_list as $staf): ?>
                    <tr>
                      <td>
                        <div class="td-main font-semibold"><?= h($staf['name']) ?></div>
                        <div class="td-sub-small mt-1"><?= h($staf['email']) ?><br/><?= h($staf['phone'] ?: '-') ?></div>
                      </td>
                      <td>
                        <div class="td-main">
                          <?php 
                            $rNames = strtolower($staf['role_names'] ?? '');
                            if (strpos($rNames, 'doctor') !== false) {
                                echo 'Dokter · ' . h($staf['specialization'] ?: 'Umum');
                            } elseif (strpos($rNames, 'admin') !== false && strpos($rNames, 'pharmacist') !== false) {
                                echo 'Admin Klinik • Apoteker';
                            } elseif (strpos($rNames, 'admin') !== false) {
                                echo 'Hak Akses · Admin Klinik';
                            } elseif (strpos($rNames, 'pharmacist') !== false) {
                                echo 'Apoteker';
                            } else {
                                echo h(ucwords($staf['role_names']));
                            }
                          ?>
                        </div>
                        <div class="td-sub-small mt-1">
                          <?php
                            if (strpos($rNames, 'doctor') !== false) {
                                echo "STR " . h($staf['str_number'] ?: '-') . "<br/>SIP " . h($staf['sip_number'] ?: '-');
                            } elseif (strpos($rNames, 'pharmacist') !== false) {
                                echo "STRA " . h($staf['stra_number'] ?: '-') . "<br/>SIPA " . h($staf['sipa_number'] ?: '-');
                            } else {
                                echo "Akses administrasi klinik<br/>Manajemen operasional";
                            }
                          ?>
                        </div>
                      </td>
                      <td>
                        <div class="td-main"><?= h($staf['phone'] ?: '-') ?></div>
                        <div class="td-sub-small mt-1"><?= h($staf['email']) ?></div>
                      </td>
                      <td>
                        <?= getStatusBadge($staf['status']) ?>
                        <div class="td-sub-small mt-2">
                          <?php 
                            if ($staf['last_login']) {
                                echo h(date('d M Y, H:i', strtotime($staf['last_login'])));
                            } else {
                                echo "Belum pernah login";
                            }
                          ?>
                        </div>
                      </td>
                      <td>
                        <div class="action-links">
                          <a href="javascript:void(0)" class="link-primary" onclick='openEditStaffRole(<?= json_encode($staf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Edit Peran</a>
                          <a href="javascript:void(0)" class="link-primary fw-normal" onclick='openDetailStaff(<?= json_encode($staf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Lihat Detail</a>
                          <?php if ((int)$staf['is_active'] === 1): ?>
                            <a href="javascript:void(0)" class="link-danger" onclick="confirmToggleStatus(<?= (int)$staf['id'] ?>, '<?= h(addslashes($staf['name'])) ?>', 0)">Nonaktifkan</a>
                          <?php else: ?>
                            <a href="javascript:void(0)" class="link-primary" style="color: #059669;" onclick="confirmToggleStatus(<?= (int)$staf['id'] ?>, '<?= h(addslashes($staf['name'])) ?>', 1)">Aktifkan</a>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info" id="staffPageInfo">Menampilkan 1–<?= count($staff_list) ?> dari <?= count($staff_list) ?> staf terdaftar</div>
            <div class="page-controls">
              <button class="btn-page" disabled>Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page" disabled>Berikutnya</button>
            </div>
          </div>
        </div>

        <!-- ================= TAB 2: PERMINTAAN VERIFIKASI ================= -->
        <div id="content-verify" class="d-none">
          <div class="panel-filters staff-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 310px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchPending" placeholder="Cari nama, email, atau telepon" class="search-input" onkeyup="filterPendingTable()">
              </div>
              <select id="filterPendingRole" class="filter-select" onchange="filterPendingTable()">
                <option value="">Peran: Semua</option>
                <option value="Dokter">Dokter</option>
                <option value="Apoteker">Apoteker</option>
                <option value="Admin">Admin</option>
              </select>
              <div class="flex-spacer"></div>
              <div class="filter-info-right">Pendaftaran mandiri · Dokter & Apoteker</div>
            </div>
          </div>

          <div style="padding: 16px 20px; display: flex; align-items: center; gap: 10px;">
            <span class="badge-orange-tag">Menunggu verifikasi</span>
            <span class="text-gray" style="font-size: 12px;">Pendaftar belum aktif dan belum memiliki akses. Tinjau kredensial sebelum menyetujui permintaan.</span>
          </div>

          <div class="table-responsive">
            <table class="data-table staff-table" id="tablePendingVerify">
              <thead>
                <tr style="background: var(--bg-light);">
                  <th width="25%" class="text-gray" style="font-size: 11px; font-weight: 600;">Staf</th>
                  <th width="15%" class="text-gray" style="font-size: 11px; font-weight: 600;">Peran Diajukan</th>
                  <th width="25%" class="text-gray" style="font-size: 11px; font-weight: 600;">Kredensial Medis</th>
                  <th width="15%" class="text-gray" style="font-size: 11px; font-weight: 600;">Tanggal Pengajuan</th>
                  <th width="20%" class="text-gray" style="font-size: 11px; font-weight: 600;">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($pending_list)): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-gray);">
                      Tidak ada permintaan verifikasi staf yang tertunda saat ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($pending_list as $p): ?>
                    <tr>
                      <td>
                        <div class="td-main font-semibold text-dark"><?= h($p['name']) ?></div>
                        <div class="td-sub-small text-gray" style="font-size: 11px;"><?= h($p['email']) ?></div>
                        <div class="td-sub-small text-gray" style="font-size: 11px;"><?= h($p['phone'] ?: '-') ?></div>
                      </td>
                      <td>
                        <span class="badge-blue-tag" style="margin-bottom: 4px; display: inline-block;"><?= h($p['role']) ?></span>
                        <div class="text-gray" style="font-size: 11px;">Belum aktif<br/>Belum memiliki akses</div>
                      </td>
                      <td>
                        <div class="font-semibold text-dark" style="font-size: 13px;"><?= h($p['specialization'] ?: '-') ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['credential_1'] ?: 'STR/STRA: Belum diisi') ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['credential_2'] ?: 'SIP/SIPA: Belum diisi') ?></div>
                      </td>
                      <td>
                        <div class="text-dark" style="font-size: 12px; font-weight: 500;"><?= h($p['date']) ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['time']) ?></div>
                      </td>
                      <td>
                        <div class="action-links" style="display: flex; flex-direction: row; gap: 8px;">
                          <button type="button" onclick="openModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" class="btn-verify">Verifikasi & Terima</button>
                          <button type="button" class="btn-reject" onclick="confirmReject(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['name'])) ?>')">Tolak</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;">
            <div class="page-info text-gray" id="pendingPageInfo" style="font-size: 12px;">Menampilkan <?= count($pending_list) ?> dari <?= $pendingVerifikasi ?> permintaan verifikasi</div>
          </div>
        </div>

      </div>

      <!-- Info Note -->
      <div class="info-note text-gray" style="margin-top: 16px; font-size: 11px;">
        Undangan email hanya mengajak staf mendaftar mandiri, bukan membuat akun atau password. Akses klinik diberikan setelah verifikasi dan persetujuan admin.
      </div>
      <div class="info-note text-gray" style="margin-top: 6px; font-size: 11px;">
        ⓘ Setiap perubahan peran dan akses internal dicatat otomatis dalam Audit Log.
      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto; display: flex; justify-content: space-between; padding-top: 12px;">
        <span class="text-gray" style="font-size: 11px;">© <?= date('Y') ?> <?= h($_SESSION['clinic_name']) ?> · Panel Kontrol Admin</span>
        <span class="text-gray" style="font-size: 11px;" id="clock-main"></span>
      </footer>

    </main>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 1: UNDANG STAF VIA EMAIL                                 -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalInviteStaff">
    <div class="modal-container" style="max-width: 580px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title-group">
          <h2>Undang Staf via Email</h2>
          <p>Kirimkan undangan resmi agar dokter atau apoteker mendaftar ke klinik ini.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeInviteModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="staff-management.php" id="formInviteStaff">
        <input type="hidden" name="action" value="invite_staff">

        <div class="modal-body">
          <div class="alert-box-blue">
            <span class="badge-solid-blue">Info Undangan</span>
            <span class="alert-text">Tautan pendaftaran khusus klinik akan dikirimkan ke email tujuan. Calon staf akan mengisi kredensial medisnya dan menunggu persetujuan Anda.</span>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="invite_email">Email Calon Staf *</label>
              <input type="email" name="email" id="invite_email" class="input-control" placeholder="dokter@gmail.com" required>
            </div>
            <div class="form-field-group">
              <label for="invite_name">Nama Lengkap & Gelar</label>
              <input type="text" name="name" id="invite_name" class="input-control" placeholder="Contoh: dr. Budi Santoso, Sp.A">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="invite_role">Peran Ditujukan *</label>
              <select name="role" id="invite_role" class="input-control" onchange="toggleInviteRoleFields(this.value)" required>
                <option value="Dokter">Dokter</option>
                <option value="Apoteker">Apoteker</option>
                <option value="Admin">Admin Klinik</option>
              </select>
            </div>
            <div class="form-field-group" id="group_invite_spec">
              <label for="invite_spec">Spesialisasi Dokter</label>
              <input type="text" name="specialization" id="invite_spec" class="input-control" placeholder="Dokter Umum / Spesialis">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="invite_phone">Nomor Telepon / WhatsApp</label>
              <input type="text" name="phone" id="invite_phone" class="input-control" placeholder="08xxxxxxxxxx">
            </div>
            <div class="form-field-group">
              <label for="invite_cred1" id="label_invite_cred1">Nomor STR (Opsional)</label>
              <input type="text" name="credential_1" id="invite_cred1" class="input-control" placeholder="Nomor STR jika ada">
            </div>
          </div>

          <div class="form-field-group">
            <label for="invite_notes">Pesan Tambahan (Opsional)</label>
            <textarea name="notes" id="invite_notes" class="input-control" rows="2" placeholder="Tuliskan pesan pembuka bagi rekan sejawat...">Halo Rekan Medis, Anda diundang untuk bergabung dalam tim medis <?= h($_SESSION['clinic_name']) ?> melalui sistem informasi klinik terpadu kami.</textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-outline-gray" onclick="closeInviteModal()">Batal</button>
          <div class="footer-actions">
            <button type="submit" class="btn-primary-solid">Kirim Undangan Sekarang</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 2: VERIFIKASI STAF (DIHUBUNGKAN KE DATABASE)             -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="verifyModal">
    <div class="modal-container" style="max-width: 650px; width: 100%;">
      
      <!-- Modal Header -->
      <div class="modal-header">
        <div class="modal-title-group">
          <h2>Verifikasi Permintaan Akses Staf</h2>
          <p>Tinjau data kredensial dokter/apoteker yang mendaftar mandiri sebelum memberikan hak akses ke klinik.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <!-- Modal Body -->
      <form method="POST" action="staff-management.php" id="formVerifyStaff">
        <input type="hidden" name="action" value="approve_verification">
        <input type="hidden" name="request_id" id="verify_request_id" value="0">
        <input type="hidden" name="assigned_role" id="verify_assigned_role" value="doctor">

        <div class="modal-body">
          <div class="alert-box-blue">
            <span class="badge-solid-blue">Menunggu Verifikasi</span>
            <span class="alert-text">Data pendaftaran mandiri · hanya baca. Tinjau identitas sebelum mengaktifkan hak akses staf ini.</span>
          </div>

          <!-- Section 1: Identitas -->
          <div class="detail-section gray-bg">
            <div class="section-heading">
              <h3>Identitas Pendaftar</h3>
              <p>Informasi yang dikirimkan oleh staf saat mengajukan akses.</p>
            </div>
            
            <div class="grid-2-col">
              <div class="readonly-group">
                <label>Nama lengkap</label>
                <div class="readonly-value" id="modal-name">-</div>
              </div>
              <div class="readonly-group">
                <label>Email login</label>
                <div class="readonly-value" id="modal-email">-</div>
              </div>
              <div class="readonly-group">
                <label>Nomor telepon</label>
                <div class="readonly-value" id="modal-phone">-</div>
              </div>
              <div class="readonly-group">
                <label>Spesialisasi</label>
                <div class="readonly-value" id="modal-specialization">-</div>
              </div>
              <div class="readonly-group">
                <label>Kredensial 1 (STR / STRA)</label>
                <div class="readonly-value" id="modal-cred1">-</div>
              </div>
              <div class="readonly-group">
                <label>Kredensial 2 (SIP / SIPA)</label>
                <div class="readonly-value" id="modal-cred2">-</div>
              </div>
              <div class="readonly-group" style="grid-column: span 2;">
                <label>Diajukan pada</label>
                <div class="readonly-value" id="modal-date">-</div>
              </div>
            </div>
          </div>

          <!-- Section 2: Penetapan Peran -->
          <div class="detail-section">
            <div class="section-heading">
              <h3>Penetapan Peran Staf</h3>
              <p>Pilih peran yang akan diaktifkan untuk akses klinik ini.</p>
            </div>
            <div class="selection-grid-2">
              <label class="selection-card active" id="card-role-doctor" onclick="selectVerifyRole('doctor')">
                <input type="radio" name="role_radio" value="doctor" checked class="d-none">
                <div class="custom-radio active" id="radio-indicator-doctor"></div>
                <span>Dokter</span>
              </label>
              <label class="selection-card" id="card-role-pharmacist" onclick="selectVerifyRole('pharmacist')">
                <input type="radio" name="role_radio" value="pharmacist" class="d-none">
                <div class="custom-radio" id="radio-indicator-pharmacist"></div>
                <span>Apoteker</span>
              </label>
            </div>
          </div>

          <!-- Section 3: Hak Akses Khusus -->
          <div class="detail-section">
            <div class="section-heading">
              <h3>Hak Akses Medis</h3>
              <p>Daftar izin operasional yang aktif otomatis berdasarkan peran yang ditetapkan.</p>
            </div>
            <div class="selection-grid-3" style="display: flex; flex-direction: column; gap: 8px;">
              <label class="toggle-row" style="padding: 10px 14px;">
                <div class="custom-checkbox active"></div>
                <span>Pemeriksaan & rekam medis SOAP</span>
              </label>
              <label class="toggle-row" style="padding: 10px 14px;">
                <div class="custom-checkbox active"></div>
                <span>Diagnosis (ICD) & tindakan medis</span>
              </label>
              <label class="toggle-row" style="padding: 10px 14px;">
                <div class="custom-checkbox active"></div>
                <span>Penulisan & verifikasi resep farmasi</span>
              </label>
            </div>
          </div>

          <!-- Section 4: Primary Clinic -->
          <label class="toggle-row" style="cursor: pointer;">
            <input type="checkbox" name="is_primary" value="1" checked style="width: 18px; height: 18px; accent-color: var(--primary);">
            <span>Tetapkan sebagai Klinik Utama (Primary Clinic) untuk akun staf ini</span>
          </label>
        </div>

        <!-- Modal Footer -->
        <div class="modal-footer">
          <button type="button" class="btn-outline-gray" onclick="closeModal()">Batal</button>
          <div class="footer-actions">
            <button type="button" class="btn-outline-red" id="btnRejectFromModal" onclick="rejectFromVerifyModal()">Tolak Pengajuan</button>
            <button type="submit" class="btn-primary-solid">Konfirmasi & Beri Akses</button>
          </div>
        </div>
      </form>
      
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 3: EDIT PERAN & KREDENSIAL STAF                          -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalEditRole">
    <div class="modal-container" style="max-width: 580px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title-group">
          <h2>Edit Peran & Kredensial Staf</h2>
          <p>Ubah hak akses atau nomor STR/SIP staf pada klinik ini.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeEditRoleModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="staff-management.php" id="formEditRole">
        <input type="hidden" name="action" value="edit_staff_role">
        <input type="hidden" name="user_id" id="edit_user_id" value="0">

        <div class="modal-body">
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label>Nama Staf</label>
              <input type="text" id="edit_display_name" class="input-control" readonly style="background: var(--bg-light); cursor: not-allowed;">
            </div>
            <div class="form-field-group">
              <label>Email Login</label>
              <input type="text" id="edit_display_email" class="input-control" readonly style="background: var(--bg-light); cursor: not-allowed;">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="edit_phone">Nomor Telepon</label>
              <input type="text" name="phone" id="edit_phone" class="input-control">
            </div>
            <div class="form-field-group">
              <label for="edit_role_id">Peran Staf *</label>
              <select name="role_id" id="edit_role_id" class="input-control" onchange="toggleEditRoleFields(this.value)" required>
                <option value="1">Admin Klinik</option>
                <option value="3">Dokter</option>
                <option value="4">Apoteker</option>
              </select>
            </div>
          </div>

          <div id="section_edit_doctor" class="form-row-grid-2">
            <div class="form-field-group">
              <label for="edit_specialization">Spesialisasi</label>
              <input type="text" name="specialization" id="edit_specialization" class="input-control" placeholder="Dokter Umum / Anak / dll.">
            </div>
            <div class="form-field-group">
              <label for="edit_str">Nomor STR</label>
              <input type="text" name="str_number" id="edit_str" class="input-control" placeholder="Nomor STR aktif">
            </div>
          </div>

          <div id="section_edit_pharm" class="form-field-group d-none">
            <label for="edit_stra">Nomor STRA</label>
            <input type="text" name="stra_number" id="edit_stra" class="input-control" placeholder="Nomor STRA aktif">
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="edit_is_active">Status Akun</label>
              <select name="is_active" id="edit_is_active" class="input-control">
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </div>
            <div class="form-field-group" style="justify-content: center;">
              <label style="display: flex; align-items: center; gap: 8px; margin-top: 22px; cursor: pointer;">
                <input type="checkbox" name="is_primary" id="edit_is_primary" value="1" style="width: 18px; height: 18px; accent-color: var(--primary);">
                <span>Klinik Utama (Primary)</span>
              </label>
            </div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-outline-gray" onclick="closeEditRoleModal()">Batal</button>
          <div class="footer-actions">
            <button type="submit" class="btn-primary-solid">Simpan Perubahan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 4: LIHAT DETAIL INFORMASI STAF                           -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalDetailStaff">
    <div class="modal-container" style="max-width: 540px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title-group">
          <h2>Informasi Detail Staf</h2>
          <p>Profil staf dan status kredensial medis pada klinik.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeDetailStaffModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <div class="modal-body">
        <div style="display: flex; align-items: center; gap: 14px; padding-bottom: 8px; border-bottom: 1px solid var(--border);">
          <div class="avatar" id="detail_avatar" style="width: 48px; height: 48px; font-size: 18px;">U</div>
          <div>
            <div style="font-size: 17px; font-weight: 700; color: var(--text-dark);" id="detail_name">-</div>
            <div style="font-size: 12.5px; color: var(--primary); font-weight: 600;" id="detail_role_badge">-</div>
          </div>
          <div class="flex-spacer"></div>
          <div id="detail_status_badge"></div>
        </div>

        <div class="grid-2-col" style="gap: 14px;">
          <div class="readonly-group">
            <label>Alamat Email</label>
            <div class="readonly-value" id="detail_email">-</div>
          </div>
          <div class="readonly-group">
            <label>Nomor Telepon</label>
            <div class="readonly-value" id="detail_phone">-</div>
          </div>
          <div class="readonly-group">
            <label>Kredensial STR / STRA</label>
            <div class="readonly-value" id="detail_cred1">-</div>
          </div>
          <div class="readonly-group">
            <label>Spesialisasi</label>
            <div class="readonly-value" id="detail_spec">-</div>
          </div>
          <div class="readonly-group">
            <label>Tanggal Bergabung</label>
            <div class="readonly-value" id="detail_joined">-</div>
          </div>
          <div class="readonly-group">
            <label>Terakhir Login</label>
            <div class="readonly-value" id="detail_login">-</div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-outline-gray" onclick="closeDetailStaffModal()">Tutup</button>
        <button type="button" class="btn-primary-solid" id="btnEditFromDetail">Edit Peran Staf</button>
      </div>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 5: KONFIRMASI STATUS (AKTIFKAN / NONAKTIFKAN)            -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalToggleStaffStatus">
    <div class="modal-container" style="max-width: 480px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title-group">
          <h2 id="toggle_modal_title">Konfirmasi Status Staf</h2>
          <p id="toggle_modal_sub">Perbarui status aktifitas staf ini pada sistem klinik.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeToggleStatusModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="staff-management.php">
        <input type="hidden" name="action" value="toggle_staff_status">
        <input type="hidden" name="user_id" id="toggle_user_id" value="0">
        <input type="hidden" name="target_status" id="toggle_target_status" value="0">

        <div class="modal-body">
          <div style="font-size: 14px; color: var(--text-dark); line-height: 1.6;">
            Apakah Anda yakin ingin <span id="toggle_action_word" style="font-weight: 700;">menonaktifkan</span> akun staf <strong id="toggle_staff_name"></strong>?
          </div>
          <div style="font-size: 12.5px; color: var(--text-gray); line-height: 1.5; background: var(--bg-light); padding: 12px; border-radius: 8px;">
            <span id="toggle_explain_text">Staf yang dinonaktifkan tidak akan dapat login atau mengakses rekam medis klinik hingga diaktifkan kembali.</span>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-outline-gray" onclick="closeToggleStatusModal()">Batal</button>
          <div class="footer-actions">
            <button type="submit" class="btn-primary-solid" id="btnToggleSubmit">Ya, Lanjutkan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 6: KONFIRMASI TOLAK PERMINTAAN VERIFIKASI                -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalRejectConfirm">
    <div class="modal-container" style="max-width: 480px; width: 100%;">
      <div class="modal-header">
        <div class="modal-title-group">
          <h2 style="color: #DC2626;">Tolak Permintaan Akses</h2>
          <p>Permintaan akses staf yang ditolak tidak akan diberikan hak login.</p>
        </div>
        <button type="button" class="btn-close-modal" onclick="closeRejectModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="staff-management.php">
        <input type="hidden" name="action" value="reject_verification">
        <input type="hidden" name="request_id" id="reject_request_id" value="0">

        <div class="modal-body">
          <div style="font-size: 14px; color: var(--text-dark); line-height: 1.6;">
            Tolak permintaan akses dari pendaftar <strong id="reject_staff_name"></strong>?
          </div>
          <div class="form-field-group">
            <label for="reject_reason">Alasan Penolakan</label>
            <textarea name="reject_reason" id="reject_reason" class="input-control" rows="2" placeholder="Tuliskan catatan alasan penolakan..."></textarea>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn-outline-gray" onclick="closeRejectModal()">Batal</button>
          <div class="footer-actions">
            <button type="submit" class="btn-outline-red">Ya, Tolak Permintaan</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <!-- SCRIPT -->
  <script>
    // JS Jam Digital Real-time
    function updateClock() {
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date()) + ' WIB';
        const cTop = document.getElementById('clock-top');
        const cSide = document.getElementById('clock-sidebar');
        const cMain = document.getElementById('clock-main');
        if (cTop) cTop.textContent = s;
        if (cSide) cSide.textContent = s;
        if (cMain) cMain.textContent = s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    // JS Tab Switching Interaktif & URL Persistence
    function switchTab(event, tabName) {
      if (event) event.preventDefault();

      const tabActive = document.getElementById('tab-active');
      const tabVerify = document.getElementById('tab-verify');
      const contentActive = document.getElementById('content-active');
      const contentVerify = document.getElementById('content-verify');

      if (tabName === 'active') {
        tabActive.classList.add('active');
        tabVerify.classList.remove('active');
        contentActive.classList.remove('d-none');
        contentVerify.classList.add('d-none');
      } else if (tabName === 'verify') {
        tabVerify.classList.add('active');
        tabActive.classList.remove('active');
        contentVerify.classList.remove('d-none');
        contentActive.classList.add('d-none');
      }
    }

    // Auto tab dari URL parameter ?tab=...
    window.addEventListener('DOMContentLoaded', function() {
      const urlParams = new URLSearchParams(window.location.search);
      const tabParam = urlParams.get('tab');
      if (tabParam === 'verify') {
        switchTab(null, 'verify');
      }
    });

    // Sinkronisasi Pencarian Topbar
    function syncTopSearch(val) {
      const contentActive = document.getElementById('content-active');
      if (!contentActive.classList.contains('d-none')) {
        document.getElementById('searchStaff').value = val;
        filterStaffTable();
      } else {
        document.getElementById('searchPending').value = val;
        filterPendingTable();
      }
    }

    // Real-time Search & Filter Tabel Staf Aktif
    function filterStaffTable() {
      const keyword = (document.getElementById('searchStaff').value || '').toLowerCase();
      const roleFilter = (document.getElementById('filterRole').value || '').toLowerCase();
      const statusFilter = (document.getElementById('filterStatus').value || '').toLowerCase();
      const rows = document.querySelectorAll('#tableStaffActive tbody tr');
      let visibleCount = 0;

      rows.forEach(row => {
        if (row.cells.length < 5) return;
        const stafInfo = row.cells[0].textContent.toLowerCase();
        const roleInfo = row.cells[1].textContent.toLowerCase();
        const contactInfo = row.cells[2].textContent.toLowerCase();
        const statusBadge = row.cells[3].textContent.trim().toLowerCase();

        const matchSearch = stafInfo.includes(keyword) || contactInfo.includes(keyword);
        const matchRole = !roleFilter || roleInfo.includes(roleFilter);
        const matchStatus = !statusFilter || statusBadge.includes(statusFilter);

        if (matchSearch && matchRole && matchStatus) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const pageInfo = document.getElementById('staffPageInfo');
      if (pageInfo) {
        pageInfo.textContent = `Menampilkan ${visibleCount} dari <?= count($staff_list) ?> staf terdaftar`;
      }
    }

    // Real-time Search & Filter Tabel Permintaan Verifikasi
    function filterPendingTable() {
      const keyword = (document.getElementById('searchPending').value || '').toLowerCase();
      const roleFilter = (document.getElementById('filterPendingRole').value || '').toLowerCase();
      const rows = document.querySelectorAll('#tablePendingVerify tbody tr');
      let visibleCount = 0;

      rows.forEach(row => {
        if (row.cells.length < 5) return;
        const stafInfo = row.cells[0].textContent.toLowerCase();
        const roleInfo = row.cells[1].textContent.toLowerCase();
        const credInfo = row.cells[2].textContent.toLowerCase();

        const matchSearch = stafInfo.includes(keyword) || credInfo.includes(keyword);
        const matchRole = !roleFilter || roleInfo.includes(roleFilter);

        if (matchSearch && matchRole) {
          row.style.display = '';
          visibleCount++;
        } else {
          row.style.display = 'none';
        }
      });

      const pageInfo = document.getElementById('pendingPageInfo');
      if (pageInfo) {
        pageInfo.textContent = `Menampilkan ${visibleCount} dari <?= $pendingVerifikasi ?> permintaan verifikasi`;
      }
    }

    // --- MODAL UNDANG STAF VIA EMAIL ---
    function openInviteModal() {
      document.getElementById('formInviteStaff').reset();
      toggleInviteRoleFields('Dokter');
      document.getElementById('modalInviteStaff').classList.remove('d-none');
    }

    function closeInviteModal() {
      document.getElementById('modalInviteStaff').classList.add('d-none');
    }

    function toggleInviteRoleFields(role) {
      const specGroup = document.getElementById('group_invite_spec');
      const cred1Label = document.getElementById('label_invite_cred1');
      if (role === 'Dokter') {
        specGroup.classList.remove('d-none');
        cred1Label.textContent = 'Nomor STR (Opsional)';
      } else if (role === 'Apoteker') {
        specGroup.classList.add('d-none');
        cred1Label.textContent = 'Nomor STRA (Opsional)';
      } else {
        specGroup.classList.add('d-none');
        cred1Label.textContent = 'Kredensial Tambahan';
      }
    }

    // --- MODAL VERIFIKASI STAF ---
    let currentVerifyData = null;
    function openModal(data) {
      currentVerifyData = data;
      if (data) {
        document.getElementById('verify_request_id').value = data.id || '0';
        document.getElementById('modal-name').textContent = data.name || '-';
        document.getElementById('modal-email').textContent = data.email || '-';
        document.getElementById('modal-phone').textContent = data.phone || '-';
        document.getElementById('modal-specialization').textContent = data.specialization || '-';
        document.getElementById('modal-cred1').textContent = data.credential_1 || '-';
        document.getElementById('modal-cred2').textContent = data.credential_2 || '-';
        document.getElementById('modal-date').textContent = (data.date && data.time) ? data.date + ' · ' + data.time : '-';

        const reqRole = (data.role || 'Dokter').toLowerCase();
        if (reqRole.includes('apoteker') || reqRole.includes('pharmacist')) {
          selectVerifyRole('pharmacist');
        } else {
          selectVerifyRole('doctor');
        }
      }
      document.getElementById('verifyModal').classList.remove('d-none');
    }

    function closeModal() {
      document.getElementById('verifyModal').classList.add('d-none');
    }

    function selectVerifyRole(role) {
      document.getElementById('verify_assigned_role').value = role;
      const cardDoc = document.getElementById('card-role-doctor');
      const cardPharm = document.getElementById('card-role-pharmacist');
      const radDoc = document.getElementById('radio-indicator-doctor');
      const radPharm = document.getElementById('radio-indicator-pharmacist');

      if (role === 'doctor') {
        cardDoc.classList.add('active');
        radDoc.classList.add('active');
        cardPharm.classList.remove('active');
        radPharm.classList.remove('active');
      } else {
        cardPharm.classList.add('active');
        radPharm.classList.add('active');
        cardDoc.classList.remove('active');
        radDoc.classList.remove('active');
      }
    }

    function rejectFromVerifyModal() {
      if (currentVerifyData) {
        closeModal();
        confirmReject(currentVerifyData.id, currentVerifyData.name);
      }
    }

    // --- MODAL TOLAK VERIFIKASI ---
    function confirmReject(id, name) {
      document.getElementById('reject_request_id').value = id;
      document.getElementById('reject_staff_name').textContent = name;
      document.getElementById('reject_reason').value = '';
      document.getElementById('modalRejectConfirm').classList.remove('d-none');
    }

    function closeRejectModal() {
      document.getElementById('modalRejectConfirm').classList.add('d-none');
    }

    // --- MODAL EDIT PERAN STAF ---
    let editingStaffData = null;
    function openEditStaffRole(data) {
      editingStaffData = data;
      document.getElementById('edit_user_id').value = data.id || '0';
      document.getElementById('edit_display_name').value = data.name || '';
      document.getElementById('edit_display_email').value = data.email || '';
      document.getElementById('edit_phone').value = data.phone || '';
      document.getElementById('edit_is_active').value = (data.is_active !== undefined) ? data.is_active : 1;
      document.getElementById('edit_is_primary').checked = (data.is_primary == 1);

      let targetRoleId = 1;
      const rNames = (data.role_names || '').toLowerCase();
      if (rNames.includes('doctor')) {
        targetRoleId = 3;
      } else if (rNames.includes('pharmacist')) {
        targetRoleId = 4;
      }
      document.getElementById('edit_role_id').value = targetRoleId;
      toggleEditRoleFields(targetRoleId);

      document.getElementById('edit_specialization').value = data.specialization || 'Umum';
      document.getElementById('edit_str').value = data.str_number || '';
      document.getElementById('edit_stra').value = data.stra_number || '';

      document.getElementById('modalEditRole').classList.remove('d-none');
    }

    function closeEditRoleModal() {
      document.getElementById('modalEditRole').classList.add('d-none');
    }

    function toggleEditRoleFields(roleId) {
      const rId = parseInt(roleId);
      const secDoc = document.getElementById('section_edit_doctor');
      const secPharm = document.getElementById('section_edit_pharm');
      if (rId === 3) {
        secDoc.classList.remove('d-none');
        secPharm.classList.add('d-none');
      } else if (rId === 4) {
        secDoc.classList.add('d-none');
        secPharm.classList.remove('d-none');
      } else {
        secDoc.classList.add('d-none');
        secPharm.classList.add('d-none');
      }
    }

    // --- MODAL DETAIL STAF ---
    function openDetailStaff(data) {
      editingStaffData = data;
      const initial = (data.name || 'U').substring(0, 1).toUpperCase();
      document.getElementById('detail_avatar').textContent = initial;
      document.getElementById('detail_name').textContent = data.name || '-';
      document.getElementById('detail_email').textContent = data.email || '-';
      document.getElementById('detail_phone').textContent = data.phone || '-';

      let roleLabel = 'Admin Klinik';
      const rNames = (data.role_names || '').toLowerCase();
      if (rNames.includes('doctor')) {
        roleLabel = 'Dokter ' + (data.specialization ? '· ' + data.specialization : '');
      } else if (rNames.includes('pharmacist') && rNames.includes('admin')) {
        roleLabel = 'Admin Klinik & Apoteker';
      } else if (rNames.includes('pharmacist')) {
        roleLabel = 'Apoteker';
      }
      document.getElementById('detail_role_badge').textContent = roleLabel;

      const isAct = (parseInt(data.is_active) === 1);
      document.getElementById('detail_status_badge').innerHTML = isAct 
        ? '<span class="status-badge status-green">Aktif</span>' 
        : '<span class="status-badge status-red">Nonaktif</span>';

      document.getElementById('detail_cred1').textContent = data.str_number || data.stra_number || '-';
      document.getElementById('detail_spec').textContent = data.specialization || '-';
      document.getElementById('detail_joined').textContent = data.joined_at ? new Date(data.joined_at).toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric'}) : '-';
      document.getElementById('detail_login').textContent = data.last_login ? new Date(data.last_login).toLocaleDateString('id-ID', {day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'}) : 'Belum pernah login';

      document.getElementById('btnEditFromDetail').onclick = function() {
        closeDetailStaffModal();
        openEditStaffRole(data);
      };

      document.getElementById('modalDetailStaff').classList.remove('d-none');
    }

    function closeDetailStaffModal() {
      document.getElementById('modalDetailStaff').classList.add('d-none');
    }

    // --- MODAL KONFIRMASI STATUS (AKTIF / NONAKTIF) ---
    function confirmToggleStatus(userId, staffName, targetStatus) {
      document.getElementById('toggle_user_id').value = userId;
      document.getElementById('toggle_target_status').value = targetStatus;
      document.getElementById('toggle_staff_name').textContent = staffName;

      const wordSpan = document.getElementById('toggle_action_word');
      const explainSpan = document.getElementById('toggle_explain_text');
      const btnSubmit = document.getElementById('btnToggleSubmit');
      const title = document.getElementById('toggle_modal_title');

      if (targetStatus === 0) {
        title.textContent = 'Nonaktifkan Akun Staf';
        wordSpan.textContent = 'menonaktifkan';
        wordSpan.style.color = '#DC2626';
        explainSpan.textContent = 'Staf yang dinonaktifkan tidak akan dapat login atau mengakses rekam medis klinik hingga diaktifkan kembali.';
        btnSubmit.textContent = 'Ya, Nonaktifkan Staf';
        btnSubmit.style.background = '#DC2626';
      } else {
        title.textContent = 'Aktifkan Kembali Akun Staf';
        wordSpan.textContent = 'mengaktifkan kembali';
        wordSpan.style.color = '#059669';
        explainSpan.textContent = 'Akun staf akan dipulihkan dan dapat kembali masuk ke sistem informasi klinik.';
        btnSubmit.textContent = 'Ya, Aktifkan Staf';
        btnSubmit.style.background = '#059669';
      }

      document.getElementById('modalToggleStaffStatus').classList.remove('d-none');
    }

    function closeToggleStatusModal() {
      document.getElementById('modalToggleStaffStatus').classList.add('d-none');
    }

    // Tutup modal jika klik overlay latar belakang
    window.addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.add('d-none');
      }
    });
  </script>

</body>
</html>
