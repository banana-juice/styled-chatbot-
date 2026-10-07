<?php
// ============================================================
// PAYMONGO CLIENT — thin wrapper around the PayMongo REST API.
// Uses cURL directly (same approach already used for Brevo in this
// codebase — see php/brevo_email.php) rather than pulling in an SDK,
// since this app has no Composer autoloading pipeline deployed to
// production beyond PHPMailer.
//
// Docs: https://developers.paymongo.com/reference
// ============================================================

require_once __DIR__ . '/config/paymongo.php';

class PaymongoException extends Exception {}

/**
 * Low-level authenticated request to the PayMongo API.
 * Auth: HTTP Basic with the secret key as username, empty password.
 */
function paymongo_request(string $method, string $endpoint, ?array $body = null, ?int $timeoutSeconds = null): array {
    // NOTE: $endpoint must include its own version prefix, e.g. '/v2/checkout_sessions'.
    // PayMongo's Checkout Sessions API is versioned per-resource (v2 is current/
    // recommended for Checkout Sessions as of 2026), unlike a single global API version.
    $ch = curl_init('https://api.paymongo.com' . $endpoint);

    $headers = [
        'accept: application/json',
        'content-type: application/json',
        'authorization: Basic ' . base64_encode(PAYMONGO_SECRET_KEY . ':'),
    ];

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $timeoutSeconds !== null ? min(3, $timeoutSeconds) : 10,
        CURLOPT_TIMEOUT        => $timeoutSeconds ?? 20,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        throw new PaymongoException('Could not reach PayMongo: ' . $curlError);
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $detail = $decoded['errors'][0]['detail']
            ?? ('PayMongo API error (HTTP ' . $httpCode . ')');
        throw new PaymongoException($detail);
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * Create a Checkout Session — a PayMongo-hosted payment page that can
 * accept Card, GCash, and/or Maya depending on $paymentMethodTypes.
 * The customer is redirected there; STYLED never touches card data.
 *
 * We intentionally send the order as ONE line item for the grand
 * total rather than itemising every product, so discounts/shipping
 * baked into grand_total don't have to be re-expressed as PayMongo
 * line items (PayMongo line item amounts must be positive). The
 * itemised breakdown is still shown to the customer on our own
 * checkout page and order confirmation email.
 */
function paymongo_create_checkout_session(
    string $description,
    float $amountPhp,
    string $referenceNumber,
    string $successUrl,
    string $cancelUrl,
    array $paymentMethodTypes,
    string $customerName,
    string $customerEmail
): array {
    $amountCentavos = (int) round($amountPhp * 100);

    $body = [
        'data' => [
            'attributes' => [
                'send_email_receipt'  => false,
                'show_description'    => true,
                'show_line_items'     => true,
                'line_items'          => [[
                    'currency' => 'PHP',
                    'amount'   => $amountCentavos,
                    'name'     => $description,
                    'quantity' => 1,
                ]],
                'payment_method_types' => $paymentMethodTypes,
                'reference_number'     => $referenceNumber,
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'description'          => $description,
                'billing' => [
                    'name'  => $customerName,
                    'email' => $customerEmail,
                ],
            ],
        ],
    ];

    return paymongo_request('POST', '/v2/checkout_sessions', $body);
}

function paymongo_retrieve_checkout_session(string $sessionId, ?int $timeoutSeconds = null): array {
    // Retrieval lives under /v1 (verified against the live test API: the
    // /v2 path returns "The requested route does not exist"), even though
    // sessions are created through /v2.
    return paymongo_request('GET', '/v1/checkout_sessions/' . $sessionId, null, $timeoutSeconds);
}

/**
 * Best-effort: expire a still-open checkout session so a cancelled order
 * can't be paid later from the old PayMongo page. Never throws — if
 * PayMongo is unreachable the webhook's late-payment handling covers it.
 */
function paymongo_expire_checkout_session_quietly(?string $sessionId): void {
    if (!$sessionId) {
        return;
    }
    try {
        paymongo_request('POST', '/v1/checkout_sessions/' . $sessionId . '/expire');
    } catch (Throwable $e) {
        error_log('PayMongo expire session ' . $sessionId . ': ' . $e->getMessage());
    }
}

/**
 * Verify the `Paymongo-Signature` header on an incoming webhook.
 * Header format: t=<timestamp>,te=<test_sig>,li=<live_sig>
 * Signature = HMAC-SHA256("{timestamp}.{raw_body}", webhook_secret)
 * compared against te (test mode) or li (live mode) depending on
 * which secret key is configured.
 */
function paymongo_verify_webhook_signature(string $rawBody, string $signatureHeader): bool {
    if ($signatureHeader === '' || PAYMONGO_WEBHOOK_SECRET === '') {
        return false;
    }

    $parts = [];
    foreach (explode(',', $signatureHeader) as $pair) {
        $kv = explode('=', $pair, 2);
        if (count($kv) === 2) {
            $parts[trim($kv[0])] = trim($kv[1]);
        }
    }

    $timestamp = $parts['t'] ?? null;
    $isLiveKey = str_starts_with(PAYMONGO_SECRET_KEY, 'sk_live_');
    $providedSignature = $isLiveKey ? ($parts['li'] ?? null) : ($parts['te'] ?? null);

    if (!$timestamp || !$providedSignature) {
        return false;
    }

    $signedPayload      = $timestamp . '.' . $rawBody;
    $expectedSignature  = hash_hmac('sha256', $signedPayload, PAYMONGO_WEBHOOK_SECRET);

    return hash_equals($expectedSignature, $providedSignature);
}
