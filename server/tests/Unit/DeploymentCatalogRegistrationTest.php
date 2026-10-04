<?php

declare(strict_types=1);

namespace tests\Unit;

use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Modules\Identity\Authorization\CatalogLifecycleService;
use PHPUnit\Framework\TestCase;
use think\App;
use think\DbManager;
use think\facade\Db;

/** Native MySQL SQL builder; SQLite adapts only INSERT SET, duplicate-key and locking syntax, not query conditions. */
final class DeploymentCatalogRegistrationTest extends TestCase
{
    private PDO $pdo;
    private CatalogLifecycleService $owner;
    private ManifestDocument $manifest;
    private RegistrationSqliteConnection $connection;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($root . '/.local/tmp/catalog-registration');
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->connection = new RegistrationSqliteConnection($this->pdo);
        $db = new \SharedPdoDbManager($this->connection);
        $this->connection->setDb($db);
        $app->instance(DbManager::class, $db);
        $this->pdo->exec("CREATE TABLE pa_module_installation (id INTEGER PRIMARY KEY AUTOINCREMENT,module_key TEXT UNIQUE,installed_version TEXT,manifest_schema_version INTEGER,manifest_digest TEXT,status TEXT DEFAULT 'active',revision INTEGER DEFAULT 1,installed_at TEXT,activated_at TEXT,created_at TEXT,updated_at TEXT)");
        $this->owner = new CatalogLifecycleService();
        $this->manifest = ManifestDocument::fromArray($root . '/.local/tmp/registration-fixture', ['key' => 'fixture.catalog', 'version' => '1.2.3', 'schema_version' => 1]);
    }

    public function testNativeDuplicateInsertPreservesExistingIdentityAndTimestamps(): void
    {
        $first = $this->owner->registerDeployedManifest($this->manifest);
        self::assertSame(['key' => 'fixture.catalog', 'version' => '1.2.3', 'schema' => 1, 'digest' => $this->manifest->digest, 'status' => 'active'], $first);
        $rows = $this->pdo->query('SELECT * FROM pa_module_installation')->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame($first, $this->owner->registerDeployedManifest($this->manifest));
        self::assertSame($rows, $this->pdo->query('SELECT * FROM pa_module_installation')->fetchAll(PDO::FETCH_ASSOC));
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE', implode("\n", $this->connection->originalSql));
        self::assertStringContainsString('FOR UPDATE', implode("\n", $this->connection->originalSql));
    }

    public function testConflictingIdentityAndInactiveRecordAreRejectedWithoutOverwriting(): void
    {
        $this->owner->registerDeployedManifest($this->manifest);
        foreach ([['installed_version', '9.0.0'], ['status', 'disabled']] as [$field, $value]) {
            $this->pdo->exec("UPDATE pa_module_installation SET installed_version='1.2.3',status='active'");
            $this->pdo->prepare("UPDATE pa_module_installation SET {$field}=?")->execute([$value]);
            $before = $this->pdo->query('SELECT * FROM pa_module_installation')->fetchAll(PDO::FETCH_ASSOC);
            try {
                $this->owner->registerDeployedManifest($this->manifest);
                self::fail('Conflicting deployment was accepted.');
            } catch (ModuleException $failure) {
                self::assertSame('MODULE_INSTALLATION_MISMATCH', $failure->errorCode);
            }
            self::assertSame($before, $this->pdo->query('SELECT * FROM pa_module_installation')->fetchAll(PDO::FETCH_ASSOC));
        }
    }

    public function testOuterCatalogFailureRollsBackInstallationRecord(): void
    {
        try {
            Db::transaction(function (): void {
                $this->owner->registerDeployedManifest($this->manifest);
                self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn());
                throw new \DomainException('fixture-next-owner-failed');
            });
            self::fail('Outer failure disappeared.');
        } catch (\DomainException $failure) {
            self::assertSame('fixture-next-owner-failed', $failure->getMessage());
        }
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pa_module_installation')->fetchColumn());
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testMissingCatalogIsNotAnInstallationSuccess(): void
    {
        $this->pdo->exec('DROP TABLE pa_module_installation');
        $this->expectException(\Throwable::class);
        $this->owner->registerDeployedManifest($this->manifest);
    }

    public function testHostKeepsItsOuterTransactionAndNoLongerMutatesIdentityStorage(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/app/platform/infrastructure/module/DeploymentModuleInstaller.php');
        self::assertStringContainsString('Db::transaction(', $source);
        self::assertStringContainsString('registerDeployedManifest($manifest)', $source);
        self::assertStringContainsString('$this->catalogs->apply(', $source);
        self::assertStringNotContainsString("Db::name('module_installation')", $source);
    }
}

final class RegistrationSqliteConnection extends \think\db\connector\Sqlite
{
    public array $originalSql = [];
    public function __construct(private readonly PDO $fixture)
    {
        parent::__construct(['type' => 'sqlite', 'builder' => \think\db\builder\Mysql::class, 'prefix' => 'pa_']);
    }
    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->fixture;
    }
    public function getPDOStatement(string $sql, array $bind = [], bool $master = false, bool $procedure = false): PDOStatement
    {
        $this->originalSql[] = $sql;
        if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
            $sql = preg_replace('/ON DUPLICATE KEY UPDATE\s+`module_key`\s*=\s*VALUES\(`module_key`\)/i', 'ON CONFLICT(module_key) DO UPDATE SET module_key=excluded.module_key', $sql);
            if (str_contains($sql, 'ON DUPLICATE KEY UPDATE')) {
                throw new \LogicException('FIXTURE_DUPLICATE_CLAUSE_NOT_REVIEWED:' . $sql);
            }
        }
        if (preg_match('/^INSERT INTO `pa_module_installation` SET (.+?)\s+(ON CONFLICT\(module_key\) DO UPDATE SET module_key=excluded.module_key)\s*$/sD', $sql, $match) === 1) {
            $fields = [];
            $values = [];
            foreach (explode(',', $match[1]) as $assignment) {
                if (preg_match('/^\s*(`[_a-z]+`)\s*=\s*(:[A-Za-z0-9_]+)\s*$/D', $assignment, $parts) !== 1) {
                    throw new \LogicException('FIXTURE_INSERT_ASSIGNMENT_NOT_REVIEWED');
                }
                $fields[] = $parts[1];
                $values[] = $parts[2];
            }
            $sql = 'INSERT INTO `pa_module_installation` (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ') ' . $match[2];
        }
        return parent::getPDOStatement(str_replace(' FOR UPDATE', '', $sql), $bind, $master, $procedure);
    }
}
