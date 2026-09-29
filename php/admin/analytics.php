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

requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pdo = getPDO();

// Total revenue / orders / avg order value
$rev = $pdo->query("
    SELECT
        COALESCE(SUM(total_amount), 0) AS total_revenue,
        COUNT(*)                       AS total_orders,
        COALESCE(AVG(total_amount), 0) AS avg_order_value
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
")->fetch();

// Revenue by day (last 14 days)
$revByDay = $pdo->query("
    SELECT
        DATE(created_at)            AS date,
        SUM(total_amount)           AS revenue
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
      AND created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
    GROUP BY DATE(created_at)
    ORDER BY date ASC
")->fetchAll();

// Revenue change (last 7 days vs previous 7 days)
$revChange = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0) AS revenue
    FROM orders
    WHERE status NOT IN ('cancelled', 'refunded')
      AND created_at >= ? AND created_at < ?
");

// ── Orders by status ──────────────────────────────────────────────────────────
$byStatus = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM orders
    GROUP BY status
")->fetchAll();

$ordersByStatus = [
    'pending'    => 0,
    'processing' => 0,
    'shipped'    => 0,
    'delivered'  => 0,
    'cancelled'  => 0,
    'refunded'   => 0,
];
foreach ($byStatus as $row) {
    $key = strtolower($row['status']);
    if (isset($ordersByStatus[$key])) {
        $ordersByStatus[$key] = (int) $row['cnt'];
    }
}

// ── Sales by category ─────────────────────────────────────────────────────────
$catSales = $pdo->query("
    SELECT
        c.name                         AS category,
        SUM(oi.qty)                    AS qty_sold
    FROM order_items oi
    JOIN products p  ON p.product_id = oi.product_id
    JOIN categories c ON c.category_id = p.category_id
    JOIN orders o     ON o.order_id = oi.order_id
    WHERE o.status NOT IN ('cancelled', 'refunded')
    GROUP BY c.category_id, c.name
    ORDER BY qty_sold DESC
")->fetchAll();

$totalQty = array_sum(array_column($catSales, 'qty_sold')) ?: 1;
$salesByCategory = array_map(fn($r) => [
    'category'   => $r['category'],
    'percentage' => round(($r['qty_sold'] / $totalQty) * 100),
], $catSales);

// ── Top products ──────────────────────────────────────────────────────────────
$topProducts = $pdo->query("
    SELECT
        p.product_id,
        p.name,
        SUM(oi.qty)                         AS quantity_sold,
        SUM(oi.qty * oi.unit_price)         AS revenue
    FROM order_items oi
    JOIN products p ON p.product_id = oi.product_id
    JOIN orders o   ON o.order_id = oi.order_id
    WHERE o.status NOT IN ('cancelled', 'refunded')
    GROUP BY p.product_id, p.name
    ORDER BY quantity_sold DESC
    LIMIT 10
")->fetchAll();

// ── Conversion rate (orders / total users × 100) ─────────────────────────────
$totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
$convRate   = $totalUsers > 0
    ? round(((int) $rev['total_orders'] / $totalUsers) * 100, 2)
    : 0;

// ── Percentage changes vs previous period ─────────────────────────────────────
$today = date('Y-m-d');
$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$fourteenDaysAgo = date('Y-m-d', strtotime('-14 days'));

// Revenue current vs previous
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

// Orders current vs previous
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
    'total_revenue'           => (float) $rev['total_revenue'],
    'total_orders'            => (int)   $rev['total_orders'],
    'avg_order_value'         => (float) $rev['avg_order_value'],
    'conversion_rate'         => $convRate,
    'revenue_by_day'          => $revByDay,
    'orders_by_status'        => $ordersByStatus,
    'sales_by_category'       => $salesByCategory,
    'top_products'            => $topProducts,
    'revenue_change_percent'  => $revenueChangePercent,
    'orders_change_percent'   => $ordersChangePercent,
]);