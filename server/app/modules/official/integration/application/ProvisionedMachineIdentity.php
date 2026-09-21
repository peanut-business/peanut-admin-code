<?php

declare(strict_types=1);

namespace app\modules\official\integration\application;

final readonly class ProvisionedMachineIdentity
{
    public function __construct(public MachineIdentity $identity, public string $token) {}
}
