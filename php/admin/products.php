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
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single product
    if (!empty($_GET['id'])) {
        $id = (int) $_GET['id'];
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid product id.']);
            exit;
        }
        $stmt = $pdo->prepare("
            SELECT p.*, c.name AS category_name
            FROM products p
            LEFT JOIN categories c ON c.category_id = p.category_id
            WHERE p.product_id = ?
        ");
        $stmt->execute([$id]);
        $product = $stmt->fetch();

        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found.']);
            exit;
        }

        // Sizes + stock
        $sizes = $pdo->prepare("SELECT * FROM product_sizes WHERE product_id = ?");
        $sizes->execute([$product['product_id']]);
        $product['sizes'] = $sizes->fetchAll();

        // Images — order by primary first, then image_id
        $imgs = $pdo->prepare("
            SELECT image_id, image_url, is_primary
            FROM product_images
            WHERE product_id = ?
            ORDER BY is_primary DESC, image_id ASC
        ");
        $imgs->execute([$product['product_id']]);
        $product['images'] = $imgs->fetchAll();

        echo json_encode(['success' => true, 'product' => $product]);
        exit;
    }

    // List products (admin sees all, no status filter)
    $page     = max(1, (int) ($_GET['page']     ?? 1));
    $limit    = min(50, max(1, (int) ($_GET['limit'] ?? 8)));
    $offset   = ($page - 1) * $limit;
    $category = $_GET['category'] ?? '';
    $search   = $_GET['search']   ?? '';

    $where  = [];
    $params = [];

    if ($category) {
        $where[]  = 'c.name = ?';
        $params[] = $category;
    }
    if ($search) {
        $where[]  = 'p.name LIKE ?';
        $params[] = "%$search%";
    }

    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $totalStmt = $pdo->prepare("
        SELECT COUNT(*) FROM products p
        LEFT JOIN categories c ON c.category_id = p.category_id
        $whereSQL
    ");
    $totalStmt->execute($params);
    $totalCount = (int) $totalStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.product_id, p.name, p.price,
               c.name AS category,
               COALESCE(SUM(ps.stock_qty), 0) AS stock
        FROM products p
        LEFT JOIN categories   c  ON c.category_id = p.category_id
        LEFT JOIN product_sizes ps ON ps.product_id = p.product_id
        $whereSQL
        GROUP BY p.product_id
        ORDER BY p.created_at DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    
    $products = $stmt->fetchAll();
    
    foreach ($products as &$product) {
        $stock = (int) $product['stock'];
        if ($stock == 0) {
            $product['status'] = 'Out of Stock';
        } elseif ($stock <= 5) {
            $product['status'] = 'Low Stock';
        } else {
            $product['status'] = 'Active';
        }
    }

    echo json_encode([
        'success'  => true,
        'products' => $products,
        'total'    => $totalCount,
        'page'     => $page,
        'pages'    => (int) ceil($totalCount / $limit),
    ]);
    exit;
}

