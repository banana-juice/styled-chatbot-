-- ============================================
-- payment-columns-migration.sql
-- ============================================
-- The payment gateway code (php/checkout.php, php/create_payment.php,
-- php/paymongo_webhook.php, php/admin/orders.php) reads/writes columns
-- and a table that aren't in the styled_db export everyone's been
-- importing.
--
-- Without part 1, php/orders.php and php/admin/orders.php 500 with
-- "Unknown column 'payment_status'" the moment you load Orders/My Orders.
-- Without part 2, php/create_payment.php 500s with "Table
-- 'payment_transactions' doesn't exist" the moment a real PayMongo key
-- is configured and someone actually completes a card/GCash checkout
-- (with a placeholder key you'll hit "Invalid merchant key format"
-- first, from PayMongo itself, before this table is ever touched).
-- Without part 3, php/auth/google-callback.php 500s with "Unknown
-- column 'google_id'" the moment someone finishes a Google sign-in.
--
-- Safe to run more than once? No — re-running part 1 or part 3 will
-- error with "Duplicate column name" if already applied. Part 2 uses
-- CREATE TABLE IF NOT EXISTS, so it's safe alone. That's fine either
-- way, it just means you already have them.
--
-- Usage:
--   mysql -u root styled_db < payment-columns-migration.sql

-- ── Part 1: orders columns ──────────────────────────────────────────
ALTER TABLE orders
  ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid' AFTER payment_method,
  ADD COLUMN payment_reference VARCHAR(191) NULL DEFAULT NULL AFTER payment_status,
  ADD COLUMN paid_at DATETIME NULL DEFAULT NULL AFTER payment_reference;

-- Backfill: existing COD orders are inherently "paid on delivery", not
-- sitting unpaid waiting on a gateway. Card/GCash orders placed before
-- this column existed have no real payment history to infer, so they
-- stay at the 'unpaid' default rather than guessing.
UPDATE orders SET payment_status = 'cod' WHERE payment_method = 'cod';

-- ── Part 2: payment_transactions (audit trail of every PayMongo
-- checkout session + webhook event, one row per payment attempt) ────
CREATE TABLE IF NOT EXISTS payment_transactions (
  transaction_id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  provider VARCHAR(30) NOT NULL DEFAULT 'paymongo',
  checkout_session_id VARCHAR(191) NULL,
  amount DECIMAL(10,2) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'PHP',
  status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
  last_event_type VARCHAR(100) NULL,
  raw_payload LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order_id (order_id),
  KEY idx_checkout_session_id (checkout_session_id),
  CONSTRAINT fk_payment_transactions_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Part 3: users columns (Google OAuth login) ──────────────────────
ALTER TABLE users
  ADD COLUMN google_id VARCHAR(64) NULL DEFAULT NULL AFTER admin_notes,
  ADD COLUMN avatar_url VARCHAR(500) NULL DEFAULT NULL AFTER google_id,
  ADD COLUMN auth_provider VARCHAR(20) NOT NULL DEFAULT 'password' AFTER avatar_url,
  ADD UNIQUE KEY uq_google_id (google_id);
