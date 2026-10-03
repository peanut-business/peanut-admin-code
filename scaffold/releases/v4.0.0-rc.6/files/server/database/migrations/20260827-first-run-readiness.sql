-- PC12: Tenant-visible first-run readiness entry and exact read permission.
SET @pa_app_setting_root_id = (
  SELECT `id`
  FROM `pa_system_menu`
  WHERE `type` = 'M' AND `paths` = '/app-setting'
  ORDER BY `id`
  LIMIT 1
);

INSERT INTO `pa_system_menu`
  (`pid`,`type`,`name`,`icon`,`sort`,`perms`,`paths`,`component`,`is_cache`,`is_show`,`is_disable`)
SELECT @pa_app_setting_root_id,'C','生产准备清单','icon-check-circle',95,
       'readiness/checklist','/app-setting/readiness','app-setting/readiness/index',0,1,0
WHERE @pa_app_setting_root_id IS NOT NULL
  AND NOT EXISTS (
    SELECT 1 FROM `pa_system_menu`
    WHERE `paths` = '/app-setting/readiness'
       OR LOWER(`perms`) = 'readiness/checklist'
  );
