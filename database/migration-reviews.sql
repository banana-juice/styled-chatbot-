-- Product reviews & ratings.
-- The application creates this table automatically (php/reviews_lib.php ->
-- reviews_ensure_schema), so running this file by hand is optional.
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- SKU was removed from the admin/staff UI. The product_sizes.sku column is
-- no longer read or written; dropping it is optional:
-- ALTER TABLE product_sizes DROP COLUMN sku;
