<?php
// ============================================================
// PRODUCT REVIEWS (admin moderation) — php/admin/reviews.php
//
//   GET     ?status=&rating=&search=&page=&limit=   list every review
//   PUT     {review_id, status?: visible|hidden, reply?: "text"|""}
//                                                   hide/show a review and/or set
//                                                   (or clear, with "") the store's reply
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

function admin_review_fail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

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
            SELECT r.review_id, r.product_id, r.rating, r.comment, r.size, r.status, r.created_at,
                   r.seller_reply, r.seller_reply_at,
                   (SELECT COUNT(*) FROM product_review_votes v WHERE v.review_id = r.review_id) AS helpful_count,
                   COALESCE(p.name, 'Deleted product') AS product_name,
                   COALESCE(u.full_name, 'Deleted user') AS reviewer
            $from
            ORDER BY r.created_at DESC, r.review_id DESC
            LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        $counts = $pdo->query("SELECT status, COUNT(*) FROM product_reviews GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
        $avg    = $pdo->query("SELECT ROUND(AVG(rating), 1) FROM product_reviews WHERE status = 'visible'")->fetchColumn();
        $unans  = (int) $pdo->query("SELECT COUNT(*) FROM product_reviews WHERE status = 'visible' AND (seller_reply IS NULL OR seller_reply = '')")->fetchColumn();

        echo json_encode([
            'success' => true,
            'reviews' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total'   => $total,
            'stats'   => [
                'visible'    => (int) ($counts['visible'] ?? 0),
                'hidden'     => (int) ($counts['hidden'] ?? 0),
                'average'    => $avg !== null ? (float) $avg : 0,
                'unanswered' => $unans,
            ],
        ]);
        exit;
    }

    $body     = json_decode(file_get_contents('php://input'), true);
    $body     = is_array($body) ? $body : [];
    $reviewId = as_pos_int($body['review_id'] ?? 0);
    if ($reviewId <= 0) {
        admin_review_fail(400, 'review_id is required.');
    }

    if ($method === 'PUT') {
        $hasStatus = array_key_exists('status', $body);
        $hasReply  = array_key_exists('reply', $body);
        if (!$hasStatus && !$hasReply) {
            admin_review_fail(400, 'status must be "visible" or "hidden" (or send a reply).');
        }
        $newStatus = null;
        if ($hasStatus) {
            $newStatus = $body['status'];
            if (!is_string($newStatus) || !in_array($newStatus, ['visible', 'hidden'], true)) {
                admin_review_fail(400, 'status must be "visible" or "hidden".');
            }
        }
        $reply = null;
        if ($hasReply) {
            $reply = reviews_clean_comment($body['reply'], REVIEW_REPLY_MAX);
            if ($reply === null) {
                admin_review_fail(400, 'The reply must be text of at most ' . REVIEW_REPLY_MAX . ' characters.');
            }
        }

        $exists = $pdo->prepare('SELECT 1 FROM product_reviews WHERE review_id = ?');
        $exists->execute([$reviewId]);
        if (!$exists->fetchColumn()) {
            admin_review_fail(404, 'Review not found.');
        }
        if ($hasStatus) {
            $pdo->prepare('UPDATE product_reviews SET status = ? WHERE review_id = ?')->execute([$newStatus, $reviewId]);
        }
        if ($hasReply) {
            if ($reply === '') {
                $pdo->prepare('UPDATE product_reviews SET seller_reply = NULL, seller_reply_at = NULL WHERE review_id = ?')->execute([$reviewId]);
            } else {
                $pdo->prepare('UPDATE product_reviews SET seller_reply = ?, seller_reply_at = NOW() WHERE review_id = ?')->execute([$reply, $reviewId]);
            }
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($method === 'DELETE') {
        $pdo->prepare('DELETE FROM product_review_votes WHERE review_id = ?')->execute([$reviewId]);
        $del = $pdo->prepare('DELETE FROM product_reviews WHERE review_id = ?');
        $del->execute([$reviewId]);
        if ($del->rowCount() === 0) {
            admin_review_fail(404, 'Review not found.');
        }
        echo json_encode(['success' => true]);
        exit;
    }

    admin_review_fail(405, 'Method not allowed.');

} catch (Throwable $e) {
    error_log('admin/reviews.php: ' . $e->getMessage());
    admin_review_fail(500, 'Server error.');
}
