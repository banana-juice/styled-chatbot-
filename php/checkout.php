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
require_once __DIR__ . '/stock.php';

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
    stock_ensure_schema($pdo);
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

    // ── Validate items and price them from the catalog ─────────────────────
    // The client's `unit_price` is deliberately ignored: it is whatever the
    // browser says it is, so trusting it let anyone buy a ₱299 item for ₱1
    // (and, for card/GCash, have PayMongo charge that fake total). Every
    // price below comes from products.price, and every size is resolved
    // against the size rows that actually carry stock.
    $valid_sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
    $prodStmt  = $pdo->prepare(
        "SELECT product_id, name, price FROM products
         WHERE product_id = :pid AND status = 'active' AND is_active = 1 LIMIT 1"
    );
    $sizesStmt = $pdo->prepare('SELECT size FROM product_sizes WHERE product_id = :pid');

    $merged = []; // product_id|size => line, so duplicate lines are validated as one
    foreach ($items as $item) {
        if (!is_array($item)) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid item in cart']);
            exit;
        }
        $rawQty = $item['qty'] ?? null;
        if (!is_int($rawQty) && !(is_string($rawQty) && ctype_digit($rawQty))) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Each item must have a whole-number quantity']);
            exit;
        }
        $product_id = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $qty        = (int) $rawQty;
        $size       = strtoupper(trim((string) ($item['size'] ?? '')));

        if ($product_id <= 0 || $qty < 1 || $qty > 99) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Each item must have a valid product_id and a quantity between 1 and 99']);
            exit;
        }

        $prodStmt->execute([':pid' => $product_id]);
        $product = $prodStmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            ob_end_clean();
            http_response_code(409);
            echo json_encode(['error' => 'One of the items in your cart is no longer available.', 'code' => 'product_unavailable']);
            exit;
        }

        $sizesStmt->execute([':pid' => $product_id]);
        $productSizes = $sizesStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$productSizes) {
            // No stock row at all means no stock (the storefront already
            // shows these as 0). It must not mean "unlimited".
            ob_end_clean();
            http_response_code(409);
            echo json_encode([
                'error' => "{$product['name']} is out of stock.",
                'code'  => 'insufficient_stock',
            ]);
            exit;
        }
        if ($size === '') {
            if (count($productSizes) === 1) {
                $size = $productSizes[0]; // single-variant item (most accessories)
            } else {
                ob_end_clean();
                http_response_code(400);
                echo json_encode(['error' => "Please choose a size for {$product['name']}.", 'code' => 'size_required']);
                exit;
            }
        }
        if (!in_array($size, $valid_sizes, true) || !in_array($size, $productSizes, true)) {
            ob_end_clean();
            http_response_code(409);
            echo json_encode([
                'error' => "{$product['name']} isn't available in size {$size}.",
                'code'  => 'size_unavailable',
            ]);
            exit;
        }

        $key = $product_id . '|' . $size;
        if (isset($merged[$key])) {
            $merged[$key]['qty'] += $qty;
        } else {
            $merged[$key] = [
                'product_id' => $product_id,
                'name'       => $product['name'],
                'size'       => $size,
                'qty'        => $qty,
                'unit_price' => round((float) $product['price'], 2),
            ];
        }
    }
    // Fixed lock order (product, size) so two multi-item checkouts that
    // overlap can't deadlock each other.
    ksort($merged, SORT_NATURAL);
    $validated_items = array_values($merged);
    foreach ($validated_items as $line) {
        if ($line['qty'] > 99 || $line['unit_price'] <= 0) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Invalid quantity or price for ' . $line['name']]);
            exit;
        }
    }

    $subtotal = 0.0;
    foreach ($validated_items as $item) {
        $subtotal += $item['unit_price'] * $item['qty'];
    }

    // ── Shipping + tax, read from the same settings the frontend uses ───────
    // This used to be hardcoded here (free over ₱1000, else a flat ₱150,
    // no tax at all) while js/checkout.js's updateTotals() correctly read
    // the real settings (free over ₱500, ₱100 flat, +12% VAT) to compute
    // what it showed the customer on the confirmation screen. The two
    // never matched — verified live: a ₱310 order displayed as ₱447.20
    // but saved to the database as ₱460.00. Reading the same settings
    // here, with the same formula as updateTotals(), keeps what gets
    // charged equal to what the customer actually saw and approved.
    $settingsStmt = $pdo->query(
        "SELECT `group`, `key`, value FROM settings
         WHERE (`group`, `key`) IN (
            ('shipping', 'shipping-free-threshold'), ('shipping', 'shipping-standard-fee'),
            ('tax', 'tax-vat-rate')
         )"
    );
    $settingsMap = [];
    foreach ($settingsStmt->fetchAll() as $row) {
        $settingsMap[$row['group'] . '.' . $row['key']] = $row['value'];
    }
    $freeShippingThreshold = isset($settingsMap['shipping.shipping-free-threshold'])
        ? (float) $settingsMap['shipping.shipping-free-threshold'] : 1000.0;
    $standardShippingFee = isset($settingsMap['shipping.shipping-standard-fee'])
        ? (float) $settingsMap['shipping.shipping-standard-fee'] : 150.0;
    $taxRatePercent = isset($settingsMap['tax.tax-vat-rate'])
        ? (float) $settingsMap['tax.tax-vat-rate'] : 0.0;

    $shipping_fee = $subtotal >= $freeShippingThreshold ? 0.0 : $standardShippingFee;

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

    // ── Final grand total: subtotal - discount + shipping + tax ─────────────
    // Identical formula to updateTotals() in js/checkout.js: tax applies to
    // the discounted subtotal, not the pre-discount one.
    $taxable_amount = max(0.0, $subtotal - $discount_amount);
    $tax_amount     = round($taxable_amount * ($taxRatePercent / 100), 2);
    $grand_total    = round($subtotal - $discount_amount + $shipping_fee + $tax_amount, 2);

    // ── One checkout per customer at a time + duplicate-submit guard ────────
    // A double-click on "Place order" (or a retried request) fires the same
    // checkout twice. Serialise each customer's checkouts, then if an
    // identical order was created seconds ago, hand that one back instead of
    // creating a second order and taking the stock twice. The lock is
    // released automatically when this request's DB connection closes.
    $lockName = $pdo->quote('styled_checkout_u' . $user_id);
    if ((int) $pdo->query("SELECT GET_LOCK($lockName, 10)")->fetchColumn() !== 1) {
        ob_end_clean();
        http_response_code(429);
        echo json_encode(['error' => 'Another checkout is already in progress. Please wait a moment.']);
        exit;
    }

    $fingerprint = static function (array $lines): string {
        $m = [];
        foreach ($lines as $l) {
            $k = $l['product_id'] . '|' . $l['size'];
            $m[$k] = ($m[$k] ?? 0) + (int) $l['qty'];
        }
        ksort($m, SORT_NATURAL);
        return json_encode($m);
    };
    $recent = $pdo->prepare(
        "SELECT order_id, order_number, grand_total, payment_method, payment_status
         FROM orders
         WHERE user_id = :uid AND payment_method = :pm AND grand_total = :gt
           AND status <> 'cancelled' AND created_at >= (NOW() - INTERVAL 15 SECOND)
         ORDER BY order_id DESC LIMIT 5"
    );
    $recent->execute([':uid' => $user_id, ':pm' => $payment_method, ':gt' => $grand_total]);
    $recentItems = $pdo->prepare('SELECT product_id, size, qty FROM order_items WHERE order_id = ?');
    foreach ($recent->fetchAll(PDO::FETCH_ASSOC) as $dup) {
        $recentItems->execute([$dup['order_id']]);
        if ($fingerprint($recentItems->fetchAll(PDO::FETCH_ASSOC)) === $fingerprint($validated_items)) {
            $pdo->query("SELECT RELEASE_LOCK($lockName)");
            ob_end_clean();
            echo json_encode([
                'success'          => true,
                'duplicate'        => true,
                'order_id'         => (int) $dup['order_id'],
                'order_number'     => $dup['order_number'],
                'grand_total'      => (float) $dup['grand_total'],
                'payment_method'   => $dup['payment_method'],
                'payment_status'   => $dup['payment_status'],
                'requires_payment' => $dup['payment_method'] !== 'cod',
            ]);
            exit;
        }
    }

    // Free up units held by abandoned card/GCash orders first, so they're
    // available to this customer instead of being reported as sold out.
    stock_release_stale_holds($pdo);

    $pdo->beginTransaction();

    // ── Reserve stock ─────────────────────────────────────────────────────
    // Decrementing inside the same transaction as the order means any
    // item that can't be covered rolls the whole order back (nothing is
    // partially created). Each size row is locked (SELECT … FOR UPDATE)
    // before it is checked, so two simultaneous checkouts for the last unit
    // are serialised by InnoDB: the second sees 0 and is rejected, and stock
    // can never go negative. Every reservation is written to the stock
    // audit log once the order id exists (below), and that log is also what
    // lets a cancelled/expired order give exactly these units back.
    $lockStock = $pdo->prepare('SELECT stock_qty FROM product_sizes WHERE product_id = :pid AND size = :size FOR UPDATE');
    $takeStock = $pdo->prepare(
        'UPDATE product_sizes SET stock_qty = stock_qty - :qty
         WHERE product_id = :pid AND size = :size AND stock_qty >= :qty2'
    );
    $reservations = [];

    foreach ($validated_items as $item) {
        $lockStock->execute([':pid' => $item['product_id'], ':size' => $item['size']]);
        $before = $lockStock->fetchColumn();

        if ($before === false || (int) $before < $item['qty']) {
            $pdo->rollBack();
            ob_end_clean();
            http_response_code(409);
            $have = $before === false ? 0 : (int) $before;
            echo json_encode([
                'error' => $have === 0
                    ? "{$item['name']} (size {$item['size']}) is out of stock."
                    : "{$item['name']} (size {$item['size']}) doesn't have enough stock left. Only {$have} available.",
                'code'  => 'insufficient_stock',
            ]);
            exit;
        }

        $takeStock->execute([
            ':qty' => $item['qty'], ':pid' => $item['product_id'],
            ':size' => $item['size'], ':qty2' => $item['qty'],
        ]);
        if ($takeStock->rowCount() === 0) {
            $pdo->rollBack();
            ob_end_clean();
            http_response_code(409);
            echo json_encode([
                'error' => "{$item['name']} (size {$item['size']}) doesn't have enough stock left.",
                'code'  => 'insufficient_stock',
            ]);
            exit;
        }
        $reservations[] = $item + ['before' => (int) $before];
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

    // First timeline entry, stamped by the server clock.
    order_timeline_add($pdo, $order_id, 'Order Placed', null);

    // ── Stock audit log: who/what/when for every unit taken ─────────────────
    foreach ($reservations as $r) {
        stock_log($pdo, $r['product_id'], $r['size'], -$r['qty'], $r['before'], $r['before'] - $r['qty'],
                  'order_reserve', $order_id, $user_id, $order_number);
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