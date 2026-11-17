<?php
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json; charset=utf-8');

$parent = trim($_GET['parent_id'] ?? '');
$type = trim($_GET['type'] ?? '');
$allowed = ['province', 'city', 'district', 'village'];

if (!in_array($type, $allowed, true)) {
    echo json_encode([]);
    exit;
}

// 1. Cek dari database lokal terlebih dahulu jika ada
if ($type === 'province') {
    $s = $conn->query("SELECT id, name, region_code FROM regions WHERE region_type='province' ORDER BY name");
    $dbData = $s ? $s->fetch_all(MYSQLI_ASSOC) : [];
    if (!empty($dbData)) {
        echo json_encode($dbData);
        exit;
    }
} elseif (!empty($parent)) {
    $s = $conn->prepare("SELECT id, name, region_code FROM regions WHERE (parent_id = ? OR region_code = ?) AND region_type = ? ORDER BY name");
    $s->bind_param('sss', $parent, $parent, $type);
    $s->execute();
    $dbData = $s->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!empty($dbData)) {
        echo json_encode($dbData);
        exit;
    }
}

// 2. Jika di database belum ada, ambil dari API publik Emsifa
$url = '';
if ($type === 'province') {
    $url = 'https://www.emsifa.com/api-wilayah-indonesia/api/provinces.json';
} elseif ($type === 'city' && !empty($parent)) {
    $url = 'https://www.emsifa.com/api-wilayah-indonesia/api/regencies/' . urlencode($parent) . '.json';
} elseif ($type === 'district' && !empty($parent)) {
    $url = 'https://www.emsifa.com/api-wilayah-indonesia/api/districts/' . urlencode($parent) . '.json';
} elseif ($type === 'village' && !empty($parent)) {
    $url = 'https://www.emsifa.com/api-wilayah-indonesia/api/villages/' . urlencode($parent) . '.json';
}

if ($url) {
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 5,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    $res = @file_get_contents($url, false, $ctx);
    if ($res) {
        echo $res;
        exit;
    }
}

// 3. Fallback jika offline untuk daftar provinsi
if ($type === 'province') {
    $fallbackProvinces = [
        ['id' => '11', 'name' => 'ACEH'],
        ['id' => '12', 'name' => 'SUMATERA UTARA'],
        ['id' => '13', 'name' => 'SUMATERA BARAT'],
        ['id' => '14', 'name' => 'RIAU'],
        ['id' => '15', 'name' => 'JAMBI'],
        ['id' => '16', 'name' => 'SUMATERA SELATAN'],
        ['id' => '17', 'name' => 'BENGKULU'],
        ['id' => '18', 'name' => 'LAMPUNG'],
        ['id' => '19', 'name' => 'KEPULAUAN BANGKA BELITUNG'],
        ['id' => '21', 'name' => 'KEPULAUAN RIAU'],
        ['id' => '31', 'name' => 'DKI JAKARTA'],
        ['id' => '32', 'name' => 'JAWA BARAT'],
        ['id' => '33', 'name' => 'JAWA TENGAH'],
        ['id' => '34', 'name' => 'DAERAH ISTIMEWA YOGYAKARTA'],
        ['id' => '35', 'name' => 'JAWA TIMUR'],
        ['id' => '36', 'name' => 'BANTEN'],
        ['id' => '51', 'name' => 'BALI'],
        ['id' => '52', 'name' => 'NUSA TENGGARA BARAT'],
        ['id' => '53', 'name' => 'NUSA TENGGARA TIMUR'],
        ['id' => '61', 'name' => 'KALIMANTAN BARAT'],
        ['id' => '62', 'name' => 'KALIMANTAN TENGAH'],
        ['id' => '63', 'name' => 'KALIMANTAN SELATAN'],
        ['id' => '64', 'name' => 'KALIMANTAN TIMUR'],
        ['id' => '65', 'name' => 'KALIMANTAN UTARA'],
        ['id' => '71', 'name' => 'SULAWESI UTARA'],
        ['id' => '72', 'name' => 'SULAWESI TENGAH'],
        ['id' => '73', 'name' => 'SULAWESI SELATAN'],
        ['id' => '74', 'name' => 'SULAWESI TENGGARA'],
        ['id' => '75', 'name' => 'GORONTALO'],
        ['id' => '76', 'name' => 'SULAWESI BARAT'],
        ['id' => '81', 'name' => 'MALUKU'],
        ['id' => '82', 'name' => 'MALUKU UTARA'],
        ['id' => '91', 'name' => 'PAPUA BARAT'],
        ['id' => '92', 'name' => 'PAPUA']
    ];
    echo json_encode($fallbackProvinces);
    exit;
}

echo json_encode([]);
