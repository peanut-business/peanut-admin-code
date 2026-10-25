-- peanut-release: 5.0.8
CREATE TABLE `pa_scaffold_upgrade_demo` (
  `id` BIGINT UNSIGNED NOT NULL,
  `release_version` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `message` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Scaffold upgrade demonstration metadata';

INSERT INTO `pa_scaffold_upgrade_demo` (`id`, `release_version`, `message`)
VALUES (1, '5.0.8', 'Scaffold upgrade migration applied.');
