ALTER TABLE `pa_workflow_event`
  ADD COLUMN `notification_fact_json` JSON NULL,
  ADD COLUMN `notification_outbox_json` JSON NULL,
  ADD COLUMN `notification_last_error` VARCHAR(64) NULL;
