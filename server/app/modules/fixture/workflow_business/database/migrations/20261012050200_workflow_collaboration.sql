CREATE TABLE IF NOT EXISTS `pa_fixture_workflow_collaboration` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tenant_id` BIGINT UNSIGNED NOT NULL,
  `entry_key` VARCHAR(64) NOT NULL,
  `object_key` VARCHAR(64) NOT NULL,
  `instance_key` VARCHAR(64) NOT NULL,
  `work_item_key` VARCHAR(64) NOT NULL,
  `kind` VARCHAR(16) NOT NULL,
  `note` VARCHAR(1000) NOT NULL,
  `reference` VARCHAR(128) NOT NULL,
  `actor_member_id` BIGINT UNSIGNED NOT NULL,
  `created_at` DATETIME(3) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fixture_workflow_collaboration` (`tenant_id`, `entry_key`),
  KEY `idx_fixture_workflow_collaboration_object` (`tenant_id`, `object_key`, `instance_key`, `id`),
  CHECK (`kind` IN ('comment', 'supplement'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
