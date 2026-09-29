<?php
// ============================================================
// Shared order-confirmation email sender.
// Extracted from php/checkout.php so it can also be called from
// php/paymongo_webhook.php once a gateway payment is confirmed as paid
// (for card/gcash orders, the confirmation email now fires on payment
// success, not at order-placement time — see checkout.php).
// ============================================================

// ── Email helper (PHPMailer) ──────────────────────────────────────────────────
require_once __DIR__ . '/config/smtp.php';
$_mailerAvailable = file_exists(__DIR__ . '/../vendor/autoload.php');
if ($_mailerAvailable) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/**
 * Send order confirmation email
 */
function send_order_confirmation(
    string $toEmail,
    string $toName,
    string $orderNumber,
    array  $items,
    PDO    $pdo,
    float  $subtotal,
    float  $shippingFee,
    float  $grandTotal,
    string $paymentMethod,
    array  $shippingAddress
): void {
    // Recalculate subtotal from items to ensure consistency
    $recalcSubtotal = 0;
    foreach ($items as $item) {
        $recalcSubtotal += $item['unit_price'] * $item['qty'];
    }
    $subtotal = $recalcSubtotal;
    
    // Build items HTML rows
    $itemsHtml = '';
    foreach ($items as $item) {
        $stmt = $pdo->prepare('SELECT name FROM products WHERE product_id = :pid LIMIT 1');
        $stmt->execute([':pid' => $item['product_id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        $name    = $product ? htmlspecialchars($product['name']) : 'Product #' . $item['product_id'];
        $size    = $item['size'] ? ' (' . htmlspecialchars($item['size']) . ')' : '';
        $lineTotal = $item['unit_price'] * $item['qty'];
        $unitPrice = $item['unit_price'];

        $itemsHtml .= "
        <tr>
            <td style='padding:10px 8px;border-bottom:1px solid #f0ebe5;'>{$name}{$size}</td>
            <td style='padding:10px 8px;border-bottom:1px solid #f0ebe5;text-align:center;'>{$item['qty']}</td>
            <td style='padding:10px 8px;border-bottom:1px solid #f0ebe5;text-align:right;'>₱" . number_format($unitPrice, 2) . "</td>
            <td style='padding:10px 8px;border-bottom:1px solid #f0ebe5;text-align:right;'>₱" . number_format($lineTotal, 2) . "</td>
        </tr>";
    }

    $shippingDisplay = $shippingFee == 0.0 ? 'FREE' : '₱' . number_format($shippingFee, 2);
    $subtotalDisplay = '₱' . number_format($subtotal, 2);
    $grandDisplay    = '₱' . number_format($grandTotal, 2);
    $paymentMap      = ['card' => 'Credit / Debit Card', 'gcash' => 'GCash', 'cod' => 'Cash on Delivery'];
    $paymentDisplay  = $paymentMap[strtolower($paymentMethod)] ?? ucfirst($paymentMethod);

    $addrLine = implode(', ', array_filter([
        $shippingAddress['street'] ?? '',
        $shippingAddress['city']   ?? '',
        $shippingAddress['province'] ?? '',
        $shippingAddress['zip_code'] ?? '',
    ])) . ', Philippines';

    $firstName = htmlspecialchars($toName);

    $html = <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="UTF-8"/></head>
    <body style="margin:0;padding:0;background:#faf7f4;font-family:'Jost',Arial,sans-serif;color:#2c1f14;">
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#faf7f4;padding:40px 0;">
        <tr><td align="center">
          <table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,.06);">

            <!-- Header -->
            <tr>
              <td style="background:#2c1f14;padding:32px 40px;text-align:center;">
                <h1 style="margin:0;color:#e8ddd4;font-size:28px;font-weight:300;letter-spacing:4px;">STYLED</h1>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:40px;">
                <h2 style="margin:0 0 8px;font-size:22px;font-weight:400;">Order Confirmed!</h2>
                <p style="margin:0 0 24px;color:#7a6a5a;font-size:14px;">Hi {$firstName}, thank you for shopping with Styled. We've received your order and will begin processing it shortly.</p>

                <div style="background:#faf7f4;border-radius:6px;padding:16px 20px;margin-bottom:28px;">
                  <p style="margin:0;font-size:13px;color:#7a6a5a;">Order Number</p>
                  <p style="margin:4px 0 0;font-size:18px;font-weight:500;letter-spacing:1px;">{$orderNumber}</p>
                </div>

                <!-- Items table -->
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-bottom:20px;">
                  <thead>
                    <tr style="background:#faf7f4;">
                      <th style="padding:10px 8px;text-align:left;font-weight:500;color:#7a6a5a;">Item</th>
                      <th style="padding:10px 8px;text-align:center;font-weight:500;color:#7a6a5a;">Qty</th>
                      <th style="padding:10px 8px;text-align:right;font-weight:500;color:#7a6a5a;">Unit Price</th>
                      <th style="padding:10px 8px;text-align:right;font-weight:500;color:#7a6a5a;">Total</th>
                    </tr>
                  </thead>
                  <tbody>{$itemsHtml}</tbody>
                </table>

                <!-- Totals -->
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-bottom:28px;">
                  <tr>
                    <td style="padding:6px 8px;color:#7a6a5a;">Subtotal</td>
                    <td style="padding:6px 8px;text-align:right;">{$subtotalDisplay}</td>
                  </tr>
                  <tr>
                    <td style="padding:6px 8px;color:#7a6a5a;">Shipping</td>
                    <td style="padding:6px 8px;text-align:right;">{$shippingDisplay}</td>
                  </tr>
                  <tr style="border-top:2px solid #f0ebe5;">
                    <td style="padding:10px 8px;font-weight:600;font-size:15px;">Total</td>
                    <td style="padding:10px 8px;text-align:right;font-weight:600;font-size:15px;">{$grandDisplay}</td>
                  </tr>
                </table>

                <!-- Details row -->
                <table width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;margin-bottom:32px;">
                  <tr>
                    <td width="50%" style="vertical-align:top;padding-right:16px;">
                      <p style="margin:0 0 6px;font-weight:500;color:#7a6a5a;text-transform:uppercase;font-size:11px;letter-spacing:1px;">Shipping To</p>
                      <p style="margin:0;line-height:1.6;">{$addrLine}</p>
                    </td>
                    <td width="50%" style="vertical-align:top;">
                      <p style="margin:0 0 6px;font-weight:500;color:#7a6a5a;text-transform:uppercase;font-size:11px;letter-spacing:1px;">Payment Method</p>
                      <p style="margin:0;">{$paymentDisplay}</p>
                    </td>
                  </tr>
                </table>

                <a href="https://styled.com/orders.html?order={$orderNumber}" style="display:inline-block;background:#2c1f14;color:#fff;text-decoration:none;padding:14px 32px;border-radius:4px;font-size:13px;letter-spacing:1px;">Track Your Order</a>
              </td>
            </tr>

            <!-- Footer -->
            <tr>
              <td style="background:#faf7f4;padding:24px 40px;text-align:center;border-top:1px solid #f0ebe5;">
                <p style="margin:0;font-size:12px;color:#a89a8a;">Questions? Reply to this email or visit our <a href="https://styled.com/contact.html" style="color:#2c1f14;">Help Centre</a>.</p>
                <p style="margin:8px 0 0;font-size:11px;color:#c4b8ae;">© Styled Philippines</p>
              </td>
            </tr>

          </table>
        </td>
      </table>
    </body>
    </html>
    HTML;

    global $_mailerAvailable;
    if (!$_mailerAvailable) {
        error_log('PHPMailer not installed - skipping confirmation email for ' . $orderNumber);
        return;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;

    $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
    $mail->addAddress($toEmail, $toName);
    $mail->addReplyTo(SMTP_USER, SMTP_FROM_NAME);

    $mail->isHTML(true);
    $mail->Subject = "Your Styled Order {$orderNumber} is Confirmed!";
    $mail->Body    = $html;
    $mail->AltBody = "Hi {$toName}, your order {$orderNumber} has been confirmed. Subtotal: {$subtotalDisplay}, Shipping: {$shippingDisplay}, Total: {$grandDisplay}. Payment: {$paymentDisplay}. Ship to: {$addrLine}.";

    $mail->send();
}