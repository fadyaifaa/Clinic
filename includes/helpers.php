<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fetchCount(mysqli $db, string $sql, int $clinicId): int {
    $s=$db->prepare($sql); $s->bind_param('i',$clinicId); $s->execute();
    return (int)($s->get_result()->fetch_row()[0] ?? 0);
}
function requireAdmin(): void {
    if (empty($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'admin' || empty($_SESSION['clinic_id'])) {
        header('Location: ../login.php'); exit;
    }
}
