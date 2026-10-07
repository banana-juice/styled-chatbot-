-- ============================================================
-- Migration for the QA-fix release (stock lifecycle, audit log, timestamps)
--
-- You normally DON'T need to run this by hand: php/stock.php applies the same
-- changes automatically the first time any of the affected endpoints runs.
-- It is provided so the schema can be reviewed or applied explicitly (e.g.
-- through phpMyAdmin). Skip any ALTER whose column already exists.
-- ============================================================

-- Every change to product_sizes.stock_qty, with who/what/when/why.
-- order_reserve (-qty) / order_release (+qty) rows are also how the app knows
-- how many units an order currently holds, which makes releasing idempotent.
CREATE TABLE IF NOT EXISTS stock_adjustments (
    adjustment_id INT AUTO_INCREMENT PRIMARY KEY,
    product_id    INT NOT NULL,
    size          VARCHAR(10) NOT NULL DEFAULT '',
    delta         INT NOT NULL,
    qty_before    INT NULL,
    qty_after     INT NULL,
    reason        VARCHAR(40) NOT NULL,   -- order_reserve | order_release | admin_adjust
    order_id      INT NULL,
    user_id       INT NULL,
    note          VARCHAR(255) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_adj_order (order_id),
    INDEX idx_adj_product (product_id, size),
    INDEX idx_adj_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Payment lifecycle timestamps next to the existing created_at / paid_at.
-- updated_at only moves when the order row really changes.
ALTER TABLE orders ADD COLUMN failed_at    DATETIME NULL;
ALTER TABLE orders ADD COLUMN cancelled_at DATETIME NULL;
ALTER TABLE orders ADD COLUMN updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
