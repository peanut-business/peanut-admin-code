CREATE TABLE `pa_member_job` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `tenant_member_id` BIGINT UNSIGNED NOT NULL,
  `job_id` INT UNSIGNED NOT NULL,
  `assigned_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_member_job` (`tenant_id`, `tenant_member_id`, `job_id`),
  KEY `idx_member_job_job` (`tenant_id`, `job_id`),
  CONSTRAINT `fk_member_job_member` FOREIGN KEY (`tenant_id`, `tenant_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`, `id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_member_job_job` FOREIGN KEY (`tenant_id`, `job_id`) REFERENCES `pa_jobs` (`tenant_id`, `id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
