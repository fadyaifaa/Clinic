<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

$err = '';
$registrationSuccess = false;
$successClinicCode = '';
$successClinicName = '';
$successAdminEmail = '';
$activeStep = 1;
$old = $_POST;

function h($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/**
 * Fungsi untuk mencari atau membuat hirarki wilayah secara aman di tabel `regions`
 */
function getOrCreateRegionId($conn, $provName, $provCode, $cityName, $cityCode, $distName, $distCode, $villName, $villCode) {
    if (!$provName || !$cityName || !$distName || !$villName) {
        return null;
    }
    
    // 1. Province
    $stmt = $conn->prepare("SELECT id FROM regions WHERE region_type='province' AND (region_code=? OR name=?) LIMIT 1");
    $stmt->bind_param('ss', $provCode, $provName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $provId = (int)$row['id'];
    } else {
        $stmtIns = $conn->prepare("INSERT INTO regions (parent_id, name, region_type, region_code) VALUES (NULL, ?, 'province', ?)");
        $stmtIns->bind_param('ss', $provName, $provCode);
        $stmtIns->execute();
        $provId = $conn->insert_id;
    }

    // 2. City / Regency
    $stmt = $conn->prepare("SELECT id FROM regions WHERE region_type='city' AND parent_id=? AND (region_code=? OR name=?) LIMIT 1");
    $stmt->bind_param('iss', $provId, $cityCode, $cityName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $cityId = (int)$row['id'];
    } else {
        $stmtIns = $conn->prepare("INSERT INTO regions (parent_id, name, region_type, region_code) VALUES (?, ?, 'city', ?)");
        $stmtIns->bind_param('iss', $provId, $cityName, $cityCode);
        $stmtIns->execute();
        $cityId = $conn->insert_id;
    }

    // 3. District
    $stmt = $conn->prepare("SELECT id FROM regions WHERE region_type='district' AND parent_id=? AND (region_code=? OR name=?) LIMIT 1");
    $stmt->bind_param('iss', $cityId, $distCode, $distName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $distId = (int)$row['id'];
    } else {
        $stmtIns = $conn->prepare("INSERT INTO regions (parent_id, name, region_type, region_code) VALUES (?, ?, 'district', ?)");
        $stmtIns->bind_param('iss', $cityId, $distName, $distCode);
        $stmtIns->execute();
        $distId = $conn->insert_id;
    }

    // 4. Village
    $stmt = $conn->prepare("SELECT id FROM regions WHERE region_type='village' AND parent_id=? AND (region_code=? OR name=?) LIMIT 1");
    $stmt->bind_param('iss', $distId, $villCode, $villName);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
        $villId = (int)$row['id'];
    } else {
        $stmtIns = $conn->prepare("INSERT INTO regions (parent_id, name, region_type, region_code) VALUES (?, ?, 'village', ?)");
        $stmtIns->bind_param('iss', $distId, $villName, $villCode);
        $stmtIns->execute();
        $villId = $conn->insert_id;
    }

    return $villId;
}

