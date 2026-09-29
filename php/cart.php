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
        $product_id = isset($body['product_id']) ? (int) $body['product_id'] : 0;
        $qty        = isset($body['qty'])        ? (int) $body['qty']         : 1;

        // Validate size against the ENUM — default to 'XS' for accessories
        $valid_sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $size = isset($body['size']) && in_array($body['size'], $valid_sizes)
                ? trim($body['size']) : 'XS';

        if ($product_id <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }
        if ($qty < 1) { $qty = 1; }

        // Manual upsert: check if row exists, then UPDATE or INSERT
        // (ON DUPLICATE KEY needs a unique key on (user_id, product_id, size)
        //  which this table doesn't have, so we do it manually)
        $check = $pdo->prepare(
            'SELECT cart_id, qty FROM cart
             WHERE user_id = :uid AND product_id = :pid AND size = :size'
        );
        $check->execute([':uid' => $user_id, ':pid' => $product_id, ':size' => $size]);
        $existing = $check->fetch();

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

        echo json_encode(cart_response($pdo, $user_id));
        exit;
    }

    // ── DELETE: remove an item ────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $body       = get_json_body();
        $product_id = isset($body['product_id']) ? (int) $body['product_id'] : 0;
        $size       = isset($body['size'])       ? trim($body['size'])        : '';

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