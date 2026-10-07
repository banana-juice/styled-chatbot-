<?php
// ============================================
// STOCK HISTORY — php/admin/stock-log.php
// Read-only audit trail of every change to product_sizes.stock_qty:
// what changed, by how much, why (order reserve/release, admin edit),
// which order or admin caused it, and when (Asia/Manila).
//
//   GET ?product_id=&order_id=&page=&limit=
// ============================================

header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json');
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../stock.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getPDO();
stock_ensure_schema($pdo);

$where  = [];
$params = [];
if (!empty($_GET['product_id'])) {
    $where[]  = 'a.product_id = ?';
    $params[] = (int) $_GET['product_id'];
}
if (!empty($_GET['order_id'])) {
    $where[]  = 'a.order_id = ?';
    $params[] = (int) $_GET['order_id'];
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$page   = max(1, (int) ($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int) ($_GET['limit'] ?? 25)));
$offset = ($page - 1) * $limit;

$count = $pdo->prepare("SELECT COUNT(*) FROM stock_adjustments a $whereSQL");
$count->execute($params);
$total = (int) $count->fetchColumn();

$stmt = $pdo->prepare("
    SELECT a.adjustment_id, a.created_at, a.product_id, p.name AS product_name, a.size,
           a.delta, a.qty_before, a.qty_after, a.reason, a.note,
           a.order_id, o.order_number, a.user_id, u.full_name AS changed_by
    FROM stock_adjustments a
    LEFT JOIN products p ON p.product_id = a.product_id
    LEFT JOIN orders   o ON o.order_id   = a.order_id
    LEFT JOIN users    u ON u.user_id    = a.user_id
    $whereSQL
    ORDER BY a.created_at DESC, a.adjustment_id DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);

echo json_encode([
    'success' => true,
    'log'     => $stmt->fetchAll(PDO::FETCH_ASSOC),
    'total'   => $total,
    'page'    => $page,
    'pages'   => (int) ceil($total / $limit),
]);
