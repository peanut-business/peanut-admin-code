ALTER TABLE `pa_article_body_save`
 DROP CHECK `chk_article_body_save_phase`,
 DROP CHECK `chk_article_body_save_result`,
 ADD CONSTRAINT `chk_article_body_save_phase` CHECK (`phase` IN ('prepared','completed','conflicted')),
 ADD CONSTRAINT `chk_article_body_save_result` CHECK (
  (`phase`='prepared' AND `result_json` IS NULL AND `completed_at` IS NULL)
  OR (`phase` IN ('completed','conflicted') AND `result_json` IS NOT NULL AND `completed_at` IS NOT NULL)
 );
