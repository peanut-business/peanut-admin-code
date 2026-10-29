<?php

declare(strict_types=1);

namespace app\common\module;

use app\platform\exception\plugin\PluginLifecycleException;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use Phinx\Util\Util;
use think\migration\command\migrate\Run;

/** Adapts source discovery only; execution, ordering and the ledger remain native. */
final class ModuleMigrationRun extends Run
{
    private string $directory = '';

    /** @param list<array<string,mixed>> $sources */
    public function __construct(private readonly array $sources)
    {
        parent::__construct();
    }

    /**
     * Does not include PHP or access the database. A null owner denotes the Host.
     *
     * @param array<string,ManifestDocument> $manifests
     * @return list<array{migration_key:string,name:string,sha256:string,file:string,module_key:?string,declared_tables:array,author_description:?string,change_description:?string}>
     */
    public static function sources(array $manifests, string $serverRoot, bool $includeHost = false): array
    {
        $directories = [];
        if ($includeHost) {
            $directories[] = [$serverRoot . '/database/migrations', null, [], null, $serverRoot];
        }
        foreach ($manifests as $key => $manifest) {
            $path = $manifest->data['backend']['migrations'] ?? null;
            if ($path === null) {
                continue;
            }
            if ($path !== 'database/migrations') {
                throw new PluginLifecycleException('MODULE_MIGRATION_PATH_INVALID', "Noncanonical migration path: {$key}");
            }
            $directories[] = [$manifest->root . '/' . $path, $key,
                $manifest->data['database']['owned_tables'] ?? [], $manifest->data['description'] ?? null, $manifest->root];
        }
        $sources = [];
        $classes = [];
        foreach ($directories as [$path, $owner, $tables, $description, $boundary]) {
            $directory = realpath($path);
            $root = realpath($boundary);
            if ($directory === false || $root === false || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_PATH_INVALID', "Migration directory is missing or escapes its owner: {$path}");
            }
            foreach (glob($directory . '/*.php') ?: [] as $file) {
                $name = basename($file);
                if (is_link($file) || preg_match('/^[0-9]{14}_[a-z][a-z0-9_]*\.php$/D', $name) !== 1
                    || !Util::isValidMigrationFileName($name)) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_FILE_INVALID', "Invalid migration source: {$file}");
                }
                $version = (string) Util::getVersionFromFileName($name);
                $class = Util::mapFileNameToClassName($name);
                $classKey = strtolower($class);
                if (strlen($class) > 100 || isset($sources[$version]) || isset($classes[$classKey])) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_IDENTITY_CONFLICT', "Duplicate migration version or class: {$file}");
                }
                $source = file_get_contents($file);
                if (!is_string($source)) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_FILE_INVALID', "Unreadable migration: {$file}");
                }
                $note = null;
                $declaredClass = null;
                $namespace = false;
                $tokens = token_get_all($source);
                foreach ($tokens as $index => $token) {
                    if (!is_array($token)) {
                        continue;
                    }
                    if ($token[0] === T_NAMESPACE) {
                        $namespace = true;
                    }
                    if ($token[0] === T_DOC_COMMENT) {
                        $note = $token[1];
                    }
                    if ($token[0] === T_CLASS) {
                        for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                            if (is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
                                continue;
                            }
                            if (is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                                $declaredClass = $tokens[$next][1];
                            }
                            break;
                        }
                        break;
                    }
                }
                if ($namespace || $declaredClass !== $class) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_FILE_INVALID', "Migration must declare its native global class {$class}: {$file}");
                }
                $sources[$version] = ['migration_key' => $version, 'name' => $class, 'sha256' => hash('sha256', $source),
                    'file' => $file, 'module_key' => $owner, 'declared_tables' => $tables,
                    'author_description' => $description, 'change_description' => $note];
                $classes[$classKey] = true;
            }
        }
        ksort($sources, SORT_NUMERIC);
        return array_values($sources);
    }

    protected function getPath()
    {
        return $this->directory;
    }

    protected function getMigrations()
    {
        $directories = [];
        foreach ($this->sources as $source) {
            if (!hash_equals($source['sha256'], (string) hash_file('sha256', $source['file']))) {
                throw new PluginLifecycleException('MODULE_MIGRATION_SOURCE_CHANGED', 'Migration source changed after planning.');
            }
            $directories[dirname($source['file'])][] = $source;
        }
        $selected = [];
        foreach ($directories as $directory => $sources) {
            $this->directory = $directory;
            $this->migrations = null;
            $native = parent::getMigrations();
            if (count($native) !== count($sources)) {
                throw new PluginLifecycleException('MODULE_MIGRATION_SOURCE_CHANGED', 'Migration directory changed after planning.');
            }
            foreach ($sources as $source) {
                $version = $source['migration_key'];
                $migration = $native[$version] ?? null;
                if ($migration === null || (new \ReflectionClass($migration))->getFileName() !== $source['file']) {
                    throw new PluginLifecycleException('MODULE_MIGRATION_IDENTITY_CONFLICT', 'Loaded migration differs from its selected source.');
                }
                $selected[$version] = $migration;
            }
        }
        ksort($selected, SORT_NUMERIC);
        return $this->migrations = $selected;
    }
}
