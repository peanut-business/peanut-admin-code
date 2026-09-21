<?php

declare(strict_types=1);

namespace app\modules\official\integration\contracts;

class ExternalTenantResolutionException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('EXTERNAL_CALLBACK_REJECTED');
    }
}
