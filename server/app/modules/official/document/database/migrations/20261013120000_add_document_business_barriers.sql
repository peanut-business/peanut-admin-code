-- Additive command progress. Existing solo/store receipts remain completed.
ALTER TABLE `pa_document_operation`
 ADD COLUMN `phase` VARCHAR(16) NOT NULL DEFAULT 'completed',
 ADD COLUMN `initiator_json` JSON NULL,
 ADD COLUMN `command_input_json` JSON NULL,
 ADD COLUMN `barrier_plan_json` JSON NULL,
 ADD COLUMN `business_sha256` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
 ADD COLUMN `released_at` DATETIME(3) NULL,
 ADD CONSTRAINT `chk_document_operation_phase` CHECK (`phase` IN ('prepared','attention','completed'));
