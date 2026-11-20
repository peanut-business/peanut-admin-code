<?php

declare(strict_types=1);

use app\common\http\ApiProblemMapper;
use app\common\services\XlsxExportService;
use PeanutAdmin\ImportExport\SpreadsheetReader;
use PHPUnit\Framework\TestCase;
use think\db\exception\PDOException as ThinkPdoException;

final class ScaffoldBackendContractTest extends TestCase
{
    public function testLegacyXlsxValuesAndFilenameArePreserved(): void
    {
        $method = new ReflectionMethod(XlsxExportService::class, 'createArtifact');
        [$path, $filename] = $method->invoke(null, 'abc.xlsx', ['a', 'b', 'c', 'd'], [[true, false, null, '=1+1']]);
        try {
            self::assertMatchesRegularExpression('/^abc-\d{8}-\d{6}-[a-f0-9]{6}\.xlsx$/', $filename);
            $rows = array_values(iterator_to_array((new SpreadsheetReader())->read($path)));
            self::assertSame(['1', '', '', '=1+1'], $rows[1]);
        } finally {
            @unlink($path);
        }
    }

    public function testAdditionalActionsAreDisabledWithoutExplicitOptIn(): void
    {
        $controller = new class {
            use \app\common\traits\CrudTrait;
            protected function resolveCrudContext(): mixed { return null; }
        };
        $this->expectException(\app\common\exception\BusinessException::class);
        $controller->export();
    }

    public function testOnlyKnownDataConstraintsAreMappedTo422(): void
    {
        $mapper = new ApiProblemMapper();
        $error = new PDOException('constraint');
        $error->errorInfo = ['23000', 1062, 'duplicate'];
        self::assertSame(422, $mapper->map(new ThinkPdoException($error))->httpStatus);
        $error = new PDOException('connection');
        $error->errorInfo = ['HY000', 2002, 'connection failed'];
        self::assertNull($mapper->map(new ThinkPdoException($error)));
    }
}
