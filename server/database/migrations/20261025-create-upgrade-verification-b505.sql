-- peanut-release: 5.0.5
CREATE TABLE `pa_upgrade_verification_b505` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `introduced_in_version` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '5.0.5',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Dedicated upgrade verification table introduced in scaffold 5.0.5';
