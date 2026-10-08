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

ini_set('display_errors', 0);
error_reporting(E_ALL);
require_once __DIR__ . '/session_boot.php';
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/payments.php';

$pdo = getPDO();
stock_ensure_schema($pdo);
stock_release_stale_holds($pdo);
$method = $_SERVER['REQUEST_METHOD'];

// Auth check
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}
$user_id = (int) $_SESSION['user_id'];

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Bring this customer's still-unpaid online orders up to date with PayMongo
// before we show them, so a payment that just went through shows as Paid right
// away instead of waiting for the webhook. Only their own orders, only those
// waiting on payment, at most a few per request.
{
    $sql = "SELECT order_id FROM orders
            WHERE user_id = ? AND payment_method <> 'cod' AND payment_status IN ('unpaid', 'processing')";
    $args = [$user_id];
    if (!empty($_GET['id'])) {
        $sql .= ' AND order_number = ?';
        $args[] = trim(is_string($_GET['id']) ? $_GET['id'] : '');
    }
    $w = $pdo->prepare($sql . ' ORDER BY order_id DESC LIMIT 5');
    $w->execute($args);
    payment_reconcile_orders($pdo, $w->fetchAll(PDO::FETCH_COLUMN), 5);
}

function formatPrice($num) {
    return '₱' . number_format((float)$num, 2);
}

// Timestamps are written by the server in Asia/Manila (see db.php). Send
// both a human string with the time of day and an ISO-8601 string with the
// explicit +08:00 offset, so the browser never has to guess a timezone.
function fmtDateTime($ts) {
    return $ts ? date('M d, Y g:i A', strtotime($ts)) : null;
}
function isoDateTime($ts) {
    return $ts ? date('c', strtotime($ts)) : null;
}

