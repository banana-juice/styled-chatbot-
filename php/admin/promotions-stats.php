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

requireAuth(); // staff and admin

$pdo = getPDO();

// Active codes: promotions that are active, not expired, and have remaining usage (if limit set)
$active = $pdo->query("
    SELECT COUNT(*) AS cnt
    FROM promotions
    WHERE is_active = 1
      AND (expiry_date IS NULL OR expiry_date > NOW())
      AND (usage_limit IS NULL OR usage_count < usage_limit)
")->fetchColumn();

// Total uses = number of orders that used any promo (distinct orders)
$totalUses = (int) $pdo->query("
    SELECT COUNT(DISTINCT order_id)
    FROM orders
    WHERE promo_id IS NOT NULL
      AND status NOT IN ('cancelled', 'refunded')
")->fetchColumn();

// Revenue from promos = sum of discount from orders that used a promo
$revenueFromPromos = (float) $pdo->query("
    SELECT COALESCE(SUM(discount), 0)
    FROM orders
    WHERE promo_id IS NOT NULL
      AND status NOT IN ('cancelled', 'refunded')
")->fetchColumn();

echo json_encode([
    'active_codes'         => (int) $active,
    'total_uses'           => $totalUses,
    'revenue_from_promos'  => $revenueFromPromos,
]);