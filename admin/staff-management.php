<?php
session_start();

// 1. Data login (Bypass sementara jika sistem auth belum siap)
$_SESSION['clinic_id'] = 1; 
$_SESSION['clinic_name'] = 'Klinik Sehat Bersama';
$_SESSION['clinic_code'] = 'klinik-sehat';
$_SESSION['user_name'] = 'Dahlia Cahya';

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

// 4. Tarik Statistik Staf Asli dari Database
$totalStaf = fetchCount($conn, "SELECT COUNT(*) FROM user_clinics WHERE clinic_id=?", $id);
$stafAktif = fetchCount($conn, "SELECT COUNT(*) FROM user_clinics uc JOIN users u ON u.id = uc.user_id WHERE uc.clinic_id=? AND u.is_active=1", $id);
$stafNonaktif = fetchCount($conn, "SELECT COUNT(*) FROM user_clinics uc JOIN users u ON u.id = uc.user_id WHERE uc.clinic_id=? AND u.is_active=0", $id);

/* 
  INSTRUKSI DATABASE UNTUK PERMINTAAN VERIFIKASI (PENDING):
  Jika Anda ingin tabel verifikasi terisi data dinamis, pastikan Anda memiliki tabel 
  (misal: staff_verification_requests) atau kolom status 'pending' pada relasi user_clinics.
  Contoh query untuk menarik data pending:
  
  $sql_pending = "SELECT * FROM staff_verification_requests WHERE clinic_id = ? AND status = 'pending'";
  $stmt_p = $conn->prepare($sql_pending);
  $stmt_p->bind_param('i', $id);
  $stmt_p->execute();
  $pending_list = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
*/

// Untuk sementara kita inisialisasi sebagai array kosong atau data dinamis dari query di atas:
$pending_list = []; // Kosong secara default jika belum ada data di database
// $pending_list = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC); // Gunakan ini jika tabel sudah siap
$pendingVerifikasi = count($pending_list);

