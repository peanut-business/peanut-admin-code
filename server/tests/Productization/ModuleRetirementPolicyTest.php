<?php

declare(strict_types=1);

use app\platform\exception\module\ModuleLifecycleException;
use app\platform\infrastructure\module\ModuleArtifactWriter;
use app\platform\infrastructure\module\ModuleLockResolver;
use app\platform\policy\module\ModuleLifecyclePolicy;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/Support/ThinkPhpTestConnection.php';

$assertions = 0;
function retirementExpect(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) { throw new RuntimeException($message); }
}
function retirementCopy(string $source, string $target, array $replace): void
{
    if (!is_dir($target)) { mkdir($target, 0700, true); }
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || in_array($entry->getFilename(), ['node_modules', 'vendor', '.git'], true)) { continue; }
        if ($entry->isLink()) { throw new RuntimeException('TEST_SOURCE_SYMLINK'); }
        $file = $target . '/' . $entry->getFilename();
        if ($entry->isDir()) { retirementCopy($entry->getPathname(), $file, $replace); }
        else { file_put_contents($file, str_replace(array_keys($replace), array_values($replace), (string) file_get_contents($entry->getPathname()))); }
    }
}
function retirementRemove(string $root): void
{
    if (!is_dir($root)) { return; }
    foreach (new DirectoryIterator($root) as $entry) {
        if ($entry->isDot()) { continue; }
        $entry->isDir() && !$entry->isLink() ? retirementRemove($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
$server = dirname(__DIR__, 2);
$root = rtrim(sys_get_temp_dir(), '/') . '/pa-module-retirement-policy-' . bin2hex(random_bytes(8));
mkdir($root, 0700, true);
try {
    $fromNamespace = implode(chr(92), ['PeanutAdmin', 'Fixtures', 'DeliveryRecord']);
    foreach (['base', 'child'] as $name) {
        $namespace = implode(chr(92), ['Acme', 'Modules', ucfirst($name)]);
        $target = $root . '/server/app/modules/acme/' . $name;
        retirementCopy($server . '/app/modules/fixture/delivery_record', $target, [
            'fixture.delivery-record' => 'acme.' . $name,
            'peanut-fixture/delivery-record' => 'acme/' . $name,
            'fixture_delivery_record' => 'acme_' . $name,
            trim(json_encode($fromNamespace), '"') => trim(json_encode($namespace), '"'),
            $fromNamespace => $namespace,
        ]);
        $manifest = json_decode((string) file_get_contents($target . '/module.json'), true, 128, JSON_THROW_ON_ERROR);
        $manifest['frontend'] = new stdClass();
        unset($manifest['web_package']);
        $manifest['dependencies'] = $name === 'child' ? [['module_key' => 'acme.base', 'version' => '^1.0.0']] : [];
        file_put_contents($target . '/module.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    }
    mkdir($root . '/server/resources/schemas', 0700, true);
    copy($server . '/resources/schemas/module-artifact.schema.json', $root . '/server/resources/schemas/module-artifact.schema.json');
    $writer = new ModuleArtifactWriter($root . '/server');
    foreach (['base', 'child'] as $name) { $writer->make('acme.' . $name, '1.0.0', ['acme.' . $name . '=server/app/modules/acme/' . $name]); }
    $writer->writeLock([]);
    $resolver = new ModuleLockResolver($root . '/server', '../modules.lock');
    $app = new think\App($root . '/server');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE pa_module_installation(module_key TEXT PRIMARY KEY,status TEXT); CREATE TABLE pa_plan_module(plan_id INTEGER,module_key TEXT); CREATE TABLE pa_business_probe(id INTEGER,value TEXT); CREATE TABLE pa_migrations(version TEXT); INSERT INTO pa_business_probe VALUES (1,'retained'); INSERT INTO pa_migrations VALUES ('20261103010001'); INSERT INTO pa_module_installation VALUES ('acme.base','active'),('acme.child','active');");
    $connection = ThinkPhpTestConnection::fromPdo($pdo);
    $states = new TenantModuleStateQueries();
    retirementExpect(ModuleLifecyclePolicy::activeBusinessDependents($resolver, ['acme.base']) === ['acme.child->acme.base'], 'Active dependent was not reported');
    try { ModuleLifecyclePolicy::assertNoActiveBusinessDependents($resolver, ['acme.base']); throw new RuntimeException('Active dependency removal was admitted'); }
    catch (ModuleLifecycleException $exception) { retirementExpect($exception->errorCode === 'MODULE_DEPENDENT_INSTALLED', 'Unexpected dependency failure'); }
    $pdo->exec("UPDATE pa_module_installation SET status='maintenance' WHERE module_key='acme.child'");
    retirementExpect(ModuleLifecyclePolicy::activeBusinessDependents($resolver, ['acme.base']) === [], 'Disabled consumer remained active');
    retirementExpect(ModuleLifecyclePolicy::installedBusinessDependents($resolver, ['acme.base'], $states) === ['acme.child->acme.base'], 'Disabled installation must still protect required source/data');
    $pdo->exec("UPDATE pa_module_installation SET status='failed' WHERE module_key='acme.child'");
    retirementExpect(ModuleLifecyclePolicy::installedBusinessDependents($resolver, ['acme.base'], $states) === ['acme.child->acme.base'], 'Failed installation lost its dependency protection');
    $pdo->exec("UPDATE pa_module_installation SET status='uninstalled' WHERE module_key='acme.child'");
    retirementExpect(ModuleLifecyclePolicy::installedBusinessDependents($resolver, ['acme.base'], $states) === [], 'Retired history still acts as an installed dependent');
    $pdo->exec("INSERT INTO pa_plan_module VALUES (7,'acme.base')");
    retirementExpect($states->hasEnabledModules(['acme.base']), 'Existing plan reference was ignored');
    retirementExpect($states->enabledReferencesForUpdate(['acme.base']) === ['acme.base'], 'Native plan reference projection changed');
    $pdo->exec('DELETE FROM pa_plan_module');
    retirementExpect(!$states->hasEnabledModules(['acme.base']), 'Removed plan reference still prevents retirement');
    $protected = ManifestDocument::fromObject($root, (object) ['key' => 'acme.base', 'lifecycle' => (object) ['protected' => true]]);
    try { ModuleLifecyclePolicy::assertMutable(['acme.base' => $protected]); throw new RuntimeException('Protected Module admitted for retirement'); }
    catch (ModuleLifecycleException $exception) { retirementExpect($exception->errorCode === 'MODULE_LIFECYCLE_PROTECTED', 'Unexpected protected Module rejection'); }
    retirementExpect($pdo->query('SELECT value FROM pa_business_probe WHERE id=1')->fetchColumn() === 'retained', 'Policy modified business data');
    retirementExpect($pdo->query('SELECT version FROM pa_migrations')->fetchColumn() === '20261103010001', 'Policy modified migration history');
    $connection->free(); // SQLite requires the last native query cursor to be released before DDL.
    $pdo->exec('DROP TABLE pa_plan_module');
    $failed = false;
    try { $states->hasEnabledModules(['acme.base']); } catch (Throwable) { $failed = true; }
    retirementExpect($failed, 'A missing native plan ledger was silently accepted');
    echo "MODULE-RETIREMENT-POLICY-001 passed assertions={$assertions}; native ThinkPHP/SQLite policy, not MySQL uninstall execution\n";
} finally {
    retirementRemove($root);
}
