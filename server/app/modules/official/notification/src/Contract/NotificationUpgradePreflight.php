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
        $pdo = null;
        $started = false;
        $phase = 'execution_context';
        try {
            $context = $this->contexts->current();
            if (!$context instanceof InstanceExecutionContext && !$context instanceof InstallationExecutionContext) {
                return $unknown + ['reason' => 'EXECUTION_CONTEXT_UNAVAILABLE', 'phase' => $phase];
            }
            $phase = 'connection';
            $connection = Db::connect();
            // getPdo() observes the current link; a native query initializes a lazy connection.
            if ($connection->getPdo() === false) {
                $connection->query('SELECT 1', [], true);
            }
            $pdo = $connection->getPdo();
            if (!$pdo instanceof PDO) {
                return $unknown + ['reason' => 'CONNECTION_UNAVAILABLE', 'phase' => $phase];
            }
            if ($pdo->inTransaction()) {
                return $unknown + ['reason' => 'TRANSACTION_ALREADY_ACTIVE', 'phase' => $phase];
            }
            $phase = 'transaction_read_only';
            $pdo->exec('SET TRANSACTION READ ONLY');
            $phase = 'transaction_begin';
            $pdo->beginTransaction();
            $started = true;
            $phase = 'outbox_schema';
            $table = Db::name('notification_outbox')->getTable();
            $exists = Db::query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]);
            if ((int) ($exists[0]['n'] ?? 0) !== 1) {
                return $unknown + ['reason' => 'OUTBOX_SCHEMA_UNAVAILABLE', 'phase' => $phase];
            }
            $phase = 'pending_count';
            $pending = (int) Db::name('notification_outbox')->whereIn('status', ['pending', 'queued', 'processing', 'retryable'])->count();
            $phase = 'status_validation';
            $invalid = (int) Db::name('notification_outbox')->whereNotIn('status', ['pending', 'queued', 'processing', 'retryable', 'delivered', 'permanent_failed'])->count();
            if ($invalid !== 0) {
                return $unknown + ['reason' => 'OUTBOX_STATUS_UNRECOGNIZED', 'phase' => $phase];
            }
            return [
                'schema_version' => 1, 'module_key' => 'official.notification',
                'status' => $pending === 0 ? 'ready' : 'blocked',
                'pending_count' => $pending, 'may_apply' => $pending === 0,
                'code' => $pending === 0 ? null : 'NOTIFICATION_LEGACY_DELIVERY_PENDING',
                'runtime_safe_window_required' => true,
            ];
        } catch (\Throwable) {
            return $unknown + ['reason' => 'PREFLIGHT_OPERATION_FAILED', 'phase' => $phase];
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
