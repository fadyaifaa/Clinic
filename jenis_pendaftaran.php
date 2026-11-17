<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pilih Jenis Pendaftaran - KlinikKu</title>
  
  <!-- Google Fonts: Outfit & Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  
  <style>
    :root {
      --primary: #2563EB;
      --primary-hover: #1D4ED8;
      --primary-light: #EFF6FF;
      --primary-light-border: #DBEAFE;
      --bg-page: #F8FAFC;
      --card-bg: #FFFFFF;
      --text-main: #0F172A;
      --text-muted: #64748B;
      --border-color: #E2E8F0;
      --border-hover: #CBD5E1;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      text-decoration: none;
    }

    body {
      font-family: 'Inter', sans-serif;
      background-color: var(--bg-page);
      color: var(--text-main);
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      -webkit-font-smoothing: antialiased;
    }

    /* Navbar */
    .navbar {
      height: 72px;
      background: #FFFFFF;
      border-bottom: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 40px;
    }

    .logo-container {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
    }

    .logo-icon {
      width: 32px;
      height: 32px;
      background: var(--primary);
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #FFFFFF;
    }

    .logo-icon svg {
      width: 18px;
      height: 18px;
      stroke-width: 2.8;
    }

    .logo-text {
      font-family: 'Outfit', sans-serif;
      font-size: 22px;
      font-weight: 700;
      color: var(--primary);
      letter-spacing: -0.2px;
    }

    .btn-masuk {
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      font-weight: 500;
      color: #1E293B;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 8px 20px;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .btn-masuk:hover {
      background: #F1F5F9;
      border-color: #CBD5E1;
    }

    /* Main Container */
    .main-wrapper {
      flex: 1;
      max-width: 860px;
      width: 100%;
      margin: 32px auto 60px auto;
      padding: 0 24px;
    }

    /* Back Link */
    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: var(--text-muted);
      font-size: 13.5px;
      font-weight: 500;
      transition: color 0.2s ease;
      margin-bottom: 28px;
    }

    .back-link:hover {
      color: var(--text-main);
    }

    .back-link svg {
      width: 15px;
      height: 15px;
      stroke-width: 2.2;
    }

    /* Title & Description */
    .header-section {
      text-align: center;
      margin-bottom: 36px;
    }

    .header-section h1 {
      font-family: 'Outfit', sans-serif;
      font-size: 32px;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.5px;
      margin-bottom: 10px;
    }

    .header-section p {
      font-size: 14.5px;
      color: var(--text-muted);
      max-width: 620px;
      margin: 0 auto;
      line-height: 1.5;
    }

    /* Selection Grid */
    .selection-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-bottom: 24px;
    }

    .select-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 24px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      transition: all 0.2s ease;
      position: relative;
    }

    .select-card:hover {
      border-color: var(--border-hover);
      box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.04), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
      transform: translateY(-2px);
    }

    .card-top {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 16px;
    }

    .card-icon {
      width: 44px;
      height: 44px;
      border-radius: 12px;
      background: var(--primary-light);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary);
      flex-shrink: 0;
    }

    .card-icon svg {
      width: 22px;
      height: 22px;
      stroke-width: 2;
    }

    .card-titles {
      display: flex;
      flex-direction: column;
    }

    .card-titles h3 {
      font-family: 'Outfit', sans-serif;
      font-size: 18px;
      font-weight: 700;
      color: var(--text-main);
      line-height: 1.25;
    }

    .card-titles span {
      font-size: 13px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .card-desc {
      font-size: 13.5px;
      line-height: 1.55;
      color: var(--text-muted);
      margin-bottom: 22px;
      flex-grow: 1;
    }

    /* Action Button */
    .btn-action {
      display: flex;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      background: var(--primary-light);
      color: var(--primary);
      border: 1px solid transparent;
      border-radius: 10px;
      padding: 10px 16px;
      font-size: 13.5px;
      font-weight: 600;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .btn-action:hover {
      background: #E0E7FF;
      color: var(--primary-hover);
    }

    .btn-action svg {
      width: 16px;
      height: 16px;
      stroke-width: 2.2;
      transition: transform 0.2s ease;
    }

    .select-card:hover .btn-action svg {
      transform: translateX(3px);
    }

    /* Info Banner */
    .info-banner {
      background: var(--primary-light);
      border: 1px solid var(--primary-light-border);
      border-radius: 12px;
      padding: 16px 20px;
      display: flex;
      align-items: flex-start;
      gap: 14px;
      margin-bottom: 30px;
    }

    .info-icon {
      color: var(--primary);
      flex-shrink: 0;
      margin-top: 1px;
    }

    .info-icon svg {
      width: 20px;
      height: 20px;
      stroke-width: 2;
    }

    .info-content h4 {
      font-size: 13.5px;
      font-weight: 700;
      color: #1E293B;
      margin-bottom: 4px;
    }

    .info-content p {
      font-size: 13px;
      color: var(--text-muted);
      line-height: 1.5;
    }

    /* Footer Text */
    .footer-login {
      text-align: center;
      font-size: 13.5px;
      color: var(--text-muted);
    }

    .footer-login a {
      color: var(--primary);
      font-weight: 600;
      margin-left: 4px;
    }

    .footer-login a:hover {
      text-decoration: underline;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .navbar {
        padding: 0 20px;
      }
      .selection-grid {
        grid-template-columns: 1fr;
      }
      .header-section h1 {
        font-size: 26px;
      }
      .main-wrapper {
        margin-top: 20px;
      }
    }
  </style>
</head>
<body>

  <!-- Navbar -->
  <header class="navbar">
    <a href="index.php" class="logo-container">
      <div class="logo-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      </div>
      <div class="logo-text">KlinikKu</div>
    </a>
    <div>
      <a href="login.php" class="btn-masuk">Masuk</a>
    </div>
  </header>

  <!-- Main Content -->
  <main class="main-wrapper">
    <!-- Tombol Kembali -->
    <a href="index.php" class="back-link">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
        <line x1="19" y1="12" x2="5" y2="12"></line>
        <polyline points="12 19 5 12 12 5"></polyline>
      </svg>
      Kembali ke beranda
    </a>

    <!-- Header Judul -->
    <div class="header-section">
      <h1>Pilih Jenis Pendaftaran</h1>
      <p>Anda ingin mendaftar sebagai apa? Pilih jenis akun yang sesuai untuk melanjutkan.</p>
    </div>

    <!-- Grid Pilihan Akun -->
    <div class="selection-grid">
      
      <!-- Card 1: Klinik -->
      <div class="select-card">
        <div>
          <div class="card-top">
            <div class="card-icon">
              <!-- Building-2 Icon -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M6 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18Z"></path>
                <path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h2"></path>
                <path d="M18 9h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2h-2"></path>
                <path d="M10 6h4"></path>
                <path d="M10 10h4"></path>
                <path d="M10 14h4"></path>
                <path d="M10 18h4"></path>
              </svg>
            </div>
            <div class="card-titles">
              <h3>Klinik</h3>
              <span>Pemilik / pengelola klinik</span>
            </div>
          </div>
          <p class="card-desc">Daftarkan klinik baru dan buat ruang kerja mandiri untuk mengelola klinik Anda.</p>
        </div>
        <a href="registrasi-klinik.php" class="btn-action">
          <span>Daftar Klinik</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
      </div>

      <!-- Card 2: Pasien -->
      <div class="select-card">
        <div>
          <div class="card-top">
            <div class="card-icon">
              <!-- User Icon -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
              </svg>
            </div>
            <div class="card-titles">
              <h3>Pasien</h3>
              <span>Akun pasien</span>
            </div>
          </div>
          <p class="card-desc">Buat akun pribadi sebagai pasien untuk menggunakan layanan kesehatan di klinik.</p>
        </div>
        <a href="#" class="btn-action" onclick="alertPendaftaran('Pasien'); return false;">
          <span>Daftar Pasien</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
      </div>

      <!-- Card 3: Dokter -->
      <div class="select-card">
        <div>
          <div class="card-top">
            <div class="card-icon">
              <!-- Stethoscope Icon -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4.5 3v5a6.5 6.5 0 0 0 13 0V3"></path>
                <path d="M3 3h3"></path>
                <path d="M16 3h3"></path>
                <path d="M11 14.5v3.5a4 4 0 0 0 8 0v-2"></path>
                <circle cx="19" cy="14" r="2"></circle>
              </svg>
            </div>
            <div class="card-titles">
              <h3>Dokter</h3>
              <span>Akun tenaga medis</span>
            </div>
          </div>
          <p class="card-desc">Daftar sebagai dokter untuk bergabung dengan ruang kerja klinik setelah mendapat persetujuan.</p>
        </div>
        <a href="#" class="btn-action" onclick="alertPendaftaran('Dokter'); return false;">
          <span>Daftar Dokter</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
      </div>

      <!-- Card 4: Apoteker -->
      <div class="select-card">
        <div>
          <div class="card-top">
            <div class="card-icon">
              <!-- Pill Icon -->
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"></path>
                <path d="m8.5 8.5 7 7"></path>
              </svg>
            </div>
            <div class="card-titles">
              <h3>Apoteker</h3>
              <span>Akun tenaga medis</span>
            </div>
          </div>
          <p class="card-desc">Daftar sebagai apoteker untuk bergabung dengan ruang kerja klinik setelah mendapat persetujuan.</p>
        </div>
        <a href="#" class="btn-action" onclick="alertPendaftaran('Apoteker'); return false;">
          <span>Daftar Apoteker</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
      </div>

    </div>

    <!-- Info Banner Persetujuan Klinik -->
    <div class="info-banner">
      <div class="info-icon">
        <!-- Shield Check Icon -->
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"></path>
          <path d="m9 12 2 2 4-4"></path>
        </svg>
      </div>
      <div class="info-content">
        <h4>Akses tenaga medis mengikuti persetujuan klinik</h4>
        <p>Akun dokter dan apoteker tidak membuat ruang kerja baru. Akses data diberikan setelah verifikasi dan otorisasi oleh klinik terkait.</p>
      </div>
    </div>

    <!-- Bottom Link -->
    <div class="footer-login">
      Sudah memiliki akun? <a href="login.php">Masuk</a>
    </div>

  </main>

  <script>
    function alertPendaftaran(role) {
      alert('Pendaftaran ' + role + ' akan segera tersedia. Saat ini hanya Pendaftaran Klinik yang dapat digunakan.');
    }
  </script>

</body>
</html>