// Single order
if (!empty($_GET['id'])) {
    $order_number = trim(is_string($_GET['id']) ? $_GET['id'] : '');
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.status, o.payment_method,
               o.payment_status,
               o.grand_total AS total, o.created_at, o.tracking_number,
               o.paid_at, o.failed_at, o.cancelled_at, o.updated_at,
               o.shipping_fee, o.discount,
               a.street, a.city, a.province, a.zip_code
        FROM orders o
        LEFT JOIN addresses a ON a.address_id = o.address_id
        WHERE o.order_number = ? AND o.user_id = ?
    ");
    $stmt->execute([$order_number, $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Order not found.']);
        exit;
    }

    // Items
    $itemsStmt = $pdo->prepare("
        SELECT oi.product_id, oi.qty, oi.unit_price, oi.size,
               COALESCE(p.name, 'Product') AS product_name,
               COALESCE(
                   (SELECT pi.image_url FROM product_images pi
                    WHERE pi.product_id = p.product_id AND pi.is_primary = 1 LIMIT 1),
                   ''
               ) AS img
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $itemsStmt->execute([$order['order_id']]);
    $items = $itemsStmt->fetchAll();

    $subtotal = 0;
    foreach ($items as &$item) {
        $item['price'] = formatPrice($item['unit_price']);
        $subtotal += $item['unit_price'] * $item['qty'];
    }

    // Payment method display
    $paymentMap = [
        'cod' => 'Cash on Delivery',
        'gcash' => 'GCash',
        'card' => 'Credit/Debit Card',
        'bank_transfer' => 'Bank Transfer'
    ];
    $paymentDisplay = $paymentMap[strtolower($order['payment_method'])] ?? ucfirst($order['payment_method']);
    if (empty($paymentDisplay)) $paymentDisplay = '—';

    $paymentStatusMap = [
        'unpaid'     => 'Awaiting Payment',
        'processing' => 'Payment Processing',
        'paid'       => 'Paid',
        'failed'     => 'Payment Failed',
        'refunded'   => 'Refunded',
        'cancelled'  => 'Payment Cancelled',
        'cod'        => 'Pay on Delivery',
    ];
    $paymentStatusDisplay = $paymentStatusMap[$order['payment_status']] ?? ucfirst($order['payment_status']);

    // Real figures from the order itself. This used to be total - subtotal,
    // which folded VAT (and any discount) into the "Shipping" line.
    $shippingFee = (float) $order['shipping_fee'];
    $discount    = (float) $order['discount'];
    $tax         = max(0, round((float) $order['total'] - $subtotal + $discount - $shippingFee, 2));
    $address = implode(', ', array_filter([$order['street'], $order['city'], $order['province'], $order['zip_code']]));

    // Timeline
    $timelineStmt = $pdo->prepare("
        SELECT step_label, occurred_at, note
        FROM order_timeline
        WHERE order_id = ?
        ORDER BY occurred_at ASC
    ");
    $timelineStmt->execute([$order['order_id']]);
    $timeline = $timelineStmt->fetchAll();
    $steps = [];
    foreach ($timeline as $t) {
        $steps[] = [
            'label' => $t['step_label'],
            'date' => date('M d, Y', strtotime($t['occurred_at'])),
            'datetime' => fmtDateTime($t['occurred_at']),
            'datetime_iso' => isoDateTime($t['occurred_at']),
            'done' => true,
            'active' => false,
        ];
    }

    $canRetry = $order['payment_method'] !== 'cod'
        && in_array($order['payment_status'], ['unpaid', 'processing', 'failed', 'cancelled'], true)
        && ($order['status'] !== 'cancelled' || order_can_reopen_for_payment($pdo, (int) $order['order_id']));

    $orderData = [
        'order_id' => $order['order_id'],
        'can_retry_payment' => $canRetry,
        'id' => $order['order_number'],
        'date' => date('M d, Y', strtotime($order['created_at'])),
        'date_time' => fmtDateTime($order['created_at']),
        'created_at_iso' => isoDateTime($order['created_at']),
        'paid_at' => fmtDateTime($order['paid_at']),
        'paid_at_iso' => isoDateTime($order['paid_at']),
        'failed_at' => fmtDateTime($order['failed_at']),
        'failed_at_iso' => isoDateTime($order['failed_at']),
        'cancelled_at' => fmtDateTime($order['cancelled_at']),
        'cancelled_at_iso' => isoDateTime($order['cancelled_at']),
        'updated_at' => fmtDateTime($order['updated_at']),
        'total' => formatPrice($order['total']),
        'totalNum' => (float) $order['total'],
        'status' => ucfirst($order['status']),
        'payment' => $paymentDisplay,
        'payment_status' => $order['payment_status'],
        'payment_status_display' => $paymentStatusDisplay,
        'items' => $items,
        'shipping' => [
            'address' => $address ?: '—',
            'cost' => $shippingFee,
            'cost_display' => $shippingFee == 0 ? 'FREE' : formatPrice($shippingFee)
        ],
        'discount_display' => $discount > 0 ? '−' . formatPrice($discount) : null,
        'tax_display' => $tax > 0 ? formatPrice($tax) : null,
        'subtotal' => formatPrice($subtotal),
        'subtotalNum' => $subtotal,
        'tracking' => [
            'steps' => $steps,
            'tracking_number' => $order['tracking_number'] ?? null,
            'estimated_delivery' => null,
        ]
    ];

    echo json_encode(['success' => true, 'order' => $orderData]);
    exit;
}

// List orders
$stmt = $pdo->prepare("
    SELECT order_id, order_number, status, payment_method, payment_status, grand_total AS total, created_at,
           paid_at, failed_at, cancelled_at
    FROM orders
    WHERE user_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$user_id]);
$rows = $stmt->fetchAll();

$orders = [];
foreach ($rows as $row) {
    $imgStmt = $pdo->prepare("
        SELECT COALESCE(p.name, 'Product') AS name,
               COALESCE(
                   (SELECT pi.image_url FROM product_images pi
                    WHERE pi.product_id = oi.product_id AND pi.is_primary = 1 LIMIT 1),
                   ''
               ) AS img
        FROM order_items oi
        LEFT JOIN products p ON p.product_id = oi.product_id
        WHERE oi.order_id = ?
        LIMIT 1
    ");
    $imgStmt->execute([$row['order_id']]);
    $firstItem = $imgStmt->fetch();

    $orders[] = [
        'order_id' => $row['order_id'],
        'id' => $row['order_number'],
        'date' => date('M d, Y', strtotime($row['created_at'])),
        'date_time' => fmtDateTime($row['created_at']),
        'created_at_iso' => isoDateTime($row['created_at']),
        'paid_at' => fmtDateTime($row['paid_at']),
        'cancelled_at' => fmtDateTime($row['cancelled_at']),
        'failed_at' => fmtDateTime($row['failed_at']),
        'total' => formatPrice($row['total']),
        'status' => ucfirst($row['status']),
        'payment_method' => $row['payment_method'],
        'payment_status' => $row['payment_status'],
        'items' => $firstItem ? [$firstItem] : [],
    ];
}

echo json_encode(['success' => true, 'orders' => $orders]);