<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\DeliveryRecord\Contract;

interface DeliveryRecordCommands
{
    /** @return array{id:int,tenant_id:int,reference:string,status:string} */
    public function record(string $reference): array;

    /** @return list<array{id:int,tenant_id:int,reference:string,status:string}> */
    public function list(): array;
}
