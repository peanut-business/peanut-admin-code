-- peanut-release: 3.0.12
ALTER TABLE `pa_generator_table`
  ADD COLUMN `data_owner` VARCHAR(20) NULL COMMENT 'Explicit generated data owner' AFTER `template_type`,
  ADD COLUMN `target_edition` VARCHAR(20) NULL COMMENT 'Explicit generated deployment edition' AFTER `data_owner`,
  ADD CONSTRAINT `chk_generator_data_owner`
    CHECK (`data_owner` IS NULL OR `data_owner` IN ('tenant-orm','platform','instance','shared')),
  ADD CONSTRAINT `chk_generator_target_edition`
    CHECK (`target_edition` IS NULL OR `target_edition` IN ('standalone','multi-tenant'));
