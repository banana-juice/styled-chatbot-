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

// ── Helper: read raw JSON body ────────────────────────────────────────────────
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Helper: build cart response ───────────────────────────────────────────────
function cart_response(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare(
        'SELECT
             c.product_id,
             c.size,
             c.qty,
             p.name,
             p.price,
             cat.slug  AS category,
             (SELECT pi.image_url
                FROM product_images pi
               WHERE pi.product_id = p.product_id
               ORDER BY pi.image_id ASC
               LIMIT 1) AS image
         FROM cart c
         JOIN products    p   ON p.product_id    = c.product_id
         JOIN categories  cat ON cat.category_id = p.category_id
         WHERE c.user_id = :uid
         ORDER BY c.added_at DESC'
    );
    $stmt->execute([':uid' => $user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items      = [];
    $subtotal   = 0.0;
    $item_count = 0;

    foreach ($rows as $row) {
        $price_num   = (float) preg_replace('/[^0-9.]/', '', $row['price']);
        $line_total  = $price_num * (int) $row['qty'];
        $subtotal   += $line_total;
        $item_count += (int) $row['qty'];

        $items[] = [
            'product_id' => (int) $row['product_id'],
            'name'       => $row['name'],
            'price'      => $row['price'],
            'price_num'  => $price_num,
            'img'        => $row['image'],
            'image'      => $row['image'],
            'category'   => $row['category'],
            'size'       => $row['size'],
            'qty'        => (int) $row['qty'],
        ];
    }

    return [
        'items'      => $items,
        'subtotal'   => round($subtotal, 2),
        'item_count' => $item_count,
    ];
}

// ── Route ─────────────────────────────────────────────────────────────────────
try {
    $pdo = getPDO();

    ob_end_clean(); // discard any stray output before sending JSON

    // ── GET: return the user's cart ───────────────────────────────────────────
    if ($method === 'GET') {
        echo json_encode(cart_response($pdo, $user_id));
        exit;
    }

    // ── POST: add or update an item ───────────────────────────────────────────
    if ($method === 'POST') {
        $body       = get_json_body();
        $product_id = isset($body['product_id']) ? as_pos_int($body['product_id']) : 0;
        $qtyRaw     = array_key_exists('qty', $body) ? $body['qty'] : 1;

        if ($product_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }

        // Quantity must be a real whole number from 1 to 99. Before, 0,
        // negatives and text were silently turned into 1 and 999999 was
        // stored as-is, so the cart could disagree with what the customer typed.
        if ((!is_int($qtyRaw) && !(is_string($qtyRaw) && ctype_digit($qtyRaw)))
            || (int) $qtyRaw < 1 || (int) $qtyRaw > 99) {
            http_response_code(400);
            echo json_encode(['error' => 'Quantity must be a whole number between 1 and 99.']);
            exit;
        }
        $qty = (int) $qtyRaw;

        // Only active products with stock rows can be carted.
        $prod = $pdo->prepare("SELECT name FROM products WHERE product_id = :pid AND status = 'active' AND is_active = 1");
        $prod->execute([':pid' => $product_id]);
        $productName = $prod->fetchColumn();
        if ($productName === false) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
            exit;
        }

        // Resolve the size against the sizes this product really has. An
        // unknown size is an error now, instead of silently becoming 'XS'
        // (which put the wrong variant in the cart).
        $valid_sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $szStmt = $pdo->prepare('SELECT size, stock_qty FROM product_sizes WHERE product_id = :pid');
        $szStmt->execute([':pid' => $product_id]);
        $stockBySize = $szStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $size = strtoupper(trim((string) ($body['size'] ?? '')));
        if ($size === '' && count($stockBySize) === 1) {
            $size = (string) array_key_first($stockBySize); // single-variant item
        }
        if ($size === '') {
            http_response_code(400);
            echo json_encode(['error' => 'Please choose a size.', 'code' => 'size_required']);
            exit;
        }
        if (!in_array($size, $valid_sizes, true) || !array_key_exists($size, $stockBySize)) {
            http_response_code(400);
            echo json_encode(['error' => "{$productName} isn't available in size {$size}.", 'code' => 'size_unavailable']);
            exit;
        }

        // Never carry more than is in stock. Checkout re-checks authoritatively;
        // this just stops the cart promising units that don't exist.
        $available = (int) $stockBySize[$size];
        $notice = null;
        if ($available < 1) {
            http_response_code(409);
            echo json_encode(['error' => "{$productName} (size {$size}) is out of stock.", 'code' => 'out_of_stock']);
            exit;
        }
        if ($qty > $available) {
            $qty = $available;
            $notice = "Only {$available} of {$productName} (size {$size}) available; quantity adjusted.";
        }

        // Check-then-write under a per-customer lock, so rapid double clicks (or
        // two tabs) can never create duplicate rows or lose an increment, whatever
        // unique keys the database has.
        $lockName = 'cart_' . $user_id;
        $lk = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $lk->execute([$lockName]);
        $gotLock = (int) $lk->fetchColumn() === 1;
        try {
            $check = $pdo->prepare(
                'SELECT cart_id, qty FROM cart
                 WHERE user_id = :uid AND product_id = :pid AND size = :size'
            );
            $check->execute([':uid' => $user_id, ':pid' => $product_id, ':size' => $size]);
            $existing = $check->fetch();

            // "add" mode (the Add to Cart buttons): stack onto what is already in the
            // cart instead of replacing it. The cart page's +/- sends no flag and
            // keeps "set this quantity" semantics.
            if ($existing && ($body['add'] ?? false) === true) {
                $want = (int) $existing['qty'] + $qty;
                $cap  = min($available, 99);
                if ($want > $cap) {
                    $want   = $cap;
                    $notice = "Only {$available} of {$productName} (size {$size}) available; your cart quantity was adjusted.";
                }
                $qty = $want;
            }

            if ($existing) {
                $upd = $pdo->prepare(
                    'UPDATE cart SET qty = :qty, added_at = NOW()
                     WHERE cart_id = :cid'
                );
                $upd->execute([':qty' => $qty, ':cid' => $existing['cart_id']]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO cart (user_id, product_id, size, qty, added_at)
                     VALUES (:uid, :pid, :size, :qty, NOW())'
                );
                $ins->execute([
                    ':uid'  => $user_id,
                    ':pid'  => $product_id,
                    ':size' => $size,
                    ':qty'  => $qty,
                ]);
            }
        } finally {
            if ($gotLock) {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            }
        }

        $response = cart_response($pdo, $user_id);
        if ($notice !== null) {
            $response['notice'] = $notice;
        }
        echo json_encode($response);
        exit;
    }

    // ── DELETE: remove an item ────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $body       = get_json_body();
        $product_id = isset($body['product_id']) ? as_pos_int($body['product_id']) : 0;
        $size       = isset($body['size'])       ? trim(is_string($body['size']) ? $body['size'] : '')        : '';

        if ($product_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }

        $stmt = $pdo->prepare(
            'DELETE FROM cart
             WHERE user_id = :uid AND product_id = :pid AND size = :size'
        );
        $stmt->execute([
            ':uid'  => $user_id,
            ':pid'  => $product_id,
            ':size' => $size,
        ]);

        echo json_encode(cart_response($pdo, $user_id));
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}