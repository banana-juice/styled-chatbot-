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
require_once __DIR__ . '/../paymongo_client.php';
require_once __DIR__ . '/../email-functions.php';
require_once __DIR__ . '/../order-confirmation-email.php';  
$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();
stock_ensure_schema($pdo);

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single order
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("
SELECT o.*,
           o.grand_total AS total_amount,  
           u.full_name  AS customer_name,
           u.email      AS customer_email,
           a.street, a.city, a.province, a.zip_code
    FROM orders o
            LEFT JOIN users     u ON u.user_id    = o.user_id
            LEFT JOIN addresses a ON a.address_id = o.address_id
            WHERE o.order_number = ?
        ");
        $stmt->execute([$_GET['id']]);
        $order = $stmt->fetch();

        if (!$order) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Order not found.']);
            exit;
        }

        // Items
        $items = $pdo->prepare("
            SELECT oi.*, p.name AS product_name, oi.size
            FROM order_items oi
            JOIN products p ON p.product_id = oi.product_id
            WHERE oi.order_id = ?
        ");
        $items->execute([$order['order_id']]);
        $order['items'] = $items->fetchAll();

        // Timeline
        $tl = $pdo->prepare("
            SELECT step_label, occurred_at, note
            FROM order_timeline
            WHERE order_id = ?
            ORDER BY occurred_at ASC, timeline_id ASC
        ");
        $tl->execute([$order['order_id']]);
        $order['timeline'] = $tl->fetchAll();

        echo json_encode(['success' => true, 'order' => $order]);
        exit;
    }

    // Orders whose payment hold ran out are cancelled before we list them,
// so the list never shows an abandoned order as still "awaiting payment".
stock_release_stale_holds($pdo);

// List with filters + pagination
$page   = max(1, (int) ($_GET['page']  ?? 1));
$limit  = min(50, max(1, (int) ($_GET['limit'] ?? 8)));
$offset = ($page - 1) * $limit;
$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';
$paymentStatus = $_GET['payment_status'] ?? '';   // <-- ADDED

$where  = [];
$params = [];

if ($status) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if ($search) {
    $where[]  = '(o.order_number LIKE ? OR u.full_name LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
// ── ADD PAYMENT STATUS FILTER ──────────────────────────────
// Now backed by the real orders.payment_status column (set by
// php/checkout.php and confirmed by php/paymongo_webhook.php),
// instead of guessing from payment_method. COD is bucketed under
// "unpaid" here because cash hasn't actually been collected yet at
// order time — same admin intent as the original filter.
if ($paymentStatus === 'paid') {
    $where[]  = 'o.payment_status = ?';
    $params[] = 'paid';
} elseif ($paymentStatus === 'unpaid') {
    $where[]  = 'o.payment_status IN (?, ?, ?)';
    $params[] = 'unpaid';
    $params[] = 'processing';
    $params[] = 'cod';
} elseif ($paymentStatus === 'failed') {
    $where[]  = 'o.payment_status = ?';
    $params[] = 'failed';
} elseif ($paymentStatus === 'cancelled') {
    $where[]  = 'o.payment_status = ?';
    $params[] = 'cancelled';
} elseif ($paymentStatus === 'refunded') {
    $where[]  = 'o.payment_status = ?';
    $params[] = 'refunded';
}
// ───────────────────────────────────────────────────────────

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// sort=asc  -> oldest first (FIFO queue: the order to work through them in)
// sort=desc -> newest first (default; what the dashboard's "recent orders" wants)
// order_id is the tie-breaker so orders sharing a created_at second keep a
// stable, repeatable position instead of shuffling between page loads.
$dir = (strtolower($_GET['sort'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';

$total = $pdo->prepare("
    SELECT COUNT(*) FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    $whereSQL
");
$total->execute($params);
$totalCount = (int) $total->fetchColumn();

$stmt = $pdo->prepare("
    SELECT o.order_id, o.order_number, o.status, o.payment_method, o.payment_status,
           o.grand_total AS total_amount, o.created_at, o.tracking_number,
           o.estimated_delivery, o.paid_at, o.failed_at, o.cancelled_at, o.updated_at,
           u.full_name AS customer_name, u.email AS customer_email
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    $whereSQL
    ORDER BY o.created_at $dir, o.order_id $dir
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'orders'  => $orders,
    'total'   => $totalCount,
    'page'    => $page,
    'pages'   => (int) ceil($totalCount / $limit),
]);
exit;
}

// ── PUT: Update status (tracking is ignored, no email for tracking) ─────────────
if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if (empty($body['order_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing order_id.']);
        exit;
    }

    $orderId   = (int) $body['order_id'];
    $newStatus = isset($body['status']) ? strtolower(trim($body['status'])) : null;

    // Payment status is NOT editable here. It is set only by the PayMongo
    // webhook (paid / failed) and the customer's own cancel — never by hand,
    // so a "Paid" order always means PayMongo really confirmed the money.
    // (This used to accept payment_status and let any admin mark an order
    // paid, with a "manual override" dropdown in the admin UI.)
    $requestedPaymentStatus = isset($body['payment_status']) ? strtolower(trim((string) $body['payment_status'])) : '';

    $allowedStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];
    if ($newStatus !== null && !in_array($newStatus, $allowedStatuses, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid status.']);
        exit;
    }

    // Staff cannot cancel or refund
    if ($user['role'] === 'staff' && in_array($newStatus, ['cancelled', 'refunded'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Staff cannot cancel or refund orders.']);
        exit;
    }

    // Fetch the current order
    $current = $pdo->prepare('SELECT order_id, status, payment_status, payment_method, payment_reference, user_id FROM orders WHERE order_id = ?');
    $current->execute([$orderId]);
    $currentOrder = $current->fetch(PDO::FETCH_ASSOC);
    if (!$currentOrder) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    $oldStatus = $currentOrder['status'];

    // A page cached from before this change still sends the payment_status it
    // was showing. Echoing the CURRENT value back is harmless and ignored;
    // asking for a different one is refused.
    if ($requestedPaymentStatus !== '' && $requestedPaymentStatus !== strtolower($currentOrder['payment_status'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Payment status is updated automatically by the payment gateway and cannot be changed manually.']);
        exit;
    }

    // A cancelled order has given its stock back, so it can't quietly be
    // moved back into the fulfilment flow.
    if ($oldStatus === 'cancelled' && $newStatus !== null && $newStatus !== 'cancelled') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'A cancelled order cannot be reopened. Ask the customer to place a new order.']);
        exit;
    }

    if ($newStatus === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nothing to update.']);
        exit;
    }

    // Even if tracking_number is sent, we ignore it (do not update the database)

    $statusChanged = ($newStatus !== strtolower($oldStatus));

    if ($newStatus === 'cancelled' && $statusChanged) {
        // Cancelling returns the order's units to stock (exactly once) and
        // hands back any promo-code use; also writes the timeline entry.
        order_cancel_and_release($pdo, $orderId, null, 'admin_cancelled',
            $body['note'] ?? 'Cancelled by ' . $user['role'], (int) $user['user_id']);
        if ($currentOrder['payment_status'] !== 'paid') {
            paymongo_expire_checkout_session_quietly($currentOrder['payment_reference']);
        }
    } elseif ($statusChanged) {
        $pdo->prepare('UPDATE orders SET status = ? WHERE order_id = ?')->execute([$newStatus, $orderId]);

        $statusToLabel = [
            'pending'    => 'Order Placed',
            'processing' => 'Processing',
            'shipped'    => 'Shipped',
            'delivered'  => 'Delivered',
            'refunded'   => 'Refunded',
        ];
        $stepLabel = $statusToLabel[$newStatus] ?? ucfirst($newStatus);
        order_timeline_add($pdo, $orderId, $stepLabel, $body['note'] ?? null);
    }

    // Send status-change email only when fulfillment status changes (tracking
    // updates alone do NOT trigger this).
    if ($statusChanged) {
        send_order_status_email($pdo, $orderId, $oldStatus, $newStatus, null);
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);