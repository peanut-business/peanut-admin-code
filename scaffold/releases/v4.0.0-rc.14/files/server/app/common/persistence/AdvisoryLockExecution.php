<?php

declare(strict_types=1);

namespace app\common\persistence;

use app\common\value\runtime\RuntimeNamespace;
use think\facade\Db;

/** Executes one callback while holding a bounded MySQL advisory lock. */
final class AdvisoryLockExecution
{
    public function run(string $name, int $timeoutSeconds, callable $operation): mixed
    {
        if ($name === '' || $timeoutSeconds < 0 || $timeoutSeconds > 30) {
            throw new \InvalidArgumentException('DATABASE_ADVISORY_LOCK_INVALID');
        }

        $name = RuntimeNamespace::fromConfiguration()->advisoryLockName($name);
        $lock = Db::query('SELECT GET_LOCK(?, ?) AS acquired', [$name, $timeoutSeconds]);
        if ((int) ($lock[0]['acquired'] ?? 0) !== 1) {
            throw new AdvisoryLockUnavailable('DATABASE_ADVISORY_LOCK_UNAVAILABLE');
        }

        try {
            return $operation();
        } finally {
            try {
                Db::query('SELECT RELEASE_LOCK(?)', [$name]);
            } catch (\Throwable) {
            }
        }
    }
}
