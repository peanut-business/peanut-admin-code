-- peanut-release: 3.0.6
-- Converge the exact canonical Article permission set to its authoritative
-- official Module without changing permission identities. A valid upgrade may
-- already have the target owner after Plugin catalog synchronization.

CREATE TEMPORARY TABLE `pa_article_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `expected_owner_count` INT NOT NULL,
  CONSTRAINT `chk_article_permission_owner_assertion`
    CHECK (`permission_count` = 13 AND `expected_owner_count` = 13)
);

INSERT INTO `pa_article_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT
  'before',
  COUNT(*),
  CASE
    WHEN SUM(CASE WHEN `module_key` = 'peanut.admin' THEN 1 ELSE 0 END) = 13 THEN 13
    WHEN SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END) = 13 THEN 13
    ELSE 0
  END
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articleCate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

UPDATE `pa_permission`
SET `module_key` = 'official.article'
WHERE `module_key` = 'peanut.admin'
  AND `key` IN (
    'article.articleCate/lists',
    'article.articleCate/all',
    'article.articleCate/detail',
    'article.articleCate/add',
    'article.articleCate/edit',
    'article.articleCate/delete',
    'article.articleCate/updateStatus',
    'article.article/lists',
    'article.article/detail',
    'article.article/add',
    'article.article/edit',
    'article.article/delete',
    'article.article/updateStatus'
  );

INSERT INTO `pa_article_permission_owner_assertion`
  (`phase`, `permission_count`, `expected_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articleCate/all',
  'article.articleCate/detail',
  'article.articleCate/add',
  'article.articleCate/edit',
  'article.articleCate/delete',
  'article.articleCate/updateStatus',
  'article.article/lists',
  'article.article/detail',
  'article.article/add',
  'article.article/edit',
  'article.article/delete',
  'article.article/updateStatus'
);

DROP TEMPORARY TABLE `pa_article_permission_owner_assertion`;
