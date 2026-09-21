<?php

declare(strict_types=1);

namespace app\modules\official\ops\domain\Task;

use InvalidArgumentException;
use app\modules\official\ops\domain\Support\Contract;

final readonly class BackupRestoreProviderDescriptor
{
    /**
     * @param list<string> $restoreTargetKeys
     */
    public function __construct(
        private BackupRestoreProvider $providerReference,
        public string $key,
        public string $backupHandlerKey,
        public string $restoreHandlerKey,
        public array $restoreTargetKeys,
        public int $maximumAttempts,
    ) {
        Contract::qualifiedKey($key);
        Contract::qualifiedKey($backupHandlerKey);
        Contract::qualifiedKey($restoreHandlerKey);
        if ($restoreTargetKeys === [] || count($restoreTargetKeys) > 32
            || $maximumAttempts < 1 || $maximumAttempts > 10
        ) {
            throw new InvalidArgumentException('Invalid operations provider registration.');
        }
        $unique = [];
        foreach ($restoreTargetKeys as $target) {
            Contract::qualifiedKey($target, 64);
            if (preg_match('/(?:^|[.-])(?:active|current|primary|prod|production)(?:$|[.-])/', $target) === 1
                || isset($unique[$target])
            ) {
                throw new InvalidArgumentException('Unsafe restore target registration.');
            }
            $unique[$target] = true;
        }
    }

    /** The provider object is retained only as an opaque Host-adapter boundary. */
    public function providerReference(): object
    {
        return $this->providerReference;
    }
}
