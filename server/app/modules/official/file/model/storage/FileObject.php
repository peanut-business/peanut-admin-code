<?php
declare(strict_types=1);

namespace app\modules\official\file\model\storage;

use app\common\model\TenantOwnedModel;

/** Tenant-owned storage ledger entry for one durable file object. */
final class FileObject extends TenantOwnedModel
{
    protected $name = 'file_object';
    protected $autoWriteTimestamp = false;
}
