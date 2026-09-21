-- peanut-release: 4.0.0-dev
-- Image metadata and variants reference the canonical pa_file_object ledger by file_key.
ALTER TABLE `pa_file_object`
  ADD UNIQUE KEY `uk_file_object_tenant_key` (`tenant_id`, `file_key`);

CREATE TABLE `pa_file_image_asset` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` INT UNSIGNED NOT NULL,
  `height` INT UNSIGNED NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `file_key`),
  CONSTRAINT `fk_file_image_asset_object` FOREIGN KEY (`tenant_id`, `file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_image_asset_dimensions` CHECK (`width` BETWEEN 1 AND 50000 AND `height` BETWEEN 1 AND 50000 AND `width` * `height` <= 100000000),
  CONSTRAINT `chk_file_image_asset_media_type` CHECK (`media_type` IN ('image/jpeg','image/png'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_derivative` (
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `source_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `variant_key` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `derivative_file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `width` INT UNSIGNED NOT NULL,
  `height` INT UNSIGNED NOT NULL,
  `media_type` VARCHAR(127) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  `updated_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`tenant_id`, `source_file_key`, `variant_key`),
  UNIQUE KEY `uk_file_derivative_object` (`tenant_id`, `derivative_file_key`),
  CONSTRAINT `fk_file_derivative_source` FOREIGN KEY (`tenant_id`, `source_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `fk_file_derivative_object` FOREIGN KEY (`tenant_id`, `derivative_file_key`) REFERENCES `pa_file_object` (`tenant_id`, `file_key`) ON DELETE RESTRICT,
  CONSTRAINT `chk_file_derivative_key` CHECK (`variant_key` REGEXP '^[a-z][a-z0-9-]{0,31}$'),
  CONSTRAINT `chk_file_derivative_dimensions` CHECK (`width` BETWEEN 1 AND 4096 AND `height` BETWEEN 1 AND 4096),
  CONSTRAINT `chk_file_derivative_media_type` CHECK (`media_type` IN ('image/jpeg','image/png')),
  CONSTRAINT `chk_file_derivative_distinct` CHECK (`source_file_key` <> `derivative_file_key`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `pa_file_delivery_nonce` (
  `token_id_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `consumed_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`token_id_hash`),
  KEY `idx_file_delivery_nonce_expiry` (`expires_at`),
  CONSTRAINT `chk_file_delivery_nonce_hash` CHECK (`token_id_hash` REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
