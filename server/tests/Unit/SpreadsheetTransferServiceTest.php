<?php

declare(strict_types=1);

use app\common\services\SpreadsheetTransferService;
use PeanutAdmin\ImportExport\ColumnTemplate;
use PHPUnit\Framework\TestCase;

final class SpreadsheetTransferServiceTest extends TestCase
{
    public function testCsvAndXlsxRowsAreValidatedAndPreviewIsBounded(): void
    {
        $service = new SpreadsheetTransferService();
        $template = new ColumnTemplate(['name' => static fn($value) => $value === '' ? 'required' : null]);
        foreach (['csv', 'xlsx'] as $format) {
            $path = sys_get_temp_dir() . '/peanut-transfer-' . bin2hex(random_bytes(8)) . '.' . $format;
            try {
                $service->export($path, ['name'], [['first'], ['second']]);
                self::assertSame('first', $service->preview($path, $template, 1)[0]['values']['name']);
                self::assertCount(2, iterator_to_array($service->rows($path, $template)));
                $service->template($path, $template);
                self::assertSame([], iterator_to_array($service->rows($path, $template)));
            } finally {
                @unlink($path);
            }
        }
    }

    public function testWrongHeadersAreRejected(): void
    {
        $path = sys_get_temp_dir() . '/peanut-transfer-' . bin2hex(random_bytes(8)) . '.csv';
        try {
            $service = new SpreadsheetTransferService();
            $service->export($path, ['wrong'], []);
            $this->expectException(InvalidArgumentException::class);
            iterator_to_array($service->rows($path, new ColumnTemplate(['name' => static fn($value) => null])));
        } finally {
            @unlink($path);
        }
    }
}
