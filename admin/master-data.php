<?php
require_once __DIR__ . '/../includes/auth.php';

// 2. Panggil koneksi database
require_once __DIR__ . '/../config/database.php';

// 3. Fungsi Helper Mandiri
function h($string) {
    return htmlspecialchars((string)$string, ENT_QUOTES, 'UTF-8');
}

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

$id = (int)$_SESSION['clinic_id'];

// 4. PROSES POST (TAMBAH, EDIT, HAPUS) UNTUK SEMUA TAB
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- TAB 1: OBAT KLINIK ---
    if ($action === 'add_obat') {
        $kode = trim($_POST['medicine_code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $generik = trim($_POST['generic_name'] ?? '');
        $sediaan = trim($_POST['dosage_form'] ?? '');
        $kekuatan = trim($_POST['strength'] ?? '');
        $satuan = trim($_POST['unit'] ?? 'tablet');
        $stokMin = (float)($_POST['minimum_stock'] ?? 10);
        $resep = (int)($_POST['requires_prescription'] ?? 0);
        $aktif = (int)($_POST['is_active'] ?? 1);

        if ($kode === '' || $nama === '') {
            $_SESSION['flash_error'] = 'Kode obat dan nama obat wajib diisi.';
        } else {
            $stmtCek = $conn->prepare("SELECT id FROM medicines WHERE medicine_code = ?");
            $stmtCek->bind_param('s', $kode);
            $stmtCek->execute();
            if ($stmtCek->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = "Kode obat '{$kode}' sudah terdaftar. Gunakan kode lain.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmtIns = $conn->prepare("
                        INSERT INTO medicines (medicine_code, name, generic_name, dosage_form, strength, unit, requires_prescription, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtIns->bind_param('ssssssii', $kode, $nama, $generik, $sediaan, $kekuatan, $satuan, $resep, $aktif);
                    $stmtIns->execute();
                    $newMedId = $stmtIns->insert_id;

                    $stmtCm = $conn->prepare("
                        INSERT INTO clinic_medicines (clinic_id, medicine_id, minimum_stock, is_active)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmtCm->bind_param('iidi', $id, $newMedId, $stokMin, $aktif);
                    $stmtCm->execute();

                    $conn->commit();
                    $_SESSION['flash_success'] = "Obat '{$nama}' ({$kode}) berhasil ditambahkan ke katalog.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = "Gagal menambahkan obat: " . $e->getMessage();
                }
            }
        }
        header("Location: master-data.php?tab=obat");
        exit;
    }

    if ($action === 'edit_obat') {
        $medId = (int)($_POST['medicine_id'] ?? 0);
        $kode = trim($_POST['medicine_code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $generik = trim($_POST['generic_name'] ?? '');
        $sediaan = trim($_POST['dosage_form'] ?? '');
        $kekuatan = trim($_POST['strength'] ?? '');
        $satuan = trim($_POST['unit'] ?? 'tablet');
        $stokMin = (float)($_POST['minimum_stock'] ?? 0);
        $resep = (int)($_POST['requires_prescription'] ?? 0);
        $aktif = (int)($_POST['is_active'] ?? 1);

        if ($medId <= 0 || $kode === '' || $nama === '') {
            $_SESSION['flash_error'] = 'Data obat tidak valid.';
        } else {
            $stmtCek = $conn->prepare("SELECT id FROM medicines WHERE medicine_code = ? AND id != ?");
            $stmtCek->bind_param('si', $kode, $medId);
            $stmtCek->execute();
            if ($stmtCek->get_result()->fetch_assoc()) {
                $_SESSION['flash_error'] = "Kode obat '{$kode}' sudah digunakan oleh obat lain.";
            } else {
                $conn->begin_transaction();
                try {
                    $stmtUpd = $conn->prepare("
                        UPDATE medicines 
                        SET medicine_code = ?, name = ?, generic_name = ?, dosage_form = ?, strength = ?, unit = ?, requires_prescription = ?, is_active = ?
                        WHERE id = ?
                    ");
                    $stmtUpd->bind_param('ssssssiii', $kode, $nama, $generik, $sediaan, $kekuatan, $satuan, $resep, $aktif, $medId);
                    $stmtUpd->execute();

                    // Update atau masukkan ke clinic_medicines
                    $stmtCmCek = $conn->prepare("SELECT id FROM clinic_medicines WHERE clinic_id = ? AND medicine_id = ?");
                    $stmtCmCek->bind_param('ii', $id, $medId);
                    $stmtCmCek->execute();
                    $cmRow = $stmtCmCek->get_result()->fetch_assoc();

                    if ($cmRow) {
                        $stmtCmUpd = $conn->prepare("UPDATE clinic_medicines SET minimum_stock = ?, is_active = ? WHERE id = ?");
                        $stmtCmUpd->bind_param('dii', $stokMin, $aktif, $cmRow['id']);
                        $stmtCmUpd->execute();
                    } else {
                        $stmtCmIns = $conn->prepare("INSERT INTO clinic_medicines (clinic_id, medicine_id, minimum_stock, is_active) VALUES (?, ?, ?, ?)");
                        $stmtCmIns->bind_param('iidi', $id, $medId, $stokMin, $aktif);
                        $stmtCmIns->execute();
                    }

                    $conn->commit();
                    $_SESSION['flash_success'] = "Data obat '{$nama}' ({$kode}) berhasil diperbarui.";
                } catch (Exception $e) {
                    $conn->rollback();
                    $_SESSION['flash_error'] = "Gagal memperbarui obat: " . $e->getMessage();
                }
            }
        }
        header("Location: master-data.php?tab=obat");
        exit;
    }

    if ($action === 'delete_obat') {
        $medId = (int)($_POST['id'] ?? 0);
        if ($medId <= 0) {
            $_SESSION['flash_error'] = 'ID obat tidak valid.';
        } else {
            $conn->begin_transaction();
            try {
                // Hapus relasi klinik terlebih dahulu
                $stmtDelCm = $conn->prepare("DELETE FROM clinic_medicines WHERE medicine_id = ? AND clinic_id = ?");
                $stmtDelCm->bind_param('ii', $medId, $id);
                $stmtDelCm->execute();

                // Cek apakah ada klinik lain yang masih menggunakan obat ini
                $stmtCekCmOther = $conn->prepare("SELECT COUNT(*) FROM clinic_medicines WHERE medicine_id = ?");
                $stmtCekCmOther->bind_param('i', $medId);
                $stmtCekCmOther->execute();
                $otherCount = (int)$stmtCekCmOther->get_result()->fetch_row()[0];

                if ($otherCount === 0) {
                    $stmtDelMed = $conn->prepare("DELETE FROM medicines WHERE id = ?");
                    $stmtDelMed->bind_param('i', $medId);
                    $stmtDelMed->execute();
                }

                $conn->commit();
                $_SESSION['flash_success'] = 'Data obat berhasil dihapus dari master data.';
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                if ($e->getCode() == 1451) {
                    $_SESSION['flash_error'] = 'Obat ini tidak dapat dihapus karena sudah memiliki riwayat batch stok atau resep medis. Anda dapat menonaktifkannya melalui tombol Edit.';
                } else {
                    $_SESSION['flash_error'] = 'Gagal menghapus obat: ' . $e->getMessage();
                }
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['flash_error'] = 'Gagal menghapus obat: ' . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=obat");
        exit;
    }

    // --- TAB 2: DIAGNOSIS (ICD) ---
    if ($action === 'add_diagnosis') {
        $kode = trim($_POST['code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $deskripsi = trim($_POST['description'] ?? '');
        $sistem = trim($_POST['coding_system'] ?? 'ICD-10');

        if ($nama === '') {
            $_SESSION['flash_error'] = 'Nama diagnosis wajib diisi.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO diagnoses (code, name, description, coding_system) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('ssss', $kode, $nama, $deskripsi, $sistem);
                $stmt->execute();
                $_SESSION['flash_success'] = "Diagnosis '{$nama}' ({$kode}) berhasil ditambahkan.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Gagal menambahkan diagnosis: " . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=diagnosis");
        exit;
    }

    if ($action === 'edit_diagnosis') {
        $diagId = (int)($_POST['id'] ?? 0);
        $kode = trim($_POST['code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $deskripsi = trim($_POST['description'] ?? '');
        $sistem = trim($_POST['coding_system'] ?? 'ICD-10');

        if ($diagId <= 0 || $nama === '') {
            $_SESSION['flash_error'] = 'Data diagnosis tidak valid.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE diagnoses SET code = ?, name = ?, description = ?, coding_system = ? WHERE id = ?");
                $stmt->bind_param('ssssi', $kode, $nama, $deskripsi, $sistem, $diagId);
                $stmt->execute();
                $_SESSION['flash_success'] = "Diagnosis '{$nama}' berhasil diperbarui.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Gagal memperbarui diagnosis: " . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=diagnosis");
        exit;
    }

    if ($action === 'delete_diagnosis') {
        $diagId = (int)($_POST['id'] ?? 0);
        if ($diagId <= 0) {
            $_SESSION['flash_error'] = 'ID diagnosis tidak valid.';
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM diagnoses WHERE id = ?");
                $stmt->bind_param('i', $diagId);
                $stmt->execute();
                $_SESSION['flash_success'] = 'Data diagnosis berhasil dihapus.';
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1451) {
                    $_SESSION['flash_error'] = 'Diagnosis ini tidak dapat dihapus karena telah tercatat dalam rekam medis pasien.';
                } else {
                    $_SESSION['flash_error'] = 'Gagal menghapus diagnosis: ' . $e->getMessage();
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Gagal menghapus diagnosis: ' . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=diagnosis");
        exit;
    }

    // --- TAB 3: TARIF & TINDAKAN MEDIS ---
    if ($action === 'add_tindakan') {
        $kode = trim($_POST['code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $keterangan = trim($_POST['description'] ?? '');
        $tarif = (float)($_POST['default_price'] ?? 0);
        $aktif = (int)($_POST['is_active'] ?? 1);

        if ($nama === '') {
            $_SESSION['flash_error'] = 'Nama tindakan medis wajib diisi.';
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO medical_actions (code, name, description, default_price, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sssdi', $kode, $nama, $keterangan, $tarif, $aktif);
                $stmt->execute();
                $_SESSION['flash_success'] = "Tindakan medis '{$nama}' berhasil ditambahkan.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Gagal menambahkan tindakan medis: " . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=tindakan");
        exit;
    }

    if ($action === 'edit_tindakan') {
        $tndId = (int)($_POST['id'] ?? 0);
        $kode = trim($_POST['code'] ?? '');
        $nama = trim($_POST['name'] ?? '');
        $keterangan = trim($_POST['description'] ?? '');
        $tarif = (float)($_POST['default_price'] ?? 0);
        $aktif = (int)($_POST['is_active'] ?? 1);

        if ($tndId <= 0 || $nama === '') {
            $_SESSION['flash_error'] = 'Data tindakan medis tidak valid.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE medical_actions SET code = ?, name = ?, description = ?, default_price = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param('sssidi', $kode, $nama, $keterangan, $tarif, $aktif, $tndId);
                $stmt->execute();
                $_SESSION['flash_success'] = "Tindakan medis '{$nama}' berhasil diperbarui.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = "Gagal memperbarui tindakan medis: " . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=tindakan");
        exit;
    }

    if ($action === 'delete_tindakan') {
        $tndId = (int)($_POST['id'] ?? 0);
        if ($tndId <= 0) {
            $_SESSION['flash_error'] = 'ID tindakan medis tidak valid.';
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM medical_actions WHERE id = ?");
                $stmt->bind_param('i', $tndId);
                $stmt->execute();
                $_SESSION['flash_success'] = 'Tindakan medis berhasil dihapus.';
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1451) {
                    $_SESSION['flash_error'] = 'Tindakan medis ini tidak dapat dihapus karena telah tercatat dalam riwayat rekam medis pasien. Anda dapat mengubah statusnya menjadi "Nonaktif" melalui menu Edit.';
                } else {
                    $_SESSION['flash_error'] = 'Gagal menghapus tindakan medis: ' . $e->getMessage();
                }
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Gagal menghapus tindakan medis: ' . $e->getMessage();
            }
        }
        header("Location: master-data.php?tab=tindakan");
        exit;
    }
}

// 5. AMBIL FLASH MESSAGE
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 6. STATISTIK & DATA TAB 1: OBAT KLINIK
$total_obat = fetchCount($conn, "SELECT COUNT(*) FROM medicines");
$total_obat_aktif = fetchCount($conn, "SELECT COUNT(*) FROM medicines WHERE is_active = 1");

$total_obat_restok = fetchCount($conn, "
    SELECT COUNT(DISTINCT cm.medicine_id) 
    FROM clinic_medicines cm 
    LEFT JOIN (
        SELECT clinic_medicine_id, SUM(quantity_on_hand) as total_qty 
        FROM medicine_batches 
        GROUP BY clinic_medicine_id
    ) mb ON mb.clinic_medicine_id = cm.id 
    WHERE cm.clinic_id = ? AND IFNULL(mb.total_qty, 0) <= cm.minimum_stock
", $id);

$obat_list = [];
$stmtObat = $conn->prepare("
    SELECT 
        m.id,
        m.medicine_code AS kode_obat, 
        m.name AS nama_obat, 
        m.generic_name AS generik, 
        m.dosage_form AS sediaan,
        m.strength,
        m.unit,
        m.requires_prescription,
        m.is_active,
        IFNULL(cm.minimum_stock, 0) AS stok_min,
        IFNULL(mb.total_qty, 0) AS stok,
        IF(m.requires_prescription = 1, 'Resep Dokter', 'Resep Bebas') AS status_resep, 
        IF(m.is_active = 1, 'Aktif', 'Nonaktif') AS status_aktif 
    FROM medicines m
    LEFT JOIN clinic_medicines cm ON cm.medicine_id = m.id AND cm.clinic_id = ?
    LEFT JOIN (
        SELECT clinic_medicine_id, SUM(quantity_on_hand) AS total_qty
        FROM medicine_batches
        GROUP BY clinic_medicine_id
    ) mb ON mb.clinic_medicine_id = cm.id
    ORDER BY m.medicine_code ASC
");
if ($stmtObat) {
    $stmtObat->bind_param('i', $id);
    $stmtObat->execute();
    $res_obat = $stmtObat->get_result();
    if ($res_obat) $obat_list = $res_obat->fetch_all(MYSQLI_ASSOC);
}


// 7. STATISTIK & DATA TAB 2: DIAGNOSIS (ICD)
$total_diagnosis = fetchCount($conn, "SELECT COUNT(*) FROM diagnoses");

$diagnosis_list = [];
$res_diag = $conn->query("
    SELECT id,
           code AS kode_icd, 
           name AS nama_diagnosis, 
           description AS deskripsi, 
           coding_system AS sistem_pengkodean 
    FROM diagnoses 
    ORDER BY code ASC
");
if ($res_diag) $diagnosis_list = $res_diag->fetch_all(MYSQLI_ASSOC);


// 8. STATISTIK & DATA TAB 3: TINDAKAN MEDIS
$total_tindakan = fetchCount($conn, "SELECT COUNT(*) FROM medical_actions");

$tindakan_list = [];
$res_tnd = $conn->query("
    SELECT id,
           code AS kode_tindakan, 
           name AS nama_tindakan, 
           description AS keterangan, 
           default_price AS tarif,
           is_active,
           IF(is_active = 1, 'Aktif', 'Nonaktif') AS status 
    FROM medical_actions 
    ORDER BY code ASC
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
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>"> 
  <link rel="stylesheet" href="../assets/css/masterdata.css?v=<?= filemtime(__DIR__ . '/../assets/css/masterdata.css') ?>"> 
  <style>
    .d-none { display: none !important; }
  </style>
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <?php require __DIR__ . '/../includes/topbar.php'; ?>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($_SESSION['clinic_name'])) ?></span>
        <span class="sidebar-title">Master Data Klinik</span>
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
        <a href="pharmacy-stock.php" class="nav-item">
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

      <!-- Flash Notification Alerts -->
      <?php if ($flashSuccess): ?>
        <div class="alert-banner alert-success" id="flashAlert">
          <div class="alert-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"></polyline></svg>
            <span><?= h($flashSuccess) ?></span>
          </div>
          <button type="button" class="alert-close" onclick="document.getElementById('flashAlert').remove()" aria-label="Tutup">&times;</button>
        </div>
      <?php endif; ?>

      <?php if ($flashError): ?>
        <div class="alert-banner alert-danger" id="flashAlert">
          <div class="alert-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span><?= h($flashError) ?></span>
          </div>
          <button type="button" class="alert-close" onclick="document.getElementById('flashAlert').remove()" aria-label="Tutup">&times;</button>
        </div>
      <?php endif; ?>

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
              <div class="filter-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchObat" placeholder="Cari nama atau kode obat..." class="search-input" onkeyup="filterTableObat()">
              </div>

              <select id="filterKategoriObat" class="filter-select" onchange="filterTableObat()">
                <option value="">Kategori: Semua sediaan</option>
                <option value="Tablet">Tablet</option>
                <option value="Kapsul">Kapsul</option>
                <option value="Sirup">Sirup</option>
                <option value="Salep">Salep</option>
                <option value="Inhaler">Inhaler</option>
                <option value="Tetes">Tetes</option>
                <option value="Injeksi">Injeksi</option>
              </select>

              <select id="filterStatusObat" class="filter-select" onchange="filterTableObat()">
                <option value="">Status: Aktif / Nonaktif</option>
                <option value="Aktif">Aktif</option>
                <option value="Nonaktif">Nonaktif</option>
              </select>

              <div class="flex-spacer"></div>
              <button type="button" class="btn-primary-small" onclick="openModalObat()">+ Tambah Obat Klinik</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table master-table">
              <thead>
                <tr>
                  <th width="12%">Kode Obat</th>
                  <th width="26%">Nama Obat & Generik</th>
                  <th width="18%">Sediaan & Dosis</th>
                  <th width="13%">Stok Minimum</th>
                  <th width="12%">Status Resep</th>
                  <th width="10%">Status</th>
                  <th width="9%" style="text-align: center;">Aksi</th>
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
                      <td>
                        <div class="td-desc text-dark fw-normal">
                          <?= h($row['sediaan'] ?? '') ?><?= !empty($row['strength']) ? ' (' . h($row['strength']) . ')' : '' ?>
                        </div>
                      </td>
                      <td>
                        <div class="<?= (isset($row['stok']) && isset($row['stok_min']) && $row['stok'] <= $row['stok_min']) ? 'badge-amber' : 'td-main fw-normal' ?>">
                          <?= (float)($row['stok_min'] ?? 0) ?> <?= h($row['unit'] ?? '') ?>
                          <?php if(isset($row['stok']) && isset($row['stok_min']) && $row['stok'] <= $row['stok_min']): ?> · Kritis <?php endif; ?>
                        </div>
                      </td>
                      <td><span class="status-badge status-gray fw-semibold"><?= h($row['status_resep'] ?? 'Resep Bebas') ?></span></td>
                      <td>
                        <span class="status-badge <?= ($row['status_aktif'] ?? 'Aktif') === 'Aktif' ? 'status-blue' : 'status-gray' ?> fw-semibold">
                          <?= h($row['status_aktif'] ?? 'Aktif') ?>
                        </span>
                      </td>
                      <td>
                        <div class="action-icons" style="justify-content: center;">
                          <button type="button" class="btn-icon-edit" title="Edit Obat" onclick='openEditObat(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                          </button>
                          <button type="button" class="btn-icon-delete" title="Hapus Obat" onclick="confirmDelete('obat', <?= (int)$row['id'] ?>, '<?= h(addslashes($row['nama_obat'])) ?>', '<?= h(addslashes($row['kode_obat'])) ?>')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <polyline points="3 6 5 6 21 6"></polyline>
                              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                              <line x1="10" y1="11" x2="10" y2="17"></line>
                              <line x1="14" y1="11" x2="14" y2="17"></line>
                            </svg>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan <?= count($obat_list) > 0 ? '1–'.count($obat_list) : '0' ?> dari <?= $total_obat ?> obat klinik</div>
            <div class="page-controls">
              <button class="btn-page" disabled>Sebelumnya</button>
              <div class="page-num active">1</div>
              <button class="btn-page" disabled>Berikutnya</button>
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
              <div class="filter-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchDiagnosis" placeholder="Cari Kode ICD (contoh: A09) atau Nama Diagnosis..." class="search-input" onkeyup="filterTableDiagnosis()">
              </div>

              <select id="filterSistemDiag" class="filter-select" onchange="filterTableDiagnosis()">
                <option value="">Sistem Pengkodean: Semua</option>
                <option value="ICD-10">ICD-10</option>
                <option value="ICD-9-CM">ICD-9-CM</option>
              </select>

              <div class="flex-spacer"></div>
              <button type="button" class="btn-primary-small" onclick="openModalDiagnosis()">+ Tambah Diagnosis Baru</button>
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
                  <th width="8%" style="text-align: center;">Aksi</th>
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
                      <td><div class="td-desc" style="line-height: 1.5;"><?= h($row['deskripsi'] ?? '-') ?></div></td>
                      <td><span class="badge-gray"><?= h($row['sistem_pengkodean'] ?? 'ICD-10') ?></span></td>
                      <td>
                        <div class="action-icons" style="justify-content: center;">
                          <button type="button" class="btn-icon-edit" title="Edit Diagnosis" onclick='openEditDiagnosis(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                          </button>
                          <button type="button" class="btn-icon-delete" title="Hapus Diagnosis" onclick="confirmDelete('diagnosis', <?= (int)$row['id'] ?>, '<?= h(addslashes($row['nama_diagnosis'])) ?>', '<?= h(addslashes($row['kode_icd'])) ?>')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <polyline points="3 6 5 6 21 6"></polyline>
                              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                              <line x1="10" y1="11" x2="10" y2="17"></line>
                              <line x1="14" y1="11" x2="14" y2="17"></line>
                            </svg>
                          </button>
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
              <button type="button" class="btn-page" disabled>Sebelumnya</button>
              <div class="page-num active">1</div>
              <button type="button" class="btn-page" disabled>Berikutnya</button>
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
              <div class="filter-search">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                <input type="text" id="searchTindakan" placeholder="Cari kode atau nama tindakan..." class="search-input" onkeyup="filterTableTindakan()">
              </div>
              <div class="flex-spacer"></div>
              <button type="button" class="btn-primary-small" onclick="openModalTindakan()">+ Tambah Tindakan</button>
            </div>
          </div>

          <div class="table-responsive">
            <table class="data-table master-table">
              <thead>
                <tr>
                  <th width="15%">Kode</th>
                  <th width="38%">Nama Tindakan</th>
                  <th width="20%">Tarif Standar</th>
                  <th width="15%">Status</th>
                  <th width="12%" style="text-align: center;">Aksi</th>
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
                        <div class="td-sub-small mt-1"><?= h($row['keterangan'] ?? '-') ?></div>
                      </td>
                      <td><div class="td-main font-semibold">Rp <?= number_format($row['tarif'] ?? 0, 0, ',', '.') ?></div></td>
                      <td>
                        <span class="status-badge <?= ($row['status'] ?? 'Aktif') === 'Aktif' ? 'status-green' : 'status-gray' ?> fw-semibold">
                          <?= h($row['status'] ?? 'Aktif') ?>
                        </span>
                      </td>
                      <td>
                        <div class="action-icons" style="justify-content: center;">
                          <button type="button" class="btn-icon-edit" title="Edit Tindakan" onclick='openEditTindakan(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'>
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                              <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                            </svg>
                          </button>
                          <button type="button" class="btn-icon-delete" title="Hapus Tindakan" onclick="confirmDelete('tindakan', <?= (int)$row['id'] ?>, '<?= h(addslashes($row['nama_tindakan'])) ?>', '<?= h(addslashes($row['kode_tindakan'])) ?>')">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                              <polyline points="3 6 5 6 21 6"></polyline>
                              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                              <line x1="10" y1="11" x2="10" y2="17"></line>
                              <line x1="14" y1="11" x2="14" y2="17"></line>
                            </svg>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <div class="pagination-footer">
            <div class="page-info">Menampilkan <?= count($tindakan_list) > 0 ? '1–'.count($tindakan_list) : '0' ?> dari <?= $total_tindakan ?> tindakan</div>
            <div class="page-controls">
              <button type="button" class="btn-page" disabled>Sebelumnya</button>
              <div class="page-num active">1</div>
              <button type="button" class="btn-page" disabled>Berikutnya</button>
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

  <!-- ============================================================== -->
  <!-- MODAL 1: FORM TAMBAH / EDIT OBAT KLINIK                         -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalObat">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean" id="modalObatTitle">Tambah Obat Klinik</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalObat()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="master-data.php" id="formObat">
        <input type="hidden" name="action" id="action_obat" value="add_obat">
        <input type="hidden" name="medicine_id" id="input_medicine_id" value="0">

        <div class="modal-body-scroll">
          
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_med_code">Kode Obat *</label>
              <input type="text" name="medicine_code" id="input_med_code" class="input-control" placeholder="Contoh: MED-PCM-500" required>
            </div>
            <div class="form-field-group">
              <label for="input_med_name">Nama Obat *</label>
              <input type="text" name="name" id="input_med_name" class="input-control" placeholder="Contoh: Paracetamol 500 mg" required>
            </div>
          </div>

          <div class="form-field-group">
            <label for="input_med_generic">Nama Generik</label>
            <input type="text" name="generic_name" id="input_med_generic" class="input-control" placeholder="Contoh: Paracetamol">
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_med_dosage_form">Bentuk Sediaan *</label>
              <select name="dosage_form" id="input_med_dosage_form" class="input-control" required>
                <option value="Tablet">Tablet</option>
                <option value="Kapsul">Kapsul</option>
                <option value="Sirup">Sirup</option>
                <option value="Salep">Salep</option>
                <option value="Inhaler">Inhaler</option>
                <option value="Tetes">Tetes</option>
                <option value="Injeksi">Injeksi</option>
                <option value="Suspensi">Suspensi</option>
              </select>
            </div>
            <div class="form-field-group">
              <label for="input_med_strength">Kekuatan / Dosis</label>
              <input type="text" name="strength" id="input_med_strength" class="input-control" placeholder="Contoh: 500 mg">
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_med_unit">Satuan Kemasan Dasar *</label>
              <input type="text" name="unit" id="input_med_unit" class="input-control" placeholder="tablet / kapsul / botol" required>
            </div>
            <div class="form-field-group">
              <label for="input_med_min_stock">Stok Minimum Klinik *</label>
              <input type="number" name="minimum_stock" id="input_med_min_stock" class="input-control" placeholder="10" min="0" step="any" required>
            </div>
          </div>

          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_med_prescription">Kategori Resep</label>
              <select name="requires_prescription" id="input_med_prescription" class="input-control">
                <option value="0">Resep Bebas (OTC)</option>
                <option value="1">Resep Dokter (Wajib Resep)</option>
              </select>
            </div>
            <div class="form-field-group">
              <label for="input_med_active">Status Aktif</label>
              <select name="is_active" id="input_med_active" class="input-control">
                <option value="1">Aktif</option>
                <option value="0">Nonaktif</option>
              </select>
            </div>
          </div>

        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalObat()">Batal</button>
          <button type="submit" class="btn-modal-save" id="btnSubmitObat">Simpan Obat</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 2: FORM TAMBAH / EDIT DIAGNOSIS (ICD)                    -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalDiagnosis">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean" id="modalDiagnosisTitle">Tambah Diagnosis Baru</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalDiagnosis()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="master-data.php" id="formDiagnosis">
        <input type="hidden" name="action" id="action_diagnosis" value="add_diagnosis">
        <input type="hidden" name="id" id="input_diag_id" value="0">

        <div class="modal-body-scroll">
          
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_diag_code">Kode Diagnosis (ICD) *</label>
              <input type="text" name="code" id="input_diag_code" class="input-control" placeholder="Contoh: A09" required>
            </div>
            <div class="form-field-group">
              <label for="input_diag_system">Sistem Pengkodean *</label>
              <select name="coding_system" id="input_diag_system" class="input-control" required>
                <option value="ICD-10">ICD-10</option>
                <option value="ICD-9-CM">ICD-9-CM</option>
              </select>
            </div>
          </div>

          <div class="form-field-group">
            <label for="input_diag_name">Nama Diagnosis *</label>
            <input type="text" name="name" id="input_diag_name" class="input-control" placeholder="Contoh: Gastroenteritis dan kolitis infeksius" required>
          </div>

          <div class="form-field-group">
            <label for="input_diag_desc">Deskripsi / Keterangan Klinis</label>
            <textarea name="description" id="input_diag_desc" class="input-control" rows="3" placeholder="Tambahkan catatan atau sinonim medis jika diperlukan..."></textarea>
          </div>

        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalDiagnosis()">Batal</button>
          <button type="submit" class="btn-modal-save" id="btnSubmitDiagnosis">Simpan Diagnosis</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 3: FORM TAMBAH / EDIT TINDAKAN MEDIS                     -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalTindakan">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean" id="modalTindakanTitle">Tambah Tindakan Medis</h3>
        <button type="button" class="modal-close-btn" onclick="closeModalTindakan()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="master-data.php" id="formTindakan">
        <input type="hidden" name="action" id="action_tindakan" value="add_tindakan">
        <input type="hidden" name="id" id="input_tnd_id" value="0">

        <div class="modal-body-scroll">
          
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_tnd_code">Kode Tindakan *</label>
              <input type="text" name="code" id="input_tnd_code" class="input-control" placeholder="Contoh: TND-001" required>
            </div>
            <div class="form-field-group">
              <label for="input_tnd_price">Tarif Standar (Rp) *</label>
              <input type="number" name="default_price" id="input_tnd_price" class="input-control" placeholder="50000" min="0" step="1000" required>
            </div>
          </div>

          <div class="form-field-group">
            <label for="input_tnd_name">Nama Tindakan Medis *</label>
            <input type="text" name="name" id="input_tnd_name" class="input-control" placeholder="Contoh: Konsultasi & Pemeriksaan Fisik" required>
          </div>

          <div class="form-field-group">
            <label for="input_tnd_desc">Keterangan / Rincian Tindakan</label>
            <textarea name="description" id="input_tnd_desc" class="input-control" rows="3" placeholder="Rincian prosedur medis atau komponen layanan..."></textarea>
          </div>

          <div class="form-field-group">
            <label for="input_tnd_active">Status Tindakan</label>
            <select name="is_active" id="input_tnd_active" class="input-control">
              <option value="1">Aktif (Tersedia untuk Pelayanan)</option>
              <option value="0">Nonaktif (Tidak Ditampilkan)</option>
            </select>
          </div>

        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalTindakan()">Batal</button>
          <button type="submit" class="btn-modal-save" id="btnSubmitTindakan">Simpan Tindakan</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ============================================================== -->
  <!-- MODAL 4: PERINGATAN KONFIRMASI HAPUS DATA                       -->
  <!-- ============================================================== -->
  <div class="modal-overlay d-none" id="modalDeleteConfirm">
    <div class="modal-dialog" style="max-width: 480px;">
      <div class="modal-header-clean" style="border-bottom-color: #FEE2E2;">
        <h3 class="modal-title-clean" style="color: #DC2626; display: flex; align-items: center; gap: 8px;">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#DC2626" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
            <line x1="12" y1="9" x2="12" y2="13"></line>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
          </svg>
          Konfirmasi Hapus Data
        </h3>
        <button type="button" class="modal-close-btn" onclick="closeModalDelete()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="master-data.php" id="formDelete">
        <input type="hidden" name="action" id="delete_action" value="">
        <input type="hidden" name="id" id="delete_id" value="">

        <div class="modal-body-scroll" style="gap: 14px;">
          <div style="font-size: 14px; color: #1E293B; line-height: 1.6;">
            Apakah Anda yakin ingin menghapus data <strong id="delete_item_name"></strong> (<span id="delete_item_code" style="font-family: monospace; font-weight: 600;"></span>)?
          </div>
          <div style="background: #FEF2F2; border-left: 4px solid #EF4444; padding: 12px 14px; border-radius: 6px; font-size: 12.5px; color: #991B1B; line-height: 1.5;">
            <strong>Peringatan:</strong> Tindakan ini tidak dapat dibatalkan. Jika data telah terkait dengan transaksi medis atau stok, sistem akan menolak penghapusan demi menjaga integritas data klinik.
          </div>
        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeModalDelete()">Batal</button>
          <button type="submit" class="btn-modal-delete">Ya, Hapus Data</button>
        </div>
      </form>
    </div>
  </div>

  <!-- SCRIPT -->
  <script>
    // JS Jam Digital Real-time
    function updateClock() {
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date()) + ' WIB';
        const cTop = document.getElementById('clock-top');
        const cSide = document.getElementById('clock-sidebar');
        const cMain = document.getElementById('clock-main');
        if (cTop) cTop.textContent = s;
        if (cSide) cSide.textContent = s;
        if (cMain) cMain.textContent = 'Zona waktu Asia/Jakarta (WIB) · ' + s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    // JS Tab Switching
    function switchTab(event, tabName) {
      if (event) event.preventDefault();
      
      // Hapus active dari semua tombol tab
      document.getElementById('tab-obat').classList.remove('active');
      document.getElementById('tab-diagnosis').classList.remove('active');
      document.getElementById('tab-tindakan').classList.remove('active');
      
      // Sembunyikan semua konten panel
      document.getElementById('content-obat').classList.add('d-none');
      document.getElementById('content-diagnosis').classList.add('d-none');
      document.getElementById('content-tindakan').classList.add('d-none');
      
      // Tampilkan tab yang dipilih
      const targetTabBtn = document.getElementById('tab-' + tabName);
      const targetContent = document.getElementById('content-' + tabName);
      if (targetTabBtn) targetTabBtn.classList.add('active');
      if (targetContent) targetContent.classList.remove('d-none');

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

    // Auto switch tab berdasarkan URL param '?tab=...'
    window.addEventListener('DOMContentLoaded', function() {
      const urlParams = new URLSearchParams(window.location.search);
      const tabParam = urlParams.get('tab');
      if (tabParam === 'diagnosis' || tabParam === 'tindakan') {
        switchTab(null, tabParam);
      }
    });

    // --- MODAL OBAT ---
    function openModalObat() {
      document.getElementById('modalObatTitle').innerText = 'Tambah Obat Klinik';
      document.getElementById('action_obat').value = 'add_obat';
      document.getElementById('input_medicine_id').value = '0';
      document.getElementById('formObat').reset();
      document.getElementById('input_med_code').readOnly = false;
      document.getElementById('btnSubmitObat').innerText = '+ Tambah Obat Klinik';
      document.getElementById('modalObat').classList.remove('d-none');
    }

    function openEditObat(data) {
      document.getElementById('modalObatTitle').innerText = 'Edit Obat Klinik';
      document.getElementById('action_obat').value = 'edit_obat';
      document.getElementById('input_medicine_id').value = data.id || '0';
      document.getElementById('input_med_code').value = data.kode_obat || '';
      document.getElementById('input_med_name').value = data.nama_obat || '';
      document.getElementById('input_med_generic').value = data.generik || '';
      document.getElementById('input_med_dosage_form').value = data.sediaan || 'Tablet';
      document.getElementById('input_med_strength').value = data.strength || '';
      document.getElementById('input_med_unit').value = data.unit || 'tablet';
      document.getElementById('input_med_min_stock').value = data.stok_min || 0;
      document.getElementById('input_med_prescription').value = (data.requires_prescription !== undefined) ? data.requires_prescription : 0;
      document.getElementById('input_med_active').value = (data.is_active !== undefined) ? data.is_active : 1;
      document.getElementById('btnSubmitObat').innerText = 'Simpan Perubahan';
      document.getElementById('modalObat').classList.remove('d-none');
    }

    function closeModalObat() {
      document.getElementById('modalObat').classList.add('d-none');
    }

    // --- MODAL DIAGNOSIS ---
    function openModalDiagnosis() {
      document.getElementById('modalDiagnosisTitle').innerText = 'Tambah Diagnosis Baru';
      document.getElementById('action_diagnosis').value = 'add_diagnosis';
      document.getElementById('input_diag_id').value = '0';
      document.getElementById('formDiagnosis').reset();
      document.getElementById('btnSubmitDiagnosis').innerText = '+ Tambah Diagnosis';
      document.getElementById('modalDiagnosis').classList.remove('d-none');
    }

    function openEditDiagnosis(data) {
      document.getElementById('modalDiagnosisTitle').innerText = 'Edit Diagnosis';
      document.getElementById('action_diagnosis').value = 'edit_diagnosis';
      document.getElementById('input_diag_id').value = data.id || '0';
      document.getElementById('input_diag_code').value = data.kode_icd || '';
      document.getElementById('input_diag_system').value = data.sistem_pengkodean || 'ICD-10';
      document.getElementById('input_diag_name').value = data.nama_diagnosis || '';
      document.getElementById('input_diag_desc').value = data.deskripsi || '';
      document.getElementById('btnSubmitDiagnosis').innerText = 'Simpan Perubahan';
      document.getElementById('modalDiagnosis').classList.remove('d-none');
    }

    function closeModalDiagnosis() {
      document.getElementById('modalDiagnosis').classList.add('d-none');
    }

    // --- MODAL TINDAKAN ---
    function openModalTindakan() {
      document.getElementById('modalTindakanTitle').innerText = 'Tambah Tindakan Medis';
      document.getElementById('action_tindakan').value = 'add_tindakan';
      document.getElementById('input_tnd_id').value = '0';
      document.getElementById('formTindakan').reset();
      document.getElementById('btnSubmitTindakan').innerText = '+ Tambah Tindakan';
      document.getElementById('modalTindakan').classList.remove('d-none');
    }

    function openEditTindakan(data) {
      document.getElementById('modalTindakanTitle').innerText = 'Edit Tindakan Medis';
      document.getElementById('action_tindakan').value = 'edit_tindakan';
      document.getElementById('input_tnd_id').value = data.id || '0';
      document.getElementById('input_tnd_code').value = data.kode_tindakan || '';
      document.getElementById('input_tnd_price').value = Math.round(data.tarif || 0);
      document.getElementById('input_tnd_name').value = data.nama_tindakan || '';
      document.getElementById('input_tnd_desc').value = data.keterangan || '';
      document.getElementById('input_tnd_active').value = (data.is_active !== undefined) ? data.is_active : 1;
      document.getElementById('btnSubmitTindakan').innerText = 'Simpan Perubahan';
      document.getElementById('modalTindakan').classList.remove('d-none');
    }

    function closeModalTindakan() {
      document.getElementById('modalTindakan').classList.add('d-none');
    }

    // --- MODAL DELETE CONFIRMATION ---
    function confirmDelete(type, id, name, code) {
      document.getElementById('delete_id').value = id;
      document.getElementById('delete_item_name').innerText = name;
      document.getElementById('delete_item_code').innerText = code;

      if (type === 'obat') {
        document.getElementById('delete_action').value = 'delete_obat';
      } else if (type === 'diagnosis') {
        document.getElementById('delete_action').value = 'delete_diagnosis';
      } else if (type === 'tindakan') {
        document.getElementById('delete_action').value = 'delete_tindakan';
      }

      document.getElementById('modalDeleteConfirm').classList.remove('d-none');
    }

    function closeModalDelete() {
      document.getElementById('modalDeleteConfirm').classList.add('d-none');
    }

    // Tutup modal jika user klik di overlay latar belakang
    window.addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.add('d-none');
      }
    });

    // --- FILTER TABLES ---
    function filterTableObat() {
      const keyword  = document.getElementById('searchObat').value.toLowerCase();
      const kategori = document.getElementById('filterKategoriObat').value.toLowerCase();
      const status   = document.getElementById('filterStatusObat').value.toLowerCase();
      const rows     = document.querySelectorAll('#content-obat tbody tr');

      rows.forEach(row => {
        if (row.cells.length < 7) return;
        const kode     = row.cells[0].textContent.toLowerCase();
        const nama     = row.cells[1].textContent.toLowerCase();
        const sediaan  = row.cells[2].textContent.toLowerCase();
        const stAktif  = row.cells[5].textContent.trim().toLowerCase();

        const matchSearch   = kode.includes(keyword) || nama.includes(keyword);
        const matchKategori = !kategori || sediaan.includes(kategori);
        const matchStatus   = !status || stAktif === status;

        row.style.display = (matchSearch && matchKategori && matchStatus) ? '' : 'none';
      });
    }

    function filterTableDiagnosis() {
      const keyword = document.getElementById('searchDiagnosis').value.toLowerCase();
      const sistem  = document.getElementById('filterSistemDiag').value.toLowerCase();
      const rows    = document.querySelectorAll('#content-diagnosis tbody tr');

      rows.forEach(row => {
        if (row.cells.length < 5) return;
        const kode      = row.cells[0].textContent.toLowerCase();
        const nama      = row.cells[1].textContent.toLowerCase();
        const deskripsi = row.cells[2].textContent.toLowerCase();
        const kodeSys   = row.cells[3].textContent.trim().toLowerCase();

        const matchSearch = kode.includes(keyword) || nama.includes(keyword) || deskripsi.includes(keyword);
        const matchSistem = !sistem || kodeSys === sistem;

        row.style.display = (matchSearch && matchSistem) ? '' : 'none';
      });
    }

    function filterTableTindakan() {
      const keyword = document.getElementById('searchTindakan').value.toLowerCase();
      const rows    = document.querySelectorAll('#content-tindakan tbody tr');

      rows.forEach(row => {
        if (row.cells.length < 5) return;
        const kode = row.cells[0].textContent.toLowerCase();
        const nama = row.cells[1].textContent.toLowerCase();
        const desc = row.cells[2].textContent.toLowerCase();

        row.style.display = (kode.includes(keyword) || nama.includes(keyword) || desc.includes(keyword)) ? '' : 'none';
      });
    }
  </script>

</body>
</html>
