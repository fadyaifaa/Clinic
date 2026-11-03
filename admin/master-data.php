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

function fetchCount($conn, $sql, $id = null) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // Hanya lakukan bind_param jika $id diberikan dan query memiliki tanda '?'
        if ($id !== null) {
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? (int)$result->fetch_row()[0] : 0;
    }
    return 0;
}

$id = (int)$_SESSION['clinic_id'];

// 4. STATISTIK & DATA TAB 1: OBAT KLINIK (Sesuai Skema Asli)
$total_obat = fetchCount($conn, "SELECT COUNT(*) FROM medicines");
$total_obat_aktif = fetchCount($conn, "SELECT COUNT(*) FROM medicines WHERE is_active = 1");

// Asumsi stok ditarik dari relasi clinic_medicines dan medicine_batches
$total_obat_restok = fetchCount($conn, "
    SELECT COUNT(*) 
    FROM clinic_medicines cm 
    JOIN medicine_batches mb ON mb.clinic_medicine_id = cm.id 
    WHERE mb.quantity_on_hand <= cm.minimum_stock
");

$obat_list = [];
$res_obat = $conn->query("
    SELECT medicine_code AS kode_obat, 
           name AS nama_obat, 
           generic_name AS generik, 
           dosage_form AS sediaan, 
           0 AS stok, 
           0 AS stok_min, 
           IF(requires_prescription=1, 'Resep Dokter', 'Resep Bebas') AS status_resep, 
           IF(is_active=1, 'Aktif', 'Nonaktif') AS status_aktif 
    FROM medicines 
    ORDER BY medicine_code ASC 
    LIMIT 10
");
if ($res_obat) $obat_list = $res_obat->fetch_all(MYSQLI_ASSOC);


// 5. STATISTIK & DATA TAB 2: DIAGNOSIS (ICD) (Sesuai Skema Asli)
$total_diagnosis = fetchCount($conn, "SELECT COUNT(*) FROM diagnoses");

$diagnosis_list = [];
$res_diag = $conn->query("
    SELECT code AS kode_icd, 
           name AS nama_diagnosis, 
           description AS deskripsi, 
           coding_system AS sistem_pengkodean 
    FROM diagnoses 
    ORDER BY code ASC 
    LIMIT 10
");
if ($res_diag) $diagnosis_list = $res_diag->fetch_all(MYSQLI_ASSOC);


// 6. STATISTIK & DATA TAB 3: TINDAKAN MEDIS (Sesuai Skema Asli)
$total_tindakan = fetchCount($conn, "SELECT COUNT(*) FROM medical_actions");

$tindakan_list = [];
$res_tnd = $conn->query("
    SELECT code AS kode_tindakan, 
           name AS nama_tindakan, 
           description AS keterangan, 
           default_price AS tarif, 
           IF(is_active=1, 'Aktif', 'Nonaktif') AS status 
    FROM medical_actions 
    ORDER BY code ASC 
    LIMIT 10
");
if ($res_tnd) $tindakan_list = $res_tnd->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Master Data Klinik - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css"> 
  <link rel="stylesheet" href="../assets/css/masterdata.css"> 
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
      <input type="text" placeholder="Cari data master..." class="search-input">
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
        <span class="sidebar-subtitle">MASTER DATA KLINIK</span>
        <span class="sidebar-title"><?= h(strtoupper($_SESSION['clinic_name'])) ?></span>
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
        <a href="master-data.php" class="nav-item active">
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
        <div class="breadcrumb" id="dynamic-breadcrumb">Dashboard / Master Data Klinik</div>
        <div class="page-title-row">
          <div class="page-title-group">
            <h1 id="dynamic-title">Master Data Klinik</h1>
            <p id="dynamic-subtitle">Kelola katalog obat, daftar diagnosis, dan tarif tindakan medis khusus klinik ini.</p>
          </div>
        </div>
      </div>

      <!-- Tab Navigation -->
      <div class="tabs-container">
        <a href="#" class="tab-item active" id="tab-obat" onclick="switchTab(event, 'obat')">Katalog Obat Klinik</a>
        <a href="#" class="tab-item" id="tab-diagnosis" onclick="switchTab(event, 'diagnosis')">Diagnosis (ICD)</a>
        <a href="#" class="tab-item" id="tab-tindakan" onclick="switchTab(event, 'tindakan')">Tarif & Tindakan Medis</a>
      </div>

      <!-- ==================== TAB 1: KATALOG OBAT KLINIK ==================== -->
      <div id="content-obat">
        <div class="master-header">
          <div class="master-header-text">
            <h3>Katalog Obat Klinik</h3>
            <p>Tentukan obat mana saja yang dijual di klinik ini dan atur batas stok minimum untuk setiap item.</p>
          </div>
          <div class="badge-pill-blue">
            <?= $total_obat_aktif ?> obat aktif · <?= $total_obat_restok ?> perlu restok
          </div>
        </div>

        <div class="data-panel">
          <div class="panel-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 360px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari nama atau kode obat" class="search-input">
              </div>
              <div class="filter-select">
                <span>Kategori: Semua sediaan</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="filter-select">
                <span>Status: Aktif / Nonaktif</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="flex-spacer"></div>
              <button class="btn-primary-small">+ Tambah Obat Klinik</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table master-table">
              <thead>
                <tr>
                  <th width="12%">Kode Obat</th>
                  <th width="28%">Nama Obat & Generik</th>
                  <th width="20%">Sediaan & Dosis</th>
                  <th width="12%">Stok Minimum</th>
                  <th width="12%">Status Resep</th>
                  <th width="10%">Status</th>
                  <th width="6%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($obat_list)): ?>
                  <tr>
                    <td colspan="7" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data obat di database. Angka statistik di atas telah disesuaikan menjadi 0.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($obat_list as $row): ?>
                    <tr>
                      <td><div class="td-desc"><?= h($row['kode_obat'] ?? '') ?></div></td>
                      <td>
                        <div class="td-main"><?= h($row['nama_obat'] ?? '') ?></div>
                        <div class="td-sub-small mt-1"><?= h($row['generik'] ?? '') ?></div>
                      </td>
                      <td><div class="td-desc text-dark fw-normal"><?= h($row['sediaan'] ?? '') ?></div></td>
                      <td>
                        <div class="<?= (isset($row['stok']) && isset($row['stok_min']) && $row['stok'] <= $row['stok_min']) ? 'badge-amber' : 'td-main fw-normal' ?>">
                          <?= h($row['stok_min'] ?? 0) ?>
                          <?php if(isset($row['stok']) && isset($row['stok_min']) && $row['stok'] <= $row['stok_min']): ?> · Kritis <?php endif; ?>
                        </div>
                      </td>
                      <td><span class="status-badge status-gray fw-semibold"><?= h($row['status_resep'] ?? 'Resep Bebas') ?></span></td>
                      <td><span class="status-badge status-blue fw-semibold"><?= h($row['status_aktif'] ?? 'Aktif') ?></span></td>
                      <td><div class="link-primary fw-semibold">Edit</div></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan <?= count($obat_list) > 0 ? '1–'.count($obat_list) : '0' ?> dari <?= $total_obat ?> obat klinik</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== TAB 2: DIAGNOSIS (ICD) ==================== -->
      <div id="content-diagnosis" class="d-none">
        <div class="master-header">
          <div class="master-header-text">
            <h3>Pustaka Diagnosis</h3>
            <p>Kelola kode dan nama diagnosis yang digunakan oleh dokter di Klinik Sehat Bersama.</p>
          </div>
          <div class="badge-pill-blue">Berlaku khusus klinik ini</div>
        </div>

        <div class="data-panel">
          <div class="panel-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 480px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari Kode ICD (contoh: A09) atau Nama Diagnosis..." class="search-input">
              </div>
              <div class="filter-select">
                <span>Sistem Pengkodean: Semua</span>
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
              </div>
              <div class="flex-spacer"></div>
              <button class="btn-primary-small">+ Tambah Diagnosis Baru</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table master-table">
              <thead>
                <tr>
                  <th width="15%">Kode Diagnosis</th>
                  <th width="35%">Nama Diagnosis</th>
                  <th width="30%">Deskripsi / Catatan</th>
                  <th width="12%">Sistem Pengkodean</th>
                  <th width="8%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($diagnosis_list)): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data diagnosis di database. Total data = 0.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($diagnosis_list as $row): ?>
                    <tr>
                      <td><span class="code-badge"><?= h($row['kode_icd'] ?? '') ?></span></td>
                      <td><div class="td-main" style="line-height: 1.5;"><?= h($row['nama_diagnosis'] ?? '') ?></div></td>
                      <td><div class="td-desc" style="line-height: 1.5;"><?= h($row['deskripsi'] ?? '') ?></div></td>
                      <td><span class="badge-gray"><?= h($row['sistem_pengkodean'] ?? 'ICD-10') ?></span></td>
                      <td>
                        <div class="action-icons">
                          <button class="btn-icon-edit"></button>
                          <button class="btn-icon-delete"></button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan <?= count($diagnosis_list) > 0 ? '1–'.count($diagnosis_list) : '0' ?> dari <?= $total_diagnosis ?> diagnosis</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ==================== TAB 3: TARIF & TINDAKAN MEDIS ==================== -->
      <div id="content-tindakan" class="d-none">
        <div class="master-header">
          <div class="master-header-text">
            <h3>Tarif & Tindakan Medis</h3>
            <p>Atur harga layanan medis yang berlaku khusus untuk Klinik Sehat Bersama.</p>
          </div>
          <div class="badge-pill-blue">Berlaku khusus klinik ini</div>
        </div>

        <div class="data-panel">
          <div class="panel-filters">
            <div class="filter-row">
              <div class="filter-search" style="width: 360px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" placeholder="Cari kode atau nama tindakan" class="search-input">
              </div>
              <div class="flex-spacer"></div>
              <button class="btn-primary-small">+ Tambah Tindakan</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table master-table">
              <thead>
                <tr>
                  <th width="15%">Kode</th>
                  <th width="40%">Nama Tindakan</th>
                  <th width="20%">Tarif Standar</th>
                  <th width="15%">Status</th>
                  <th width="10%">Aksi</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($tindakan_list)): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 40px; color: #64748B;">
                      Belum ada data tindakan & tarif di database. Total data = 0.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($tindakan_list as $row): ?>
                    <tr>
                      <td><div class="td-desc"><?= h($row['kode_tindakan'] ?? '') ?></div></td>
                      <td>
                        <div class="td-main"><?= h($row['nama_tindakan'] ?? '') ?></div>
                        <div class="td-sub-small mt-1"><?= h($row['keterangan'] ?? '') ?></div>
                      </td>
                      <td><div class="td-main">Rp <?= number_format($row['tarif'] ?? 0, 0, ',', '.') ?></div></td>
                      <td><span class="status-badge status-green fw-semibold"><?= h($row['status'] ?? 'Aktif') ?></span></td>
                      <td><div class="link-primary fw-semibold">Edit</div></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan <?= count($tindakan_list) > 0 ? '1–'.count($tindakan_list) : '0' ?> dari <?= $total_tindakan ?> tindakan</div>
            <div class="page-controls">
              <button class="btn-page">Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page">Berikutnya</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto;">
        <span>© <?= date('Y') ?> <?= h($_SESSION['clinic_name']) ?> · Master Data Klinik</span>
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
        document.getElementById('clock-main').textContent = 'Zona waktu Asia/Jakarta (WIB) · ' + s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    // JS Tab Switching
    function switchTab(event, tabName) {
      event.preventDefault();
      
      // Hapus active dari semua tombol tab
      document.getElementById('tab-obat').classList.remove('active');
      document.getElementById('tab-diagnosis').classList.remove('active');
      document.getElementById('tab-tindakan').classList.remove('active');
      
      // Sembunyikan semua konten panel
      document.getElementById('content-obat').classList.add('d-none');
      document.getElementById('content-diagnosis').classList.add('d-none');
      document.getElementById('content-tindakan').classList.add('d-none');
      
      // Tampilkan tab yang dipilih
      document.getElementById('tab-' + tabName).classList.add('active');
      document.getElementById('content-' + tabName).classList.remove('d-none');

      // Update Breadcrumb & Title dinamis
      const bText = document.getElementById('dynamic-breadcrumb');
      const tText = document.getElementById('dynamic-title');
      const sText = document.getElementById('dynamic-subtitle');

      if(tabName === 'obat') {
          bText.innerText = 'Dashboard / Master Data Klinik';
          tText.innerText = 'Master Data Klinik';
          sText.innerText = 'Kelola katalog obat, daftar diagnosis, dan tarif tindakan medis khusus klinik ini.';
      } else if (tabName === 'diagnosis') {
          bText.innerText = 'Dashboard / Master Data Klinik / Pustaka Diagnosis';
          tText.innerText = 'Master Data Klinik - Pustaka Diagnosis';
          sText.innerText = 'Pustaka referensi kode diagnosis medis terstandar (ICD-10) untuk pengisian rekam medis SOAP oleh dokter.';
      } else if (tabName === 'tindakan') {
          bText.innerText = 'Dashboard / Master Data Klinik / Tarif & Tindakan Medis';
          tText.innerText = 'Master Data Klinik';
          sText.innerText = 'Kelola katalog obat, daftar diagnosis, dan tarif tindakan medis khusus klinik ini.';
      }
    }
  </script>

</body>
</html>