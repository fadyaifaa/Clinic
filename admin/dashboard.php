<?php
session_start();

// Buat data login buatan (bypass)
$_SESSION['clinic_id'] = 1; 
$_SESSION['clinic_name'] = 'Klinik Sehat Bersama';
$_SESSION['clinic_code'] = 'klinik-sehat';
$_SESSION['user_name'] = 'Dahlia Cahya';

// Panggil koneksi database
require_once __DIR__ . '/../config/database.php';
// require_once __DIR__ . '/../includes/helpers.php'; // Biarkan ini tetap mati

// TAMBAHKAN FUNGSI INI SEBAGAI PENGGANTI helpers.php
function fetchCount($conn, $sql, $id) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? (int)$result->fetch_row()[0] : 0;
}

// TAMBAHKAN FUNGSI INI JUGA (Pengganti helpers h())
function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

$id = (int)$_SESSION['clinic_id'];

// ... (sisa kode fetchCount dan SELECT lainnya di bawah sini tetap biarkan sama) ...

// Mengambil Data Statistik dari Database
$total = fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id=? AND status='active'", $id);
$new = fetchCount($conn, "SELECT COUNT(*) FROM patient_clinics WHERE clinic_id=? AND registered_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')", $id);
$queue = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status IN ('registered','waiting','in_progress')", $id);
$called = fetchCount($conn, "SELECT COUNT(*) FROM registrations WHERE clinic_id=? AND DATE(registration_date)=CURDATE() AND status='in_progress'", $id);
$rx = fetchCount($conn, "SELECT COUNT(*) FROM prescriptions WHERE clinic_id=? AND status IN ('issued','partially_dispensed')", $id);
$rxReady = fetchCount($conn, "SELECT COUNT(*) FROM prescriptions WHERE clinic_id=? AND status='issued'", $id);
$low = fetchCount($conn, "SELECT COUNT(*) FROM (SELECT cm.id,cm.minimum_stock,COALESCE(SUM(mb.quantity_on_hand),0) qty FROM clinic_medicines cm LEFT JOIN medicine_batches mb ON mb.clinic_medicine_id=cm.id WHERE cm.clinic_id=? AND cm.is_active=1 GROUP BY cm.id,cm.minimum_stock) x WHERE x.qty<=x.minimum_stock", $id);
$exp = fetchCount($conn, "SELECT COUNT(*) FROM medicine_batches mb JOIN clinic_medicines cm ON cm.id=mb.clinic_medicine_id WHERE cm.clinic_id=? AND mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) AND mb.quantity_on_hand>0", $id);

// Mengambil Data Tabel (Limit 10)
$s = $conn->prepare("SELECT p.full_name, d.specialization, r.visit_type, r.status, r.registration_number FROM registrations r JOIN patients p ON p.id=r.patient_id LEFT JOIN doctors d ON d.id=r.doctor_id WHERE r.clinic_id=? AND DATE(r.registration_date)=CURDATE() ORDER BY r.created_at DESC LIMIT 10");
$s->bind_param('i', $id);
$s->execute();
$registrations = $s->get_result()->fetch_all(MYSQLI_ASSOC);

$s = $conn->prepare("SELECT m.name, mb.batch_number, mb.expiry_date, mb.quantity_on_hand, cm.minimum_stock FROM medicine_batches mb JOIN clinic_medicines cm ON cm.id=mb.clinic_medicine_id JOIN medicines m ON m.id=cm.medicine_id WHERE cm.clinic_id=? AND mb.quantity_on_hand>0 AND (mb.quantity_on_hand<=cm.minimum_stock OR mb.expiry_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY)) ORDER BY mb.expiry_date LIMIT 10");
$s->bind_param('i', $id);
$s->execute();
$stocks = $s->get_result()->fetch_all(MYSQLI_ASSOC);

