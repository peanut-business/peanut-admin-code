<?php

declare(strict_types=1);

/** 验证原生 Validate 场景的可选筛选；不连接数据库，不以服务替身代替 HTTP 验收。 */
$root = dirname(__DIR__, 3);
if (!class_exists(\think\Validate::class)) {
    require_once $root . '/server/vendor/autoload.php';
}
require_once $root . '/server/app/modules/official/article/src/Validation/ArticleValidate.php';
require_once $root . '/server/app/modules/official/article/src/Validation/ArticleCateValidate.php';

$checks = 0;
$verify = static function (string $class, string $scene, array $data, bool $expected) use (&$checks): void {
    $validator = new $class();
    $actual = $validator->scene($scene)->check($data);
    ++$checks;
    if ($actual !== $expected) {
        throw new RuntimeException($class . ':' . $scene . ':' . json_encode(array_keys($data)) . ':' . $validator->getError());
    }
};
$article = PeanutAdmin\Modules\Article\Validation\ArticleValidate::class;
$category = PeanutAdmin\Modules\Article\Validation\ArticleCateValidate::class;
$verify($article, 'lists', [], true);
$verify($article, 'recycle', [], true);
$verify($article, 'recycle', ['title' => 'synthetic-filter'], true);
$verify($article, 'recycle', ['cid' => 999, 'page_size' => 25, 'export' => 1], true);
$verify($article, 'recycle', ['cid' => 0], false);
$verify($article, 'recycle', ['cid' => -1], false);
$verify($article, 'recycle', ['cid' => 'invalid'], false);
$verify($article, 'recycle', ['is_show' => 3], false);
$verify($article, 'recycle', ['page_size' => 0], false);
$verify($article, 'recycle', ['export' => 3], false);
$verify($article, 'add', [], false);
$verify($article, 'recycleDetail', ['id' => 1], true);
$verify($article, 'restore', ['id' => 1], true);
$verify($article, 'restore', [], false);
$verify($category, 'lists', [], true);
$verify($category, 'recycle', [], true);
$verify($category, 'recycle', ['name' => 'synthetic-filter'], true);
$verify($category, 'add', [], false);
echo 'ARTICLE-VALIDATION-SCENES-001 checks=' . $checks . " passed; database-not-executed\n";
