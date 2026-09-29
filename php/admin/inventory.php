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

requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $category = $_GET['category'] ?? '';
    $status   = $_GET['status']   ?? '';
    $search   = $_GET['search']   ?? '';

    $where  = [];
    $params = [];

    if ($category) {
        $where[]  = 'c.name = ?';
        $params[] = $category;
    }
    if ($search) {
        $where[]  = '(p.name LIKE ? OR ps.sku LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    if ($status === 'out') {
        $where[] = 'ps.stock_qty = 0';
    } elseif ($status === 'low') {
        $where[] = 'ps.stock_qty > 0 AND ps.stock_qty <= 5';
    } elseif ($status === 'in') {
        $where[] = 'ps.stock_qty > 5';
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // INCLUDE sku column - it exists in your table!
    $stmt = $pdo->prepare("
        SELECT ps.size_id, ps.size, ps.stock_qty, ps.sku,
               p.product_id, p.name AS product_name,
               c.name AS category
        FROM product_sizes ps
        JOIN products   p ON p.product_id   = ps.product_id
        JOIN categories c ON c.category_id  = p.category_id
        $whereSQL
        ORDER BY p.name ASC, ps.size ASC
    ");
    $stmt->execute($params);

    echo json_encode(['success' => true, 'inventory' => $stmt->fetchAll()]);
    exit;
}

// ── PUT: Update stock ─────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (!isset($body['size_id'], $body['stock_qty'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'size_id and stock_qty are required.']);
        exit;
    }

    $pdo->prepare("UPDATE product_sizes SET stock_qty = ? WHERE size_id = ?")
        ->execute([(int) $body['stock_qty'], (int) $body['size_id']]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>