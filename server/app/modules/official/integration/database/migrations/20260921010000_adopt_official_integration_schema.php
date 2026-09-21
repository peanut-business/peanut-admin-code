<?php
declare(strict_types=1);

use app\modules\official\integration\database\Schema;
use think\facade\Db;
use think\migration\Migrator;

/**
 * Adopts the previously optional integration-security schema without replaying
 * or replacing the existing external channel binding table.
 */
final class AdoptOfficialIntegrationSchema extends Migrator
{
    public function up(): void
    {
        foreach (Schema::tableNames() as $table) {
            if (!$this->hasTable($table)) {
                Db::execute(Schema::createSql($table));
            }
        }
    }

    public function down(): void
    {
        // Forward-only: credentials and delivery evidence are retained on rollback.
    }
}
