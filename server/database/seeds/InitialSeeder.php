<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Identity\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Modules\Identity\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use Phinx\Db\Adapter\PdoAdapter;
use Phinx\Db\Adapter\WrapperInterface;
use think\facade\Db;
use think\migration\Seeder;

final class InitialSeeder extends Seeder
{
    public function run(): void
    {
        $adapter = $this->getAdapter();
        while ($adapter instanceof WrapperInterface) {
            $adapter = $adapter->getAdapter();
        }
        if (!$adapter instanceof PdoAdapter) {
            throw new RuntimeException('InitialSeeder requires the native PDO adapter.');
        }
        $pdo = $adapter->getConnection();
        $sql = file_get_contents(__DIR__ . '/../schema/baseline-data.sql');
        if (!is_string($sql) || $sql === '') {
            throw new RuntimeException('The initial data source is missing.');
        }
        $data = explode('-- peanut-baseline-catalog-boundary', $sql);
        if (count($data) !== 2) {
            throw new RuntimeException('The initial data catalog boundary is invalid.');
        }
        $committedInitialData = false;
        $emulated = (bool) $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
        try {
            $tenants = $this->fetchAll('SELECT id, code, status FROM pa_tenant');
            if (count($tenants) !== 1 || $tenants[0]['status'] !== 'active') {
                throw new RuntimeException('InitialSeeder requires one initialized active default tenant.');
            }
            $tenant = $tenants[0];
            $ownerStatement = $this->query(<<<'SQL'
SELECT COUNT(DISTINCT tm.id) AS count
FROM pa_tenant_member tm
JOIN pa_account a ON a.id = tm.account_id AND a.status = 'active'
JOIN pa_credential c ON c.account_id = a.id AND c.status = 'active'
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = ? AND tm.status = 'active' AND r.`key` = 'core.tenant-owner'
SQL, [(int) $tenant['id']]);
            $owners = $ownerStatement->fetch(PDO::FETCH_ASSOC);
            $ownerStatement->closeCursor();
            if ((int) $owners['count'] !== 1) {
                throw new RuntimeException('InitialSeeder requires the initialized default tenant owner.');
            }
            $state = $this->fetchRow(<<<'SQL'
SELECT (SELECT COUNT(*) FROM pa_system_dict_type)
     + (SELECT COUNT(*) FROM pa_system_menu)
     + (SELECT COUNT(*) FROM pa_config)
     + (SELECT COUNT(*) FROM pa_tenant_setting)
     + (SELECT COUNT(*) FROM pa_storage_account) AS count
SQL);
            if ((int) $state['count'] !== 0) {
                throw new RuntimeException('INITIAL_SEED_NONEMPTY: initial or partial seed data already exists; refusing to replay.');
            }
            $frameworkConnection = Db::connect();
            $frameworkConnection->query('SELECT 1', [], true);
            $frameworkPdo = $frameworkConnection->getPdo();
            $database = $pdo->query("SELECT CONCAT(@@server_uuid, ':', DATABASE())")->fetchColumn();
            if ($frameworkPdo->query("SELECT CONCAT(@@server_uuid, ':', DATABASE())")->fetchColumn() !== $database) {
                throw new RuntimeException('The permission catalog and native seeder target different databases.');
            }
            if ($frameworkPdo->inTransaction()) {
                throw new RuntimeException('The permission catalog connection has an active transaction.');
            }
            // seed:run owns the native transaction. Session variables belong to this connection only.
            $this->execute('SET @pa_default_tenant_id = ?, @pa_default_tenant_code = ?', [(int) $tenant['id'], $tenant['code']]);
            $this->executeData($data[0]);
            $adapter->commitTransaction();
            $committedInitialData = true;

            // The repository uses ThinkPHP's separate PDO. Release native seed locks before synchronization.
            (new CorePermissionCatalogSynchronizer(new ThinkPhpAuthorizationCatalogRepository()))->synchronize();

            $adapter->beginTransaction();
            $this->executeData($data[1]);
            $temporaryMenus = $this->fetchRow("SELECT COUNT(*) AS count FROM pa_system_menu WHERE menu_key LIKE 'seed.%'");
            if ((int) $temporaryMenus['count'] !== 0) {
                throw new RuntimeException('Initial menu identities did not converge.');
            }
            // The official seed runner commits the final native transaction.
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $adapter->rollbackTransaction();
            }
            if ($committedInitialData) {
                throw new RuntimeException('INITIAL_SEED_PARTIAL: initial data was committed before catalog/versioned data failed; replay is refused.', 0, $exception);
            }
            throw $exception;
        } finally {
            try {
                $this->execute('SET @pa_default_tenant_id = NULL, @pa_default_tenant_code = NULL');
            } finally {
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulated);
            }
        }
    }

    private function executeData(string $sql): void
    {
        // Use the official query API for the SQL batch, draining result sets without parsing SQL.
        $statement = $this->query($sql);
        try {
            do {
                if ($statement->columnCount() > 0) {
                    $statement->fetchAll(PDO::FETCH_ASSOC);
                }
            } while ($statement->nextRowset());
        } finally {
            $statement->closeCursor();
        }
    }
}
