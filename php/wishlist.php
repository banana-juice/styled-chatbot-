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

require_once __DIR__ . '/session_boot.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

// Helper to fix image URL (same as in products.php)
function fixImageUrl($url) {
    if (empty($url)) return '/styled/assets/images/placeholder.jpg';
    if (strpos($url, '/styled/') === 0) return $url;
    return '/styled/' . ltrim($url, '/');
}

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'logged_in' => false]);
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$method  = $_SERVER['REQUEST_METHOD'];

// ── Helper: read JSON body ────────────────────────────────────────────────────
function get_json_body(): array {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ── Helper: fetch all wishlist items for user (joined with products) ──────────
function fetch_wishlist_items(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare(
        'SELECT w.product_id,
                p.name,
                p.price,
                COALESCE(c.name, "") AS category,
                COALESCE(
                    (SELECT pi.image_url
                     FROM product_images pi
                     WHERE pi.product_id = p.product_id
                     ORDER BY pi.is_primary DESC, pi.image_id ASC
                     LIMIT 1),
                    ""
                ) AS img,
                p.description
         FROM wishlist w
         JOIN products p ON p.product_id = w.product_id
         LEFT JOIN categories c ON c.category_id = p.category_id
         WHERE w.user_id = :uid
           AND p.status = "active" AND p.is_active = 1
         ORDER BY w.added_at DESC'
    );
    $stmt->execute([':uid' => $user_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = [];
    foreach ($rows as $row) {
        // Fix image URL
        $imgUrl = fixImageUrl($row['img']);
        $items[] = [
            'product_id'  => (int) $row['product_id'],
            'name'        => $row['name'],
            'price'       => '₱' . number_format((float) $row['price'], 2),
            'img'         => $imgUrl,
            'category'    => $row['category'],
            'description' => $row['description'] ?? '',
        ];
    }
    return $items;
}

try {
    $pdo = getPDO();

    // ── GET — return all wishlist items ───────────────────────────────────────
    if ($method === 'GET') {
        $items = fetch_wishlist_items($pdo, $user_id);
        ob_end_clean();
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // ── POST — add item ───────────────────────────────────────────────────────
    if ($method === 'POST') {
        $body       = get_json_body();
        $product_id = isset($body['product_id']) ? as_pos_int($body['product_id']) : 0;

        if ($product_id <= 0) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }

        // Only real, currently-sold products can be wishlisted.
        $exists = $pdo->prepare("SELECT 1 FROM products WHERE product_id = ? AND status = 'active' AND is_active = 1");
        $exists->execute([$product_id]);
        if (!$exists->fetchColumn()) {
            ob_end_clean();
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
            exit;
        }

        // Upsert — ignore duplicate (already wishlisted)
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO wishlist (user_id, product_id) VALUES (:uid, :pid)'
        );
        $stmt->execute([':uid' => $user_id, ':pid' => $product_id]);

        $items = fetch_wishlist_items($pdo, $user_id);
        ob_end_clean();
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // ── DELETE — remove item ──────────────────────────────────────────────────
    if ($method === 'DELETE') {
        $body       = get_json_body();
        $product_id = isset($body['product_id']) ? as_pos_int($body['product_id']) : 0;

        if ($product_id <= 0) {
            ob_end_clean();
            http_response_code(400);
            echo json_encode(['error' => 'product_id is required']);
            exit;
        }

        $stmt = $pdo->prepare(
            'DELETE FROM wishlist WHERE user_id = :uid AND product_id = :pid'
        );
        $stmt->execute([':uid' => $user_id, ':pid' => $product_id]);

        $items = fetch_wishlist_items($pdo, $user_id);
        ob_end_clean();
        echo json_encode(['success' => true, 'items' => $items]);
        exit;
    }

    // ── Method not allowed ────────────────────────────────────────────────────
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'detail' => $e->getMessage()]);
}