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
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

$pdo = getPDO();
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

function formatPrice($num) {
    return '₱' . number_format((float)$num, 2);
}

// Single order
if (!empty($_GET['id'])) {
    $order_number = trim($_GET['id']);
    $stmt = $pdo->prepare("
        SELECT o.order_id, o.order_number, o.status, o.payment_method,
               o.payment_status,
               o.grand_total AS total, o.created_at, o.tracking_number,
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
        'cod'        => 'Pay on Delivery',
    ];
    $paymentStatusDisplay = $paymentStatusMap[$order['payment_status']] ?? ucfirst($order['payment_status']);

    $shippingFee = max(0, $order['total'] - $subtotal);
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
            'done' => true,
            'active' => false,
        ];
    }

    $orderData = [
        'order_id' => $order['order_id'],
        'id' => $order['order_number'],
        'date' => date('M d, Y', strtotime($order['created_at'])),
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
    SELECT order_id, order_number, status, payment_method, payment_status, grand_total AS total, created_at
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
        'total' => formatPrice($row['total']),
        'status' => ucfirst($row['status']),
        'payment_method' => $row['payment_method'],
        'payment_status' => $row['payment_status'],
        'items' => $firstItem ? [$firstItem] : [],
    ];
}

echo json_encode(['success' => true, 'orders' => $orders]);