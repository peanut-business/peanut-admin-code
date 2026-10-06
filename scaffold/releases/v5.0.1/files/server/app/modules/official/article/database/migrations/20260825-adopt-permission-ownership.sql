-- official.article owns these stable permission rows. Fresh application seeds
-- historically created the same keys under peanut.admin; Plugin activation
-- converges only that exact set before the shared catalog is synchronized.

CREATE TEMPORARY TABLE `pa_official_article_permission_owner_assertion` (
  `phase` VARCHAR(16) NOT NULL,
  `permission_count` INT NOT NULL,
  `allowed_owner_count` INT NOT NULL,
  CONSTRAINT `chk_official_article_permission_owner_assertion`
    CHECK (`permission_count` = 13 AND `allowed_owner_count` = 13)
);

INSERT INTO `pa_official_article_permission_owner_assertion`
  (`phase`, `permission_count`, `allowed_owner_count`)
SELECT
  'before',
  COUNT(*),
  SUM(CASE WHEN `module_key` IN ('peanut.admin', 'official.article') THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articlecate/all',
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
    'article.articlecate/all',
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

INSERT INTO `pa_official_article_permission_owner_assertion`
  (`phase`, `permission_count`, `allowed_owner_count`)
SELECT
  'after',
  COUNT(*),
  SUM(CASE WHEN `module_key` = 'official.article' THEN 1 ELSE 0 END)
FROM `pa_permission`
WHERE `key` IN (
  'article.articleCate/lists',
  'article.articlecate/all',
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

DROP TEMPORARY TABLE `pa_official_article_permission_owner_assertion`;