function statusBadgeHtml($status) {
    switch ($status) {
        case 'registered':
        case 'waiting':
            return '<span class="status-badge status-gray">Menunggu</span>';
        case 'in_progress':
            return '<span class="status-badge status-blue">Dipanggil</span>';
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
  <title>Dashboard Admin - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css"> 
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
        <span class="sidebar-subtitle">DASHBOARD KLINIK</span>
        <span class="sidebar-title"><?= h($_SESSION['clinic_name']) ?></span>
      </div>
      
      <nav class="sidebar-nav">
        <a href="dashboard.php" class="nav-item active">
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
        <a href="laporan-operasional.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          Laporan Operasional
        </a>
        <a href="pengaturan-klinik.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0

          Pengaturan Klinik
        </a>
      </nav>
      
      <div class="flex-spacer"></div>
      
      <div class="system-status">
        <div class="status-header">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#047857" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
          <span>Sistem klinik beroperasi normal</span>
        </div>
        <div class="status-meta">1 tenant aktif · Asia/Jakarta (WIB)</div>
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
        <div class="breadcrumb">Dashboard / <?= h($_SESSION['clinic_name']) ?></div>
        <div class="page-title-row">
          <div class="page-title-group">
            <h1>Ringkasan operasional klinik</h1>
            <p>Pantau pasien, registrasi, resep, dan stok obat klinik ini dalam satu dashboard tenant tunggal.</p>
          </div>
          <div class="datetime-badge-right">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
            <span id="clock-main"></span>
          </div>
        </div>
      </div>

      <!-- Quick Stats (4 Large Cards) -->
      <div class="stats-grid-large">
        <div class="stat-card-lg">
          <div class="stat-header-top">
            <span class="stat-label-lg">Pasien Terdaftar</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>
          </div>
          <div class="stat-value-lg"><?= number_format($total, 0, ',', '.') ?></div>
          <div class="stat-desc">Total pasien terikat klinik ini<br/><strong class="text-blue">+<?= number_format($new, 0, ',', '.') ?> pasien baru bulan ini</strong></div>
        </div>

        <div class="stat-card-lg">
          <div class="stat-header-top">
            <span class="stat-label-lg">Antrean Hari Ini</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
          </div>
          <div class="stat-value-lg"><?= number_format($queue, 0, ',', '.') ?></div>
          <div class="stat-desc">Registrasi kunjungan aktif hari ini<br/><span class="text-gray"><?= number_format($called, 0, ',', '.') ?> pasien sedang dipanggil</span></div>
        </div>

        <div class="stat-card-lg">
          <div class="stat-header-top">
            <span class="stat-label-lg">Resep Diproses</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#2563EB" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path></svg>
          </div>
          <div class="stat-value-lg"><?= number_format($rx, 0, ',', '.') ?></div>
          <div class="stat-desc">Layanan farmasi aktif hari ini<br/><span class="text-gray"><?= number_format($rxReady, 0, ',', '.') ?> resep siap dibagikan</span></div>
        </div>

        <div class="stat-card-lg">
          <div class="stat-header-top">
            <span class="stat-label-lg">Stok Menipis</span>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
          </div>
          <div class="stat-value-lg"><?= number_format($low, 0, ',', '.') ?> item</div>
          <div class="stat-desc">Stok di bawah minimum_stock<br/><strong class="text-orange"><?= number_format($exp, 0, ',', '.') ?> batch mendekati expired</strong></div>
        </div>
      </div>

      <!-- Two Columns Layout (Tables) -->
      <div class="dashboard-grid-2">
        
        <!-- KOLOM KIRI: Ringkasan Registrasi Pasien Hari Ini -->
        <div class="data-panel">
          <div class="panel-header">
            <div class="panel-title-group">
              <h3>Ringkasan Registrasi Pasien Hari Ini</h3>
              <p>Tabel registrasi kunjungan aktif klinik ini</p>
            </div>
            <button class="btn-primary-small">+ Registrasi Pasien Baru</button>
          </div>

          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Nama pasien</th>
                  <th>Dokter bertugas</th>
                  <th>Tipe kunjungan</th>
                  <th>Status antrean</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$registrations): ?>
                  <tr>
                    <td colspan="4" style="text-align: center; padding: 30px; color: #64748B;">
                      Belum ada data kunjungan hari ini.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($registrations as $r): ?>
                    <tr>
                      <td>
                        <div class="td-main"><?= h($r['full_name']) ?></div>
                        <div class="td-sub-small"><?= h($r['registration_number']) ?></div>
                      </td>
                      <td><div class="td-desc"><?= h($r['specialization'] ?? 'Umum') ?></div></td>
                      <td><div class="td-desc"><?= h($r['visit_type']) ?></div></td>
                      <td><?= statusBadgeHtml($r['status']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- KOLOM KANAN: Stok Obat & Peringatan Expired -->
        <div class="data-panel">
          <div class="panel-header">
            <div class="panel-title-group">
              <h3>Stok Obat & Peringatan Expired</h3>
              <p>Batch obat, mendekati kedaluwarsa dan stok minimum</p>
            </div>
            <?php if (count($stocks) > 0): ?>
                <span class="badge-orange-pill"><?= count($stocks) ?> batch perlu tindak lanjut</span>
            <?php endif; ?>
          </div>

          <div class="table-responsive">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Obat</th>
                  <th>Batch</th>
                  <th>Expired</th>
                  <th>Stok</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$stocks): ?>
                  <tr>
                    <td colspan="5" style="text-align: center; padding: 30px; color: #64748B;">
                      Semua stok obat aman.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($stocks as $r): 
                    $near = !empty($r['expiry_date']) && $r['expiry_date'] <= date('Y-m-d', strtotime('+30 days'));
                    $isLow = $r['quantity_on_hand'] <= $r['minimum_stock'];
                  ?>
                    <tr>
                      <td><div class="td-main"><?= h($r['name']) ?></div></td>
                      <td><div class="td-desc"><?= h($r['batch_number']) ?></div></td>
                      <td><div class="td-desc"><?= h(date('d M Y', strtotime($r['expiry_date']))) ?></div></td>
                      <td><div class="<?= $isLow ? 'text-red font-semibold' : 'td-desc' ?>"><?= h($r['quantity_on_hand']) ?></div></td>
                      <td>
                          <?php if ($near): ?>
                              <span class="link-orange">Mendekati expired</span>
                          <?php elseif ($isLow): ?>
                              <span class="link-orange">Stok menipis</span>
                          <?php else: ?>
                              <span class="link-orange">Periksa batch</span>
                          <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto;">
        <span>© <?= date('Y') ?> <?= h($_SESSION['clinic_name']) ?> · Dashboard Admin Klinik</span>
        <span>Zona waktu Asia/Jakarta (WIB)</span>
      </footer>

    </main>
  </div>

  <script>
    function updateClock() {
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date()) + ' WIB';
        document.getElementById('clock-top').textContent = s;
        document.getElementById('clock-main').textContent = s;
    }
    updateClock();
    setInterval(updateClock, 60000);
  </script>

</body>
</html>