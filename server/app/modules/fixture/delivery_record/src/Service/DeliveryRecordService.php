<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Service;

use InvalidArgumentException;
use PeanutAdmin\Fixtures\DeliveryRecord\Contract\DeliveryRecordCommands;
use PeanutAdmin\Fixtures\DeliveryRecord\Model\DeliveryRecord;
use app\common\execution\CurrentExecutionContext;

final readonly class DeliveryRecordService implements DeliveryRecordCommands
{
    public function __construct(
        private DeliveryRecordAccess $access,
        private CurrentExecutionContext $executionContext,
    ) {}

    public function record(string $reference): array
    {
        $this->executionContext->tenantAdmin();
        $this->access->requirePermission('fixture.delivery-record.create');
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 96) {
            throw new InvalidArgumentException('Delivery reference must contain at most 96 bytes.');
        }

        $now = gmdate('Y-m-d H:i:s.v');
        $record = DeliveryRecord::create([
            'reference' => $reference,
            'status' => 'recorded',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'id' => (int) $record->id,
            'tenant_id' => (int) $record->tenant_id,
            'reference' => (string) $record->reference,
            'status' => (string) $record->status,
        ];
    }

    public function list(): array
    {
        $this->executionContext->tenantAdmin();
        $this->access->requirePermission('fixture.delivery-record.read');
        return array_map(
            static fn(array $row): array => [
                'id' => (int) $row['id'],
                'tenant_id' => (int) $row['tenant_id'],
                'reference' => (string) $row['reference'],
                'status' => (string) $row['status'],
            ],
            DeliveryRecord::field('id,tenant_id,reference,status')->order('id')->select()->toArray(),
        );
    }
}
