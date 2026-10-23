<?php

declare(strict_types=1);

namespace app\command;

use app\common\execution\ModuleContextualCommand;
use app\common\services\upgrade\ApplicationMigrationRunner;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PeanutAdmin\Modules\Identity\Contract\TenantOwnerAdminProvisioner;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantEntryBindingAdminService;
use PeanutAdmin\Modules\Identity\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Modules\Identity\Tenancy\TenantStatus;
use RuntimeException;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/** An explicit, reviewed local development maintenance operation, never an installer. */
final class DevelopmentTenantSites extends ModuleContextualCommand
{
    protected function configure(): void
    {
        $this->setName('development:tenant-sites')
            ->setDescription('Inspect or apply a protected development tenant-site plan')
            ->addOption('plan', null, Option::VALUE_REQUIRED, 'Private reviewed plan')
            ->addOption('apply', null, Option::VALUE_NONE, 'Execute once; default is read-only');
    }

    protected function handle(Input $input, Output $output): int
    {
        $attempt = null;
        $stage = 'preflight';
        $lastDurableStage = 'none';
        $planSha = null;
        try {
            $this->assertDevelopmentInstanceMaintenanceAccess();
            $server = rtrim($this->getApp()->getRootPath(), DIRECTORY_SEPARATOR);
            require_once $server . '/database/environment-guard.php';
            $config = \guardedDatabaseConfig();
            $connection = Db::connect();
            $identity = $connection->query('SELECT DATABASE() AS database_name');
            if (($identity[0]['database_name'] ?? null) !== $config['database']) { throw new RuntimeException('DEVELOPMENT_SITES_DATABASE_CHANGED'); }
            $pdo = $connection->getPdo();
            $snapshot = self::snapshot($pdo);
            if (!$input->getOption('apply')) {
                $output->writeln(json_encode(['status' => 'observed', 'database' => $config['database'], 'snapshot' => $snapshot], JSON_THROW_ON_ERROR));
                return 0;
            }
            $plan = json_decode(self::privateFile((string) $input->getOption('plan')), true, 64, JSON_THROW_ON_ERROR);
            if (($plan['protocol'] ?? null) !== 'peanut.development-tenant-sites.v1'
                || $config['database'] !== 'peanut_dev_mt'
                || ($plan['database'] ?? null) !== $config['database'] || ($plan['snapshot'] ?? null) !== $snapshot
                || !is_int($plan['expires_at'] ?? null) || $plan['expires_at'] <= time()
                || !is_string($plan['approval_ref'] ?? null) || $plan['approval_ref'] === ''
                || ($plan['review_status'] ?? null) !== 'accepted'
                || !is_string($plan['backup'] ?? null) || !is_string($plan['backup_sha256'] ?? null)
                || !hash_equals(hash_file('sha256', $plan['backup']), $plan['backup_sha256'])) {
                throw new RuntimeException('DEVELOPMENT_SITES_PLAN_INVALID');
            }
            $sql = $server . '/database/maintenance/20261024-development-admin-credentials.sql';
            $sources = ['server/app/command/DevelopmentTenantSites.php' => hash_file('sha256', __FILE__),
                'server/app/common/services/upgrade/ApplicationMigrationRunner.php' => hash_file('sha256', $server . '/app/common/services/upgrade/ApplicationMigrationRunner.php'),
                'server/config/console.php' => hash_file('sha256', $server . '/config/console.php'),
                'server/database/maintenance/20261024-development-admin-credentials.sql' => hash_file('sha256', $sql)];
            $review = json_decode(self::privateFile((string) ($plan['review_receipt'] ?? '')), true, 64, JSON_THROW_ON_ERROR);
            $metadata = \activeLeaseMetadata((string) getenv('PEANUT_RESOURCE_LEASE_PROOF'), time(), 'shared-development');
            if (($plan['source_files'] ?? null) !== $sources || ($review['source_files'] ?? null) !== $sources
                || ($review['status'] ?? null) !== 'accepted' || !is_string($review['reviewer_agent'] ?? null)
                || ($plan['owner'] ?? null) !== $metadata['owner'] || ($plan['thread'] ?? null) !== $metadata['thread']
                || ($plan['source_ref'] ?? null) !== $metadata['candidate'] || ($plan['server_uuid'] ?? null) !== $snapshot['server_uuid']
                || ($review['server_uuid'] ?? null) !== $snapshot['server_uuid']) {
                throw new RuntimeException('DEVELOPMENT_SITES_REVIEW_BINDING_INVALID');
            }
            if ($snapshot['account']['status'] !== 'active' || $snapshot['account']['locked_until'] !== null
                || $snapshot['credential']['identifier_normalized'] !== 'admin@peanut.local'
                || $snapshot['credential']['account_id'] !== 1 || $snapshot['credential']['status'] !== 'active'
                || $snapshot['credential']['kind'] !== 'email_password' || $snapshot['credential']['identifier_type'] !== 'email'
                || $snapshot['credential']['locked_until'] !== null || $snapshot['credential']['expires_at'] !== null
                || $snapshot['credential']['failed_attempts'] !== 0 || $snapshot['platform_role_bindings'] === []) {
                throw new RuntimeException('DEVELOPMENT_SITES_TARGET_INELIGIBLE');
            }
            if ((int) $pdo->query("SELECT COUNT(*) FROM pa_credential WHERE identifier_normalized IN ('admin@test.com','platform@test.com','admin-a@test.com','admin-b@test.com')")->fetchColumn() !== 0
                || (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant WHERE code IN ('tenant-a','tenant-b')")->fetchColumn() !== 0
                || (int) $pdo->query("SELECT COUNT(*) FROM pa_tenant_entry_binding WHERE host IN ('tenant-a-demo.peanut-admin.localhost','tenant-b.peanut-admin.localhost','public.peanut-admin.localhost')")->fetchColumn() !== 0) {
                throw new RuntimeException('DEVELOPMENT_SITES_TARGET_CONFLICT');
            }
            foreach ([PlatformAccessAdminService::class, PlatformTenantAdminService::class, TenantOwnerAdminService::class,
                TenantOwnerAdminProvisioner::class, TenantEntryBindingAdminService::class] as $service) { $this->getApp()->make($service); }
            if (TenantStatus::Active->value !== 'active') { throw new RuntimeException('DEVELOPMENT_SITES_SERVICE_CONTRACT_INVALID'); }
            $passwordPath = realpath((string) ($plan['password_file'] ?? ''));
            $previousPath = realpath((string) ($plan['previous_password_file'] ?? ''));
            $privateRoot = $server . '/private/credentials/';
            if (!is_string($passwordPath) || !is_string($previousPath)
                || !str_starts_with($passwordPath, $privateRoot) || !str_starts_with($previousPath, $privateRoot)) {
                throw new RuntimeException('DEVELOPMENT_SITES_PASSWORD_REF_INVALID');
            }
            $password = rtrim(self::privateFile($passwordPath), "\r\n");
            $previous = rtrim(self::privateFile($previousPath), "\r\n");
            if (($plan['password_sha256'] ?? null) !== hash('sha256', $password)
                || ($plan['previous_password_sha256'] ?? null) !== hash('sha256', $previous)) {
                throw new RuntimeException('DEVELOPMENT_SITES_PASSWORD_CHANGED');
            }
            $this->getApp()->make(PasswordPolicy::class)->assertValid($password);
            $hasher = $this->getApp()->make(PasswordHasher::class);
            $stored = (string) $pdo->query('SELECT secret_hash FROM pa_credential WHERE id=1 AND account_id=1')->fetchColumn();
            if (!$hasher->verify($previous, $stored)) { throw new RuntimeException('DEVELOPMENT_SITES_PREVIOUS_CREDENTIAL_CHANGED'); }
            $proof = (string) getenv('PEANUT_RESOURCE_LEASE_PROOF');
            $access = array_map(static fn(string $value): array => json_decode($value, true, 64, JSON_THROW_ON_ERROR), \activeLeaseResources($proof)['mysql-access'] ?? []);
            $exclusive = array_filter($access, static fn(array $row): bool => ($row['schema'] ?? '') === $config['host'] . ':' . $config['port'] . '/' . $config['database']
                && in_array($row['operation'] ?? '', ['ddl', 'exclusive'], true) && ($row['complete'] ?? false) === true);
            if (count($exclusive) !== 1) { throw new RuntimeException('DEVELOPMENT_SITES_EXCLUSIVE_SCOPE_REQUIRED'); }
            $planPath = (string) $input->getOption('plan');
            $planSha = hash_file('sha256', $planPath);
            $guard = static function () use ($config, $plan, $sources, $server, $passwordPath, $previousPath, $planPath, $planSha): void {
                if (\guardedDatabaseConfig() !== $config || time() >= $plan['expires_at'] || hash_file('sha256', $planPath) !== $planSha
                    || hash('sha256', rtrim(self::privateFile($passwordPath), "\r\n")) !== $plan['password_sha256']
                    || hash('sha256', rtrim(self::privateFile($previousPath), "\r\n")) !== $plan['previous_password_sha256']) {
                    throw new RuntimeException('DEVELOPMENT_SITES_ADMISSION_CHANGED');
                }
                foreach ($sources as $file => $sha) {
                    if (hash_file('sha256', dirname($server) . '/' . $file) !== $sha) { throw new RuntimeException('DEVELOPMENT_SITES_SOURCE_CHANGED'); }
                }
            };
            $attemptPath = (string) ($plan['attempt_file'] ?? '');
            if (!str_starts_with($attemptPath, $server . '/private/') || realpath(dirname($attemptPath)) !== dirname($attemptPath)) {
                throw new RuntimeException('DEVELOPMENT_SITES_ATTEMPT_PATH_INVALID');
            }
            $previousUmask = umask(0077);
            try { $attempt = fopen($attemptPath, 'x'); } finally { umask($previousUmask); }
            if ($attempt === false) { throw new RuntimeException('DEVELOPMENT_SITES_RECONCILIATION_REQUIRED'); }
            if (!chmod($attemptPath, 0600)) { throw new RuntimeException('DEVELOPMENT_SITES_ATTEMPT_MODE_FAILED'); }
            $writeStage = static function (string $phase, array $result = []) use ($attempt, $planSha, &$lastDurableStage): void {
                self::appendDurably($attempt, ['plan_sha256' => $planSha, 'stage' => $phase, 'result' => $result]);
                $lastDurableStage = $phase;
            };
            $stage = 'credentials_applying'; $writeStage($stage);
            $vars = [
                'account' => 1, 'credential' => 1, 'identifier' => 'admin@test.com', 'old_identifier' => 'admin@peanut.local',
                'credential_revision' => $snapshot['credential']['revision'], 'security_revision' => $snapshot['account']['security_revision'],
                'hash' => $hasher->hash($password), 'request' => 'development-sites-20261024', 'approval' => $plan['approval_ref'],
                'password_ref' => 'server/private/credentials/' . basename($passwordPath),
                'before' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            ];
            $migration = (new ApplicationMigrationRunner($config, $pdo))->run([$sql], '5.0.2', '5.0.2', false,
                static function (PDO $connection) use ($snapshot, $vars, $guard): void {
                    $guard();
                    if (self::snapshot($connection) !== $snapshot) { throw new RuntimeException('DEVELOPMENT_SITES_PRESTATE_CHANGED'); }
                    foreach ($vars as $key => $value) {
                        $expression = is_string($value) ? 'CONVERT(? USING utf8mb4) COLLATE utf8mb4_0900_ai_ci' : '?';
                        $connection->prepare('SET @maintenance_' . $key . '=' . $expression)->execute([$value]);
                    }
                }, null,
                static function (PDO $connection) use ($password, $hasher, $snapshot, $guard): void {
                    $guard();
                    $row = $connection->query('SELECT identifier_normalized,secret_hash,revision FROM pa_credential WHERE id=1 AND account_id=1')->fetch();
                    $revision = (int) $connection->query('SELECT security_revision FROM pa_account WHERE id=1')->fetchColumn();
                    if ($row['identifier_normalized'] !== 'admin@test.com' || !$hasher->verify($password, $row['secret_hash'])
                        || (int) $row['revision'] !== $snapshot['credential']['revision'] + 1 || $revision !== $snapshot['account']['security_revision'] + 1) {
                        throw new RuntimeException('DEVELOPMENT_SITES_CREDENTIAL_POSTSTATE_INVALID');
                    }
                },
                static function (PDO $connection, string $id, array $row) use ($plan, $snapshot, $guard, $writeStage): bool {
                    $guard();
                    $recovery = json_decode(self::privateFile((string) ($plan['rolled_back_failure_receipt'] ?? '')), true, 64, JSON_THROW_ON_ERROR);
                    if (($plan['rolled_back_failure_sha256'] ?? null) !== hash_file('sha256', $plan['rolled_back_failure_receipt'])
                        || $id !== '20261024-development-admin-credentials' || ($recovery['failed_row'] ?? null) !== $row
                        || ($recovery['snapshot'] ?? null) !== $snapshot || self::snapshot($connection) !== $snapshot
                        || !str_starts_with((string) $row['error_code'], 'SQLSTATE[HY000]: General error: 1267 Illegal mix of collations')
                        || $row['checksum'] !== hash_file('sha256', dirname(__DIR__, 2) . '/database/maintenance/' . $id . '.sql')
                        || ($recovery['attempt_sha256'] ?? null) !== hash_file('sha256', (string) ($recovery['attempt_file'] ?? ''))
                        || (int) $connection->query("SELECT COUNT(*) FROM pa_auth_security_event WHERE request_id='development-sites-20261024'")->fetchColumn() !== 0) {
                        throw new RuntimeException('DEVELOPMENT_SITES_FAILED_RECOVERY_NOT_PROVEN');
                    }
                    $writeStage('failed_history_preserved', ['receipt_sha256' => $plan['rolled_back_failure_sha256'], 'failed_row' => $row]);
                    return true;
                });
            $stage = 'credentials_applied'; $writeStage($stage, $migration);
            $guard();
            $stage = 'business_applying'; $writeStage($stage, $migration);
            $sites = Db::transaction(function () use ($password, $guard, $snapshot): array {
                $guard();
                $app = $this->getApp();
                $actor = PlatformContext::fromTrustedAutomation(1, 1, 'development-maintenance', 'development-sites-20261024', new DateTimeImmutable('now', new DateTimeZone('UTC')));
                $access = $app->make(PlatformAccessAdminService::class);
                $operator = $access->createOperator($actor, 'platform@test.com', '平台管理员', $password);
                $roleIds = array_map(static fn(array $row): int => (int) $row['platform_role_id'], $snapshot['platform_role_bindings']);
                $access->replaceOperatorRoles($actor, (int) $operator['id'], $roleIds, (int) $operator['security_revision'], 'Provision the explicitly approved local development platform administrator.');
                $tenants = $app->make(PlatformTenantAdminService::class);
                $owners = $app->make(TenantOwnerAdminService::class);
                $provisioner = $app->make(TenantOwnerAdminProvisioner::class);
                $bindings = $app->make(TenantEntryBindingAdminService::class);
                $result = ['platform' => ['operator_id' => (int) $operator['id'], 'email' => 'platform@test.com'], 'public' => ['tenant_id' => 1, 'email' => 'admin@test.com']];
                foreach ([['tenant-a', '租户 A', 'admin-a@test.com', 'tenant-a-demo.peanut-admin.localhost'], ['tenant-b', '租户 B', 'admin-b@test.com', 'tenant-b.peanut-admin.localhost']] as [$code, $name, $email, $host]) {
                    $tenant = $tenants->createTenant($actor, $code, $name, $name, 'zh-CN', 'Asia/Shanghai');
                    $id = (int) $tenant['id'];
                    $candidate = $owners->createCandidate($actor, $id, $email, $name . ' 管理员', $password);
                    $member = $owners->activateCandidate($actor, $id, (int) $candidate['member']['id'], (int) $candidate['member']['revision'], 'development-' . $code, 'Provision the explicitly approved local development tenant administrator.');
                    $provisioner->provision($id, (int) $member['member']['account_id'], (int) $member['member']['id'], (int) $member['member']['role_id'], $code, $name . ' 管理员');
                    $tenants->transitionTenant($actor, $id, (int) $tenant['revision'], TenantStatus::Active, 'Activate the approved local development tenant.');
                    $bindings->enable($actor, $id, $host, 'admin-web', 'Bind the approved development tenant entry.');
                    $result[$code] = ['tenant_id' => $id, 'email' => $email, 'host' => $host];
                }
                $bindings->enable($actor, 1, 'public.peanut-admin.localhost', 'admin-web', 'Bind the existing default public tenant entry.');
                $guard();
                return $result;
            });
            $stage = 'completed'; $writeStage($stage, $sites); fclose($attempt); $attempt = null;
            $output->writeln(json_encode(['status' => 'applied', 'migration' => $migration, 'sites' => $sites, 'historical_operator_1_preserved' => true], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            return 0;
        } catch (\Throwable $error) {
            if (is_resource($attempt)) {
                try {
                    self::appendDurably($attempt, ['plan_sha256' => $planSha, 'attempted_stage' => $stage,
                        'last_durable_stage' => $lastDurableStage, 'status' => 'reconciliation_required', 'diagnostic' => $error->getMessage()]);
                } catch (\Throwable) { /* Earlier complete journal lines remain; report UNKNOWN, never continue. */ }
                fclose($attempt);
            }
            $output->writeln(json_encode(['status' => 'blocked', 'code' => 'DEVELOPMENT_SITES_FAILED', 'diagnostic_sha256' => hash('sha256', $error->getMessage()), 'origin' => basename($error->getFile()) . ':' . $error->getLine()], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    private static function privateFile(string $path): string
    {
        if (!is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) { throw new RuntimeException('DEVELOPMENT_SITES_PRIVATE_FILE_REQUIRED'); }
        return (string) file_get_contents($path);
    }

    /** Append-only: a short final line cannot erase the last complete, fsynced phase. */
    private static function appendDurably($handle, array $record): void
    {
        $bytes = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        for ($offset = 0, $length = strlen($bytes); $offset < $length;) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) { throw new RuntimeException('DEVELOPMENT_SITES_ATTEMPT_WRITE_FAILED'); }
            $offset += $written;
        }
        if (!fflush($handle) || !fsync($handle)) { throw new RuntimeException('DEVELOPMENT_SITES_ATTEMPT_SYNC_FAILED'); }
    }

    private static function snapshot(PDO $pdo): array
    {
        $account = $pdo->query('SELECT id,status,security_revision,locked_until FROM pa_account WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        $credential = $pdo->query('SELECT id,account_id,identifier_normalized,status,revision,kind,identifier_type,failed_attempts,locked_until,expires_at FROM pa_credential WHERE id=1')->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || !is_array($credential)) { throw new RuntimeException('DEVELOPMENT_SITES_TARGET_MISSING'); }
        foreach (['id', 'security_revision'] as $key) { $account[$key] = (int) $account[$key]; }
        foreach (['id', 'account_id', 'revision', 'failed_attempts'] as $key) { $credential[$key] = (int) $credential[$key]; }
        return ['server_uuid' => $pdo->query('SELECT @@server_uuid')->fetchColumn(), 'account' => $account, 'credential' => $credential,
            'tenants' => $pdo->query('SELECT id,code,status FROM pa_tenant ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'members' => $pdo->query('SELECT id,tenant_id,account_id,status FROM pa_tenant_member WHERE account_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'operators' => $pdo->query('SELECT id,account_id,status FROM pa_platform_operator ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'platform_role_bindings' => $pdo->query('SELECT id,platform_operator_id,platform_role_id FROM pa_platform_operator_role WHERE platform_operator_id=1 ORDER BY id')->fetchAll(PDO::FETCH_ASSOC),
            'member_role_bindings' => $pdo->query('SELECT binding.id,binding.tenant_id,binding.tenant_member_id,binding.role_id FROM pa_member_role binding JOIN pa_tenant_member member ON member.id=binding.tenant_member_id WHERE member.account_id=1 ORDER BY binding.id')->fetchAll(PDO::FETCH_ASSOC)];
    }
}
