<?php

declare(strict_types=1);

use app\common\execution\AdminExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Kernel\Persistence\Schema\KernelSchema;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Modules\Identity\Contract\TenantSessionAccess;
use PeanutAdmin\Modules\Document\Contract\DocumentCommands;
use PeanutAdmin\Modules\Document\Contract\DocumentQueries;
use PeanutAdmin\Modules\Document\Contract\DocumentOwnerReference;
use PeanutAdmin\Modules\Document\Model\DocumentRevision;
use PeanutAdmin\Modules\File\Contract\FileDocumentSnapshots;
use PeanutAdmin\Modules\File\Contract\FileAccess;
use PeanutAdmin\Modules\File\Service\Storage\StorageService;
use PeanutAdmin\Modules\File\Service\FileAuthorizationService;
use PeanutAdmin\Modules\RichText\Contract\RichTextProjection;
use think\facade\Db;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';
require_once dirname(__DIR__, 3) . '/database/install.php';
require_once dirname(__DIR__, 2) . '/Support/RegisteredMysqlTestResource.php';
require_once dirname(__DIR__, 2) . '/Support/ThinkPhpTestConnection.php';

$assertions = 0;
function documentExpect(bool $condition, string $message): void
{
    global $assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions++;
}
function documentDenied(callable $operation, string $message, ?string $errorCode = null): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        documentExpect($error instanceof \app\common\exception\BusinessException
            || $error instanceof \PeanutAdmin\Modules\Identity\Contract\TenantSessionAccessException
            || $error instanceof \PeanutAdmin\Kernel\Module\ModuleException, $message . ': unexpected ' . $error::class . ': ' . $error->getMessage());
        if ($errorCode !== null) {
            documentExpect($error instanceof \app\common\exception\BusinessException && $error->errorCode === $errorCode, $message . ': wrong rejection reason');
        }
        return;
    }
    throw new RuntimeException($message . ': accepted');
}
function documentProjection(string $text, ?string $fileKey = null): array
{
    $nodes = [['type' => 'paragraph','content' => [['type' => 'text','text' => $text]]]];
    if ($fileKey !== null) {
        $nodes[] = ['type' => 'media','attrs' => ['fileKey' => $fileKey,'kind' => 'image']];
    }
    return ['schemaVersion' => RichTextProjection::SCHEMA,'editorModel' => 'tiptap-prosemirror','content' => ['type' => 'doc','content' => $nodes],'annotations' => []];
}

