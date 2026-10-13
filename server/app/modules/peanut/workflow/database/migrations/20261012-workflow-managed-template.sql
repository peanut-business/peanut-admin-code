-- Preserve tenant customized drafts and historical executable versions on template upgrade.
ALTER TABLE `pa_workflow_definition` ADD COLUMN `managed_template_json` JSON NULL;
