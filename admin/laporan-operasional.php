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

function formatTanggalIndoShort($dateStr) {
    if (!$dateStr) return '-';
    $ts = strtotime($dateStr);
    if (!$ts) return '-';
    $bulan = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr',
        5 => 'Mei', 6 => 'Jun', 7 => 'Jul', 8 => 'Agu',
        9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des'
    ];
    return date('d', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function formatBytes($bytes) {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function getReportTypeLabel($type) {
    $map = [
        'kunjungan_pasien'      => 'Kunjungan & Pasien',
        'rekam_medis_diagnosis' => 'Rekam Medis & Diagnosis',
        'farmasi_stok'          => 'Farmasi & Stok Obat',
        'keuangan_pendapatan'   => 'Keuangan & Pendapatan',
    ];
    return $map[$type] ?? ucwords(str_replace('_', ' ', (string)$type));
}

function getDefaultReportTitle($type) {
    $map = [
        'kunjungan_pasien'      => 'Rekap Kunjungan & Pasien',
        'rekam_medis_diagnosis' => 'Rekap Rekam Medis & Diagnosis',
        'farmasi_stok'          => 'Laporan Farmasi & Stok Obat',
        'keuangan_pendapatan'   => 'Laporan Keuangan & Pendapatan',
    ];
    return $map[$type] ?? 'Laporan Operasional Klinik';
}

function generateReportFile($conn, $clinicId, $clinicName, $reportCode, $reportType, $startDate, $endDate, $fileFormat) {
    $uploadDir = __DIR__ . '/../uploads/reports';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new Exception('Gagal membuat direktori penyimpanan laporan.');
        }
    }

    $headers = [];
    $rows = [];
    $summaryNote = '';

    if ($reportType === 'kunjungan_pasien') {
        $headers = ['No. Registrasi', 'Waktu Kunjungan', 'Nama Pasien', 'No. RM', 'Dokter', 'Tipe Kunjungan', 'Status', 'Keluhan'];
        $stmt = $conn->prepare("
            SELECT r.registration_number, r.registration_date, p.full_name,
                   COALESCE(pc.medical_record_number, '-') AS mrn,
                   COALESCE(u.name, 'Poli Umum') AS doctor_name,
                   r.visit_type, r.status, COALESCE(r.complaint, '-') AS complaint
            FROM registrations r
            JOIN patients p ON p.id = r.patient_id
            LEFT JOIN patient_clinics pc ON pc.patient_id = p.id AND pc.clinic_id = r.clinic_id
            LEFT JOIN doctors d ON d.id = r.doctor_id
            LEFT JOIN users u ON u.id = d.user_id
            WHERE r.clinic_id = ? AND DATE(r.registration_date) BETWEEN ? AND ?
            ORDER BY r.registration_date DESC
        ");
        $stmt->bind_param('iss', $clinicId, $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                $r['registration_number'],
                date('d/m/Y H:i', strtotime($r['registration_date'])),
                $r['full_name'],
                $r['mrn'],
                $r['doctor_name'],
                $r['visit_type'],
                $r['status'],
                $r['complaint'],
            ];
        }
        $summaryNote = 'Total Kunjungan: ' . count($rows);
    } elseif ($reportType === 'rekam_medis_diagnosis') {
        $headers = ['No. Registrasi', 'Tanggal Periksa', 'Nama Pasien', 'No. RM', 'Kode ICD', 'Diagnosis', 'Tipe Diagnosis', 'Status RM'];
        $stmt = $conn->prepare("
            SELECT r.registration_number, mr.created_at, p.full_name,
                   COALESCE(pc.medical_record_number, '-') AS mrn,
                   COALESCE(dg.code, '-') AS icd_code,
                   COALESCE(dg.name, '-') AS diagnosis_name,
                   COALESCE(mrd.diagnosis_type, '-') AS diagnosis_type,
                   mr.status
            FROM medical_records mr
            JOIN visits v ON v.id = mr.visit_id
            JOIN registrations r ON r.id = v.registration_id
            JOIN patients p ON p.id = r.patient_id
            LEFT JOIN patient_clinics pc ON pc.patient_id = p.id AND pc.clinic_id = r.clinic_id
            LEFT JOIN medical_record_diagnoses mrd ON mrd.medical_record_id = mr.id
            LEFT JOIN diagnoses dg ON dg.id = mrd.diagnosis_id
            WHERE r.clinic_id = ? AND DATE(mr.created_at) BETWEEN ? AND ?
            ORDER BY mr.created_at DESC
        ");
        $stmt->bind_param('iss', $clinicId, $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                $r['registration_number'],
                date('d/m/Y H:i', strtotime($r['created_at'])),
                $r['full_name'],
                $r['mrn'],
                $r['icd_code'],
                $r['diagnosis_name'],
                $r['diagnosis_type'],
                $r['status'],
            ];
        }
        $summaryNote = 'Total Catatan Rekam Medis & Diagnosis: ' . count($rows);
    } elseif ($reportType === 'farmasi_stok') {
        $headers = ['Waktu Mutasi', 'Kode Obat', 'Nama Obat', 'Nomor Batch', 'Expired', 'Tipe Mutasi', 'Jumlah', 'Satuan', 'Sisa Batch', 'Petugas', 'Catatan'];
        $stmt = $conn->prepare("
            SELECT sm.movement_at, m.medicine_code, m.name AS medicine_name,
                   mb.batch_number, mb.expiry_date, sm.movement_type, sm.quantity,
                   m.unit, mb.quantity_on_hand,
                   COALESCE(u.name, 'Sistem') AS officer_name,
                   COALESCE(sm.notes, '-') AS notes
            FROM stock_movements sm
            JOIN medicine_batches mb ON mb.id = sm.medicine_batch_id
            JOIN clinic_medicines cm ON cm.id = mb.clinic_medicine_id
            JOIN medicines m ON m.id = cm.medicine_id
            LEFT JOIN users u ON u.id = sm.performed_by
            WHERE cm.clinic_id = ? AND DATE(sm.movement_at) BETWEEN ? AND ?
            ORDER BY sm.movement_at DESC
        ");
        $stmt->bind_param('iss', $clinicId, $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $rows[] = [
                date('d/m/Y H:i', strtotime($r['movement_at'])),
                $r['medicine_code'],
                $r['medicine_name'],
                $r['batch_number'],
                $r['expiry_date'] ? date('d/m/Y', strtotime($r['expiry_date'])) : '-',
                strtoupper($r['movement_type']),
                $r['quantity'],
                $r['unit'],
                $r['quantity_on_hand'],
                $r['officer_name'],
                $r['notes'],
            ];
        }
        $summaryNote = 'Total Mutasi Stok Periode Ini: ' . count($rows);
    } elseif ($reportType === 'keuangan_pendapatan') {
        $headers = ['No. Registrasi', 'Tanggal Tindakan', 'Nama Pasien', 'Kode Tindakan', 'Nama Tindakan', 'Qty', 'Tarif Satuan (Rp)', 'Subtotal (Rp)'];
        $stmt = $conn->prepare("
            SELECT r.registration_number, COALESCE(mra.performed_at, mr.created_at) AS action_date,
                   p.full_name, COALESCE(ma.code, '-') AS action_code, ma.name AS action_name,
                   mra.quantity, mra.unit_price, (mra.quantity * mra.unit_price) AS subtotal
            FROM medical_record_actions mra
            JOIN medical_actions ma ON ma.id = mra.medical_action_id
            JOIN medical_records mr ON mr.id = mra.medical_record_id
            JOIN visits v ON v.id = mr.visit_id
            JOIN registrations r ON r.id = v.registration_id
            JOIN patients p ON p.id = r.patient_id
            WHERE r.clinic_id = ? AND DATE(COALESCE(mra.performed_at, mr.created_at)) BETWEEN ? AND ?
            ORDER BY action_date DESC
        ");
        $stmt->bind_param('iss', $clinicId, $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        $totalRevenue = 0;
        while ($r = $res->fetch_assoc()) {
            $sub = (float)$r['subtotal'];
            $totalRevenue += $sub;
            $rows[] = [
                $r['registration_number'],
                date('d/m/Y H:i', strtotime($r['action_date'])),
                $r['full_name'],
                $r['action_code'],
                $r['action_name'],
                $r['quantity'],
                number_format((float)$r['unit_price'], 0, ',', '.'),
                number_format($sub, 0, ',', '.'),
            ];
        }
        $summaryNote = 'Total Tindakan: ' . count($rows) . ' · Total Pendapatan: Rp ' . number_format($totalRevenue, 0, ',', '.');
    } else {
        throw new Exception('Jenis laporan tidak dikenali.');
    }

    $titleLabel = getDefaultReportTitle($reportType);
    $periodText = formatTanggalIndoShort($startDate) . ' - ' . formatTanggalIndoShort($endDate);

    if (stripos($fileFormat, 'Excel') !== false || stripos($fileFormat, 'CSV') !== false) {
        $filename = $reportCode . '.csv';
        $absPath  = $uploadDir . '/' . $filename;
        $relPath  = 'uploads/reports/' . $filename;

        $fp = fopen($absPath, 'w');
        if (!$fp) {
            throw new Exception('Gagal menulis file CSV laporan.');
        }
        // UTF-8 BOM agar Excel langsung membaca karakter Indonesia dengan benar
        fwrite($fp, "\xEF\xBB\xBF");
        fputcsv($fp, [$clinicName . ' - ' . $titleLabel]);
        fputcsv($fp, ['Kode Laporan', $reportCode, 'Periode', $periodText, 'Ringkasan', $summaryNote]);
        fputcsv($fp, []);
        fputcsv($fp, $headers);
        foreach ($rows as $row) {
            fputcsv($fp, $row);
        }
        if (empty($rows)) {
            fputcsv($fp, ['Tidak ada data transaksi pada periode yang dipilih.']);
        }
        fclose($fp);

        return [
            'file_path' => $relPath,
            'file_size' => formatBytes(filesize($absPath)),
            'row_count' => count($rows),
        ];
    } else {
        // Format PDF (Print-Ready HTML View yang bisa langsung Save as PDF / Cetak)
        $filename = $reportCode . '.html';
        $absPath  = $uploadDir . '/' . $filename;
        $relPath  = 'uploads/reports/' . $filename;

        $tableHeadHtml = '';
        foreach ($headers as $head) {
            $tableHeadHtml .= '<th>' . h($head) . '</th>';
        }
        $tableBodyHtml = '';
        if (empty($rows)) {
            $tableBodyHtml = '<tr><td colspan="' . count($headers) . '" style="text-align:center; padding:24px; color:#64748B;">Tidak ada data pada periode ' . h($periodText) . '.</td></tr>';
        } else {
            foreach ($rows as $row) {
                $tableBodyHtml .= '<tr>';
                foreach ($row as $cell) {
                    $tableBodyHtml .= '<td>' . h($cell) . '</td>';
                }
                $tableBodyHtml .= '</tr>';
            }
        }

        $htmlContent = '<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>' . h($titleLabel) . ' - ' . h($reportCode) . '</title>
<style>
  body { font-family: Inter, Arial, sans-serif; color: #0F172A; margin: 32px; background: #fff; }
  .toolbar { display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 20px; }
  .btn-print { background: #2563EB; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; }
  .header { border-bottom: 2px solid #0F172A; padding-bottom: 14px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: flex-end; }
  .clinic-name { font-size: 20px; font-weight: 700; margin: 0 0 4px 0; }
  .report-title { font-size: 15px; font-weight: 600; color: #2563EB; margin: 0; }
  .meta { text-align: right; font-size: 12px; color: #475569; line-height: 1.6; }
  .summary-box { background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 10px 14px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; }
  th { background: #F1F5F9; color: #334155; text-align: left; padding: 9px 10px; border: 1px solid #CBD5E1; font-weight: 600; }
  td { padding: 8px 10px; border: 1px solid #E2E8F0; color: #1E293B; }
  @media print { .toolbar { display: none; } body { margin: 12px; } }
</style>
</head>
<body>
  <div class="toolbar">
    <button class="btn-print" onclick="window.print()">Cetak / Simpan sebagai PDF</button>
  </div>
  <div class="header">
    <div>
      <h1 class="clinic-name">' . h($clinicName) . '</h1>
      <p class="report-title">' . h($titleLabel) . '</p>
    </div>
    <div class="meta">
      <div>Kode: <strong>' . h($reportCode) . '</strong></div>
      <div>Periode: <strong>' . h($periodText) . '</strong></div>
      <div>Dicetak: ' . date('d/m/Y H:i') . ' WIB</div>
    </div>
  </div>
  <div class="summary-box">' . h($summaryNote) . '</div>
  <table>
    <thead><tr>' . $tableHeadHtml . '</tr></thead>
    <tbody>' . $tableBodyHtml . '</tbody>
  </table>
</body>
</html>';

        if (file_put_contents($absPath, $htmlContent) === false) {
            throw new Exception('Gagal menyimpan file PDF/Print View laporan.');
        }

        return [
            'file_path' => $relPath,
            'file_size' => formatBytes(filesize($absPath)),
            'row_count' => count($rows),
        ];
    }
}

$clinic_id   = (int)$_SESSION['clinic_id'];
$userId      = (int)($_SESSION['user_id'] ?? 1);
$clinicName  = $_SESSION['clinic_name'] ?? 'Klinik Sehat Bersama';

// 4. ENDPOINT UNDUH / BUKA FILE LAPORAN (?download=ID)
if (isset($_GET['download'])) {
    $dlId = (int)$_GET['download'];
    $stmtDl = $conn->prepare("SELECT * FROM reports WHERE id = ? AND clinic_id = ? AND status = 'completed'");
    if ($stmtDl) {
        $stmtDl->bind_param('ii', $dlId, $clinic_id);
        $stmtDl->execute();
        $rep = $stmtDl->get_result()->fetch_assoc();
        if ($rep && !empty($rep['file_path'])) {
            $safeBase = realpath(__DIR__ . '/../uploads/reports');
            $targetPath = realpath(__DIR__ . '/../' . $rep['file_path']);
            if ($safeBase && $targetPath && strpos($targetPath, $safeBase) === 0 && is_file($targetPath)) {
                $ext = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
                if ($ext === 'csv') {
                    header('Content-Type: text/csv; charset=UTF-8');
                    header('Content-Disposition: attachment; filename="' . basename($targetPath) . '"');
                    readfile($targetPath);
                    exit;
                } else {
                    header('Content-Type: text/html; charset=UTF-8');
                    readfile($targetPath);
                    exit;
                }
            }
        }
    }
    $_SESSION['flash_error'] = 'File laporan tidak ditemukan atau belum selesai diproses.';
    header('Location: laporan-operasional.php');
    exit;
}

// 5. HANDLER POST (BUAT LAPORAN BARU / BUAT ULANG)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_report') {
        $reportType = trim($_POST['report_type'] ?? 'kunjungan_pasien');
        $startDate  = trim($_POST['start_date'] ?? date('Y-m-01'));
        $endDate    = trim($_POST['end_date'] ?? date('Y-m-d'));
        $fileFormat = trim($_POST['file_format'] ?? 'PDF');

        $allowedTypes = ['kunjungan_pasien', 'rekam_medis_diagnosis', 'farmasi_stok', 'keuangan_pendapatan'];
        if (!in_array($reportType, $allowedTypes, true)) {
            $reportType = 'kunjungan_pasien';
        }
        $allowedFormats = ['PDF', 'Excel'];
        if (!in_array($fileFormat, $allowedFormats, true)) {
            $fileFormat = 'PDF';
        }

        if ($startDate === '' || $endDate === '' || strtotime($startDate) > strtotime($endDate)) {
            $_SESSION['flash_error'] = 'Rentang tanggal tidak valid. Tanggal mulai tidak boleh melebihi tanggal akhir.';
            header('Location: laporan-operasional.php');
            exit;
        }

        $seq = fetchCount($conn, "SELECT COUNT(*) FROM reports WHERE clinic_id = ?", $clinic_id) + 1;
        $reportCode = 'RPT-' . date('Ym') . '-' . str_pad((string)$seq, 3, '0', STR_PAD_LEFT);
        $title = getDefaultReportTitle($reportType);

        $initParams = json_encode([
            'report_code'   => $reportCode,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
            'file_format'   => $fileFormat,
            'file_size'     => null,
            'row_count'     => 0,
            'error_message' => null,
        ]);

        // Simpan permintaan awal
        $stmtIns = $conn->prepare("
            INSERT INTO reports (clinic_id, created_by, report_type, title, parameters, status)
            VALUES (?, ?, ?, ?, ?, 'processing')
        ");
        $stmtIns->bind_param('iisss', $clinic_id, $userId, $reportType, $title, $initParams);
        $stmtIns->execute();
        $reportId = $stmtIns->insert_id;

        try {
            $gen = generateReportFile($conn, $clinic_id, $clinicName, $reportCode, $reportType, $startDate, $endDate, $fileFormat);

            $doneParams = json_encode([
                'report_code'   => $reportCode,
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'file_format'   => $fileFormat,
                'file_size'     => $gen['file_size'],
                'row_count'     => $gen['row_count'],
                'error_message' => null,
            ]);

            $stmtUpd = $conn->prepare("
                UPDATE reports
                SET status = 'completed', file_path = ?, parameters = ?, completed_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $stmtUpd->bind_param('ssii', $gen['file_path'], $doneParams, $reportId, $clinic_id);
            $stmtUpd->execute();

            // Audit log
            $auditDetails = json_encode(['report_code' => $reportCode, 'report_type' => $reportType, 'file_format' => $fileFormat, 'row_count' => $gen['row_count']]);
            $stmtAudit = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'GENERATE_REPORT', 'reports', ?, ?)");
            if ($stmtAudit) {
                $stmtAudit->bind_param('iiis', $clinic_id, $userId, $reportId, $auditDetails);
                $stmtAudit->execute();
            }

            $_SESSION['flash_success'] = "Laporan '{$title}' ({$reportCode}) berhasil dibuat dalam format {$fileFormat} ({$gen['row_count']} baris data).";
        } catch (Exception $e) {
            $errParams = json_encode([
                'report_code'   => $reportCode,
                'start_date'    => $startDate,
                'end_date'      => $endDate,
                'file_format'   => $fileFormat,
                'file_size'     => null,
                'row_count'     => 0,
                'error_message' => $e->getMessage(),
            ]);
            $stmtFail = $conn->prepare("UPDATE reports SET status = 'failed', parameters = ? WHERE id = ? AND clinic_id = ?");
            $stmtFail->bind_param('sii', $errParams, $reportId, $clinic_id);
            $stmtFail->execute();

            $_SESSION['flash_error'] = 'Gagal membuat laporan: ' . $e->getMessage();
        }

        header('Location: laporan-operasional.php');
        exit;
    }

    if ($action === 'retry_report') {
        $reportId = (int)($_POST['report_id'] ?? 0);
        $stmtGet = $conn->prepare("SELECT * FROM reports WHERE id = ? AND clinic_id = ? AND status = 'failed'");
        $stmtGet->bind_param('ii', $reportId, $clinic_id);
        $stmtGet->execute();
        $rep = $stmtGet->get_result()->fetch_assoc();

        if (!$rep) {
            $_SESSION['flash_error'] = 'Data laporan gagal tidak ditemukan.';
        } else {
            $params = json_decode($rep['parameters'] ?? '{}', true) ?: [];
            $reportCode = $params['report_code'] ?? ('RPT-' . date('Ym') . '-' . str_pad((string)$reportId, 3, '0', STR_PAD_LEFT));
            $startDate  = $params['start_date'] ?? date('Y-m-01');
            $endDate    = $params['end_date'] ?? date('Y-m-d');
            $fileFormat = $params['file_format'] ?? 'PDF';

            try {
                $gen = generateReportFile($conn, $clinic_id, $clinicName, $reportCode, $rep['report_type'], $startDate, $endDate, $fileFormat);
                $doneParams = json_encode([
                    'report_code'   => $reportCode,
                    'start_date'    => $startDate,
                    'end_date'      => $endDate,
                    'file_format'   => $fileFormat,
                    'file_size'     => $gen['file_size'],
                    'row_count'     => $gen['row_count'],
                    'error_message' => null,
                ]);
                $stmtUpd = $conn->prepare("UPDATE reports SET status = 'completed', file_path = ?, parameters = ?, completed_at = NOW() WHERE id = ? AND clinic_id = ?");
                $stmtUpd->bind_param('ssii', $gen['file_path'], $doneParams, $reportId, $clinic_id);
                $stmtUpd->execute();

                $auditDetails = json_encode(['report_code' => $reportCode, 'retry' => true]);
                $stmtAudit = $conn->prepare("INSERT INTO audit_logs (clinic_id, user_id, action, entity_type, entity_id, details) VALUES (?, ?, 'RETRY_REPORT', 'reports', ?, ?)");
                if ($stmtAudit) {
                    $stmtAudit->bind_param('iiis', $clinic_id, $userId, $reportId, $auditDetails);
                    $stmtAudit->execute();
                }

                $_SESSION['flash_success'] = "Laporan '{$rep['title']}' ({$reportCode}) berhasil dibuat ulang.";
            } catch (Exception $e) {
                $_SESSION['flash_error'] = 'Gagal membuat ulang laporan: ' . $e->getMessage();
            }
        }
        header('Location: laporan-operasional.php');
        exit;
    }
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 6. STATISTIK RIWAYAT LAPORAN
$total_reports      = fetchCount($conn, "SELECT COUNT(*) FROM reports WHERE clinic_id = ?", $clinic_id);
$completed_reports  = fetchCount($conn, "SELECT COUNT(*) FROM reports WHERE clinic_id = ? AND status = 'completed'", $clinic_id);
$processing_reports = fetchCount($conn, "SELECT COUNT(*) FROM reports WHERE clinic_id = ? AND status IN ('queued', 'processing')", $clinic_id);
$failed_reports     = fetchCount($conn, "SELECT COUNT(*) FROM reports WHERE clinic_id = ? AND status = 'failed'", $clinic_id);

// 7. FILTER & PAGINATION RIWAYAT LAPORAN
$filterSearch = trim($_GET['q'] ?? '');
$filterType   = trim($_GET['type'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

$whereClauses = ['r.clinic_id = ?'];
$bindTypes    = 'i';
$bindValues   = [$clinic_id];

if ($filterSearch !== '') {
    $whereClauses[] = "(r.title LIKE ? OR r.parameters LIKE ?)";
    $like = '%' . $filterSearch . '%';
    $bindTypes .= 'ss';
    $bindValues[] = $like;
    $bindValues[] = $like;
}
if ($filterType !== '') {
    $whereClauses[] = "r.report_type = ?";
    $bindTypes .= 's';
    $bindValues[] = $filterType;
}
if ($filterStatus !== '') {
    if ($filterStatus === 'processing') {
        $whereClauses[] = "r.status IN ('queued', 'processing')";
    } else {
        $whereClauses[] = "r.status = ?";
        $bindTypes .= 's';
        $bindValues[] = $filterStatus;
    }
}

$whereSql = implode(' AND ', $whereClauses);

// Total terfilter
$stmtCount = $conn->prepare("SELECT COUNT(*) FROM reports r WHERE {$whereSql}");
$stmtCount->bind_param($bindTypes, ...$bindValues);
$stmtCount->execute();
$filteredTotal = (int)$stmtCount->get_result()->fetch_row()[0];
$totalPages = max(1, (int)ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

// Daftar laporan
$report_list = [];
$sqlReports = "
    SELECT r.*, COALESCE(u.name, 'Admin Klinik') AS created_by_name
    FROM reports r
    LEFT JOIN users u ON u.id = r.created_by
    WHERE {$whereSql}
    ORDER BY r.created_at DESC
    LIMIT ? OFFSET ?
";
$bindTypesList = $bindTypes . 'ii';
$bindValuesList = array_merge($bindValues, [$perPage, $offset]);
$stmtList = $conn->prepare($sqlReports);
if ($stmtList) {
    $stmtList->bind_param($bindTypesList, ...$bindValuesList);
    $stmtList->execute();
    $report_list = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Helper Status Badge
function getReportBadge($status, $error_msg = '') {
    switch (strtolower((string)$status)) {
        case 'completed':
            return '<span class="status-badge status-green">Selesai</span>';
        case 'queued':
            return '<span class="status-badge status-orange">Menunggu</span><div class="td-sub-small mt-1">Menunggu giliran</div>';
        case 'processing':
            return '<span class="status-badge status-blue">Sedang Diproses</span><div class="progress-track"><div class="progress-fill"></div></div><div class="td-sub-small mt-1">Memproses data...</div>';
        case 'failed':
            return '<span class="status-badge status-red">Gagal</span><div class="td-sub-small mt-1" style="color: #B91C1C;">' . h($error_msg ?: 'Gagal memproses') . '</div>';
        default:
            return '<span class="status-badge status-gray">' . h($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Pusat Laporan & Analitik - <?= h($clinicName) ?></title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@700;800&family=Roboto+Mono:wght@400;500;700&display=swap" rel="stylesheet">
  
  <link rel="stylesheet" href="../assets/css/style.css">
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= filemtime(__DIR__ . '/../assets/css/dashboard.css') ?>">
  <link rel="stylesheet" href="../assets/css/reports.css?v=<?= filemtime(__DIR__ . '/../assets/css/reports.css') ?>">
</head>
<body class="dashboard-body">

  <!-- TOP NAVBAR -->
  <?php require __DIR__ . '/../includes/topbar.php'; ?>

  <!-- MAIN LAYOUT -->
  <div class="dashboard-layout">
    
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="sidebar-header">
        <span class="sidebar-subtitle"><?= h(strtoupper($clinicName)) ?></span>
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
        <a href="pharmacy-stock.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path><polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline><line x1="12" y1="22.08" x2="12" y2="12"></line></svg>
          Farmasi & Stok
        </a>
        <a href="laporan-operasional.php" class="nav-item active">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"></line><line x1="12" y1="20" x2="12" y2="4"></line><line x1="6" y1="20" x2="6" y2="14"></line></svg>
          Laporan Operasional
        </a>
        <a href="pengaturan-klinik.php" class="nav-item">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
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
        <div class="breadcrumb"><?= h($clinicName) ?> / Laporan Operasional</div>
        <div class="page-title-group">
          <h1>Pusat Laporan & Analitik</h1>
          <p>Generate dan unduh laporan operasional, kunjungan pasien, serta transaksi farmasi.</p>
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

      <!-- CARD 1: KONFIGURASI & PEMBUATAN LAPORAN -->
      <div class="data-panel report-config-card">
        <div class="panel-header" style="flex-direction: column; align-items: flex-start; border-bottom: none; padding-bottom: 14px;">
          <h3 style="font-size: 16px; color: var(--text-dark); margin: 0 0 4px 0;">Buat Laporan Baru</h3>
          <p style="font-size: 12px; color: var(--text-gray); margin: 0;">Pilih jenis laporan, periode data, dan format file yang dibutuhkan.</p>
        </div>
        
        <form method="POST" action="laporan-operasional.php" class="panel-filters" style="border-top: none;">
          <input type="hidden" name="action" value="generate_report">
          <div class="report-form-grid">
            
            <div class="form-group-report" style="flex: 1 1 240px;">
              <label for="report_type">Jenis Laporan</label>
              <select name="report_type" id="report_type" class="input-report" required>
                <option value="kunjungan_pasien">Kunjungan &amp; Pasien</option>
                <option value="rekam_medis_diagnosis">Rekam Medis &amp; Diagnosis</option>
                <option value="farmasi_stok">Farmasi &amp; Stok Obat</option>
                <option value="keuangan_pendapatan">Keuangan &amp; Pendapatan</option>
              </select>
            </div>
            
            <div class="form-group-report" style="flex: 0 0 auto;">
              <label>Rentang Tanggal</label>
              <div class="date-range-inputs">
                <input type="date" name="start_date" id="start_date" class="input-report" value="<?= date('Y-m-01') ?>" required>
                <span class="date-range-sep">—</span>
                <input type="date" name="end_date" id="end_date" class="input-report" value="<?= date('Y-m-d') ?>" required>
              </div>
            </div>
            
            <div class="form-group-report" style="width: 170px;">
              <label for="file_format">Format File</label>
              <select name="file_format" id="file_format" class="input-report" required>
                <option value="PDF">PDF</option>
                <option value="Excel">Excel (.csv)</option>
              </select>
            </div>
            
            <button type="submit" class="btn-generate">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
              Buat Laporan
            </button>
            
          </div>
          
          <div class="report-form-meta">
            <span>Format tersedia: PDF / Excel (.csv) · Tanggal mengikuti Asia/Jakarta (WIB)</span>
            <span>Laporan akan langsung diproses dan tersedia di daftar riwayat di bawah.</span>
          </div>
        </form>
      </div>

      <!-- CARD 2: RIWAYAT & STATUS LAPORAN -->
      <div class="data-panel">
        <div class="panel-header">
          <div class="panel-title-group" style="display: flex; flex-direction: column; gap: 4px;">
            <div style="display: flex; align-items: center; gap: 10px;">
              <h3 style="margin: 0; font-size: 16px;">Riwayat Laporan</h3>
              <span style="background: #F8FAFC; color: #475569; padding: 4px 9px; border-radius: 6px; font-size: 11px; font-weight: 600; border: 1px solid var(--border);"><?= $total_reports ?> laporan</span>
            </div>
            <p style="margin: 0; font-size: 12px; color: var(--text-gray);">Pantau status pembuatan dan unduh laporan yang telah selesai.</p>
          </div>
          <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <span class="report-stat-pill" style="background: #ECFDF5; color: #047857;"><?= $completed_reports ?> selesai</span>
            <span class="report-stat-pill" style="background: #EFF6FF; color: #2563EB;"><?= $processing_reports ?> sedang diproses</span>
            <span class="report-stat-pill" style="background: #FEF2F2; color: #B91C1C;"><?= $failed_reports ?> gagal</span>
          </div>
        </div>

        <!-- Filters Table -->
        <form method="GET" action="laporan-operasional.php" class="panel-filters" id="filterReportForm">
          <div class="filter-row">
            <div class="filter-search">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" name="q" id="searchReport" value="<?= h($filterSearch) ?>" placeholder="Cari judul atau kode laporan..." class="search-input" onkeyup="filterReportsLive()">
            </div>
            <select name="type" id="filterReportType" class="filter-select" onchange="this.form.submit()">
              <option value="">Jenis Laporan: Semua</option>
              <option value="kunjungan_pasien" <?= $filterType === 'kunjungan_pasien' ? 'selected' : '' ?>>Kunjungan &amp; Pasien</option>
              <option value="rekam_medis_diagnosis" <?= $filterType === 'rekam_medis_diagnosis' ? 'selected' : '' ?>>Rekam Medis &amp; Diagnosis</option>
              <option value="farmasi_stok" <?= $filterType === 'farmasi_stok' ? 'selected' : '' ?>>Farmasi &amp; Stok Obat</option>
              <option value="keuangan_pendapatan" <?= $filterType === 'keuangan_pendapatan' ? 'selected' : '' ?>>Keuangan &amp; Pendapatan</option>
            </select>
            <select name="status" id="filterReportStatus" class="filter-select" onchange="this.form.submit()">
              <option value="">Status: Semua</option>
              <option value="completed" <?= $filterStatus === 'completed' ? 'selected' : '' ?>>Selesai</option>
              <option value="processing" <?= $filterStatus === 'processing' ? 'selected' : '' ?>>Sedang Diproses</option>
              <option value="failed" <?= $filterStatus === 'failed' ? 'selected' : '' ?>>Gagal</option>
            </select>
            <div class="flex-spacer"></div>
            <span style="font-size: 11px; color: var(--text-gray);">Riwayat <?= formatTanggalIndoShort(date('Y-m-d')) ?></span>
          </div>
        </form>

        <!-- Table Data -->
        <div class="table-responsive">
          <table class="data-table queue-table" id="tableReportHistory">
            <thead>
              <tr>
                <th width="22%">Nama Laporan</th>
                <th width="16%">Jenis</th>
                <th width="16%">Rentang Tanggal</th>
                <th width="15%">Dibuat Oleh</th>
                <th width="13%">Status Proses</th>
                <th width="10%">Waktu Selesai</th>
                <th width="8%">Tindakan</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($report_list)): ?>
                <tr>
                  <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-gray);">
                    Belum ada riwayat pembuatan laporan. Silakan buat laporan baru di atas.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($report_list as $row): 
                  $params     = json_decode($row['parameters'] ?? '{}', true) ?: [];
                  $repCode    = $params['report_code'] ?? ('RPT-' . str_pad((string)$row['id'], 4, '0', STR_PAD_LEFT));
                  $fileFmt    = $params['file_format'] ?? 'PDF';
                  $fileSize   = $params['file_size'] ?? null;
                  $startDt    = $params['start_date'] ?? null;
                  $endDt      = $params['end_date'] ?? null;
                  $errMsg     = $params['error_message'] ?? '';
                  $dateRange  = ($startDt && $endDt) ? (formatTanggalIndoShort($startDt) . ' – ' . formatTanggalIndoShort($endDt)) : '-';
                ?>
                  <tr>
                    <td>
                      <div class="td-main"><?= h($row['title']) ?></div>
                      <div class="td-sub-small mt-1"><?= h($repCode) ?> · <?= h($fileFmt) ?><?= $fileSize ? ' (' . h($fileSize) . ')' : '' ?></div>
                    </td>
                    <td><div class="td-desc"><?= h(getReportTypeLabel($row['report_type'])) ?></div></td>
                    <td><div class="td-desc"><?= h($dateRange) ?></div></td>
                    <td>
                      <div class="td-main fw-normal"><?= h($row['created_by_name']) ?></div>
                      <div class="td-sub-small mt-1"><?= formatTanggalIndoShort($row['created_at']) ?> · <?= date('H:i', strtotime($row['created_at'])) ?> WIB</div>
                    </td>
                    <td>
                      <?= getReportBadge($row['status'], $errMsg) ?>
                    </td>
                    <td>
                      <?php if ($row['status'] === 'completed' && !empty($row['completed_at'])): ?>
                        <div class="td-desc"><?= formatTanggalIndoShort($row['completed_at']) ?></div>
                        <div class="td-sub-small mt-1"><?= date('H:i', strtotime($row['completed_at'])) ?> WIB</div>
                      <?php else: ?>
                        <div class="td-desc">—</div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (strtolower($row['status']) === 'completed'): ?>
                        <a href="laporan-operasional.php?download=<?= (int)$row['id'] ?>" target="<?= stripos($fileFmt, 'PDF') !== false ? '_blank' : '_self' ?>" class="btn-download-report">
                          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                          Unduh
                        </a>
                      <?php elseif (strtolower($row['status']) === 'failed'): ?>
                        <form method="POST" action="laporan-operasional.php" style="margin:0;">
                          <input type="hidden" name="action" value="retry_report">
                          <input type="hidden" name="report_id" value="<?= (int)$row['id'] ?>">
                          <button type="submit" class="btn-retry-report">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
                            Buat Ulang
                          </button>
                        </form>
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
        <?php
          $startItem = $filteredTotal > 0 ? ($offset + 1) : 0;
          $endItem   = min($offset + count($report_list), $filteredTotal);
          $queryBase = http_build_query(array_filter([
              'q'      => $filterSearch,
              'type'   => $filterType,
              'status' => $filterStatus,
          ]));
          $pagePrefix = $queryBase ? ('?' . $queryBase . '&page=') : '?page=';
        ?>
        <div class="pagination-footer">
          <div class="page-info" id="reportPageInfo">Menampilkan <?= $startItem ?>–<?= $endItem ?> dari <?= $filteredTotal ?> laporan</div>
          <div class="page-controls">
            <a href="<?= $page > 1 ? h($pagePrefix . ($page - 1)) : '#' ?>" class="btn-page <?= $page <= 1 ? 'disabled' : '' ?>">Sebelumnya</a>
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
              <a href="<?= h($pagePrefix . $p) ?>" class="page-num <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a href="<?= $page < $totalPages ? h($pagePrefix . ($page + 1)) : '#' ?>" class="btn-page <?= $page >= $totalPages ? 'disabled' : '' ?>">Berikutnya</a>
          </div>
        </div>
      </div>

      <!-- Info Note -->
      <div class="info-note">
        ⓘ Unduh tersedia hanya untuk laporan berstatus Selesai. Jika laporan gagal, gunakan Buat Ulang untuk memproses ulang permintaan dengan parameter yang sama.
      </div>

      <!-- Footer Dashboard -->
      <footer class="dashboard-footer" style="margin-top: auto; display: flex; justify-content: space-between;">
        <span>© <?= date('Y') ?> <?= h($clinicName) ?> · Panel Kontrol Admin</span>
        <span>Zona waktu Asia/Jakarta (WIB)</span>
      </footer>

    </main>
  </div>

  <!-- SCRIPT -->
  <script>
    // JS Jam Digital Real-time
    function updateClock() {
        let s = new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeStyle: 'short', timeZone: 'Asia/Jakarta' }).format(new Date()) + ' WIB';
        const cTop = document.getElementById('clock-top');
        const cSide = document.getElementById('clock-sidebar');
        if (cTop) cTop.textContent = s;
        if (cSide) cSide.textContent = s;
    }
    updateClock();
    setInterval(updateClock, 60000);

    // Filter live client-side untuk pencarian cepat judul / kode laporan
    function filterReportsLive() {
      const keyword = (document.getElementById('searchReport').value || '').toLowerCase();
      const rows = document.querySelectorAll('#tableReportHistory tbody tr');
      let visible = 0;

      rows.forEach(row => {
        if (row.cells.length < 7) return;
        const nameAndCode = row.cells[0].textContent.toLowerCase();
        const typeText    = row.cells[1].textContent.toLowerCase();
        if (nameAndCode.includes(keyword) || typeText.includes(keyword)) {
          row.style.display = '';
          visible++;
        } else {
          row.style.display = 'none';
        }
      });
    }
  </script>

</body>
</html>
