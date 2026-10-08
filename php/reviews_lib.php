<?php
// ============================================================
// PRODUCT REVIEWS — shared helpers (php/reviews_lib.php)
//
// Rules the review system enforces:
//   * Only a customer who actually RECEIVED the product (one of their orders
//     containing it has status "delivered") can review it.
//   * One review per customer per product (UNIQUE key); they can edit or
//     delete their own.
//   * Rating is a whole number 1-5; the comment is optional text, max 1000.
//   * Staff can hide a review (it stops counting and showing) or delete it.
// ============================================================

if (!defined('REVIEW_COMMENT_MAX')) {
    define('REVIEW_COMMENT_MAX', 1000);
}

/**
 * Create the reviews table if it is missing, so deploying the code never needs
 * a manual SQL step (database/migration-reviews.sql is provided for the same).
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
 * A clean comment, or null when it is invalid (not text / too long).
 * Empty is fine and becomes ''. Control characters are dropped, newlines kept.
 */
function reviews_clean_comment($v): ?string {
    if ($v === null) {
        return '';
    }
    if (!is_string($v)) {
        return null;
    }
    $v = preg_replace('/[^\P{C}\n]/u', '', str_replace(["\r\n", "\r"], "\n", $v));
    if ($v === null) {
        return null; // invalid UTF-8
    }
    $v = trim($v);
    if (mb_strlen($v) > REVIEW_COMMENT_MAX) {
        return null;
    }
    return $v;
}

/** Average / count / per-star distribution of the VISIBLE reviews of a product. */
function reviews_summary(PDO $pdo, int $productId): array {
    $stmt = $pdo->prepare("
        SELECT rating, COUNT(*) AS n
        FROM product_reviews
        WHERE product_id = ? AND status = 'visible'
        GROUP BY rating");
    $stmt->execute([$productId]);
    $dist  = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
    $count = 0;
    $sum   = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dist[(int) $row['rating']] = (int) $row['n'];
        $count += (int) $row['n'];
        $sum   += (int) $row['rating'] * (int) $row['n'];
    }
    return [
        'average'      => $count ? round($sum / $count, 1) : 0,
        'count'        => $count,
        'distribution' => $dist,
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
