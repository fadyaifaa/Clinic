<?php
require_once __DIR__ . '/config/database.php';

function n(mysqli $db, string $sql): int {
    return (int) $db->query($sql)->fetch_row()[0];
}

function e($v) {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$totalKlinik = n($conn, "SELECT COUNT(*) FROM clinics WHERE is_active=1");
$totalPasien = n($conn, "SELECT COUNT(*) FROM patients");
$totalDokter = n($conn, "SELECT COUNT(*) FROM doctors WHERE is_active=1");
$antrean = n($conn, "SELECT COUNT(*) FROM registrations WHERE registration_date >= CURDATE() AND registration_date < CURDATE() + INTERVAL 1 DAY AND status IN ('registered','waiting','in_progress')");

$recent = $conn->query("
    SELECT r.registration_number, p.full_name, r.status 
    FROM registrations r 
    JOIN patients p ON p.id = r.patient_id 
    ORDER BY r.created_at DESC 
    LIMIT 2
")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>KlinikKu - Sistem Manajemen Klinik</title>
  
  <!-- Google Fonts: Outfit (Heading) & Inter (Body) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">
  
  <!-- Link ke file CSS terpisah -->
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

  <!-- Navbar -->
  <header class="navbar">
    <div class="logo-container">
      <div class="logo-icon"><div class="logo-inner"></div></div>
      <div class="logo-text">KlinikKu</div>
    </div>
    <nav class="nav-links">
      <a href="#fitur">Fitur</a>
      <a href="#tentang">Tentang</a>
      <a href="#statistik">Statistik</a>
      <a href="#kontak">Kontak</a>
    </nav>
    <div class="nav-actions">
      <a href="login.php" class="btn-login">Masuk</a>
      <a href="registrasi-klinik.php" class="btn-primary">Daftar Sekarang</a>
    </div>
  </header>

  <!-- Hero Section -->
  <section class="hero">
    <div class="hero-content">
      <div class="badge">✨ Web-Based Clinic Management System v2.0</div>
      <h1 class="hero-title">Kelola Klinik Anda dengan Lebih Mudah</h1>
      <p class="hero-subtitle">Sistem manajemen klinik digital yang membantu Anda mengelola pasien, jadwal dokter, rekam medis, dan operasional klinik dalam satu platform terintegrasi.</p>
      <a href="registrasi-klinik.php" class="btn-primary btn-large">Mulai Gratis</a>
    </div>
    
    <div class="hero-visual">
      <div class="dashboard-card">
        <div class="dashboard-header">
          <div class="mac-buttons">
            <div class="dot dot-red"></div>
            <div class="dot dot-yellow"></div>
            <div class="dot dot-green"></div>
            <div class="header-badge">Dashboard Utama</div>
          </div>
          <div class="header-skeleton"></div>
        </div>
        
        <div class="stats-grid">
          <div class="stat-box">
            <div class="stat-num text-blue"><?= number_format($totalPasien, 0, ',', '.') ?></div>
            <div class="stat-label">Pasien</div>
          </div>
          <div class="stat-box">
            <div class="stat-num text-green"><?= number_format($antrean, 0, ',', '.') ?></div>
            <div class="stat-label">Antrean</div>
          </div>
          <div class="stat-box">
            <div class="stat-num text-indigo"><?= number_format($totalDokter, 0, ',', '.') ?></div>
            <div class="stat-label">Dokter Aktif</div>
          </div>
        </div>
        
        <div class="queue-list">
          <div class="queue-title">Antrean Pasien Terkini</div>
          <hr class="divider">
          
          <?php if (!$recent): ?>
            <p>Belum ada registrasi hari ini.</p>
          <?php else: ?>
            <?php foreach ($recent as $r): ?>
              <div class="queue-item">
                <div class="queue-info">
                  <span class="queue-id"><?= e($r['registration_number']) ?></span>
                  <span class="queue-name"><?= e($r['full_name']) ?></span>
                </div>
                <span class="status-badge status-<?= e($r['status'] == 'waiting' ? 'waiting' : 'active') ?>"><?= e($r['status']) ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
          
        </div>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section class="features" id="fitur">
    <div class="section-header">
      <div class="subtitle">LAYANAN TERBAIK</div>
      <h2 class="title">Fitur Unggulan KlinikKu</h2>
      <p class="desc">Didesain khusus untuk memenuhi kebutuhan operasional klinik modern, praktis, dan aman.</p>
    </div>
    
    <div class="features-grid">
      <div class="feature-card">
        <div class="feature-icon"><div class="icon-inner"></div></div>
        <h3 class="feature-title">Manajemen Pasien</h3>
        <p class="feature-desc">Kelola data pasien, rekam medis, dan riwayat kunjungan dengan mudah dan terstruktur.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><div class="icon-inner"></div></div>
        <h3 class="feature-title">Jadwal & Appointment</h3>
        <p class="feature-desc">Atur jadwal praktis dokter dan booking appointment pasien secara online tanpa tumpang tindih.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><div class="icon-inner"></div></div>
        <h3 class="feature-title">Rekam Medis Digital</h3>
        <p class="feature-desc">Akses rekam medis pasien secara digital, aman, dan terorganisir sesuai standar akreditasi.</p>
      </div>
      <div class="feature-card">
        <div class="feature-icon"><div class="icon-inner"></div></div>
        <h3 class="feature-title">Laporan & Analitik</h3>
        <p class="feature-desc">Pantau performa klinik dengan laporan kunjungan, stok obat, dan dashboard finansial otomatis.</p>
      </div>
    </div>
  </section>

  <!-- Stats Banner -->
  <section class="stats-banner" id="statistik">
    <div class="stat-item">
      <div class="stat-value"><?= number_format($totalKlinik, 0, ',', '.') ?></div>
      <div class="stat-name">Klinik Aktif</div>
    </div>
    <div class="stat-item">
      <div class="stat-value"><?= number_format($totalPasien, 0, ',', '.') ?></div>
      <div class="stat-name">Pasien Terdaftar</div>
    </div>
    <div class="stat-item">
      <div class="stat-value"><?= number_format($totalDokter, 0, ',', '.') ?></div>
      <div class="stat-name">Dokter Aktif</div>
    </div>
    <div class="stat-item">
      <div class="stat-value">99.9%</div>
      <div class="stat-name">SLA Uptime</div>
    </div>
  </section>

  <!-- CTA Section -->
  <section class="cta-section" id="tentang">
    <div class="cta-card">
      <h2 class="cta-title">Siap Mendigitalkan Klinik Anda?</h2>
      <p class="cta-desc">Daftar sekarang dan nikmati kemudahan mengelola pasien, resep, rekam medis, serta administrasi klinik Anda secara aman dalam satu dashboard digital.</p>
      <a href="registrasi-klinik.php" class="btn-primary btn-large">Daftarkan Klinik — Gratis</a>
    </div>
  </section>

  <!-- Footer -->
  <footer class="footer" id="kontak">
    <div class="footer-top">
      <div class="footer-brand">
        <div class="logo-container">
          <div class="logo-icon"><div class="logo-inner"></div></div>
          <div class="logo-text">KlinikKu</div>
        </div>
        <p class="footer-desc">Solusi inovatif digitalisasi layanan kesehatan. Mempermudah manajemen klinik demi pelayanan pasien yang optimal.</p>
      </div>
      <div class="footer-links">
        <h4 class="link-title">Produk</h4>
        <a href="#fitur">Fitur</a>
        <a href="#">Harga</a>
        <a href="#">Demo</a>
      </div>
      <div class="footer-links">
        <h4 class="link-title">Perusahaan</h4>
        <a href="#tentang">Tentang</a>
        <a href="#">Karir</a>
        <a href="#kontak">Kontak</a>
      </div>
      <div class="footer-links">
        <h4 class="link-title">Legal</h4>
        <a href="#">Kebijakan Privasi</a>
        <a href="#">Syarat & Ketentuan</a>
      </div>
    </div>
    <div class="footer-bottom">
      <p>© <?= date('Y') ?> KlinikKu. All rights reserved.</p>
      <p>Sistem Informasi Klinik Berlisensi</p>
    </div>
  </footer>

</body>
</html>