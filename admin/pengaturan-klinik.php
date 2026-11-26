<?php
session_start();

// 1. Data login (Bypass sementara jika sistem auth belum siap)
if (!isset($_SESSION['clinic_id'])) {
    $_SESSION['clinic_id']   = 1;
    $_SESSION['clinic_name'] = 'Klinik Sehat Bersama';
    $_SESSION['clinic_code'] = 'klinik-sehat';
    $_SESSION['user_name']   = 'Dahlia Cahya';
    $_SESSION['user_id']     = 1;
    $_SESSION['role_name']   = 'admin';
}

// 2. Panggil koneksi database
require_once __DIR__ . '/../config/database.php';

// 3. Fungsi Helper Mandiri
function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

$clinic_id = (int)$_SESSION['clinic_id'];
$userId    = (int)($_SESSION['user_id'] ?? 1);
$roleName  = strtolower((string)($_SESSION['role_name'] ?? 'admin'));

// Verifikasi hak akses admin di database jika user_roles tersedia
$isAdmin = ($roleName === 'admin');
$stmtRole = $conn->prepare("
    SELECT r.name 
    FROM user_roles ur 
    JOIN roles r ON r.id = ur.role_id 
    WHERE ur.user_id = ? AND ur.clinic_id = ? AND r.name = 'admin'
    LIMIT 1
");
if ($stmtRole) {
    $stmtRole->bind_param('ii', $userId, $clinic_id);
    $stmtRole->execute();
    if ($stmtRole->get_result()->fetch_assoc()) {
        $isAdmin = true;
    }
}

// 4. PROSES SIMPAN PENGATURAN (POST - PRG PATTERN)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        if (!$isAdmin) {
            $_SESSION['flash_error'] = 'Akses ditolak. Hanya Admin Klinik yang dapat mengubah pengaturan.';
            header('Location: pengaturan-klinik.php');
            exit;
        }

        $clinicName   = trim($_POST['name'] ?? '');
        $phone        = trim($_POST['phone'] ?? '');
        $email        = trim($_POST['email'] ?? '');
        $address      = trim($_POST['address'] ?? '');
        $timezone     = trim($_POST['timezone'] ?? 'Asia/Jakarta');

        $description  = trim($_POST['clinic_description'] ?? '');
        $opDays       = trim($_POST['operating_days'] ?? 'Senin – Sabtu');
        $opWeekday    = trim($_POST['operating_hours_weekday'] ?? '08:00 – 20:00');
        $opWeekend    = trim($_POST['operating_hours_weekend'] ?? '08:00 – 14:00');
        $mrnPrefix    = strtoupper(trim($_POST['mrn_prefix'] ?? 'RM'));
        $regPrefix    = strtoupper(trim($_POST['reg_prefix'] ?? 'REG'));
        $receiptSize  = trim($_POST['receipt_size'] ?? 'thermal_80');
        $receiptHead  = trim($_POST['receipt_header'] ?? '');
        $receiptFoot  = trim($_POST['receipt_footer'] ?? '');
        $autoCall     = isset($_POST['queue_auto_call']) ? '1' : '0';

        $allowedTz = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];
        if (!in_array($timezone, $allowedTz, true)) {
            $timezone = 'Asia/Jakarta';
        }
        $allowedSizes = ['thermal_80', 'thermal_58', 'a4', 'a5'];
        if (!in_array($receiptSize, $allowedSizes, true)) {
            $receiptSize = 'thermal_80';
        }

        // Validasi input wajib
        if ($clinicName === '' || $phone === '' || $address === '') {
            $_SESSION['flash_error'] = 'Nama Klinik, Nomor Telepon, dan Alamat Lengkap wajib diisi.';
            header('Location: pengaturan-klinik.php');
            exit;
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Format alamat email klinik tidak valid.';
            header('Location: pengaturan-klinik.php');
            exit;
        }

        // Ambil logo saat ini (jika ada)
        $currentLogo = '';
        $stmtGetLogo = $conn->prepare("SELECT setting_value FROM system_settings WHERE clinic_id = ? AND setting_key = 'clinic_logo' LIMIT 1");
        if ($stmtGetLogo) {
            $stmtGetLogo->bind_param('i', $clinic_id);
            $stmtGetLogo->execute();
            $logoRow = $stmtGetLogo->get_result()->fetch_assoc();
            if ($logoRow) {
                $currentLogo = (string)$logoRow['setting_value'];
            }
        }

        // Proses upload logo baru jika ada file diunggah
        $newLogoPath = $currentLogo;
        if (isset($_FILES['clinic_logo']) && $_FILES['clinic_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['clinic_logo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $_SESSION['flash_error'] = 'Terjadi kesalahan saat mengunggah file logo.';
                header('Location: pengaturan-klinik.php');
                exit;
            }
            if ($file['size'] > 2 * 1024 * 1024) {
                $_SESSION['flash_error'] = 'Ukuran file logo maksimal 2 MB.';
                header('Location: pengaturan-klinik.php');
                exit;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            if (!in_array($ext, $allowedExt, true)) {
                $_SESSION['flash_error'] = 'Format logo hanya mendukung JPG, PNG, atau WEBP.';
                header('Location: pengaturan-klinik.php');
                exit;
            }

            $logoDir = __DIR__ . '/../uploads/logos';
            if (!is_dir($logoDir) && !mkdir($logoDir, 0775, true) && !is_dir($logoDir)) {
                $_SESSION['flash_error'] = 'Gagal membuat folder penyimpanan logo.';
                header('Location: pengaturan-klinik.php');
                exit;
            }

            $fileName = 'clinic_' . $clinic_id . '_' . time() . '.' . $ext;
            $destPath = $logoDir . '/' . $fileName;
            if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                $_SESSION['flash_error'] = 'Gagal menyimpan file logo ke server.';
                header('Location: pengaturan-klinik.php');
                exit;
            }
            $newLogoPath = 'uploads/logos/' . $fileName;
        }

        $emailParam = ($email !== '') ? $email : null;

        $conn->begin_transaction();
        try {
            // 1. Update tabel clinics
            $stmtClinic = $conn->prepare("
                UPDATE clinics 
                SET name = ?, address = ?, phone = ?, email = ?, timezone = ?
                WHERE id = ?
            ");
            $stmtClinic->bind_param('sssssi', $clinicName, $address, $phone, $emailParam, $timezone, $clinic_id);
            $stmtClinic->execute();

            // 2. Upsert tabel system_settings
            $settingsMap = [
                'clinic_logo'             => [$newLogoPath, 'Path file logo klinik'],
                'clinic_description'      => [$description, 'Deskripsi atau tagline klinik'],
                'operating_days'          => [$opDays, 'Hari operasional klinik'],
                'operating_hours_weekday' => [$opWeekday, 'Jam operasional hari kerja'],
                'operating_hours_weekend' => [$opWeekend, 'Jam operasional akhir pekan'],
                'timezone'                => [$timezone, 'Zona waktu operasional klinik'],
                'mrn_prefix'              => [$mrnPrefix ?: 'RM', 'Prefix nomor rekam medis'],
                'reg_prefix'              => [$regPrefix ?: 'REG', 'Prefix nomor registrasi kunjungan'],
                'receipt_size'            => [$receiptSize, 'Ukuran kuitansi pembayaran'],
                'receipt_header'          => [$receiptHead ?: $clinicName, 'Header kuitansi pembayaran klinik'],
                'receipt_footer'          => [$receiptFoot, 'Footer kuitansi pembayaran klinik'],
                'queue_auto_call'         => [$autoCall, 'Aktifkan pemanggilan antrean otomatis'],
            ];

            $stmtSet = $conn->prepare("
                INSERT INTO system_settings (clinic_id, setting_key, setting_value, description, updated_by)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    setting_value = VALUES(setting_value),
                    description   = VALUES(description),
                    updated_by    = VALUES(updated_by)
            ");
            foreach ($settingsMap as $key => $meta) {
                $val  = (string)$meta[0];
                $desc = (string)$meta[1];
                $stmtSet->bind_param('isssi', $clinic_id, $key, $val, $desc, $userId);
                $stmtSet->execute();
            }

            // 3. Catat Audit Log
            $auditDetails = json_encode([
                'clinic_name' => $clinicName,
                'phone'       => $phone,
                'email'       => $emailParam,
                'timezone'    => $timezone,
                'receipt_size'=> $receiptSize,
            ]);
            $stmtAudit = $conn->prepare("
                INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details)
                VALUES (?, ?, 'UPDATE_CLINIC_SETTINGS', 'clinics', ?, ?)
            ");
            if ($stmtAudit) {
                $stmtAudit->bind_param('iiis', $clinic_id, $userId, $clinic_id, $auditDetails);
                $stmtAudit->execute();
            }

            $conn->commit();

            // 4. Sinkronisasi Session agar Topbar & Sidebar langsung berubah
            $_SESSION['clinic_name'] = $clinicName;
            $_SESSION['timezone']    = $timezone;
            $_SESSION['flash_success'] = 'Pengaturan klinik berhasil disimpan.';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Gagal menyimpan pengaturan klinik: ' . $e->getMessage();
        }

        header('Location: pengaturan-klinik.php');
        exit;
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 5. LOAD DATA KLINIK AKTIF & SYSTEM SETTINGS
$clinicData = [
    'name'        => $_SESSION['clinic_name'] ?? 'Klinik Sehat Bersama',
    'clinic_code' => $_SESSION['clinic_code'] ?? 'klinik-sehat',
    'address'     => '',
    'phone'       => '',
    'email'       => '',
    'timezone'    => 'Asia/Jakarta',
    'region_id'   => null,
    'updated_at'  => null,
];
$stmtC = $conn->prepare("SELECT * FROM clinics WHERE id = ? LIMIT 1");
if ($stmtC) {
    $stmtC->bind_param('i', $clinic_id);
    $stmtC->execute();
    $rowC = $stmtC->get_result()->fetch_assoc();
    if ($rowC) {
        $clinicData = $rowC;
        $_SESSION['clinic_name'] = $rowC['name'];
        $_SESSION['clinic_code'] = $rowC['clinic_code'];
        $_SESSION['timezone']    = $rowC['timezone'];
    }
}

// Ambil hierarki wilayah (jika region_id terisi)
$regionLabel = 'Belum ditautkan';
if (!empty($clinicData['region_id'])) {
    $names = [];
    $currRegId = (int)$clinicData['region_id'];
    $depth = 0;
    while ($currRegId > 0 && $depth < 5) {
        $stmtReg = $conn->prepare("SELECT parent_id, name FROM regions WHERE id = ? LIMIT 1");
        $stmtReg->bind_param('i', $currRegId);
        $stmtReg->execute();
        $rRow = $stmtReg->get_result()->fetch_assoc();
        if (!$rRow) break;
        $names[] = $rRow['name'];
        $currRegId = (int)($rRow['parent_id'] ?? 0);
        $depth++;
    }
    if (!empty($names)) {
        $regionLabel = implode(', ', $names);
    }
}

// Ambil system_settings
$settings = [];
$stmtS = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE clinic_id = ?");
if ($stmtS) {
    $stmtS->bind_param('i', $clinic_id);
    $stmtS->execute();
    $resS = $stmtS->get_result();
    while ($rS = $resS->fetch_assoc()) {
        $settings[$rS['setting_key']] = $rS['setting_value'];
    }
}

$logoPath    = $settings['clinic_logo'] ?? '';
$hasLogoFile = ($logoPath !== '' && is_file(__DIR__ . '/../' . $logoPath));
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pengaturan Klinik - <?= h($clinicData['name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
  <link rel="stylesheet" href="../assets/css/settings.css?v=<?= filemtime(__DIR__ . '/../assets/css/settings.css') ?>">
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <?php require __DIR__ . '/../includes/topbar.php'; ?>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($clinicData['name'])) ?></span>
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
        <a href="staff-management.php" class="nav-item">
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
        <a href="pengaturan-klinik.php" class="nav-item active">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
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
        <div class="breadcrumb"><?= h($clinicData['name']) ?> / Pengaturan Klinik</div>
        <div class="page-title-group">
          <h1>Pengaturan Klinik</h1>
          <p>Kelola profil identitas klinik, kontak operasional, jam layanan, dan preferensi sistem tenant.</p>
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

      <form method="POST" action="pengaturan-klinik.php" enctype="multipart/form-data" class="settings-form" id="formSettings">
        <input type="hidden" name="action" value="save_settings">

        <!-- SECTION 1: PROFIL & IDENTITAS KLINIK -->
        <section class="settings-card">
          <div class="settings-card-header">
            <div>
              <h3>Profil &amp; Identitas Klinik</h3>
              <p>Informasi utama klinik yang ditampilkan pada sistem, header kuitansi, dan laporan resmi.</p>
            </div>
            <span class="badge-readonly">Tenant ID #<?= (int)$clinic_id ?></span>
          </div>

          <div class="settings-card-body">
            <!-- Upload & Preview Logo -->
            <div class="logo-upload-row">
              <div class="logo-preview-box" id="logoPreviewBox">
                <?php if ($hasLogoFile): ?>
                  <img src="../<?= h($logoPath) ?>?v=<?= time() ?>" alt="Logo Klinik" id="logoPreviewImg">
                <?php else: ?>
                  <span id="logoPreviewInitial"><?= h(strtoupper(substr($clinicData['name'], 0, 2))) ?></span>
                <?php endif; ?>
              </div>
              <div class="logo-upload-info">
                <strong>Logo Klinik</strong>
                <span>Format yang didukung: JPG, PNG, atau WEBP (maks. 2 MB).</span>
                <label class="btn-upload-logo" for="clinic_logo_input">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
                  Pilih File Logo
                </label>
                <input type="file" name="clinic_logo" id="clinic_logo_input" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" style="display: none;" onchange="previewClinicLogo(this)">
              </div>
            </div>

            <div class="settings-grid-2">
              <div class="form-field">
                <label for="clinic_name">Nama Klinik *</label>
                <input type="text" name="name" id="clinic_name" class="input-setting" value="<?= h($clinicData['name']) ?>" required>
              </div>
              <div class="form-field">
                <label for="clinic_code">Kode Tenant / Subdomain (Read-Only)</label>
                <input type="text" id="clinic_code" class="input-setting" value="<?= h($clinicData['clinic_code']) ?>" readonly>
                <span class="field-hint">Domain akses: <?= h($clinicData['clinic_code']) ?>.platform.com</span>
              </div>
            </div>

            <div class="form-field">
              <label for="clinic_description">Tagline / Deskripsi Singkat Klinik</label>
              <input type="text" name="clinic_description" id="clinic_description" class="input-setting" value="<?= h($settings['clinic_description'] ?? '') ?>" placeholder="Contoh: Layanan kesehatan keluarga terpadu dan ramah pasien">
            </div>
          </div>
        </section>

        <!-- SECTION 2: ALAMAT & KONTAK OPERASIONAL -->
        <section class="settings-card">
          <div class="settings-card-header">
            <div>
              <h3>Alamat &amp; Kontak Operasional</h3>
              <p>Informasi kontak resmi dan lokasi operasional klinik.</p>
            </div>
          </div>

          <div class="settings-card-body">
            <div class="settings-grid-2">
              <div class="form-field">
                <label for="clinic_phone">Nomor Telepon Klinik *</label>
                <input type="text" name="phone" id="clinic_phone" class="input-setting" value="<?= h($clinicData['phone'] ?? '') ?>" placeholder="Contoh: 021-7401234 / +62812..." required>
              </div>
              <div class="form-field">
                <label for="clinic_email">Email Resmi Klinik</label>
                <input type="email" name="email" id="clinic_email" class="input-setting" value="<?= h($clinicData['email'] ?? '') ?>" placeholder="admin@klinikanda.com">
              </div>
            </div>

            <div class="form-field">
              <label for="clinic_address">Alamat Lengkap Klinik *</label>
              <textarea name="address" id="clinic_address" class="input-setting" rows="2" required><?= h($clinicData['address'] ?? '') ?></textarea>
            </div>

            <div class="form-field">
              <label>Wilayah Administratif Terdaftar</label>
              <input type="text" class="input-setting" value="<?= h($regionLabel) ?>" readonly>
              <span class="field-hint">Terhubung ke referensi wilayah regional sistem (`regions`).</span>
            </div>
          </div>
        </section>

        <!-- SECTION 3: JAM OPERASIONAL & ZONA WAKTU -->
        <section class="settings-card">
          <div class="settings-card-header">
            <div>
              <h3>Jam Operasional &amp; Zona Waktu</h3>
              <p>Atur jadwal layanan rutin dan zona waktu lokal untuk pencatatan antrean serta laporan.</p>
            </div>
          </div>

          <div class="settings-card-body">
            <div class="settings-grid-3">
              <div class="form-field">
                <label for="operating_days">Hari Operasional</label>
                <input type="text" name="operating_days" id="operating_days" class="input-setting" value="<?= h($settings['operating_days'] ?? 'Senin – Sabtu') ?>" placeholder="Senin – Sabtu">
              </div>
              <div class="form-field">
                <label for="operating_hours_weekday">Jam Layanan (Senin – Jumat)</label>
                <input type="text" name="operating_hours_weekday" id="operating_hours_weekday" class="input-setting" value="<?= h($settings['operating_hours_weekday'] ?? '08:00 – 20:00') ?>" placeholder="08:00 – 20:00">
              </div>
              <div class="form-field">
                <label for="operating_hours_weekend">Jam Layanan (Akhir Pekan)</label>
                <input type="text" name="operating_hours_weekend" id="operating_hours_weekend" class="input-setting" value="<?= h($settings['operating_hours_weekend'] ?? '08:00 – 14:00') ?>" placeholder="08:00 – 14:00">
              </div>
            </div>

            <div class="form-field" style="max-width: 380px;">
              <label for="clinic_timezone">Zona Waktu Operasional</label>
              <select name="timezone" id="clinic_timezone" class="input-setting">
                <option value="Asia/Jakarta" <?= ($clinicData['timezone'] ?? 'Asia/Jakarta') === 'Asia/Jakarta' ? 'selected' : '' ?>>Asia/Jakarta (WIB · UTC+7)</option>
                <option value="Asia/Makassar" <?= ($clinicData['timezone'] ?? '') === 'Asia/Makassar' ? 'selected' : '' ?>>Asia/Makassar (WITA · UTC+8)</option>
                <option value="Asia/Jayapura" <?= ($clinicData['timezone'] ?? '') === 'Asia/Jayapura' ? 'selected' : '' ?>>Asia/Jayapura (WIT · UTC+9)</option>
              </select>
            </div>
          </div>
        </section>

        <!-- SECTION 4: PREFERENSI SISTEM, NOMOR RM & KUITANSI -->
        <section class="settings-card">
          <div class="settings-card-header">
            <div>
              <h3>Preferensi Sistem &amp; Kuitansi</h3>
              <p>Konfigurasi format penomoran rekam medis, registrasi kunjungan, serta standar cetak kuitansi.</p>
            </div>
          </div>

          <div class="settings-card-body">
            <div class="settings-grid-3">
              <div class="form-field">
                <label for="mrn_prefix">Prefix Nomor RM</label>
                <input type="text" name="mrn_prefix" id="mrn_prefix" class="input-setting" value="<?= h($settings['mrn_prefix'] ?? 'RM') ?>" maxlength="10">
              </div>
              <div class="form-field">
                <label for="reg_prefix">Prefix Nomor Registrasi</label>
                <input type="text" name="reg_prefix" id="reg_prefix" class="input-setting" value="<?= h($settings['reg_prefix'] ?? 'REG') ?>" maxlength="10">
              </div>
              <div class="form-field">
                <label for="receipt_size">Ukuran Standar Kuitansi</label>
                <select name="receipt_size" id="receipt_size" class="input-setting">
                  <option value="thermal_80" <?= ($settings['receipt_size'] ?? 'thermal_80') === 'thermal_80' ? 'selected' : '' ?>>Thermal 80mm</option>
                  <option value="thermal_58" <?= ($settings['receipt_size'] ?? '') === 'thermal_58' ? 'selected' : '' ?>>Thermal 58mm</option>
                  <option value="a5" <?= ($settings['receipt_size'] ?? '') === 'a5' ? 'selected' : '' ?>>Kertas A5</option>
                  <option value="a4" <?= ($settings['receipt_size'] ?? '') === 'a4' ? 'selected' : '' ?>>Kertas A4</option>
                </select>
              </div>
            </div>

            <div class="settings-grid-2">
              <div class="form-field">
                <label for="receipt_header">Header Kuitansi Pembayaran</label>
                <input type="text" name="receipt_header" id="receipt_header" class="input-setting" value="<?= h($settings['receipt_header'] ?? $clinicData['name']) ?>">
              </div>
              <div class="form-field">
                <label for="receipt_footer">Footer / Pesan Penutup Kuitansi</label>
                <input type="text" name="receipt_footer" id="receipt_footer" class="input-setting" value="<?= h($settings['receipt_footer'] ?? 'Terima kasih atas kunjungan Anda. Semoga lekas sembuh.') ?>">
              </div>
            </div>

            <label class="setting-toggle-box" style="cursor: pointer;">
              <div class="setting-toggle-text">
                <strong>Pemanggilan Antrean Otomatis</strong>
                <span>Aktifkan notifikasi otomatis saat dokter memulai pemeriksaan pasien dari antrean.</span>
              </div>
              <input type="checkbox" name="queue_auto_call" value="1" <?= ($settings['queue_auto_call'] ?? '1') === '1' ? 'checked' : '' ?> style="width: 18px; height: 18px; accent-color: var(--primary);">
            </label>
          </div>
        </section>

        <!-- ACTION BAR: SIMPAN / RESET -->
        <div class="settings-actions-bar">
          <div class="actions-meta">
            Terakhir diperbarui: <strong><?= !empty($clinicData['updated_at']) ? h(date('d M Y, H:i', strtotime($clinicData['updated_at']))) . ' WIB' : '-' ?></strong>
          </div>
          <div class="actions-buttons">
            <button type="reset" class="btn-reset-settings" onclick="resetLogoPreview()">Reset / Batal</button>
            <button type="submit" class="btn-save-settings">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
              Simpan Perubahan
            </button>
          </div>
        </div>
      </form>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto; display: flex; justify-content: space-between;">
        <span>© <?= date('Y') ?> <?= h($clinicData['name']) ?> · Panel Kontrol Admin</span>
        <span>Zona waktu <?= h($clinicData['timezone'] ?? 'Asia/Jakarta') ?></span>
      </footer>

    </main>
  </div>

  <!-- SCRIPT -->
  <script>
    function updateClock() {
        let tz = <?= json_encode($clinicData['timezone'] ?? 'Asia/Jakarta') ?>;
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: tz }).format(new Date()) + ' WIB';
        const cTop = document.getElementById('clock-top');
        const cSide = document.getElementById('clock-sidebar');
        if (cTop) cTop.textContent = s;
        if (cSide) cSide.textContent = s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    const originalLogoHtml = document.getElementById('logoPreviewBox').innerHTML;

    function previewClinicLogo(input) {
      if (input.files && input.files[0]) {
        const file = input.files[0];
        if (file.size > 2 * 1024 * 1024) {
          alert('Ukuran file maksimal 2 MB.');
          input.value = '';
          return;
        }
        const reader = new FileReader();
        reader.onload = function(e) {
          document.getElementById('logoPreviewBox').innerHTML = '<img src="' + e.target.result + '" alt="Preview Logo">';
        };
        reader.readAsDataURL(file);
      }
    }

    function resetLogoPreview() {
      document.getElementById('logoPreviewBox').innerHTML = originalLogoHtml;
    }
  </script>

</body>
</html>
