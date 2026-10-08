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

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    requireAuth();  // staff and admin can view promotions
} else {
    requireAuth('admin'); // only admin can create/update/delete
}
$pdo = getPDO();

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $rows = $pdo->query("SELECT * FROM promotions ORDER BY created_at DESC")->fetchAll();
    echo json_encode(['success' => true, 'promotions' => $rows]);
    exit;
}

// ── POST: Create ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    reject_nested_json($body);

    foreach (['code', 'discount_type', 'discount_value'] as $f) {
        if (empty($body[$f])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Missing field: $f"]);
            exit;
        }
    }

    $code = strtoupper(trim(as_text($body['code'])));
    if ($code === '' || mb_strlen($code) > 40 || !is_string($body['discount_type'] ?? null) || !in_array($body['discount_type'], ['percent', 'fixed'], true) || !is_numeric($body['discount_value']) || (float) $body['discount_value'] <= 0 || (float) $body['discount_value'] > 1000000) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid code, discount type or discount value.']);
        exit;
    }

    // Duplicate check
    $chk = $pdo->prepare("SELECT promo_id FROM promotions WHERE code = ?");
    $chk->execute([$code]);
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
        ':code'           => $code,
        ':discount_type'  => $body['discount_type'],
        ':discount_value' => (float) $body['discount_value'],
        ':min_order'      => (float) ($body['min_order']   ?? 0),
        ':usage_limit'    => isset($body['usage_limit']) ? as_nonneg_int($body['usage_limit']) : null,
        ':is_active'      => as_flag($body['is_active'] ?? 1, 1),
        ':expiry_date'    => $body['expiry_date'] ?? null,
    ]);

    echo json_encode(['success' => true, 'promo_id' => (int) $pdo->lastInsertId()]);
    exit;
}

// ── PUT: Update / toggle ──────────────────────────────────────────────────────
if ($method === 'PUT') {
    $id = as_pos_int($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing promo id.']);
        exit;
    }

    $body   = json_decode(file_get_contents('php://input'), true) ?? [];

    reject_nested_json($body);
    $fields = [];
    $params = [];

    $allowed = ['code', 'discount_type', 'discount_value', 'min_order', 'usage_limit', 'is_active', 'expiry_date'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $fields[] = "$f = ?";
            if ($f === 'code') {
                $params[] = strtoupper(trim(as_text($body[$f])));
            } elseif ($f === 'is_active') {
                $params[] = as_flag($body[$f], 1);
            } elseif ($f === 'usage_limit') {
                $params[] = $body[$f] === null ? null : as_nonneg_int($body[$f]);
            } else {
                $v = $body[$f];
                if ($f === 'discount_type' && !(is_string($v) && in_array($v, ['percent', 'fixed'], true))) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'discount_type must be "percent" or "fixed".']);
                    exit;
                }
                if (in_array($f, ['discount_value', 'min_order'], true) && !(is_numeric($v) && (float) $v >= 0 && (float) $v <= 1000000)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => "$f must be a number from 0 to 1,000,000."]);
                    exit;
                }
                if ($f === 'expiry_date' && $v !== null && !(is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v))) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'error' => 'expiry_date must look like 2026-12-31.']);
                    exit;
                }
                $params[] = $v;
            }
        }
    }

    if (empty($fields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nothing to update.']);
        exit;
    }

    $params[] = $id;
    $pdo->prepare("UPDATE promotions SET " . implode(', ', $fields) . " WHERE promo_id = ?")->execute($params);

    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $id = as_pos_int($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing promo id.']);
        exit;
    }

    $pdo->prepare("DELETE FROM promotions WHERE promo_id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);