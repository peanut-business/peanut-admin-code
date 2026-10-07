-- Register the existing Tenant Admin readiness endpoint under the host permission owner.
-- The built-in Tenant owner can use registered permissions; no other role is granted access here.
INSERT INTO `pa_permission`
  (`key`,`module_key`,`type`,`name`,`description`,`risk_level`,`status`,`manifest_version`,`created_at`,`updated_at`,`retired_at`)
VALUES
  ('readiness/checklist','peanut.admin','api','生产准备清单','Read the tenant first-run readiness checklist.','normal','active','5.0.1-readiness-v1',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),NULL)
ON DUPLICATE KEY UPDATE
  `module_key`=VALUES(`module_key`),`type`=VALUES(`type`),`name`=VALUES(`name`),
  `description`=VALUES(`description`),`risk_level`=VALUES(`risk_level`),
  `status`='active',`manifest_version`=VALUES(`manifest_version`),
  `updated_at`=VALUES(`updated_at`),`retired_at`=NULL;
