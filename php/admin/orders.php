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
require_once __DIR__ . '/../email-functions.php';
require_once __DIR__ . '/../order-confirmation-email.php';  
$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

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

    // List with filters + pagination
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
} elseif ($paymentStatus === 'refunded') {
    $where[]  = 'o.payment_status = ?';
    $params[] = 'refunded';
}
// ───────────────────────────────────────────────────────────

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

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
           o.estimated_delivery,
           u.full_name AS customer_name, u.email AS customer_email
    FROM orders o
    LEFT JOIN users u ON u.user_id = o.user_id
    $whereSQL
    ORDER BY o.created_at DESC
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

    $allowedPaymentStatuses = ['unpaid', 'processing', 'paid', 'failed', 'refunded', 'cod'];
    $newPaymentStatus = null;
    if (isset($body['payment_status']) && $body['payment_status'] !== '') {
        $candidate = strtolower(trim($body['payment_status']));
        if (!in_array($candidate, $allowedPaymentStatuses, true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid payment_status.']);
            exit;
        }
        $newPaymentStatus = $candidate;
    }

    // Staff cannot cancel or refund
    if ($user['role'] === 'staff' && in_array($newStatus, ['cancelled', 'refunded'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Staff cannot cancel or refund orders.']);
        exit;
    }
    // Staff also shouldn't be able to grant/revoke "paid" or "refunded" payment
    // status manually — that's a financial action, same tier as cancel/refund.
    if ($user['role'] === 'staff' && in_array($newPaymentStatus, ['paid', 'refunded'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Staff cannot mark orders paid or refunded.']);
        exit;
    }

    // Fetch the current order
    $current = $pdo->prepare('SELECT order_id, status, payment_status, payment_method, user_id FROM orders WHERE order_id = ?');
    $current->execute([$orderId]);
    $currentOrder = $current->fetch(PDO::FETCH_ASSOC);
    if (!$currentOrder) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    $oldStatus = $currentOrder['status'];
    $oldPaymentStatus = $currentOrder['payment_status'];
    $fields = [];
    $params = [];

    if ($newStatus !== null) {
        $fields[] = 'status = ?';
        $params[] = $newStatus;
    }

    $paymentStatusChanged = ($newPaymentStatus !== null && $newPaymentStatus !== $oldPaymentStatus);
    if ($paymentStatusChanged) {
        $fields[] = 'payment_status = ?';
        $params[] = $newPaymentStatus;
        // Only set paid_at the first time an order becomes paid — don't
        // clobber the original payment timestamp on later edits.
        if ($newPaymentStatus === 'paid') {
            $fields[] = 'paid_at = COALESCE(paid_at, NOW())';
        }
    }

    // Even if tracking_number is sent, we ignore it (do not update the database)
    // You can optionally remove the tracking_number field from the frontend entirely,
    // but here we simply skip updating it.

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nothing to update.']);
        exit;
    }

    $params[] = $orderId;
    $pdo->prepare('UPDATE orders SET ' . implode(', ', $fields) . ' WHERE order_id = ?')->execute($params);

    // Insert timeline for status change
    $statusChanged = ($newStatus !== null && $newStatus !== strtolower($oldStatus));
    if ($statusChanged) {
        $statusToLabel = [
            'pending'    => 'Order Placed',
            'processing' => 'Processing',
            'shipped'    => 'Shipped',
            'delivered'  => 'Delivered',
            'cancelled'  => 'Cancelled',
        ];
        $stepLabel = $statusToLabel[$newStatus] ?? ucfirst($newStatus);
        $note = $body['note'] ?? null;
        $pdo->prepare(
            'INSERT INTO order_timeline (order_id, step_label, occurred_at, note)
             VALUES (?, ?, NOW(), ?)'
        )->execute([$orderId, $stepLabel, $note]);
    }

    // Send status-change email only when fulfillment status changes (tracking
    // updates alone do NOT trigger this).
    if ($statusChanged) {
        send_order_status_email($pdo, $orderId, $oldStatus, $newStatus, null);
    }

    // If an admin manually flipped payment_status to "paid" (e.g. the
    // PayMongo webhook didn't arrive, or a bank transfer was confirmed by
    // hand), send the same order-confirmation email the webhook would have
    // sent automatically — same trigger condition as php/paymongo_webhook.php.
    if ($paymentStatusChanged && $newPaymentStatus === 'paid') {
        $userStmt = $pdo->prepare('SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1');
        $userStmt->execute([$currentOrder['user_id']]);
        $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($userRow) {
            $orderStmt = $pdo->prepare('SELECT order_number, subtotal, shipping_fee, grand_total, payment_method FROM orders WHERE order_id = ?');
            $orderStmt->execute([$orderId]);
            $orderRow = $orderStmt->fetch(PDO::FETCH_ASSOC);

            $itemsStmt = $pdo->prepare('SELECT product_id, size, qty, unit_price FROM order_items WHERE order_id = ?');
            $itemsStmt->execute([$orderId]);
            $itemsForEmail = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $addrStmt = $pdo->prepare('
                SELECT a.street, a.city, a.province, a.zip_code
                FROM orders o JOIN addresses a ON a.address_id = o.address_id
                WHERE o.order_id = ?
            ');
            $addrStmt->execute([$orderId]);
            $shippingAddressForEmail = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            try {
                send_order_confirmation(
                    $userRow['email'],
                    explode(' ', $userRow['full_name'])[0] ?? 'Valued Customer',
                    $orderRow['order_number'],
                    $itemsForEmail,
                    $pdo,
                    (float) $orderRow['subtotal'],
                    (float) $orderRow['shipping_fee'],
                    (float) $orderRow['grand_total'],
                    $orderRow['payment_method'],
                    $shippingAddressForEmail
                );
            } catch (Throwable $mailErr) {
                error_log('Admin manual payment-status email failed: ' . $mailErr->getMessage());
            }
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);