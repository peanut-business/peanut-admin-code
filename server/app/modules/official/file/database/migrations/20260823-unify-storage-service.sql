-- peanut-release: 3.0.6
-- Instance accounts, immutable spaces, public/private routes and one object ledger.
CREATE TABLE `pa_storage_account` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `account_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `driver` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `name` VARCHAR(128) NOT NULL, `credential_ciphertext` MEDIUMTEXT NULL, `credential_key_version` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL, `credential_rotated_at` DATETIME(3) NULL,
 `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active', `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_storage_account_key` (`account_key`),
 CONSTRAINT `chk_storage_account_driver` CHECK (`driver` IN ('local','qiniu','aliyun','qcloud')),
 CONSTRAINT `chk_storage_account_status` CHECK (`status` IN ('active','disabled')),
 CONSTRAINT `chk_storage_account_credentials` CHECK ((`driver`='local' AND `credential_ciphertext` IS NULL AND `credential_key_version` IS NULL AND `credential_rotated_at` IS NULL) OR (`driver`<>'local' AND `credential_ciphertext` IS NOT NULL AND `credential_key_version` IS NOT NULL AND `credential_rotated_at` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `pa_storage_space` (
 `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, `space_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `account_id` BIGINT UNSIGNED NOT NULL,
 `name` VARCHAR(128) NOT NULL, `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `bucket` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL, `region` VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NULL,
 `endpoint` VARCHAR(500) NULL, `access_domain` VARCHAR(500) NULL, `local_path` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 `status` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active', `created_at` DATETIME(3) NOT NULL, `updated_at` DATETIME(3) NOT NULL,
 PRIMARY KEY (`id`), UNIQUE KEY `uk_storage_space_key` (`space_key`), UNIQUE KEY `uk_storage_space_location` (`account_id`,`bucket`,`region`),
 CONSTRAINT `fk_storage_space_account` FOREIGN KEY (`account_id`) REFERENCES `pa_storage_account` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `chk_storage_space_access` CHECK (`access_type` IN ('public','private')), CONSTRAINT `chk_storage_space_status` CHECK (`status` IN ('active','read_only','disabled')),
 CONSTRAINT `chk_storage_space_shape` CHECK ((`local_path` IS NOT NULL AND `bucket` IS NULL AND `region` IS NULL AND `endpoint` IS NULL) OR (`local_path` IS NULL AND `bucket` IS NOT NULL)),
 CONSTRAINT `chk_storage_space_local_path` CHECK (`local_path` IS NULL OR `local_path` IN ('public/storage','private/storage'))
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `pa_storage_route` (
 `route_key` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 `space_id` BIGINT UNSIGNED NOT NULL, `updated_at` DATETIME(3) NOT NULL, PRIMARY KEY (`route_key`), KEY `idx_storage_route_space` (`space_id`),
 CONSTRAINT `fk_storage_route_space` FOREIGN KEY (`space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
 CONSTRAINT `chk_storage_route_access` CHECK (`access_type` IN ('public','private')),
 CONSTRAINT `chk_storage_route_key` CHECK (`route_key` REGEXP '^[a-z][a-z0-9._-]{2,95}$')
) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `pa_storage_account` (`account_key`,`driver`,`name`,`credential_ciphertext`,`credential_key_version`,`credential_rotated_at`,`status`,`created_at`,`updated_at`) VALUES ('local','local','本地存储',NULL,NULL,NULL,'active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3));
INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-public',`id`,'本地公开文件','public','public/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';
INSERT INTO `pa_storage_space` (`space_key`,`account_id`,`name`,`access_type`,`local_path`,`status`,`created_at`,`updated_at`)
 SELECT 'local-private',`id`,'本地私有文件','private','private/storage','active',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM `pa_storage_account` WHERE `account_key`='local';
INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`)
 SELECT 'default.public','public',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-public';
INSERT INTO `pa_storage_route` (`route_key`,`access_type`,`space_id`,`updated_at`) SELECT 'default.private','private',`id`,UTC_TIMESTAMP(3) FROM `pa_storage_space` WHERE `space_key`='local-private';

ALTER TABLE `pa_file_object` MODIFY `sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, MODIFY `created_by_member_id` BIGINT UNSIGNED NULL,
 ADD COLUMN `purpose` VARCHAR(96) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'export.csv' AFTER `tenant_id`,
 ADD COLUMN `access_type` VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'private' AFTER `purpose`,
 ADD COLUMN `storage_space_id` BIGINT UNSIGNED NULL AFTER `access_type`, ADD COLUMN `disposition` VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'attachment' AFTER `storage_key`;
UPDATE `pa_file_object` f JOIN `pa_storage_space` s ON s.`space_key`='local-private' SET f.`storage_space_id`=s.`id`,f.`purpose`='export.csv',f.`access_type`='private' WHERE f.`storage_provider_key`='app.private-runtime';
ALTER TABLE `pa_file` ADD COLUMN `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER `tenant_id`;
INSERT INTO `pa_file_object` (`file_key`,`tenant_id`,`purpose`,`access_type`,`storage_space_id`,`storage_provider_key`,`storage_key`,`disposition`,`original_name`,`media_type`,`size_bytes`,`sha256`,`status`,`created_by_member_id`,`revision`,`created_at`,`updated_at`,`archived_at`)
 SELECT CONCAT('file_',MD5(CONCAT('legacy-material:',f.`tenant_id`,':',f.`id`))),f.`tenant_id`,CASE f.`type` WHEN 10 THEN 'material.image' WHEN 20 THEN 'material.video' ELSE 'material.file' END,'public',s.`id`,CONCAT('legacy.',f.`storage`),
 CASE WHEN f.`storage`='local' AND f.`uri` LIKE 'storage/%' THEN SUBSTRING(f.`uri`,9) ELSE f.`uri` END,'inline',f.`name`,'application/octet-stream',0,NULL,CASE WHEN f.`delete_time` IS NULL THEN 'ready' ELSE 'archived' END,NULL,1,FROM_UNIXTIME(f.`create_time`),FROM_UNIXTIME(f.`update_time`),CASE WHEN f.`delete_time` IS NULL THEN NULL ELSE FROM_UNIXTIME(f.`delete_time`) END
 FROM `pa_file` f JOIN `pa_storage_space` s ON s.`space_key`=CASE WHEN f.`storage`='local' THEN 'local-public' ELSE CONCAT('legacy-',f.`storage`,'-public') END;
UPDATE `pa_file` SET `file_key`=CONCAT('file_',MD5(CONCAT('legacy-material:',`tenant_id`,':',`id`)));
ALTER TABLE `pa_file` MODIFY `file_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, ADD UNIQUE KEY `uk_file_object_reference` (`file_key`),
 ADD CONSTRAINT `fk_file_object_reference` FOREIGN KEY (`file_key`) REFERENCES `pa_file_object` (`file_key`) ON DELETE RESTRICT, DROP COLUMN `uri`, DROP COLUMN `storage`;
ALTER TABLE `pa_file_object` DROP FOREIGN KEY `fk_file_object_member`, DROP INDEX `uk_file_object_storage`, DROP COLUMN `storage_provider_key`,
 CHANGE COLUMN `storage_key` `object_key` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, MODIFY `storage_space_id` BIGINT UNSIGNED NOT NULL,
 ADD UNIQUE KEY `uk_file_object_storage` (`storage_space_id`,`object_key`), ADD KEY `idx_file_object_purpose` (`tenant_id`,`purpose`,`status`,`id`),
 ADD CONSTRAINT `fk_file_object_space` FOREIGN KEY (`storage_space_id`) REFERENCES `pa_storage_space` (`id`) ON DELETE RESTRICT,
 DROP CHECK `chk_file_object_status`, DROP CHECK `chk_file_object_archive_shape`,
 ADD CONSTRAINT `chk_file_object_purpose` CHECK (`purpose` REGEXP '^[a-z][a-z0-9._-]{2,95}$'), ADD CONSTRAINT `chk_file_object_access` CHECK (`access_type` IN ('public','private')),
 ADD CONSTRAINT `chk_file_object_disposition` CHECK (`disposition` IN ('inline','attachment')),
 ADD CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('pending_write','write_failed','ready','archived')),
 ADD CONSTRAINT `chk_file_object_archive_shape` CHECK (((`status` IN ('pending_write','write_failed','ready')) AND `archived_at` IS NULL) OR (`status`='archived' AND `archived_at` IS NOT NULL));
ALTER TABLE `pa_file_object`
 ADD CONSTRAINT `fk_file_object_member` FOREIGN KEY (`tenant_id`,`created_by_member_id`) REFERENCES `pa_tenant_member` (`tenant_id`,`id`) ON DELETE RESTRICT;
DELETE FROM `pa_config` WHERE `type`='storage';