final class PreparedNativeCrontabProbe extends \app\command\CrontabDemo
{
    protected function handle(\think\console\Input $input, \think\console\Output $output): int
    {
        $stack = app(ExecutionContextStore::class);
        $current = $stack->require();
        documentExpect($current instanceof \app\common\execution\SystemExecutionContext && $stack->rootIdentity() !== null, 'native dispatch has perjob System root');
        documentExpect($current->metadata->jobKey !== null && $current->metadata->attemptNumber > 0 && $current->metadata->handlerKey === 'official.task.crontab.execute', 'real claimed job metadata survives nested dispatch');
        $root = $stack->rootIdentity();
        $GLOBALS['preparedTaskRoots'][] = [$root,$current->tenantId(),$current->metadata->jobKey,$current->metadata->attemptNumber];
        $result = parent::handle($input, $output);
        documentExpect($stack->rootIdentity() === $root, 'native command returns in original job root');
        $pdo = Db::connect()->getPdo();
        $before = $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn();
        $scheduler = app(\PeanutAdmin\Modules\Task\Contract\TaskScheduler::class);
        foreach ([fn() => $scheduler->runDue(time()),fn() => $scheduler->start(\PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext::require(), ['id' => 1])] as $nested) {
            try {
                $nested();
                throw new RuntimeException('recursive scheduler accepted');
            } catch (DomainException $e) {
                documentExpect($e->getMessage() === 'TASK_EXECUTION_ROOT_REQUIRED', 'ambient scheduler entry is rejected before enqueue');
            }
        }
        documentExpect($before === $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'nested scheduler leaves queue unchanged');
        if (($GLOBALS['preparedTaskMode'] ?? null) === 'lease_once') {
            $GLOBALS['preparedTaskMode'] = null;
            $q = $pdo->prepare('UPDATE pa_task_job SET lease_expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(3)) WHERE job_key=?');
            $q->execute([$current->metadata->jobKey]);
        } elseif (($GLOBALS['preparedTaskMode'] ?? null) === 'revoke') {
            $GLOBALS['preparedTaskMode'] = null;
            $q = $pdo->prepare("UPDATE pa_tenant_module SET status='disabled' WHERE tenant_id=? AND module_key='official.task'");
            $q->execute([$current->tenantId()]);
        }
        return $result;
    }
}
$database = RegisteredMysqlTestResource::configuredDatabaseName();
[$pdo, $created] = RegisteredMysqlTestResource::openEmptyDatabase($database);
$paths = [];
try {
    foreach (KernelSchema::tableNames() as $table) {
        $pdo->exec(KernelSchema::createSql($table));
    }
    $pdo->exec(KernelSchema::addTenantMemberDepartmentForeignKeySql());
    ensureTenantChallengeClientKey($pdo);
    ThinkPhpTestConnection::fromPdo($pdo);
    $bootstrap = new \PeanutAdmin\Modules\Identity\Platform\Bootstrap\BootstrapService(
        new \PeanutAdmin\Modules\Identity\Audit\AuditService(),
        new \PeanutAdmin\Kernel\Identity\PasswordHasher(),
        new \PeanutAdmin\Kernel\Identity\PasswordPolicy(),
    );
    $platform = $bootstrap->bootstrapPlatformOwner('b1-owner@example.invalid', 'SyntheticB1Acceptance2026!', 'B1 Owner', 'b1-bootstrap');
    $owner = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'default', 'B1 Synthetic', 'b1-owner@example.invalid', null, 'B1 Owner', 'b1-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $owner->tenantId, $owner->memberId, 'b1-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $owner->tenantId, 'b1-tenant-active');
    $serverRoot = dirname(__DIR__, 3);
    executeSqlFile($pdo, $serverRoot . '/database/init.sql');
    executeSqlFiles($pdo, applicationMigrationFiles($serverRoot . '/database'));
    foreach (['task'] as $module) {
        $migrations = glob($serverRoot . '/app/modules/official/' . $module . '/database/migrations/*.sql');
        sort($migrations);
        executeSqlFiles($pdo, $migrations);
    }
    $app = new think\App();
    $app->initialize();
    ThinkPhpTestConnection::fromPdo($pdo);
    $registry = $app->make(CompiledModuleRegistry::class);
    foreach (['official.identity', 'official.task'] as $moduleKey) {
        $manifest = $registry->requireManifest($moduleKey);
        $s = $pdo->prepare('INSERT INTO pa_module_installation(module_key,installed_version,manifest_schema_version,manifest_digest,status,revision,installed_at,activated_at,created_at,updated_at) VALUES(?,?,?,?,"active",1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$moduleKey, $manifest->data['version'], $manifest->data['schema_version'], $manifest->digest]);
        $s = $pdo->prepare('INSERT INTO pa_tenant_module(tenant_id,module_key,status,source,config_revision,authorization_revision,created_at,updated_at) VALUES(?,?,"enabled","manual",1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))');
        $s->execute([$owner->tenantId, $moduleKey]);
    }
    $app->make(\app\platform\infrastructure\plugin\ModuleCatalogApplier::class)->apply($registry, ['official.identity', 'official.task']);
    $tenant = $owner->tenantId;
    $account = $owner->accountId;
    $member = $owner->memberId;
    $sessionKey = '01K00000000000000000000001';
    $s = $pdo->prepare('INSERT INTO pa_tenant_session(session_key,tenant_id,account_id,tenant_member_id,client_key,status,account_security_revision,tenant_security_revision,member_security_revision,issued_at,last_seen_at,idle_expires_at,absolute_expires_at,created_at,updated_at) SELECT ?,m.tenant_id,m.account_id,m.id,"admin-web","active",a.security_revision,t.security_revision,m.security_revision,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 1 HOUR),DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 2 HOUR),UTC_TIMESTAMP(3),UTC_TIMESTAMP(3) FROM pa_tenant_member m JOIN pa_account a ON a.id=m.account_id JOIN pa_tenant t ON t.id=m.tenant_id WHERE m.id=?');
    $s->execute([$sessionKey,$member]);
    $sessions = $app->make(TenantSessionAccess::class);
    $context = $sessions->revalidateSession($tenant, $account, $member, $sessionKey, 'admin-web', 'b1-native');
    $stack = $app->make(ExecutionContextStore::class);
    $run = function (callable $call) use ($stack, $app, &$context) {
        return $stack->run(new AdminExecutionContext($context, 'prepared.document', $app->make(\app\common\contract\authorization\AdminAuthorizationQuery::class)->principal($context)->toArray()), $call);
    };

    $commandsConfig = $app->config->get('console.commands');
    $commandsConfig['crontab:demo'] = PreparedNativeCrontabProbe::class;
    $app->config->set(['commands' => $commandsConfig] + $app->config->get('console'), 'console');
    $app->config->set(['signing_key' => bin2hex(random_bytes(32)),'worker_limit' => 25], 'async');
    $runtime = $app->make(\PeanutAdmin\Modules\Task\Contract\TaskJobRuntime::class);
    $scheduler = $app->make(\PeanutAdmin\Modules\Task\Contract\TaskScheduler::class);
    $console = $app->make('console');
    $beta = $bootstrap->provisionTenantOwnerCandidate($platform->operatorId, 'beta-prepared', 'Prepared Beta', 'prepared-beta@example.invalid', 'SyntheticBetaAcceptance2026!', 'Prepared Beta', 'prepared-beta-owner');
    $bootstrap->activateTenantOwner($platform->operatorId, $beta->tenantId, $beta->memberId, 'prepared-beta-owner-active');
    $bootstrap->activateTenant($platform->operatorId, $beta->tenantId, 'prepared-beta-tenant-active');
    $q = $pdo->prepare("INSERT INTO pa_tenant_module(tenant_id,module_key,status,source,config_revision,authorization_revision,created_at,updated_at) VALUES(?,'official.task','enabled','manual',1,1,UTC_TIMESTAMP(3),UTC_TIMESTAMP(3))");
    $q->execute([$beta->tenantId]);
    $pdo->exec('DELETE FROM pa_crontab');
    $ids = [];
    foreach ([$tenant,$beta->tenantId] as $tid) {
        $q = $pdo->prepare("INSERT INTO pa_crontab(tenant_id,name,type,command,params,status,expression,last_time,create_time,update_time) VALUES(?,'Prepared Native CLI',1,'crontab:demo','',1,'* * * * *',?,?,?)");
        $q->execute([$tid,time() - 60,time(),time()]);
        $ids[$tid] = (int) $pdo->lastInsertId();
    }
    $GLOBALS['preparedTaskRoots'] = [];
    $console->call('crontab');
    documentExpect((int) $pdo->query("SELECT COUNT(*) FROM pa_task_job WHERE status='succeeded'")->fetchColumn() === 2, 'native cron CLI processes two real signed tenant jobs');
    $roots = $GLOBALS['preparedTaskRoots'];
    documentExpect(count($roots) === 2 && $roots[0][0] !== $roots[1][0] && $roots[0][1] !== $roots[1][1], 'cross-tenant jobs have distinct roots');
    documentExpect($stack->isEmpty() && $stack->rootIdentity() === null && \PeanutAdmin\Kernel\Tenancy\ScheduledTenantContext::current() === null, 'CLI and scheduled context finally release');
    $enqueue = function (int $tid, int $window) use ($runtime, $ids) {
        $identity = \PeanutAdmin\Modules\Task\Service\CrontabTaskDefinition::contextIdentity($tid, $ids[$tid], $window);
        $runtime->enqueueCrontab(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tid, $identity), $ids[$tid], $identity);
    };
    $before = $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn();
    $run(function () use ($runtime, $scheduler, $tenant, $ids, $before, $pdo): void {
        foreach ([fn() => $runtime->runTenant($tenant, 'prepared-invalid-worker'),fn() => $scheduler->runDue(time()),
            fn() => $scheduler->start(\PeanutAdmin\Kernel\Tenancy\TenantScope::fromTrustedContext($tenant, 'prepared-invalid-start'), ['id' => $ids[$tenant]])] as $call) {
            try {
                $call();
                throw new RuntimeException('ambient native runtime accepted');
            } catch (DomainException $e) {
                documentExpect($e->getMessage() === 'TASK_EXECUTION_ROOT_REQUIRED', 'runtime/scheduler rejects ambient before side effects');
            }
        }
        documentExpect($before === $pdo->query('SELECT COUNT(*) FROM pa_task_job')->fetchColumn(), 'ambient calls do not claim or enqueue');
    });
    $enqueue($tenant, time() + 10);
    $GLOBALS['preparedTaskMode'] = 'lease_once';
    $beforeRoots = count($GLOBALS['preparedTaskRoots']);
    $console->call('tenant-task:work', [(string) $tenant]);
    $last = $pdo->query('SELECT status,attempt_count FROM pa_task_job ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    documentExpect($last['status'] === 'succeeded' && (int) $last['attempt_count'] === 2, 'native worker fences lost lease and genuinely reclaims original job');
    $roots = $GLOBALS['preparedTaskRoots'];
    documentExpect(count($roots) === $beforeRoots + 2 && $roots[count($roots) - 1][0] !== $roots[count($roots) - 2][0], 'same job recovery attempt gets fresh root');
    $enqueue($tenant, time() + 20);
    $GLOBALS['preparedTaskMode'] = 'revoke';
    $console->call('tenant-task:work', [(string) $tenant]);
    $last = $pdo->query('SELECT status,last_error_code FROM pa_task_job ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    documentExpect($last['status'] === 'dead' && $last['last_error_code'] === 'TASK_PERMISSION_DENIED', 'live module revocation denied by actual final fence');
    documentExpect($stack->isEmpty() && $stack->rootIdentity() === null, 'denied final fence releases root');
    $pdo->exec("UPDATE pa_tenant_module SET status='enabled' WHERE module_key='official.task'");
    $enqueue($tenant, time() + 30);
    $beforeRoots = count($GLOBALS['preparedTaskRoots']);
    $pdo->exec("UPDATE pa_task_job SET trusted_envelope=JSON_SET(trusted_envelope,'$.signature',REPEAT('0',64)) WHERE status='queued'");
    $console->call('tenant-task:work', [(string) $tenant]);
    $last = $pdo->query('SELECT status,last_error_code FROM pa_task_job ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    documentExpect($last['status'] === 'dead' && $last['last_error_code'] === 'TASK_PERMISSION_DENIED' && count($GLOBALS['preparedTaskRoots']) === $beforeRoots, 'forged envelope never enters job root or native handler');
    documentExpect($stack->isEmpty() && $stack->rootIdentity() === null,'all runtime paths terminate with empty stack');
    echo 'TASK_NATIVE_ROOT_OK assertions=' . $assertions . ' actual_codec_claim_reauthorize_cli=1 real_mysql=8.4' . PHP_EOL;
} finally {
    RegisteredMysqlTestResource::cleanup($pdo,$database,$created);
}
