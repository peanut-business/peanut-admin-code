<?php
declare(strict_types=1);

namespace app\modules\official\settings\contracts;

final class DeploymentSettingsTransferException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct($errorCode, 0, $previous);
    }
}
