<?php
// ============================================================
// PAYMONGO WEBHOOK — PayMongo calls this URL directly (server-to-server)
// whenever a checkout session's payment succeeds, fails, or expires.
// This is the ONLY place that marks an order as genuinely "paid" —
// never trust the browser redirect back to success_url for that, since
// a user can hit that URL without ever actually paying.
//
// Register this URL in the PayMongo Dashboard under Developers > Webhooks:
//   https://styled.great-site.net/php/paymongo_webhook.php
// Subscribe to: checkout_session.payment.paid, checkout_session.expired
// (There is no separate "checkout_session.payment.failed" event — a
// failed attempt just lets the customer retry on the same PayMongo-hosted
// session; only a full expiry ends it without payment.)
//
// No session/auth here on purpose — PayMongo is calling this, not a
// logged-in browser. Trust is established via the signature check below.
// ============================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/paymongo_client.php';
require_once __DIR__ . '/order-confirmation-email.php';

// ── TEMPORARY DEBUG LOGGING ─────────────────────────────────────────────
// InfinityFree's free tier has no error log viewer, so this writes a plain
// text trace of every webhook call to a file you can open directly in
// File Manager. DELETE THIS BLOCK (and the debug_log() calls below, and
// the log file itself) once the webhook is confirmed working — it's not
// meant to stay in production.
function debug_log(string $msg): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    file_put_contents(__DIR__ . '/paymongo_webhook_debug.log', $line, FILE_APPEND);
}
debug_log('--- webhook invoked ---');
// ─────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

debug_log('Signature header present: ' . ($signatureHeader !== '' ? 'yes' : 'NO'));

if (!paymongo_verify_webhook_signature($rawBody, $signatureHeader)) {
    debug_log('SIGNATURE VERIFICATION FAILED. Header was: ' . $signatureHeader);
    error_log('PayMongo webhook: signature verification failed');
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}
debug_log('Signature verification: PASSED');

$event = json_decode($rawBody, true);

// Confirmed payload shape (PayMongo docs, 2026):
//   { "data": { "type": "checkout_session.payment.paid", "data": { "id": "cs_xxx",
//     "attributes": { "reference_number": "STY-...", ... } } } }
$eventType   = $event['data']['type'] ?? '';
$sessionObj  = $event['data']['data'] ?? [];
$checkoutSessionId = $sessionObj['id'] ?? null;
$referenceNumber   = $sessionObj['attributes']['reference_number'] ?? null;

debug_log("eventType=$eventType checkoutSessionId=$checkoutSessionId referenceNumber=$referenceNumber");


if (!$checkoutSessionId) {
    debug_log('NO checkoutSessionId found in payload — exiting early');
    // Nothing we can act on — acknowledge so PayMongo doesn't keep retrying.
    http_response_code(200);
    echo json_encode(['received' => true, 'note' => 'No checkout session id in payload']);
    exit;
}

