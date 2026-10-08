<?php
// ============================================================
// PRODUCT REVIEWS (customer side) — php/reviews.php
//
//   GET    ?product_id=N[&page=1&limit=10&rating=1-5&with_comment=1&sort=newest|helpful|highest|lowest]
//                                           public: summary + visible reviews,
//                                           plus what the signed-in viewer may do
//   POST   {product_id, rating, comment}    review a product you received
//   POST   {action:"vote", review_id, helpful:true|false}
//                                           mark another customer's review helpful
//   PUT    {product_id, rating, comment}    edit your own review
//   DELETE {product_id}                     delete your own review
// ============================================================

header("Access-Control-Allow-Origin: https://styled.great-site.net");
header("Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Credentials: true");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reviews_lib.php';

header('Content-Type: application/json');

function review_out(int $status, array $payload): void {
    ob_end_clean();
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/** The decoded JSON request body, or null when it is missing / not valid JSON. */
function review_body(): ?array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : null;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    $pdo = getPDO();
    reviews_ensure_schema($pdo);

    $source = $method === 'GET' ? $_GET : review_body();
    if ($source === null) {
        review_out(400, ['success' => false, 'error' => 'The request body must be valid JSON.']);
    }

    // ── POST {action: "vote"} — "Helpful" on someone else's review ─────────────
    if ($method === 'POST' && ($source['action'] ?? null) === 'vote') {
        if (!$userId) {
            review_out(401, ['success' => false, 'error' => 'Please sign in to mark reviews as helpful.']);
        }
        if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff'], true)) {
            review_out(403, ['success' => false, 'error' => 'Staff accounts cannot vote on reviews.']);
        }
        $reviewId = as_pos_int($source['review_id'] ?? 0);
        $helpful  = $source['helpful'] ?? null;
        if ($reviewId <= 0 || !is_bool($helpful)) {
            review_out(400, ['success' => false, 'error' => 'review_id and helpful (true/false) are required.']);
        }
        $row = $pdo->prepare("
            SELECT r.user_id
            FROM product_reviews r
            JOIN products p ON p.product_id = r.product_id
            WHERE r.review_id = ? AND r.status = 'visible' AND p.status = 'active' AND p.is_active = 1");
        $row->execute([$reviewId]);
        $owner = $row->fetchColumn();
        if ($owner === false) {
            review_out(404, ['success' => false, 'error' => 'Review not found.']);
        }
        if ((int) $owner === $userId) {
            review_out(403, ['success' => false, 'error' => "You can't mark your own review as helpful."]);
        }
        if ($helpful) {
            $pdo->prepare('INSERT IGNORE INTO product_review_votes (review_id, user_id) VALUES (?, ?)')->execute([$reviewId, $userId]);
        } else {
            $pdo->prepare('DELETE FROM product_review_votes WHERE review_id = ? AND user_id = ?')->execute([$reviewId, $userId]);
        }
        $cnt = $pdo->prepare('SELECT COUNT(*) FROM product_review_votes WHERE review_id = ?');
        $cnt->execute([$reviewId]);
        review_out(200, ['success' => true, 'helpful_count' => (int) $cnt->fetchColumn(), 'i_voted' => $helpful]);
    }

    $productId = as_pos_int($source['product_id'] ?? 0);
    if ($productId <= 0) {
        review_out(400, ['success' => false, 'error' => 'product_id is required.']);
    }

    $prod = $pdo->prepare("SELECT product_id FROM products WHERE product_id = ? AND status = 'active' AND is_active = 1");
    $prod->execute([$productId]);
    if (!$prod->fetchColumn()) {
        review_out(404, ['success' => false, 'error' => 'Product not found.']);
    }

    // The viewer's own review (if any), used by every verb below.
    $mine = null;
    if ($userId) {
        $q = $pdo->prepare('SELECT review_id, rating, comment, size, status, seller_reply, seller_reply_at, created_at, updated_at FROM product_reviews WHERE user_id = ? AND product_id = ?');
        $q->execute([$userId, $productId]);
        $mine = $q->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($mine) {
            $mine['review_id'] = (int) $mine['review_id'];
            $mine['rating']    = (int) $mine['rating'];
        }
    }

    // ── GET ──────────────────────────────────────────────────────────────────
    if ($method === 'GET') {
        $page   = max(1, as_pos_int($_GET['page'] ?? 1) ?: 1);
        $limit  = min(50, max(1, as_pos_int($_GET['limit'] ?? 10) ?: 10));
        $offset = ($page - 1) * $limit;

        $rating      = reviews_parse_rating($_GET['rating'] ?? null);        // 0 = every star level
        $withComment = ($_GET['with_comment'] ?? '') === '1';
        $sort        = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'newest';
        $orderBy     = reviews_order_by($sort);

        $summary = reviews_summary($pdo, $productId);

        $where  = "r.product_id = ? AND r.status = 'visible'";
        $params = [$productId];
        if ($rating) {
            $where   .= ' AND r.rating = ?';
            $params[] = $rating;
        }
        if ($withComment) {
            $where .= " AND r.comment IS NOT NULL AND r.comment <> ''";
        }

        $tot = $pdo->prepare("SELECT COUNT(*) FROM product_reviews r WHERE $where");
        $tot->execute($params);
        $matching = (int) $tot->fetchColumn();

        $list = $pdo->prepare("
            SELECT r.review_id, r.user_id, r.rating, r.comment, r.size, r.created_at,
                   r.seller_reply, r.seller_reply_at, u.full_name,
                   (SELECT COUNT(*) FROM product_review_votes v WHERE v.review_id = r.review_id) AS helpful_count,
                   (SELECT COUNT(*) FROM product_review_votes v2 WHERE v2.review_id = r.review_id AND v2.user_id = ?) AS i_voted
            FROM product_reviews r
            LEFT JOIN users u ON u.user_id = r.user_id
            WHERE $where
            ORDER BY $orderBy
            LIMIT $limit OFFSET $offset");
        $list->execute(array_merge([$userId], $params));
        $reviews = [];
        foreach ($list->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $reviews[] = [
                'review_id'       => (int) $r['review_id'],
                'rating'          => (int) $r['rating'],
                'comment'         => (string) ($r['comment'] ?? ''),
                'size'            => (string) ($r['size'] ?? ''),
                'created_at'      => $r['created_at'],
                'reviewer'        => reviews_display_name($r['full_name']),
                'verified'        => true, // only customers with a delivered order can review
                'mine'            => $userId && (int) $r['user_id'] === $userId,
                'helpful_count'   => (int) $r['helpful_count'],
                'i_voted'         => (int) $r['i_voted'] > 0,
                'seller_reply'    => (string) ($r['seller_reply'] ?? ''),
                'seller_reply_at' => $r['seller_reply_at'],
            ];
        }

        $eligible = $userId ? reviews_eligible_order($pdo, $userId, $productId) > 0 : false;
        $openOrder = ($userId && !$eligible && !$mine) ? reviews_has_open_order($pdo, $userId, $productId) : false;
        review_out(200, [
            'success'      => true,
            'summary'      => $summary,
            'reviews'      => $reviews,
            'page'         => $page,
            'matching'     => $matching,
            'has_more'     => $offset + count($reviews) < $matching,
            'filter'       => ['rating' => $rating, 'with_comment' => $withComment, 'sort' => $sort],
            'viewer'       => [
                'logged_in'  => (bool) $userId,
                'can_review' => $eligible && !$mine,
                'purchased'  => $eligible,
                'has_open_order' => $openOrder,
                'my_review'  => $mine,
            ],
        ]);
    }

    // ── Everything below needs a signed-in customer ───────────────────────────
    if (!$userId) {
        review_out(401, ['success' => false, 'error' => 'Please sign in to review products.']);
    }
    if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'staff'], true)) {
        review_out(403, ['success' => false, 'error' => 'Staff accounts cannot post reviews.']);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $body   = $source;
        $rating = reviews_parse_rating($body['rating'] ?? null);
        if ($rating === 0) {
            review_out(400, ['success' => false, 'error' => 'Please choose a rating from 1 to 5 stars.']);
        }
        $comment = reviews_clean_comment($body['comment'] ?? null);
        if ($comment === null) {
            review_out(400, ['success' => false, 'error' => 'Your comment must be text of at most ' . REVIEW_COMMENT_MAX . ' characters.']);
        }

        if ($method === 'POST') {
            if ($mine) {
                review_out(409, ['success' => false, 'error' => 'You have already reviewed this product. You can edit your review.']);
            }
            $orderId = reviews_eligible_order($pdo, $userId, $productId);
            if ($orderId <= 0) {
                review_out(403, ['success' => false, 'error' => 'Only customers who have received this product can review it.']);
            }
            $size = reviews_order_item_size($pdo, $orderId, $productId);
            try {
                $pdo->prepare('INSERT INTO product_reviews (product_id, user_id, order_id, size, rating, comment) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$productId, $userId, $orderId, $size !== '' ? $size : null, $rating, $comment]);
            } catch (PDOException $e) {
                if ((string) $e->getCode() === '23000') { // double submit raced past the check above
                    review_out(409, ['success' => false, 'error' => 'You have already reviewed this product. You can edit your review.']);
                }
                throw $e;
            }
            review_out(201, ['success' => true, 'summary' => reviews_summary($pdo, $productId)]);
        }

        // PUT — edit own review. An admin-hidden review stays hidden after an edit.
        if (!$mine) {
            review_out(404, ['success' => false, 'error' => 'You have not reviewed this product yet.']);
        }
        $pdo->prepare('UPDATE product_reviews SET rating = ?, comment = ? WHERE review_id = ? AND user_id = ?')
            ->execute([$rating, $comment, $mine['review_id'], $userId]);
        review_out(200, ['success' => true, 'summary' => reviews_summary($pdo, $productId)]);
    }

    if ($method === 'DELETE') {
        if ($mine) {
            $pdo->prepare('DELETE FROM product_review_votes WHERE review_id = ?')->execute([$mine['review_id']]);
        }
        $pdo->prepare('DELETE FROM product_reviews WHERE user_id = ? AND product_id = ?')->execute([$userId, $productId]);
        review_out(200, ['success' => true, 'summary' => reviews_summary($pdo, $productId)]);
    }

    review_out(405, ['success' => false, 'error' => 'Method not allowed.']);

} catch (Throwable $e) {
    error_log('reviews.php: ' . $e->getMessage());
    review_out(500, ['success' => false, 'error' => 'Server error.']);
}
