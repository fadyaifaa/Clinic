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

// 4. Statistik Antrean Hari Ini
$waitingCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status IN ('registered','waiting')", $id);
$inProgressCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='in_progress'", $id);
$completedCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='completed'", $id);
$cancelledCount = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='cancelled'", $id);
$totalQueueToday = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE()", $id);

// 5. Tarik Data Antrean Hari Ini
$sql_queue = "
    SELECT r.registration_number, r.registration_date, r.status, r.visit_type, r.chief_complaint,
           p.full_name, p.medical_record_number,
           COALESCE(d.specialization, 'Umum') as specialization
    FROM registrations r
    JOIN patients p ON p.id = r.patient_id
    LEFT JOIN doctors d ON d.id = r.doctor_id
    WHERE r.clinic_id = ? AND DATE(r.registration_date) = CURDATE()
    ORDER BY r.created_at DESC
    LIMIT 10
";
$stmt = $conn->prepare($sql_queue);
$stmt->bind_param('i', $id);
$stmt->execute();
$queue_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 6. Tarik Data Master Pasien Klinik
$totalPatients = fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id=?", $id);

$sql_patients = "
    SELECT p.id, p.full_name, p.nik, p.birth_date, p.gender, p.blood_type, p.allergies,
           pc.medical_record_number, pc.status as link_status
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
  <link rel="stylesheet" href="../assets/css/dashboard.css"> 
  <link rel="stylesheet" href="../assets/css/patient.css"> 
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
      <input type="text" placeholder="Cari No. RM / Nama Pasien" class="search-input">
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
        <a href="#" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect><line x1="8" y1="21" x2="16" y2="21"></line><line x1="12" y1="17" x2="12" y2="21"></line></svg>
          Master Data Klinik
        </a>
        <a href="#" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          Farmasi & Stok
        </a>
        <a href="#" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          Laporan Operasional
        </a>
        <a href="#" class="nav-item">
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
            <button class="btn-primary-small">+ Registrasi Kunjungan Baru</button>
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
                          <a href="#" class="link-primary">Periksa</a>
                          <a href="#" class="link-danger">Batal</a>
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
            <button class="btn-primary-small">+ Tambah Pasien Baru</button>
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
      event.preventDefault();

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
  </script>

</body>
</html>