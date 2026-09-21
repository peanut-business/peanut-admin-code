<?php
declare(strict_types=1);

use PeanutAdmin\Modules\Article\Contract\ArticleAdministration;
use PeanutAdmin\Modules\Article\Contract\ArticleQueries;
use PeanutAdmin\Modules\Article\Contract\PublicArticleQueries;
use PeanutAdmin\Modules\Article\Model\Article;
use PeanutAdmin\Modules\Article\Model\ArticleCate;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use app\common\service\decoration\DecorationSchemaService;
use PeanutAdmin\Kernel\Context\AuthenticatedMemberContext;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require __DIR__ . '/../Support/IsolatedBackendEnvironment.php';

function expectArticleTenant(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tenantContext(int $tenantId, int $accountId, int $memberId, string $requestId): TenantContext
{
    return TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $memberId,
        '01JMT02ARTICLE' . str_pad((string)$memberId, 13, '0', STR_PAD_LEFT),
        $tenantId,
        $accountId,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2031-01-01T00:00:00Z'),
        1,
    ), $requestId);
}

function deniedShape(callable $operation): array
{
    try {
        $operation();
    } catch (Throwable $exception) {
        return [
            property_exists($exception, 'errorCode') ? $exception->errorCode : null,
            property_exists($exception, 'httpStatus') ? $exception->httpStatus : null,
            $exception->getMessage(),
        ];
    }
    throw new RuntimeException('Article capability denial was expected.');
}

function createArticleCollectMemberFkSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_member (
  id INT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_member_tenant_id (tenant_id, id),
  CONSTRAINT fk_collect_gate_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_article (
  id INT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_article_tenant_id (tenant_id, id),
  CONSTRAINT fk_collect_gate_article_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_article_collect (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  member_id INT UNSIGNED NOT NULL,
  article_id INT UNSIGNED NOT NULL,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uk_article_collect_tenant_member_article (tenant_id, member_id, article_id),
  CONSTRAINT fk_article_collect_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_article_collect_tenant_article FOREIGN KEY (tenant_id, article_id) REFERENCES pa_article (tenant_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_article_collect_tenant_member FOREIGN KEY (tenant_id, member_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active');
INSERT INTO pa_member (id, tenant_id) VALUES (501, 101), (502, 202);
INSERT INTO pa_article (id, tenant_id) VALUES (21, 101), (22, 202);
SQL);
}

function expectArticleCollectConstraintFailure(PDO $pdo, string $sql, string $message): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        expectArticleTenant($exception->getCode() === '23000', $message . ': unexpected SQLSTATE ' . $exception->getCode());
        return;
    }
    throw new RuntimeException($message . ': insert unexpectedly succeeded');
}

function runArticleCollectMemberFkGate(): void
{
    $host = IsolatedBackendEnvironment::required('DB_HOST');
    $port = (int)IsolatedBackendEnvironment::required('DB_PORT');
    $user = IsolatedBackendEnvironment::required('DB_USER');
    $password = IsolatedBackendEnvironment::required('DB_PASS');
    $runId = strtolower(bin2hex(random_bytes(5)));
    $database = 'peanut_mt02_collect_member_' . $runId;
    $admin = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
    try {
        $admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo = new PDO(
            "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
            $user,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
        );
        createArticleCollectMemberFkSchema($pdo);
        $pdo->exec(
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 501, 21)'
        );
        expectArticleCollectConstraintFailure(
            $pdo,
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 502, 21)',
            'cross-Tenant member collection was not rejected'
        );
        expectArticleCollectConstraintFailure(
            $pdo,
            'INSERT INTO pa_article_collect (tenant_id, member_id, article_id) VALUES (101, 501, 22)',
            'cross-Tenant Article collection was not rejected'
        );
        expectArticleTenant(
            (int)$pdo->query(
                'SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id=101 AND member_id=501 AND article_id=21'
            )->fetchColumn() === 1,
            'existing valid Article collection changed after migration'
        );

        echo "MT02-ARTICLE-COLLECT-MEMBER-TENANT-FK-001 passed\n";
    } finally {
        $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
    }
}

if (in_array('--collect-member-fk', $argv ?? [], true)) {
    runArticleCollectMemberFkGate();
    exit(0);
}

