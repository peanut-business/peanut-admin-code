ALTER TABLE `pa_collaboration_barrier`
 MODIFY COLUMN `connection_id` BIGINT UNSIGNED NULL,
 ADD COLUMN `business_plan_json` JSON NULL,
 ADD COLUMN `business_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN `business_result_json` JSON NULL,
 ADD COLUMN `released_at` DATETIME(3) NULL;
-- Legacy connection barriers retain the existing status/purpose constraints.
