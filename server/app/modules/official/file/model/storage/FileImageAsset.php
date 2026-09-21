<?php
declare(strict_types=1);

namespace app\modules\official\file\model\storage;

use app\common\model\TenantOwnedModel;

final class FileImageAsset extends TenantOwnedModel
{
    protected $name = 'file_image_asset';
    protected $pk = 'file_key';
    protected $autoWriteTimestamp = false;
}
