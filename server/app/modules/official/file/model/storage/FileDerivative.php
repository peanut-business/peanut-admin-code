<?php
declare(strict_types=1);

namespace app\modules\official\file\model\storage;

use app\common\model\TenantOwnedModel;

final class FileDerivative extends TenantOwnedModel
{
    protected $name = 'file_derivative';
    protected $pk = 'variant_key';
    protected $autoWriteTimestamp = false;
}