try {
    $pdo = getPDO();
    debug_log('DB connection: OK');

    // PayMongo's own guidance: "look up the order by the session's id or
    // reference_number." We set reference_number = our order_number at
    // checkout-session creation time, so prefer that direct match; fall
    // back to the stored checkout_session_id (payment_reference) in case
    // reference_number is ever missing from the payload.
    if ($referenceNumber) {
        $stmt = $pdo->prepare('
            SELECT o.order_id, o.order_number, o.payment_status, o.status,
                   u.email, u.full_name
            FROM orders o
            JOIN users u ON u.user_id = o.user_id
            WHERE o.order_number = :ref
            LIMIT 1
        ');
        $stmt->execute([':ref' => $referenceNumber]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $order = false;
    }

    if (!$order) {
        $stmt = $pdo->prepare('
            SELECT o.order_id, o.order_number, o.payment_status, o.status,
                   u.email, u.full_name
            FROM orders o
            JOIN users u ON u.user_id = o.user_id
            WHERE o.payment_reference = :sid
            LIMIT 1
        ');
        $stmt->execute([':sid' => $checkoutSessionId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$order) {
        debug_log("NO ORDER FOUND matching reference_number=$referenceNumber or checkout_session_id=$checkoutSessionId");
        error_log("PayMongo webhook: no order found for checkout session $checkoutSessionId");
        http_response_code(200); // acknowledge — nothing local to update
        echo json_encode(['received' => true, 'note' => 'No matching order']);
        exit;
    }

    debug_log("Order FOUND: order_id={$order['order_id']} order_number={$order['order_number']} current payment_status={$order['payment_status']}");

    // Always log the raw event for audit/debugging, regardless of type.
    $txnIdStmt = $pdo->prepare('
        SELECT transaction_id FROM payment_transactions
        WHERE checkout_session_id = :sid
        ORDER BY transaction_id DESC LIMIT 1
    ');
    $txnIdStmt->execute([':sid' => $checkoutSessionId]);
    $txnId = $txnIdStmt->fetchColumn();
    if ($txnId) {
        $pdo->prepare('
            UPDATE payment_transactions
            SET last_event_type = :etype, raw_payload = :payload, updated_at = NOW()
            WHERE transaction_id = :tid
        ')->execute([
            ':etype'   => $eventType,
            ':payload' => $rawBody,
            ':tid'     => $txnId,
        ]);
    }

    if ($eventType === 'checkout_session.payment.paid') {
        debug_log('Event matched checkout_session.payment.paid — attempting to mark order as paid');
        if ($order['payment_status'] !== 'paid') {
            $pdo->prepare('
                UPDATE orders
                SET payment_status = "paid", paid_at = NOW(),
                    status = IF(status = "pending", "processing", status)
                WHERE order_id = :oid
            ')->execute([':oid' => $order['order_id']]);
            debug_log('UPDATE orders SET payment_status=paid executed for order_id=' . $order['order_id']);

            if ($txnId) {
                $pdo->prepare('UPDATE payment_transactions SET status = "paid" WHERE transaction_id = :tid')
                    ->execute([':tid' => $txnId]);
            }

            // Now that payment is actually confirmed, send the order
            // confirmation email (this was deliberately skipped in
            // checkout.php for card/gcash orders — see that file).
            $itemsStmt = $pdo->prepare('SELECT product_id, size, qty, unit_price FROM order_items WHERE order_id = :oid');
            $itemsStmt->execute([':oid' => $order['order_id']]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            $addrStmt = $pdo->prepare('
                SELECT a.street, a.city, a.province, a.zip_code
                FROM orders o JOIN addresses a ON a.address_id = o.address_id
                WHERE o.order_id = :oid
            ');
            $addrStmt->execute([':oid' => $order['order_id']]);
            $shippingAddress = $addrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $totalsStmt = $pdo->prepare('SELECT subtotal, shipping_fee, grand_total, payment_method FROM orders WHERE order_id = :oid');
            $totalsStmt->execute([':oid' => $order['order_id']]);
            $totals = $totalsStmt->fetch(PDO::FETCH_ASSOC);

            try {
                send_order_confirmation(
                    $order['email'],
                    explode(' ', $order['full_name'])[0] ?? 'Valued Customer',
                    $order['order_number'],
                    $items,
                    $pdo,
                    (float) $totals['subtotal'],
                    (float) $totals['shipping_fee'],
                    (float) $totals['grand_total'],
                    $totals['payment_method'],
                    $shippingAddress
                );
                debug_log('send_order_confirmation: SUCCESS');
            } catch (Throwable $mailErr) {
                debug_log('send_order_confirmation FAILED: ' . $mailErr->getMessage());
                error_log('PayMongo webhook: confirmation email failed: ' . $mailErr->getMessage());
            }
        } else {
            debug_log('Order was already payment_status=paid, skipping update+email (idempotent)');
        }
    } elseif ($eventType === 'checkout_session.expired') {
        // The session timed out without being paid — no separate "failed"
        // event exists for checkout sessions (see note at top of file).
        if (!in_array($order['payment_status'], ['paid'], true)) {
            $pdo->prepare('UPDATE orders SET payment_status = "failed" WHERE order_id = :oid')
                ->execute([':oid' => $order['order_id']]);
            if ($txnId) {
                $pdo->prepare('UPDATE payment_transactions SET status = "failed" WHERE transaction_id = :tid')
                    ->execute([':tid' => $txnId]);
            }
        }
    }
    // Any other event type: already logged above via raw_payload, no
    // order state change needed.

    debug_log('Webhook processing complete, returning 200');
    http_response_code(200);
    echo json_encode(['received' => true]);

} catch (Throwable $e) {
    debug_log('EXCEPTION CAUGHT: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    error_log('paymongo_webhook.php error: ' . $e->getMessage());
    // Still return 200 for unexpected-but-not-signature errors after
    // logging would be debatable; for a student project, surfacing the
    // failure (500) so PayMongo retries is safer than silently losing it.
    http_response_code(500);
    echo json_encode(['error' => 'Webhook processing error']);
}
