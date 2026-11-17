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
  <link rel="stylesheet" href="assets/css/login.css">
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
