<?php
// ============================================================
// PAYMENT STATE — php/payments.php
//
// The single place that turns an order into "paid". Two things call it:
//
//   1. php/paymongo_webhook.php — PayMongo tells us a payment succeeded.
//   2. payment_reconcile_order() — WE ask PayMongo "was this session paid?"
//      whenever a customer or admin looks at an order that is still waiting.
//
// (2) means an order turns Paid on its own even if the webhook is late,
// missing, mis-registered, or blocked by the host. It is safe because the
// answer comes from PayMongo's API using our secret key, never from the
// browser: opening orders.html?payment=success proves nothing by itself.
// ============================================================

require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/paymongo_client.php';

/**
 * Mark an order paid. Idempotent and race-safe: the row is locked, and only the
 * call that actually flips unpaid -> paid returns true (and sends the email).
 */
function payment_mark_paid(PDO $pdo, int $orderId, string $source = 'webhook'): bool {
    stock_ensure_schema($pdo);

    $pdo->beginTransaction();
    $lock = $pdo->prepare('SELECT status, payment_status FROM orders WHERE order_id = ? FOR UPDATE');
    $lock->execute([$orderId]);
    $fresh = $lock->fetch(PDO::FETCH_ASSOC);
    if (!$fresh || $fresh['payment_status'] === 'paid') {
        $pdo->rollBack(); // already paid (e.g. webhook and reconcile raced) or gone
        return false;
    }

    $lateNote     = null;
    $newStatusSql = 'IF(status = "pending", "processing", status)';
    if ($fresh['status'] === 'cancelled') {
        // Paid after we had already released this order's stock (hold ran out /
        // they backed out, then paid on the old PayMongo page). The money is in,
        // so try to take the units back; if they're gone, flag a refund.
        if (stock_rereserve_order($pdo, $orderId)) {
            $newStatusSql = '"processing"';
            $lateNote = 'Payment received after the stock hold expired; stock re-reserved and order reopened.';
        } else {
            $newStatusSql = '"cancelled"';
            $lateNote = 'REFUND REQUIRED: payment received after the stock hold expired and the items are no longer available.';
        }
    }

    $pdo->prepare('
        UPDATE orders
        SET payment_status = "paid", paid_at = NOW(),
            cancelled_at = IF(' . $newStatusSql . ' = "cancelled", cancelled_at, NULL),
            failed_at = NULL,
            status = ' . $newStatusSql . '
        WHERE order_id = :oid
    ')->execute([':oid' => $orderId]);
    order_timeline_add($pdo, $orderId, 'Payment Received', $lateNote);
    $pdo->prepare('UPDATE payment_transactions SET status = "paid" WHERE order_id = ? ORDER BY transaction_id DESC LIMIT 1')
        ->execute([$orderId]);
    $pdo->commit();

    // Confirmation email — only for the call that really made the transition.
    try {
        require_once __DIR__ . '/order-confirmation-email.php';
        $info = $pdo->prepare('
            SELECT o.order_number, o.subtotal, o.shipping_fee, o.grand_total, o.payment_method,
                   u.email, u.full_name
            FROM orders o JOIN users u ON u.user_id = o.user_id WHERE o.order_id = ?');
        $info->execute([$orderId]);
        $o = $info->fetch(PDO::FETCH_ASSOC);

        $itemsStmt = $pdo->prepare('SELECT product_id, size, qty, unit_price FROM order_items WHERE order_id = ?');
        $itemsStmt->execute([$orderId]);
        $addrStmt = $pdo->prepare('
            SELECT a.street, a.city, a.province, a.zip_code
            FROM orders o JOIN addresses a ON a.address_id = o.address_id WHERE o.order_id = ?');
        $addrStmt->execute([$orderId]);

        if ($o) {
            send_order_confirmation(
                $o['email'],
                explode(' ', $o['full_name'])[0] ?? 'Valued Customer',
                $o['order_number'],
                $itemsStmt->fetchAll(PDO::FETCH_ASSOC),
                $pdo,
                (float) $o['subtotal'],
                (float) $o['shipping_fee'],
                (float) $o['grand_total'],
                $o['payment_method'],
                $addrStmt->fetch(PDO::FETCH_ASSOC) ?: []
            );
        }
    } catch (Throwable $e) {
        error_log("payment_mark_paid ($source): confirmation email failed: " . $e->getMessage());
    }
    return true;
}

/**
 * Interpret a PayMongo checkout-session payload.
 * @return array{paid:bool, expired:bool, mismatch:bool}
 */
function payment_session_state(array $session, float $expectedPhp): array {
    $a = $session['data']['attributes'] ?? [];

    $paid = false;
    $amount = null;
    foreach (($a['payments'] ?? []) as $p) {
        $pa = $p['attributes'] ?? [];
        if (($pa['status'] ?? '') === 'paid') {
            $paid = true;
            $amount = $pa['amount'] ?? null;
            break;
        }
    }
    if (!$paid && (($a['payment_intent']['attributes']['status'] ?? '') === 'succeeded')) {
        $paid = true;
        $amount = $a['payment_intent']['attributes']['amount'] ?? null;
    }
    if (!$paid && (($a['status'] ?? '') === 'paid')) {
        $paid = true;
    }

    // The amount PayMongo collected must match the order total (centavos).
    $mismatch = false;
    if ($paid && $amount !== null && abs((int) $amount - (int) round($expectedPhp * 100)) > 1) {
        $paid = false;
        $mismatch = true;
    }

    return ['paid' => $paid, 'expired' => (($a['status'] ?? '') === 'expired'), 'mismatch' => $mismatch];
}

/**
 * Ask PayMongo whether an order's checkout session was paid, and update the
 * order if so. Returns the order's payment_status afterwards, or null if it was
 * skipped (nothing to check / checked a moment ago / PayMongo unreachable).
 *
 * @param callable|null $fetch test hook: fn(string $sessionId): array
 */
function payment_reconcile_order(PDO $pdo, int $orderId, ?callable $fetch = null): ?string {
    stock_ensure_schema($pdo);

    $q = $pdo->prepare('
        SELECT o.order_id, o.payment_method, o.payment_status, o.grand_total, o.payment_reference,
               t.transaction_id, t.checkout_session_id, t.checked_at,
               (t.checked_at IS NULL OR t.checked_at < (NOW() - INTERVAL 10 SECOND)) AS due
        FROM orders o
        LEFT JOIN payment_transactions t ON t.transaction_id =
            (SELECT MAX(transaction_id) FROM payment_transactions WHERE order_id = o.order_id)
        WHERE o.order_id = ?');
    $q->execute([$orderId]);
    $o = $q->fetch(PDO::FETCH_ASSOC);
    if (!$o || $o['payment_method'] === 'cod' || $o['payment_status'] === 'paid') {
        return $o['payment_status'] ?? null;
    }

    $sessionId = $o['checkout_session_id'] ?: $o['payment_reference'];
    if (!$sessionId || !$o['transaction_id']) {
        return null; // customer never reached PayMongo
    }

    // Orders already given up on (failed/cancelled) are only re-checked rarely,
    // just in case the customer paid on the old page.
    if (in_array($o['payment_status'], ['failed', 'cancelled'], true)) {
        if ($o['checked_at'] !== null) {
            $recent = $pdo->prepare('SELECT ? > (NOW() - INTERVAL 10 MINUTE)');
            $recent->execute([$o['checked_at']]);
            if ((int) $recent->fetchColumn() === 1) {
                return null;
            }
        }
    } elseif (!(int) $o['due']) {
        return null; // throttle: checked within the last 10 seconds
    }

    $pdo->prepare('UPDATE payment_transactions SET checked_at = NOW() WHERE transaction_id = ?')
        ->execute([$o['transaction_id']]);

    try {
        $session = $fetch ? $fetch($sessionId) : paymongo_retrieve_checkout_session($sessionId);
    } catch (Throwable $e) {
        error_log("payment_reconcile_order #$orderId: " . $e->getMessage());
        return null;
    }

    $state = payment_session_state($session, (float) $o['grand_total']);
    if ($state['mismatch']) {
        error_log("payment_reconcile_order #$orderId: PayMongo amount does not match the order total; not marking paid.");
        return null;
    }
    if ($state['paid']) {
        payment_mark_paid($pdo, $orderId, 'reconcile');
        return 'paid';
    }
    return $o['payment_status'];
}

/** Reconcile several orders, capped so a page load never fans out too far. */
function payment_reconcile_orders(PDO $pdo, array $orderIds, int $limit = 5): void {
    $n = 0;
    foreach ($orderIds as $id) {
        if ($n >= $limit) {
            break;
        }
        $before = microtime(true);
        payment_reconcile_order($pdo, (int) $id);
        $n++;
        if (microtime(true) - $before > 8) {
            break; // PayMongo is slow right now; don't stack more calls on this request
        }
    }
}
