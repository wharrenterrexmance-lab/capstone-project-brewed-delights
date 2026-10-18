-- Clean Brewed Delights database with the current menu catalog.
CREATE DATABASE IF NOT EXISTS brewed_delights_clean CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE brewed_delights_clean;
DROP TABLE IF EXISTS product_feedback;
DROP TABLE IF EXISTS user_identities;

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: brewed_delights
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
-- Table structure for table `api_tokens`
--

DROP TABLE IF EXISTS `api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `api_tokens` (
  `token_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `revoked` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_token_hash` (`token_hash`),
  KEY `fk_api_tokens_user` (`user_id`),
  CONSTRAINT `fk_api_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `feedback`
--

DROP TABLE IF EXISTS `feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `feedback` (
  `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `rating` tinyint(4) NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  KEY `fk_feedback_user` (`user_id`),
  KEY `fk_feedback_order` (`order_id`),
  CONSTRAINT `fk_feedback_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_feedback_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_attempts` (
  `attempt_id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(150) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`attempt_id`),
  KEY `idx_login_attempts_lookup` (`email`,`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `loyalty_history`
--

DROP TABLE IF EXISTS `loyalty_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `loyalty_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `order_id` int(11) DEFAULT NULL,
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `fk_loyalty_user` (`user_id`),
  KEY `fk_loyalty_order` (`order_id`),
  CONSTRAINT `fk_loyalty_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_loyalty_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `menu`
--

DROP TABLE IF EXISTS `menu`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `price` decimal(8,2) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(60) NOT NULL DEFAULT 'Coffee',
  `image_path` varchar(255) DEFAULT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `is_available` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`item_id`)
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `menu_options`
--

DROP TABLE IF EXISTS `menu_options`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `menu_options` (
  `option_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_id` int(11) NOT NULL,
  `label` varchar(100) NOT NULL,
  `price` decimal(8,2) NOT NULL,
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `uq_menu_option_label` (`item_id`,`label`),
  CONSTRAINT `fk_menu_options_item` FOREIGN KEY (`item_id`) REFERENCES `menu` (`item_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=155 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `order_items`
--

DROP TABLE IF EXISTS `order_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_items` (
  `order_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(120) NOT NULL,
  `unit_price` decimal(8,2) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `line_total` decimal(10,2) NOT NULL,
  PRIMARY KEY (`order_item_id`),
  KEY `fk_oi_order` (`order_id`),
  KEY `fk_oi_item` (`item_id`),
  CONSTRAINT `fk_oi_item` FOREIGN KEY (`item_id`) REFERENCES `menu` (`item_id`),
  CONSTRAINT `fk_oi_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `order_status_history`
--

DROP TABLE IF EXISTS `order_status_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `order_status_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `status` varchar(30) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`history_id`),
  KEY `fk_osh_order` (`order_id`),
  KEY `fk_osh_user` (`changed_by`),
  CONSTRAINT `fk_osh_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_osh_user` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `orders`
--

DROP TABLE IF EXISTS `orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('Pending','Preparing','Ready','Out for Delivery','Completed','Cancelled') NOT NULL DEFAULT 'Pending',
  `payment_status` enum('Unpaid','Pending Verification','Paid','Failed','Refunded') NOT NULL DEFAULT 'Unpaid',
  `payment_method` enum('GCash','Cash') NOT NULL DEFAULT 'Cash',
  `points_awarded` tinyint(1) NOT NULL DEFAULT 0,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `loyalty_discount` decimal(8,2) NOT NULL DEFAULT 0.00,
  `reference_code` varchar(20) DEFAULT NULL,
  `order_type` enum('Pickup','Delivery') NOT NULL DEFAULT 'Pickup',
  `delivery_fee` decimal(8,2) NOT NULL DEFAULT 0.00,
  `delivery_address` varchar(255) DEFAULT NULL,
  `delivery_lat` decimal(10,7) DEFAULT NULL,
  `delivery_lng` decimal(10,7) DEFAULT NULL,
  `rider_id` int(11) DEFAULT NULL,
  `rider_lat` decimal(10,7) DEFAULT NULL,
  `rider_lng` decimal(10,7) DEFAULT NULL,
  `rider_location_updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`order_id`),
  UNIQUE KEY `uq_orders_reference_code` (`reference_code`),
  KEY `fk_orders_user` (`user_id`),
  KEY `fk_orders_rider` (`rider_id`),
  CONSTRAINT `fk_orders_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=36 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `payments`
--

DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `method` enum('GCash','Cash') NOT NULL,
  `reference_number` varchar(50) DEFAULT NULL,
  `status` enum('Unpaid','Pending Verification','Paid','Failed','Refunded') NOT NULL DEFAULT 'Unpaid',
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`payment_id`),
  KEY `fk_payments_order` (`order_id`),
  KEY `fk_payments_verifier` (`verified_by`),
  CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_payments_verifier` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `rider_feedback`
