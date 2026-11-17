<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

// Pastikan user telah terautentikasi (baik pending maupun aktif)
$userId = (int)($_SESSION['pending_user_id'] ?? $_SESSION['user_id'] ?? 0);

if (!$userId) {
    header('Location: login.php');
    exit;
}

// Ambil data profil user
$stmtUser = $conn->prepare("SELECT id, name, email FROM users WHERE id = ? AND is_active = 1 LIMIT 1");
$stmtUser->bind_param('i', $userId);
$stmtUser->execute();
$user = $stmtUser->get_result()->fetch_assoc();

if (!$user) {
    header('Location: login.php?action=logout');
    exit;
}

// Ambil seluruh relasi klinik dan role riil dari database
$stmtRoles = $conn->prepare("
    SELECT 
        ur.clinic_id,
        c.name AS clinic_name,
        c.clinic_code,
        r.id AS role_id,
        r.name AS role_name,
        r.description AS role_desc
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN clinics c ON c.id = ur.clinic_id
    WHERE ur.user_id = ?
    ORDER BY c.id ASC, r.id ASC
");
$stmtRoles->bind_param('i', $userId);
$stmtRoles->execute();
$results = $stmtRoles->get_result()->fetch_all(MYSQLI_ASSOC);

function formatRoleLabel($name) {
    switch (strtolower(trim($name))) {
        case 'admin':
            return 'Admin Klinik';
        case 'doctor':
            return 'Dokter Umum';
        case 'pharmacist':
            return 'Apoteker';
        case 'patient':
            return 'Pasien';
        default:
            return ucfirst($name);
    }
}

// Kelompokkan data berdasarkan klinik
$clinicsMap = [];
foreach ($results as $row) {
    $cid = $row['clinic_id'] !== null ? (int)$row['clinic_id'] : 0;
    if (!isset($clinicsMap[$cid])) {
        $clinicsMap[$cid] = [
            'clinic_id'   => $cid,
            'clinic_name' => $row['clinic_name'] ?? 'Klinik Mandiri',
            'clinic_code' => $row['clinic_code'] ?? 'klinik',
            'roles'       => []
        ];
    }
    $clinicsMap[$cid]['roles'][] = $row['role_name'];
}

// Aturan: Jika akun hanya memiliki 1 role pada 1 klinik, jangan tampilkan halaman ini
if (count($clinicsMap) === 1) {
    $singleClinic = reset($clinicsMap);
    if (count($singleClinic['roles']) <= 1) {
        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_name']   = $user['name'];
        $_SESSION['clinic_id']   = $singleClinic['clinic_id'];
        $_SESSION['clinic_name'] = $singleClinic['clinic_name'];
        $_SESSION['clinic_code'] = $singleClinic['clinic_code'];
        $_SESSION['role_name']   = $singleClinic['roles'][0] ?? 'admin';
        unset($_SESSION['pending_user_id']);

        header('Location: admin/dashboard.php');
        exit;
    }
} elseif (count($clinicsMap) === 0) {
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user_name']   = $user['name'];
    $_SESSION['clinic_id']   = 1;
    $_SESSION['clinic_name'] = 'Klinik Utama';
    $_SESSION['clinic_code'] = 'klinik';
    $_SESSION['role_name']   = 'admin';
    unset($_SESSION['pending_user_id']);

    header('Location: admin/dashboard.php');
    exit;
}

// Format teks tampilan peran per klinik
foreach ($clinicsMap as $cid => &$cData) {
    $formattedList = array_map('formatRoleLabel', $cData['roles']);
    $cData['formatted_roles'] = implode(' • ', $formattedList);
}
unset($cData);

// Pemrosesan pemilihan klinik & role saat diklik
if (isset($_GET['select_clinic'])) {
    $selectedCid = (int)$_GET['select_clinic'];
    if (isset($clinicsMap[$selectedCid])) {
        $selectedClinic = $clinicsMap[$selectedCid];

        $_SESSION['user_id']     = $user['id'];
        $_SESSION['user_name']   = $user['name'];
        $_SESSION['clinic_id']   = $selectedClinic['clinic_id'];
        $_SESSION['clinic_name'] = $selectedClinic['clinic_name'];
        $_SESSION['clinic_code'] = $selectedClinic['clinic_code'];
        $_SESSION['role_name']   = $selectedClinic['roles'][0] ?? 'admin';
        $_SESSION['user_roles']  = $selectedClinic['roles'];
        unset($_SESSION['pending_user_id']);

        header('Location: admin/dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pilih Klinik & Akses Peran - KlinikKu</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary: #2563EB;
      --primary-hover: #1D4ED8;
      --primary-light: #EFF6FF;
      --bg-page: #F8FAFC;
      --card-bg: #FFFFFF;
      --text-main: #0F172A;
      --text-muted: #64748B;
      --border-color: #E2E8F0;
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

    .logo-text {
      font-family: 'Outfit', sans-serif;
      font-size: 22px;
      font-weight: 700;
      color: var(--primary);
      letter-spacing: -0.2px;
    }

    /* Main Container */
    .switcher-container {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px 60px 20px;
    }

    .switcher-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 38px 36px;
      max-width: 480px;
      width: 100%;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
    }

    .switcher-header {
      margin-bottom: 24px;
    }

    .switcher-header h1 {
      font-family: 'Outfit', sans-serif;
      font-size: 24px;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.4px;
      margin-bottom: 6px;
    }

    .switcher-header p {
      font-size: 13.5px;
      color: var(--text-muted);
      line-height: 1.45;
    }

    /* Clinic List */
    .clinic-list {
      display: flex;
      flex-direction: column;
      gap: 14px;
      margin-bottom: 24px;
    }

    .clinic-card {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 12px;
      padding: 16px 18px;
      transition: all 0.2s ease;
      cursor: pointer;
      color: inherit;
    }

    .clinic-card:hover {
      border-color: #CBD5E1;
      box-shadow: 0 4px 12px -2px rgba(0, 0, 0, 0.05);
      transform: translateY(-1.5px);
    }

    .clinic-card-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .clinic-card-icon {
      width: 44px;
      height: 44px;
      background: var(--primary-light);
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary);
      flex-shrink: 0;
    }

    .clinic-card-icon svg {
      width: 22px;
      height: 22px;
      stroke-width: 2;
    }

    .clinic-card-details {
      display: flex;
      flex-direction: column;
    }

    .clinic-card-name {
      font-size: 15px;
      font-weight: 700;
      color: var(--text-main);
      line-height: 1.3;
      margin-bottom: 4px;
    }

    .clinic-badge {
      display: inline-block;
      font-family: monospace;
      font-size: 11px;
      background: #F1F5F9;
      color: #475569;
      padding: 2px 7px;
      border-radius: 4px;
      border: 1px solid #E2E8F0;
      margin-bottom: 5px;
      width: fit-content;
    }

    .clinic-card-role {
      font-size: 12.5px;
      color: var(--text-muted);
    }

    .clinic-card-arrow {
      color: var(--primary);
      display: flex;
      align-items: center;
      justify-content: center;
      transition: transform 0.2s ease;
    }

    .clinic-card-arrow svg {
      width: 18px;
      height: 18px;
      stroke-width: 2.2;
    }

    .clinic-card:hover .clinic-card-arrow {
      transform: translateX(4px);
    }

    /* Back Link */
    .footer-action {
      text-align: center;
    }

    .btn-logout {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      color: var(--text-muted);
      font-size: 13.5px;
      font-weight: 500;
      transition: color 0.2s;
    }

    .btn-logout:hover {
      color: var(--text-main);
    }

    .btn-logout svg {
      width: 15px;
      height: 15px;
      stroke-width: 2.2;
    }

    @media (max-width: 640px) {
      .navbar {
        padding: 0 20px;
      }
      .switcher-card {
        padding: 28px 20px;
      }
    }
  </style>
</head>
<body>

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
  </header>

  <main class="switcher-container">
    <div class="switcher-card">
      <div class="switcher-header">
        <h1>Pilih Klinik & Akses Peran</h1>
        <p>Akun Anda terhubung dengan beberapa akses klinik.</p>
      </div>

      <div class="clinic-list">
        <?php foreach ($clinicsMap as $cid => $c): ?>
          <a href="role-switcher.php?select_clinic=<?= urlencode((string)$c['clinic_id']) ?>" class="clinic-card">
            <div class="clinic-card-left">
              <div class="clinic-card-icon">
                <!-- Building-2 / Hospital Icon -->
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
              <div class="clinic-card-details">
                <div class="clinic-card-name"><?= htmlspecialchars($c['clinic_name']) ?></div>
                <div><span class="clinic-badge"><?= htmlspecialchars($c['clinic_code']) ?></span></div>
                <div class="clinic-card-role">Role: <?= htmlspecialchars($c['formatted_roles']) ?></div>
              </div>
            </div>
            <div class="clinic-card-arrow">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
                <line x1="5" y1="12" x2="19" y2="12"></line>
                <polyline points="12 5 19 12 12 19"></polyline>
              </svg>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="footer-action">
        <a href="login.php?action=logout" class="btn-logout">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
          </svg>
          Keluar / Ganti Akun
        </a>
      </div>

    </div>
  </main>

</body>
</html>
