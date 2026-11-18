-- Schema fragment for the manual deposit request history.
-- This is not a full installation schema. Apply it outside the webroot.

CREATE TABLE IF NOT EXISTS `deposit_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `gateway` varchar(50) NOT NULL DEFAULT 'chaopao',
  `method` varchar(50) NOT NULL,
  `trx_id` varchar(100) NOT NULL,
  `gateway_trx_id` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','completed','failed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `trx_id` (`trx_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
