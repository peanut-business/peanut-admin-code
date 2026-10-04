<?php

declare(strict_types=1);

namespace tests\Unit;

use app\platform\infrastructure\provider\StorageQualificationContributor;
use PDO;
use PeanutAdmin\Modules\File\Contract\StorageQualificationQueries;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use think\App;

/** Synthetic rows and actual owner/contributor; never decrypts credentials or contacts a storage service. */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StorageQualificationBoundaryTest extends TestCase
{
    private PDO $database;
    private StorageQualificationQueries $queries;
    private StorageQualificationContributor $contributor;
    private string $key;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $temporary = $root . '/.local/tmp/storage-qualification-tests';
        if (!is_dir($temporary) && !mkdir($temporary, 0700, true) && !is_dir($temporary)) {
            throw new \RuntimeException('STORAGE_QUALIFICATION_TEST_DIRECTORY_UNAVAILABLE');
        }
        self::assertStringStartsWith($root . '/.local/tmp/', (string) realpath($temporary));
        require_once $root . '/server/vendor/topthink/framework/src/helper.php';
        $app = new App($temporary . '/case-' . bin2hex(random_bytes(5)));
        $this->database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        \ThinkPhpTestConnection::fromPdo($this->database);
        $this->database->exec(<<<'SQL'
            CREATE TABLE pa_storage_account (id INTEGER PRIMARY KEY, account_key TEXT, driver TEXT, credential_ciphertext TEXT, credential_key_version TEXT, credential_rotated_at TEXT, status TEXT, updated_at TEXT);
            CREATE TABLE pa_storage_space (id INTEGER PRIMARY KEY, account_id INTEGER, status TEXT, updated_at TEXT);
            INSERT INTO pa_storage_account VALUES
                (1,'local-main','local',NULL,NULL,NULL,'active','2031-01-01 00:00:00'),
                (2,'remote-main','cos','fixture-secret-cipher','v1','2031-01-01 00:00:00','active','2031-01-01 01:00:00'),
                (3,'remote-incomplete','oss','fixture-secret-cipher','','2031-01-01 00:00:00','active','2031-01-01 01:00:00'),
                (4,'inactive','local',NULL,NULL,NULL,'inactive','2031-01-01 01:00:00'),
                (5,'no-space','local',NULL,NULL,NULL,'active','2031-01-01 01:00:00');
            INSERT INTO pa_storage_space VALUES (1,1,'active','2031-01-01 02:00:00'),(2,2,'active','2031-01-01 03:00:00'),(3,2,'active','2031-01-01 04:00:00'),(4,2,'inactive','2032-01-01 00:00:00'),(5,3,'active','2031-01-01 04:00:00'),(6,4,'active','2031-01-01 04:00:00'),(7,5,'inactive','2031-01-01 04:00:00');
            SQL);
        $this->key = str_repeat('fixture-key-', 4);
        $this->queries = $app->make(StorageQualificationQueries::class);
        $this->contributor = new StorageQualificationContributor($this->key, $this->queries);
    }

    public function testContributorUsesTheDeclaredOwnerInsteadOfStoragePrivateTables(): void
    {
        $manifest = json_decode(file_get_contents(dirname(__DIR__, 2) . '/app/modules/official/file/module.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains(StorageQualificationQueries::class, $manifest['contracts']['exports']);
        $source = file_get_contents((new ReflectionClass(StorageQualificationContributor::class))->getFileName());
        self::assertStringNotContainsString('Db::', $source);
        self::assertStringContainsString('StorageQualificationQueries $storage', $source);
    }

    public function testQualificationRetainsAccountOrderStateAndLocalCredentialException(): void
    {
        $subjects = $this->contributor->subjects();
        self::assertSame(['local-main', 'remote-main', 'remote-incomplete', 'inactive', 'no-space'], array_column($subjects, 'scopeReference'));
        self::assertSame([true, true, false, false, false], array_column($subjects, 'configured'));
        self::assertSame(['storage.local', 'storage.cos', 'storage.oss', 'storage.local', 'storage.local'], array_column($subjects, 'providerKey'));
        self::assertSame([null, '2031-01-01T00:00:00Z', '2031-01-01T00:00:00Z', null, null], array_column($subjects, 'credentialRotatedAt'));
        foreach ($subjects as $subject) {
            self::assertSame('instance', $subject->scopeType);
            self::assertNull($subject->tenantId);
            self::assertFalse($subject->callbackRequired);
        }
    }

    public function testFingerprintRetainsOriginalKeyedFieldOrderAndIgnoresInactiveSpaces(): void
    {
        $expected = hash_hmac('sha256', implode("\0", [
            '2', 'remote-main', 'cos', 'fixture-secret-cipher', 'v1', '2031-01-01 00:00:00',
            'active', '2031-01-01 01:00:00', '2', '2031-01-01 04:00:00',
        ]), $this->key);
        self::assertSame($expected, $this->contributor->subjects()[1]->configDigest);
        $this->database->exec("UPDATE pa_storage_space SET updated_at='2033-01-01 00:00:00' WHERE id=4");
        self::assertSame($expected, $this->contributor->subjects()[1]->configDigest);
        $this->database->exec("UPDATE pa_storage_account SET credential_ciphertext='rotated-fixture-cipher' WHERE id=2");
        self::assertNotSame($expected, $this->contributor->subjects()[1]->configDigest);
    }

    public function testPublicRowsNeverExposeCiphertextOrArbitraryColumns(): void
    {
        $rows = $this->queries->subjects($this->key);
        foreach ($rows as $row) {
            self::assertSame(['account_key', 'driver', 'configured', 'credential_rotated_at', 'config_digest'], array_keys($row));
        }
        self::assertStringNotContainsString('fixture-secret-cipher', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertNotSame($rows[1]['config_digest'], $this->queries->subjects(str_repeat('another-key-', 4))[1]['config_digest']);
    }

    public function testMissingLedgerIsNotMisreportedAsEmptyOrConfigured(): void
    {
        $this->database->exec('DROP TABLE pa_storage_account');
        $this->expectException(\Throwable::class);
        $this->contributor->subjects();
    }

    public function testShortFingerprintKeyIsRejectedByTheOwner(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->queries->subjects('short');
    }
}
