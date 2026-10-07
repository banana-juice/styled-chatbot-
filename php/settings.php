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
require_once __DIR__ . '/db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$group = is_string($_GET['group'] ?? null) ? $_GET['group'] : '';
$allowedGroups = ['store', 'payment', 'shipping', 'tax'];
if (!$group || !in_array($group, $allowedGroups)) {
    http_response_code(400);
    echo json_encode(['error' => 'Valid group parameter required (store, payment, shipping, tax)']);
    exit;
}

try {
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `group` = ?');
    $stmt->execute([$group]);
    $rows = $stmt->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key']] = $row['value'];
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}