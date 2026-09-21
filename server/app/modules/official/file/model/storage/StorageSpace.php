<?php
declare(strict_types=1);

namespace app\modules\official\file\model\storage;

use app\common\model\InstanceOwnedModel;

/** Deployment-owned bucket or local filesystem space configuration. */
final class StorageSpace extends InstanceOwnedModel
{
    protected $name = 'storage_space';
    protected $autoWriteTimestamp = false;
}
