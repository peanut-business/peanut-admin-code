-- peanut-release: 3.0.9
-- Append-only, secret-free evidence recorded by trusted Application adapters.
CREATE TABLE `pa_provider_qualification_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `evidence_key` VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `provider_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `scope_type` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NULL,
  `scope_reference` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `evidence_type` VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `outcome` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `config_digest` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `status_code` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_id` VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `observed_at` DATETIME(3) NOT NULL,
  `expires_at` DATETIME(3) NOT NULL,
  `recorded_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_provider_qualification_evidence_key` (`evidence_key`),
  KEY `idx_provider_qualification_subject_observed`
    (`provider_key`,`scope_type`,`tenant_id`,`observed_at`),
  KEY `idx_provider_qualification_expiry` (`expires_at`,`id`),
  CONSTRAINT `fk_provider_qualification_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_provider_qualification_scope`
    CHECK ((`scope_type`='tenant' AND `tenant_id` IS NOT NULL)
      OR (`scope_type`='instance' AND `tenant_id` IS NULL)),
  CONSTRAINT `chk_provider_qualification_type`
    CHECK (`evidence_type` IN ('connectivity','callback','production')),
  CONSTRAINT `chk_provider_qualification_outcome`
    CHECK (`outcome` IN ('passed','failed')),
  CONSTRAINT `chk_provider_qualification_digest`
    CHECK (`config_digest` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_provider_qualification_expiry_order`
    CHECK (`expires_at` > `observed_at`)
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Append-only safe Provider qualification evidence';