// ── POST: Create product with multiple images (admin only) ───────────────────
if ($method === 'POST') {
    requireAuth('admin');

    // Check for JSON or multipart
    $isJson = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
    
    if ($isJson) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name        = trim($body['name']        ?? '');
        $category_id = (int) ($body['category_id'] ?? 0);
        $price       = (float) ($body['price']    ?? 0);
        $description = trim($body['description'] ?? '');
    } else {
        $name        = trim($_POST['name']        ?? '');
        $category_id = (int) ($_POST['category_id'] ?? 0);
        $price       = (float) ($_POST['price']    ?? 0);
        $description = trim($_POST['description'] ?? '');
    }

    if (!$name || !$category_id || !$price) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'name, category_id and price are required.']);
        exit;
    }

    // Insert product
    $stmt = $pdo->prepare("
        INSERT INTO products (name, category_id, price, description, created_at)
        VALUES (:name, :category_id, :price, :description, NOW())
    ");
    $stmt->execute([
        ':name'        => $name,
        ':category_id' => $category_id,
        ':price'       => $price,
        ':description' => $description,
    ]);
    $productId = (int) $pdo->lastInsertId();

    // Handle multiple image uploads (key = 'images[]')
    if (!empty($_FILES['images']['tmp_name'][0])) {
        $uploadDir = __DIR__ . '/../../assets/images/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $isFirst = true;
        
        foreach ($_FILES['images']['tmp_name'] as $index => $tmpName) {
            if (empty($tmpName)) continue;
            
            $ext = strtolower(pathinfo($_FILES['images']['name'][$index], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            
            $filename = uniqid('prod_') . '.' . $ext;
            move_uploaded_file($tmpName, $uploadDir . $filename);
            $imageUrl = 'assets/images/products/' . $filename;
            
            // First image becomes primary by default
            $isPrimary = $isFirst ? 1 : 0;
            $isFirst = false;
            
            $imgStmt = $pdo->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)");
            $imgStmt->execute([$productId, $imageUrl, $isPrimary]);
        }
    }

    echo json_encode(['success' => true, 'product_id' => $productId]);
    exit;
}

// ── PUT: Update product + images + variants (admin only) ─────────────────────
if ($method === 'PUT') {
    requireAuth('admin');
    
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing product id.']);
        exit;
    }
    
    // Handle multipart vs JSON
    $isMultipart = !empty($_FILES);
    $imagesActions = [];
    $body = [];
    
    if ($isMultipart) {
        // Get images actions from JSON string field
        if (!empty($_POST['images_actions'])) {
            $imagesActions = json_decode($_POST['images_actions'], true) ?: [];
        }
        // For product fields, we need to read from $_POST (not JSON)
        // But product fields are usually sent via JSON PUT, not multipart.
        // However, for image updates we only expect images_actions.
    } else {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $imagesActions = $body['images'] ?? [];
        
        // Update product fields from JSON
        $fields = [];
        $params = [];
        if (isset($body['name'])) {
            $fields[] = 'name = ?';
            $params[] = $body['name'];
        }
        if (isset($body['description'])) {
            $fields[] = 'description = ?';
            $params[] = $body['description'];
        }
        if (isset($body['price'])) {
            $fields[] = 'price = ?';
            $params[] = (float) $body['price'];
        }
        if (isset($body['category_id'])) {
            $fields[] = 'category_id = ?';
            $params[] = (int) $body['category_id'];
        }
        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE products SET " . implode(', ', $fields) . " WHERE product_id = ?")->execute($params);
        }
        
        // ── Update variants (sizes) ─────────────────────────────────────────
        if (isset($body['variants']) && is_array($body['variants'])) {
            // Delete existing sizes for this product
            $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$id]);
            // Insert new ones
            $insert = $pdo->prepare("INSERT INTO product_sizes (product_id, size, stock_qty, sku) VALUES (?, ?, ?, ?)");
            foreach ($body['variants'] as $v) {
                if (!empty($v['size'])) {
                    $insert->execute([$id, $v['size'], (int)($v['stock_qty'] ?? 0), $v['sku'] ?? null]);
                }
            }
        }
    }
    
    // Process image actions (both JSON and multipart)
    foreach ($imagesActions as $action) {
        $actionType = $action['action'] ?? '';
        $imageId = (int) ($action['image_id'] ?? 0);
        
        if ($actionType === 'delete' && $imageId) {
            $pdo->prepare("DELETE FROM product_images WHERE image_id = ? AND product_id = ?")->execute([$imageId, $id]);
        }
        elseif ($actionType === 'set_primary' && $imageId) {
            $pdo->prepare("UPDATE product_images SET is_primary = 0 WHERE product_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE product_images SET is_primary = 1 WHERE image_id = ?")->execute([$imageId]);
        }
        elseif ($actionType === 'add' && !empty($action['image_url'])) {
            $isPrimary = isset($action['is_primary']) ? (int) $action['is_primary'] : 0;
            $pdo->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)")
                ->execute([$id, $action['image_url'], $isPrimary]);
        }
    }
    
    // Process newly uploaded files from multipart request (key = 'new_images[]')
    if ($isMultipart && !empty($_FILES['new_images']['tmp_name'][0])) {
        $uploadDir = __DIR__ . '/../../assets/images/products/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0775, true);
        
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        // Check if any images exist to determine primary
        $existingCount = $pdo->prepare("SELECT COUNT(*) FROM product_images WHERE product_id = ?");
        $existingCount->execute([$id]);
        $hasImages = $existingCount->fetchColumn() > 0;
        
        foreach ($_FILES['new_images']['tmp_name'] as $index => $tmpName) {
            if (empty($tmpName)) continue;
            
            $ext = strtolower(pathinfo($_FILES['new_images']['name'][$index], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            
            $filename = uniqid('prod_') . '.' . $ext;
            move_uploaded_file($tmpName, $uploadDir . $filename);
            $imageUrl = 'assets/images/products/' . $filename;
            
            // If no images yet, this becomes primary
            $isPrimary = $hasImages ? 0 : 1;
            $hasImages = true;
            
            $pdo->prepare("INSERT INTO product_images (product_id, image_url, is_primary) VALUES (?, ?, ?)")
                ->execute([$id, $imageUrl, $isPrimary]);
        }
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE (admin only) ───────────────────────────────────────────────────────
if ($method === 'DELETE') {
    requireAuth('admin');

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing or invalid product id.']);
        exit;
    }

    // Check if product exists
    $check = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ?");
    $check->execute([$id]);
    if (!$check->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Product not found.']);
        exit;
    }

    // Delete (images will cascade due to foreign key constraint)
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]);

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);