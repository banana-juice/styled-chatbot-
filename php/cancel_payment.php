<?php
// ============================================================
// CANCEL PAYMENT — php/cancel_payment.php
//
// Called by checkout.js when PayMongo sends the customer back to the
// cancel_url (they backed out of the hosted payment page). The order is
// cancelled and the units it was holding go straight back on sale, instead
// of staying locked away from other customers until someone notices.
//
// Only the order's own customer may cancel it, only while it is still
// unpaid, and calling it twice is harmless.
// ============================================================

header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/paymongo_client.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}
$user_id = (int) $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body        = json_decode(file_get_contents('php://input'), true) ?? [];
$orderNumber = trim((string) ($body['order_number'] ?? ''));
if ($orderNumber === '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'order_number is required']);
    exit;
}

try {
    $pdo = getPDO();
    stock_ensure_schema($pdo);

    $stmt = $pdo->prepare(
        'SELECT order_id, payment_method, payment_status, status, payment_reference
         FROM orders WHERE order_number = :n AND user_id = :uid LIMIT 1'
    );
    $stmt->execute([':n' => $orderNumber, ':uid' => $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }
    if ($order['payment_method'] === 'cod' || $order['payment_status'] === 'paid') {
        ob_end_clean();
        http_response_code(409);
        echo json_encode(['error' => 'This order can no longer be cancelled here.']);
        exit;
    }

    $did = order_cancel_and_release($pdo, (int) $order['order_id'], 'cancelled', 'customer_cancelled',
        'Payment was cancelled by the customer.', $user_id);
    if ($did) {
        paymongo_expire_checkout_session_quietly($order['payment_reference']);
    }

    ob_end_clean();
    echo json_encode(['success' => true, 'cancelled' => $did || $order['status'] === 'cancelled']);
} catch (Throwable $e) {
    error_log('cancel_payment.php: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
