<?php
session_start();

// 1. Data login (Bypass sementara)
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

// Fungsi fetchCount yang sudah diperbaiki (Parameter $id opsional)
function fetchCount($conn, $sql, $id = null) {
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($id !== null) {
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? (int)$result->fetch_row()[0] : 0;
    }
    return 0;
}

$clinic_id = (int)$_SESSION['clinic_id'];

/* 
  ========================================================================
  INSTRUKSI DATABASE:
  Halaman ini memanggil tabel `report_queue`. Jika tabel ini belum ada 
  di database Anda, query di bawah akan mengembalikan nilai kosong (0) 
  dan tabel HTML akan memunculkan pesan "Belum ada antrean laporan".
  
  Kolom yang dibutuhkan di tabel report_queue minimal:
  - id, clinic_id, title, report_type, date_range, format_file, 
  - created_by, created_at, status (completed/queued/processing/failed), 
  - file_size, completed_at, error_message
  ========================================================================
*/

// 4. Tarik Statistik Laporan dari Database
$total_reports = fetchCount($conn, "SELECT COUNT(*) FROM report_queue WHERE clinic_id=?", $clinic_id);
$completed_reports = fetchCount($conn, "SELECT COUNT(*) FROM report_queue WHERE clinic_id=? AND status='completed'", $clinic_id);
$queued_reports = fetchCount($conn, "SELECT COUNT(*) FROM report_queue WHERE clinic_id=? AND status IN ('queued', 'processing')", $clinic_id);
$failed_reports = fetchCount($conn, "SELECT COUNT(*) FROM report_queue WHERE clinic_id=? AND status='failed'", $clinic_id);

// 5. Tarik Daftar Antrean Laporan
$report_list = [];
$sql_reports = "SELECT * FROM report_queue WHERE clinic_id=? ORDER BY created_at DESC LIMIT 10";
$stmt = $conn->prepare($sql_reports);
if ($stmt) {
    $stmt->bind_param('i', $clinic_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        $report_list = $result->fetch_all(MYSQLI_ASSOC);
    }
}

