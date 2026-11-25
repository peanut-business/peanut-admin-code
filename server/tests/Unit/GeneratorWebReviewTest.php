<?php

declare(strict_types=1);

use app\adminapi\infrastructure\generator\GeneratorImportPersistence;
use app\adminapi\infrastructure\generator\ThinkPhpGeneratorMetadata;
use app\adminapi\services\generator\GeneratorArchiveService;
use app\adminapi\services\generator\GeneratorService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\GeneratorFixtureApplication;
use think\App;
use think\Container;

final class GeneratorWebReviewTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testWebServicePreviewAndZipPreserveGeneratedAndHandwrittenSource(): void
    {
        require_once dirname(__DIR__) . '/Support/GeneratorFixtureApplication.php';
        $fixture = new GeneratorFixtureApplication();
        $app = new App($fixture->root . '/server');
        Container::setInstance($app);
        require_once $app->getThinkPath() . 'helper.php';
        $app->config->set([
            'default' => 'file', 'stores' => ['file' => ['type' => 'File']],
        ], 'cache');
        $app->config->set([
            'default' => 'fixture',
            'connections' => ['fixture' => [
                'type' => 'sqlite', 'database' => ':memory:', 'prefix' => 'pa_', 'trigger_sql' => false,
            ]],
        ], 'database');
        $connection = $app->db->connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        $pdo->exec(<<<'SQL'
            CREATE TABLE pa_generator_table (
                id INTEGER PRIMARY KEY, admin_id INTEGER NOT NULL, table_name TEXT NOT NULL,
                table_comment TEXT, module_name TEXT, entity_name TEXT, template_type TEXT,
                data_owner TEXT, target_edition TEXT, author TEXT, tree_config TEXT, relations TEXT,
                create_time INTEGER, update_time INTEGER
            );
            CREATE TABLE pa_generator_column (
                id INTEGER PRIMARY KEY, table_id INTEGER NOT NULL, column_name TEXT NOT NULL,
                column_comment TEXT, column_type TEXT, php_type TEXT, is_required INTEGER,
                is_pk INTEGER, is_insert INTEGER, is_update INTEGER, is_lists INTEGER,
                is_query INTEGER, query_type TEXT, view_type TEXT, dict_type TEXT, sort INTEGER,
                create_time INTEGER, update_time INTEGER
            );
            CREATE TABLE pa_generator_download (
                id INTEGER PRIMARY KEY, admin_id INTEGER NOT NULL, token_hash TEXT,
                archive_path TEXT, download_name TEXT, expire_time INTEGER, used_time INTEGER,
                create_time INTEGER, update_time INTEGER
            );
            INSERT INTO pa_generator_table (
                id, admin_id, table_name, table_comment, module_name, entity_name, template_type,
                data_owner, target_edition, author, tree_config, relations
            ) VALUES (
                1, 5, 'shop_note', 'Generated note', 'fixture.delivery-record', 'Note', 'crud',
                'tenant-orm', 'standalone', 'Fixture', NULL, NULL
            );
            INSERT INTO pa_generator_column (
                id, table_id, column_name, column_comment, column_type, php_type, is_required,
                is_pk, is_insert, is_update, is_lists, is_query, query_type, view_type, dict_type, sort
            ) VALUES
                (1, 1, 'id', 'ID', 'int', 'int', 1, 1, 0, 0, 1, 0, '=', 'input', '', 1),
                (2, 1, 'tenant_id', 'Tenant', 'int', 'int', 1, 0, 0, 0, 0, 0, '=', 'input', '', 2),
                (3, 1, 'title', 'Title', 'varchar(60)', 'varchar', 1, 0, 1, 1, 1, 1, 'like', 'input', '', 3),
                (4, 1, 'category', 'Category', 'varchar(25)', 'varchar', 1, 0, 1, 1, 1, 1, '=', 'input', '', 4),
                (5, 1, 'computed_label', 'Label', 'varchar(60)', 'varchar', 0, 0, 0, 0, 1, 0, '=', 'input', '', 5);
            SQL);
        $service = new GeneratorService(
            new GeneratorImportPersistence(),
            new ThinkPhpGeneratorMetadata(),
            'shop_',
            $fixture->root,
        );
        $model = 'server/app/modules/fixture/delivery_record/src/Model/Note.php';
        $view = 'web/src/modules/fixture-delivery-record/generated/note/index.vue';
        $handwrittenPath = $fixture->root . '/web/src/modules/fixture-delivery-record/handwritten-extension.ts';

        $initial = $service->preview(5, 1);
        self::assertCount(14, $initial);
        self::assertContains('create', array_column($initial, 'operation'));
        self::assertContains('merge', array_column($initial, 'operation'));
        self::assertFileDoesNotExist($fixture->root . '/' . $model);

        $firstIssued = $service->generate(5, [1]);
        $firstZip = self::consumeZip($service, 5, $firstIssued);
        self::assertArrayHasKey($model, $firstZip);
        self::assertArrayHasKey('merge-preview/server/app/modules/fixture/delivery_record/module.json', $firstZip);
        self::assertFileDoesNotExist($fixture->root . '/' . $model);
        try {
            $service->consumeDownload(5, $firstIssued['download_token']);
            self::fail('Download token must be consumed exactly once.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('下载令牌无效或已过期', $exception->getMessage());
        }

        foreach ($initial as $file) {
            $path = $fixture->root . '/' . $file['path'];
            if (!is_dir(dirname($path))) {
                self::assertTrue(mkdir(dirname($path), 0775, true));
            }
            self::assertSame(strlen($file['content']), file_put_contents($path, $file['content']));
        }
        $handwritten = '// fixture handwritten extension';
        self::assertSame(strlen($handwritten), file_put_contents($handwrittenPath, $handwritten));
        $modelBytes = file_get_contents($fixture->root . '/' . $model);
        $viewBytes = file_get_contents($fixture->root . '/' . $view);

        $repeated = $service->preview(5, 1);
        self::assertSame(['unchanged'], array_values(array_unique(array_column($repeated, 'operation'))));
        $secondZip = self::consumeZip($service, 5, $service->generate(5, [1]));
        self::assertSame(['GENERATOR-MERGE-GUIDE.md'], array_keys($secondZip));

        $customization = "\n<!-- handwritten source customization -->\n";
        self::assertSame(strlen($customization), file_put_contents($fixture->root . '/' . $view, $customization, FILE_APPEND));
        $modifiedView = $viewBytes . $customization;
        $conflicted = array_column($service->preview(5, 1), null, 'path');
        self::assertSame('conflict', $conflicted[$view]['operation']);
        self::assertSame(hash('sha256', $modifiedView), $conflicted[$view]['base_sha256']);
        self::assertSame(hash('sha256', $viewBytes), $conflicted[$view]['proposed_sha256']);
        self::assertSame('unchanged', $conflicted[$model]['operation']);

        $conflictZip = self::consumeZip($service, 5, $service->generate(5, [1]));
        self::assertArrayNotHasKey($view, $conflictZip);
        self::assertSame($viewBytes, $conflictZip['merge-preview/' . $view]);
        self::assertStringContainsString($conflicted[$view]['base_sha256'], $conflictZip['GENERATOR-MERGE-GUIDE.md']);
        self::assertStringContainsString($conflicted[$view]['proposed_sha256'], $conflictZip['GENERATOR-MERGE-GUIDE.md']);
        self::assertSame($modifiedView, file_get_contents($fixture->root . '/' . $view));
        self::assertSame($modelBytes, file_get_contents($fixture->root . '/' . $model));
        self::assertSame($handwritten, file_get_contents($handwrittenPath));

        try {
            $service->preview(6, 1);
            self::fail('An unrelated administrator must not read the generator draft.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('生成配置不存在或无权访问', $exception->getMessage());
        }
    }

    /** @return array<string,string> */
    private static function consumeZip(GeneratorService $service, int $adminId, array $response): array
    {
        $file = $service->consumeDownload($adminId, $response['download_token']);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($file['path']) === true);
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);
            self::assertIsString($name);
            $contents = $zip->getFromIndex($index);
            self::assertIsString($contents);
            $files[$name] = $contents;
        }
        self::assertTrue($zip->close());
        GeneratorArchiveService::cleanup($file['archive_path'], $adminId);
        return $files;
    }
}
