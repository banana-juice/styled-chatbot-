-- ============================================
-- payment-columns-migration.sql
-- ============================================
-- The payment gateway code (php/checkout.php, php/create_payment.php,
-- php/paymongo_webhook.php, php/admin/orders.php) reads/writes three
-- columns on `orders` that aren't in the styled_db export everyone's
-- been importing: payment_status, payment_reference, paid_at.
--
-- Without this, php/orders.php and php/admin/orders.php 500 with
-- "Unknown column 'payment_status'" the moment you load Orders/My Orders.
--
-- Safe to run more than once? No — re-running will error with
-- "Duplicate column name" if these already exist. That's fine, it just
-- means you already have them.
--
-- Usage:
--   mysql -u root styled_db < payment-columns-migration.sql

ALTER TABLE orders
  ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER payment_method,
  ADD COLUMN payment_reference VARCHAR(191) NULL DEFAULT NULL AFTER payment_status,
  ADD COLUMN paid_at DATETIME NULL DEFAULT NULL AFTER payment_reference;

-- Backfill: existing COD orders are inherently "paid on delivery", not
-- sitting unpaid waiting on a gateway. Card/GCash orders placed before
-- this column existed have no real payment history to infer, so they
-- stay at the 'unpaid' default rather than guessing.
UPDATE orders SET payment_status = 'cod' WHERE payment_method = 'cod';
