<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Schema;

use PDO;
use RuntimeException;

final readonly class DataPermissionMigrationRunner
{
    public function __construct(
        private string $database,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {}

    public function migrate(): void
    {
        $connection = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->database),
            $this->username,
            $this->password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $directory = dirname(__DIR__, 6) . '/app/modules/official/identity/database/migrations';
        $files = glob($directory . '/*create_pa_data_permission_*.sql') ?: [];
        sort($files, SORT_STRING);
        if ($files === []) {
            throw new RuntimeException('The locked Identity Module data permission migrations are unavailable.');
        }

        foreach ($files as $file) {
            $sql = file_get_contents($file);
            if (!is_string($sql) || trim($sql) === '') {
                throw new RuntimeException('The locked Identity Module data permission migration is unreadable.');
            }
            $connection->exec($sql);
        }
    }
}
