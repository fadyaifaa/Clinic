<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Data Login & Isolasi Tenant
if (!isset($_SESSION['clinic_id'])) {
    $_SESSION['clinic_id']   = 1; 
    $_SESSION['clinic_name'] = 'Klinik Sehat Bersama';
    $_SESSION['clinic_code'] = 'klinik-sehat';
    $_SESSION['user_name']   = 'Dahlia Cahya';
    $_SESSION['user_id']     = 1;
    $_SESSION['role_name']   = 'admin';
}

require_once __DIR__ . '/../config/database.php';

$clinicId = (int)$_SESSION['clinic_id'];
$userId   = (int)($_SESSION['user_id'] ?? 1);

if (!function_exists('h')) {
    function h($v) {
        return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatTanggalIndo')) {
    function formatTanggalIndo($datetime, $withTime = false) {
        if (!$datetime) return '-';
        $timestamp = strtotime($datetime);
        $bulanShort = [
            1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
            5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
            9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
        ];
        $d = date('j', $timestamp);
        $m = (int)date('n', $timestamp);
        $y = date('Y', $timestamp);
        $res = "$d {$bulanShort[$m]} $y";
        if ($withTime) {
            $res .= ' · ' . date('H:i', $timestamp) . ' WIB';
        }
        return $res;
    }
}

if (!function_exists('getExpiryMeta')) {
    function getExpiryMeta($expiryDate) {
        if (!$expiryDate) return ['label' => '-', 'class' => 'subtext-muted', 'is_near' => false, 'is_expired' => false];
        $today = new DateTime('today');
        $exp = new DateTime($expiryDate);
        $diff = (int)$today->diff($exp)->format("%r%a");

        if ($diff < 0) {
            return [
                'label' => 'Sudah Expired',
                'class' => 'text-danger font-semibold',
                'badge' => 'Expired',
                'is_near' => true,
                'is_expired' => true
            ];
        } elseif ($diff <= 90) {
            return [
                'label' => "$diff hari lagi",
                'class' => 'text-danger font-semibold',
                'badge' => '< 3 bulan',
                'is_near' => true,
                'is_expired' => false
            ];
        } else {
            return [
                'label' => 'Lebih dari 3 bulan',
                'class' => 'subtext-muted',
                'badge' => '',
                'is_near' => false,
                'is_expired' => false
            ];
        }
    }
}

if (!function_exists('formatRoleLabel')) {
    function formatRoleLabel($name) {
        switch (strtolower(trim($name))) {
            case 'admin': return 'Admin Klinik';
            case 'pharmacist': return 'Apoteker';
            case 'doctor': return 'Dokter';
            case 'patient': return 'Pasien';
            default: return ucfirst($name);
        }
    }
}

// ==========================================================
// 2. PROSES FORM SUBMIT (POST - PRG PATTERN)
// ==========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- A. INPUT BATCH BARU ---
    if ($action === 'input_batch') {
        $targetCmId    = (int)($_POST['clinic_medicine_id'] ?? 0);
        $newMedId      = (int)($_POST['new_medicine_id'] ?? 0);
        $batchNumber   = trim($_POST['batch_number'] ?? '');
        $expiryDate    = trim($_POST['expiry_date'] ?? '');
        $quantity      = (float)($_POST['quantity'] ?? 0);
        $purchasePrice = (float)($_POST['purchase_price'] ?? 0);
        $supplierName  = trim($_POST['supplier_name'] ?? '');
        $notes         = trim($_POST['notes'] ?? '');

        if (!$batchNumber || !$expiryDate || $quantity <= 0) {
            $_SESSION['flash_error'] = 'Harap isi Nomor Batch, Tanggal Expired, dan Jumlah Masuk (> 0).';
            header('Location: pharmacy-stock.php?tab=batches');
            exit;
        }

        $conn->begin_transaction();
        try {
            // Jika memilih obat dari master data global yang belum ada di clinic_medicines
            if ($targetCmId === 0 && $newMedId > 0) {
                $checkCm = $conn->prepare("SELECT id FROM clinic_medicines WHERE clinic_id = ? AND medicine_id = ? LIMIT 1");
                $checkCm->bind_param('ii', $clinicId, $newMedId);
                $checkCm->execute();
                $existingCm = $checkCm->get_result()->fetch_assoc();
                if ($existingCm) {
                    $targetCmId = (int)$existingCm['id'];
                } else {
                    $insCm = $conn->prepare("INSERT INTO clinic_medicines (clinic_id, medicine_id, minimum_stock, is_active) VALUES (?, ?, 10, 1)");
                    $insCm->bind_param('ii', $clinicId, $newMedId);
                    $insCm->execute();
                    $targetCmId = $conn->insert_id;
                }
            }

            // Validasi apakah clinic_medicine_id milik clinic_id aktif
            $valCm = $conn->prepare("SELECT id, medicine_id FROM clinic_medicines WHERE id = ? AND clinic_id = ? LIMIT 1");
            $valCm->bind_param('ii', $targetCmId, $clinicId);
            $valCm->execute();
            if ($valCm->get_result()->num_rows === 0) {
                throw new Exception('Data obat tidak valid untuk klinik Anda.');
            }

            // Validasi nomor batch unik per clinic_medicine
            $checkBatch = $conn->prepare("SELECT id FROM medicine_batches WHERE clinic_medicine_id = ? AND batch_number = ? LIMIT 1");
            $checkBatch->bind_param('is', $targetCmId, $batchNumber);
            $checkBatch->execute();
            if ($checkBatch->get_result()->num_rows > 0) {
                throw new Exception("Nomor batch '$batchNumber' sudah terdaftar untuk obat ini.");
            }

            // 1. Simpan ke medicine_batches
            $insB = $conn->prepare("
                INSERT INTO medicine_batches 
                (clinic_medicine_id, batch_number, expiry_date, quantity_on_hand, purchase_price, supplier_name, received_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $insB->bind_param('issdds', $targetCmId, $batchNumber, $expiryDate, $quantity, $purchasePrice, $supplierName);
            $insB->execute();
            $batchId = $conn->insert_id;

            // 2. Simpan pergerakan stok ke stock_movements
            $movNote = $notes ? $notes : "Penerimaan stok dari " . ($supplierName ? $supplierName : "supplier");
            $insMov = $conn->prepare("
                INSERT INTO stock_movements 
                (medicine_batch_id, movement_type, quantity, reference_type, notes, performed_by, movement_at) 
                VALUES (?, 'in', ?, 'purchase', ?, ?, NOW())
            ");
            $insMov->bind_param('idsi', $batchId, $quantity, $movNote, $userId);
            $insMov->execute();

            // 3. Catat ke audit_logs jika ada
            $auditJson = json_encode([
                'batch_number' => $batchNumber,
                'quantity' => $quantity,
                'expiry_date' => $expiryDate,
                'supplier' => $supplierName
            ]);
            $insAudit = $conn->prepare("INSERT INTO audit_logs (user_id, clinic_id, action, entity_type, entity_id, details) VALUES (?, ?, 'INSERT_BATCH', 'medicine_batches', ?, ?)");
            if ($insAudit) {
                $insAudit->bind_param('iiis', $userId, $clinicId, $batchId, $auditJson);
                $insAudit->execute();
            }

            $conn->commit();
            $_SESSION['flash_success'] = "Batch baru '$batchNumber' berhasil ditambahkan ke inventaris farmasi.";
            header('Location: pharmacy-stock.php?tab=batches');
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Gagal menambahkan batch: ' . $e->getMessage();
            header('Location: pharmacy-stock.php?tab=batches');
            exit;
        }
    }

    // --- B. PENYESUAIAN STOK ---
    if ($action === 'adjust_stock') {
        $batchId     = (int)($_POST['batch_id'] ?? 0);
        $newQuantity = (float)($_POST['new_quantity'] ?? 0);
        $reasonType  = trim($_POST['reason_type'] ?? 'adjustment');
        $notes       = trim($_POST['notes'] ?? '');
        $returnTab   = ($_POST['return_tab'] ?? 'batches') === 'movements' ? 'movements' : 'batches';

        if ($batchId <= 0 || $newQuantity < 0 || empty($notes)) {
            $_SESSION['flash_error'] = 'Harap tentukan batch obat, stok fisik baru (>= 0), dan tuliskan alasan penyesuaian.';
            header("Location: pharmacy-stock.php?tab=$returnTab");
            exit;
        }

        $conn->begin_transaction();
        try {
            // Kunci baris batch & verifikasi kepemilikan tenant
            $stmtB = $conn->prepare("
                SELECT mb.id, mb.batch_number, mb.quantity_on_hand, cm.clinic_id, m.name AS med_name, m.unit 
                FROM medicine_batches mb 
                JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
                JOIN medicines m ON m.id = cm.medicine_id 
                WHERE mb.id = ? AND cm.clinic_id = ? FOR UPDATE
            ");
            $stmtB->bind_param('ii', $batchId, $clinicId);
            $stmtB->execute();
            $batch = $stmtB->get_result()->fetch_assoc();

            if (!$batch) {
                throw new Exception('Data batch obat tidak ditemukan atau bukan milik klinik Anda.');
            }

            $currentQty = (float)$batch['quantity_on_hand'];
            $diff       = $newQuantity - $currentQty;

            if (abs($diff) < 0.0001) {
                throw new Exception("Stok fisik baru sama dengan stok sistem ($currentQty). Tidak ada perubahan.");
            }

            // 1. Update quantity_on_hand pada medicine_batches
            $updB = $conn->prepare("UPDATE medicine_batches SET quantity_on_hand = ? WHERE id = ?");
            $updB->bind_param('di', $newQuantity, $batchId);
            $updB->execute();

            // 2. Tentukan movement_type
            $movementType = in_array($reasonType, ['expired', 'return'], true) ? $reasonType : 'adjustment';

            // 3. Catat log mutasi
            $insMov = $conn->prepare("
                INSERT INTO stock_movements 
                (medicine_batch_id, movement_type, quantity, reference_type, notes, performed_by, movement_at) 
                VALUES (?, ?, ?, 'manual_adjustment', ?, ?, NOW())
            ");
            $insMov->bind_param('isdsi', $batchId, $movementType, $diff, $notes, $userId);
            $insMov->execute();

            // 4. Catat audit_logs
            $auditJson = json_encode([
                'batch_number' => $batch['batch_number'],
                'medicine'     => $batch['med_name'],
                'old_qty'      => $currentQty,
                'new_qty'      => $newQuantity,
                'diff'         => $diff,
                'reason'       => $reasonType,
                'notes'        => $notes
            ]);
            $insAudit = $conn->prepare("INSERT INTO audit_logs (user_id, clinic_id, action, entity_type, entity_id, details) VALUES (?, ?, 'ADJUST_STOCK', 'medicine_batches', ?, ?)");
            if ($insAudit) {
                $insAudit->bind_param('iiis', $userId, $clinicId, $batchId, $auditJson);
                $insAudit->execute();
            }

            $conn->commit();
            $diffFormatted = ($diff > 0 ? "+$diff" : "$diff") . " " . $batch['unit'];
            $_SESSION['flash_success'] = "Penyesuaian stok berhasil disimpan ($diffFormatted). Log mutasi telah diperbarui.";
            header("Location: pharmacy-stock.php?tab=$returnTab");
            exit;
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['flash_error'] = 'Gagal menyesuaikan stok: ' . $e->getMessage();
            header("Location: pharmacy-stock.php?tab=$returnTab");
            exit;
        }
    }
}

// Ambil Flash Messages
$flashSuccess = $_SESSION['flash_success'] ?? '';
$flashError   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Tab Aktif
$activeTab = ($_GET['tab'] ?? 'batches') === 'movements' ? 'movements' : 'batches';

// ==========================================================
// 3. RINGKASAN METRIC CARDS (DINAMIS DARI DATABASE)
// ==========================================================
// 1. Total Batch Aktif (quantity_on_hand > 0)
$stmtM1 = $conn->prepare("
    SELECT COUNT(*) 
    FROM medicine_batches mb 
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
    WHERE cm.clinic_id = ? AND mb.quantity_on_hand > 0
");
$stmtM1->bind_param('i', $clinicId);
$stmtM1->execute();
$totalBatchAktif = (int)$stmtM1->get_result()->fetch_row()[0];

// Total Jenis Obat Aktif
$stmtM1Sub = $conn->prepare("SELECT COUNT(*) FROM clinic_medicines WHERE clinic_id = ? AND is_active = 1");
$stmtM1Sub->bind_param('i', $clinicId);
$stmtM1Sub->execute();
$totalJenisObatAktif = (int)$stmtM1Sub->get_result()->fetch_row()[0];

// 2. Obat Mendekati Expired (< 3 Bulan / 90 Hari)
$stmtM2 = $conn->prepare("
    SELECT COUNT(*) 
    FROM medicine_batches mb 
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
    WHERE cm.clinic_id = ? 
      AND mb.quantity_on_hand > 0 
      AND mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
");
$stmtM2->bind_param('i', $clinicId);
$stmtM2->execute();
$totalMendekatiExpired = (int)$stmtM2->get_result()->fetch_row()[0];

// Tanggal 90 hari ke depan
$expLimitDate = date('Y-m-d', strtotime('+90 days'));
$expLimitDateText = formatTanggalIndo($expLimitDate);

// 3. Stok Kritis (quantity_on_hand <= minimum_stock)
$stmtM3 = $conn->prepare("
    SELECT COUNT(*) 
    FROM medicine_batches mb 
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
    WHERE cm.clinic_id = ? 
      AND mb.quantity_on_hand > 0 
      AND mb.quantity_on_hand <= cm.minimum_stock
");
$stmtM3->bind_param('i', $clinicId);
$stmtM3->execute();
$totalStokKritis = (int)$stmtM3->get_result()->fetch_row()[0];

// 4. Mutasi Bulan Ini (Tahun & Bulan berjalan)
$stmtM4 = $conn->prepare("
    SELECT COUNT(*) 
    FROM stock_movements sm 
    JOIN medicine_batches mb ON mb.id = sm.medicine_batch_id 
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
    WHERE cm.clinic_id = ? 
      AND MONTH(sm.movement_at) = MONTH(CURDATE()) 
      AND YEAR(sm.movement_at) = YEAR(CURDATE())
");
$stmtM4->bind_param('i', $clinicId);
$stmtM4->execute();
$totalMutasiBulanIni = (int)$stmtM4->get_result()->fetch_row()[0];

// Tanggal range bulan ini
$currentMonthRange = "1-" . date('t') . " " . formatTanggalIndo(date('Y-m-d'));

// ==========================================================
// 4. QUERY TAB 1: MANAJEMEN BATCH & FEFO
// ==========================================================
$searchBatch   = trim($_GET['search_batch'] ?? '');
$filterExpired = trim($_GET['filter_expired'] ?? 'all');
$filterStock   = trim($_GET['filter_stock'] ?? 'all');

$sqlBatches = "
    SELECT 
        mb.id AS batch_id,
        mb.batch_number,
        mb.expiry_date,
        mb.quantity_on_hand,
        mb.purchase_price,
        mb.supplier_name,
        cm.id AS clinic_medicine_id,
        cm.minimum_stock,
        m.id AS medicine_id,
        m.name AS medicine_name,
        m.unit,
        m.dosage_form
    FROM medicine_batches mb
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id
    JOIN medicines m ON m.id = cm.medicine_id
    WHERE cm.clinic_id = ?
";

$params = [$clinicId];
$types  = 'i';

if (!empty($searchBatch)) {
    $sqlBatches .= " AND (m.name LIKE ? OR mb.batch_number LIKE ?)";
    $searchWild = "%$searchBatch%";
    $params[] = $searchWild;
    $params[] = $searchWild;
    $types   .= 'ss';
}

if ($filterExpired === 'near') {
    $sqlBatches .= " AND mb.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)";
} elseif ($filterExpired === 'expired') {
    $sqlBatches .= " AND mb.expiry_date < CURDATE()";
}

if ($filterStock === 'critical') {
    $sqlBatches .= " AND mb.quantity_on_hand <= cm.minimum_stock";
} elseif ($filterStock === 'safe') {
    $sqlBatches .= " AND mb.quantity_on_hand > cm.minimum_stock";
}

// URUTAN FEFO OTOMATIS: Expired terdekat di paling atas
$sqlBatches .= " ORDER BY mb.expiry_date ASC, m.name ASC";

$stmtBList = $conn->prepare($sqlBatches);
$stmtBList->bind_param($types, ...$params);
$stmtBList->execute();
$batchList = $stmtBList->get_result()->fetch_all(MYSQLI_ASSOC);

// ==========================================================
// 5. QUERY TAB 2: RIWAYAT MUTASI & ADJUSTMENT STOK
// ==========================================================
$searchMov  = trim($_GET['search_mov'] ?? '');
$filterType = trim($_GET['filter_type'] ?? 'all');
$startDate  = trim($_GET['start_date'] ?? '');
$endDate    = trim($_GET['end_date'] ?? '');

$sqlMovements = "
    SELECT 
        sm.id,
        sm.movement_at,
        sm.movement_type,
        sm.quantity,
        sm.notes,
        sm.reference_type,
        mb.batch_number,
        m.name AS medicine_name,
        m.unit AS medicine_unit,
        u.name AS user_name,
        r.name AS user_role
    FROM stock_movements sm
    JOIN medicine_batches mb ON mb.id = sm.medicine_batch_id
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id
    JOIN medicines m ON m.id = cm.medicine_id
    LEFT JOIN users u ON u.id = sm.performed_by
    LEFT JOIN user_roles ur ON ur.user_id = u.id AND ur.clinic_id = cm.clinic_id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE cm.clinic_id = ?
";

$mParams = [$clinicId];
$mTypes  = 'i';

if (!empty($searchMov)) {
    $sqlMovements .= " AND (m.name LIKE ? OR mb.batch_number LIKE ? OR u.name LIKE ? OR sm.notes LIKE ?)";
    $mWild = "%$searchMov%";
    $mParams[] = $mWild;
    $mParams[] = $mWild;
    $mParams[] = $mWild;
    $mParams[] = $mWild;
    $mTypes   .= 'ssss';
}

if (!empty($filterType) && $filterType !== 'all') {
    $sqlMovements .= " AND sm.movement_type = ?";
    $mParams[] = $filterType;
    $mTypes   .= 's';
}

if (!empty($startDate)) {
    $sqlMovements .= " AND DATE(sm.movement_at) >= ?";
    $mParams[] = $startDate;
    $mTypes   .= 's';
}

if (!empty($endDate)) {
    $sqlMovements .= " AND DATE(sm.movement_at) <= ?";
    $mParams[] = $endDate;
    $mTypes   .= 's';
}

$sqlMovements .= " ORDER BY sm.movement_at DESC";

$stmtMList = $conn->prepare($sqlMovements);
$stmtMList->bind_param($mTypes, ...$mParams);
$stmtMList->execute();
$movementList = $stmtMList->get_result()->fetch_all(MYSQLI_ASSOC);

// ==========================================================
// 6. DATA OPSI MODAL
// ==========================================================
// Opsi Obat untuk Modal Input Batch
$stmtMedOptions = $conn->prepare("
    SELECT cm.id AS clinic_medicine_id, m.id AS medicine_id, m.name, m.dosage_form, m.unit, cm.minimum_stock 
    FROM clinic_medicines cm 
    JOIN medicines m ON m.id = cm.medicine_id 
    WHERE cm.clinic_id = ? AND cm.is_active = 1 
    ORDER BY m.name ASC
");
$stmtMedOptions->bind_param('i', $clinicId);
$stmtMedOptions->execute();
$clinicMedicineOptions = $stmtMedOptions->get_result()->fetch_all(MYSQLI_ASSOC);

// Opsi Obat Global yang belum terdaftar di klinik
$stmtGlobalMed = $conn->prepare("
    SELECT id, name, dosage_form, unit 
    FROM medicines 
    WHERE is_active = 1 
      AND id NOT IN (SELECT medicine_id FROM clinic_medicines WHERE clinic_id = ?) 
    ORDER BY name ASC
");
$stmtGlobalMed->bind_param('i', $clinicId);
$stmtGlobalMed->execute();
$globalMedicineOptions = $stmtGlobalMed->get_result()->fetch_all(MYSQLI_ASSOC);

// Opsi Batch untuk Modal Penyesuaian Stok
$stmtAllBatches = $conn->prepare("
    SELECT mb.id AS batch_id, mb.batch_number, mb.quantity_on_hand, m.name AS medicine_name, m.unit 
    FROM medicine_batches mb 
    JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id 
    JOIN medicines m ON m.id = cm.medicine_id 
    WHERE cm.clinic_id = ? 
    ORDER BY m.name ASC, mb.expiry_date ASC
");
$stmtAllBatches->bind_param('i', $clinicId);
$stmtAllBatches->execute();
$allBatchOptions = $stmtAllBatches->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Farmasi & Stok Obat - <?= h($_SESSION['clinic_name']) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css">
  <link rel="stylesheet" href="../assets/css/pharmacy.css">
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <header class="topbar">
    <div class="topbar-brand">
      <div class="logo-icon"><div class="logo-inner"></div></div>
      <div class="brand-info">
        <div class="brand-name"><?= h($_SESSION['clinic_name']) ?></div>
        <div class="brand-meta">
          <span class="badge-blue"><?= h($_SESSION['clinic_code'] ?? 'klinik-sehat') ?></span>
          <span class="meta-text">Tenant tunggal · <?= h($_SESSION['clinic_code'] ?? 'klinik-sehat') ?>.platform.com</span>
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
      <span id="clock-top">27 Oktober 2026 · 10:30 WIB</span>
    </div>
    
    <button class="notif-btn" title="Notifikasi">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
      <span class="notif-dot"></span>
    </button>
    
    <div class="user-profile">
      <div class="avatar"><?= h(strtoupper(substr($_SESSION['user_name'], 0, 2))) ?></div>
      <div class="user-info">
        <span class="user-name"><?= h($_SESSION['user_name']) ?></span>
        <span class="user-role"><?= h(formatRoleLabel($_SESSION['role_name'] ?? 'Admin Klinik')) ?></span>
      </div>
      <a href="../login.php?action=logout" title="Keluar"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg></a>
    </div>
  </header>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($_SESSION['clinic_name'])) ?></span>
        <span class="sidebar-title">Farmasi & Stok Obat</span>
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
        <a href="pharmacy-stock.php" class="nav-item active">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m10.5 20.5 10-10a4.95 4.95 0 1 0-7-7l-10 10a4.95 4.95 0 1 0 7 7Z"></path><path d="m8.5 8.5 7 7"></path></svg>
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
        <div class="status-meta">1 tenant aktif · Asia/Jakarta (WIB)</div>
      </div>
      
      <a href="#" class="help-center-link">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
        <span>Pusat Bantuan</span>
      </a>
    </aside>

    <!-- MAIN CONTENT AREA -->
    <main class="content-area">
      
      <!-- BREADCRUMB -->
      <div class="breadcrumb">
        <?= h($_SESSION['clinic_name']) ?> / Farmasi & Stok <?= ($activeTab === 'movements') ? '/ Riwayat Mutasi' : '' ?>
      </div>

      <!-- FLASH MESSAGES -->
      <?php if (!empty($flashSuccess)): ?>
        <div class="alert-flash success">
          <span>✓ <?= h($flashSuccess) ?></span>
          <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:700; color:#166534;">&times;</button>
        </div>
      <?php endif; ?>

      <?php if (!empty($flashError)): ?>
        <div class="alert-flash error">
          <span>⚠️ <?= h($flashError) ?></span>
          <button type="button" onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-weight:700; color:#991B1B;">&times;</button>
        </div>
      <?php endif; ?>

      <!-- PAGE HEADER -->
      <div class="page-header">
        <div class="page-title-group">
          <h1>Farmasi & Stok Obat</h1>
          <p>
            <?= ($activeTab === 'movements') 
              ? 'Audit log transparansi keluar-masuk obat, riwayat dispensing resep, serta catatan penyesuaian stok opname manual.' 
              : 'Pemantauan batch obat, tanggal kedaluwarsa, dan riwayat mutasi stok.' 
            ?>
          </p>
        </div>

        <div>
          <?php if ($activeTab === 'batches'): ?>
            <button class="btn-primary" onclick="openInputBatchModal()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle; margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              Input Batch Baru
            </button>
          <?php else: ?>
            <button class="btn-primary" onclick="openAdjustModal()">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="vertical-align:middle; margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
              Penyesuaian Stok
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- 4 METRIC CARDS RINGKASAN (DINAMIS DARI DATABASE) -->
      <?php if ($activeTab === 'batches'): ?>
        <div class="stats-grid">
          <!-- Card 1: Total Batch Obat -->
          <div class="stat-card">
            <div class="card-header">
              <span class="card-title">Total Batch Obat</span>
              <div class="icon-box-blue">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
              </div>
            </div>
            <div class="stat-value"><?= number_format($totalBatchAktif, 0, ',', '.') ?></div>
            <div class="stat-meta">
              <span class="meta-desc"><?= $totalJenisObatAktif ?> obat aktif · <?= $totalJenisObatAktif > 0 ? round($totalBatchAktif / $totalJenisObatAktif, 1) : 0 ?> batch per obat</span>
            </div>
          </div>

          <!-- Card 2: Obat Mendekati Expired (< 3 Bulan) -->
          <div class="stat-card">
            <div class="card-header">
              <span class="card-title">Obat Mendekati Expired</span>
              <div class="icon-box-danger">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line><circle cx="12" cy="15" r="2"></circle></svg>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
              <span class="stat-value"><?= number_format($totalMendekatiExpired, 0, ',', '.') ?></span>
              <span class="badge-metric-danger">&lt; 3 bulan</span>
            </div>
            <div class="stat-meta">
              <span class="meta-desc">Expired sebelum <?= h($expLimitDateText) ?></span>
            </div>
          </div>

          <!-- Card 3: Stok Kritis -->
          <div class="stat-card">
            <div class="card-header">
              <span class="card-title">Stok Kritis</span>
              <div class="icon-box-warning">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
              </div>
            </div>
            <div style="display:flex; align-items:center; gap:10px;">
              <span class="stat-value"><?= number_format($totalStokKritis, 0, ',', '.') ?></span>
              <span class="badge-metric-warning">Perlu restok</span>
            </div>
            <div class="stat-meta">
              <span class="meta-desc">Item dengan stok di bawah minimum</span>
            </div>
          </div>

          <!-- Card 4: Mutasi Bulan Ini -->
          <div class="stat-card">
            <div class="card-header">
              <span class="card-title">Mutasi Bulan Ini</span>
              <div class="icon-box-blue">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="17 1 21 5 17 9"></polyline><path d="M3 11V9a4 4 0 0 1 4-4h14"></path><polyline points="7 23 3 19 7 15"></polyline><path d="M21 13v2a4 4 0 0 1-4 4H3"></path></svg>
              </div>
            </div>
            <div class="stat-value"><?= number_format($totalMutasiBulanIni, 0, ',', '.') ?></div>
            <div class="stat-meta">
              <span class="meta-desc"><?= h($currentMonthRange) ?></span>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- NAVIGATION TABS -->
      <div class="pharmacy-tabs-wrapper">
        <nav class="pharmacy-tabs">
          <a href="pharmacy-stock.php?tab=batches" class="pharmacy-tab-item <?= ($activeTab === 'batches') ? 'active' : '' ?>">
            <span>Batch & Tanggal Expired</span>
            <span class="tab-badge"><?= count($batchList) ?></span>
          </a>
          <a href="pharmacy-stock.php?tab=movements" class="pharmacy-tab-item <?= ($activeTab === 'movements') ? 'active' : '' ?>">
            <span>Mutasi & Adjustment Stok</span>
          </a>
        </nav>
        
        <?php if ($activeTab === 'movements'): ?>
          <div class="auto-log-indicator">
            <span class="dot-green-live"></span>
            <span>Pencatatan otomatis aktif</span>
          </div>
        <?php endif; ?>
      </div>

      <!-- ========================================================== -->
      <!-- TAB 1: MANAJEMEN BATCH & FEFO                              -->
      <!-- ========================================================== -->
      <?php if ($activeTab === 'batches'): ?>
        
        <!-- Filter Bar Tab 1 -->
        <form method="GET" action="pharmacy-stock.php" class="pharmacy-filter-bar">
          <input type="hidden" name="tab" value="batches">
          <div class="filter-left-group">
            <div class="filter-search-box">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" name="search_batch" placeholder="Cari nama obat atau nomor batch..." value="<?= h($searchBatch) ?>" onchange="this.form.submit()">
            </div>

            <select name="filter_expired" class="filter-dropdown" onchange="this.form.submit()">
              <option value="all" <?= ($filterExpired === 'all') ? 'selected' : '' ?>>Expired: Semua</option>
              <option value="near" <?= ($filterExpired === 'near') ? 'selected' : '' ?>>Mendekati Expired (&lt; 3 Bulan)</option>
              <option value="expired" <?= ($filterExpired === 'expired') ? 'selected' : '' ?>>Sudah Expired</option>
            </select>

            <select name="filter_stock" class="filter-dropdown" onchange="this.form.submit()">
              <option value="all" <?= ($filterStock === 'all') ? 'selected' : '' ?>>Stok: Semua</option>
              <option value="critical" <?= ($filterStock === 'critical') ? 'selected' : '' ?>>Stok Kritis / Perlu Restok</option>
              <option value="safe" <?= ($filterStock === 'safe') ? 'selected' : '' ?>>Stok Aman</option>
            </select>
          </div>

          <div class="filter-right-info">
            Per <?= formatTanggalIndo(date('Y-m-d')) ?>
          </div>
        </form>

        <!-- Tabel Batch & FEFO -->
        <div class="data-panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Nama Obat</th>
                <th>Nomor Batch</th>
                <th>Tanggal Expired</th>
                <th>Stok Sisa</th>
                <th>Harga Beli</th>
                <th>Pemasok / Supplier</th>
                <th style="text-align: right;">Aksi</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($batchList)): ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 36px; color: var(--text-light);">
                    Tidak ada batch obat yang sesuai dengan filter pencarian.
                  </td>
                </tr>
              <?php else: ?>
                <?php 
                $countNearExpiredRow = 0;
                $countCriticalRow = 0;
                ?>
                <?php foreach ($batchList as $b): ?>
                  <?php 
                  $expMeta = getExpiryMeta($b['expiry_date']);
                  if ($expMeta['is_near']) $countNearExpiredRow++;
                  $isCritical = ((float)$b['quantity_on_hand'] <= (float)$b['minimum_stock']);
                  if ($isCritical) $countCriticalRow++;
                  ?>
                  <tr>
                    <!-- 1. Nama Obat -->
                    <td>
                      <div class="medicine-name-title"><?= h($b['medicine_name']) ?></div>
                      <div class="subtext-muted">Satuan: <?= h($b['unit']) ?></div>
                    </td>

                    <!-- 2. Nomor Batch -->
                    <td>
                      <span class="batch-code"><?= h($b['batch_number']) ?></span>
                    </td>

                    <!-- 3. Tanggal Expired -->
                    <td>
                      <div class="<?= $expMeta['class'] ?>"><?= formatTanggalIndo($b['expiry_date']) ?></div>
                      <div class="<?= $expMeta['class'] ?>" style="font-size: 11px;"><?= $expMeta['label'] ?></div>
                    </td>

                    <!-- 4. Stok Sisa -->
                    <td>
                      <div>
                        <strong class="text-dark"><?= number_format($b['quantity_on_hand'], 0, ',', '.') ?> <?= h($b['unit']) ?></strong>
                        <?php if ($isCritical): ?>
                          <span class="badge-status-kritis">Kritis</span>
                        <?php endif; ?>
                      </div>
                      <div class="subtext-muted">Minimum <?= number_format($b['minimum_stock'], 0, ',', '.') ?> <?= h($b['unit']) ?></div>
                    </td>

                    <!-- 5. Harga Beli -->
                    <td>
                      <div class="text-dark font-semibold">Rp <?= number_format($b['purchase_price'], 0, ',', '.') ?></div>
                      <div class="subtext-muted">per <?= h($b['unit']) ?></div>
                    </td>

                    <!-- 6. Pemasok / Supplier -->
                    <td>
                      <div class="text-gray"><?= h($b['supplier_name'] ?: '-') ?></div>
                    </td>

                    <!-- 7. Aksi -->
                    <td style="text-align: right;">
                      <button type="button" class="btn-action-adjust" onclick="openAdjustModalForBatch(<?= (int)$b['batch_id'] ?>, '<?= h(addslashes($b['medicine_name'])) ?>', '<?= h(addslashes($b['batch_number'])) ?>', <?= (float)$b['quantity_on_hand'] ?>, '<?= h($b['unit']) ?>')">
                        Sesuaikan Stok
                      </button>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <!-- Footer Ringkasan Tabel -->
          <div class="table-footer-summary">
            <div>
              Menampilkan <?= count($batchList) ?> batch obat
            </div>
            <div class="footer-tags-group">
              <?php if (isset($countNearExpiredRow) && $countNearExpiredRow > 0): ?>
                <span class="tag-red"><?= $countNearExpiredRow ?> batch mendekati expired</span>
              <?php endif; ?>
              <?php if (isset($countCriticalRow) && $countCriticalRow > 0): ?>
                <span class="tag-amber"><?= $countCriticalRow ?> item stok kritis</span>
              <?php endif; ?>
            </div>
          </div>
        </div>

      <!-- ========================================================== -->
      <!-- TAB 2: RIWAYAT MUTASI & ADJUSTMENT STOK                    -->
      <!-- ========================================================== -->
      <?php else: ?>

        <!-- Filter Bar Tab 2 -->
        <form method="GET" action="pharmacy-stock.php" class="pharmacy-filter-bar">
          <input type="hidden" name="tab" value="movements">
          <div class="filter-left-group">
            <div class="filter-search-box">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748B" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" name="search_mov" placeholder="Cari nama obat, nomor batch, atau petugas..." value="<?= h($searchMov) ?>" onchange="this.form.submit()">
            </div>

            <select name="filter_type" class="filter-dropdown" onchange="this.form.submit()">
              <option value="all" <?= ($filterType === 'all') ? 'selected' : '' ?>>Tipe Mutasi: Semua Tipe</option>
              <option value="in" <?= ($filterType === 'in') ? 'selected' : '' ?>>Stok Masuk (In)</option>
              <option value="out" <?= ($filterType === 'out') ? 'selected' : '' ?>>Obat Keluar / Resep (Out)</option>
              <option value="adjustment" <?= ($filterType === 'adjustment') ? 'selected' : '' ?>>Penyesuaian (Adjustment)</option>
              <option value="expired" <?= ($filterType === 'expired') ? 'selected' : '' ?>>Expired / Rusak (Expired)</option>
              <option value="return" <?= ($filterType === 'return') ? 'selected' : '' ?>>Pengembalian (Return)</option>
            </select>

            <div style="display:flex; align-items:center; gap:6px;">
              <input type="date" name="start_date" class="filter-date-input" value="<?= h($startDate) ?>" onchange="this.form.submit()" title="Tanggal Awal">
              <span style="color:var(--text-light); font-size:12px;">s/d</span>
              <input type="date" name="end_date" class="filter-date-input" value="<?= h($endDate) ?>" onchange="this.form.submit()" title="Tanggal Akhir">
            </div>
          </div>
        </form>

        <!-- Keterangan Legend Tipe Mutasi -->
        <div style="font-size: 11.5px; color: var(--text-light); margin-top: -6px; margin-bottom: 14px;">
          Tipe: Semua Tipe · Stok Masuk (In) · Obat Keluar/Resep (Out) · Penyesuaian (Adjustment) · Expired/Rusak (Expired) · Pengembalian (Return)
        </div>

        <!-- Tabel Mutasi (Read-Only Immutable Log) -->
        <div class="data-panel">
          <table class="data-table">
            <thead>
              <tr>
                <th>Tanggal & Waktu ↓</th>
                <th>Nama Obat & Batch</th>
                <th>Tipe Mutasi</th>
                <th>Jumlah Perubahan</th>
                <th>Petugas / Perencana</th>
                <th>Alasan / Catatan</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($movementList)): ?>
                <tr>
                  <td colspan="6" style="text-align: center; padding: 36px; color: var(--text-light);">
                    Belum ada riwayat mutasi stok pada periode filter yang dipilih.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($movementList as $m): ?>
                  <?php 
                  $qtyVal = (float)$m['quantity'];
                  $badgeCls = 'badge-movement-' . htmlspecialchars($m['movement_type']);
                  $badgeLabel = strtoupper($m['movement_type']);
                  if ($m['movement_type'] === 'out') $badgeLabel = 'DISPENSED / OUT';
                  ?>
                  <tr>
                    <!-- 1. Tanggal & Waktu -->
                    <td>
                      <div class="text-dark font-semibold"><?= formatTanggalIndo($m['movement_at']) ?></div>
                      <div class="subtext-muted"><?= date('H:i', strtotime($m['movement_at'])) ?> WIB</div>
                    </td>

                    <!-- 2. Nama Obat & Batch -->
                    <td>
                      <div class="medicine-name-title"><?= h($m['medicine_name']) ?></div>
                      <div class="batch-code"><?= h($m['batch_number']) ?></div>
                    </td>

                    <!-- 3. Tipe Mutasi -->
                    <td>
                      <span class="badge-movement <?= $badgeCls ?>"><?= $badgeLabel ?></span>
                    </td>

                    <!-- 4. Jumlah Perubahan -->
                    <td>
                      <?php if ($qtyVal > 0): ?>
                        <span class="qty-positive">+<?= number_format($qtyVal, 0, ',', '.') ?> <?= h($m['medicine_unit']) ?></span>
                      <?php else: ?>
                        <span class="qty-negative danger"><?= number_format($qtyVal, 0, ',', '.') ?> <?= h($m['medicine_unit']) ?></span>
                      <?php endif; ?>
                    </td>

                    <!-- 5. Petugas / Perencana -->
                    <td>
                      <div class="text-dark font-semibold"><?= h($m['user_name'] ?: 'Petugas Sistem') ?></div>
                      <div class="subtext-muted"><?= h(formatRoleLabel($m['user_role'] ?: 'Petugas')) ?></div>
                    </td>

                    <!-- 6. Alasan / Catatan (Read-Only) -->
                    <td>
                      <div class="text-gray" style="max-width: 320px;"><?= h($m['notes'] ?: '-') ?></div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>

          <!-- Footer Ringkasan Mutasi -->
          <div class="table-footer-summary">
            <div>
              Menampilkan <?= count($movementList) ?> riwayat mutasi stok
            </div>
            <div>
              <span class="subtext-muted">Audit log bersifat permanen dan tidak dapat diubah/dihapus secara langsung.</span>
            </div>
          </div>
        </div>

      <?php endif; ?>

      <!-- BANNER FEFO NOTICE (DI KEDUA TAB) -->
      <div class="fefo-banner">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
        <span>Prioritaskan batch dengan tanggal expired terdekat (FEFO). Setiap penyesuaian stok dicatat secara otomatis dalam riwayat mutasi.</span>
      </div>

      <!-- FOOTER -->
      <footer style="margin-top: 14px; display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-light); padding-bottom: 20px;">
        <div>© 2026 <?= h($_SESSION['clinic_name']) ?> · Farmasi & Stok Obat · Panel Kontrol Admin</div>
        <div>Zona waktu <?= h($_SESSION['timezone'] ?? 'Asia/Jakarta (WIB)') ?></div>
      </footer>

    </main>
  </div>

  <!-- ========================================================== -->
  <!-- MODAL 1: FORM INPUT BATCH BARU                             -->
  <!-- ========================================================== -->
  <div class="modal-overlay d-none" id="modalInputBatch">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean">Input Batch Baru</h3>
        <button type="button" class="modal-close-btn" onclick="closeInputBatchModal()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="pharmacy-stock.php" id="formInputBatch">
        <input type="hidden" name="action" value="input_batch">

        <div class="modal-body-scroll">
          
          <!-- Pilih Obat -->
          <div class="form-field-group">
            <label for="input_clinic_medicine_id">Pilih Obat *</label>
            <select name="clinic_medicine_id" id="input_clinic_medicine_id" class="input-control" required onchange="onMedicineSelected(this)">
              <option value="">-- Pilih Obat dari Inventaris Klinik --</option>
              <?php foreach ($clinicMedicineOptions as $med): ?>
                <option value="<?= $med['clinic_medicine_id'] ?>" data-unit="<?= h($med['unit']) ?>">
                  <?= h($med['name']) ?> (<?= h($med['dosage_form']) ?>) - Satuan: <?= h($med['unit']) ?>
                </option>
              <?php endforeach; ?>

              <?php if (!empty($globalMedicineOptions)): ?>
                <optgroup label="-- Tambah dari Master Data Global --">
                  <?php foreach ($globalMedicineOptions as $gmed): ?>
                    <option value="new_<?= $gmed['id'] ?>" data-new="1" data-medid="<?= $gmed['id'] ?>" data-unit="<?= h($gmed['unit']) ?>">
                      + <?= h($gmed['name']) ?> (<?= h($gmed['dosage_form']) ?>)
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
            <input type="hidden" name="new_medicine_id" id="hidden_new_medicine_id" value="0">
          </div>

          <!-- Nomor Batch & Tanggal Expired -->
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_batch_number">Nomor Batch *</label>
              <input type="text" name="batch_number" id="input_batch_number" class="input-control" placeholder="Contoh: AMX-260215" required>
            </div>
            <div class="form-field-group">
              <label for="input_expiry_date">Tanggal Expired *</label>
              <input type="date" name="expiry_date" id="input_expiry_date" class="input-control" required min="<?= date('Y-m-d') ?>">
            </div>
          </div>

          <!-- Jumlah Masuk & Harga Beli -->
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label for="input_quantity">Jumlah Masuk (<span id="label_input_unit">Satuan</span>) *</label>
              <input type="number" name="quantity" id="input_quantity" class="input-control" placeholder="0" min="1" step="any" required>
            </div>
            <div class="form-field-group">
              <label for="input_purchase_price">Harga Beli Satuan (Rp)</label>
              <input type="number" name="purchase_price" id="input_purchase_price" class="input-control" placeholder="0" min="0" step="any">
            </div>
          </div>

          <!-- Supplier / Pemasok -->
          <div class="form-field-group">
            <label for="input_supplier_name">Pemasok / Distributor</label>
            <input type="text" name="supplier_name" id="input_supplier_name" class="input-control" placeholder="Contoh: PT Sembada Farma">
          </div>

          <!-- Catatan Penerimaan -->
          <div class="form-field-group">
            <label for="input_notes">Catatan Penerimaan</label>
            <textarea name="notes" id="input_notes" class="input-control" rows="2" placeholder="Catatan penerimaan stok baru..."></textarea>
          </div>

        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeInputBatchModal()">Batal</button>
          <button type="submit" class="btn-modal-save">+ Simpan Batch Baru</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ========================================================== -->
  <!-- MODAL 2: FORM PENYESUAIAN STOK                             -->
  <!-- ========================================================== -->
  <div class="modal-overlay d-none" id="modalAdjustStock">
    <div class="modal-dialog">
      <div class="modal-header-clean">
        <h3 class="modal-title-clean">Form Penyesuaian Stok</h3>
        <button type="button" class="modal-close-btn" onclick="closeAdjustModal()" aria-label="Tutup">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
      </div>

      <form method="POST" action="pharmacy-stock.php" id="formAdjustStock">
        <input type="hidden" name="action" value="adjust_stock">
        <input type="hidden" name="return_tab" value="<?= h($activeTab) ?>">

        <div class="modal-body-scroll">
          
          <!-- Pilih Obat & Batch -->
          <div class="form-field-group">
            <label for="adjust_batch_id">Pilih Obat &amp; Nomor Batch *</label>
            <select name="batch_id" id="adjust_batch_id" class="input-control" required onchange="onBatchSelected(this)">
              <option value="">-- Pilih Batch Obat --</option>
              <?php foreach ($allBatchOptions as $ab): ?>
                <option value="<?= $ab['batch_id'] ?>" data-qty="<?= (float)$ab['quantity_on_hand'] ?>" data-unit="<?= h($ab['unit']) ?>" data-medname="<?= h($ab['medicine_name']) ?>" data-batchnum="<?= h($ab['batch_number']) ?>">
                  <?= h($ab['medicine_name']) ?> — Batch: <?= h($ab['batch_number']) ?> (Sisa: <?= (float)$ab['quantity_on_hand'] ?> <?= h($ab['unit']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Stok Sistem Saat Ini & Stok Fisik Baru -->
          <div class="form-row-grid-2">
            <div class="form-field-group">
              <label>Stok Sistem Saat Ini</label>
              <input type="text" id="display_current_qty" class="input-control" readonly value="-">
            </div>
            <div class="form-field-group">
              <label for="adjust_new_quantity">Stok Fisik Baru (Opname) *</label>
              <input type="number" name="new_quantity" id="adjust_new_quantity" class="input-control" placeholder="0" min="0" step="any" required oninput="calculateStockDiff()">
            </div>
          </div>

          <!-- Selisih Stok (Real-time Calculation) -->
          <div class="diff-preview-box">
            <span style="color:var(--text-gray); font-weight:500;">Perkiraan Selisih Stok:</span>
            <span id="display_diff_val" class="diff-value diff-zero">0 (Tidak ada perubahan)</span>
          </div>

          <!-- Tipe / Alasan Penyesuaian -->
          <div class="form-field-group">
            <label for="adjust_reason_type">Tipe Penyesuaian *</label>
            <select name="reason_type" id="adjust_reason_type" class="input-control" required>
              <option value="adjustment">Penyesuaian Stok Opname / Koreksi Selisih</option>
              <option value="expired">Kedaluwarsa / Rusak (Pemisahan &amp; Pemusnahan)</option>
              <option value="return">Pengembalian / Retur Unit</option>
            </select>
          </div>

          <!-- Catatan / Keterangan Wajib -->
          <div class="form-field-group">
            <label for="adjust_notes">Keterangan / Alasan Wajib *</label>
            <textarea name="notes" id="adjust_notes" class="input-control" rows="2" placeholder="Contoh: Stok opname bulanan - selisih kemasan rusak" required></textarea>
          </div>

        </div>

        <div class="modal-footer-clean">
          <button type="button" class="btn-modal-cancel" onclick="closeAdjustModal()">Batal</button>
          <button type="submit" class="btn-modal-save">Simpan Penyesuaian</button>
        </div>
      </form>
    </div>
  </div>

  <!-- JAVASCRIPT LOGIC -->
  <script>
    // Live Clock
    function updateClock() {
      const now = new Date();
      const months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
      const day = now.getDate();
      const month = months[now.getMonth()];
      const year = now.getFullYear();
      const hours = String(now.getHours()).padStart(2, '0');
      const mins = String(now.getMinutes()).padStart(2, '0');
      const clockEl = document.getElementById('clock-top');
      if (clockEl) {
        clockEl.textContent = `${day} ${month} ${year} · ${hours}:${mins} WIB`;
      }
    }
    setInterval(updateClock, 1000);
    updateClock();

    // Modal Input Batch Baru
    function openInputBatchModal() {
      document.getElementById('modalInputBatch').classList.remove('d-none');
    }
    function closeInputBatchModal() {
      document.getElementById('modalInputBatch').classList.add('d-none');
      document.getElementById('formInputBatch').reset();
      document.getElementById('label_input_unit').textContent = 'Satuan';
      document.getElementById('hidden_new_medicine_id').value = '0';
    }

    function onMedicineSelected(selectEl) {
      const opt = selectEl.options[selectEl.selectedIndex];
      const unit = opt.getAttribute('data-unit') || 'Satuan';
      document.getElementById('label_input_unit').textContent = unit;

      if (opt.getAttribute('data-new') === '1') {
        document.getElementById('hidden_new_medicine_id').value = opt.getAttribute('data-medid');
      } else {
        document.getElementById('hidden_new_medicine_id').value = '0';
      }
    }

    // Modal Penyesuaian Stok
    let currentActiveBatchQty = 0;
    let currentActiveBatchUnit = '';

    function openAdjustModal() {
      document.getElementById('modalAdjustStock').classList.remove('d-none');
    }

    function closeAdjustModal() {
      document.getElementById('modalAdjustStock').classList.add('d-none');
      document.getElementById('formAdjustStock').reset();
      document.getElementById('display_current_qty').value = '-';
      document.getElementById('display_diff_val').textContent = '0 (Tidak ada perubahan)';
      document.getElementById('display_diff_val').className = 'diff-value diff-zero';
      currentActiveBatchQty = 0;
      currentActiveBatchUnit = '';
    }

    // Dibuka langsung dari baris tabel batch
    function openAdjustModalForBatch(batchId, medName, batchNum, qtyOnHand, unit) {
      openAdjustModal();
      const selectEl = document.getElementById('adjust_batch_id');
      selectEl.value = batchId;

      currentActiveBatchQty = qtyOnHand;
      currentActiveBatchUnit = unit;

      document.getElementById('display_current_qty').value = `${qtyOnHand} ${unit}`;
      document.getElementById('adjust_new_quantity').value = '';
      calculateStockDiff();
    }

    function onBatchSelected(selectEl) {
      const opt = selectEl.options[selectEl.selectedIndex];
      if (!opt.value) {
        document.getElementById('display_current_qty').value = '-';
        currentActiveBatchQty = 0;
        currentActiveBatchUnit = '';
        calculateStockDiff();
        return;
      }
      currentActiveBatchQty = parseFloat(opt.getAttribute('data-qty')) || 0;
      currentActiveBatchUnit = opt.getAttribute('data-unit') || '';
      document.getElementById('display_current_qty').value = `${currentActiveBatchQty} ${currentActiveBatchUnit}`;
      calculateStockDiff();
    }

    function calculateStockDiff() {
      const newQtyInput = document.getElementById('adjust_new_quantity');
      const diffDisplay = document.getElementById('display_diff_val');

      if (!newQtyInput.value || document.getElementById('adjust_batch_id').value === '') {
        diffDisplay.textContent = '0 (Tidak ada perubahan)';
        diffDisplay.className = 'diff-value diff-zero';
        return;
      }

      const newQty = parseFloat(newQtyInput.value);
      const diff = newQty - currentActiveBatchQty;

      if (diff > 0) {
        diffDisplay.textContent = `+${diff} ${currentActiveBatchUnit} (Penambahan Stok)`;
        diffDisplay.className = 'diff-value diff-positive';
      } else if (diff < 0) {
        diffDisplay.textContent = `${diff} ${currentActiveBatchUnit} (Pengurangan Stok)`;
        diffDisplay.className = 'diff-value diff-negative';
      } else {
        diffDisplay.textContent = `0 (Tidak ada perubahan)`;
        diffDisplay.className = 'diff-value diff-zero';
      }
    }
  </script>

</body>
</html>