// 5. Tarik Daftar Staf Aktif dari Database (Sesuai SQL Skema Asli)
$sql_staff = "
    SELECT u.id, u.name, u.email, u.phone, 
           IF(u.is_active = 1, 'active', 'inactive') AS status, 
           u.last_login_at AS last_login,
           r.name AS role_name,
           COALESCE(d.specialization, 'Umum') AS specialization,
           d.license_number AS str_number, 
           NULL AS sip_number, 
           a.license_number AS stra_number, 
           NULL AS sipa_number
    FROM user_clinics uc
    JOIN users u ON u.id = uc.user_id
    JOIN user_roles ur ON ur.user_id = u.id AND ur.clinic_id = uc.clinic_id
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN doctors d ON d.user_id = u.id
    LEFT JOIN pharmacists a ON a.user_id = u.id
    WHERE uc.clinic_id = ?
    ORDER BY u.name ASC
    LIMIT 10
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
      <input type="text" placeholder="Cari nama, email, atau telepon" class="search-input">
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
        <a href="farmasi-stok.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          Farmasi & Stok
        </a>
        <a href="laporan-operasional.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          Laporan Operasional
        </a>
        <a href="pengaturan-klinik.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l.06-.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
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
          <button class="btn-outline-blue">+ Undang Staf via Email</button>
        </div>
      </div>

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
          <div class="stat-val"><?= $pendingVerifikasi ?></div>
          <div class="badge-pending">Permintaan Pendataan (Pending)</div>
        </div>
      </div>

      <!-- Main Panel: Staf -->
      <div class="data-panel">
        
        <!-- Tab Sub-Nav inside Panel -->
        <div class="panel-tab-nav">
          <a href="#" class="ptab-item active" id="tab-active" onclick="switchTab(event, 'active')">
            Staf Aktif <span class="badge-blue-pill"><?= number_format($stafAktif, 0, ',', '.') ?></span>
          </a>
          <a href="#" class="ptab-item" id="tab-verify" onclick="switchTab(event, 'verify')">
            Permintaan Verifikasi <span class="badge-orange-pill"><?= $pendingVerifikasi ?></span>
          </a>
        </div>

        <!-- ================= TAB 1: STAF AKTIF ================= -->
        <div id="content-active">
          <div class="panel-filters staff-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 310px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari nama, email, atau telepon" class="search-input">
              </div>
              <div class="filter-select">
                <span>Peran: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="filter-select">
                <span>Status: Aktif</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="flex-spacer"></div>
              <div class="filter-info-right">
                Dokter · Apoteker · Hak Akses · Verifikasi
              </div>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table staff-table">
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
                        <div class="td-main"><?= h($staf['name']) ?></div>
                        <div class="td-sub-small mt-1"><?= h($staf['email']) ?><br/><?= h($staf['phone'] ?? '-') ?></div>
                      </td>
                      <td>
                        <div class="td-main">
                          <?php 
                            $roleDisp = h($staf['role_name']);
                            if (strtolower($staf['role_name']) === 'doctor') {
                                $roleDisp = 'Dokter · ' . h($staf['specialization']);
                            } elseif (strtolower($staf['role_name']) === 'admin') {
                                $roleDisp = 'Hak Akses · Admin Klinik';
                            } elseif (strtolower($staf['role_name']) === 'pharmacist') {
                                $roleDisp = 'Apoteker';
                            }
                            echo $roleDisp;
                          ?>
                        </div>
                        <div class="td-sub-small mt-1">
                          <?php
                            if (strtolower($staf['role_name']) === 'doctor') {
                                echo "STR " . h($staf['str_number'] ?? '-') . "<br/>SIP " . h($staf['sip_number'] ?? '-');
                            } elseif (strtolower($staf['role_name']) === 'pharmacist') {
                                echo "STRA " . h($staf['stra_number'] ?? '-') . "<br/>SIPA " . h($staf['sipa_number'] ?? '-');
                            } else {
                                echo "Akses administrasi klinik<br/>Manajemen operasional";
                            }
                          ?>
                        </div>
                      </td>
                      <td>
                        <div class="td-main"><?= h($staf['phone'] ?? '-') ?></div>
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
                          <a href="#" class="link-primary">Edit Peran</a>
                          <a href="#" class="link-primary fw-normal">Lihat Detail</a>
                          <a href="#" class="link-danger">Nonaktifkan</a>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan 1–<?= count($staff_list) ?> dari <?= $stafAktif ?> staf aktif</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <div class="page-num-text">2</div>
              <div class="page-num-text">...</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>

        <!-- ================= TAB 2: PERMINTAAN VERIFIKASI (LOGIKA IF-ELSE) ================= -->
        <div id="content-verify" class="d-none">
          <div class="panel-filters staff-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 310px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari nama, email, atau telepon" class="search-input">
              </div>
              <div class="filter-select">
                <span>Peran: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="filter-select">
                <span>Tanggal: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="flex-spacer"></div>
              <div class="filter-info-right">Pendaftaran mandiri · Dokter & Apoteker</div>
            </div>
          </div>

          <div style="padding: 16px 20px; display: flex; align-items: center; gap: 10px;">
            <span class="badge-orange-tag">Menunggu verifikasi</span>
            <span class="text-gray" style="font-size: 12px;">Pendaftar belum aktif dan belum memiliki akses. Tinjau kredensial sebelum menyetujui permintaan.</span>
          </div>

          <div class="table-responsive">
            <table class="data-table staff-table">
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
                  <!-- Jika kosong, tampilkan baris informasi kosong -->
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-gray);">
                      Tidak ada permintaan verifikasi staf yang tertunda saat ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <!-- Jika ada data, lakukan looping dinamis -->
                  <?php foreach ($pending_list as $p): ?>
                    <tr>
                      <td>
                        <div class="td-main font-semibold text-dark"><?= h($p['name']) ?></div>
                        <div class="td-sub-small text-gray" style="font-size: 11px;"><?= h($p['email']) ?></div>
                        <div class="td-sub-small text-gray" style="font-size: 11px;"><?= h($p['phone']) ?></div>
                      </td>
                      <td>
                        <span class="badge-blue-tag" style="margin-bottom: 4px; display: inline-block;"><?= h($p['role']) ?></span>
                        <div class="text-gray" style="font-size: 11px;">Belum aktif<br/>Belum memiliki akses</div>
                      </td>
                      <td>
                        <div class="font-semibold text-dark" style="font-size: 13px;"><?= h($p['specialization']) ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['credential_1']) ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['credential_2']) ?></div>
                      </td>
                      <td>
                        <div class="text-dark" style="font-size: 12px; font-weight: 500;"><?= h($p['date']) ?></div>
                        <div class="text-gray" style="font-size: 11px;"><?= h($p['time']) ?></div>
                      </td>
                      <td>
                        <div class="action-links" style="display: flex; gap: 8px;">
                          <!-- Tombol memicu modal dengan melewatkan parameter data -->
                          <button onclick="openModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)" class="btn-verify">Verifikasi & Terima</button>
                          <button class="btn-reject">Tolak</button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer" style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center;">
            <div class="page-info text-gray" style="font-size: 12px;">Menampilkan <?= count($pending_list) ?> dari <?= $pendingVerifikasi ?> permintaan</div>
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
        <span class="text-gray" style="font-size: 11px;">© 2026 Klinik Sehat Bersama · Panel Kontrol Admin</span>
        <span class="text-gray" style="font-size: 11px;" id="clock-main"></span>
      </footer>

    </main>
  </div>

  <!-- MODAL VERIFIKASI STAF (DINAMIS MENGGUNAKAN JAVASCRIPT / DATA PASIEN) -->
  <div class="modal-overlay d-none" id="verifyModal">
    <div class="modal-container" style="width: 650px;">
      
      <!-- Modal Header -->
      <div class="modal-header">
        <div class="modal-title-group">
          <h2>Verifikasi Permintaan Akses Staf</h2>
          <p>Tinjau data kredensial dokter/apoteker yang mendaftar mandiri sebelum memberikan hak akses ke klinik.</p>
        </div>
        <button class="btn-close-modal" onclick="closeModal()">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <!-- Modal Body -->
      <div class="modal-body">
        <div class="alert-box-blue">
          <span class="badge-solid-blue">Menunggu Verifikasi</span>
          <span class="alert-text">Data pendaftaran mandiri · hanya baca. Admin tidak dapat mengedit nama, email, STR, SIP, atau spesialisasi.</span>
        </div>

        <!-- Section 1: Identitas (Diisi dinamis lewat JS saat tombol verifikasi diklik) -->
        <div class="detail-section gray-bg">
          <div class="section-heading">
            <h3>Identitas Pendaftar</h3>
            <p>Informasi yang dikirimkan oleh staf saat mendaftar mandiri.</p>
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
              <label>Diajukan</label>
              <div class="readonly-value" id="modal-date">-</div>
            </div>
          </div>
        </div>

        <!-- Section 2: Peran -->
        <div class="detail-section">
          <div class="section-heading">
            <h3>Penetapan Peran</h3>
            <p>Pilih peran utama yang akan diberikan kepada staf ini.</p>
          </div>
          <div class="selection-grid-2">
            <label class="selection-card active">
              <input type="radio" name="role" checked class="d-none">
              <div class="custom-radio active"></div>
              <span id="modal-role-label">Dokter</span>
            </label>
            <label class="selection-card">
              <input type="radio" name="role" class="d-none">
              <div class="custom-radio"></div>
              <span>Apoteker</span>
            </label>
          </div>
        </div>

        <!-- Section 3: Hak Akses -->
        <div class="detail-section">
          <div class="section-heading">
            <h3>Hak Akses</h3>
            <p>Pilih hak akses yang akan diberikan setelah peran dipilih.</p>
          </div>
          <div class="selection-grid-3" style="display: flex; flex-direction: column; gap: 8px;">
            <label class="toggle-row" style="padding: 10px 14px;">
              <div class="custom-checkbox active"></div>
              <span>Pemeriksaan & rekam medis</span>
            </label>
            <label class="toggle-row" style="padding: 10px 14px;">
              <div class="custom-checkbox active"></div>
              <span>Diagnosis & tindakan</span>
            </label>
            <label class="toggle-row" style="padding: 10px 14px;">
              <div class="custom-checkbox active"></div>
              <span>Penulisan resep</span>
            </label>
          </div>
        </div>

        <!-- Section 4: Primary Clinic -->
        <label class="toggle-row">
          <div class="custom-checkbox active"></div>
          <span>Tetapkan sebagai Klinik Utama (Primary Clinic)</span>
        </label>

      </div>

      <!-- Modal Footer -->
      <div class="modal-footer">
        <button class="btn-outline-gray" onclick="closeModal()">Batal</button>
        <div class="footer-actions">
          <button class="btn-outline-red">Tolak Pengajuan</button>
          <button class="btn-primary-solid">Konfirmasi & Beri Akses</button>
        </div>
      </div>
      
    </div>
  </div>

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

    // JS Tab Switching Interaktif
    function switchTab(event, tabName) {
      event.preventDefault();

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

    // JS Modal Verifikasi Dinamis (Menerima parameter data staf saat tombol diklik)
    function openModal(data) {
      if (data) {
        document.getElementById('modal-name').textContent = data.name || '-';
        document.getElementById('modal-email').textContent = data.email || '-';
        document.getElementById('modal-phone').textContent = data.phone || '-';
        document.getElementById('modal-specialization').textContent = data.specialization || '-';
        document.getElementById('modal-cred1').textContent = data.credential_1 || '-';
        document.getElementById('modal-cred2').textContent = data.credential_2 || '-';
        document.getElementById('modal-date').textContent = (data.date && data.time) ? data.date + ' ' + data.time : '-';
        document.getElementById('modal-role-label').textContent = data.role || 'Dokter';
      }
      document.getElementById('verifyModal').classList.remove('d-none');
    }

    function closeModal() {
      document.getElementById('verifyModal').classList.add('d-none');
    }
  </script>

</body>
</html>