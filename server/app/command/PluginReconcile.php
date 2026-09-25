<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use think\facade\Db;

/** Reconciles either the canonical official set or the full release replacement set. */
final class PluginReconcile extends ModuleContextualCommand
{
    use PluginCommandSupport;

    /** Declares mutually exclusive reconciliation scopes so deployments cannot select arbitrary Plugin keys. */
    protected function configure()
    {
        $this->setName('plugin:reconcile')
            ->setDescription('Reconcile immutable locked Plugin installations')
            ->addOption('official-locked', null, Option::VALUE_NONE, 'Reconcile every official.* Plugin in plugins.lock')
            ->addOption('release-locked', null, Option::VALUE_NONE, 'Reconcile official and already installed Plugins in plugins.lock');
    }

    /** Resolves the fixed lock scope and applies each eligible lifecycle operation. */
    protected function handle(Input $input, Output $output): int
    {
        $officialLocked = (bool) $input->getOption('official-locked');
        $releaseLocked = (bool) $input->getOption('release-locked');
        if (!$officialLocked && !$releaseLocked) {
            $output->writeln('{"error":"OFFICIAL_LOCKED_REQUIRED"}');
            return 1;
        }
        if ($officialLocked && $releaseLocked) {
            $output->writeln('{"error":"PLUGIN_RECONCILE_SCOPE_CONFLICT"}');
            return 1;
        }

        return $this->runPluginOperation(
            $output,
            function ($service) use ($releaseLocked): array {
                $config = Config::get('modules', []);
                $lockPath = is_array($config) ? trim((string) ($config['plugin_lock'] ?? '')) : '';
                if ($lockPath === '') {
                    throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Plugin lock path is not configured.');
                }
                $resolver = new PluginLockResolver(dirname(__DIR__, 2), $lockPath);
                $officialKeys = array_values(array_filter(
                    array_keys($resolver->all()),
                    static fn(string $key): bool => str_starts_with($key, 'official.'),
                ));
                $selection = $releaseLocked
                    ? $this->releasePluginSelection($resolver->all(), $officialKeys)
                    : ['reconcile' => $officialKeys, 'preserved_disabled' => []];
                $keys = $selection['reconcile'];
                sort($keys, SORT_STRING);
                if ($keys === [] && !$releaseLocked) {
                    throw new PluginLifecycleException(
                        'OFFICIAL_PLUGIN_SET_EMPTY',
                        'plugins.lock contains no Plugin selected for reconciliation.',
                    );
                }

                $results = [
                    'installed' => [],
                    'upgraded' => [],
                    'unchanged' => [],
                    'preserved_disabled' => array_map(
                        static fn(string $key): array => ['key' => $key, 'operation' => 'preserve-disabled'],
                        $selection['preserved_disabled'],
                    ),
                ];
                foreach ($keys as $key) {
                    $result = $service->reconcile($key);
                    $operation = (string) ($result['operation'] ?? '');
                    if (!array_key_exists($operation, $results)) {
                        throw new PluginLifecycleException(
                            'PLUGIN_RECONCILE_RESULT_INVALID',
                            "Plugin reconciliation returned an unsupported operation: {$operation}",
                        );
                    }
                    $results[$operation][] = $result;
                }

                return $results;
            },
        );
    }

    /**
     * Release replacement reconciles active packages, preserves fully disabled packages, and rejects transitions.
     *
     * @param array<string,\app\platform\value\plugin\PluginDescriptor> $locked
     * @param list<string> $officialKeys
     * @return array{reconcile:list<string>,preserved_disabled:list<string>}
     */
    private function releasePluginSelection(array $locked, array $officialKeys): array
    {
        $keys = array_fill_keys($officialKeys, true);
        $preserved = [];
        $rows = Db::name('plugin_installation')->alias('pi')
            ->leftJoin('plugin_module pm', 'pm.plugin_key=pi.plugin_key')
            ->leftJoin('module_installation mi', 'mi.module_key=pm.module_key')
            ->where('pi.status', '<>', 'uninstalled')->field('pi.plugin_key,pi.status')
            ->fieldRaw("COUNT(pm.module_key) member_count,SUM(CASE WHEN mi.status='active' AND mi.last_error_code IS NULL THEN 1 ELSE 0 END) active_count,SUM(CASE WHEN mi.status='maintenance' AND mi.last_error_code IS NULL THEN 1 ELSE 0 END) disabled_count")
            ->group('pi.plugin_key,pi.status')->order('pi.plugin_key')->select()->toArray();
        foreach ($rows as $row) {
            $key = (string) ($row['plugin_key'] ?? '');
            $members = (int) ($row['member_count'] ?? 0);
            if ((string) ($row['status'] ?? '') !== 'active' || $members < 1 || !isset($locked[$key])) {
                throw new PluginLifecycleException(
                    'PLUGIN_STATE_INVALID',
                    "Release reconciliation found an invalid or unlocked Plugin installation: {$key}",
                );
            }
            if ((int) ($row['active_count'] ?? 0) === $members) {
                $keys[$key] = true;
                continue;
            }
            if ((int) ($row['disabled_count'] ?? 0) === $members) {
                unset($keys[$key]);
                $preserved[] = $key;
                continue;
            }
            throw new PluginLifecycleException(
                'PLUGIN_STATE_INVALID',
                "Release reconciliation found mixed or transitional Module states: {$key}",
            );
        }
        sort($preserved, SORT_STRING);
        return ['reconcile' => array_keys($keys), 'preserved_disabled' => $preserved];
    }
}
