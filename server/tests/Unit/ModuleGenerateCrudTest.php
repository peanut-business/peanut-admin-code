<?php

declare(strict_types=1);

use app\adminapi\infrastructure\generator\GeneratorImportPersistence;
use app\adminapi\infrastructure\generator\ThinkPhpGeneratorMetadata;
use app\adminapi\services\generator\GeneratorService;
use app\command\ModuleGenerateCrud;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Tests\Support\GeneratorFixtureApplication;
use think\App;
use think\console\Input;
use think\console\Output;

final class ModuleGenerateCrudTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testPreviewAndWriteShareNativeRenderingAndAllowPartialEdits(): void
    {
        require_once dirname(__DIR__) . '/Support/GeneratorFixtureApplication.php';
        $fixture = new GeneratorFixtureApplication();
        $app = new App($fixture->root . '/server');
        require_once $app->getThinkPath() . 'helper.php';
        $app->debug();
        $app->config->set(['mode' => 'multi-tenant'], 'deployment');
        $app->config->set(['environment' => 'development'], 'peanut');
        $app->config->set(['default' => 'file', 'stores' => ['file' => ['type' => 'File']]], 'cache');
        $app->config->set([
            'default' => 'fixture',
            'connections' => ['fixture' => ['type' => 'sqlite', 'database' => ':memory:', 'trigger_sql' => false]],
        ], 'database');
        $connection = $app->db->connect();
        $connection->query('SELECT 1');
        $pdo = $connection->getPdo();
        $pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'main', 0);
        $pdo->exec(<<<'SQL'
            ATTACH DATABASE ':memory:' AS information_schema;
            CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, TABLE_TYPE TEXT, TABLE_COMMENT TEXT);
            CREATE TABLE information_schema.COLUMNS (
                TABLE_SCHEMA TEXT, TABLE_NAME TEXT, COLUMN_NAME TEXT, COLUMN_COMMENT TEXT, COLUMN_TYPE TEXT,
                DATA_TYPE TEXT, IS_NULLABLE TEXT, COLUMN_KEY TEXT, COLUMN_DEFAULT TEXT, EXTRA TEXT, ORDINAL_POSITION INTEGER
            );
            INSERT INTO information_schema.TABLES VALUES ('main', 'shop_note', 'BASE TABLE', 'Generated note');
            INSERT INTO information_schema.COLUMNS VALUES
              ('main', 'shop_note', 'id', 'ID', 'int', 'int', 'NO', 'PRI', null, 'auto_increment', 1),
              ('main', 'shop_note', 'tenant_id', 'Tenant', 'int', 'int', 'NO', '', null, '', 2),
              ('main', 'shop_note', 'title', 'Title', 'varchar(60)', 'varchar', 'NO', '', null, '', 3),
              ('main', 'shop_note', 'category', 'Category', 'varchar(25)', 'varchar', 'NO', '', null, '', 4),
              ('main', 'shop_note', 'computed_label', 'Label', 'varchar(60)', 'varchar', 'YES', '', null, 'VIRTUAL GENERATED', 5);
            SQL);
        $app->instance(GeneratorService::class, new GeneratorService(new GeneratorImportPersistence(), new ThinkPhpGeneratorMetadata(), 'shop_'));
        $contexts = new ExecutionContextStore();
        $command = new ModuleGenerateCrud($contexts, new CurrentExecutionContext($contexts));
        $command->setApp($app);
        $model = $fixture->root . '/server/app/modules/fixture/delivery_record/src/Model/Note.php';
        $args = ['fixture.delivery-record', 'shop_note'];
        $preview = new Output('buffer');
        self::assertSame(0, $command->run(new Input($args), $preview));
        $plan = trim($preview->fetch());
        self::assertCount(14, explode(PHP_EOL, $plan));
        self::assertStringContainsString('merge server/app/modules/fixture/delivery_record/module.json', $plan);
        self::assertFileDoesNotExist($model);

        $write = new Output('buffer');
        self::assertSame(0, $command->run(new Input([...$args, '--write']), $write));
        self::assertSame($plan, trim($write->fetch()));
        self::assertStringContainsString("protected \$table = 'shop_note';", file_get_contents($model));
        $path = $fixture->root . '/web/src/modules/fixture-delivery-record/generated/note';
        $view = file_get_contents($path . '/index.vue');
        self::assertStringContainsString(':saved-views="savedViews"', $view);
        self::assertStringContainsString("key: 'title', editable: true", $view);
        self::assertStringNotContainsString("key: 'computed_label', editable: true", $view);
        $api = file_get_contents($path . '/api.ts');
        self::assertMatchesRegularExpression('/interface NoteUpdateInput \{\s+id: number;\s+title\?: string;\s+category\?: string;/', $api);

        // Daily G03 re-review must remain available after first generation and
        // must never rewrite hand-authored extensions or edited generated files.
        $ownedExtension = $fixture->root . '/web/src/modules/fixture-delivery-record/handwritten-extension.ts';
        $handwritten = '// handwritten; do not overwrite';
        self::assertSame(strlen($handwritten), file_put_contents($ownedExtension, $handwritten));
        $firstModel = file_get_contents($model);
        $firstView = file_get_contents($path . '/index.vue');
        $review = new Output('buffer');
        self::assertSame(0, $command->run(new Input($args), $review));
        $reviewPlan = trim($review->fetch());
        self::assertStringContainsString('unchanged ' . substr($model, strlen($fixture->root) + 1), $reviewPlan);
        self::assertStringNotContainsString('conflict ', $reviewPlan);
        self::assertSame($firstModel, file_get_contents($model));
        self::assertSame($firstView, file_get_contents($path . '/index.vue'));
        $retryWrite = new Output('buffer');
        self::assertSame(0, $command->run(new Input([...$args, '--write']), $retryWrite));
        self::assertSame($firstModel, file_get_contents($model));
        self::assertSame($firstView, file_get_contents($path . '/index.vue'));

        file_put_contents($path . '/index.vue', "\n<!-- handwritten customization -->\n", FILE_APPEND);
        $changedView = file_get_contents($path . '/index.vue');
        $conflictPlan = new Output('buffer');
        self::assertSame(0, $command->run(new Input($args), $conflictPlan));
        self::assertStringContainsString('conflict web/src/modules/fixture-delivery-record/generated/note/index.vue current=', $conflictPlan->fetch());
        self::assertSame($changedView, file_get_contents($path . '/index.vue'));
        try {
            $command->run(new Input([...$args, '--write']), new Output('buffer'));
            self::fail('A handwritten generated-file conflict must stop before any write.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('CRUD_CREATE_TARGET_CONFLICT', $exception->getMessage());
        }
        self::assertSame($changedView, file_get_contents($path . '/index.vue'));
        self::assertSame($firstModel, file_get_contents($model));
        self::assertSame($handwritten, file_get_contents($ownedExtension));

        require $fixture->root . '/server/app/modules/fixture/delivery_record/src/Validation/NoteValidate.php';
        $type = \PeanutAdmin\Fixtures\DeliveryRecord\Validation\NoteValidate::class;
        self::assertTrue((new $type())->scene('edit')->check(['id' => 1, 'title' => 'Only this field']));
        self::assertFalse((new $type())->scene('edit')->check(['id' => 1, 'title' => '']));
        self::assertFalse((new $type())->scene('add')->check(['title' => 'Only this field']));
    }
}
