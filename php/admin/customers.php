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

    // Single customer
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT user_id, full_name, email, created_at, admin_notes
            FROM users
            WHERE user_id = ? AND role = 'customer'
        ");
        $stmt->execute([as_pos_int($_GET['id'])]);
        $customer = $stmt->fetch();

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Customer not found.']);
            exit;
        }

        // Order history
        $orders = $pdo->prepare("
            SELECT order_number, status, grand_total AS total_amount, created_at
            FROM orders
            WHERE user_id = ?
            ORDER BY created_at DESC
        ");
        $orders->execute([$customer['user_id']]);
        $customer['orders'] = $orders->fetchAll();

        // Stats - FIXED: changed total_amount to grand_total
        $stats = $pdo->prepare("
            SELECT COUNT(*) AS order_count,
                   COALESCE(SUM(grand_total), 0) AS total_spent
            FROM orders
            WHERE user_id = ? AND status NOT IN ('cancelled', 'refunded')
        ");
        $stats->execute([$customer['user_id']]);
        $s = $stats->fetch();
        $customer['order_count']  = (int)   $s['order_count'];
        $customer['total_spent']  = (float) $s['total_spent'];

// Fetch default address
$addr = $pdo->prepare("
    SELECT street, city, province, zip_code
    FROM addresses
    WHERE user_id = ? AND is_default = 1
    LIMIT 1
");
$addr->execute([$customer['user_id']]);
$customer['address'] = $addr->fetch();

        echo json_encode(['success' => true, 'customer' => $customer]);
        exit;
    }

    // List
    $page   = max(1, as_pos_int($_GET['page'] ?? 1) ?: 1);
    $limit  = min(50, max(1, as_pos_int($_GET['limit'] ?? 8) ?: 8));
    $offset = ($page - 1) * $limit;
    $search = as_text($_GET['search'] ?? '');

    $where  = ["u.role = 'customer'"];
    $params = [];

    if ($search) {
        $where[]  = '(u.full_name LIKE ? OR u.email LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $where);

    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM users u $whereSQL");
    $totalStmt->execute($params);
    $totalCount = (int) $totalStmt->fetchColumn();

    // FIXED: changed total_amount to grand_total
    $stmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.email, u.created_at, u.admin_notes,
               COUNT(o.order_id)                               AS order_count,
               COALESCE(SUM(o.grand_total), 0)               AS total_spent
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.user_id
            AND o.status NOT IN ('cancelled','refunded')
        $whereSQL
        GROUP BY u.user_id
        ORDER BY total_spent DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);

    echo json_encode([
        'success'   => true,
        'customers' => $stmt->fetchAll(),
        'total'     => $totalCount,
        'page'      => $page,
        'pages'     => (int) ceil($totalCount / $limit),
    ]);
    exit;
}

// ── PUT: Add/update admin notes ───────────────────────────────────────────────
if ($method === 'PUT') {
    $id = as_pos_int($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing customer id.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    reject_nested_json($body);

    if (!isset($body['admin_notes']) || !is_string($body['admin_notes']) || mb_strlen($body['admin_notes']) > 5000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'admin_notes (text, at most 5000 characters) is required.']);
        exit;
    }

    $pdo->prepare("UPDATE users SET admin_notes = ? WHERE user_id = ? AND role = 'customer'")
        ->execute([$body['admin_notes'], $id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>