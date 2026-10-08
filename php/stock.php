<?php
// ============================================================
// STOCK + ORDER LIFECYCLE HELPERS — php/stock.php
//
// Invariant this file maintains: an order "holds" stock from the moment it
// is placed (php/checkout.php) until it is either fulfilled or released.
// Every change to product_sizes.stock_qty made on behalf of an order is
// written to stock_adjustments, and that log is the single source of truth
// for how many units an order currently holds. Releasing is therefore
// idempotent: it restores exactly (reserved - already released) units, so a
// replayed webhook, a double-clicked cancel, or the stale-hold sweeper
// racing a customer cancel can never restore stock twice.
//
// Unpaid card/GCash orders do not hold stock forever: after
// STOCK_HOLD_MINUTES they are cancelled and their units go back on sale.
// ============================================================

if (!defined('STOCK_HOLD_MINUTES')) {
    define('STOCK_HOLD_MINUTES', 30);
}

/**
 * Create/upgrade the tables and columns this feature needs. Safe to call on
 * every request (cheap, one-time per request) and safe on a database that has
 * already been migrated — so deploying the code never requires a manual SQL
 * step, though database/migration-qa-fixes.sql is provided for the same.
 * DDL implicitly commits in MySQL, so never call this inside a transaction.
 */
function stock_ensure_schema(PDO $pdo): void {
    require_once __DIR__ . '/schema_flags.php';
    // Runs once (then costs a single indexed SELECT per request, not a dozen queries).
    // Rename 'stock_v1' to ship another change to these tables.
    schema_once($pdo, 'stock_v1', function () use ($pdo) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS stock_adjustments (
                adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
                product_id    INT NOT NULL,
                size          VARCHAR(10) NOT NULL DEFAULT '',
                delta         INT NOT NULL,
                qty_before    INT NULL,
                qty_after     INT NULL,
                reason        VARCHAR(40) NOT NULL,
                order_id      INT NULL,
                user_id       INT NULL,
                note          VARCHAR(255) NULL,
                created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_adj_order (order_id),
                INDEX idx_adj_product (product_id, size),
                INDEX idx_adj_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $wanted = [
            'orders' => [
                'failed_at'    => 'DATETIME NULL',
                'cancelled_at' => 'DATETIME NULL',
                'updated_at'   => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ],
            // when we last asked PayMongo whether this payment went through
            'payment_transactions' => ['checked_at' => 'DATETIME NULL'],
            // so a saved address can carry the customer's phone for next time
            'addresses' => ['phone' => 'VARCHAR(30) NULL'],
        ];
        foreach ($wanted as $table => $cols) {
            $existing = $pdo->query(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table)
            )->fetchAll(PDO::FETCH_COLUMN);
            $existing = array_map('strtolower', $existing);
            foreach ($cols as $col => $ddl) {
                if (in_array($col, $existing, true)) {
                    continue;
                }
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $ddl");
                } catch (PDOException $e) {
                    // Another request added it first (error 1060): that is fine.
                    if ((int) ($e->errorInfo[1] ?? 0) !== 1060) {
                        throw $e;
                    }
                }
            }
        }
    });
}

/** Append one row to the stock audit log. */
function stock_log(PDO $pdo, int $productId, string $size, int $delta, ?int $before, ?int $after,
                   string $reason, ?int $orderId = null, ?int $userId = null, ?string $note = null): void {
    $pdo->prepare(
        'INSERT INTO stock_adjustments
            (product_id, size, delta, qty_before, qty_after, reason, order_id, user_id, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$productId, $size, $delta, $before, $after, $reason, $orderId, $userId, $note]);
}

/**
 * Units an order currently holds, per product/size, according to the log.
 * Returns [ ['product_id'=>..,'size'=>..,'held'=>..], ... ] with held > 0 only.
 */
