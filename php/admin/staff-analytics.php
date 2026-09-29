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

// Only staff and admin can access
requireAuth();

$pdo = getPDO();

// Only count orders that are not cancelled/refunded
$stats = $pdo->query("
    SELECT
        COALESCE(SUM(grand_total), 0)   AS total_revenue,
        COUNT(*)                        AS total_orders,
        COALESCE(AVG(grand_total), 0)   AS avg_order_value
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
")->fetch();

// Conversion rate = (total_orders / total_customers) * 100
$totalCustomers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$conversionRate = $totalCustomers > 0 ? round(($stats['total_orders'] / $totalCustomers) * 100, 2) : 0;

// Percentage changes vs previous 7 days (same logic as admin analytics)
$today = date('Y-m-d');
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$fourteenDaysAgo = date('Y-m-d', strtotime('-14 days'));

$revChange = $pdo->prepare("
    SELECT COALESCE(SUM(grand_total), 0) AS revenue
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
      AND created_at >= ? AND created_at < ?
");
$revChange->execute([$fourteenDaysAgo, $sevenDaysAgo]);
$prevRevenue = (float) $revChange->fetchColumn();
$revChange->execute([$sevenDaysAgo, $today]);
$currRevenue = (float) $revChange->fetchColumn();
$revenueChangePercent = 0;
if ($prevRevenue > 0) {
    $revenueChangePercent = round((($currRevenue - $prevRevenue) / $prevRevenue) * 100, 1);
} elseif ($currRevenue > 0) {
    $revenueChangePercent = 100;
}

$ordersChange = $pdo->prepare("
    SELECT COUNT(*) AS cnt
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
      AND created_at >= ? AND created_at < ?
");
$ordersChange->execute([$fourteenDaysAgo, $sevenDaysAgo]);
$prevOrders = (int) $ordersChange->fetchColumn();
$ordersChange->execute([$sevenDaysAgo, $today]);
$currOrders = (int) $ordersChange->fetchColumn();
$ordersChangePercent = 0;
if ($prevOrders > 0) {
    $ordersChangePercent = round((($currOrders - $prevOrders) / $prevOrders) * 100, 1);
} elseif ($currOrders > 0) {
    $ordersChangePercent = 100;
}

echo json_encode([
    'total_revenue'           => (float) $stats['total_revenue'],
    'total_orders'            => (int)   $stats['total_orders'],
    'avg_order_value'         => (float) $stats['avg_order_value'],
    'conversion_rate'         => $conversionRate,
    'revenue_change_percent'  => $revenueChangePercent,
    'orders_change_percent'   => $ordersChangePercent,
]);