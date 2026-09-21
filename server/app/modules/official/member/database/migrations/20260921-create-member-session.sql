-- 会员端访问凭证的持久会话与安全修订。
-- 旧 JWT 不含 session_key/tenant_id/session_revision，部署迁移后按安全策略立即失效。

ALTER TABLE `pa_member`
  ADD COLUMN `session_revision` BIGINT UNSIGNED NOT NULL DEFAULT 1
    COMMENT '会员安全域会话修订；改密、重置或停用时递增' AFTER `status`,
  ADD CONSTRAINT `chk_member_security_session_revision` CHECK (`session_revision` >= 1);

CREATE TABLE `pa_member_session` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `member_id` INT UNSIGNED NOT NULL,
  `session_revision` BIGINT UNSIGNED NOT NULL,
  `issued_at` BIGINT UNSIGNED NOT NULL,
  `expires_at` BIGINT UNSIGNED NOT NULL,
  `revoked_at` BIGINT UNSIGNED NULL,
  `revoke_reason` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_session_hash` (`session_hash`),
  KEY `idx_member_session_subject` (`tenant_id`, `member_id`, `revoked_at`, `expires_at`, `id`),
  CONSTRAINT `fk_member_session_tenant`
    FOREIGN KEY (`tenant_id`) REFERENCES `pa_tenant` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_session_member`
    FOREIGN KEY (`tenant_id`, `member_id`) REFERENCES `pa_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `chk_member_session_hash` CHECK (`session_hash` REGEXP '^[0-9a-f]{64}$'),
  CONSTRAINT `chk_member_session_record_revision` CHECK (`session_revision` >= 1),
  CONSTRAINT `chk_member_session_expiry` CHECK (`expires_at` > `issued_at`),
  CONSTRAINT `chk_member_session_revocation` CHECK (
    (`revoked_at` IS NULL AND `revoke_reason` IS NULL)
    OR (`revoked_at` IS NOT NULL AND `revoked_at` >= `issued_at` AND `revoke_reason` IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='会员端可撤销会话';