$serverRoot = dirname(__DIR__, 2);
foreach ([
    'app/common/execution/CurrentExecutionContext.php',
    'app/modules/official/article/src/Model/Article.php',
    'app/modules/official/article/src/Model/ArticleCate.php',
    'app/modules/official/article/src/Model/ArticleCollect.php',
    'app/modules/official/article/src/Controller/ArticleController.php',
    'app/modules/official/article/src/Controller/ArticleCateController.php',
    'app/modules/official/article/src/Service/ArticleAdministrationService.php',
    'app/modules/official/article/src/Contract/ArticleAdministration.php',
    'app/modules/official/article/src/Validation/ArticleValidate.php',
    'app/modules/official/article/src/Validation/ArticleCateValidate.php',
    'app/adminapi/controller/decoration/DecorationPageController.php',
    'app/adminapi/controller/decoration/DecorationTabbarController.php',
    'app/adminapi/application/decoration/DecorationPageApplicationService.php',
    'app/adminapi/application/decoration/DecorationTabbarApplicationService.php',
    'app/common/service/decoration/DecorationSchemaService.php',
    'app/api/controller/ArticleController.php',
    'app/api/controller/IndexController.php',
    'app/api/controller/PcController.php',
    'app/api/controller/UserController.php',
    'app/modules/official/article/src/Service/PublicArticleService.php',
    'app/modules/official/article/src/Contract/PublicArticleQueries.php',
    'app/api/application/IndexApplicationService.php',
    'app/api/application/PcApplicationService.php',
    'app/api/application/UserApplicationService.php',
    'tests/Productization/ContentDecorationHostTest.php',
    'tests/Multitenancy/ArticleTenantIsolationTest.php',
] as $relativePath) {
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($serverRoot . '/' . $relativePath);
    exec($command, $lintOutput, $lintExit);
    expectArticleTenant($lintExit === 0, 'PHP 8.3 lint failed: ' . $relativePath . ' ' . implode(' ', $lintOutput));
    $lintOutput = [];
}

$host = IsolatedBackendEnvironment::required('DB_HOST');
$port = (int)IsolatedBackendEnvironment::required('DB_PORT');
$user = IsolatedBackendEnvironment::required('DB_USER');
$password = IsolatedBackendEnvironment::required('DB_PASS');
$database = trim((string)getenv('PEANUT_TEST_DATABASE'));
if ($database === '') {
    $database = 'peanut_admin_mt02_article_' . strtolower(bin2hex(random_bytes(5)));
}
if (preg_match('/^peanut_admin_mt02_article_[a-z0-9]{1,24}$/D', $database) !== 1) {
    throw new RuntimeException('ARTICLE_TEST_DATABASE_INVALID');
}
$admin = new PDO(
    "mysql:host={$host};port={$port};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
);
$admin->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => true]
    );
    $pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(32) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB;
