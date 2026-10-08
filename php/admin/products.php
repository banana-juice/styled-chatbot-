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
require_once __DIR__ . '/../stock.php';

/**
 * The real image type of an uploaded file ('jpg' | 'png' | 'webp'), or null. The file's
 * CONTENT decides, not its name: a text/HTML/PHP file renamed to .jpg is refused.
 * Files over 8 MB are refused too.
 */
function detect_uploaded_image_ext(string $tmpPath): ?string {
    if (!is_uploaded_file($tmpPath) || filesize($tmpPath) > 8 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize($tmpPath);
    if (!$info) {
        return null;
    }
    $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    return $map[$info[2]] ?? null;
}

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();
stock_ensure_schema($pdo);

/** Shared product-field validation. Returns an error string or null. */
function validate_product_fields(?string $name, $price): ?string {
    if ($name !== null) {
        if ($name === '') return 'Product name is required.';
        if (mb_strlen($name) > 200) return 'Product name must be 200 characters or fewer.';
    }
    if ($price !== null) {
        if (!is_numeric($price)) return 'Price must be a number.';
        if ((float) $price <= 0) return 'Price must be greater than zero.';
        if ((float) $price > 10000000) return 'Price is too large.';
    }
    return null;
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    // Single product
    if (!empty($_GET['id'])) {
        $id = as_pos_int($_GET['id']);
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
        $sizes = $pdo->prepare("SELECT size_id, product_id, size, stock_qty FROM product_sizes WHERE product_id = ?");
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
    $page     = max(1, as_pos_int($_GET['page'] ?? 1) ?: 1);
    $limit    = min(50, max(1, as_pos_int($_GET['limit'] ?? 8) ?: 8));
    $offset   = ($page - 1) * $limit;
    $category = as_text($_GET['category'] ?? '');
    $search   = as_text($_GET['search'] ?? '');

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
        $name        = trim(as_text($body['name'] ?? ''));
        $category_id = as_pos_int($body['category_id'] ?? 0);
        $price       = is_scalar($body['price'] ?? null) ? (float) $body['price'] : 0;
        $description = trim(as_text($body['description'] ?? ''));
    } else {
        $name        = trim(as_text($_POST['name'] ?? ''));
        $category_id = as_pos_int($_POST['category_id'] ?? 0);
        $price       = (float) ($_POST['price']    ?? 0);
        $description = trim(as_text($_POST['description'] ?? ''));
    }

    if (!$name || !$category_id || !$price) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'name, category_id and price are required.']);
        exit;
    }
    // `!$price` only rejects exactly 0 -- a negative price (-99) sailed through.
    if ($err = validate_product_fields($name, $price)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $err]);
        exit;
    }
    if (mb_strlen($description) > 5000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Description must be 5000 characters or fewer.']);
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
            
            $ext = detect_uploaded_image_ext($tmpName);
            if ($ext === null) continue;
            
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
    
    $id = as_pos_int($_GET['id'] ?? 0);
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
        
        // Validate everything first so a bad value can't leave the product
        // half-updated (e.g. name saved, variants rejected).
        $nameIn  = isset($body['name']) ? trim(as_text($body['name'])) : null;
        $priceIn = isset($body['price']) ? $body['price'] : null;
        if ($err = validate_product_fields($nameIn, $priceIn)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $err]);
            exit;
        }
        if (isset($body['description']) && (!is_string($body['description']) || mb_strlen($body['description']) > 5000)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Description must be 5000 characters or fewer.']);
            exit;
        }
        $validSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $cleanVariants = null;
        if (isset($body['variants']) && is_array($body['variants'])) {
            $cleanVariants = [];
            foreach ($body['variants'] as $v) {
                if (empty($v['size'])) continue;
                $qtyRaw = $v['stock_qty'] ?? 0;
                if (!in_array($v['size'], $validSizes, true)
                    || !is_numeric($qtyRaw) || (int) $qtyRaw != $qtyRaw
                    || (int) $qtyRaw < 0 || (int) $qtyRaw > 1000000) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'Each variant needs a valid size and a whole-number stock of 0 or more.']);
                    exit;
                }
                $cleanVariants[$v['size']] = [
                    'qty' => (int) $qtyRaw,
                ];
            }
        }

        // Update product fields from JSON
        $fields = [];
        $params = [];
        if ($nameIn !== null) {
            $fields[] = 'name = ?';
            $params[] = $nameIn;
        }
        if (isset($body['description'])) {
            $fields[] = 'description = ?';
            $params[] = $body['description'];
        }
        if (isset($body['price'])) {
            $fields[] = 'price = ?';
            $params[] = is_scalar($body['price']) ? (float) $body['price'] : 0;
        }
        if (isset($body['category_id'])) {
            $fields[] = 'category_id = ?';
            $params[] = as_pos_int($body['category_id']);
        }
        if (!empty($fields)) {
            $params[] = $id;
            $pdo->prepare("UPDATE products SET " . implode(', ', $fields) . " WHERE product_id = ?")->execute($params);
        }

        // -- Update variants (sizes) ------------------------------------------
        // Replace-all, in one transaction, and every size whose stock changed
        // is written to the stock audit log with who changed it and when.
        if ($cleanVariants !== null) {
            $pdo->beginTransaction();
            $old = $pdo->prepare('SELECT size, stock_qty FROM product_sizes WHERE product_id = ? FOR UPDATE');
            $old->execute([$id]);
            $oldQty = $old->fetchAll(PDO::FETCH_KEY_PAIR);

            $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$id]);
            $insert = $pdo->prepare("INSERT INTO product_sizes (product_id, size, stock_qty) VALUES (?, ?, ?)");
            foreach ($cleanVariants as $size => $v) {
                $insert->execute([$id, $size, $v['qty']]);
                $before = array_key_exists($size, $oldQty) ? (int) $oldQty[$size] : 0;
                if ($before !== $v['qty']) {
                    stock_log($pdo, $id, $size, $v['qty'] - $before, $before, $v['qty'], 'admin_adjust', null, $user['user_id'], 'Product variants edited');
                }
            }
            foreach ($oldQty as $size => $qty) {
                if (!isset($cleanVariants[$size]) && (int) $qty !== 0) {
                    stock_log($pdo, $id, $size, -(int) $qty, (int) $qty, 0, 'admin_adjust', null, $user['user_id'], 'Size removed');
                }
            }
            $pdo->commit();
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
            
            $ext = detect_uploaded_image_ext($tmpName);
            if ($ext === null) continue;
            
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

    $id = as_pos_int($_GET['id'] ?? 0);
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

    // A product that has ever been ordered can't be hard-deleted: order_items
    // references it (the old code just crashed with a foreign-key error), and
    // past orders must keep showing what was bought. Archive it instead — the
    // storefront only lists status = 'active', so it disappears from sale.
    $ordered = $pdo->prepare("SELECT COUNT(*) FROM order_items WHERE product_id = ?");
    $ordered->execute([$id]);
    if ((int) $ordered->fetchColumn() > 0) {
        $pdo->prepare("UPDATE products SET status = 'archived' WHERE product_id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'archived' => true,
            'message' => 'This product appears in past orders, so it was archived (removed from sale) instead of deleted.']);
        exit;
    }

    // product_sizes and product_images have no foreign key to products, so
    // nothing cascades: delete the children explicitly, in one transaction,
    // or their rows are orphaned (and stock rows linger for a product that no
    // longer exists).
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM product_sizes WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM products WHERE product_id = ?")->execute([$id]);
    $pdo->commit();

    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);