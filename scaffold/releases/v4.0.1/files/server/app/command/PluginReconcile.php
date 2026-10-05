<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\common\execution\ModuleContextualCommand;
use PeanutAdmin\Modules\Identity\Contract\TenantModuleStateQueries;
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
        $rows = Db::name('plugin_installation')->where('status', '<>', 'uninstalled')
            ->field('plugin_key,status')->order('plugin_key')->select()->toArray();
        $pluginKeys = array_values(array_map(static fn(array $row): string => (string) $row['plugin_key'], $rows));
        $membersByPlugin = [];
        $moduleKeys = [];
        if ($pluginKeys !== []) {
            foreach (Db::name('plugin_module')->whereIn('plugin_key', $pluginKeys)
                ->field('plugin_key,module_key')->order('plugin_key')->order('module_key')->select()->toArray() as $member) {
                $pluginKey = (string) ($member['plugin_key'] ?? '');
                $moduleKey = (string) ($member['module_key'] ?? '');
                $membersByPlugin[$pluginKey][] = $moduleKey;
                $moduleKeys[$moduleKey] = true;
            }
        }
        $states = (new TenantModuleStateQueries())->installationStates(array_keys($moduleKeys));
        foreach ($rows as $row) {
            $key = (string) ($row['plugin_key'] ?? '');
            $memberKeys = $membersByPlugin[$key] ?? [];
            $members = count($memberKeys);
            if ((string) ($row['status'] ?? '') !== 'active' || $members < 1 || !isset($locked[$key])) {
                throw new PluginLifecycleException(
                    'PLUGIN_STATE_INVALID',
                    "Release reconciliation found an invalid or unlocked Plugin installation: {$key}",
                );
            }
            $active = 0;
            $disabled = 0;
            foreach ($memberKeys as $moduleKey) {
                $state = $states[$moduleKey] ?? null;
                if (($state['status'] ?? null) === 'active' && ($state['last_error_code'] ?? null) === null) {
                    ++$active;
                } elseif (($state['status'] ?? null) === 'maintenance' && ($state['last_error_code'] ?? null) === null) {
                    ++$disabled;
                }
            }
            if ($active === $members) {
                $keys[$key] = true;
                continue;
            }
            if ($disabled === $members) {
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
