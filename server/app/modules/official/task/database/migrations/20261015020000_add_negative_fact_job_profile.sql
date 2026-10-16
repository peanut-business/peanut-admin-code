-- Bootstrap may already create the current Task schema; adopt existing tables without rewriting old migrations.
SET @task_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_task_job' AND COLUMN_NAME='authorization_profile'), 'SELECT 1', 'ALTER TABLE `pa_task_job` ADD COLUMN `authorization_profile` VARCHAR(32) CHARACTER SET ascii NOT NULL DEFAULT ''member'', ADD COLUMN `producer_fact_key` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL');
PREPARE task_statement FROM @task_sql;
EXECUTE task_statement;
DEALLOCATE PREPARE task_statement;
ALTER TABLE `pa_task_job` MODIFY COLUMN `created_by_member_id` BIGINT UNSIGNED NULL;
SET @task_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_task_job' AND INDEX_NAME='uk_task_negative_fact'), 'SELECT 1', 'ALTER TABLE `pa_task_job` ADD UNIQUE KEY `uk_task_negative_fact` (`authorization_profile`,`producer_fact_key`)');
PREPARE task_statement FROM @task_sql;
EXECUTE task_statement;
DEALLOCATE PREPARE task_statement;
SET @task_sql = IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='pa_task_job' AND CONSTRAINT_NAME='chk_task_job_profile'), 'SELECT 1', 'ALTER TABLE `pa_task_job` ADD CONSTRAINT `chk_task_job_profile` CHECK ((`authorization_profile`=''member'' AND `created_by_member_id` IS NOT NULL AND `producer_fact_key` IS NULL) OR (`authorization_profile`=''identity_negative_fact'' AND `created_by_member_id` IS NULL AND `producer_fact_key` IS NOT NULL AND `task_type`=''identity.revocation.propagate'' AND `handler_key`=''identity.revocation.propagate''))');
PREPARE task_statement FROM @task_sql;
EXECUTE task_statement;
DEALLOCATE PREPARE task_statement;
SET @task_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='pa_task_job_event' AND COLUMN_NAME='event_reference'), 'SELECT 1', 'ALTER TABLE `pa_task_job_event` ADD COLUMN `event_reference` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY `uk_task_event_reference` (`job_id`,`event_reference`)');
PREPARE task_statement FROM @task_sql;
EXECUTE task_statement;
DEALLOCATE PREPARE task_statement;
