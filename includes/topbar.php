<?php
$topbarUserId     = (int)($_SESSION['user_id'] ?? 0);
$topbarClinicId   = (int)($_SESSION['clinic_id'] ?? 0);
$topbarRoleName   = strtolower((string)($_SESSION['role_name'] ?? 'admin'));
$topbarUserName   = $_SESSION['user_name'] ?? 'Pengguna';
$topbarUserEmail  = '';
$topbarClinicName = $_SESSION['clinic_name'] ?? 'Klinik';
$topbarClinicCode = $_SESSION['clinic_code'] ?? 'klinik';

if (!function_exists('topbarFormatRoleLabel')) {
    function topbarFormatRoleLabel($roleName) {
        switch (strtolower(trim((string)$roleName))) {
            case 'admin':      return 'Admin Klinik';
            case 'doctor':     return 'Dokter';
            case 'pharmacist': return 'Apoteker';
            case 'patient':    return 'Pasien';
            default:           return ucfirst((string)$roleName);
        }
    }
}

$topbarRolesList = [];
if (isset($conn) && $conn instanceof mysqli) {
    $stmtU = $conn->prepare("SELECT name, email FROM users WHERE id = ? LIMIT 1");
    if ($stmtU) {
        $stmtU->bind_param('i', $topbarUserId);
        $stmtU->execute();
        if ($uRow = $stmtU->get_result()->fetch_assoc()) {
            $topbarUserName  = $uRow['name'] ?: $topbarUserName;
            $topbarUserEmail = $uRow['email'] ?? '';
        }
    }

    $stmtR = $conn->prepare("
        SELECT 
            ur.role_id,
            r.name AS role_name,
            ur.clinic_id,
            c.name AS clinic_name,
            c.clinic_code
        FROM user_roles ur
        JOIN roles r ON r.id = ur.role_id
        JOIN clinics c ON c.id = ur.clinic_id
        LEFT JOIN user_clinics uc ON uc.user_id = ur.user_id AND uc.clinic_id = ur.clinic_id
        WHERE ur.user_id = ? AND c.is_active = 1
        ORDER BY c.id ASC, r.id ASC
    ");
    if ($stmtR) {
        $stmtR->bind_param('i', $topbarUserId);
        $stmtR->execute();
        $topbarRolesList = $stmtR->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

$esc = function($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
  <!-- TOP NAVBAR -->
  <header class="topbar">
    <div class="topbar-brand">
      <div class="logo-icon"><div class="logo-inner"></div></div>
      <div class="brand-info">
        <div class="brand-name"><?= $esc($topbarClinicName) ?></div>
        <div class="brand-meta">
          <span class="badge-blue"><?= $esc($topbarClinicCode) ?></span>
          <span class="meta-text">Tenant tunggal · <?= $esc($topbarClinicCode) ?>.platform.com</span>
        </div>
      </div>
    </div>

    <div class="flex-spacer"></div>

    <div class="datetime-widget">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
      <span id="clock-top"></span>
    </div>

    <button type="button" class="notif-btn" title="Notifikasi">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
      <span class="notif-dot"></span>
    </button>

    <div class="profile-dropdown-wrapper" id="profileDropdownWrapper">
      <div class="user-profile" onclick="toggleProfileDropdown(event)" role="button" tabindex="0" aria-expanded="false" aria-haspopup="true">
        <div class="avatar"><?= $esc(strtoupper(substr($topbarUserName, 0, 2))) ?></div>
        <div class="user-info">
          <span class="user-name"><?= $esc($topbarUserName) ?></span>
          <span class="user-role"><?= $esc(topbarFormatRoleLabel($topbarRoleName)) ?></span>
        </div>
        <svg class="profile-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
      </div>

      <div class="profile-dropdown-menu" id="profileDropdownMenu">
        <div class="profile-dropdown-header">
          <div class="pd-name"><?= $esc($topbarUserName) ?></div>
          <?php if ($topbarUserEmail !== ''): ?>
            <div class="pd-email"><?= $esc($topbarUserEmail) ?></div>
          <?php endif; ?>
          <span class="pd-clinic-badge"><?= $esc($topbarClinicName) ?></span>
        </div>

        <div class="profile-dropdown-section">
          <div class="pd-section-title">
            <span>Ganti Peran / Akses</span>
            <?php if (count($topbarRolesList) <= 1): ?>
              <span class="pd-single-note">1 akses aktif</span>
            <?php endif; ?>
          </div>

          <?php if (empty($topbarRolesList)): ?>
            <div class="profile-role-item active">
              <div class="pd-role-info">
                <span class="pd-role-label"><?= $esc(topbarFormatRoleLabel($topbarRoleName)) ?></span>
                <span class="pd-role-clinic"><?= $esc($topbarClinicName) ?></span>
              </div>
              <span class="pd-check">✓</span>
            </div>
          <?php else: ?>
            <?php foreach ($topbarRolesList as $rOpt): 
              $isCurrent = (strtolower($rOpt['role_name']) === $topbarRoleName && (int)$rOpt['clinic_id'] === $topbarClinicId);
            ?>
              <form method="POST" action="../switch-role.php" style="margin: 0;">
                <input type="hidden" name="role_id" value="<?= (int)$rOpt['role_id'] ?>">
                <input type="hidden" name="clinic_id" value="<?= (int)$rOpt['clinic_id'] ?>">
                <button type="submit" class="profile-role-item <?= $isCurrent ? 'active' : '' ?>">
                  <div class="pd-role-info">
                    <span class="pd-role-label"><?= $esc(topbarFormatRoleLabel($rOpt['role_name'])) ?></span>
                    <span class="pd-role-clinic"><?= $esc($rOpt['clinic_name']) ?></span>
                  </div>
                  <?php if ($isCurrent): ?>
                    <span class="pd-check">✓</span>
                  <?php endif; ?>
                </button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <hr class="profile-dropdown-divider">

        <a href="../login.php?action=logout" class="profile-logout-link">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
          Keluar (Logout)
        </a>
      </div>
    </div>
  </header>

  <script>
    function toggleProfileDropdown(event) {
      if (event) event.stopPropagation();
      const wrapper = document.getElementById('profileDropdownWrapper');
      if (!wrapper) return;
      const isOpen = wrapper.classList.toggle('open');
      const trigger = wrapper.querySelector('.user-profile');
      if (trigger) trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    }

    document.addEventListener('click', function(e) {
      const wrapper = document.getElementById('profileDropdownWrapper');
      if (wrapper && !wrapper.contains(e.target)) {
        wrapper.classList.remove('open');
        const trigger = wrapper.querySelector('.user-profile');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
      }
    });

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Escape') {
        const wrapper = document.getElementById('profileDropdownWrapper');
        if (wrapper && wrapper.classList.contains('open')) {
          wrapper.classList.remove('open');
          const trigger = wrapper.querySelector('.user-profile');
          if (trigger) trigger.setAttribute('aria-expanded', 'false');
        }
      }
    });
  </script>
