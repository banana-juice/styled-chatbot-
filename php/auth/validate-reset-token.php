<?php
// CORS headers
header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['valid' => false]);
    exit;
}

require_once __DIR__ . '/../db.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    echo json_encode(['valid' => false]);
    exit;
}

$pdo  = getPDO();
$stmt = $pdo->prepare(
    'SELECT reset_id FROM password_resets
     WHERE token = ?
       AND expires_at > NOW()
       AND used = 0
     LIMIT 1'
);
$stmt->execute([$token]);
$row = $stmt->fetch();

echo json_encode(['valid' => (bool) $row]);