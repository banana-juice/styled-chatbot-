<?php
// ============================================================
// CREATE PAYMENT — called by checkout.js right after php/checkout.php
// has created an order with payment_method = card|gcash.
//
// STYLED Checkout ──▶ this endpoint ──▶ PayMongo API (create checkout
// session) ──▶ returns a hosted checkout_url ──▶ browser redirects there.
//
// This endpoint never sees card numbers — PayMongo's hosted page
// collects them directly, so no sensitive payment data ever reaches
// STYLED's server or database.
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

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/paymongo_client.php';

header('Content-Type: application/json');

// ── Auth guard ───────────────────────────────────────────────────────────
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

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$order_id = isset($body['order_id']) ? as_pos_int($body['order_id']) : 0;

if ($order_id <= 0) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'order_id is required']);
    exit;
}

try {
    $pdo = getPDO();
    stock_ensure_schema($pdo);
    stock_release_stale_holds($pdo);

    // Order must belong to the logged-in user and must actually need
    // online payment (never let this be called for a COD order).
    $stmt = $pdo->prepare('
        SELECT o.order_id, o.order_number, o.grand_total, o.payment_method,
               o.payment_status, o.status, o.payment_reference, u.email, u.full_name
        FROM orders o
        JOIN users u ON u.user_id = o.user_id
        WHERE o.order_id = :oid AND o.user_id = :uid
        LIMIT 1
    ');
    $stmt->execute([':oid' => $order_id, ':uid' => $user_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['error' => 'Order not found']);
        exit;
    }

    if ($order['payment_method'] === 'cod') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'This order is Cash on Delivery and does not need online payment']);
        exit;
    }

    // Allow retry if a previous attempt failed or was cancelled, but not
    // if it's already paid or currently mid-flow with a live session.
    if ($order['payment_status'] === 'paid') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'This order has already been paid']);
        exit;
    }

    // A cancelled order (customer cancelled, or the stock hold expired) has
    // already given its items back to the shelf, so it can't be paid any more.
    if ($order['status'] === 'cancelled') {
        // A payment-side cancel (hold expired / session expired / customer
        // backed out) can be retried if its items can be taken off the shelf
        // again. A cancel made by staff stays cancelled.
        if ($order['payment_status'] === 'paid' || !order_can_reopen_for_payment($pdo, (int) $order['order_id'])) {
            ob_end_clean();
            http_response_code(409);
            echo json_encode(['error' => 'This order was cancelled. Please place a new order.', 'code' => 'order_cancelled']);
            exit;
        }
        $pdo->beginTransaction();
        $locked = $pdo->prepare('SELECT status FROM orders WHERE order_id = ? FOR UPDATE');
        $locked->execute([$order['order_id']]);
        if ($locked->fetchColumn() === 'cancelled') {
            if (!stock_rereserve_order($pdo, (int) $order['order_id'], 'payment retried')) {
                $pdo->rollBack();
                ob_end_clean();
                http_response_code(409);
                echo json_encode(['error' => 'Sorry, some items in this order are no longer in stock. Please place a new order.', 'code' => 'insufficient_stock']);
                exit;
            }
            $pdo->prepare("UPDATE orders SET status = 'pending', payment_status = 'unpaid', cancelled_at = NULL, failed_at = NULL, payment_reference = NULL WHERE order_id = ?")
                ->execute([$order['order_id']]);
            order_timeline_add($pdo, (int) $order['order_id'], 'Order Placed', 'Reopened for payment; items reserved again.');
            $order['payment_reference'] = null;
        }
        $pdo->commit();
    }

    // Retrying (page refresh, double-click, back button) must not spawn a
    // second PayMongo session for the same order: reuse the live one.
    if (!empty($order['payment_reference'])) {
        try {
            $existing = paymongo_retrieve_checkout_session($order['payment_reference']);
            $exAttrs  = $existing['data']['attributes'] ?? [];
            if (($exAttrs['status'] ?? '') === 'active' && !empty($exAttrs['checkout_url'])) {
                ob_end_clean();
                echo json_encode(['success' => true, 'checkout_url' => $exAttrs['checkout_url'], 'reused' => true]);
                exit;
            }
        } catch (PaymongoException $e) {
            // couldn't look the old session up; fall through and create a new one
        }
    }

    // Map STYLED's payment_method choice to the PayMongo methods to
    // present on the hosted checkout page.
    $methodMap = [
        'card'  => ['card'],
        'gcash' => ['gcash', 'paymaya'],
    ];
    $paymentMethodTypes = $methodMap[$order['payment_method']] ?? ['card', 'gcash', 'paymaya'];

    $successUrl = SITE_URL . '/orders.html?payment=success&order=' . urlencode($order['order_number']);
    $cancelUrl  = SITE_URL . '/checkout.html?payment=cancelled&order=' . urlencode($order['order_number']);

    $session = paymongo_create_checkout_session(
        'Styled order ' . $order['order_number'],
        (float) $order['grand_total'],
        $order['order_number'],
        $successUrl,
        $cancelUrl,
        $paymentMethodTypes,
        $order['full_name'] ?: 'Styled Customer',
        $order['email']
    );

    $sessionId  = $session['data']['id'] ?? null;
    $checkoutUrl = $session['data']['attributes']['checkout_url'] ?? null;

    if (!$sessionId || !$checkoutUrl) {
        throw new PaymongoException('PayMongo did not return a checkout session');
    }

    // Persist the session so the webhook can find this order later, and
    // record the attempt in the audit table.
    $pdo->prepare('UPDATE orders SET payment_status = "processing", payment_reference = :ref WHERE order_id = :oid')
        ->execute([':ref' => $sessionId, ':oid' => $order_id]);

    $pdo->prepare('
        INSERT INTO payment_transactions (order_id, provider, checkout_session_id, amount, currency, status)
        VALUES (:oid, "paymongo", :sid, :amount, "PHP", "awaiting_payment")
    ')->execute([
        ':oid'    => $order_id,
        ':sid'    => $sessionId,
        ':amount' => $order['grand_total'],
    ]);

    ob_end_clean();
    echo json_encode([
        'success'      => true,
        'checkout_url' => $checkoutUrl,
    ]);

} catch (PaymongoException $e) {
    error_log('PayMongo create_payment error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(502);
    echo json_encode(['error' => 'Could not start payment. Please try again.', 'detail' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('create_payment.php error: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
}
