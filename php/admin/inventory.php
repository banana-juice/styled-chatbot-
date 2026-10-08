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
require_once __DIR__ . '/../stock.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();
stock_ensure_schema($pdo);

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    // Release abandoned-payment holds first so the counts shown are real.
    stock_release_stale_holds($pdo);
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
        $where[]  = 'p.name LIKE ?';
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

    $stmt = $pdo->prepare("
        SELECT ps.size_id, ps.size, ps.stock_qty,
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

    // Whole-number, non-negative only. (int) 'abc' is 0 and (int) '-50' is -50,
    // so the old cast silently wiped stock on junk and accepted negatives.
    $qtyRaw = $body['stock_qty'];
    if ((!is_int($qtyRaw) && !(is_string($qtyRaw) && preg_match('/^\d+$/', $qtyRaw)))
        || (int) $qtyRaw < 0 || (int) $qtyRaw > 1000000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'stock_qty must be a whole number from 0 to 1,000,000.']);
        exit;
    }
    $newQty = (int) $qtyRaw;

    $pdo->beginTransaction();
    $row = $pdo->prepare('SELECT product_id, size, stock_qty FROM product_sizes WHERE size_id = ? FOR UPDATE');
    $row->execute([(int) $body['size_id']]);
    $cur = $row->fetch(PDO::FETCH_ASSOC);
    if (!$cur) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Inventory row not found.']);
        exit;
    }
    $pdo->prepare('UPDATE product_sizes SET stock_qty = ? WHERE size_id = ?')->execute([$newQty, (int) $body['size_id']]);
    if ((int) $cur['stock_qty'] !== $newQty) {
        stock_log($pdo, (int) $cur['product_id'], $cur['size'], $newQty - (int) $cur['stock_qty'],
                  (int) $cur['stock_qty'], $newQty, 'admin_adjust', null, $user['user_id'], 'Inventory page edit');
    }
    $pdo->commit();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>