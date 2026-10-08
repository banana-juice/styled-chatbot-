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
    $code     = strtoupper(trim(is_string($_GET['code'] ?? null) ? $_GET['code'] : ''));
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

// Creating, editing, deleting and listing promo codes is admin-only and lives in
// php/admin/promotions.php. This public file only CHECKS a code at checkout (GET above).
// It used to also accept POST create/update/delete/list with NO login at all, so any
// visitor could mint a 90%-off code (or read every code). Those actions are gone.
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);