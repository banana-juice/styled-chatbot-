<?php
// ============================================================
// PRODUCT REVIEWS — shared helpers (php/reviews_lib.php)
//
// Rules the review system enforces:
//   * Only a customer who actually RECEIVED the product (one of their orders
//     containing it has status "delivered") can review it. Every review is
//     therefore a "Verified Purchase", and records the size they received.
//   * One review per customer per product (UNIQUE key); they can edit or
//     delete their own.
//   * Rating is a whole number 1-5; the comment is optional text, max 1000.
//   * Other customers can mark a review "Helpful" (one vote each, never on
//     their own review).
//   * The store can reply to a review ("Seller Response"), hide it (it stops
//     counting and showing) or delete it.
// ============================================================

if (!defined('REVIEW_COMMENT_MAX')) {
    define('REVIEW_COMMENT_MAX', 1000);
}
if (!defined('REVIEW_REPLY_MAX')) {
    define('REVIEW_REPLY_MAX', 500);
}

/**
 * Create / upgrade the review tables, so deploying the code never needs a manual
 * SQL step (database/migration-reviews.sql is provided for the same).
 * DDL commits implicitly: never call this inside a transaction.
 */
function reviews_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_reviews (
            review_id  INT AUTO_INCREMENT PRIMARY KEY,
            product_id INT NOT NULL,
            user_id    INT NOT NULL,
            order_id   INT NULL,
            rating     TINYINT NOT NULL,
            comment    TEXT NULL,
            status     ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_review_user_product (user_id, product_id),
            KEY idx_review_product (product_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Columns added after the first release: add them only when missing. Right after
    // a deploy several requests can arrive at once, all see the column missing, and
    // all try to add it; the losers get MySQL error 1060 "Duplicate column name",
    // which just means someone else already did it, so it is ignored.
    $have = $pdo->query('SHOW COLUMNS FROM product_reviews')->fetchAll(PDO::FETCH_COLUMN);
    $upgrades = [
        'size'            => 'ALTER TABLE product_reviews ADD COLUMN size VARCHAR(10) NULL AFTER order_id',
        'seller_reply'    => 'ALTER TABLE product_reviews ADD COLUMN seller_reply TEXT NULL',
        'seller_reply_at' => 'ALTER TABLE product_reviews ADD COLUMN seller_reply_at DATETIME NULL',
    ];
    foreach ($upgrades as $column => $ddl) {
        if (in_array($column, $have, true)) {
            continue;
        }
        try {
            $pdo->exec($ddl);
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                throw $e;
            }
        }
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS product_review_votes (
            review_id  INT NOT NULL,
            user_id    INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (review_id, user_id),
            KEY idx_vote_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/** "Joseph Gabriel Lontoc" -> "Joseph L." (reviews never expose full names or emails). */
function reviews_display_name(?string $fullName): string {
    $parts = preg_split('/\s+/', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);
    if (!$parts) {
        return 'Customer';
    }
    $first = $parts[0];
    if (count($parts) > 1) {
        $last = end($parts);
        return $first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.';
    }
    return $first;
}

/** A rating from user input: 1-5 as an int or digit string, else 0. */
function reviews_parse_rating($v): int {
    if (is_int($v)) {
        return ($v >= 1 && $v <= 5) ? $v : 0;
    }
    if (is_string($v) && preg_match('/^[1-5]$/', $v)) {
        return (int) $v;
    }
    return 0;
}

/**
 * Clean free text (a review comment or a store reply), or null when it is
 * invalid (not text / too long). Empty is fine and becomes ''. Control
 * characters are dropped, newlines kept.
 */
function reviews_clean_comment($v, int $max = REVIEW_COMMENT_MAX): ?string {
    if ($v === null) {
        return '';
    }
    if (!is_string($v)) {
        return null;
    }
    $v = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", ' '], $v);
    // Drop control codes (keeping newlines) and characters that can spoof or hide
    // text: bidi overrides/isolates, zero-width space, word joiner, BOM, soft hyphen.
    // Joiners (U+200D / U+200C) are kept: emoji families and Persian/Indic words need them.
    $v = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]|[\x{80}-\x{9F}]|[\x{00AD}\x{200B}\x{2060}\x{FEFF}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $v);
    if ($v === null) {
        return null; // invalid UTF-8
    }
    $v = trim($v);
    if (mb_strlen($v) > $max) {
        return null;
    }
    return $v;
}

/** Average / count / per-star distribution / with-comment count of the VISIBLE reviews of a product. */
function reviews_summary(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare("
        SELECT rating, COUNT(*) AS n, SUM(comment IS NOT NULL AND comment <> '') AS with_comment
        FROM product_reviews
        WHERE product_id = ? AND status = 'visible'
        GROUP BY rating");
    $stmt->execute([$productId]);
    $dist         = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $count        = 0;
    $sum          = 0;
    $withComments = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dist[(int) $row['rating']] = (int) $row['n'];
        $count        += (int) $row['n'];
        $sum          += (int) $row['rating'] * (int) $row['n'];
        $withComments += (int) $row['with_comment'];
    }
    return [
        'average'       => $count ? round($sum / $count, 1) : 0,
        'count'         => $count,
        'distribution'  => $dist,
        'with_comments' => $withComments,
    ];
}

/** The newest delivered order of this customer that contains the product, or 0. */
function reviews_eligible_order(PDO $pdo, int $userId, int $productId): int {
    $stmt = $pdo->prepare("
        SELECT o.order_id
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.order_id
        WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'
        ORDER BY o.order_id DESC
        LIMIT 1");
    $stmt->execute([$userId, $productId]);
    return (int) $stmt->fetchColumn();
}

/** The size the customer received in that order for that product ('' when none). */
function reviews_order_item_size(PDO $pdo, int $orderId, int $productId): string {
    $stmt = $pdo->prepare('SELECT size FROM order_items WHERE order_id = ? AND product_id = ? ORDER BY item_id LIMIT 1');
    $stmt->execute([$orderId, $productId]);
    return (string) ($stmt->fetchColumn() ?: '');
}

/** True when the customer has an order with this product that is on its way (not delivered, not cancelled/refunded). */
function reviews_has_open_order(PDO $pdo, int $userId, int $productId): bool {
    $stmt = $pdo->prepare("
        SELECT 1
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.order_id
        WHERE o.user_id = ? AND oi.product_id = ?
          AND o.status NOT IN ('delivered', 'cancelled', 'refunded')
        LIMIT 1");
    $stmt->execute([$userId, $productId]);
    return (bool) $stmt->fetchColumn();
}

/** A whitelisted ORDER BY for the review list (user input never reaches the SQL). */
function reviews_order_by(string $sort): string {
    switch ($sort) {
        case 'helpful':
            return 'helpful_count DESC, r.created_at DESC, r.review_id DESC';
        case 'highest':
            return 'r.rating DESC, r.created_at DESC, r.review_id DESC';
        case 'lowest':
            return 'r.rating ASC, r.created_at DESC, r.review_id DESC';
        default:
            return 'r.created_at DESC, r.review_id DESC';
    }
}