// ==========================================================
// PROSES PENDAFTARAN KLINIK (BACKEND TRANSACTION)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clinicName   = trim($_POST['clinic_name'] ?? '');
    $clinicCode   = strtolower(trim($_POST['clinic_code'] ?? ''));
    $clinicEmail  = trim($_POST['clinic_email'] ?? '');
    $clinicPhone  = trim($_POST['clinic_phone'] ?? '');
    $timezone     = trim($_POST['timezone'] ?? 'Asia/Jakarta');

    $provName     = trim($_POST['province_name'] ?? '');
    $provCode     = trim($_POST['province_id'] ?? '');
    $cityName     = trim($_POST['city_name'] ?? '');
    $cityCode     = trim($_POST['city_id'] ?? '');
    $distName     = trim($_POST['district_name'] ?? '');
    $distCode     = trim($_POST['district_id'] ?? '');
    $villName     = trim($_POST['village_name'] ?? '');
    $villCode     = trim($_POST['village_id'] ?? '');
    $address      = trim($_POST['address'] ?? '');

    $adminName    = trim($_POST['admin_name'] ?? '');
    $adminEmail   = trim($_POST['admin_email'] ?? '');
    $adminPhone   = trim($_POST['admin_phone'] ?? '');
    $password     = $_POST['password'] ?? '';
    $confirmPw    = $_POST['password_confirmation'] ?? '';
    $terms        = isset($_POST['terms']);

    // Validasi Form
    if (!$clinicName) {
        $err = 'Nama klinik wajib diisi.';
        $activeStep = 1;
    } elseif (!preg_match('/^[a-z0-9-]{3,40}$/', $clinicCode)) {
        $err = 'Kode klinik / subdomain harus 3-40 karakter (hanya huruf kecil, angka, dan tanda hubung).';
        $activeStep = 1;
    } elseif (!filter_var($clinicEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Format email klinik tidak valid.';
        $activeStep = 1;
    } elseif (!$address) {
        $err = 'Alamat lengkap klinik wajib diisi.';
        $activeStep = 2;
    } elseif (!$adminName) {
        $err = 'Nama lengkap penanggung jawab / admin wajib diisi.';
        $activeStep = 3;
    } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        $err = 'Format email login admin tidak valid.';
        $activeStep = 3;
    } elseif (!$adminPhone) {
        $err = 'Nomor telepon admin wajib diisi.';
        $activeStep = 3;
    } elseif (strlen($password) < 8) {
        $err = 'Kata sandi minimal 8 karakter.';
        $activeStep = 3;
    } elseif ($password !== $confirmPw) {
        $err = 'Konfirmasi kata sandi tidak cocok.';
        $activeStep = 3;
    } elseif (!$terms) {
        $err = 'Anda harus menyetujui Syarat & Ketentuan untuk melanjutkan.';
        $activeStep = 3;
    }

    // Pengecekan Duplikasi Kode Klinik
    if (empty($err)) {
        $checkClinic = $conn->prepare("SELECT id FROM clinics WHERE clinic_code = ? LIMIT 1");
        $checkClinic->bind_param('s', $clinicCode);
        $checkClinic->execute();
        if ($checkClinic->get_result()->num_rows > 0) {
            $err = "Kode klinik / subdomain '{$clinicCode}' sudah digunakan klinik lain. Silakan pilih kode lain.";
            $activeStep = 1;
        }
    }

    // Pengecekan Duplikasi Email Admin
    if (empty($err)) {
        $checkUser = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $checkUser->bind_param('s', $adminEmail);
        $checkUser->execute();
        if ($checkUser->get_result()->num_rows > 0) {
            $err = "Email admin '{$adminEmail}' sudah terdaftar dalam sistem. Silakan gunakan email lain atau masuk ke akun Anda.";
            $activeStep = 3;
        }
    }

    // Eksekusi Database Transaction
    if (empty($err)) {
        $conn->begin_transaction();
        try {
            // 1. Simpan atau dapatkan region_id
            $regionId = getOrCreateRegionId($conn, $provName, $provCode, $cityName, $cityCode, $distName, $distCode, $villName, $villCode);

            // Susun alamat lengkap dengan detail wilayah
            $fullAddress = $address;
            $locationParts = array_filter([
                $villName ? "Kel. {$villName}" : '',
                $distName ? "Kec. {$distName}" : '',
                $cityName,
                $provName
            ]);
            if (!empty($locationParts)) {
                $fullAddress .= ', ' . implode(', ', $locationParts);
            }

            // 2. Insert ke tabel clinics
            $stmtClinic = $conn->prepare("INSERT INTO clinics (region_id, name, clinic_code, address, phone, email, timezone, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
            $stmtClinic->bind_param('issssss', $regionId, $clinicName, $clinicCode, $fullAddress, $clinicPhone, $clinicEmail, $timezone);
            $stmtClinic->execute();
            $clinicId = $conn->insert_id;

            // 3. Insert ke tabel users (Admin)
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $stmtUser = $conn->prepare("INSERT INTO users (name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmtUser->bind_param('ssss', $adminName, $adminEmail, $adminPhone, $passwordHash);
            $stmtUser->execute();
            $userId = $conn->insert_id;

            // 4. Pastikan role 'admin' ada
            $stmtRole = $conn->prepare("SELECT id FROM roles WHERE name = 'admin' LIMIT 1");
            $stmtRole->execute();
            $roleRes = $stmtRole->get_result()->fetch_assoc();
            if ($roleRes) {
                $roleId = (int)$roleRes['id'];
            } else {
                $stmtNewRole = $conn->prepare("INSERT INTO roles (name, description) VALUES ('admin', 'Administrator sistem/klinik')");
                $stmtNewRole->execute();
                $roleId = $conn->insert_id;
            }

            // 5. Hubungkan ke user_roles
            $stmtUserRole = $conn->prepare("INSERT INTO user_roles (user_id, role_id, clinic_id) VALUES (?, ?, ?)");
            $stmtUserRole->bind_param('iii', $userId, $roleId, $clinicId);
            $stmtUserRole->execute();

            // 6. Hubungkan ke user_clinics (Primary clinic)
            $stmtUserClinic = $conn->prepare("INSERT INTO user_clinics (user_id, clinic_id, is_primary) VALUES (?, ?, 1)");
            $stmtUserClinic->bind_param('ii', $userId, $clinicId);
            $stmtUserClinic->execute();

            // 7. Inisialisasi konfigurasi awal di system_settings
            $defaultSettings = [
                ['receipt_size', 'thermal_80', 'Ukuran kuitansi standar thermal 80mm', $userId],
                ['receipt_header', $clinicName, 'Header kuitansi pembayaran klinik', $userId],
                ['receipt_footer', 'Terima kasih atas kunjungan Anda. Semoga lekas sembuh.', 'Footer kuitansi pembayaran klinik', $userId],
                ['timezone', $timezone, 'Zona waktu operasional klinik', $userId]
            ];
            $stmtSetting = $conn->prepare("INSERT INTO system_settings (clinic_id, setting_key, setting_value, description, updated_by) VALUES (?, ?, ?, ?, ?)");
            foreach ($defaultSettings as $st) {
                $stmtSetting->bind_param('isssi', $clinicId, $st[0], $st[1], $st[2], $st[3]);
                $stmtSetting->execute();
            }

            // Commit transaksi jika seluruh query berhasil
            $conn->commit();

            // Simpan sesi autentikasi awal
            $_SESSION['clinic_id']   = $clinicId;
            $_SESSION['clinic_name'] = $clinicName;
            $_SESSION['clinic_code'] = $clinicCode;
            $_SESSION['user_id']     = $userId;
            $_SESSION['user_name']   = $adminName;
            $_SESSION['user_role']   = 'admin';

            $registrationSuccess = true;
            $successClinicCode = $clinicCode;
            $successClinicName = $clinicName;
            $successAdminEmail = $adminEmail;

        } catch (Throwable $e) {
            // Rollback jika terjadi kegagalan
            $conn->rollback();
            $err = 'Pendaftaran gagal disimpan: ' . $e->getMessage();
            $activeStep = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pendaftaran Klinik Baru - KlinikKu</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">

  <style>
    .alert-error {
      padding: 14px 18px;
      background-color: #fee2e2;
      color: #991b1b;
      border: 1px solid #f87171;
      border-radius: 10px;
      margin-bottom: 24px;
      font-weight: 500;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .alert-error::before {
      content: "⚠️";
      font-size: 16px;
    }
    .success-card {
      background: #ffffff;
      border: 1px solid #bbf7d0;
      border-radius: 16px;
      padding: 40px 32px;
      text-align: center;
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
      margin-top: 20px;
    }
    .success-badge-icon {
      width: 64px;
      height: 64px;
      background: #dcfce7;
      color: #16a34a;
      border-radius: 50%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
      margin-bottom: 20px;
    }
    .success-title {
      font-family: 'Outfit', sans-serif;
      font-size: 26px;
      font-weight: 700;
      color: #0f172a;
      margin-bottom: 8px;
    }
    .success-desc {
      font-size: 14.5px;
      color: #64748b;
      max-width: 520px;
      margin: 0 auto 28px auto;
      line-height: 1.55;
    }
    .success-info-box {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 20px;
      max-width: 480px;
      margin: 0 auto 30px auto;
      text-align: left;
    }
    .success-info-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px dashed #e2e8f0;
      font-size: 14px;
    }
    .success-info-row:last-child {
      border-bottom: none;
    }
    .success-info-label {
      color: #64748b;
    }
    .success-info-val {
      font-weight: 600;
      color: #0f172a;
    }
    .code-pill {
      background: #eff6ff;
      color: #2563eb;
      padding: 4px 10px;
      border-radius: 6px;
      font-family: monospace;
      font-size: 14px;
      border: 1px solid #bfdbfe;
    }
    .success-actions {
      display: flex;
      gap: 14px;
      justify-content: center;
    }
    .redirect-timer {
      font-size: 12.5px;
      color: #94a3b8;
      margin-top: 14px;
    }
  </style>
</head>
<body class="bg-light">

  <!-- Navbar -->
  <header class="navbar">
    <a href="index.php" class="logo-container">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="white" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      </div>
      <div class="logo-text">KlinikKu</div>
    </a>
    <div class="nav-actions">
      <a href="login.php" class="btn-outline-blue">Masuk (Login)</a>
    </div>
  </header>

  <!-- Main Content -->
  <main class="register-container">
    
    <!-- Page Header -->
    <div class="register-header">
      <h1 class="register-title">Pendaftaran Klinik Baru</h1>
      <p class="register-subtitle">Setiap klinik memiliki ruang kerja dan data terpisah. Anda akan mendaftarkan tenant baru untuk sistem informasi klinik mandiri.</p>
    </div>

    <?php if ($registrationSuccess): ?>
      <!-- SUCCESS SCREEN -->
      <div class="success-card">
        <div class="success-badge-icon">✓</div>
        <h2 class="success-title">Pendaftaran Klinik Berhasil!</h2>
        <p class="success-desc">Selamat! Ruang kerja klinik baru Anda telah berhasil dibuat. Anda dapat langsung mengelola operasional dan data klinik melalui dashboard administrator.</p>
        
        <div class="success-info-box">
          <div class="success-info-row">
            <span class="success-info-label">Nama Klinik:</span>
            <span class="success-info-val"><?= h($successClinicName) ?></span>
          </div>
          <div class="success-info-row">
            <span class="success-info-label">Kode Klinik / Subdomain:</span>
            <span class="code-pill"><?= h($successClinicCode) ?></span>
          </div>
          <div class="success-info-row">
            <span class="success-info-label">Alamat Subdomain:</span>
            <span class="success-info-val"><?= h($successClinicCode) ?>.platform.com</span>
          </div>
          <div class="success-info-row">
            <span class="success-info-label">Email Administrator:</span>
            <span class="success-info-val"><?= h($successAdminEmail) ?></span>
          </div>
        </div>

        <div class="success-actions">
          <a href="admin/dashboard.php" class="btn-primary" id="btnDashboard">Masuk ke Dashboard Admin →</a>
          <a href="index.php" class="btn-outline">Kembali ke Beranda</a>
        </div>
        <div class="redirect-timer" id="timerNotice">Mengarahkan otomatis ke dashboard dalam <span id="countdown">4</span> detik...</div>
      </div>

      <script>
        let seconds = 4;
        const countdownEl = document.getElementById('countdown');
        const interval = setInterval(() => {
          seconds--;
          if (countdownEl) countdownEl.innerText = seconds;
          if (seconds <= 0) {
            clearInterval(interval);
            window.location.href = 'admin/dashboard.php';
          }
        }, 1000);
      </script>

    <?php else: ?>

      <?php if (!empty($err)): ?>
        <div class="alert-error"><?= h($err) ?></div>
      <?php elseif (isset($_GET['error'])): ?>
        <div class="alert-error"><?= h($_GET['error']) ?></div>
      <?php endif; ?>

      <!-- Stepper Dinamis -->
      <div class="stepper" id="main-stepper">
        <!-- Step 1 -->
        <div class="step step-active" id="indicator-1">
          <div class="step-circle active-circle" id="circle-1">1</div>
          <div class="step-text">
            <span class="step-label-small active-label" id="label-1">Aktif</span>
            <span class="step-label-main">Profil Klinik</span>
          </div>
        </div>
        <!-- Step 2 -->
        <div class="step step-inactive" id="indicator-2">
          <div class="step-circle inactive-circle" id="circle-2">2</div>
          <div class="step-text">
            <span class="step-label-small inactive-label" id="label-2">Selanjutnya</span>
            <span class="step-label-main">Lokasi & Wilayah</span>
          </div>
        </div>
        <!-- Step 3 -->
        <div class="step step-inactive" id="indicator-3">
          <div class="step-circle inactive-circle" id="circle-3">3</div>
          <div class="step-text">
            <span class="step-label-small inactive-label" id="label-3">Terakhir</span>
            <span class="step-label-main">Akun Administrator</span>
          </div>
        </div>
      </div>

      <!-- FORM UTAMA -->
      <form action="registrasi-klinik.php" method="POST" id="registerForm" novalidate>
        
        <!-- ============================== -->
        <!-- TAHAP 1: PROFIL KLINIK         -->
        <!-- ============================== -->
        <div id="step-1-container" class="form-card step-container">
          <div class="form-header">
            <div class="form-title-group">
              <h2>Profil Informasi Klinik</h2>
              <p>Isi identitas klinik dan subdomain unik untuk tenant baru Anda.</p>
            </div>
            <div class="badge-step">Tahap 1 dari 3</div>
          </div>
          <div class="form-body">
            <div class="form-row">
              <div class="form-group">
                <label for="clinic_name">Nama Klinik *</label>
                <input type="text" id="clinic_name" name="clinic_name" class="form-control" placeholder="Klinik Sehat Bersama" value="<?= h($old['clinic_name'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="clinic_code">Kode Klinik / Subdomain *</label>
                <div class="input-group">
                  <input type="text" id="clinic_code" name="clinic_code" pattern="[a-z0-9-]+" class="form-control" placeholder="klinik-sehat" value="<?= h($old['clinic_code'] ?? '') ?>" required oninput="updatePreview()">
                  <span class="input-suffix">.platform.com</span>
                </div>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label>Preview Subdomain</label>
                <div class="form-control-readonly" id="preview-domain">namaklinik.platform.com</div>
              </div>
              <div class="form-group">
                <label>Status Ketersediaan</label>
                <div class="form-control-readonly status-container">
                  <span class="badge-success">Tersedia</span>
                  <span class="status-text">Syarat: 3-40 karakter (huruf kecil, angka, strip).</span>
                </div>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="clinic_email">Email Klinik *</label>
                <input type="email" id="clinic_email" name="clinic_email" class="form-control" placeholder="admin@klinik.id" value="<?= h($old['clinic_email'] ?? '') ?>" required>
              </div>
              <div class="form-group">
                <label for="clinic_phone">No. Telepon Resmi</label>
                <input type="text" id="clinic_phone" name="clinic_phone" class="form-control" placeholder="+62 21..." value="<?= h($old['clinic_phone'] ?? '') ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="timezone">Zona Waktu *</label>
                <select id="timezone" name="timezone" class="form-control select-control" required>
                  <option value="Asia/Jakarta" <?= (($old['timezone'] ?? '') === 'Asia/Jakarta' || !isset($old['timezone'])) ? 'selected' : '' ?>>Asia/Jakarta (WIB)</option>
                  <option value="Asia/Makassar" <?= (($old['timezone'] ?? '') === 'Asia/Makassar') ? 'selected' : '' ?>>Asia/Makassar (WITA)</option>
                  <option value="Asia/Jayapura" <?= (($old['timezone'] ?? '') === 'Asia/Jayapura') ? 'selected' : '' ?>>Asia/Jayapura (WIT)</option>
                </select>
              </div>
            </div>
          </div>
          <div class="form-footer footer-right">
            <a href="jenis_pendaftaran.php" class="btn-outline">Batal</a>
            <button type="button" class="btn-primary" onclick="nextStep(1)">Lanjut ke Lokasi</button>
          </div>
        </div>

        <!-- ============================== -->
        <!-- TAHAP 2: LOKASI & WILAYAH      -->
        <!-- ============================== -->
        <div id="step-2-container" class="form-card step-container d-none">
          <div class="form-header header-with-badge">
            <div class="form-title-group">
              <h2>Informasi Lokasi & Wilayah</h2>
              <p>Pilih wilayah secara berurutan untuk memastikan alamat klinik tercatat dengan benar.</p>
            </div>
            <div class="badge-step">Tahap 2 dari 3</div>
          </div>
          <div class="form-body">
            
            <!-- Hidden inputs untuk menyimpan nama wilayah -->
            <input type="hidden" name="province_name" id="province_name" value="<?= h($old['province_name'] ?? '') ?>">
            <input type="hidden" name="city_name" id="city_name" value="<?= h($old['city_name'] ?? '') ?>">
            <input type="hidden" name="district_name" id="district_name" value="<?= h($old['district_name'] ?? '') ?>">
            <input type="hidden" name="village_name" id="village_name" value="<?= h($old['village_name'] ?? '') ?>">

            <div class="form-row">
              <div class="form-group">
                <label for="province">Provinsi *</label>
                <select id="province" name="province_id" class="form-control select-control" required>
                  <option value="">Memuat daftar provinsi...</option>
                </select>
              </div>
              <div class="form-group">
                <label for="city">Kota/Kabupaten *</label>
                <select id="city" name="city_id" class="form-control select-control" required disabled>
                  <option value="">Pilih kota/kabupaten</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group">
                <label for="district">Kecamatan *</label>
                <select id="district" name="district_id" class="form-control select-control" required disabled>
                  <option value="">Pilih kecamatan</option>
                </select>
              </div>
              <div class="form-group">
                <label for="village">Desa/Kelurahan *</label>
                <select id="village" name="village_id" class="form-control select-control" required disabled>
                  <option value="">Pilih kelurahan/desa</option>
                </select>
              </div>
            </div>
            <div class="form-row">
              <div class="form-group form-group-full">
                <label for="address">Alamat Lengkap *</label>
                <textarea id="address" name="address" class="form-control textarea-control" placeholder="Jalan, RT/RW, Blok, No. Bangunan, dll..." required><?= h($old['address'] ?? '') ?></textarea>
              </div>
            </div>
          </div>
          <div class="form-footer footer-between">
            <span class="footer-note">Data lokasi akan digunakan untuk aktivasi tenant.</span>
            <div class="footer-actions">
              <button type="button" class="btn-outline" onclick="goToStep(1)">Kembali</button>
              <button type="button" class="btn-primary" onclick="nextStep(2)">Lanjutkan</button>
            </div>
          </div>
        </div>

        <!-- ============================== -->
        <!-- TAHAP 3: AKUN ADMINISTRATOR    -->
        <!-- ============================== -->
        <div id="step-3-container" class="step-3-layout step-container d-none">
          
          <!-- Form Utama (Kiri) -->
          <div class="form-main form-card">
            <div class="form-header">
              <div class="form-title-group">
                <h2>Buat akun penanggung jawab</h2>
                <p>Isi data admin utama untuk proses aktivasi dan pengelolaan awal.</p>
              </div>
              <div class="badge-step">Tahap 3 dari 3</div>
            </div>
            <div class="form-body">
              <div class="form-row">
                <div class="form-group">
                  <label for="admin_name">Nama Lengkap Admin *</label>
                  <input type="text" id="admin_name" name="admin_name" class="form-control" placeholder="dr. Ahmad / Nama Pemilik" value="<?= h($old['admin_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label for="admin_email">Email Login *</label>
                  <input type="email" id="admin_email" name="admin_email" class="form-control" placeholder="admin.klinik@gmail.com" value="<?= h($old['admin_email'] ?? '') ?>" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label for="admin_phone">No. Telepon / WhatsApp *</label>
                  <input type="text" id="admin_phone" name="admin_phone" class="form-control" placeholder="08123456789" value="<?= h($old['admin_phone'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label for="password">Kata Sandi *</label>
                  <input type="password" id="password" name="password" minlength="8" class="form-control" placeholder="Minimal 8 karakter" required>
                </div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label for="password_confirmation">Konfirmasi Password *</label>
                  <input type="password" id="password_confirmation" name="password_confirmation" minlength="8" class="form-control" placeholder="Ketik ulang kata sandi" required>
                </div>
              </div>
              <div class="form-checkbox" style="margin-top: 10px;">
                <input type="checkbox" id="terms" name="terms" required <?= isset($old['terms']) ? 'checked' : '' ?>>
                <label for="terms" class="checkbox-label" style="font-size: 13px;">Saya menyetujui Syarat & Ketentuan, Kebijakan Privasi, dan penggunaan data untuk proses aktivasi klinik.</label>
              </div>
              <div class="info-box box-gray" style="margin-top: 20px;">
                <div class="info-title">Peran akun admin</div>
                <div class="info-desc">Akun ini hanya mengelola klinik yang didaftarkan. Admin dapat menambahkan staf, mengatur akses, dan mengelola operasional klinik secara mandiri.</div>
              </div>
            </div>
            <div class="form-footer footer-between">
              <button type="button" class="btn-outline" onclick="goToStep(2)">Kembali</button>
              <button type="submit" class="btn-primary" id="btnSubmit">Daftarkan Klinik Sekarang</button>
            </div>
          </div>

          <!-- Sidebar Ringkasan (Kanan) -->
          <aside class="summary-sidebar">
            <div class="summary-header">
              <h3>Ringkasan</h3>
              <p>Data klinik ini akan menjadi dasar akun administrator dan domain klinik.</p>
            </div>
            <div class="summary-body">
              <div class="summary-card">
                <div class="summary-item">
                  <div class="item-text">
                    <span class="item-label">Nama Klinik</span>
                    <span class="item-value" id="summary_name">-</span>
                  </div>
                </div>
                <div class="summary-item">
                  <div class="item-text">
                    <span class="item-label">Kode Klinik</span>
                    <span class="item-value" id="summary_code">-</span>
                  </div>
                </div>
                <div class="summary-item">
                  <div class="item-text">
                    <span class="item-label">Domain Klinik</span>
                    <span class="item-value" id="summary_domain">-</span>
                  </div>
                </div>
                <div class="summary-item">
                  <div class="item-text">
                    <span class="item-label">Zona Waktu</span>
                    <span class="item-value" id="summary_timezone">-</span>
                  </div>
                </div>
              </div>
              <div class="summary-card-small">
                <span class="item-label">Alamat Klinik</span>
                <span class="item-value" id="summary_address">-</span>
              </div>
              <div class="info-box box-blue">
                <div class="info-title-blue">Perhatian</div>
                <div class="info-desc-blue">Pastikan seluruh data yang dimasukkan sudah benar. Pengajuan nama klinik bersifat final untuk tenant Anda.</div>
              </div>
            </div>
          </aside>

        </div>
      </form>

    <?php endif; ?>
  </main>

  <script>
    // State active step awal dari PHP
    const initialActiveStep = <?= (int)$activeStep ?>;

    // --- PREVIEW DOMAIN SAAT DIKETIK ---
    function updatePreview() {
      const codeInput = document.getElementById('clinic_code');
      if (!codeInput) return;
      const code = codeInput.value.trim().toLowerCase();
      const domain = code ? code + '.platform.com' : 'namaklinik.platform.com';
      const previewEl = document.getElementById('preview-domain');
      if (previewEl) previewEl.innerText = domain;
    }

    // --- NAVIGASI STEPPER MULTI-TAHAP ---
    function goToStep(targetStep) {
      const step1 = document.getElementById('step-1-container');
      const step2 = document.getElementById('step-2-container');
      const step3 = document.getElementById('step-3-container');

      if (!step1 || !step2 || !step3) return;

      step1.classList.add('d-none');
      step2.classList.add('d-none');
      step3.classList.add('d-none');

      document.getElementById('step-' + targetStep + '-container').classList.remove('d-none');

      for (let i = 1; i <= 3; i++) {
        const stepDiv = document.getElementById('indicator-' + i);
        const circleDiv = document.getElementById('circle-' + i);
        const labelSpan = document.getElementById('label-' + i);

        if (!stepDiv || !circleDiv || !labelSpan) continue;

        stepDiv.className = "step step-inactive";
        circleDiv.className = "step-circle inactive-circle";
        labelSpan.className = "step-label-small inactive-label";

        if (i < targetStep) {
          stepDiv.className = "step step-completed";
          circleDiv.className = "step-circle completed-circle";
          circleDiv.innerHTML = "✓";
          labelSpan.innerText = "Selesai";
          labelSpan.className = "step-label-small completed-label";
        } else if (i === targetStep) {
          stepDiv.className = "step step-active";
          circleDiv.className = "step-circle active-circle";
          circleDiv.innerHTML = i;
          labelSpan.innerText = "Aktif";
          labelSpan.className = "step-label-small active-label";
        } else {
          circleDiv.innerHTML = i;
          labelSpan.innerText = (i === 2) ? "Selanjutnya" : "Terakhir";
        }
      }

      // Render data Ringkasan jika pindah ke tahap 3
      if (targetStep === 3) {
        updateSummary();
      }

      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateSummary() {
      const name = document.getElementById('clinic_name')?.value || '-';
      const code = document.getElementById('clinic_code')?.value || '-';
      const domain = (code !== '-') ? code + '.platform.com' : '-';
      const tzSelect = document.getElementById('timezone');
      const tz = tzSelect ? tzSelect.options[tzSelect.selectedIndex]?.text : '-';
      
      const rawAddress = document.getElementById('address')?.value || '';
      const villName = document.getElementById('village_name')?.value || '';
      const distName = document.getElementById('district_name')?.value || '';
      const cityName = document.getElementById('city_name')?.value || '';
      const provName = document.getElementById('province_name')?.value || '';

      const addrParts = [];
      if (rawAddress) addrParts.push(rawAddress);
      if (villName) addrParts.push('Kel. ' + villName);
      if (distName) addrParts.push('Kec. ' + distName);
      if (cityName) addrParts.push(cityName);
      if (provName) addrParts.push(provName);

      const fullAddr = addrParts.length > 0 ? addrParts.join(', ') : '-';

      const sName = document.getElementById('summary_name');
      const sCode = document.getElementById('summary_code');
      const sDomain = document.getElementById('summary_domain');
      const sTz = document.getElementById('summary_timezone');
      const sAddr = document.getElementById('summary_address');

      if (sName) sName.innerText = name;
      if (sCode) sCode.innerText = code;
      if (sDomain) sDomain.innerText = domain;
      if (sTz) sTz.innerText = tz;
      if (sAddr) sAddr.innerText = fullAddr;
    }

    // --- VALIDASI STEP FORM ---
    function validateStep1() {
      const nameInput = document.getElementById('clinic_name');
      const codeInput = document.getElementById('clinic_code');
      const emailInput = document.getElementById('clinic_email');
      const tzInput = document.getElementById('timezone');

      nameInput.setCustomValidity('');
      codeInput.setCustomValidity('');
      emailInput.setCustomValidity('');

      if (!nameInput.value.trim()) {
        nameInput.setCustomValidity('Nama klinik wajib diisi.');
        nameInput.reportValidity();
        nameInput.focus();
        return false;
      }

      const codeVal = codeInput.value.trim().toLowerCase();
      const codePattern = /^[a-z0-9-]{3,40}$/;
      if (!codeVal) {
        codeInput.setCustomValidity('Kode klinik / subdomain wajib diisi.');
        codeInput.reportValidity();
        codeInput.focus();
        return false;
      } else if (!codePattern.test(codeVal)) {
        codeInput.setCustomValidity('Kode klinik harus 3-40 karakter (hanya huruf kecil, angka, dan tanda hubung).');
        codeInput.reportValidity();
        codeInput.focus();
        return false;
      }

      if (!emailInput.value.trim() || !emailInput.checkValidity()) {
        emailInput.setCustomValidity('Format email klinik tidak valid.');
        emailInput.reportValidity();
        emailInput.focus();
        return false;
      }

      if (tzInput && !tzInput.value) {
        tzInput.reportValidity();
        return false;
      }

      return true;
    }

    function validateStep2() {
      const prov = document.getElementById('province');
      const city = document.getElementById('city');
      const dist = document.getElementById('district');
      const vill = document.getElementById('village');
      const addr = document.getElementById('address');

      prov.setCustomValidity('');
      city.setCustomValidity('');
      dist.setCustomValidity('');
      vill.setCustomValidity('');
      addr.setCustomValidity('');

      if (!prov.value) {
        prov.setCustomValidity('Silakan pilih provinsi klinik Anda.');
        prov.reportValidity();
        prov.focus();
        return false;
      }

      if (!city.value) {
        city.setCustomValidity('Silakan pilih kota/kabupaten klinik Anda.');
        city.reportValidity();
        city.focus();
        return false;
      }

      if (!dist.value) {
        dist.setCustomValidity('Silakan pilih kecamatan.');
        dist.reportValidity();
        dist.focus();
        return false;
      }

      if (!vill.value) {
        vill.setCustomValidity('Silakan pilih kelurahan/desa.');
        vill.reportValidity();
        vill.focus();
        return false;
      }

      if (!addr.value.trim()) {
        addr.setCustomValidity('Alamat lengkap klinik wajib diisi.');
        addr.reportValidity();
        addr.focus();
        return false;
      }

      return true;
    }

    function validateStep3() {
      const adminName = document.getElementById('admin_name');
      const adminEmail = document.getElementById('admin_email');
      const adminPhone = document.getElementById('admin_phone');
      const password = document.getElementById('password');
      const confirmPw = document.getElementById('password_confirmation');
      const terms = document.getElementById('terms');

      adminName.setCustomValidity('');
      adminEmail.setCustomValidity('');
      adminPhone.setCustomValidity('');
      password.setCustomValidity('');
      confirmPw.setCustomValidity('');
      terms.setCustomValidity('');

      if (!adminName.value.trim()) {
        adminName.setCustomValidity('Nama lengkap admin wajib diisi.');
        adminName.reportValidity();
        adminName.focus();
        return false;
      }

      if (!adminEmail.value.trim() || !adminEmail.checkValidity()) {
        adminEmail.setCustomValidity('Format email login admin tidak valid.');
        adminEmail.reportValidity();
        adminEmail.focus();
        return false;
      }

      if (!adminPhone.value.trim()) {
        adminPhone.setCustomValidity('Nomor telepon / WhatsApp wajib diisi.');
        adminPhone.reportValidity();
        adminPhone.focus();
        return false;
      }

      if (password.value.length < 8) {
        password.setCustomValidity('Kata sandi harus minimal 8 karakter.');
        password.reportValidity();
        password.focus();
        return false;
      }

      if (password.value !== confirmPw.value) {
        confirmPw.setCustomValidity('Konfirmasi kata sandi tidak cocok.');
        confirmPw.reportValidity();
        confirmPw.focus();
        return false;
      }

      if (!terms.checked) {
        terms.setCustomValidity('Anda harus menyetujui Syarat & Ketentuan untuk melanjutkan.');
        terms.reportValidity();
        return false;
      }

      return true;
    }

    // Fungsi transisi step setelah lolos validasi
    function nextStep(currentStep) {
      if (currentStep === 1) {
        if (validateStep1()) {
          goToStep(2);
        }
      } else if (currentStep === 2) {
        if (validateStep2()) {
          goToStep(3);
        }
      }
    }

    // Submit Listener dengan validasi menyeluruh
    const regForm = document.getElementById('registerForm');
    if (regForm) {
      regForm.addEventListener('submit', function (e) {
        if (!validateStep1()) {
          e.preventDefault();
          goToStep(1);
          validateStep1();
          return false;
        }
        if (!validateStep2()) {
          e.preventDefault();
          goToStep(2);
          validateStep2();
          return false;
        }
        if (!validateStep3()) {
          e.preventDefault();
          return false;
        }

        // Tampilkan loading di tombol submit
        const submitBtn = document.getElementById('btnSubmit');
        if (submitBtn) {
          submitBtn.disabled = true;
          submitBtn.innerText = 'Memproses Pendaftaran...';
        }
      });
    }

    // --- CASCADING DROPDOWN WILAYAH (API & FALLBACK) ---
    async function fetchRegions(type, parentId = '') {
      // 1. Coba fetch dari endpoint lokal sistem (api/regions.php)
      try {
        let url = 'api/regions.php?type=' + encodeURIComponent(type);
        if (parentId) url += '&parent_id=' + encodeURIComponent(parentId);
        const res = await fetch(url);
        if (res.ok) {
          const json = await res.json();
          if (Array.isArray(json) && json.length > 0) return json;
        }
      } catch (e) {
        console.warn('Gagal memuat wilayah dari api/regions.php, mencoba CDN...', e);
      }

      // 2. Fallback: Fetch langsung ke CDN publik Emsifa
      try {
        let directUrl = '';
        if (type === 'province') {
          directUrl = 'https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json';
        } else if (type === 'city') {
          directUrl = 'https://www.emsifa.com/api-wilayah-indonesia/api/regencies/' + parentId + '.json';
        } else if (type === 'district') {
          directUrl = 'https://www.emsifa.com/api-wilayah-indonesia/api/districts/' + parentId + '.json';
        } else if (type === 'village') {
          directUrl = 'https://www.emsifa.com/api-wilayah-indonesia/api/villages/' + parentId + '.json';
        }

        if (directUrl) {
          const res = await fetch(directUrl);
          if (res.ok) {
            const json = await res.json();
            if (Array.isArray(json)) return json;
          }
        }
      } catch (err) {
        console.error('Semua sumber wilayah gagal dimuat:', err);
      }

      return [];
    }

    async function loadProvinces() {
      const provSelect = document.getElementById('province');
      if (!provSelect) return;

      provSelect.innerHTML = '<option value="">Memuat daftar provinsi...</option>';
      provSelect.disabled = true;

      const provinces = await fetchRegions('province');
      provSelect.innerHTML = '<option value="">Pilih provinsi</option>';
      
      const prevProvId = "<?= h($old['province_id'] ?? '') ?>";

      provinces.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        opt.setAttribute('data-name', p.name);
        if (prevProvId && String(p.id) === String(prevProvId)) {
          opt.selected = true;
        }
        provSelect.appendChild(opt);
      });

      provSelect.disabled = false;

      // Jika ada nilai lama saat reload/error, trigger pemuatan kota
      if (prevProvId && provSelect.value) {
        const selectedOpt = provSelect.options[provSelect.selectedIndex];
        document.getElementById('province_name').value = selectedOpt.getAttribute('data-name') || selectedOpt.text;
        await loadCities(prevProvId);
      }
    }

    async function loadCities(provinceId) {
      const citySelect = document.getElementById('city');
      const distSelect = document.getElementById('district');
      const villSelect = document.getElementById('village');

      // Reset anak dropdown
      distSelect.innerHTML = '<option value="">Pilih kecamatan</option>';
      distSelect.disabled = true;
      villSelect.innerHTML = '<option value="">Pilih kelurahan/desa</option>';
      villSelect.disabled = true;
      document.getElementById('district_name').value = '';
      document.getElementById('village_name').value = '';

      if (!provinceId) {
        citySelect.innerHTML = '<option value="">Pilih kota/kabupaten</option>';
        citySelect.disabled = true;
        document.getElementById('city_name').value = '';
        return;
      }

      citySelect.innerHTML = '<option value="">Memuat kota/kabupaten...</option>';
      citySelect.disabled = true;

      const cities = await fetchRegions('city', provinceId);
      citySelect.innerHTML = '<option value="">Pilih kota/kabupaten</option>';

      const prevCityId = "<?= h($old['city_id'] ?? '') ?>";

      cities.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        opt.setAttribute('data-name', c.name);
        if (prevCityId && String(c.id) === String(prevCityId)) {
          opt.selected = true;
        }
        citySelect.appendChild(opt);
      });

      citySelect.disabled = false;

      if (prevCityId && citySelect.value) {
        const selectedOpt = citySelect.options[citySelect.selectedIndex];
        document.getElementById('city_name').value = selectedOpt.getAttribute('data-name') || selectedOpt.text;
        await loadDistricts(prevCityId);
      }
    }

    async function loadDistricts(cityId) {
      const distSelect = document.getElementById('district');
      const villSelect = document.getElementById('village');

      villSelect.innerHTML = '<option value="">Pilih kelurahan/desa</option>';
      villSelect.disabled = true;
      document.getElementById('village_name').value = '';

      if (!cityId) {
        distSelect.innerHTML = '<option value="">Pilih kecamatan</option>';
        distSelect.disabled = true;
        document.getElementById('district_name').value = '';
        return;
      }

      distSelect.innerHTML = '<option value="">Memuat kecamatan...</option>';
      distSelect.disabled = true;

      const districts = await fetchRegions('district', cityId);
      distSelect.innerHTML = '<option value="">Pilih kecamatan</option>';

      const prevDistId = "<?= h($old['district_id'] ?? '') ?>";

      districts.forEach(d => {
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = d.name;
        opt.setAttribute('data-name', d.name);
        if (prevDistId && String(d.id) === String(prevDistId)) {
          opt.selected = true;
        }
        distSelect.appendChild(opt);
      });

      distSelect.disabled = false;

      if (prevDistId && distSelect.value) {
        const selectedOpt = distSelect.options[distSelect.selectedIndex];
        document.getElementById('district_name').value = selectedOpt.getAttribute('data-name') || selectedOpt.text;
        await loadVillages(prevDistId);
      }
    }

    async function loadVillages(districtId) {
      const villSelect = document.getElementById('village');

      if (!districtId) {
        villSelect.innerHTML = '<option value="">Pilih kelurahan/desa</option>';
        villSelect.disabled = true;
        document.getElementById('village_name').value = '';
        return;
      }

      villSelect.innerHTML = '<option value="">Memuat kelurahan/desa...</option>';
      villSelect.disabled = true;

      const villages = await fetchRegions('village', districtId);
      villSelect.innerHTML = '<option value="">Pilih kelurahan/desa</option>';

      const prevVillId = "<?= h($old['village_id'] ?? '') ?>";

      villages.forEach(v => {
        const opt = document.createElement('option');
        opt.value = v.id;
        opt.textContent = v.name;
        opt.setAttribute('data-name', v.name);
        if (prevVillId && String(v.id) === String(prevVillId)) {
          opt.selected = true;
        }
        villSelect.appendChild(opt);
      });

      villSelect.disabled = false;

      if (prevVillId && villSelect.value) {
        const selectedOpt = villSelect.options[villSelect.selectedIndex];
        document.getElementById('village_name').value = selectedOpt.getAttribute('data-name') || selectedOpt.text;
      }
    }

    // Event Listeners Cascading
    document.getElementById('province')?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      document.getElementById('province_name').value = opt ? (opt.getAttribute('data-name') || opt.text) : '';
      loadCities(this.value);
    });

    document.getElementById('city')?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      document.getElementById('city_name').value = opt ? (opt.getAttribute('data-name') || opt.text) : '';
      loadDistricts(this.value);
    });

    document.getElementById('district')?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      document.getElementById('district_name').value = opt ? (opt.getAttribute('data-name') || opt.text) : '';
      loadVillages(this.value);
    });

    document.getElementById('village')?.addEventListener('change', function () {
      const opt = this.options[this.selectedIndex];
      document.getElementById('village_name').value = opt ? (opt.getAttribute('data-name') || opt.text) : '';
    });

    // Inisialisasi saat halaman selesai dimuat
    document.addEventListener('DOMContentLoaded', function () {
      updatePreview();
      loadProvinces();
      
      // Jika sebelumnya form gagal di tahap 2 atau 3, langsung buka tahap tersebut
      if (initialActiveStep > 1) {
        goToStep(initialActiveStep);
      }
    });
  </script>
</body>
</html>
