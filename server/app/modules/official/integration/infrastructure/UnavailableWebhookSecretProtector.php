<?php
declare(strict_types=1);

namespace app\modules\official\integration\infrastructure;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;
use PeanutAdmin\IntegrationSecurity\Crypto\WebhookSecretProtector;

final class UnavailableWebhookSecretProtector implements WebhookSecretProtector
{
    public function seal(string $secret, string $binding): array
    {
        throw IntegrationSecurityException::secretInvalid();
    }

    public function open(string $ciphertext, string $keyId, string $binding): string
    {
        throw IntegrationSecurityException::secretInvalid();
    }
}