--

DROP TABLE IF EXISTS `rider_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rider_feedback` (
  `feedback_id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `rating` tinyint(4) NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`feedback_id`),
  UNIQUE KEY `uq_rider_feedback_order` (`order_id`),
  KEY `idx_rider_feedback_rider_date` (`rider_id`,`created_at`),
  KEY `fk_rider_feedback_customer` (`customer_id`),
  CONSTRAINT `fk_rider_feedback_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rider_feedback_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_rider_feedback_rider` FOREIGN KEY (`rider_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `chk_rider_feedback_rating` CHECK (`rating` between 1 and 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `username` varchar(32) DEFAULT NULL,
  `email` varchar(150) NOT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `use_address_for_delivery` tinyint(1) NOT NULL DEFAULT 0,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','admin','staff','rider') NOT NULL DEFAULT 'customer',
  `points_balance` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping events for database 'brewed_delights'
--

--
-- Dumping routines for database 'brewed_delights'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-06  8:51:31

ALTER TABLE users MODIFY password_hash VARCHAR(255) NULL, ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email;
CREATE TABLE user_identities (identity_id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, provider VARCHAR(30) NOT NULL, provider_subject VARCHAR(191) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_identity_provider_subject (provider, provider_subject), UNIQUE KEY uq_identity_user_provider (user_id, provider), CONSTRAINT fk_identity_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_feedback (
  feedback_id INT AUTO_INCREMENT PRIMARY KEY,
  order_item_id INT NOT NULL,
  order_id INT NOT NULL,
  customer_id INT NOT NULL,
  rating TINYINT NOT NULL,
  comments TEXT,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_feedback_order_item (order_item_id),
  INDEX idx_product_feedback_order_customer (order_id, customer_id),
  CONSTRAINT fk_product_feedback_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(order_item_id) ON DELETE CASCADE,
  CONSTRAINT fk_product_feedback_order FOREIGN KEY (order_id) REFERENCES orders(order_id) ON DELETE CASCADE,
  CONSTRAINT fk_product_feedback_customer FOREIGN KEY (customer_id) REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT chk_product_feedback_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB;

-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: brewed_delights
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `menu`
--

LOCK TABLES `menu` WRITE;
/*!40000 ALTER TABLE `menu` DISABLE KEYS */;
INSERT INTO `menu` VALUES (1,'Espresso',95.00,'A concentrated shot pulled from single-origin beans.','Coffee','assets/images/menu/espresso.jpg',0,0,'2026-08-12 07:25:01'),(2,'Americano',105.00,'Espresso lengthened with hot water for a lighter body.','Coffee','assets/images/menu/americano.jpg',0,0,'2026-08-12 07:25:01'),(3,'Cafe Latte',130.00,'Espresso with steamed milk and a thin layer of foam.','Coffee','assets/images/menu/cafe-latte.jpg',0,0,'2026-08-12 07:25:01'),(4,'Cappuccino',130.00,'Equal parts espresso, steamed milk, and milk foam.','Coffee','assets/images/menu/cappuccino.jpg',0,0,'2026-08-12 07:25:01'),(5,'Spanish Latte',140.00,'Sweetened with condensed milk for a creamy finish.','Coffee','assets/images/menu/spanish-latte.jpg',0,0,'2026-08-12 07:25:01'),(6,'Matcha Latte',150.00,'Stone-ground green tea whisked with steamed milk.','Non-Coffee','assets/images/menu/matcha-latte.jpg',0,0,'2026-08-12 07:25:01'),(7,'Hot Chocolate',120.00,'Rich cocoa steamed with whole milk.','Non-Coffee','assets/images/menu/hot-chocolate.jpg',0,0,'2026-08-12 07:25:01'),(8,'Croissant',85.00,'Butter croissant, baked daily.','Pastry','assets/images/menu/croissant.jpg',0,0,'2026-08-12 07:25:01'),(9,'Blueberry Muffin',90.00,'Moist muffin loaded with blueberries.','Pastry','assets/images/menu/blueberry-muffin.jpg',0,0,'2026-08-12 07:25:01'),(10,'Banana Bread',95.00,'Sliced loaf, served warm on request.','Pastry','assets/images/menu/banana-bread.jpg',0,0,'2026-08-12 07:25:01'),(11,'Saucy Takoyaki Fries',115.00,'Fries topped with takoyaki-inspired sauce.','Fries',NULL,0,0,'2026-09-30 05:10:13'),(12,'Ultimate Cheesy Fries',115.00,'Fries loaded with cheese.','Fries',NULL,0,0,'2026-09-30 05:10:13'),(13,'Cheesy Ham Fries',135.00,'Fries with cheese and ham.','Fries',NULL,0,0,'2026-09-30 05:10:13'),(14,'Classic Beef Nachos',195.00,'Classic nachos topped with beef.','Nachos',NULL,0,0,'2026-09-30 05:10:13'),(15,'Cheesy Beef Nachos',215.00,'Beef nachos topped with cheese.','Nachos',NULL,0,0,'2026-09-30 05:10:13'),(16,'Salsa Beef Nachos',215.00,'Beef nachos served with salsa.','Nachos',NULL,0,0,'2026-09-30 05:10:13'),(17,'Special Takoyaki - Solo (4 pcs)',65.00,'Vegetable takoyaki, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(18,'Special Takoyaki - Double (8 pcs)',125.00,'Vegetable takoyaki, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(19,'Special Takoyaki - Family (24 pcs)',335.00,'Vegetable takoyaki, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(20,'Extra Special Takoyaki - Solo (4 pcs)',75.00,'Takoyaki with squid cuts, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(21,'Extra Special Takoyaki - Double (8 pcs)',145.00,'Takoyaki with squid cuts, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(22,'Extra Special Takoyaki - Family (24 pcs)',415.00,'Takoyaki with squid cuts, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(23,'Octobits Takoyaki - Solo (4 pcs)',75.00,'Takoyaki with octopus tentacle bits, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(24,'Octobits Takoyaki - Double (8 pcs)',145.00,'Takoyaki with octopus tentacle bits, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(25,'Octobits Takoyaki - Family (24 pcs)',415.00,'Takoyaki with octopus tentacle bits, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(26,'Ham n\' Cheese Takoyaki - Solo (4 pcs)',75.00,'Takoyaki with ham and cheese, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(27,'Ham n\' Cheese Takoyaki - Double (8 pcs)',145.00,'Takoyaki with ham and cheese, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(28,'Ham n\' Cheese Takoyaki - Family (24 pcs)',415.00,'Takoyaki with ham and cheese, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(29,'Kani-yaki - Solo (4 pcs)',75.00,'Takoyaki with crab stick, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(30,'Kani-yaki - Double (8 pcs)',145.00,'Takoyaki with crab stick, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(31,'Kani-yaki - Family (24 pcs)',415.00,'Takoyaki with crab stick, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(32,'Ebiyaki - Solo (4 pcs)',75.00,'Takoyaki with shrimp cuts, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(33,'Ebiyaki - Double (8 pcs)',145.00,'Takoyaki with shrimp cuts, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(34,'Ebiyaki - Family (24 pcs)',415.00,'Takoyaki with shrimp cuts, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(35,'Cheese Bomb - Solo (4 pcs)',105.00,'Takoyaki with cheddar, mozzarella, quickmelt, and cheese sauce, 4 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(36,'Cheese Bomb - Double (8 pcs)',205.00,'Takoyaki with cheddar, mozzarella, quickmelt, and cheese sauce, 8 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(37,'Cheese Bomb - Family (24 pcs)',595.00,'Takoyaki with cheddar, mozzarella, quickmelt, and cheese sauce, 24 pieces.','Takoyaki',NULL,0,0,'2026-09-30 05:10:13'),(38,'Saucy Takoyaki Fries',115.00,'Fries topped with takoyaki-inspired sauce.','Fries',NULL,0,1,'2026-09-30 05:48:54'),(39,'Ultimate Cheesy Fries',115.00,'Fries loaded with cheese.','Fries',NULL,0,1,'2026-09-30 05:48:54'),(40,'Cheesy Ham Fries',135.00,'Fries with cheese and ham.','Fries',NULL,0,1,'2026-09-30 05:48:54'),(41,'Flavoured Fries',35.00,'Choose cheese, honey butter, sour cream, or BBQ flavor and a size.','Fries',NULL,0,1,'2026-09-30 05:48:54'),(42,'Classic Beef Nachos',195.00,'Classic nachos topped with beef.','Nachos',NULL,0,1,'2026-09-30 05:48:54'),(43,'Cheesy Beef Nachos',215.00,'Beef nachos topped with cheese.','Nachos',NULL,0,1,'2026-09-30 05:48:54'),(44,'Salsa Beef Nachos',215.00,'Beef nachos served with salsa.','Nachos',NULL,0,1,'2026-09-30 05:48:54'),(45,'Special Takoyaki',65.00,'Vegetable takoyaki.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(46,'Extra Special Takoyaki',75.00,'Takoyaki with squid cuts.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(47,'Octobits Takoyaki',75.00,'Takoyaki with octopus tentacle bits.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(48,'Ham n\' Cheese Takoyaki',75.00,'Takoyaki with ham and cheese.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(49,'Kani-yaki',75.00,'Takoyaki with crab stick.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(50,'Ebiyaki',75.00,'Takoyaki with shrimp cuts.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(51,'Cheese Bomb',105.00,'Takoyaki with cheddar, mozzarella, quickmelt, and cheese sauce.','Takoyaki',NULL,0,1,'2026-09-30 05:48:54'),(52,'Caramel Sweet Corn',155.00,'Caramel sweet corn drink.','Signature Drinks',NULL,0,1,'2026-09-30 05:48:54'),(53,'Kit Kat Green Tea Choco',155.00,'Green tea and chocolate signature drink.','Signature Drinks',NULL,0,1,'2026-09-30 05:48:54'),(54,'Oreo Overload',155.00,'Oreo signature drink.','Signature Drinks',NULL,0,1,'2026-09-30 05:48:54'),(55,'Milo Crunch',155.00,'Milo signature drink with a crunchy finish.','Signature Drinks',NULL,0,1,'2026-09-30 05:48:54'),(56,'Tiger Muscovado',155.00,'Muscovado signature drink.','Signature Drinks',NULL,0,1,'2026-09-30 05:48:54'),(57,'Okinawa Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(58,'Hokkaido Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(59,'Wintermelon Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(60,'Taro Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(61,'Matcha Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(62,'Chocolate Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(63,'Thai Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(64,'Salted Caramel Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(65,'Cookies and Cream Milk Tea',105.00,'Classic milk tea with pearl.','Classic Milk Tea',NULL,0,1,'2026-09-30 05:48:54'),(66,'Chocolate Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(67,'Hershey\'s Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(68,'Nutella Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(69,'Dark Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(70,'Black Forest Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(71,'Red Velvet Choco Delight',145.00,'Choco delight with chocolate chips, cream cheese, and pearl.','Choco Delights',NULL,0,1,'2026-09-30 05:48:54'),(72,'Green Apple Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(73,'Lychee Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(74,'Passion Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(75,'Strawberry Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(76,'Blueberry Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(77,'Lemon Fruit Tea',105.00,'Fruit tea with fruit jelly.','Fruit Tea',NULL,0,1,'2026-09-30 05:48:54'),(78,'Pearl',25.00,'Add-on: pearl.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(79,'Cream Cheese',25.00,'Add-on: cream cheese.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(80,'Crystals',25.00,'Add-on: crystals.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(81,'Fruit Jelly',25.00,'Add-on: fruit jelly.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(82,'Cheese Cake',25.00,'Add-on: cheese cake.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(83,'Oreo Toppings',25.00,'Add-on: Oreo toppings.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(84,'Coffee Jelly',25.00,'Add-on: coffee jelly.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(85,'Choco Chips',25.00,'Add-on: chocolate chips.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(86,'Espresso Shot',30.00,'Add-on: espresso shot.','Add-ons',NULL,0,1,'2026-09-30 05:48:54'),(87,'Brown Sugar Syrup',25.00,'Add syrup: brown sugar.','Syrups',NULL,0,1,'2026-09-30 05:48:54'),(88,'Caramel Syrup',25.00,'Add syrup: caramel.','Syrups',NULL,0,1,'2026-09-30 05:48:54'),(89,'Strawberry Syrup',25.00,'Add syrup: strawberry.','Syrups',NULL,0,1,'2026-09-30 05:48:54'),(90,'Chocolate Syrup',25.00,'Add syrup: chocolate.','Syrups',NULL,0,1,'2026-09-30 05:48:54'),(91,'Original Philippine Coffee Beans',95.00,'Hot coffee. Choose Barako from Batangas or Sagada from Mountain Province.','Hot Coffee',NULL,0,1,'2026-09-30 05:48:54'),(92,'Flavoured Coffee Beans',95.00,'Hot coffee. Choose mocha or hazelnut.','Hot Coffee',NULL,0,1,'2026-09-30 05:48:54'),(93,'Iced Coffee Black',114.00,'Cold coffee, served over ice.','Cold Coffee',NULL,0,1,'2026-09-30 05:48:54'),(94,'Iced Coffee Latte',124.00,'Cold coffee with milk.','Cold Coffee',NULL,0,1,'2026-09-30 05:48:54'),(95,'Iced Caramel Latte',134.00,'Cold coffee with caramel and milk.','Cold Coffee',NULL,0,1,'2026-09-30 05:48:54'),(96,'Cold White Brew',144.00,'Cold white brew coffee.','Cold Coffee',NULL,0,1,'2026-09-30 05:48:54'),(97,'Coffee Matcha',135.00,'Cold coffee and matcha.','Cold Coffee',NULL,0,1,'2026-09-30 05:48:54'),(98,'Hot Chocolate',95.00,'Hot chocolate.','Hot Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(99,'Hot Tea',110.00,'Hot tea.','Hot Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(100,'Matcha Latte',135.00,'Cold drink made with ceremonial-grade matcha.','Cold Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(101,'Strawberry Matcha',150.00,'Cold strawberry drink with ceremonial-grade matcha.','Cold Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(102,'Iced Milky Choco',150.00,'Iced milky chocolate.','Cold Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(103,'Caramel Macchiato Frappe',165.00,'Ice-blended coffee.','Frapped Coffee',NULL,0,1,'2026-09-30 05:48:54'),(104,'Cappuccino Frappe',165.00,'Ice-blended coffee.','Frapped Coffee',NULL,0,1,'2026-09-30 05:48:54'),(105,'Mocha Frappe',165.00,'Ice-blended coffee.','Frapped Coffee',NULL,0,1,'2026-09-30 05:48:54'),(106,'Coffee Jelly Frappe',180.00,'Ice-blended coffee with coffee jelly.','Frapped Coffee',NULL,0,1,'2026-09-30 05:48:54'),(107,'Choco Chips Frappe',180.00,'Ice-blended coffee with chocolate chips.','Frapped Coffee',NULL,0,1,'2026-09-30 05:48:54'),(108,'Strawberry Blend',165.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(109,'Matcha Blend',165.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(110,'Chocolate Blend',165.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(111,'Black Forest Blend',165.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(112,'Red Velvet Blend',165.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54'),(113,'Cookies n\' Cream Blend',180.00,'Ice-blended non-coffee drink.','Frapped Non-Coffee',NULL,0,1,'2026-09-30 05:48:54');
/*!40000 ALTER TABLE `menu` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping data for table `menu_options`
--

LOCK TABLES `menu_options` WRITE;
/*!40000 ALTER TABLE `menu_options` DISABLE KEYS */;
INSERT INTO `menu_options` VALUES (1,41,'Cheese - Small',35.00),(2,41,'Cheese - Medium',50.00),(3,41,'Cheese - Large',100.00),(4,41,'Honey Butter - Small',35.00),(5,41,'Honey Butter - Medium',50.00),(6,41,'Honey Butter - Large',100.00),(7,41,'Sour Cream - Small',35.00),(8,41,'Sour Cream - Medium',50.00),(9,41,'Sour Cream - Large',100.00),(10,41,'BBQ - Small',35.00),(11,41,'BBQ - Medium',50.00),(12,41,'BBQ - Large',100.00),(13,45,'Solo (4 pcs)',65.00),(14,45,'Double (8 pcs)',125.00),(15,45,'Family (24 pcs)',335.00),(16,46,'Solo (4 pcs)',75.00),(17,46,'Double (8 pcs)',145.00),(18,46,'Family (24 pcs)',415.00),(19,47,'Solo (4 pcs)',75.00),(20,47,'Double (8 pcs)',145.00),(21,47,'Family (24 pcs)',415.00),(22,48,'Solo (4 pcs)',75.00),(23,48,'Double (8 pcs)',145.00),(24,48,'Family (24 pcs)',415.00),(25,49,'Solo (4 pcs)',75.00),(26,49,'Double (8 pcs)',145.00),(27,49,'Family (24 pcs)',415.00),(28,50,'Solo (4 pcs)',75.00),(29,50,'Double (8 pcs)',145.00),(30,50,'Family (24 pcs)',415.00),(31,51,'Solo (4 pcs)',105.00),(32,51,'Double (8 pcs)',205.00),(33,51,'Family (24 pcs)',595.00),(34,52,'Medium',155.00),(35,52,'Large',165.00),(36,53,'Medium',155.00),(37,53,'Large',165.00),(38,54,'Medium',155.00),(39,54,'Large',165.00),(40,55,'Medium',155.00),(41,55,'Large',165.00),(42,56,'Medium',155.00),(43,56,'Large',165.00),(44,57,'Medium',105.00),(45,57,'Large',115.00),(46,57,'1 LTR',175.00),(47,58,'Medium',105.00),(48,58,'Large',115.00),(49,58,'1 LTR',175.00),(50,59,'Medium',105.00),(51,59,'Large',115.00),(52,59,'1 LTR',175.00),(53,60,'Medium',105.00),(54,60,'Large',115.00),(55,60,'1 LTR',175.00),(56,61,'Medium',105.00),(57,61,'Large',115.00),(58,61,'1 LTR',175.00),(59,62,'Medium',105.00),(60,62,'Large',115.00),(61,62,'1 LTR',175.00),(62,63,'Medium',105.00),(63,63,'Large',115.00),(64,63,'1 LTR',175.00),(65,64,'Medium',105.00),(66,64,'Large',115.00),(67,64,'1 LTR',175.00),(68,65,'Medium',105.00),(69,65,'Large',115.00),(70,65,'1 LTR',175.00),(71,66,'Medium',145.00),(72,66,'Large',155.00),(73,66,'1 LTR',225.00),(74,67,'Medium',145.00),(75,67,'Large',155.00),(76,67,'1 LTR',225.00),(77,68,'Medium',145.00),(78,68,'Large',155.00),(79,68,'1 LTR',225.00),(80,69,'Medium',145.00),(81,69,'Large',155.00),(82,69,'1 LTR',225.00),(83,70,'Medium',145.00),(84,70,'Large',155.00),(85,70,'1 LTR',225.00),(86,71,'Medium',145.00),(87,71,'Large',155.00),(88,71,'1 LTR',225.00),(89,72,'Medium',105.00),(90,72,'Large',115.00),(91,72,'1 LTR',175.00),(92,73,'Medium',105.00),(93,73,'Large',115.00),(94,73,'1 LTR',175.00),(95,74,'Medium',105.00),(96,74,'Large',115.00),(97,74,'1 LTR',175.00),(98,75,'Medium',105.00),(99,75,'Large',115.00),(100,75,'1 LTR',175.00),(101,76,'Medium',105.00),(102,76,'Large',115.00),(103,76,'1 LTR',175.00),(104,77,'Medium',105.00),(105,77,'Large',115.00),(106,77,'1 LTR',175.00),(107,91,'Barako (Batangas) - 8 oz',95.00),(108,91,'Barako (Batangas) - 12 oz',110.00),(109,91,'Sagada (Mountain Province) - 8 oz',95.00),(110,91,'Sagada (Mountain Province) - 12 oz',110.00),(111,92,'Mocha - 8 oz',95.00),(112,92,'Mocha - 12 oz',110.00),(113,92,'Hazelnut - 8 oz',95.00),(114,92,'Hazelnut - 12 oz',110.00),(115,93,'Medium',114.00),(116,93,'Large',124.00),(117,94,'Medium',124.00),(118,94,'Large',134.00),(119,95,'Medium',134.00),(120,95,'Large',144.00),(121,96,'Medium',144.00),(122,96,'Large',154.00),(123,97,'Medium',135.00),(124,97,'Large',145.00),(125,98,'8 oz',95.00),(126,98,'12 oz',110.00),(127,100,'Medium',135.00),(128,100,'Large',150.00),(129,101,'Medium',150.00),(130,101,'Large',165.00),(131,102,'Medium',150.00),(132,102,'Large',165.00),(133,103,'Medium',165.00),(134,103,'Large',180.00),(135,104,'Medium',165.00),(136,104,'Large',180.00),(137,105,'Medium',165.00),(138,105,'Large',180.00),(139,106,'Medium',180.00),(140,106,'Large',195.00),(141,107,'Medium',180.00),(142,107,'Large',195.00),(143,108,'Medium',165.00),(144,108,'Large',180.00),(145,109,'Medium',165.00),(146,109,'Large',180.00),(147,110,'Medium',165.00),(148,110,'Large',180.00),(149,111,'Medium',165.00),(150,111,'Large',180.00),(151,112,'Medium',165.00),(152,112,'Large',180.00),(153,113,'Medium',180.00),(154,113,'Large',195.00);
/*!40000 ALTER TABLE `menu_options` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-06  8:51:31