function stock_held_by_order(PDO $pdo, int $orderId): array {
    $stmt = $pdo->prepare(
        'SELECT product_id, size, -SUM(delta) AS held
         FROM stock_adjustments
         WHERE order_id = ? AND reason IN (\'order_reserve\', \'order_release\')
         GROUP BY product_id, size
         HAVING -SUM(delta) > 0'
    );
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Give an order's held units back to the shelf. Idempotent. Also hands the
 * promo-code use back. Caller must already be inside a transaction.
 *
 * @return int number of units restored (0 if the order held nothing)
 */
function stock_release_order(PDO $pdo, int $orderId, string $reason, ?int $userId = null): int {
    // Serialise concurrent releases of the same order (customer cancel vs
    // sweeper vs webhook): whoever gets the row lock first does the work,
    // the others then see held = 0 and do nothing.
    $lock = $pdo->prepare('SELECT promo_id FROM orders WHERE order_id = ? FOR UPDATE');
    $lock->execute([$orderId]);
    $promoId = $lock->fetchColumn();

    $restored = 0;
    foreach (stock_held_by_order($pdo, $orderId) as $row) {
        $held = (int) $row['held'];
        $sel = $pdo->prepare('SELECT stock_qty FROM product_sizes WHERE product_id = ? AND size = ? FOR UPDATE');
        $sel->execute([$row['product_id'], $row['size']]);
        $before = $sel->fetchColumn();
        if ($before === false) {
            continue; // size row was deleted by an admin; nothing to restore into
        }
        $pdo->prepare('UPDATE product_sizes SET stock_qty = stock_qty + ? WHERE product_id = ? AND size = ?')
            ->execute([$held, $row['product_id'], $row['size']]);
        stock_log($pdo, (int) $row['product_id'], $row['size'], $held, (int) $before, (int) $before + $held,
                  'order_release', $orderId, $userId, $reason);
        $restored += $held;
    }

    if ($restored > 0 && $promoId) {
        $pdo->prepare('UPDATE promotions SET usage_count = GREATEST(usage_count - 1, 0) WHERE promo_id = ?')
            ->execute([$promoId]);
    }
    return $restored;
}

/**
 * Re-take stock for an order whose hold was released but which then turned
 * out to be paid (customer completed PayMongo checkout after the hold
 * expired). All-or-nothing. Caller must be inside a transaction.
 */
function stock_rereserve_order(PDO $pdo, int $orderId, string $note = 'paid after hold expired'): bool {
    $wasReleased = $pdo->prepare(
        'SELECT 1 FROM stock_adjustments WHERE order_id = ? AND reason = \'order_release\' LIMIT 1'
    );
    $wasReleased->execute([$orderId]);
    if (!$wasReleased->fetchColumn() || stock_held_by_order($pdo, $orderId)) {
        return true; // never released (or still held): nothing to re-take
    }

    $items = $pdo->prepare('SELECT product_id, size, qty FROM order_items WHERE order_id = ?');
    $items->execute([$orderId]);
    $lines = $items->fetchAll(PDO::FETCH_ASSOC);

    // Check first so a shortfall on line 2 doesn't leave line 1 half-taken.
    $check = $pdo->prepare('SELECT stock_qty FROM product_sizes WHERE product_id = ? AND size = ? FOR UPDATE');
    foreach ($lines as $l) {
        $check->execute([$l['product_id'], $l['size']]);
        $have = $check->fetchColumn();
        if ($have === false || (int) $have < (int) $l['qty']) {
            return false;
        }
    }
    foreach ($lines as $l) {
        $check->execute([$l['product_id'], $l['size']]);
        $before = (int) $check->fetchColumn();
        $pdo->prepare('UPDATE product_sizes SET stock_qty = stock_qty - ? WHERE product_id = ? AND size = ?')
            ->execute([$l['qty'], $l['product_id'], $l['size']]);
        stock_log($pdo, (int) $l['product_id'], $l['size'], -(int) $l['qty'], $before, $before - (int) $l['qty'],
                  'order_reserve', $orderId, null, $note);
    }
    $promo = $pdo->prepare('SELECT promo_id FROM orders WHERE order_id = ?');
    $promo->execute([$orderId]);
    if ($promoId = $promo->fetchColumn()) {
        $pdo->prepare('UPDATE promotions SET usage_count = usage_count + 1 WHERE promo_id = ?')->execute([$promoId]);
    }
    return true;
}

/**
 * May the customer reopen this cancelled order to pay for it? Only when it
 * was cancelled by the payment side (hold ran out, session expired, or they
 * backed out of PayMongo) and never when staff cancelled it on purpose.
 */
function order_can_reopen_for_payment(PDO $pdo, int $orderId): bool {
    $q = $pdo->prepare(
        "SELECT note FROM stock_adjustments
         WHERE order_id = ? AND reason = 'order_release'
         ORDER BY adjustment_id DESC LIMIT 1"
    );
    $q->execute([$orderId]);
    return in_array($q->fetchColumn(), ['hold_expired', 'payment_expired', 'customer_cancelled'], true);
}

/** Add a row to the customer-visible order timeline. */
function order_timeline_add(PDO $pdo, int $orderId, string $label, ?string $note = null): void {
    // order_timeline.status is NOT NULL with no default; supply it explicitly
    // rather than depending on the server's SQL mode to fill one in.
    $status = ['Processing' => 'processing', 'Shipped' => 'shipped', 'Delivered' => 'delivered'][$label] ?? 'pending';
    $pdo->prepare('INSERT INTO order_timeline (order_id, status, step_label, occurred_at, note) VALUES (?, ?, ?, NOW(), ?)')
        ->execute([$orderId, $status, $label, $note]);
}

/**
 * Cancel an order and put its stock back on sale, atomically. Idempotent:
 * a second call on an already-cancelled order is a no-op.
 *
 * @param string|null $paymentStatus new payment_status ('failed'|'cancelled'),
 *                                   or null to leave it untouched (admin cancel)
 * @return bool true if this call performed the cancellation
 */
function order_cancel_and_release(PDO $pdo, int $orderId, ?string $paymentStatus, string $reason,
                                  string $timelineNote, ?int $userId = null): bool {
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $cur = $pdo->prepare('SELECT status, payment_status FROM orders WHERE order_id = ? FOR UPDATE');
        $cur->execute([$orderId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        // A payment-side cancel (failed/expired/cancelled by the customer) must never
        // touch an order that has actually been paid; an admin cancel ($paymentStatus
        // null) is allowed on any not-yet-cancelled order.
        $alreadyPaidPaymentSideCancel = ($row && $row['payment_status'] === 'paid' && $paymentStatus !== null);
        if (!$row || $row['status'] === 'cancelled' || $alreadyPaidPaymentSideCancel) {
            if ($own) {
                $pdo->rollBack();
            }
            return false;
        }

        $sets   = ['status = \'cancelled\'', 'cancelled_at = NOW()'];
        if ($paymentStatus !== null) {
            $sets[] = 'payment_status = ' . $pdo->quote($paymentStatus);
            if ($paymentStatus === 'failed') {
                $sets[] = 'failed_at = NOW()';
            }
        }
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE order_id = ?')->execute([$orderId]);

        $restored = stock_release_order($pdo, $orderId, $reason, $userId);
        order_timeline_add($pdo, $orderId, 'Cancelled',
            $timelineNote . ($restored > 0 ? " ($restored unit(s) returned to stock)" : ''));

        if ($own) {
            $pdo->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * The hold clock is orders.updated_at (the last real change to the order),
 * not created_at: created_at never moves, so an order reopened for a retry
 * keeps its original queue position yet still gets a fresh hold window.
 *
 * Cancel unpaid online-payment orders whose stock hold has run out, so
 * abandoned carts can't keep units off sale forever. Called lazily from the
 * public/admin read paths and from checkout, so released stock is available
 * to the next customer straight away without needing a cron job.
 */
function stock_release_stale_holds(PDO $pdo): int {
    static $ran = false;
    if ($ran) {
        return 0;
    }
    $ran = true;

    $n = 0;
    try {
        $stmt = $pdo->prepare(
            "SELECT order_id FROM orders
             WHERE payment_method IN ('card', 'gcash')
               AND payment_status IN ('unpaid', 'processing')
               AND status = 'pending'
               AND updated_at < (NOW() - INTERVAL " . (int) STOCK_HOLD_MINUTES . " MINUTE)
             ORDER BY updated_at ASC
             LIMIT 50"
        );
        $stmt->execute();
        require_once __DIR__ . '/payments.php';
        $asked = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $oid) {
            // Before giving up on an unpaid order, ask PayMongo whether the
            // customer actually paid (the webhook may not have reached us).
            // Paid orders are never cancelled by this sweep.
            if ($asked < 5) {
                $asked++;
                if (payment_reconcile_order($pdo, (int) $oid) === 'paid') {
                    continue;
                }
            }
            if (order_cancel_and_release($pdo, (int) $oid, 'failed', 'hold_expired',
                    'Payment was not completed in time; the order was released.')) {
                $n++;
            }
        }
    } catch (Throwable $e) {
        error_log('stock_release_stale_holds: ' . $e->getMessage());
    }
    return $n;
}
