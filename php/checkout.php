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

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($method !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Helper: read raw JSON body ────────────────────────────────────────────────
function get_json_body(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Helper: generate unique order number (STY-YYYYMMDD-XXXX) ─────────────────
function generate_order_number(PDO $pdo): string {
    $date = date('Ymd');
    do {
        $rand        = str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $order_number = "STY-{$date}-{$rand}";
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE order_number = :on');
        $stmt->execute([':on' => $order_number]);
    } while ($stmt->fetch());
    return $order_number;
}

// ── Email helper (order confirmation) ─────────────────────────────────────────
require_once __DIR__ . '/order-confirmation-email.php';

// ── Main checkout logic ───────────────────────────────────────────────────────
try {
    $pdo  = getPDO();
    $body = get_json_body();

    $items           = $body['items']            ?? [];
    $shipping_address = $body['shipping_address'] ?? [];
    $payment_method  = trim($body['payment_method'] ?? '');
    $promo_code      = trim($body['promo_code']     ?? '');

    if (empty($items) || !is_array($items)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'items array is required and must not be empty']);
        exit;
    }

    $required_address = ['street', 'city', 'province', 'zip_code'];
    foreach ($required_address as $key) {
        if (empty($shipping_address[$key])) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => "shipping_address.$key is required"]);
            exit;
        }
    }

    if ($payment_method === '') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'payment_method is required']);
        exit;
    }

    $allowed_payment_methods = ['cod', 'card', 'gcash'];
    if (!in_array($payment_method, $allowed_payment_methods, true)) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment_method']);
        exit;
    }

    // COD never touches a payment gateway. Card/GCash orders start life
    // as "unpaid" — they only become "paid" once php/paymongo_webhook.php
    // confirms a successful PayMongo payment. This is a real status, not
    // a guess based on the chosen payment method.
    $payment_status = ($payment_method === 'cod') ? 'cod' : 'unpaid';

    $validated_items = [];
    foreach ($items as $item) {
        $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $qty        = isset($item['qty'])        ? (int) $item['qty']        : 0;
        $unit_price = isset($item['unit_price']) ? (float) $item['unit_price'] : 0.0;
        $size       = trim($item['size'] ?? '');

        if ($product_id <= 0 || $qty <= 0 || $unit_price <= 0) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Each item must have valid product_id, qty, and unit_price']);
            exit;
        }

        $validated_items[] = [
            'product_id' => $product_id,
            'size'       => $size,
            'qty'        => $qty,
            'unit_price' => $unit_price,
        ];
    }

    $subtotal = 0.0;
    foreach ($validated_items as $item) {
        $subtotal += $item['unit_price'] * $item['qty'];
    }

    $shipping_fee = $subtotal >= 1000 ? 0.0 : 150.0;

    // ── Promo validation and discount calculation ───────────────────────────
    $promo_id = null;
    $discount_amount = 0.00;

    if ($promo_code !== '') {
        $promo_stmt = $pdo->prepare('
            SELECT promo_id, discount_type, discount_value, min_order, usage_limit, usage_count 
            FROM promotions 
            WHERE code = :code AND is_active = 1 
              AND (expiry_date IS NULL OR expiry_date > NOW())
              AND (usage_limit IS NULL OR usage_count < usage_limit)
            LIMIT 1
        ');
        $promo_stmt->execute([':code' => $promo_code]);
        $promo = $promo_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($promo) {
            if ($subtotal >= ($promo['min_order'] ?? 0)) {
                $promo_id = (int) $promo['promo_id'];
                if ($promo['discount_type'] === 'percent') {
                    $discount_amount = $subtotal * ($promo['discount_value'] / 100);
                } else {
                    $discount_amount = min($promo['discount_value'], $subtotal);
                }
            }
        }
    }

    // ── Final grand total after discount ────────────────────────────────────
    $grand_total = round($subtotal - $discount_amount + $shipping_fee, 2);

    $pdo->beginTransaction();

    // ── Reserve stock ─────────────────────────────────────────────────────
    // Nothing in this codebase decremented product_sizes.stock_qty on
    // checkout, on payment confirmation, or anywhere else — the storefront's
    // "X left" was purely cosmetic and the store could oversell indefinitely.
    // Decrementing here, inside the same transaction as the order itself,
    // means a failed/insufficient-stock item rolls back the whole order
    // (nothing partially created), and the conditional
    // "stock_qty >= qty" in the UPDATE's WHERE clause makes each decrement
    // atomic at the database level — two simultaneous checkouts for the
    // last unit can't both succeed, InnoDB's row lock serializes them and
    // the second one's UPDATE simply matches 0 rows.
    //
    // Items with no matching product_sizes row (no size tracked for that
    // product/size — true for some of the catalog, e.g. most accessories)
    // are left alone rather than blocked, since stock was never modeled
    // for them and blocking would change existing purchasable behavior.
    $stockStmt = $pdo->prepare(
        'UPDATE product_sizes SET stock_qty = stock_qty - :qty
         WHERE product_id = :pid AND size = :size AND stock_qty >= :qty2'
    );
    $stockExistsStmt = $pdo->prepare(
        'SELECT stock_qty FROM product_sizes WHERE product_id = :pid AND size = :size LIMIT 1'
    );
    $nameStmt = $pdo->prepare('SELECT name FROM products WHERE product_id = :pid LIMIT 1');

    foreach ($validated_items as $item) {
        if ($item['size'] === '') {
            continue; // no size-level stock tracked for this item
        }

        $stockExistsStmt->execute([':pid' => $item['product_id'], ':size' => $item['size']]);
        $sizeRow = $stockExistsStmt->fetch(PDO::FETCH_ASSOC);
        if ($sizeRow === false) {
            continue; // not stock-tracked for this product/size — allow as before
        }

        $stockStmt->execute([
            ':qty'   => $item['qty'],
            ':pid'   => $item['product_id'],
            ':size'  => $item['size'],
            ':qty2'  => $item['qty'],
        ]);

        if ($stockStmt->rowCount() === 0) {
            // Someone else's checkout (or a stale cart) already took the
            // remaining stock between the customer adding to cart and
            // checking out now.
            $pdo->rollBack();
            $nameStmt->execute([':pid' => $item['product_id']]);
            $productName = $nameStmt->fetchColumn() ?: 'One of the items';
            ob_end_clean();
            http_response_code(409);
            echo json_encode([
                'error' => "{$productName} (size {$item['size']}) doesn't have enough stock left. Only {$sizeRow['stock_qty']} available.",
                'code'  => 'insufficient_stock',
            ]);
            exit;
        }
    }

    // ── Address handling (insert or reuse) ──────────────────────────────────
    $street   = trim($shipping_address['street']);
    $city     = trim($shipping_address['city']);
    $province = trim($shipping_address['province']);
    $zip_code = trim($shipping_address['zip_code']);

    $addr_check = $pdo->prepare('
        SELECT address_id FROM addresses
        WHERE user_id = :uid
          AND street   = :street
          AND city     = :city
          AND province = :province
          AND zip_code = :zip
        LIMIT 1
    ');
    $addr_check->execute([
        ':uid'      => $user_id,
        ':street'   => $street,
        ':city'     => $city,
        ':province' => $province,
        ':zip'      => $zip_code,
    ]);
    $existing_addr = $addr_check->fetch(PDO::FETCH_ASSOC);

    if ($existing_addr) {
        $address_id = (int) $existing_addr['address_id'];
    } else {
        $ins_addr = $pdo->prepare('
            INSERT INTO addresses (user_id, label, street, city, province, zip_code, is_default)
            VALUES (:uid, :label, :street, :city, :province, :zip, 0)
        ');
        $ins_addr->execute([
            ':uid'      => $user_id,
            ':label'    => 'Shipping',
            ':street'   => $street,
            ':city'     => $city,
            ':province' => $province,
            ':zip'      => $zip_code,
        ]);
        $address_id = (int) $pdo->lastInsertId();
    }

    $order_number = generate_order_number($pdo);

    // ── Insert order ────────────────────────────────────────────────────────
    $ins_order = $pdo->prepare('
        INSERT INTO orders
            (user_id, address_id, order_number, subtotal, shipping_fee,
             discount, grand_total, payment_method, payment_status, promo_id, status)
        VALUES
            (:uid, :addr_id, :order_number, :subtotal, :shipping_fee,
             :discount, :grand_total, :payment_method, :payment_status, :promo_id, "pending")
    ');
    $ins_order->execute([
        ':uid'            => $user_id,
        ':addr_id'        => $address_id,
        ':order_number'   => $order_number,
        ':subtotal'       => round($subtotal, 2),
        ':shipping_fee'   => $shipping_fee,
        ':discount'       => round($discount_amount, 2),
        ':grand_total'    => $grand_total,
        ':payment_method' => $payment_method,
        ':payment_status' => $payment_status,
        ':promo_id'       => $promo_id,
    ]);
    $order_id = (int) $pdo->lastInsertId();

    // ── Increment promo usage count (if promo was applied) ───────────────────
    if ($promo_id) {
        $update_usage = $pdo->prepare('UPDATE promotions SET usage_count = usage_count + 1 WHERE promo_id = :pid');
        $update_usage->execute([':pid' => $promo_id]);
    }

    // ── Insert order items ───────────────────────────────────────────────────
    $ins_item = $pdo->prepare('
        INSERT INTO order_items (order_id, product_id, size, qty, unit_price)
        VALUES (:order_id, :product_id, :size, :qty, :unit_price)
    ');
    foreach ($validated_items as $item) {
        $ins_item->execute([
            ':order_id'   => $order_id,
            ':product_id' => $item['product_id'],
            ':size'       => $item['size'],
            ':qty'        => $item['qty'],
            ':unit_price' => $item['unit_price'],
        ]);
    }

    // ── Clear cart ──────────────────────────────────────────────────────────
    $del_cart = $pdo->prepare('DELETE FROM cart WHERE user_id = :uid');
    $del_cart->execute([':uid' => $user_id]);

    $pdo->commit();

    // ── Send confirmation email ─────────────────────────────────────────────
    // COD: no payment gateway involved, so confirm immediately as before.
    // Card/GCash: the order is NOT confirmed yet — it's "unpaid" until
    // php/paymongo_webhook.php receives a paid event from PayMongo. Sending
    // a "confirmed" email before payment actually clears would be false
    // reassurance, so that email is sent from the webhook handler instead.
    if ($payment_method === 'cod') {
        $user_stmt = $pdo->prepare('SELECT email, full_name FROM users WHERE user_id = :uid LIMIT 1');
        $user_stmt->execute([':uid' => $user_id]);
        $user_row = $user_stmt->fetch(PDO::FETCH_ASSOC);

        if ($user_row) {
            try {
                send_order_confirmation(
                    $user_row['email'],
                    explode(' ', $user_row['full_name'])[0] ?? 'Valued Customer',
                    $order_number,
                    $validated_items,
                    $pdo,
                    $subtotal,
                    $shipping_fee,
                    $grand_total,
                    $payment_method,
                    $shipping_address
                );
            } catch (Throwable $mailErr) {
                error_log('Order confirmation email failed: ' . $mailErr->getMessage());
            }
        }
    }

    ob_end_clean();
    echo json_encode([
        'success'        => true,
        'order_id'       => $order_id,
        'order_number'   => $order_number,
        'grand_total'    => $grand_total,
        'payment_method' => $payment_method,
        'payment_status' => $payment_status,
        'requires_payment' => $payment_method !== 'cod',
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}