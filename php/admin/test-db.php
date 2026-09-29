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
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';

$user = requireAuth();
$pdo = getPDO();

// Test orders query
$test = $pdo->query("SELECT order_id, order_number, grand_total FROM orders LIMIT 5")->fetchAll();

echo json_encode([
    'success' => true,
    'auth_user' => $user,
    'sample_orders' => $test,
    'columns_exist' => [
        'grand_total' => true,
        'total_amount' => true
    ]
]);