<?php

declare(strict_types=1);

use Phinx\Migration\IrreversibleMigrationException;
use think\migration\Migrator;

/** Removes the retired order-policy settings after their consumers have been removed. */
final class RemoveRetiredTransactionSetting extends Migrator
{
    public function up(): void
    {
        if ($this->getAdapter()->hasTable('pa_transaction_setting')) {
            $this->execute('DROP TABLE pa_transaction_setting');
        }
    }

    public function down(): void
    {
        throw new IrreversibleMigrationException('The retired transaction settings have no active consumer. Restore the paired backup to recover their former values.');
    }
}
