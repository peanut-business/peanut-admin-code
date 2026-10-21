-- Keep immutable file identity and original data. Only this explicit operation may reopen a restricted object.
ALTER TABLE `pa_file_object`
 MODIFY COLUMN `status` VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'ready',
 ADD COLUMN `visibility_batch_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 DROP CHECK `chk_file_object_status`,
 DROP CHECK `chk_file_object_archive_shape`,
 ADD CONSTRAINT `chk_file_object_status` CHECK (`status` IN ('pending_write','write_failed','ready','archived','restricting','verifying','restricted_attention')),
 ADD CONSTRAINT `chk_file_object_archive_shape` CHECK (((`status` IN ('pending_write','write_failed','ready','restricting','verifying','restricted_attention')) AND `archived_at` IS NULL) OR (`status`='archived' AND `archived_at` IS NOT NULL)),
 ADD CONSTRAINT `chk_file_object_visibility_operation` CHECK ((`status` IN ('restricting','verifying','restricted_attention') AND `visibility_batch_key` IS NOT NULL) OR (`status` IN ('pending_write','write_failed','ready','archived') AND `visibility_batch_key` IS NULL));
