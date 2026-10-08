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
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/paymongo_client.php';
require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/order-confirmation-email.php';

// ── Diagnostics ─────────────────────────────────────────────────────────
// Trace lines go to the server's error log (never to a file inside the web
// folder, which is what the old temporary debug log did).
function debug_log(string $msg): void {
    error_log('paymongo_webhook: ' . $msg);
}
debug_log('--- webhook invoked ---');
// ─────────────────────────────────────────────────────────────────────────

header('Content-Type: application/json');

if (PAYMONGO_WEBHOOK_SECRET === '') {
    // Misconfiguration, not a bad caller: say so loudly (503 makes PayMongo
    // retry later) instead of rejecting every genuine event as "bad signature".
    error_log('PayMongo webhook: PAYMONGO_WEBHOOK_SECRET is not set — add it to .env (Dashboard > Developers > Webhooks > Signing secret).');
    http_response_code(503);
    echo json_encode(['error' => 'Webhook signing secret is not configured on the server.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$signatureHeader = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

debug_log('Signature header present: ' . ($signatureHeader !== '' ? 'yes' : 'NO'));

if (!paymongo_verify_webhook_signature($rawBody, $signatureHeader)) {
    debug_log('SIGNATURE VERIFICATION FAILED.');
    error_log('PayMongo webhook: signature verification failed');

    // An unverified call can never change an order by itself. But if it names an
    // order that is waiting for payment, use it as a prompt to ASK PayMongo's API
    // (server-to-server, with our secret key) whether that order was paid. Only
    // PayMongo's own answer can mark it paid, so a forged call achieves nothing,
    // while a mismatched/rotated signing secret no longer delays real payments.
    try {
        $peek   = json_decode($rawBody, true);
        $refNum = $peek['data']['data']['attributes']['reference_number'] ?? null;
        if (is_string($refNum) && preg_match('/^STY-\d{8}-\d{4}$/', $refNum)) {
            $pdoPeek = getPDO();
            $q = $pdoPeek->prepare("SELECT order_id FROM orders
                WHERE order_number = ? AND payment_method <> 'cod'
                  AND payment_status IN ('unpaid', 'processing') LIMIT 1");
            $q->execute([$refNum]);
            if ($oid = $q->fetchColumn()) {
                $res = payment_reconcile_order($pdoPeek, (int) $oid); // throttled: once per 10 s per order
                debug_log('Unverified webhook for ' . $refNum . ' -> asked PayMongo directly, result: ' . var_export($res, true));
            }
        }
    } catch (Throwable $e) {
        debug_log('Reconcile after failed signature errored: ' . $e->getMessage());
    }

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
        // Same code path the "ask PayMongo" check uses, so an order can never be
        // marked paid (or emailed) twice, whichever of the two gets there first.
        if (payment_mark_paid($pdo, (int) $order['order_id'], 'webhook')) {
            debug_log('Order marked paid via webhook: order_id=' . $order['order_id']);
        } else {
            debug_log('Order was already payment_status=paid, skipping update+email (idempotent)');
        }
    } elseif ($eventType === 'checkout_session.expired') {
        // The session timed out without being paid — no separate "failed"
        // event exists for checkout sessions (see note at top of file).
        if (!in_array($order['payment_status'], ['paid'], true)) {
            stock_ensure_schema($pdo);
            // Marks the order failed + cancelled and puts its stock back on
            // sale (idempotent: a re-delivered event restores nothing twice).
            order_cancel_and_release($pdo, (int) $order['order_id'], 'failed', 'payment_expired',
                'Payment session expired without payment.');
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
