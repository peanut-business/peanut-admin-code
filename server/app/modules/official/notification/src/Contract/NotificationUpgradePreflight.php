<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Notification\Contract;

use app\common\execution\CurrentExecutionContext;
use app\common\execution\InstanceExecutionContext;
use app\common\execution\InstallationExecutionContext;
use PDO;
use think\facade\Db;

/** Old-schema, read-only admission for the trusted instance maintenance host. */
final readonly class NotificationUpgradePreflight
{
    public const MIGRATION_KEY = 'official.notification:20261012-notification-producer-identity';

    public function __construct(private CurrentExecutionContext $contexts) {}

    public function report(): array
    {
        $unknown = [
            'schema_version' => 1, 'module_key' => 'official.notification',
            'status' => 'unknown', 'pending_count' => null, 'may_apply' => false,
            'code' => 'NOTIFICATION_UPGRADE_STATE_UNKNOWN',
        ];
        $context = $this->contexts->current();
        if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
            return $unknown;
        }
        $pdo = null;
        $started = false;
        try {
            $pdo = Db::connect()->getPdo();
            if (!$pdo instanceof PDO || $pdo->inTransaction()) { return $unknown; }
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->beginTransaction();
            $started = true;
            $table = Db::name('notification_outbox')->getTable();
            $exists = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ((int) ($exists[0]['n'] ?? 0) !== 1) { return $unknown; }
            $pending = (int) Db::name('notification_outbox')->whereIn('status', ['pending', 'queued', 'processing', 'retryable'])->count();
            $invalid = (int) Db::name('notification_outbox')->whereNotIn('status', ['pending', 'queued', 'processing', 'retryable', 'delivered', 'failed'])->count();
            if ($invalid !== 0) { return $unknown; }
            return [
                'schema_version' => 1, 'module_key' => 'official.notification',
                'status' => $pending === 0 ? 'ready' : 'blocked',
                'pending_count' => $pending, 'may_apply' => $pending === 0,
                'code' => $pending === 0 ? null : 'NOTIFICATION_LEGACY_DELIVERY_PENDING',
                'runtime_safe_window_required' => true,
            ];
        } catch (\Throwable) {
            return $unknown;
        } finally {
            if ($started && $pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
        }
    }

    public function assertMayApply(): void
    {
        $report = $this->report();
        if (!$report['may_apply']) { throw new \RuntimeException($report['code']); }
    }
}
