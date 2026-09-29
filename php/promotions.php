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

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: Validate a promo code ────────────────────────────────────────────────
if ($method === 'GET') {
    $code     = strtoupper(trim($_GET['code'] ?? ''));
    $subtotal = (float) ($_GET['subtotal'] ?? 0);

    if ($code === '') {
        http_response_code(400);
        echo json_encode(['valid' => false, 'message' => 'Please enter a promo code.']);
        exit;
    }

    $pdo  = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM promotions WHERE code = ? LIMIT 1");
    $stmt->execute([$code]);
    $promo = $stmt->fetch();

    // Code not found
    if (!$promo) {
        echo json_encode(['valid' => false, 'message' => 'Invalid or expired promo code.']);
        exit;
    }

    // Not active
    if (!$promo['is_active']) {
        echo json_encode(['valid' => false, 'message' => 'This promo code is no longer active.']);
        exit;
    }

    // Expired
    if ($promo['expiry_date'] !== null && strtotime($promo['expiry_date']) < time()) {
        echo json_encode(['valid' => false, 'message' => 'This promo code has expired.']);
        exit;
    }

    // Usage limit reached
    if ($promo['usage_limit'] !== null && $promo['usage_count'] >= $promo['usage_limit']) {
        echo json_encode(['valid' => false, 'message' => 'This promo code has reached its usage limit.']);
        exit;
    }

    // Minimum order not met
    if ($promo['min_order'] > 0 && $subtotal < $promo['min_order']) {
        $min = number_format($promo['min_order'], 2);
        echo json_encode(['valid' => false, 'message' => "Minimum spend of ₱{$min} required for this code."]);
        exit;
    }

    // All good — build friendly message
    $value = (float) $promo['discount_value'];
    if ($promo['discount_type'] === 'percent') {
        $msg = number_format($value, 0) . '% off applied!';
    } else {
        $msg = '₱' . number_format($value, 2) . ' off applied!';
    }

    echo json_encode([
        'valid'          => true,
        'code'           => $promo['code'],
        'discount_type'  => $promo['discount_type'],
        'discount_value' => $value,
        'message'        => $msg,
    ]);
    exit;
}

// ── POST: Admin CRUD ──────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    $pdo    = getPDO();

    // CREATE
    if ($action === 'create') {
        $required = ['code', 'discount_type', 'discount_value'];
        foreach ($required as $f) {
            if (empty($body[$f])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => "Missing field: {$f}"]);
                exit;
            }
        }

        // Duplicate check
        $chk = $pdo->prepare("SELECT promo_id FROM promotions WHERE code = ?");
        $chk->execute([strtoupper(trim($body['code']))]);
        if ($chk->fetch()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'Promo code already exists.']);
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO promotions
                (code, discount_type, discount_value, min_order, usage_limit, usage_count, is_active, expiry_date)
            VALUES
                (:code, :discount_type, :discount_value, :min_order, :usage_limit, 0, :is_active, :expiry_date)
        ");
        $stmt->execute([
            ':code'           => strtoupper(trim($body['code'])),
            ':discount_type'  => $body['discount_type'],
            ':discount_value' => (float) $body['discount_value'],
            ':min_order'      => (float) ($body['min_order'] ?? 0),
            ':usage_limit'    => isset($body['usage_limit']) ? (int) $body['usage_limit'] : null,
            ':is_active'      => isset($body['is_active']) ? (int) $body['is_active'] : 1,
            ':expiry_date'    => $body['expiry_date'] ?? null,
        ]);

        echo json_encode(['success' => true, 'promo_id' => (int) $pdo->lastInsertId()]);
        exit;
    }

    // UPDATE
    if ($action === 'update') {
        if (empty($body['promo_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing promo_id.']);
            exit;
        }
        $stmt = $pdo->prepare("
            UPDATE promotions SET
                code           = :code,
                discount_type  = :discount_type,
                discount_value = :discount_value,
                min_order      = :min_order,
                usage_limit    = :usage_limit,
                is_active      = :is_active,
                expiry_date    = :expiry_date
            WHERE promo_id = :promo_id
        ");
        $stmt->execute([
            ':code'           => strtoupper(trim($body['code'])),
            ':discount_type'  => $body['discount_type'],
            ':discount_value' => (float) $body['discount_value'],
            ':min_order'      => (float) ($body['min_order'] ?? 0),
            ':usage_limit'    => isset($body['usage_limit']) ? (int) $body['usage_limit'] : null,
            ':is_active'      => (int) ($body['is_active'] ?? 1),
            ':expiry_date'    => $body['expiry_date'] ?? null,
            ':promo_id'       => (int) $body['promo_id'],
        ]);
        echo json_encode(['success' => true]);
        exit;
    }

    // DELETE
    if ($action === 'delete') {
        if (empty($body['promo_id'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing promo_id.']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM promotions WHERE promo_id = ?");
        $stmt->execute([(int) $body['promo_id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // LIST (for admin table)
    if ($action === 'list') {
        $rows = $pdo->query("SELECT * FROM promotions ORDER BY created_at DESC")->fetchAll();
        echo json_encode(['success' => true, 'promotions' => $rows]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);