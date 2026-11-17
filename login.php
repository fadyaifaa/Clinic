<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/config/database.php';

// Handle logout action
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    header('Location: login.php');
    exit;
}

$err = '';
$identifier = trim($_POST['email_or_phone'] ?? '');
$password = $_POST['password'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$identifier || !$password) {
        $err = 'Email/nomor telepon dan kata sandi wajib diisi.';
    } else {
        // Cari user berdasarkan email atau nomor telepon
        $stmt = $conn->prepare("SELECT id, name, email, phone, password_hash, is_active FROM users WHERE (email = ? OR phone = ?) LIMIT 1");
        $stmt->bind_param('ss', $identifier, $identifier);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();

        if ($user && (int)$user['is_active'] === 1 && password_verify($password, $user['password_hash'])) {
            // Ambil semua klinik & peran yang terafiliasi dengan akun ini
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
            $stmtRoles->bind_param('i', $user['id']);
            $stmtRoles->execute();
            $results = $stmtRoles->get_result()->fetch_all(MYSQLI_ASSOC);

            // Kelompokkan data berdasarkan clinic_id
            $clinicsMap = [];
            foreach ($results as $row) {
                $cid = $row['clinic_id'] !== null ? (int)$row['clinic_id'] : 0;
                if (!isset($clinicsMap[$cid])) {
                    $clinicsMap[$cid] = [
                        'clinic_id'   => $cid,
                        'clinic_name' => $row['clinic_name'] ?? 'Klinik',
                        'clinic_code' => $row['clinic_code'] ?? 'klinik',
                        'roles'       => []
                    ];
                }
                $clinicsMap[$cid]['roles'][] = $row['role_name'];
            }

            // Jika akun hanya memiliki 1 role pada 1 klinik:
            // Langsung arahkan ke dashboard tanpa menampilkan halaman role-switcher
            $isSingleRoleSingleClinic = false;
            if (count($clinicsMap) === 1) {
                $onlyClinic = reset($clinicsMap);
                if (count($onlyClinic['roles']) === 1) {
                    $isSingleRoleSingleClinic = true;
                }
            } elseif (count($clinicsMap) === 0) {
                $isSingleRoleSingleClinic = true;
            }

            if ($isSingleRoleSingleClinic) {
                $firstClinic = !empty($clinicsMap) ? reset($clinicsMap) : null;
                $_SESSION['user_id']     = $user['id'];
                $_SESSION['user_name']   = $user['name'];
                $_SESSION['clinic_id']   = $firstClinic ? $firstClinic['clinic_id'] : 1;
                $_SESSION['clinic_name'] = $firstClinic ? $firstClinic['clinic_name'] : 'Klinik';
                $_SESSION['clinic_code'] = $firstClinic ? $firstClinic['clinic_code'] : 'klinik';
                $_SESSION['role_name']   = ($firstClinic && !empty($firstClinic['roles'])) ? $firstClinic['roles'][0] : 'admin';

                header('Location: admin/dashboard.php');
                exit;
            } else {
                // Memiliki beberapa role atau beberapa klinik:
                // Tampilkan halaman role-switcher.php
                $_SESSION['pending_user_id']   = $user['id'];
                $_SESSION['pending_user_name'] = $user['name'];
                header('Location: role-switcher.php');
                exit;
            }
        } else {
            $err = 'Email/nomor telepon atau kata sandi tidak sesuai.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Masuk - KlinikKu</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --primary: #2563EB;
      --primary-hover: #1D4ED8;
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
    .login-container {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 40px 20px 60px 20px;
    }

    .login-card {
      background: var(--card-bg);
      border: 1px solid var(--border-color);
      border-radius: 16px;
      padding: 38px 36px;
      max-width: 440px;
      width: 100%;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03), 0 2px 4px -2px rgba(0, 0, 0, 0.03);
    }

    .login-header {
      margin-bottom: 24px;
    }

    .login-header h1 {
      font-family: 'Outfit', sans-serif;
      font-size: 26px;
      font-weight: 800;
      color: var(--text-main);
      letter-spacing: -0.4px;
      margin-bottom: 8px;
    }

    .login-header p {
      font-size: 13.5px;
      color: var(--text-muted);
      line-height: 1.45;
    }

    .alert-error {
      padding: 12px 14px;
      background-color: #FEE2E2;
      color: #991B1B;
      border: 1px solid #F87171;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 13px;
      font-weight: 500;
    }

    .form-group {
      margin-bottom: 18px;
    }

    .form-group label {
      display: block;
      font-size: 13.5px;
      font-weight: 600;
      color: var(--text-main);
      margin-bottom: 6px;
    }

    .input-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .form-control {
      width: 100%;
      padding: 11px 14px;
      font-family: 'Inter', sans-serif;
      font-size: 14px;
      color: var(--text-main);
      background: #FFFFFF;
      border: 1px solid var(--border-color);
      border-radius: 8px;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .form-control:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .form-control::placeholder {
      color: #94A3B8;
    }

    .input-wrapper .form-control {
      padding-right: 42px;
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      background: transparent;
      border: none;
      cursor: pointer;
      color: #64748B;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 4px;
    }

    .toggle-password:hover {
      color: var(--text-main);
    }

    .form-options {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 12px;
      margin-bottom: 22px;
      font-size: 13px;
    }

    .remember-me {
      display: flex;
      align-items: center;
      gap: 7px;
      color: var(--text-muted);
      cursor: pointer;
    }

    .remember-me input {
      accent-color: var(--primary);
      width: 15px;
      height: 15px;
      cursor: pointer;
    }

    .forgot-link {
      color: var(--primary);
      font-weight: 500;
      transition: color 0.2s;
    }

    .forgot-link:hover {
      text-decoration: underline;
    }

    .btn-submit {
      width: 100%;
      padding: 12px;
      background: var(--primary);
      color: #FFFFFF;
      border: none;
      border-radius: 9px;
      font-family: 'Inter', sans-serif;
      font-size: 14.5px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-submit:hover {
      background: var(--primary-hover);
    }

    /* Divider */
    .divider {
      border: none;
      border-top: 1px solid #F1F5F9;
      margin: 26px 0 20px 0;
    }

    /* Footer Links */
    .register-options-header {
      font-size: 13px;
      color: var(--text-muted);
      margin-bottom: 12px;
    }

    .register-links {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    .register-link-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      color: var(--primary);
      font-size: 13.5px;
      font-weight: 500;
      transition: transform 0.15s ease, color 0.15s ease;
      cursor: pointer;
    }

    .register-link-item:hover {
      color: var(--primary-hover);
      text-decoration: none;
    }

    .register-link-item svg {
      width: 15px;
      height: 15px;
      stroke-width: 2.2;
      transition: transform 0.15s ease;
    }

    .register-link-item:hover svg {
      transform: translateX(3px);
    }

    @media (max-width: 640px) {
      .navbar {
        padding: 0 20px;
      }
      .login-card {
        padding: 28px 22px;
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

  <!-- Login Card Form -->
  <main class="login-container">
    <div class="login-card">
      <div class="login-header">
        <h1>Selamat Datang Kembali</h1>
        <p>Masuk menggunakan email atau nomor telepon terdaftar</p>
      </div>

      <?php if (!empty($err)): ?>
        <div class="alert-error"><?= htmlspecialchars($err) ?></div>
      <?php endif; ?>

      <form method="POST" action="login.php">
        <div class="form-group">
          <label for="email_or_phone">Email</label>
          <input type="text" id="email_or_phone" name="email_or_phone" class="form-control" placeholder="Masukkan email" value="<?= htmlspecialchars($identifier) ?>" required autofocus>
        </div>

        <div class="form-group">
          <label for="password">Kata Sandi</label>
          <div class="input-wrapper">
            <input type="password" id="password" name="password" class="form-control" placeholder="Masukkan kata sandi" required>
            <button type="button" class="toggle-password" id="btnTogglePassword" onclick="togglePasswordVisibility()" aria-label="Lihat kata sandi">
              <span id="eyeIcon">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                  <circle cx="12" cy="12" r="3"></circle>
                </svg>
              </span>
            </button>
          </div>
        </div>

        <div class="form-options">
          <label class="remember-me">
            <input type="checkbox" name="remember" id="remember">
            <span>Ingat saya</span>
          </label>
          <a href="#" class="forgot-link" onclick="alert('Silakan hubungi administrator klinik Anda untuk mereset kata sandi.'); return false;">Lupa kata sandi?</a>
        </div>

        <button type="submit" class="btn-submit">Masuk</button>
      </form>

      <hr class="divider">

      <div class="register-options-header">Belum punya akun?</div>
      <div class="register-links">
        <a href="#" class="register-link-item" onclick="alert('Pendaftaran Akun Pasien akan segera dibuka. Silakan hubungi bagian pendaftaran klinik Anda.'); return false;">
          <span>Daftar Akun Pasien</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>

        <!-- Target diarahkan ke registrasi-klinik.php sesuai instruksi pengguna -->
        <a href="registrasi-klinik.php" class="register-link-item">
          <span>Daftarkan Klinik Baru</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>

        <a href="#" class="register-link-item" onclick="alert('Pendaftaran Staf / Tenaga Medis memerlukan persetujuan klinik. Silakan hubungi pemilik/pengelola klinik Anda.'); return false;">
          <span>Gabung sebagai Staf / Tenaga Medis</span>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">
            <line x1="5" y1="12" x2="19" y2="12"></line>
            <polyline points="12 5 19 12 12 19"></polyline>
          </svg>
        </a>
      </div>

    </div>
  </main>

  <script>
    function togglePasswordVisibility() {
      const pwd = document.getElementById('password');
      const eyeIcon = document.getElementById('eyeIcon');
      if (pwd.type === 'password') {
        pwd.type = 'text';
        eyeIcon.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>`;
      } else {
        pwd.type = 'password';
        eyeIcon.innerHTML = `<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>`;
      }
    }
  </script>

</body>
</html>
