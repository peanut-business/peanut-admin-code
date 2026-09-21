<?php
declare(strict_types=1);

namespace app\modules\official\settings\infrastructure;

use PeanutAdmin\Settings\Secret\SecretProtectionException;
use PeanutAdmin\Settings\Secret\SecretProtector;
use PeanutAdmin\Settings\Secret\SecretStorageContext;

final class UnavailableSecretProtector implements SecretProtector
{
    public function protect(string $plaintext, SecretStorageContext $context): array
    {
        throw SecretProtectionException::unavailable();
    }

    public function reveal(string $ciphertext, string $nonce, string $keyId, SecretStorageContext $context): string
    {
        throw SecretProtectionException::unavailable();
    }
}
