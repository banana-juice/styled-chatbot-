<?php
// ============================================================
// PRODUCT REVIEWS (admin moderation) — php/admin/reviews.php
//
//   GET     ?status=&rating=&search=&page=&limit=   list every review
//   PUT     {review_id, status: visible|hidden}     hide / show a review
//   DELETE  {review_id}                             delete a review
// ============================================================

header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ini_set('display_errors', '0');
header('Content-Type: application/json');
require_once __DIR__ . '/_auth.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../reviews_lib.php';

requireAuth('admin');
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = getPDO();
    reviews_ensure_schema($pdo);

    if ($method === 'GET') {
        $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
        $rating = reviews_parse_rating($_GET['rating'] ?? null);
        $search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
        $page   = max(1, as_pos_int($_GET['page'] ?? 1) ?: 1);
        $limit  = min(50, max(1, as_pos_int($_GET['limit'] ?? 20) ?: 20));
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];
        if (in_array($status, ['visible', 'hidden'], true)) {
            $where[]  = 'r.status = ?';
            $params[] = $status;
        }
        if ($rating) {
            $where[]  = 'r.rating = ?';
            $params[] = $rating;
        }
        if ($search !== '') {
            $where[]  = '(p.name LIKE ? OR u.full_name LIKE ? OR r.comment LIKE ?)';
            $like     = '%' . $search . '%';
            array_push($params, $like, $like, $like);
        }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $from = "FROM product_reviews r
                 LEFT JOIN products p ON p.product_id = r.product_id
                 LEFT JOIN users u    ON u.user_id    = r.user_id
                 $whereSQL";

        $t = $pdo->prepare("SELECT COUNT(*) $from");
        $t->execute($params);
        $total = (int) $t->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT r.review_id, r.product_id, r.rating, r.comment, r.status, r.created_at,
                   COALESCE(p.name, 'Deleted product') AS product_name,
                   COALESCE(u.full_name, 'Deleted user') AS reviewer
            $from
            ORDER BY r.created_at DESC, r.review_id DESC
            LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        $counts = $pdo->query("SELECT status, COUNT(*) FROM product_reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $avg    = $pdo->query("SELECT ROUND(AVG(rating), 1) FROM product_reviews WHERE status = 'visible'")->fetchColumn();

        echo json_encode([
            'success' => true,
            'reviews' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'   => $total,
            'stats'   => [
                'visible' => (int) ($counts['visible'] ?? 0),
                'hidden'  => (int) ($counts['hidden'] ?? 0),
                'average' => $avg !== null ? (float) $avg : 0,
            ],
        ]);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true);
    $body     = is_array($body) ? $body : [];
    $reviewId = as_pos_int($body['review_id'] ?? 0);
    if ($reviewId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'review_id is required.']);
        exit;
    }

    if ($method === 'PUT') {
        $newStatus = $body['status'] ?? null;
        if (!is_string($newStatus) || !in_array($newStatus, ['visible', 'hidden'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'status must be "visible" or "hidden".']);
            exit;
        }
        $upd = $pdo->prepare('UPDATE product_reviews SET status = ? WHERE review_id = ?');
        $upd->execute([$newStatus, $reviewId]);
        $exists = $pdo->prepare('SELECT 1 FROM product_reviews WHERE review_id = ?');
        $exists->execute([$reviewId]);
        if (!$exists->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Review not found.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $del = $pdo->prepare('DELETE FROM product_reviews WHERE review_id = ?');
        $del->execute([$reviewId]);
        if ($del->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Review not found.']);
            exit;
        }
        echo json_encode(['success' => true]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);

} catch (Throwable $e) {
    error_log('admin/reviews.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error.']);
}
