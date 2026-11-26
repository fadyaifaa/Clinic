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
$currentUserId = (int)($_SESSION['user_id'] ?? 1);

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

// 3b. Proses POST: Tambah Pasien Baru & Registrasi Kunjungan Baru
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_patient') {
        $fullName   = trim($_POST['full_name'] ?? '');
        $nik        = trim($_POST['national_id'] ?? '');
        $birthDate  = trim($_POST['birth_date'] ?? '');
        $sex        = trim($_POST['sex'] ?? 'unknown');
        $bloodType  = trim($_POST['blood_type'] ?? 'unknown');
        $address    = trim($_POST['address'] ?? '');
        $ecName     = trim($_POST['emergency_contact_name'] ?? '');
        $ecPhone    = trim($_POST['emergency_contact_phone'] ?? '');
        $allergies  = trim($_POST['allergies'] ?? '');
        $mrnInput   = trim($_POST['medical_record_number'] ?? '');

        $allowedSex   = ['male', 'female', 'other', 'unknown'];
        $allowedBlood = ['A', 'B', 'AB', 'O', 'unknown'];
        if (!in_array($sex, $allowedSex, true)) $sex = 'unknown';
        if (!in_array($bloodType, $allowedBlood, true)) $bloodType = 'unknown';

        if ($fullName === '') {
            $_SESSION['flash_error'] = 'Nama lengkap pasien wajib diisi.';
        } else {
            $nikParam       = ($nik !== '') ? $nik : null;
            $birthParam     = ($birthDate !== '') ? $birthDate : null;
            $addressParam   = ($address !== '') ? $address : null;
            $ecNameParam    = ($ecName !== '') ? $ecName : null;
            $ecPhoneParam   = ($ecPhone !== '') ? $ecPhone : null;
            $allergiesParam = ($allergies !== '') ? $allergies : null;

            $conn->begin_transaction();
            try {
                $patientId = 0;

                // Cek jika NIK sudah ada di tabel patients
                if ($nikParam !== null) {
                    $stmtCekNik = $conn->prepare("SELECT id FROM patients WHERE national_id = ?");
                    $stmtCekNik->bind_param('s', $nikParam);
                    $stmtCekNik->execute();
                    $existingP = $stmtCekNik->get_result()->fetch_assoc();
                    if ($existingP) {
                        $patientId = (int)$existingP['id'];
                        // Cek apakah sudah terdaftar di klinik ini
                        $stmtCekPc = $conn->prepare("SELECT id FROM patient_clinics WHERE patient_id = ? AND clinic_id = ?");
                        $stmtCekPc->bind_param('ii', $patientId, $id);
                        $stmtCekPc->execute();
                        if ($stmtCekPc->get_result()->fetch_assoc()) {
                            throw new Exception("Pasien dengan NIK '{$nikParam}' sudah terdaftar di klinik ini.");
                        }
                    }
                }

                if ($patientId === 0) {
                    $stmtInsP = $conn->prepare("
                        INSERT INTO patients (full_name, national_id, birth_date, sex, blood_type, address, emergency_contact_name, emergency_contact_phone, allergies)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtInsP->bind_param(
                        'sssssssss',
                        $fullName,
                        $nikParam,
                        $birthParam,
                        $sex,
                        $bloodType,
                        $addressParam,
                        $ecNameParam,
                        $ecPhoneParam,
                        $allergiesParam
                    );
                    $stmtInsP->execute();
                    $patientId = $stmtInsP->insert_id;
                }

                // Generate No. RM unik per klinik jika kosong
                $mrn = $mrnInput;
                if ($mrn === '') {
                    $countPc = fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id = ?", $id) + 1;
                    do {
                        $mrn = 'RM-' . date('ym') . '-' . str_pad((string)$countPc, 4, '0', STR_PAD_LEFT);
                        $stmtCekMrn = $conn->prepare("SELECT id FROM patient_clinics WHERE clinic_id = ? AND medical_record_number = ?");
                        $stmtCekMrn->bind_param('is', $id, $mrn);
                        $stmtCekMrn->execute();
                        $existsMrn = (bool)$stmtCekMrn->get_result()->fetch_assoc();
                        $countPc++;
                    } while ($existsMrn);
                } else {
                    $stmtCekMrn = $conn->prepare("SELECT id FROM patient_clinics WHERE clinic_id = ? AND medical_record_number = ?");
                    $stmtCekMrn->bind_param('is', $id, $mrn);
                    $stmtCekMrn->execute();
                    if ($stmtCekMrn->get_result()->fetch_assoc()) {
                        throw new Exception("Nomor Rekam Medis '{$mrn}' sudah digunakan di klinik ini.");
                    }
                }

                $stmtInsPc = $conn->prepare("
                    INSERT INTO patient_clinics (patient_id, clinic_id, medical_record_number, status)
                    VALUES (?, ?, ?, 'active')
                ");
                $stmtInsPc->bind_param('iis', $patientId, $id, $mrn);
                $stmtInsPc->execute();

                $conn->commit();
                $_SESSION['flash_success'] = "Pasien '{$fullName}' berhasil ditambahkan dengan No. RM {$mrn}.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Gagal menambahkan pasien: " . $e->getMessage();
            }
        }
        header("Location: patient-registration.php?tab=master");
        exit;
    }

    if ($action === 'add_registration') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $doctorId  = (int)($_POST['doctor_id'] ?? 0);
        $regDate   = trim($_POST['registration_date'] ?? '');
        $visitType = trim($_POST['visit_type'] ?? 'general');
        $complaint = trim($_POST['complaint'] ?? '');
        $status    = trim($_POST['status'] ?? 'waiting');

        $allowedVisitTypes = ['general', 'follow_up', 'emergency', 'other'];
        $allowedStatuses   = ['registered', 'waiting', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($visitType, $allowedVisitTypes, true)) $visitType = 'general';
        if (!in_array($status, $allowedStatuses, true)) $status = 'waiting';

        $regDateMysql = $regDate !== '' ? date('Y-m-d H:i:s', strtotime($regDate)) : date('Y-m-d H:i:s');
        $doctorParam  = ($doctorId > 0) ? $doctorId : null;
        $complaintParam = ($complaint !== '') ? $complaint : null;

        if ($patientId <= 0) {
            $_SESSION['flash_error'] = 'Silakan pilih pasien terlebih dahulu.';
        } else {
            $conn->begin_transaction();
            try {
                // Pastikan pasien terdaftar pada klinik aktif
                $stmtCekP = $conn->prepare("SELECT p.full_name FROM patient_clinics pc JOIN patients p ON p.id = pc.patient_id WHERE pc.patient_id = ? AND pc.clinic_id = ?");
                $stmtCekP->bind_param('ii', $patientId, $id);
                $stmtCekP->execute();
                $pRow = $stmtCekP->get_result()->fetch_assoc();
                if (!$pRow) {
                    throw new Exception('Pasien tidak ditemukan pada klinik ini.');
                }

                // Generate No. Registrasi unik untuk klinik & tanggal hari ini
                $seq = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id = ? AND DATE(registration_date) = CURDATE()", $id) + 1;
                do {
                    $regNum = 'REG-' . date('Ymd') . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
                    $stmtCekReg = $conn->prepare("SELECT id FROM registrations WHERE clinic_id = ? AND registration_number = ?");
                    $stmtCekReg->bind_param('is', $id, $regNum);
                    $stmtCekReg->execute();
                    $existsReg = (bool)$stmtCekReg->get_result()->fetch_assoc();
                    $seq++;
                } while ($existsReg);

                $stmtInsReg = $conn->prepare("
                    INSERT INTO registrations (clinic_id, patient_id, doctor_id, registration_number, registration_date, visit_type, complaint, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsReg->bind_param(
                    'iiisssssi',
                    $id,
                    $patientId,
                    $doctorParam,
                    $regNum,
                    $regDateMysql,
                    $visitType,
                    $complaintParam,
                    $status,
                    $currentUserId
                );
                $stmtInsReg->execute();

                $conn->commit();
                $_SESSION['flash_success'] = "Kunjungan untuk '{$pRow['full_name']}' berhasil didaftarkan dengan No. Registrasi {$regNum}.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Gagal mendaftarkan kunjungan: " . $e->getMessage();
            }
        }
        header("Location: patient-registration.php?tab=queue");
        exit;
    }

    if ($action === 'examine_registration') {
        $regId     = (int)($_POST['registration_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? 'in_progress');
        if (!in_array($newStatus, ['in_progress', 'completed'], true)) {
            $newStatus = 'in_progress';
        }

        $height   = ($_POST['height_cm'] ?? '') !== '' ? (float)$_POST['height_cm'] : null;
        $weight   = ($_POST['weight_kg'] ?? '') !== '' ? (float)$_POST['weight_kg'] : null;
        $temp     = ($_POST['temperature_c'] ?? '') !== '' ? (float)$_POST['temperature_c'] : null;
        $sysBp    = ($_POST['systolic_bp'] ?? '') !== '' ? (int)$_POST['systolic_bp'] : null;
        $diaBp    = ($_POST['diastolic_bp'] ?? '') !== '' ? (int)$_POST['diastolic_bp'] : null;
        $pulse    = ($_POST['pulse_bpm'] ?? '') !== '' ? (int)$_POST['pulse_bpm'] : null;
        $resp     = ($_POST['respiratory_rate'] ?? '') !== '' ? (int)$_POST['respiratory_rate'] : null;
        $spo2     = ($_POST['oxygen_saturation'] ?? '') !== '' ? (float)$_POST['oxygen_saturation'] : null;
        $notes    = trim($_POST['notes'] ?? '');
        $notesParam = ($notes !== '') ? $notes : null;

        if ($regId <= 0) {
            $_SESSION['flash_error'] = 'ID registrasi tidak valid.';
        } else {
            $conn->begin_transaction();
            try {
                $stmtCek = $conn->prepare("SELECT r.id, r.registration_number, r.status, p.full_name FROM registrations r JOIN patients p ON p.id = r.patient_id WHERE r.id = ? AND r.clinic_id = ?");
                $stmtCek->bind_param('ii', $regId, $id);
                $stmtCek->execute();
                $regRow = $stmtCek->get_result()->fetch_assoc();
                if (!$regRow) {
                    throw new Exception('Data registrasi tidak ditemukan.');
                }
                if ($regRow['status'] === 'cancelled') {
                    throw new Exception('Kunjungan yang sudah dibatalkan tidak dapat diperiksa.');
                }

                $stmtUpdReg = $conn->prepare("UPDATE registrations SET status = ? WHERE id = ? AND clinic_id = ?");
                $stmtUpdReg->bind_param('sii', $newStatus, $regId, $id);
                $stmtUpdReg->execute();

                $completedAtSql = ($newStatus === 'completed') ? "NOW()" : "completed_at";
                $stmtVisit = $conn->prepare("
                    INSERT INTO visits (
                        registration_id, check_in_at, started_at, completed_at,
                        height_cm, weight_kg, temperature_c, systolic_bp, diastolic_bp,
                        pulse_bpm, respiratory_rate, oxygen_saturation, notes
                    ) VALUES (
                        ?, NOW(), NOW(), " . ($newStatus === 'completed' ? "NOW()" : "NULL") . ",
                        ?, ?, ?, ?, ?, ?, ?, ?, ?
                    )
                    ON DUPLICATE KEY UPDATE
                        started_at = COALESCE(started_at, NOW()),
                        completed_at = " . $completedAtSql . ",
                        height_cm = VALUES(height_cm),
                        weight_kg = VALUES(weight_kg),
                        temperature_c = VALUES(temperature_c),
                        systolic_bp = VALUES(systolic_bp),
                        diastolic_bp = VALUES(diastolic_bp),
                        pulse_bpm = VALUES(pulse_bpm),
                        respiratory_rate = VALUES(respiratory_rate),
                        oxygen_saturation = VALUES(oxygen_saturation),
                        notes = VALUES(notes)
                ");
                $stmtVisit->bind_param(
                    'idddiiiids',
                    $regId,
                    $height,
                    $weight,
                    $temp,
                    $sysBp,
                    $diaBp,
                    $pulse,
                    $resp,
                    $spo2,
                    $notesParam
                );
                $stmtVisit->execute();

                $conn->commit();
                $statusLabel = ($newStatus === 'completed') ? 'Selesai Diperiksa' : 'Sedang Diperiksa';
                $_SESSION['flash_success'] = "Pemeriksaan untuk '{$regRow['full_name']}' ({$regRow['registration_number']}) diperbarui menjadi {$statusLabel}.";
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = "Gagal memproses pemeriksaan: " . $e->getMessage();
            }
        }
        header("Location: patient-registration.php?tab=queue");
        exit;
    }

    if ($action === 'cancel_registration') {
        $regId = (int)($_POST['registration_id'] ?? 0);
        if ($regId <= 0) {
            $_SESSION['flash_error'] = 'ID registrasi tidak valid.';
        } else {
            $stmtCek = $conn->prepare("SELECT r.id, r.registration_number, r.status, p.full_name FROM registrations r JOIN patients p ON p.id = r.patient_id WHERE r.id = ? AND r.clinic_id = ?");
            $stmtCek->bind_param('ii', $regId, $id);
            $stmtCek->execute();
            $regRow = $stmtCek->get_result()->fetch_assoc();

            if (!$regRow) {
                $_SESSION['flash_error'] = 'Data registrasi tidak ditemukan.';
            } elseif ($regRow['status'] === 'completed') {
                $_SESSION['flash_error'] = 'Kunjungan yang sudah selesai tidak dapat dibatalkan.';
            } elseif ($regRow['status'] === 'cancelled') {
                $_SESSION['flash_error'] = 'Kunjungan ini sudah berstatus dibatalkan.';
            } else {
                $stmtCancel = $conn->prepare("UPDATE registrations SET status = 'cancelled' WHERE id = ? AND clinic_id = ?");
                $stmtCancel->bind_param('ii', $regId, $id);
                if ($stmtCancel->execute()) {
                    $_SESSION['flash_success'] = "Kunjungan '{$regRow['full_name']}' ({$regRow['registration_number']}) berhasil dibatalkan.";
                } else {
                    $_SESSION['flash_error'] = "Gagal membatalkan kunjungan: " . $conn->error;
                }
            }
        }
        header("Location: patient-registration.php?tab=queue");
        exit;
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Opsi Dropdown untuk Modal (Pasien & Dokter di Klinik Aktif)
$patientOptions = [];
$stmtPOpt = $conn->prepare("
    SELECT p.id, p.full_name, p.national_id, pc.medical_record_number
    FROM patient_clinics pc
    JOIN patients p ON p.id = pc.patient_id
    WHERE pc.clinic_id = ? AND pc.status = 'active'
    ORDER BY p.full_name ASC
");
if ($stmtPOpt) {
    $stmtPOpt->bind_param('i', $id);
    $stmtPOpt->execute();
    $patientOptions = $stmtPOpt->get_result()->fetch_all(MYSQLI_ASSOC);
}

$doctorOptions = [];
$stmtDOpt = $conn->prepare("
    SELECT DISTINCT d.id, u.name, COALESCE(d.specialization, 'Umum') AS specialization
    FROM doctors d
    JOIN users u ON u.id = d.user_id
    JOIN user_clinics uc ON uc.user_id = u.id
    WHERE uc.clinic_id = ? AND d.is_active = 1 AND u.is_active = 1
    ORDER BY u.name ASC
");
if ($stmtDOpt) {
    $stmtDOpt->bind_param('i', $id);
    $stmtDOpt->execute();
    $doctorOptions = $stmtDOpt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Preview otomatis untuk No. RM & No. Registrasi pada modal
$nextMrnPreview = 'RM-' . date('ym') . '-' . str_pad((string)(fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id = ?", $id) + 1), 4, '0', STR_PAD_LEFT);
$nextRegPreview = 'REG-' . date('Ymd') . '-' . str_pad((string)(fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id = ? AND DATE(registration_date) = CURDATE()", $id) + 1), 3, '0', STR_PAD_LEFT);

// 4. Statistik Antrean Hari Ini
$waitingCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status IN ('registered','waiting')", $id);
$inProgressCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='in_progress'", $id);
$completedCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='completed'", $id);
$cancelledCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='cancelled'", $id);
$totalQueueToday = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE()", $id);

// 5. Tarik Data Antrean Hari Ini (Sesuai SQL Skema Asli)
$sql_queue = "
    SELECT r.id AS registration_id, r.registration_number, r.registration_date, r.status, r.visit_type, 
           r.complaint AS chief_complaint,
           p.full_name, 
           pc.medical_record_number,
           COALESCE(d.specialization, 'Umum') AS specialization,
           v.height_cm, v.weight_kg, v.temperature_c, v.systolic_bp, v.diastolic_bp,
           v.pulse_bpm, v.respiratory_rate, v.oxygen_saturation, v.notes AS visit_notes
    FROM registrations r
    JOIN patients p ON p.id = r.patient_id
    LEFT JOIN patient_clinics pc ON pc.patient_id = p.id AND pc.clinic_id = r.clinic_id
    LEFT JOIN doctors d ON d.id = r.doctor_id
    LEFT JOIN visits v ON v.registration_id = r.id
    WHERE r.clinic_id = ? AND DATE(r.registration_date) = CURDATE()
    ORDER BY r.created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_queue);
$stmt->bind_param('i', $id);
$stmt->execute();
$queue_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 6. Tarik Data Master Pasien Klinik (Sesuai SQL Skema Asli)
$totalPatients = fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id=?", $id);

$sql_patients = "
    SELECT p.id, p.full_name, 
           p.national_id AS nik, 
           p.birth_date, 
           p.sex AS gender, 
           p.blood_type, 
           p.allergies,
           pc.medical_record_number, 
           pc.status AS link_status
    FROM patient_clinics pc
    JOIN patients p ON p.id = pc.patient_id
    WHERE pc.clinic_id = ?
    ORDER BY pc.registered_at DESC
    LIMIT 10
";
$stmt2 = $conn->prepare($sql_patients);
$stmt2->bind_param('i', $id);
$stmt2->execute();
$patient_list = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

// Helper Status Badge Antrean
function getQueueBadge($status) {
    switch ($status) {
        case 'waiting':
        case 'registered':
            return '<span class="status-badge status-orange">Menunggu</span>';
        case 'in_progress':
            return '<span class="status-badge status-blue">Diperiksa</span>';
        case 'completed':
            return '<span class="status-badge status-green">Selesai</span>';
        case 'cancelled':
            return '<span class="status-badge status-red">Batal</span>';
        default:
            return '<span class="status-badge status-gray">'.$status.'</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pendaftaran & Pasien - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>"> 
  <link rel="stylesheet" href="../assets/css/patient.css?v=<?= filemtime(__DIR__ . '/../assets/css/patient.css') ?>"> 
  <style>
    .d-none { display: none !important; }
  </style>
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <?php require __DIR__ . '/../includes/topbar.php'; ?>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($_SESSION['clinic_name'])) ?></span>
        <span class="sidebar-title">Pendaftaran & Pasien</span>
      </div>
      
      <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
          Dashboard Utama
        </a>
        <a href="patient-registration.php" class="nav-item active">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          Pendaftaran & Pasien
        </a>
        <a href="staff-management.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          Manajemen Staf & Akses
        </a>
        <a href="master-data.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          Master Data Klinik
        </a>
        <a href="farmasi-stok.php" class="nav-item">
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
        <div class="breadcrumb"><?= h($_SESSION['clinic_name']) ?> / Pendaftaran & Pasien</div>
        <div class="page-title-group">
          <h1>Pendaftaran & Pasien</h1>
          <p>Kelola pendaftaran kunjungan, antrean klinik, dan master data rekam medis pasien.</p>
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

      <!-- Tab Navigation dengan Pemicu JS -->
      <div class="tabs-container">
        <a href="#" class="tab-item active" id="tab-queue" onclick="switchTab(event, 'queue')">Antrean & Registrasi Kunjungan</a>
        <a href="#" class="tab-item" id="tab-master" onclick="switchTab(event, 'master')">Master Data Pasien</a>
      </div>

      <!-- ============================================== -->
      <!-- TAB KONTEN 1: ANTREAN & REGISTRASI KUNJUNGAN   -->
      <!-- ============================================== -->
      <div id="content-queue">
        
        <!-- Quick Stats -->
        <div class="stats-grid">
          <div class="stat-card">
            <div class="card-title">Menunggu Dipanggil</div>
            <div class="stat-value"><?= number_format($waitingCount, 0, ',', '.') ?></div>
            <div class="meta-desc">Antrean aktif hari ini</div>
          </div>
          <div class="stat-card">
            <div class="card-title">Sedang Diperiksa</div>
            <div class="stat-value"><?= number_format($inProgressCount, 0, ',', '.') ?></div>
            <div class="meta-desc">Kunjungan berlangsung</div>
          </div>
          <div class="stat-card">
            <div class="card-title">Selesai</div>
            <div class="stat-value"><?= number_format($completedCount, 0, ',', '.') ?></div>
            <div class="meta-desc">Pemeriksaan selesai</div>
          </div>
          <div class="stat-card">
            <div class="card-title">Dibatalkan</div>
            <div class="stat-value"><?= number_format($cancelledCount, 0, ',', '.') ?></div>
            <div class="meta-desc">Kunjungan dibatalkan</div>
          </div>
        </div>

        <!-- Main Data Table Panel -->
        <div class="data-panel">
          <div class="panel-header">
            <div class="panel-title-group">
              <h3>Antrean Harian</h3>
              <p><?= date('d F Y') ?> · <?= $totalQueueToday ?> kunjungan terdaftar · Semua status</p>
            </div>
            <button type="button" class="btn-primary-small" onclick="openModalRegistration()">+ Registrasi Kunjungan Baru</button>
          </div>

          <!-- Filters -->
          <div class="panel-filters">
            <div class="filter-row">
              <div class="filter-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari Nama Pasien / No. RM" class="search-input">
              </div>
              <div class="filter-select">
                <span>Dokter: Semua Dokter</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="filter-select">
                <span>Tipe Kunjungan: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="filter-date">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                <span><?= date('d M Y') ?></span>
              </div>
            </div>
          </div>

          <!-- Table Data -->
          <div class="table-responsive">
            <table class="data-table queue-table">
              <thead>
                <tr>
                  <th width="15%">No. Registrasi</th>
                  <th width="10%">Waktu</th>
                  <th width="18%">Identitas Pasien</th>
                  <th width="15%">Dokter Tujuan</th>
                  <th width="12%">Tipe Kunjungan</th>
                  <th width="12%">Keluhan Awal</th>
                  <th width="10%">Status Antrean</th>
                  <th width="8%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$queue_list): ?>
                  <tr>
                    <td colspan="8" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data antrean kunjungan untuk hari ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($queue_list as $q): ?>
                    <tr>
                      <td><div class="td-reg"><?= h($q['registration_number']) ?></div><div class="td-sub">Hari ini</div></td>
                      <td><div class="td-main"><?= h(date('H:i:s', strtotime($q['registration_date']))) ?></div><div class="td-sub">WIB</div></td>
                      <td><div class="td-main"><?= h($q['full_name']) ?></div><div class="td-sub">RM <?= h($q['medical_record_number'] ?? '-') ?></div></td>
                      <td><div class="td-desc">dr. <?= h($q['specialization']) ?></div></td>
                      <td><span class="type-badge"><?= h($q['visit_type']) ?></span></td>
                      <td><div class="td-desc"><?= h($q['chief_complaint'] ?? '-') ?></div></td>
                      <td><?= getQueueBadge($q['status']) ?></td>
                      <td>
                        <div class="action-links">
                          <?php if (!in_array($q['status'], ['completed', 'cancelled'], true)): ?>
                            <a href="javascript:void(0)" class="link-primary" onclick='openModalPeriksa(<?= json_encode($q, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>Periksa</a>
                            <a href="javascript:void(0)" class="link-danger" onclick="openModalCancelReg(<?= (int)$q['registration_id'] ?>, '<?= h(addslashes($q['registration_number'])) ?>', '<?= h(addslashes($q['full_name'])) ?>')">Batal</a>
                          <?php else: ?>
                            <span class="td-sub-small"><?= $q['status'] === 'completed' ? 'Selesai' : 'Dibatalkan' ?></span>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="pagination-footer">
            <div class="page-info">Menampilkan 1–<?= count($queue_list) ?> dari <?= $totalQueueToday ?> kunjungan hari ini</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>

        <div class="info-note">
          ⓘ Pasien emergency diprioritaskan. Pasien selesai atau dibatalkan tidak dapat dipanggil kembali.
        </div>
      </div>

      <!-- ============================================== -->
      <!-- TAB KONTEN 2: MASTER DATA PASIEN               -->
      <!-- ============================================== -->
      <div id="content-master" class="d-none">
        
        <div class="data-panel" style="margin-top: 24px;">
          <div class="panel-header">
            <div class="panel-title-group">
              <h3>Daftar Pasien Klinik</h3>
              <p><?= $totalPatients ?> pasien terdaftar · Nomor rekam medis khusus klinik ini</p>
            </div>
            <button type="button" class="btn-primary-small" onclick="openModalPatient()">+ Tambah Pasien Baru</button>
          </div>

          <!-- Filters -->
          <div class="panel-filters">
            <div class="filter-row">
              <div class="filter-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari Nama, NIK, No. RM" class="search-input">
              </div>
              <div class="filter-select" style="min-width: 255px;">
                <span>Golongan Darah: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
            </div>
          </div>

          <!-- Table Data -->
          <div class="table-responsive">
            <table class="data-table queue-table">
              <thead>
                <tr>
                  <th width="12%">No. RM Klinik</th>
                  <th width="20%">Nama Lengkap Pasien</th>
                  <th width="15%">NIK</th>
                  <th width="12%">Tgl Lahir</th>
                  <th width="5%">L/P</th>
                  <th width="8%">Gol. Darah</th>
                  <th width="10%">Alergi</th>
                  <th width="10%">Status Akun</th>
                  <th width="8%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$patient_list): ?>
                  <tr>
                    <td colspan="9" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data pasien terdaftar di klinik ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($patient_list as $p): ?>
                    <tr>
                      <td><div class="td-reg"><?= h($p['medical_record_number'] ?? '-') ?></div></td>
                      <td><div class="td-main"><?= h($p['full_name']) ?></div><div class="td-sub-small">Pasien Klinik</div></td>
                      <td><div class="td-reg text-gray"><?= h($p['nik'] ?? '-') ?></div></td>
                      <td><div class="td-desc"><?= h($p['birth_date'] ? date('d M Y', strtotime($p['birth_date'])) : '-') ?></div></td>
                      <td><div class="td-desc"><?= h($p['gender'] ?? '-') ?></div></td>
                      <td><div class="td-desc"><?= h($p['blood_type'] ?? '-') ?></div></td>
                      <td><span class="type-badge"><?= h($p['allergies'] ?? 'Tidak ada') ?></span></td>
                      <td>
                        <span class="status-badge status-green"><?= h(ucfirst($p['link_status'] ?? 'Linked')) ?></span>
                      </td>
                      <td>
                        <div class="action-links">
                          <a href="#" class="link-primary">Edit</a>
                          <a href="#" class="link-primary" style="font-weight: 400;">Riwayat RM</a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <!-- Pagination -->
          <div class="pagination-footer">
            <div class="page-info">Menampilkan 1–<?= count($patient_list) ?> dari <?= $totalPatients ?> pasien terdaftar</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>

        <div class="info-note" style="margin-top: 24px;">
          ⓘ Periksa NIK dan No. RM sebelum menambahkan pasien untuk menghindari data ganda. Data pasien hanya dapat diakses staf berwenang.
        </div>
      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto;">
        <span>© <?= date('Y') ?> <?= h($_SESSION['clinic_name']) ?> · Pendaftaran & Pasien</span>
        <span id="clock-main"></span>
      </footer>

    </main>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 1: FORM REGISTRASI KUNJUNGAN BARU                        -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalRegistration">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean">Registrasi Kunjungan Baru</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalRegistration()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="patient-registration.php" id="formRegistration">
        <input type="hidden" name="action" value="add_registration">

        <div class="modal-body-scroll">
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="reg_number_preview">No. Registrasi (Otomatis)</label>
              <input type="text" id="reg_number_preview" class="input-control" value="<?= h($nextRegPreview) ?>" readonly>
            </div>
            <div class="form-field-group">
              <label for="reg_date">Tanggal & Waktu Kunjungan *</label>
              <input type="datetime-local" name="registration_date" id="reg_date" class="input-control" value="<?= date('Y-m-d\TH:i') ?>" required>
            </div>
          </div>

          <div class="form-field-group">
            <label for="reg_patient_id">Pilih Pasien *</label>
            <select name="patient_id" id="reg_patient_id" class="input-control" required>
              <option value="">-- Pilih Pasien Terdaftar --</option>
              <?php foreach ($patientOptions as $po): ?>
                <option value="<?= (int)$po['id'] ?>">
                  <?= h($po['medical_record_number']) ?> · <?= h($po['full_name']) ?><?= !empty($po['national_id']) ? ' (NIK: ' . h($po['national_id']) . ')' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <?php if (empty($patientOptions)): ?>
              <small style="font-size: 11.5px; color: #B45309; margin-top: 2px;">
                Belum ada pasien terdaftar di klinik ini. Silakan klik <a href="javascript:void(0)" onclick="closeModalRegistration(); openModalPatient();" style="color: var(--primary); font-weight: 600;">+ Tambah Pasien Baru</a> terlebih dahulu.
              </small>
            <?php endif; ?>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="reg_doctor_id">Dokter Tujuan</label>
              <select name="doctor_id" id="reg_doctor_id" class="input-control">
                <option value="0">-- Poli Umum / Dokter Jaga --</option>
                <?php foreach ($doctorOptions as $do): ?>
                  <option value="<?= (int)$do['id'] ?>">
                    <?= h($do['name']) ?> (<?= h($do['specialization']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-field-group">
              <label for="reg_visit_type">Tipe Kunjungan *</label>
              <select name="visit_type" id="reg_visit_type" class="input-control" required>
                <option value="general">Umum (General)</option>
                <option value="follow_up">Kontrol / Lanjutan (Follow Up)</option>
                <option value="emergency">Gawat Darurat (Emergency)</option>
                <option value="other">Lainnya (Other)</option>
              </select>
            </div>
          </div>

          <div class="form-field-group">
            <label for="reg_status">Status Antrean Awal</label>
            <select name="status" id="reg_status" class="input-control">
              <option value="waiting">Menunggu Dipanggil (Waiting)</option>
              <option value="registered">Terdaftar (Registered)</option>
            </select>
          </div>

          <div class="form-field-group">
            <label for="reg_complaint">Keluhan Awal</label>
            <textarea name="complaint" id="reg_complaint" class="input-control" rows="2" placeholder="Tuliskan keluhan utama atau alasan kunjungan pasien..."></textarea>
          </div>
        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalRegistration()">Batal</button>
          <button type="submit" class="btn-modal-save">+ Daftarkan Kunjungan</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 2: FORM TAMBAH PASIEN BARU                               -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalPatient">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean">Tambah Pasien Baru</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalPatient()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="patient-registration.php" id="formPatient">
        <input type="hidden" name="action" value="add_patient">

        <div class="modal-body-scroll">
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="pat_mrn">No. Rekam Medis (No. RM)</label>
              <input type="text" name="medical_record_number" id="pat_mrn" class="input-control" value="<?= h($nextMrnPreview) ?>" placeholder="Otomatis jika dikosongkan">
            </div>
            <div class="form-field-group">
              <label for="pat_nik">NIK (Nomor Induk Kependudukan)</label>
              <input type="text" name="national_id" id="pat_nik" class="input-control" placeholder="16 digit NIK (opsional)" maxlength="40">
            </div>
          </div>

          <div class="form-field-group">
            <label for="pat_full_name">Nama Lengkap Pasien *</label>
            <input type="text" name="full_name" id="pat_full_name" class="input-control" placeholder="Contoh: Siti Aminah" required>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="pat_birth_date">Tanggal Lahir</label>
              <input type="date" name="birth_date" id="pat_birth_date" class="input-control" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-field-group">
              <label for="pat_sex">Jenis Kelamin *</label>
              <select name="sex" id="pat_sex" class="input-control" required>
                <option value="male">Laki-laki</option>
                <option value="female">Perempuan</option>
                <option value="other">Lainnya</option>
                <option value="unknown">Tidak Diketahui</option>
              </select>
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="pat_blood_type">Golongan Darah</label>
              <select name="blood_type" id="pat_blood_type" class="input-control">
                <option value="unknown">Belum Diketahui</option>
                <option value="A">A</option>
                <option value="B">B</option>
                <option value="AB">AB</option>
                <option value="O">O</option>
              </select>
            </div>
            <div class="form-field-group">
              <label for="pat_allergies">Riwayat Alergi</label>
              <input type="text" name="allergies" id="pat_allergies" class="input-control" placeholder="Contoh: Tidak ada / Penisilin">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="pat_ec_name">Nama Kontak Darurat</label>
              <input type="text" name="emergency_contact_name" id="pat_ec_name" class="input-control" placeholder="Nama keluarga / wali">
            </div>
            <div class="form-field-group">
              <label for="pat_ec_phone">Telepon Kontak Darurat</label>
              <input type="text" name="emergency_contact_phone" id="pat_ec_phone" class="input-control" placeholder="08xxxxxxxxxx">
            </div>
          </div>

          <div class="form-field-group">
            <label for="pat_address">Alamat Lengkap</label>
            <textarea name="address" id="pat_address" class="input-control" rows="2" placeholder="Alamat tempat tinggal pasien..."></textarea>
          </div>
        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalPatient()">Batal</button>
          <button type="submit" class="btn-modal-save">+ Simpan Pasien Baru</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 3: FORM PEMERIKSAAN & TANDA VITAL (PERIKSA)              -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalPeriksa">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean">Pemeriksaan Kunjungan Pasien</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalPeriksa()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="patient-registration.php" id="formPeriksa">
        <input type="hidden" name="action" value="examine_registration">
        <input type="hidden" name="registration_id" id="periksa_reg_id" value="0">

        <div class="modal-body-scroll">
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label>No. Registrasi</label>
              <input type="text" id="periksa_reg_number" class="input-control" readonly>
            </div>
            <div class="form-field-group">
              <label>Pasien & No. RM</label>
              <input type="text" id="periksa_patient_info" class="input-control" readonly>
            </div>
          </div>

          <div class="form-field-group">
            <label>Keluhan Awal</label>
            <input type="text" id="periksa_complaint" class="input-control" readonly>
          </div>

          <div class="form-field-group">
            <label for="periksa_status">Status Pemeriksaan *</label>
            <select name="status" id="periksa_status" class="input-control" required>
              <option value="in_progress">Sedang Diperiksa (In Progress)</option>
              <option value="completed">Selesai Diperiksa (Completed)</option>
            </select>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="periksa_height">Tinggi Badan (cm)</label>
              <input type="number" name="height_cm" id="periksa_height" class="input-control" step="0.1" min="0" placeholder="Contoh: 165">
            </div>
            <div class="form-field-group">
              <label for="periksa_weight">Berat Badan (kg)</label>
              <input type="number" name="weight_kg" id="periksa_weight" class="input-control" step="0.1" min="0" placeholder="Contoh: 60">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="periksa_temp">Suhu Tubuh (°C)</label>
              <input type="number" name="temperature_c" id="periksa_temp" class="input-control" step="0.1" min="30" max="45" placeholder="Contoh: 36.5">
            </div>
            <div class="form-field-group">
              <label for="periksa_spo2">Saturasi Oksigen (%)</label>
              <input type="number" name="oxygen_saturation" id="periksa_spo2" class="input-control" step="0.1" min="0" max="100" placeholder="Contoh: 98">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="periksa_sys">Tekanan Darah Sistolik (mmHg)</label>
              <input type="number" name="systolic_bp" id="periksa_sys" class="input-control" min="0" placeholder="Contoh: 120">
            </div>
            <div class="form-field-group">
              <label for="periksa_dia">Tekanan Darah Diastolik (mmHg)</label>
              <input type="number" name="diastolic_bp" id="periksa_dia" class="input-control" min="0" placeholder="Contoh: 80">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="periksa_pulse">Denyut Nadi (bpm)</label>
              <input type="number" name="pulse_bpm" id="periksa_pulse" class="input-control" min="0" placeholder="Contoh: 80">
            </div>
            <div class="form-field-group">
              <label for="periksa_resp">Laju Pernapasan (/menit)</label>
              <input type="number" name="respiratory_rate" id="periksa_resp" class="input-control" min="0" placeholder="Contoh: 20">
            </div>
          </div>

          <div class="form-field-group">
            <label for="periksa_notes">Catatan Pemeriksaan / Triase</label>
            <textarea name="notes" id="periksa_notes" class="input-control" rows="2" placeholder="Catatan hasil pemeriksaan awal atau triase..."></textarea>
          </div>
        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalPeriksa()">Batal</button>
          <button type="submit" class="btn-modal-save">Simpan Pemeriksaan</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 4: KONFIRMASI PEMBATALAN KUNJUNGAN (BATAL)               -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalCancelReg">
    <div class="modal-dialog" style="max-width: 480px;">
      <div class="modal-header-clean" style="border-bottom-color: #FEE2E2;">
        <h3 class="modal-title-clean" style="color: #DC2626; display: flex; align-items: center; gap: 8px;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
          Batalkan Kunjungan
        </h3>
        <button type="button" class="modal-close-btn" onclick="closeModalCancelReg()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="patient-registration.php" id="formCancelReg">
        <input type="hidden" name="action" value="cancel_registration">
        <input type="hidden" name="registration_id" id="cancel_reg_id" value="0">

        <div class="modal-body-scroll" style="gap: 14px;">
          <div style="font-size: 14px; color: #1E293B; line-height: 1.6;">
            Apakah Anda yakin ingin membatalkan antrean kunjungan pasien <strong id="cancel_patient_name"></strong> (<span id="cancel_reg_number" style="font-family: monospace; font-weight: 600;"></span>)?
          </div>
          <div style="background: #FEF2F2; border-left: 4px solid #EF4444; padding: 12px 14px; border-radius: 6px; font-size: 12.5px; color: #991B1B; line-height: 1.5;">
            <strong>Peringatan:</strong> Pasien yang sudah dibatalkan tidak dapat dipanggil kembali ke antrean pemeriksaan.
          </div>
        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalCancelReg()">Tutup</button>
          <button type="submit" class="btn-modal-delete">Ya, Batalkan Kunjungan</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SCRIPT -->
  <script>
    // JS Jam Digital Real-time
    function updateClock() {
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date()) + ' WIB';
        document.getElementById('clock-top').textContent = s;
        document.getElementById('clock-sidebar').textContent = s;
        document.getElementById('clock-main').textContent = s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    // JS Tab Switching
    function switchTab(event, tabName) {
      if (event) event.preventDefault();

      const tabQueue = document.getElementById('tab-queue');
      const tabMaster = document.getElementById('tab-master');
      
      const contentQueue = document.getElementById('content-queue');
      const contentMaster = document.getElementById('content-master');

      if (tabName === 'queue') {
        tabQueue.classList.add('active');
        tabMaster.classList.remove('active');
        
        contentQueue.classList.remove('d-none');
        contentMaster.classList.add('d-none');
      } else if (tabName === 'master') {
        tabMaster.classList.add('active');
        tabQueue.classList.remove('active');
        
        contentMaster.classList.remove('d-none');
        contentQueue.classList.add('d-none');
      }
    }

    // Auto switch tab berdasarkan URL parameter ?tab=...
    window.addEventListener('DOMContentLoaded', function() {
      const urlParams = new URLSearchParams(window.location.search);
      const tabParam = urlParams.get('tab');
      if (tabParam === 'master') {
        switchTab(null, 'master');
      }
    });

    // Modal Registrasi Kunjungan Baru
    function openModalRegistration() {
      document.getElementById('modalRegistration').classList.remove('d-none');
    }
    function closeModalRegistration() {
      document.getElementById('modalRegistration').classList.add('d-none');
    }

    // Modal Tambah Pasien Baru
    function openModalPatient() {
      document.getElementById('modalPatient').classList.remove('d-none');
    }
    function closeModalPatient() {
      document.getElementById('modalPatient').classList.add('d-none');
    }

    // Modal Periksa Kunjungan
    function openModalPeriksa(data) {
      document.getElementById('periksa_reg_id').value = data.registration_id || 0;
      document.getElementById('periksa_reg_number').value = data.registration_number || '-';
      document.getElementById('periksa_patient_info').value = (data.full_name || '-') + ' (RM: ' + (data.medical_record_number || '-') + ')';
      document.getElementById('periksa_complaint').value = data.chief_complaint || '-';
      document.getElementById('periksa_status').value = (data.status === 'in_progress') ? 'completed' : 'in_progress';
      document.getElementById('periksa_height').value = data.height_cm ?? '';
      document.getElementById('periksa_weight').value = data.weight_kg ?? '';
      document.getElementById('periksa_temp').value = data.temperature_c ?? '';
      document.getElementById('periksa_spo2').value = data.oxygen_saturation ?? '';
      document.getElementById('periksa_sys').value = data.systolic_bp ?? '';
      document.getElementById('periksa_dia').value = data.diastolic_bp ?? '';
      document.getElementById('periksa_pulse').value = data.pulse_bpm ?? '';
      document.getElementById('periksa_resp').value = data.respiratory_rate ?? '';
      document.getElementById('periksa_notes').value = data.visit_notes ?? '';
      document.getElementById('modalPeriksa').classList.remove('d-none');
    }
    function closeModalPeriksa() {
      document.getElementById('modalPeriksa').classList.add('d-none');
    }

    // Modal Batalkan Kunjungan
    function openModalCancelReg(regId, regNum, patientName) {
      document.getElementById('cancel_reg_id').value = regId;
      document.getElementById('cancel_reg_number').textContent = regNum;
      document.getElementById('cancel_patient_name').textContent = patientName;
      document.getElementById('modalCancelReg').classList.remove('d-none');
    }
    function closeModalCancelReg() {
      document.getElementById('modalCancelReg').classList.add('d-none');
    }

    // Tutup modal saat klik overlay latar belakang
    window.addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.add('d-none');
      }
    });
  </script>

</body>
</html>