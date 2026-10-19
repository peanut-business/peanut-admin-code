<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;

/** Native selected-table mysqldump adapter for the fixed source application chain. */
final class SourceApplicationBackup
{
    private const TABLES = [
        '20260909-notification-sms-reservation' => ['pa_notice_log'],
        '20261004-group-default-navigation' => ['pa_system_menu', 'pa_menu_definition'],
        '20261004-menu-stable-identities' => ['pa_system_menu', 'pa_menu_definition'],
        '20261007-auth-client-schema' => ['pa_tenant_session', 'pa_auth_security_event'],
        '20261007-readiness-permission' => ['pa_permission'],
        '20261012-align-department-canonical-permissions' => ['pa_permission', 'pa_role_permission', 'pa_role', 'pa_tenant', 'pa_system_menu'],
        '20261012-align-platform-ops-permission-ownership' => ['pa_permission'],
        '20261012-repair-seeded-permission-parents' => ['pa_system_menu'],
        '20261016-module-migration-provenance' => ['pa_module_migration'],
    ];

    /** Schema observations, never a backup receipt or implicit restore admission. */
    public static function scope(PDO $pdo, array $pending): array
    {
        $tables = $pending === [] ? [] : ['pa_schema_migration'];
        foreach ($pending as $id) {
            if (!isset(self::TABLES[$id])) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SOURCE_UNSUPPORTED'); }
            array_push($tables, ...self::TABLES[$id]);
        }
        $tables = array_values(array_unique($tables)); sort($tables, SORT_STRING);
        $objects = [];
        foreach ($tables as $table) {
            $query = $pdo->prepare('SELECT TABLE_TYPE,ENGINE,AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $query->execute([$table]); $rows = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            if (count($rows) !== 1 || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB') {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TABLE_UNSUPPORTED');
            }
            $query = $pdo->query('SHOW CREATE TABLE `' . $table . '`');
            $ddl = $query->fetch(PDO::FETCH_NUM); $query->closeCursor();
            if (!is_array($ddl) || !is_string($ddl[1] ?? null)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_DDL_UNAVAILABLE'); }
            $objects[$table] = ['engine' => 'InnoDB', 'create_sql_sha256' => hash('sha256', $ddl[1]),
                'auto_increment' => $rows[0]['AUTO_INCREMENT'] === null ? null : (string) $rows[0]['AUTO_INCREMENT']];
        }
        $foreignKeys = []; $triggers = [];
        if ($tables !== []) {
            $marks = implode(',', array_fill(0, count($tables), '?'));
            $query = $pdo->prepare('SELECT k.CONSTRAINT_SCHEMA,k.CONSTRAINT_NAME,k.TABLE_SCHEMA,k.TABLE_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,'
                . 'k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE'
                . ' FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r'
                . ' ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME'
                . " WHERE (k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN ($marks))"
                . " OR (k.REFERENCED_TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IN ($marks))"
                . ' ORDER BY k.CONSTRAINT_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
            $query->execute([...$tables, ...$tables]); $foreignKeys = $query->fetchAll(PDO::FETCH_ASSOC); $query->closeCursor();
            $query = $pdo->prepare('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT,SQL_MODE,DEFINER'
                . " FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE IN ($marks) ORDER BY EVENT_OBJECT_TABLE,TRIGGER_NAME");
            $query->execute($tables);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $triggers[] = ['table' => $row['EVENT_OBJECT_TABLE'], 'name' => $row['TRIGGER_NAME'],
                    'event' => $row['EVENT_MANIPULATION'], 'timing' => $row['ACTION_TIMING'],
                    'definition_sha256' => hash('sha256', serialize($row))];
            }
            $query->closeCursor();
        }
        return ['tables' => $tables, 'objects' => $objects, 'foreign_keys' => $foreignKeys, 'triggers' => $triggers,
            'metadata_visibility' => 'current-account-visible; backup client visibility checked separately',
            'coverage_required' => ['complete_table_ddl', 'all_table_rows', 'indexes', 'checks', 'foreign_keys', 'triggers', 'auto_increment'],
            'external_dependencies' => 'referenced objects are not automatically added to restore writes; review FK/trigger effects before restoration',
            'backup_status' => 'not_created', 'restore_status' => 'not_executed', 'execution_admitted' => false];
    }

    /** Calls only fixed probe/dump programs; emits no database writes or restore command execution. */
    public static function capture(array $binding, array $baseline, string $baselineSha, array $scope, array $tool, string $directory, callable $recheck): array
    {
        $database = $binding['database']['database'];
        $contract = $tool['source_schema_backup'] ?? null;
        if (($tool['transport'] ?? null) !== 'ssh-docker-exec' || !is_array($contract)
            || ($contract['database_resource_id'] ?? null) !== $binding['resource_id'] || ($contract['database'] ?? null) !== $database
            || !is_array($contract['tables'] ?? null) || array_diff($scope['tables'], $contract['tables']) !== []
            || $scope['tables'] === [] || preg_match('/\A[A-Za-z0-9_]+\z/D', $database) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $baselineSha) !== 1) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_SCOPE_REQUIRED');
        }
        foreach (['ssh_command', 'docker_command', 'mysql_command', 'mysqldump_command', 'timeout_command', 'digest_command'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('~\A/[A-Za-z0-9_./-]+\z~D', $tool[$key]) !== 1 || str_contains($tool[$key], '..')) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        foreach (['ssh_alias', 'container_name'] as $key) {
            if (!is_string($tool[$key] ?? null) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]*\z/D', $tool[$key]) !== 1) {
                throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_TOOL_INVALID');
            }
        }
        $seconds = $contract['timeout_seconds'] ?? null;
        if (!is_int($seconds) || $seconds < 30 || $seconds > 600 || time() + $seconds + 60 >= (int) $binding['authority']['expires_at']) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED');
        }
        $parent = @lstat(dirname($directory));
        if (!str_starts_with($directory, '/') || realpath(dirname($directory)) !== dirname($directory)
            || !is_array($parent) || ($parent['mode'] & 0022) !== 0 || !in_array($parent['uid'], [0, posix_geteuid()], true)
            || file_exists($directory) || is_link($directory) || !mkdir($directory, 0700) || !chmod($directory, 0700)) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE');
        }
        $recheck();
        $before = self::probe($tool, $binding, $directory . '/probe-before', 'probe-before', $seconds, $recheck);
        $dump = $directory . '/database.sql';
        $flags = ['--no-defaults', '--no-login-paths', '--single-transaction', '--skip-lock-tables', '--quick', '--hex-blob',
            '--triggers', '--skip-routines', '--skip-events', '--no-tablespaces', '--set-gtid-purged=OFF', '--column-statistics=0',
            '--order-by-primary', '--skip-comments', '--skip-dump-date', '--skip-extended-insert', '--skip-add-locks', '--skip-disable-keys',
            '--default-character-set=utf8mb4', '-uroot', '--', $database, ...$scope['tables']];
        self::remote($tool, self::clientShell($tool, 'mysqldump_command', $seconds, $flags), $dump, $directory . '/dump.stderr',
            'dump', $seconds, (int) $binding['authority']['expires_at'], $recheck);
        if (filesize($dump) === 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_EMPTY'); }
        $after = self::probe($tool, $binding, $directory . '/probe-after', 'probe-after', $seconds, $recheck);
        $recheck();
        if ($before !== $after) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_REMOTE_CHANGED'); }
        $restore = ['protocol' => 'peanut.source-application-restore-instructions.v1', 'source' => $binding['source'],
            'database' => $binding['database'], 'tables' => $scope['tables'], 'native_client' => $before,
            'payload' => ['path' => $dump, 'sha256' => hash_file('sha256', $dump), 'bytes' => filesize($dump)],
            'argv' => self::argv($tool, self::clientShell($tool, 'mysql_command', $seconds,
                ['--no-defaults', '--no-login-paths', '--binary-mode', '--default-character-set=utf8mb4', '-uroot', '--database=' . $database])),
            'stdin' => $dump, 'executed' => false,
            'requirements' => ['Explicit authorization and normal native DDL/exclusive lease for these exact objects; no automatic restore.',
                'Preserve failure/unknown evidence before restoring original ledgers. Exclude affected writers and verify original DB/source identity.',
                'Inspect all recorded inbound/outbound FKs and triggers; resolve outside-object effects before any restore, without expanding scope implicitly.',
                'Restore only the selected tables. Database/accounts/storage/other tables are outside this payload; never DROP DATABASE.',
                'The native dump includes selected-table DROP/CREATE/data/trigger statements. After explicit restore, verify original structure/data and outside dependencies; not only row counts.',
                'Any interrupted/unknown restore requires reconciliation by its original owner; never automatically replay.']];
        self::write($directory . '/RESTORE.json', $restore);
        $receipts = [];
        foreach (['probe-before' => 'probe-before.txt', 'dump' => 'database.sql', 'probe-after' => 'probe-after.txt'] as $phase => $file) {
            $receipts[] = ['phase' => $phase, 'path' => $directory . '/' . $file . '.process.json',
                'sha256' => hash_file('sha256', $directory . '/' . $file . '.process.json')];
        }
        $record = ['protocol' => 'peanut.source-application-backup.v1', 'status' => 'captured', 'binding' => $binding,
            'baseline_sha256' => $baselineSha, 'pending' => $baseline['pending'],
            'selected' => array_intersect_key($baseline['selected'], array_flip($baseline['pending'])), 'scope' => $scope,
            'backup_payload' => $restore['payload'], 'restore_instructions' => ['path' => $directory . '/RESTORE.json', 'sha256' => hash_file('sha256', $directory . '/RESTORE.json')],
            'native_client' => $before, 'tooling_resource_id' => $tool['stable_resource_id'], 'adapter_sha256' => hash_file('sha256', __FILE__),
            'receipts' => $receipts,
            'observed_at' => time(), 'restore_status' => 'not_executed', 'qualification' => 'not_run', 'execution_admitted' => false];
        self::write($directory . '/backup.json', $record);
        $recovery = ['schema_version' => 1, 'protocol' => 'peanut.source-development-recovery.v1',
            'source' => $binding['source'], 'database' => $binding['database'], 'authority' => $binding['authority'],
            'registry_sha256' => $binding['registry_sha256'], 'source_database' => $binding['database'],
            'migration_sha256' => count($baseline['pending']) === 1 ? $baseline['selected'][$baseline['pending'][0]] : null,
            'observed_at' => $record['observed_at'], 'expires_at' => (int) $binding['authority']['expires_at'],
            'scope' => $scope['tables'], 'baseline_sha256' => $baselineSha,
            'backup_manifest' => ['path' => $directory . '/backup.json', 'sha256' => hash_file('sha256', $directory . '/backup.json')],
            'backup_payload' => $restore['payload'], 'restore_tool' => ['path' => __FILE__, 'sha256' => hash_file('sha256', __FILE__)],
            'restore_contract_sha256' => hash_file('sha256', $directory . '/RESTORE.json'),
            'receipts' => $receipts];
        self::write($directory . '/recovery.json', $recovery);
        return ['status' => 'captured', 'path' => $directory . '/backup.json', 'sha256' => hash_file('sha256', $directory . '/backup.json'),
            'recovery_references' => ['path' => $directory . '/recovery.json', 'sha256' => hash_file('sha256', $directory . '/recovery.json')],
            'backup_payload_sha256' => $restore['payload']['sha256'], 'tables' => $scope['tables'],
            'restore' => 'not_executed', 'execution_admitted' => false, 'qualification' => 'not_run'];
    }

    private static function probe(array $tool, array $binding, string $prefix, string $phase, int $seconds, callable $recheck): array
    {
        $sql = "SELECT JSON_OBJECT('server_uuid',@@server_uuid,'server_hostname',@@hostname,'server_port',@@port,'database',DATABASE(),'version',VERSION()); SHOW GRANTS;";
        $shell = self::quote([$tool['digest_command'], $tool['mysql_command'], $tool['mysqldump_command']]) . ' && '
            . self::quote([$tool['mysql_command'], '--version']) . ' && ' . self::quote([$tool['mysqldump_command'], '--version']) . ' && '
            . '(' . self::clientShell($tool, 'mysql_command', $seconds, ['--no-defaults', '--no-login-paths', '--batch', '--raw', '--skip-column-names', '-uroot', '--execute=' . $sql, '--database=' . $binding['database']['database']]) . ')';
        self::remote($tool, $shell, $prefix . '.txt', $prefix . '.stderr', $phase, $seconds, (int) $binding['authority']['expires_at'], $recheck);
        if (filesize($prefix . '.txt') > 16384) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROBE_INVALID'); }
        $lines = preg_split('/\r?\n/', trim((string) file_get_contents($prefix . '.txt')));
        if (count($lines) < 6 || preg_match('/\A([a-f0-9]{64})\s+/', $lines[0], $mysql) !== 1
            || preg_match('/\A([a-f0-9]{64})\s+/', $lines[1], $dump) !== 1) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROBE_INVALID'); }
        $identity = json_decode($lines[4], true, 32, JSON_THROW_ON_ERROR);
        foreach (['server_uuid', 'server_hostname', 'database'] as $key) {
            if (($identity[$key] ?? null) !== $binding['database'][$key]) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_REMOTE_MISMATCH'); }
        }
        $triggerVisible = false; $selectedVisible = false;
        foreach (array_slice($lines, 5) as $grant) {
            if (str_starts_with($grant, 'REVOKE ')) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_CLIENT_VISIBILITY_REQUIRED'); }
            if (preg_match('/\AGRANT (.+) ON (.+) TO /D', $grant, $match) !== 1
                || !in_array($match[2], ['*.*', '`' . $binding['database']['database'] . '`.*'], true)) { continue; }
            $privileges = array_map('trim', explode(',', $match[1]));
            $triggerVisible = $triggerVisible || in_array('TRIGGER', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
            $selectedVisible = $selectedVisible || in_array('SELECT', $privileges, true) || in_array('ALL PRIVILEGES', $privileges, true);
        }
        if ((int) ($identity['server_port'] ?? 0) !== $binding['database']['server_port'] || !$triggerVisible || !$selectedVisible
            || !is_string($identity['version'] ?? null) || preg_match('/\A8\.4\.[0-9]+\z/D', $identity['version']) !== 1
            || ($tool['source_schema_backup']['mysql_version'] ?? null) !== $identity['version']
            || !str_contains($lines[2], 'Ver ' . $identity['version'] . ' ') || !str_contains($lines[3], 'Ver ' . $identity['version'] . ' ')) {
            throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_CLIENT_UNSUPPORTED');
        }
        return ['identity' => $identity, 'mysql_sha256' => $mysql[1], 'mysqldump_sha256' => $dump[1],
            'client_visibility' => 'direct whole-schema SELECT and TRIGGER; no observed partial revoke'];
    }

    private static function clientShell(array $tool, string $client, int $seconds, array $arguments): string
    {
        return 'MYSQL_PWD=${MYSQL_ROOT_PASSWORD:?}; export MYSQL_PWD; exec '
            . self::quote([$tool['timeout_command'], '-k', '5s', $seconds . 's', $tool[$client], ...$arguments]);
    }

    private static function quote(array $arguments): string { return implode(' ', array_map('escapeshellarg', $arguments)); }

    private static function argv(array $tool, string $shell): array
    {
        $remote = self::quote([$tool['docker_command'], 'exec', '-i', $tool['container_name'], $tool['timeout_command'], '-k', '5s',
            $tool['source_schema_backup']['timeout_seconds'] . 's', '/bin/sh', '-c', $shell]);
        return [$tool['ssh_command'], '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', 'ConnectTimeout=5',
            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=3', $tool['ssh_alias'], $remote];
    }

    private static function remote(array $tool, string $shell, string $output, string $error, string $phase, int $seconds, int $expires, callable $recheck): void
    {
        $recheck();
        if (time() + $seconds + 60 >= $expires) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_LIFETIME_REQUIRED'); }
        // Bound SSH itself, including a stalled Docker daemon/exec startup.
        // The remote timeout cannot cover that transport phase.
        $deadline = hrtime(true) + min($seconds + 15, $expires - time() - 10) * 1000000000;
        $out = fopen($output, 'x+b'); $err = fopen($error, 'x+b');
        if ($out === false || $err === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        try {
            if (!chmod($output, 0600) || !chmod($error, 0600)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
            $environment = ['PATH' => '/usr/bin:/bin:/usr/sbin:/sbin', 'LANG' => 'C', 'LC_ALL' => 'C'];
            foreach (['HOME', 'SSH_AUTH_SOCK'] as $name) { if (is_string(getenv($name))) { $environment[$name] = getenv($name); } }
            $process = proc_open(self::argv($tool, $shell), [0 => ['file', '/dev/null', 'r'], 1 => $out, 2 => $err], $pipes, null, $environment);
            if (!is_resource($process)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_UNAVAILABLE'); }
            $timedOut = false;
            while (true) {
                $state = proc_get_status($process);
                if (!$state['running']) { break; }
                if (hrtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process);
                    foreach ([15, 9] as $signal) {
                        if ($signal === 9) { proc_terminate($process, 9); }
                        $grace = hrtime(true) + 2000000000;
                        do { usleep(50000); $state = proc_get_status($process); }
                        while ($state['running'] && hrtime(true) < $grace);
                        if (!$state['running']) { break; }
                    }
                    break;
                }
                usleep(50000);
            }
            $terminal = !$state['running'];
            $closed = $terminal ? proc_close($process) : null;
            $rc = $terminal && $state['exitcode'] >= 0 ? $state['exitcode'] : ($closed !== null && $closed >= 0 ? $closed : null);
            if (!fflush($out) || !fsync($out) || !fflush($err) || !fsync($err)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
            self::write($output . '.process.json', ['phase' => $phase, 'local_process_terminal' => $terminal, 'rc' => $rc,
                'local_deadline_exceeded' => $timedOut, 'remote_success_terminal_confirmed' => !$timedOut && $terminal && $rc === 0,
                'stdout' => ['path' => $output, 'sha256' => hash_file('sha256', $output), 'bytes' => filesize($output)],
                'stderr_sha256' => hash_file('sha256', $error),
                'note' => 'Nonzero/transport failures preserve partial output; no success record or automatic retry.']);
            if ($timedOut || !$terminal || $rc !== 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_PROCESS_FAILED'); }
            $recheck();
        } finally { fclose($out); fclose($err); }
    }

    private static function write(string $path, array $value): void
    {
        $stream = fopen($path, 'x+b');
        if ($stream === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
        try {
            if (!chmod($path, 0600)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_OUTPUT_UNSAFE'); }
            $data = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $offset = 0;
            while ($offset < strlen($data)) {
                $count = fwrite($stream, substr($data, $offset));
                if ($count === false || $count === 0) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_WRITE_FAILED'); }
                $offset += $count;
            }
            if (!fflush($stream) || !fsync($stream)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
        } finally { fclose($stream); }
        $directory = fopen(dirname($path), 'r');
        if ($directory === false) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); }
        try { if (!fsync($directory)) { throw new RuntimeException('APPLICATION_SCHEMA_BACKUP_SYNC_FAILED'); } }
        finally { fclose($directory); }
    }
}