// Helper Status Badge
function getReportBadge($status, $error_msg = '') {
    switch (strtolower($status)) {
        case 'completed':
            return '<span class="status-badge status-green">Completed</span>';
        case 'queued':
            return '<span class="status-badge status-orange">Queued</span><div class="td-sub-small mt-1 text-gray">Menunggu giliran</div>';
        case 'processing':
            return '<span class="status-badge status-blue">Processing</span><div class="td-sub-small mt-1 text-gray">Memproses data...</div>';
        case 'failed':
            return '<span class="status-badge status-red">Failed</span><div class="td-sub-small mt-1" style="color: #B91C1C;">'.h($error_msg ? $error_msg : 'Gagal memproses').'</div>';
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
  <title>Laporan Operasional - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css">
  <link rel="stylesheet" href="../assets/css/laporan.css"> <!-- File CSS Baru -->
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
        <span class="sidebar-subtitle">KLINIK SEHAT BERSAMA</span>
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
        <a href="farmasi-stok.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          Farmasi & Stok
        </a>
        <a href="laporan-operasional.php" class="nav-item active">
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
        <div class="breadcrumb"><?= h($_SESSION['clinic_name']) ?> / Laporan Operasional</div>
        <div class="page-title-group">
          <h1>Pusat Laporan & Analitik</h1>
          <p>Generate dan unduh laporan operasional, kunjungan pasien, serta transaksi farmasi.</p>
        </div>
      </div>

      <!-- SECTION 1: FORM BUAT LAPORAN BARU -->
      <div class="data-panel" style="margin-bottom: 24px;">
        <div class="panel-header" style="flex-direction: column; align-items: flex-start; border-bottom: none; padding-bottom: 0;">
          <h3 style="font-size: 16px; color: var(--text-dark); margin-bottom: 4px;">Buat Laporan Baru</h3>
          <p style="font-size: 12px; color: var(--text-gray); margin: 0;">Pilih tipe laporan, periode data, dan format file yang dibutuhkan.</p>
        </div>
        
        <div class="panel-filters" style="border-top: none;">
          <div class="filter-row" style="align-items: flex-end; gap: 16px;">
            
            <div class="form-group-report" style="flex: 1;">
              <label>Tipe Laporan</label>
              <select class="input-report">
                <option>Rekap Kunjungan</option>
                <option>Laporan Pemakaian Obat</option>
                <option>Keuangan Tindakan</option>
              </select>
            </div>
            
            <div class="form-group-report" style="width: 350px;">
              <label>Rentang Tanggal</label>
              <div class="input-report" style="display: flex; align-items: center; justify-content: space-between;">
                <span style="color: var(--text-gray); display: flex; align-items: center; gap: 8px;">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                  01 Okt 2026 — 27 Okt 2026
                </span>
              </div>
            </div>
            
            <div class="form-group-report" style="width: 176px;">
              <label>Format File</label>
              <select class="input-report">
                <option>PDF</option>
                <option>Excel</option>
              </select>
            </div>
            
            <button class="btn-generate">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Generate Laporan
            </button>
            
          </div>
          
          <div style="display: flex; justify-content: space-between; margin-top: 16px;">
            <span style="font-size: 11px; color: var(--text-gray);">Format tersedia: PDF / Excel · Tanggal mengikuti Asia/Jakarta (WIB)</span>
            <span style="font-size: 11px; color: var(--text-gray);">Laporan akan muncul di antrean setelah dibuat.</span>
          </div>
        </div>
      </div>

      <!-- SECTION 2: ANTREAN LAPORAN -->
      <div class="data-panel">
        <div class="panel-header">
          <div class="panel-title-group" style="display: flex; flex-direction: column; gap: 4px;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <h3 style="margin: 0; font-size: 16px;">Antrean Laporan</h3>
              <span style="background: #F8FAFC; color: #475569; padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 600;"><?= $total_reports ?> laporan</span>
            </div>
            <p style="margin: 0; font-size: 12px; color: var(--text-gray);">Pantau proses pembuatan dan unduh laporan yang telah selesai.</p>
          </div>
          <div style="display: flex; gap: 8px;">
            <span class="report-stat-pill" style="background: #ECFDF5; color: #047857;"><?= $completed_reports ?> selesai</span>
            <span class="report-stat-pill" style="background: #EFF6FF; color: #2563EB;"><?= $queued_reports ?> dalam antrean</span>
            <span class="report-stat-pill" style="background: #FEF2F2; color: #B91C1C;"><?= $failed_reports ?> gagal</span>
          </div>
        </div>

        <!-- Filters Table -->
        <div class="panel-filters">
          <div class="filter-row">
            <div class="filter-search" style="width: 310px;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" placeholder="Cari judul laporan" class="search-input">
            </div>
            <div class="filter-select">
              <span>Status: Semua</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
            <div class="filter-select">
              <span>Format: Semua</span>
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </div>
            <div class="flex-spacer"></div>
            <span style="font-size: 11px; color: var(--text-gray);">Riwayat <?= date('F Y') ?></span>
          </div>
        </div>

        <!-- Table Data -->
        <div class="table-responsive">
          <table class="data-table queue-table">
            <thead>
              <tr>
                <th width="22%">Judul Laporan</th>
                <th width="15%">Tipe</th>
                <th width="14%">Parameter Tanggal</th>
                <th width="15%">Dibuat Oleh</th>
                <th width="14%">Status Laporan</th>
                <th width="10%">Waktu Selesai</th>
                <th width="10%">Aksi Unduh</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($report_list)): ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-gray);">
                    Belum ada antrean pembuatan laporan. Silakan buat laporan baru.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($report_list as $row): ?>
                  <tr>
                    <td>
                      <div class="td-main"><?= h($row['title']) ?></div>
                      <div class="td-sub-small mt-1"><?= h($row['format_file']) ?> · <?= h($row['file_size'] ?? 'Permintaan baru') ?></div>
                    </td>
                    <td><div class="td-desc"><?= h($row['report_type']) ?></div></td>
                    <td><div class="td-desc"><?= h($row['date_range']) ?></div></td>
                    <td>
                      <div class="td-main fw-normal"><?= h($row['created_by_name']) ?></div>
                      <div class="td-sub-small mt-1"><?= date('d M Y · H:i', strtotime($row['created_at'])) ?> WIB</div>
                    </td>
                    <td>
                      <?= getReportBadge($row['status'], $row['error_message'] ?? '') ?>
                    </td>
                    <td>
                      <?php if ($row['status'] === 'completed'): ?>
                        <div class="td-desc"><?= date('d M Y', strtotime($row['completed_at'])) ?></div>
                        <div class="td-sub-small mt-1"><?= date('H:i', strtotime($row['completed_at'])) ?> WIB</div>
                      <?php else: ?>
                        <div class="td-desc">—</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (strtolower($row['status']) === 'completed'): ?>
                        <button class="btn-outline-blue" style="height: 32px; padding: 0 10px; font-size: 12px;">Unduh</button>
                      <?php elseif (strtolower($row['status']) === 'failed'): ?>
                        <a href="#" class="link-primary" style="font-size: 12px; font-weight: 600;">Coba Lagi</a>
                      <?php else: ?>
                        <div class="td-desc">—</div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-footer">
          <div class="page-info">Menampilkan <?= count($report_list) ?> dari <?= $total_reports ?> laporan</div>
          <div class="page-controls">
            <button class="btn-page">Sebelumnya</button>
            <div class="page-num active">1</div>
            <button class="btn-page">Berikutnya</button>
          </div>
        </div>
      </div>

      <!-- Info Note -->
      <div class="info-note" style="margin-top: 16px; margin-bottom: 24px; color: var(--text-gray); font-size: 11px;">
        ⓘ Unduh tersedia hanya untuk laporan Completed. Jika laporan gagal, gunakan Coba Lagi untuk mengirim ulang permintaan.
      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto; display: flex; justify-content: space-between;">
        <span>© <?= date('Y') ?> <?= h($_SESSION['clinic_name']) ?> · Panel Kontrol Admin</span>
        <span id="clock-main">Zona waktu Asia/Jakarta (WIB)</span>
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
    }
    updateClock();
    setInterval(updateClock, 60000);
  </script>

</body>
</html>