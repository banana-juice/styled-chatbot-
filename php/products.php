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
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/stock.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Cancel abandoned card/GCash orders whose stock hold has run out, so the
// counts the storefront shows reflect units that are really available.
if ($method === 'GET') {
    stock_ensure_schema($pdo);
    stock_release_stale_holds($pdo);
}

if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Helper to fix image URL
function fixImageUrl($url) {
    if (empty($url)) return '/styled/assets/images/placeholder.jpg';
    if (strpos($url, '/styled/') === 0) return $url;
    return '/styled/' . ltrim($url, '/');
}

// Single product
if (!empty($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT p.*, c.name AS category_name
        FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        WHERE p.product_id = ? AND p.status = 'active'
    ");
    $stmt->execute([as_pos_int($_GET['id'])]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Product not found.']);
        exit;
    }

    // Get all images for gallery
    $imgStmt = $pdo->prepare("
        SELECT image_id, image_url, is_primary
        FROM product_images
        WHERE product_id = ?
        ORDER BY is_primary DESC, image_id ASC
    ");
    $imgStmt->execute([$product['product_id']]);
    $images = $imgStmt->fetchAll();
    foreach ($images as &$img) {
        $img['image_url'] = fixImageUrl($img['image_url']);
    }
    $product['images'] = $images;

    // Get available sizes with stock
    $sizeStmt = $pdo->prepare("
        SELECT size, stock_qty
        FROM product_sizes
        WHERE product_id = ? AND stock_qty > 0
        ORDER BY FIELD(size, 'XS', 'S', 'M', 'L', 'XL', 'XXL')
    ");
    $sizeStmt->execute([$product['product_id']]);
    $product['sizes'] = $sizeStmt->fetchAll();

    echo json_encode(['success' => true, 'product' => $product]);
    exit;
}

// List products with pagination + filters
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = min(50, max(1, (int) ($_GET['limit'] ?? 12)));
$offset = ($page - 1) * $limit;
$categorySlug = is_string($_GET['category'] ?? null) ? $_GET['category'] : '';
$search = is_string($_GET['search'] ?? null) ? $_GET['search'] : '';
$minPrice = isset($_GET['min_price']) && is_scalar($_GET['min_price']) ? (float) $_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) && is_scalar($_GET['max_price']) ? (float) $_GET['max_price'] : null;

$where = ["p.status = 'active'"];
$params = [];

if ($categorySlug) {
    $where[] = 'c.slug = ?';
    $params[] = $categorySlug;
}
if ($search) {
    $where[] = '(p.name LIKE ? OR p.description LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($minPrice !== null) {
    $where[] = 'p.price >= ?';
    $params[] = $minPrice;
}
if ($maxPrice !== null) {
    $where[] = 'p.price <= ?';
    $params[] = $maxPrice;
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// Count total
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM products p
    LEFT JOIN categories c ON c.category_id = p.category_id
    $whereSQL
");
$totalStmt->execute($params);
$total = (int) $totalStmt->fetchColumn();

// Get products with primary image
$stmt = $pdo->prepare("
    SELECT p.product_id, p.name, p.price, p.sale_price, p.description,
           c.name AS category_name, c.slug AS category_slug,
           COALESCE(
               (SELECT pi.image_url FROM product_images pi 
                WHERE pi.product_id = p.product_id AND pi.is_primary = 1 LIMIT 1),
               'assets/images/placeholder.jpg'
           ) AS primary_image,
           COALESCE(SUM(ps.stock_qty), 0) AS stock
    FROM products p
    LEFT JOIN categories c ON c.category_id = p.category_id
    LEFT JOIN product_sizes ps ON ps.product_id = p.product_id
    $whereSQL
    GROUP BY p.product_id
    ORDER BY p.created_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$products = $stmt->fetchAll();

// Fix image URLs for list
foreach ($products as &$product) {
    $product['primary_image'] = fixImageUrl($product['primary_image']);
}

echo json_encode([
    'success' => true,
    'products' => $products,
    'total' => $total,
    'page' => $page,
    'pages' => (int) ceil($total / $limit)
]);