-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: styled_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `categories`
--

DROP TABLE IF EXISTS `categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `slug` varchar(100) NOT NULL,
  PRIMARY KEY (`category_id`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categories`
--

LOCK TABLES `categories` WRITE;
/*!40000 ALTER TABLE `categories` DISABLE KEYS */;
INSERT INTO `categories` VALUES (1,'Tops','tops'),(2,'Bottoms','bottoms'),(3,'Dresses','dresses'),(4,'Outerwear','outerwear'),(5,'Accessories','accessories');
/*!40000 ALTER TABLE `categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `products`
--

DROP TABLE IF EXISTS `products`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `product_id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `sale_price` decimal(10,2) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','draft','archived') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`product_id`),
  KEY `idx_category_id` (`category_id`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `products`
--

LOCK TABLES `products` WRITE;
/*!40000 ALTER TABLE `products` DISABLE KEYS */;
INSERT INTO `products` VALUES (1,1,'Mocha Wrap Tank','An elevated essential designed with a graceful wrap silhouette and smooth, breathable fabric. Thoughtfully tailored to balance comfort and elegance.',320.00,NULL,1,'2026-05-09 15:17:00','active'),(2,1,'Coca Knot Tee','A chic knotted tee crafted from soft, lightweight fabric. Features a flattering front-knot detail that adds effortless dimension to any outfit.',299.00,NULL,1,'2026-05-09 15:17:00','active'),(3,1,'Cream Off-Shoulder','A timeless off-shoulder top in a soft cream hue. The relaxed drape and clean neckline make it a versatile wardrobe staple.',350.00,NULL,1,'2026-05-09 15:17:00','active'),(4,2,'Wide-Leg Trousers','Relaxed wide-leg trousers with a sophisticated drape. Comfortable and polished for the modern woman on the move.',420.00,NULL,1,'2026-05-09 15:17:00','active'),(5,2,'Chocolate Satin Pants','Luxurious satin pants in a rich chocolate hue. The smooth sheen adds an elevated touch to any outfit.',420.00,NULL,1,'2026-05-09 15:17:00','active'),(6,2,'Black Maxi Skirt','A sleek black maxi skirt with timeless appeal. Floor-grazing length and a fluid drape for an effortlessly chic look.',390.00,NULL,1,'2026-05-09 15:17:00','active'),(7,3,'Tie-Strap Dress','An elegant tie-strap dress with delicate detail at the shoulders. Effortlessly graceful for any occasion.',650.00,NULL,1,'2026-05-09 15:17:00','active'),(8,3,'Crimson Dress','A bold crimson dress that commands attention. The rich hue and refined cut make it a true statement piece.',490.00,NULL,1,'2026-05-09 15:17:00','active'),(9,3,'Wine Satin Dress','A luxurious satin dress in a deep wine shade. The fluid drape and lustrous finish create an effortlessly glamorous look.',500.00,NULL,1,'2026-05-09 15:17:00','active'),(10,4,'Leather Jacket','A classic leather jacket that never goes out of style. Sleek, structured, and effortlessly cool for any season.',990.00,NULL,1,'2026-05-09 15:17:00','active'),(11,4,'Corduroy Jacket','A textured corduroy jacket with timeless character. Rich in detail and warmth, it layers beautifully over any outfit.',690.00,NULL,1,'2026-05-09 15:17:00','active'),(12,4,'Two-Tone Bomber','A contemporary two-tone bomber jacket with a relaxed, urban edge. The contrasting panels add a modern graphic quality.',670.00,NULL,1,'2026-05-09 15:17:00','active'),(13,5,'Gold Pearl Drops','Classic gold drop earrings with a single pearl detail. Sophisticated and refined for any occasion.',270.00,NULL,1,'2026-05-09 15:17:00','active'),(14,5,'Beaded Ocean Necklace','A delicate beaded necklace inspired by the ocean\'s palette. Light, layerable, and full of natural charm.',160.00,NULL,1,'2026-05-09 15:17:00','active'),(15,5,'Pearl Floral Earrings','Elegant floral earrings adorned with lustrous pearls. A timeless accent that elevates any look with effortless grace.',170.00,NULL,1,'2026-05-09 15:17:00','active'),(16,1,'Wine Halter Blouse','A rich wine-toned halter blouse with a refined silhouette. Perfect for transitioning from day to evening with minimal effort.',330.00,NULL,1,'2026-05-10 04:36:49','active'),(17,1,'Midnight Peplum Top','A structured peplum top in deep midnight that flatters every figure. The subtle flare adds a polished, feminine touch.',380.00,NULL,1,'2026-05-10 04:36:49','active'),(18,1,'Black Lace Tank','A delicate lace-trimmed tank that blends understated elegance with everyday wearability. Layer it or wear it alone.',310.00,NULL,1,'2026-05-10 04:36:49','active'),(19,1,'Rust Ruffle Top','An elevated essential designed with a graceful wrap silhouette and smooth, breathable fabric. Making it a versatile staple in any wardrobe.',340.00,NULL,1,'2026-05-10 04:36:49','active'),(20,1,'Ivory Flowy Top','A breezy ivory top with a fluid, relaxed fit. Effortlessly elegant for warm days and casual evenings alike.',370.00,NULL,1,'2026-05-10 04:36:49','active'),(21,1,'Sheer Chocolate Top','A subtly sheer top in a rich chocolate tone. Light, breathable, and beautifully layerable for a modern minimal look.',300.00,NULL,1,'2026-05-10 04:36:49','active'),(22,1,'Textured Puff Sleeve Blouse','A statement blouse featuring textured fabric and voluminous puff sleeves. Effortlessly elevated for any occasion.',370.00,NULL,1,'2026-05-10 04:36:49','active'),(23,2,'Midnight Lounge Shorts','Relaxed lounge shorts in a sleek midnight finish. Comfortable enough for lounging, polished enough for errands.',350.00,NULL,1,'2026-05-10 04:36:49','active'),(24,2,'Sand Tailored Shorts','Crisp tailored shorts in a warm sand tone. A refined take on casual dressing with a clean, structured cut.',270.00,NULL,1,'2026-05-10 04:36:49','active'),(25,2,'Striped Wrap Skirt','A classic striped wrap skirt with a graceful flow. Timeless patterning meets modern silhouette for effortless style.',490.00,NULL,1,'2026-05-10 04:36:49','active'),(26,2,'Bush Floral Skirt','A romantic floral skirt with a soft, feminine drape. The delicate print adds a botanical charm to your everyday look.',380.00,NULL,1,'2026-05-10 04:36:49','active'),(27,2,'Cream Mini Skirt','A clean, minimal mini skirt in a soft cream finish. Pairs effortlessly with any top for a polished, put-together look.',330.00,NULL,1,'2026-05-10 04:36:49','active'),(28,2,'Pastel Bloom Skirt','A dreamy pastel skirt adorned with delicate bloom prints. Light, flowy, and utterly feminine.',370.00,NULL,1,'2026-05-10 04:36:49','active'),(29,2,'Sunshine Skirt','A bright and cheerful skirt that brings warmth to any outfit. The fluid cut moves beautifully with every step.',460.00,NULL,1,'2026-05-10 04:36:49','active'),(30,3,'Rosy Gingham Dress','A charming gingham dress in a soft rosy palette. The classic pattern meets a fresh, feminine silhouette.',470.00,NULL,1,'2026-05-10 04:36:49','active'),(31,3,'Olive Halter Dress','A sleek halter dress in a muted olive tone. Minimal and modern with a confident, refined edge.',450.00,NULL,1,'2026-05-10 04:36:49','active'),(32,3,'Mocha Bodycon','A figure-hugging bodycon dress in a warm mocha tone. Smooth, sleek, and undeniably chic.',470.00,NULL,1,'2026-05-10 04:36:49','active'),(33,3,'Blush Lace Dress','A romantic blush dress featuring delicate lace detailing. Feminine, timeless, and utterly stunning.',570.00,NULL,1,'2026-05-10 04:36:49','active'),(34,3,'Slate Blue Dress','A sophisticated slate blue dress with a clean, minimal silhouette. Understated elegance at its finest.',440.00,NULL,1,'2026-05-10 04:36:49','active'),(35,3,'Night Bodycon','A sleek night bodycon dress that embraces your silhouette. Perfect for an evening out with confidence.',490.00,NULL,1,'2026-05-10 04:36:49','active'),(36,3,'Soft Pink Dress','A gentle soft pink dress with an airy, feminine feel. Light and graceful for warm days and special moments.',470.00,NULL,1,'2026-05-10 04:36:49','active'),(37,4,'Minimalist Shacket','A clean-lined shacket that works as both a shirt and a jacket. Versatile, effortless, and endlessly wearable.',550.00,NULL,1,'2026-05-10 04:36:49','active'),(38,4,'Zip Windbreaker','A sleek zip windbreaker built for movement and style. Lightweight protection with a polished, athletic silhouette.',800.00,NULL,1,'2026-05-10 04:36:49','active'),(39,4,'Utility Jacket','A practical yet stylish utility jacket with functional detailing. Effortlessly cool for everyday wear.',570.00,NULL,1,'2026-05-10 04:36:49','active'),(40,4,'Shearling Trucker','A cozy shearling trucker jacket with plush texture and rugged appeal. Warmth and style in perfect balance.',800.00,NULL,1,'2026-05-10 04:36:49','active'),(41,4,'Patterned Bomber','A bold patterned bomber that makes a statement. Eye-catching print meets relaxed silhouette for a modern edge.',670.00,NULL,1,'2026-05-10 04:36:49','active'),(42,4,'Leather Moto','An iconic leather moto jacket with an edgy, refined attitude. A wardrobe investment that only gets better with time.',890.00,NULL,1,'2026-05-10 04:36:49','active'),(43,4,'Oversized Utility Shacket','An oversized utility shacket with a relaxed, layered feel. Functional pockets and a roomy fit for effortless style.',670.00,NULL,1,'2026-05-10 04:36:49','active'),(44,5,'Crochet Lace Bandana','A charming crochet lace bandana with artisan texture. Style it in your hair, around your neck, or on your bag.',180.00,NULL,1,'2026-05-10 04:36:49','active'),(45,5,'Braid Tail Chain','A sculptural braided chain with a luxurious, handcrafted quality. A bold, artful statement piece.',670.00,NULL,1,'2026-05-10 04:36:49','active'),(46,5,'Gold Arm Cuff','A sleek gold arm cuff with a minimal, architectural design. Effortlessly cool on its own or stacked.',130.00,NULL,1,'2026-05-10 04:36:49','active'),(47,5,'Hibiscus Hair Clips','Playful hibiscus-shaped hair clips that add a tropical touch to any hairstyle. Feminine and fun.',150.00,NULL,1,'2026-05-10 04:36:49','active'),(48,5,'Sun Stacking Rings','A set of delicate sun-motif stacking rings in warm gold. Mix, match, and layer for a personalized look.',270.00,NULL,1,'2026-05-10 04:36:49','active'),(49,5,'Starfish Layered Necklace','A whimsical layered necklace featuring a starfish charm. Coastal-inspired and effortlessly charming.',160.00,NULL,1,'2026-05-10 04:36:49','active'),(50,5,'Oval Retro Sunglasses','Retro oval sunglasses with a timeless, sophisticated frame. A finishing touch that ties any look together.',370.00,NULL,1,'2026-05-10 04:36:49','active');
/*!40000 ALTER TABLE `products` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_images`
--

DROP TABLE IF EXISTS `product_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_images` (
  `image_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `image_url` varchar(500) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`image_id`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=59 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_images`
--

LOCK TABLES `product_images` WRITE;
/*!40000 ALTER TABLE `product_images` DISABLE KEYS */;
INSERT INTO `product_images` VALUES (1,13,'/styled/assets/images/Accessories/Gold Pearl Drops.png',1),(2,14,'/styled/assets/images/Accessories/Beaded Ocean Necklace.png',1),(3,15,'/styled/assets/images/Accessories/Pearl Floral Earrings.png',1),(4,44,'/styled/assets/images/Accessories/Crochet Lace Bandana.png',1),(5,45,'/styled/assets/images/Accessories/Braid Tail Chain.png',1),(6,46,'/styled/assets/images/Accessories/Gold Arm Cuff.png',1),(7,47,'/styled/assets/images/Accessories/Hibiscus Hair Clips.png',1),(8,48,'/styled/assets/images/Accessories/Sun Stacking Rings.png',1),(9,49,'/styled/assets/images/Accessories/Starfish Layered Necklace.png',1),(10,50,'/styled/assets/images/Accessories/Oval Retro Sunglasses.png',1),(11,4,'/styled/assets/images/Bottoms/Wide-Leg Trousers.png',1),(12,5,'/styled/assets/images/Bottoms/Chocolate Satin Pants.png',1),(13,6,'/styled/assets/images/Bottoms/Black Maxi Skirt.png',1),(14,23,'/styled/assets/images/Bottoms/Midnight Lounge Shorts.png',1),(15,24,'/styled/assets/images/Bottoms/Sand Tailored Shorts.png',1),(16,25,'/styled/assets/images/Bottoms/Striped Wrap Skirt.png',1),(17,26,'/styled/assets/images/Bottoms/Bush Floral Skirt.png',1),(18,27,'/styled/assets/images/Bottoms/Cream Mini Skirt.png',1),(19,28,'/styled/assets/images/Bottoms/Pastel Bloom Skirt.png',1),(20,29,'/styled/assets/images/Bottoms/Sunshine Skirt.png',1),(21,7,'/styled/assets/images/Dresses/Tie-Strap Dress.png',1),(22,8,'/styled/assets/images/Dresses/Crimson Dress.png',1),(23,9,'/styled/assets/images/Dresses/Wine Satin Dress.png',1),(24,30,'/styled/assets/images/Dresses/Rosy Gingham Dress.png',1),(25,31,'/styled/assets/images/Dresses/Olive Halter Dress.png',1),(26,32,'/styled/assets/images/Dresses/Mocha Bodycon.png',1),(27,33,'/styled/assets/images/Dresses/Blush Lace Dress.png',1),(28,34,'/styled/assets/images/Dresses/Slate Blue Dress.png',1),(29,35,'/styled/assets/images/Dresses/Night Bodycon.png',1),(30,36,'/styled/assets/images/Dresses/Soft Pink Dress.png',1),(31,10,'/styled/assets/images/Outerwear/Leather Jacket.png',1),(32,11,'/styled/assets/images/Outerwear/Corduroy Jacket.png',1),(33,12,'/styled/assets/images/Outerwear/Two-Tone Bomber.png',1),(34,37,'/styled/assets/images/Outerwear/Minimalist Shacket.png',1),(35,38,'/styled/assets/images/Outerwear/Zip Windbreaker.png',1),(36,39,'/styled/assets/images/Outerwear/Utility Jacket.png',1),(37,40,'/styled/assets/images/Outerwear/Shearling Trucker.png',1),(38,41,'/styled/assets/images/Outerwear/Patterned Bomber.png',1),(39,42,'/styled/assets/images/Outerwear/Leather Moto.png',1),(40,43,'/styled/assets/images/Outerwear/Oversized Utility Shacket.png',1),(41,1,'/styled/assets/images/Tops/Mocha Wrap Tank.png',1),(42,2,'/styled/assets/images/Tops/Coca Knot Tee.png',1),(43,3,'/styled/assets/images/Tops/Cream Off-Shoulder.png',1),(44,16,'/styled/assets/images/Tops/Wine Halter Blouse.png',1),(45,17,'/styled/assets/images/Tops/Midnight Peplum Top.png',1),(46,18,'/styled/assets/images/Tops/Black Lace Tank.png',1),(47,19,'/styled/assets/images/Tops/Rust Ruffle Top.png',1),(48,20,'/styled/assets/images/Tops/Ivory Flowy Top.png',1),(49,21,'/styled/assets/images/Tops/Sheer Chocolate Top.png',1),(50,22,'/styled/assets/images/Tops/Textured Puff Sleeve Blouse.png',1);
/*!40000 ALTER TABLE `product_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `product_sizes`
--

DROP TABLE IF EXISTS `product_sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_sizes` (
  `size_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `size` enum('XS','S','M','L','XL','XXL') NOT NULL,
  `stock_qty` int(11) DEFAULT 0,
  `sku` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`size_id`),
  UNIQUE KEY `unique_product_size` (`product_id`,`size`),
  KEY `idx_product_id` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=228 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `product_sizes`
--

LOCK TABLES `product_sizes` WRITE;
/*!40000 ALTER TABLE `product_sizes` DISABLE KEYS */;
INSERT INTO `product_sizes` VALUES (1,1,'XS',10,NULL),(2,1,'S',15,NULL),(3,1,'M',19,NULL),(4,1,'L',12,NULL),(5,1,'XL',8,NULL),(6,2,'XS',8,NULL),(7,2,'S',12,NULL),(8,2,'M',18,NULL),(9,2,'L',10,NULL),(10,2,'XL',5,NULL),(11,3,'XS',5,NULL),(12,3,'S',10,NULL),(13,3,'M',15,NULL),(14,3,'L',8,NULL),(15,3,'XL',4,NULL),(16,4,'XS',6,NULL),(17,4,'S',10,NULL),(18,4,'M',14,NULL),(19,4,'L',8,NULL),(20,4,'XL',3,NULL),(21,10,'XS',3,NULL),(22,10,'S',5,NULL),(23,10,'M',8,NULL),(24,10,'L',5,NULL),(25,10,'XL',2,NULL),(26,16,'XS',6,NULL),(27,16,'S',10,NULL),(28,16,'M',7,NULL),(29,16,'L',10,NULL),(30,16,'XL',10,NULL),(31,17,'XS',10,NULL),(32,17,'S',8,NULL),(33,17,'M',9,NULL),(34,17,'L',10,NULL),(35,17,'XL',10,NULL),(36,18,'XS',16,NULL),(37,18,'S',11,NULL),(38,18,'M',8,NULL),(39,18,'L',10,NULL),(40,18,'XL',10,NULL),(41,19,'XS',10,NULL),(42,19,'S',10,NULL),(43,19,'M',10,NULL),(44,19,'L',10,NULL),(45,19,'XL',10,NULL),(46,20,'XS',10,NULL),(47,20,'S',10,NULL),(48,20,'M',8,NULL),(49,20,'L',10,NULL),(50,20,'XL',10,NULL),(51,21,'XS',10,NULL),(52,21,'S',10,NULL),(53,21,'M',10,NULL),(54,21,'L',10,NULL),(55,21,'XL',10,NULL),(56,22,'XS',10,NULL),(57,22,'S',10,NULL),(58,22,'M',10,NULL),(59,22,'L',10,NULL),(60,22,'XL',10,NULL),(61,23,'XS',10,NULL),(62,23,'S',10,NULL),(63,23,'M',10,NULL),(64,23,'L',10,NULL),(65,23,'XL',10,NULL),(66,24,'XS',10,NULL),(67,24,'S',10,NULL),(68,24,'M',10,NULL),(69,24,'L',10,NULL),(70,24,'XL',10,NULL),(71,25,'XS',10,NULL),(72,25,'S',10,NULL),(73,25,'M',10,NULL),(74,25,'L',10,NULL),(75,25,'XL',10,NULL),(76,26,'XS',10,NULL),(77,26,'S',10,NULL),(78,26,'M',10,NULL),(79,26,'L',10,NULL),(80,26,'XL',10,NULL),(81,27,'XS',10,NULL),(82,27,'S',10,NULL),(83,27,'M',10,NULL),(84,27,'L',10,NULL),(85,27,'XL',10,NULL),(91,29,'XS',10,NULL),(92,29,'S',10,NULL),(93,29,'M',10,NULL),(94,29,'L',10,NULL),(95,29,'XL',10,NULL),(96,30,'XS',9,NULL),(97,30,'S',10,NULL),(98,30,'M',8,NULL),(99,30,'L',10,NULL),(100,30,'XL',10,NULL),(101,31,'XS',10,NULL),(102,31,'S',10,NULL),(103,31,'M',10,NULL),(104,31,'L',10,NULL),(105,31,'XL',10,NULL),(106,32,'XS',10,NULL),(107,32,'S',10,NULL),(108,32,'M',10,NULL),(109,32,'L',10,NULL),(110,32,'XL',10,NULL),(111,33,'XS',10,NULL),(112,33,'S',10,NULL),(113,33,'M',10,NULL),(114,33,'L',10,NULL),(115,33,'XL',10,NULL),(116,34,'XS',10,NULL),(117,34,'S',10,NULL),(118,34,'M',10,NULL),(119,34,'L',10,NULL),(120,34,'XL',11,NULL),(121,35,'XS',10,NULL),(122,35,'S',10,NULL),(123,35,'M',10,NULL),(124,35,'L',10,NULL),(125,35,'XL',10,NULL),(126,36,'XS',10,NULL),(127,36,'S',10,NULL),(128,36,'M',10,NULL),(129,36,'L',10,NULL),(130,36,'XL',10,NULL),(131,37,'XS',10,NULL),(132,37,'S',10,NULL),(133,37,'M',10,NULL),(134,37,'L',10,NULL),(135,37,'XL',10,NULL),(136,38,'XS',10,NULL),(137,38,'S',10,NULL),(138,38,'M',10,NULL),(139,38,'L',10,NULL),(140,38,'XL',10,NULL),(141,39,'XS',10,NULL),(142,39,'S',10,NULL),(143,39,'M',10,NULL),(144,39,'L',10,NULL),(145,39,'XL',10,NULL),(146,40,'XS',10,NULL),(147,40,'S',10,NULL),(148,40,'M',10,NULL),(149,40,'L',10,NULL),(150,40,'XL',10,NULL),(151,41,'XS',10,NULL),(152,41,'S',10,NULL),(153,41,'M',10,NULL),(154,41,'L',10,NULL),(155,41,'XL',10,NULL),(156,42,'XS',10,NULL),(157,42,'S',10,NULL),(158,42,'M',10,NULL),(159,42,'L',10,NULL),(160,42,'XL',10,NULL),(161,43,'XS',10,NULL),(162,43,'S',10,NULL),(163,43,'M',10,NULL),(164,43,'L',10,NULL),(165,43,'XL',10,NULL),(169,45,'XS',1,''),(173,46,'XS',11,''),(174,47,'XS',10,''),(206,49,'XS',10,''),(222,28,'XS',11,''),(223,28,'S',10,''),(224,28,'M',10,''),(225,28,'L',10,''),(226,28,'XL',10,''),(227,60,'M',5,'QA-M');
/*!40000 ALTER TABLE `product_sizes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `promotions`
--

DROP TABLE IF EXISTS `promotions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `promotions` (
  `promo_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `min_order` decimal(10,2) DEFAULT 0.00,
  `usage_limit` int(11) DEFAULT NULL,
  `usage_count` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `expiry_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`promo_id`),
  UNIQUE KEY `code` (`code`),
  KEY `idx_code` (`code`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `promotions`
--

LOCK TABLES `promotions` WRITE;
/*!40000 ALTER TABLE `promotions` DISABLE KEYS */;
INSERT INTO `promotions` VALUES (10,'STYLED10','percent',20.00,100.00,100,7,1,'2026-06-06 07:00:00','2026-05-26 08:13:41'),(13,'WINTER20','percent',30.00,500.00,100,0,1,'2026-06-03 07:00:00','2026-05-31 11:20:30');
/*!40000 ALTER TABLE `promotions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `settings`
--

DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `group` varchar(50) NOT NULL,
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `group_key` (`group`,`key`)
) ENGINE=InnoDB AUTO_INCREMENT=46 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `settings`
--

LOCK TABLES `settings` WRITE;
/*!40000 ALTER TABLE `settings` DISABLE KEYS */;
INSERT INTO `settings` VALUES (1,'general','store-name','Test Store'),(2,'store','store-name','Styled'),(3,'store','store-email','orders.styledph@gmail.com'),(4,'store','store-currency','PHP'),(5,'store','store-timezone','Asia/Manila'),(6,'store','store-address','551 M.F. Jhocson Street, Sampaloc, Manila, Philippines'),(12,'shipping','shipping-free-threshold','1000'),(13,'shipping','shipping-standard-fee','100'),(14,'tax','tax-vat-rate','12'),(15,'tax','tax-inclusive','inclusive'),(16,'payment','payment-gcash','1'),(17,'payment','payment-card','1'),(18,'payment','payment-cod','1'),(28,'domain','domain-custom','styled.com'),(29,'domain','domain-storefront-url','https://styled.great-site.net/styled/auth.html');
/*!40000 ALTER TABLE `settings` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-03  1:22:28