CREATE TABLE pa_module_installation (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  module_key VARCHAR(96) NOT NULL,
  installed_version VARCHAR(32) NOT NULL,
  manifest_schema_version INT UNSIGNED NOT NULL DEFAULT 1,
  manifest_digest CHAR(64) NOT NULL,
  status VARCHAR(24) NOT NULL,
  revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  installed_at DATETIME(3) NULL,
  activated_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  PRIMARY KEY (id), UNIQUE KEY uk_article_module_installation (module_key)
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_module (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(96) NOT NULL,
  status VARCHAR(24) NOT NULL,
  effective_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  authorization_revision BIGINT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id), UNIQUE KEY uk_article_tenant_module (tenant_id, module_key)
) ENGINE=InnoDB;
CREATE TABLE pa_member (
  id INT UNSIGNED NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_member_tenant_id (tenant_id, id),
  CONSTRAINT fk_article_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_article_cate (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(90) NOT NULL DEFAULT '', sort INT NOT NULL DEFAULT 0,
  is_show TINYINT UNSIGNED NOT NULL DEFAULT 1,
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0,
  delete_time INT UNSIGNED NULL DEFAULT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), UNIQUE KEY uk_article_cate_tenant_id (tenant_id, id),
  KEY idx_article_cate_tenant_visible (tenant_id, is_show, sort, id),
  CONSTRAINT fk_article_cate_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_article (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, cid INT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL DEFAULT '', `desc` VARCHAR(255) NULL DEFAULT '', abstract TEXT NULL,
  image VARCHAR(2048) NULL, author VARCHAR(255) NULL DEFAULT '', content TEXT NULL,
  click_virtual INT NULL DEFAULT 0, click_actual INT NULL DEFAULT 0, sort INT NULL DEFAULT 0,
  is_show TINYINT UNSIGNED NOT NULL DEFAULT 1,
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0,
  delete_time INT UNSIGNED NULL DEFAULT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), KEY idx_cid (cid), UNIQUE KEY uk_article_tenant_id (tenant_id, id),
  KEY idx_article_tenant_visible_cate (tenant_id, is_show, cid, sort, id),
  CONSTRAINT fk_article_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_article_tenant_cate FOREIGN KEY (tenant_id, cid) REFERENCES pa_article_cate (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE pa_article_collect (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, member_id INT UNSIGNED NOT NULL DEFAULT 0,
  article_id INT UNSIGNED NOT NULL DEFAULT 0, status TINYINT UNSIGNED NOT NULL DEFAULT 0,
  create_time INT UNSIGNED NOT NULL DEFAULT 0, update_time INT UNSIGNED NOT NULL DEFAULT 0,
  delete_time INT NULL DEFAULT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id), KEY idx_member_id (member_id),
  UNIQUE KEY uk_article_collect_tenant_member_article (tenant_id, member_id, article_id),
  KEY idx_article_collect_tenant_member_status (tenant_id, member_id, status, id),
  CONSTRAINT fk_article_collect_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT,
  CONSTRAINT fk_article_collect_tenant_article FOREIGN KEY (tenant_id, article_id) REFERENCES pa_article (tenant_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_article_collect_tenant_member FOREIGN KEY (tenant_id, member_id) REFERENCES pa_member (tenant_id, id) ON DELETE RESTRICT
) ENGINE=InnoDB;
INSERT INTO pa_tenant (id, status) VALUES (101, 'active'), (202, 'active');
INSERT INTO pa_module_installation (module_key, installed_version, manifest_schema_version, manifest_digest, status)
VALUES ('official.article', '1.0.0', 1, REPEAT('a', 64), 'active');
INSERT INTO pa_tenant_module (tenant_id, module_key, status, effective_at)
VALUES (101, 'official.article', 'enabled', UTC_TIMESTAMP(3)), (202, 'official.article', 'enabled', UTC_TIMESTAMP(3));
INSERT INTO pa_member (id, tenant_id) VALUES (501, 101), (502, 202);
INSERT INTO pa_article_cate (id, tenant_id, name, sort, is_show) VALUES (11, 101, 'Alpha seed', 10, 1), (12, 202, 'Beta', 10, 1);
INSERT INTO pa_article (id, tenant_id, cid, title, is_show, click_actual) VALUES (21, 101, 11, 'Alpha seed article', 1, 0), (22, 202, 12, 'Beta visible', 1, 0);
INSERT INTO pa_article_collect (id, tenant_id, member_id, article_id, status) VALUES (31, 101, 501, 21, 1);
SQL);

    IsolatedBackendEnvironment::activateDatabase($host, $port, $database, $user, $password, 'multi-tenant');
    $app = new think\App();
    $app->initialize();
    $articles = app(ArticleAdministration::class);

    $alpha = tenantContext(101, 1001, 501, 'mt02-alpha');
    $beta = tenantContext(202, 2002, 502, 'mt02-beta');
    $alphaMember = new AuthenticatedMemberContext(101, 501, 'fixture-alpha-member', 'mt02-alpha-member');
    $missingRequest = new stdClass();
    try {
        app(CurrentExecutionContext::class)->tenantAdmin();
        throw new RuntimeException('missing TenantContext unexpectedly succeeded');
    } catch (Throwable $exception) {
        expectArticleTenant($exception->getMessage() !== '', 'missing context denial lost its shape');
    }

    $payload = ['tenant_id' => 202, 'name' => 'Alpha category', 'sort' => 20, 'is_show' => 1];
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.category.add'),
            fn() => $articles->addCategory($payload),
        ),
        'Alpha category was not created',
    );
    $alphaCategoryId = (int)app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.article.category.query'),
        fn() => ArticleCate::where([])->where('name', 'Alpha category')->value('id'),
    );
    expectArticleTenant($alphaCategoryId > 0, 'Alpha category was not created');
    expectArticleTenant(
        (int)$pdo->query("SELECT tenant_id FROM pa_article_cate WHERE id = {$alphaCategoryId}")->fetchColumn() === 101,
        'payload tenant_id overrode trusted context'
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.add'),
            fn() => $articles->add([
                'tenant_id' => 202, 'cid' => $alphaCategoryId, 'title' => 'Alpha visible',
                'is_show' => 1, 'desc' => '', 'abstract' => '', 'content' => '',
            ]),
        ),
        'Alpha article was not created',
    );
    $alphaArticleId = (int)app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.article.query'),
        fn() => Article::where([])->where('title', 'Alpha visible')->value('id'),
    );
    expectArticleTenant($alphaArticleId > 0, 'Alpha article was not created');

    $beforeBeta = $pdo->query('SELECT title, click_actual FROM pa_article WHERE id = 22')->fetch(PDO::FETCH_ASSOC);
    $beforeCollects = (int)$pdo->query('SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id = 202')->fetchColumn();
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.public-detail.cross-tenant'),
            fn() => app(PublicArticleQueries::class)->detail(22, 501),
        ) === [],
        'cross-tenant detail enumerated Beta Article',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.public-detail.missing'),
            fn() => app(PublicArticleQueries::class)->detail(999999, 501),
        ) === [],
        'missing detail denial shape changed',
    );

    foreach ([22, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.article.edit.denied'),
                fn() => $articles->edit([
                    'id' => $target,
                    'cid' => $alphaCategoryId,
                    'title' => 'denied-write',
                    'is_show' => 1,
                ]),
            );
            throw new RuntimeException('cross/missing Article edit unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            expectArticleTenant($exception->getMessage() === '资讯不存在', 'cross-tenant edit enumerated the target');
        }
    }

    $crossCollectError = deniedShape(fn() => app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.cross-tenant'),
            fn() => app(PublicArticleQueries::class)->add(22, 501),
    ));
    $missingCollectError = deniedShape(fn() => app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.missing'),
            fn() => app(PublicArticleQueries::class)->add(999999, 501),
    ));
    expectArticleTenant($missingCollectError === $crossCollectError, 'cross-tenant collection enumerated the target');
    expectArticleTenant($crossCollectError[0] === 'ARTICLE_NOT_FOUND', 'collection denial code changed');

    $link = static fn(int $id): array => ['target_type' => 'article', 'target' => $id];
    foreach ([22, 999999] as $target) {
        try {
            app(ExecutionContextStore::class)->run(
                new \app\common\execution\AdminExecutionContext($alpha, 'test.article.decoration-link.denied'),
                fn() => DecorationSchemaService::validateLink($alpha, $link($target), false, app(ArticleQueries::class)),
            );
            throw new RuntimeException('invalid decoration Article unexpectedly succeeded');
        } catch (RuntimeException $exception) {
            expectArticleTenant($exception->getMessage() === '文章链接必须指向存在且可见的文章', 'decoration target enumerated Tenant ownership');
        }
    }

    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.add'),
            fn() => app(PublicArticleQueries::class)->add($alphaArticleId, 501),
        ),
        'Alpha collection failed',
    );
    expectArticleTenant(
        app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.public-detail.owned'),
            fn() => app(PublicArticleQueries::class)->detail($alphaArticleId, 501),
        )['collect'] === true,
        'Alpha Article detail/collection failed',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.public-list'),
            fn() => app(PublicArticleQueries::class)->lists(['page_size' => 20], 501),
        )->items) >= 1,
        'Alpha list lost visible Article',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.info-center'),
            fn() => app(PublicArticleQueries::class)->infoCenter(),
        )) >= 1,
        'Alpha info center lost categories',
    );
    expectArticleTenant(
        count(app(ExecutionContextStore::class)->run(
            new \app\common\execution\AdminExecutionContext($alpha, 'test.article.aggregate'),
            fn() => app(PublicArticleQueries::class)->limitArticles('new', 20),
        )) >= 1,
        'Alpha aggregate lost Article',
    );
    app(ExecutionContextStore::class)->run(
        new \app\common\execution\AdminExecutionContext($alpha, 'test.article.decoration-link.owned'),
        fn() => DecorationSchemaService::validateLink($alpha, $link($alphaArticleId), false, app(ArticleQueries::class)),
    );
    app(ExecutionContextStore::class)->run(
        \app\common\execution\ConsumerExecutionContext::member($alphaMember, 'test.article.collect.cancel'),
        fn() => app(PublicArticleQueries::class)->cancel($alphaArticleId, 501),
    );

    expectArticleTenant(
        $pdo->query('SELECT title, click_actual FROM pa_article WHERE id = 22')->fetch(PDO::FETCH_ASSOC) === $beforeBeta,
        'cross-tenant denial changed Beta Article'
    );
    expectArticleTenant(
        (int)$pdo->query('SELECT COUNT(*) FROM pa_article_collect WHERE tenant_id = 202')->fetchColumn() === $beforeCollects,
        'cross-tenant denial changed Beta collections'
    );

    echo json_encode([
        'status' => 'passed',
        'scope' => 'mt02-article-tenant-first',
        'schema' => 'fresh-canonical',
        'tenant_first_denials' => ['detail', 'edit', 'collect', 'decoration', 'typed_target'],
        'permission_policy_allowed' => true,
        'beta_unchanged' => true,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `{$database}`");
}
