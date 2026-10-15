<?php

declare(strict_types=1);

namespace app\command;

use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use app\platform\validation\plugin\PluginReleaseCompositionGuard;
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
            ->addOption('release-locked', null, Option::VALUE_NONE, 'Reconcile official and already installed Plugins in plugins.lock')
            ->addOption('plan-only', null, Option::VALUE_NONE, 'Read the full release plan without lifecycle changes')
            ->addOption('current-server', null, Option::VALUE_REQUIRED, 'Canonical current server root for release plan identity comparison');
    }

    /** Resolves the fixed lock scope and applies each eligible lifecycle operation. */
    protected function handle(Input $input, Output $output): int
    {
        $officialLocked = (bool) $input->getOption('official-locked');
        $releaseLocked = (bool) $input->getOption('release-locked');
        if ((bool) $input->getOption('plan-only')) {
            return $this->releasePlan($input, $output, $releaseLocked && !$officialLocked);
        }
        if ($input->getOption('current-server') !== null) {
            $output->writeln('{"error":"PLUGIN_RECONCILE_SCOPE_CONFLICT"}');
            return 1;
        }
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

    /** The plan branch never calls reconcile, including on failed/missing installations. */
    private function releasePlan(Input $input, Output $output, bool $validScope): int
    {
        $report = ['schema_version' => 1, 'protocol' => 'peanut.plugin-reconcile-plan.v1',
            'scope' => 'release-locked', 'readonly' => true, 'may_apply' => false, 'packages' => [],
            'complete' => false, 'observation_started_at' => gmdate(DATE_ATOM)];
        try {
            if (!$validScope) {
                throw new PluginLifecycleException('PLUGIN_RECONCILE_SCOPE_CONFLICT', 'Plans require release-locked scope.');
            }
            $server = realpath(dirname(__DIR__, 2));
            $current = (string) $input->getOption('current-server');
            if ($current === '' || realpath($current) !== $current || is_link($current) || !is_dir($current)
                || $server === false || $current === $server) {
                throw new PluginLifecycleException('PLUGIN_RELEASE_CURRENT_ROOT_INVALID', 'A distinct canonical current server is required.');
            }
            $config = Config::get('modules', []);
            $lock = is_array($config) ? trim((string) ($config['plugin_lock'] ?? '')) : '';
            if ($lock === '') {
                throw new PluginLifecycleException('PLUGIN_LOCK_INVALID', 'Target lock is unavailable.');
            }
            $lockPath = str_starts_with($lock, '/') ? $lock : $server . '/' . $lock;
            $currentLock = file_exists($current . '/.peanut/release-identity.json')
                || is_link($current . '/.peanut/release-identity.json') ? $current . '/plugins.lock' : $current . '/../plugins.lock';
            $locked = (new PluginLockResolver($server, $lockPath))->all();
            $currentLocked = (new PluginLockResolver($current, $currentLock))->all();
            $report['target_server_root'] = $server;
            $report['current_server_root'] = $current;
            $report['target_lock_sha256'] = hash_file('sha256', $lockPath);
            $report['current_lock_sha256'] = hash_file('sha256', $currentLock);
            (new PluginReleaseCompositionGuard($server, $config, $this->moduleCatalogs()))->compareLocked($currentLocked, $locked);
            $official = array_values(array_filter(array_keys($locked), static fn(string $key): bool => str_starts_with($key, 'official.')));
            $selection = $this->releasePluginSelection($locked, $official);
            $keys = array_merge($selection['reconcile'], $selection['preserved_disabled']);
            sort($keys, SORT_STRING);
            $report['selected_package_keys'] = $keys;
            $report['may_apply'] = true;
            $service = $this->pluginLifecycle();
            foreach ($keys as $key) {
                try {
                    $item = $service->reconcilePlan($key, in_array($key, $selection['preserved_disabled'], true));
                } catch (PluginLifecycleException $exception) {
                    $item = ['key' => $key, 'action' => 'reject', 'may_apply' => false, 'code' => $exception->errorCode];
                } catch (\Throwable) {
                    $item = ['key' => $key, 'action' => 'reject', 'may_apply' => false, 'code' => 'UNKNOWN_PRECONDITION'];
                }
                $report['packages'][] = $item;
                $report['may_apply'] = $report['may_apply'] && ($item['may_apply'] ?? null) === true;
            }
            $report['action'] = $keys === [] ? 'planned_noop' : 'planned_reconcile';
            if (hash_file('sha256', $lockPath) !== $report['target_lock_sha256']
                || hash_file('sha256', $currentLock) !== $report['current_lock_sha256']) {
                throw new PluginLifecycleException('MODULE_PACKAGE_RECOVERY_REQUIRED', 'Release locks changed during observation.');
            }
            $report['complete'] = true;
        } catch (PluginLifecycleException $exception) {
            $report['may_apply'] = false;
            $report['code'] = $exception->errorCode;
            if (preg_match('/: ([a-zA-Z0-9_.-]{1,128})\z/D', $exception->getMessage(), $match) === 1
                && (isset($locked[$match[1]]) || isset($currentLocked[$match[1]]))) {
                $report['plugin_key'] = $match[1];
            }
        } catch (\Throwable) {
            $report['may_apply'] = false;
            $report['code'] = 'UNKNOWN_PRECONDITION';
        }
        $report['status'] = $report['may_apply'] ? 'ready' : 'blocked';
        $report['observation_completed_at'] = gmdate(DATE_ATOM);
        $report['observation'] = 'bounded non-atomic read interval on old database; execution-time guards remain required';
        $report['report_sha256'] = hash('sha256', $this->canonicalPlan($report));
        $output->writeln($this->canonicalPlan($report));
        return $report['may_apply'] ? 0 : 1;
    }

    private function canonicalPlan(array $value): string
    {
        $normalize = static function (array $items) use (&$normalize): array {
            if (!array_is_list($items)) {
                ksort($items, SORT_STRING);
            }
            foreach ($items as $key => $item) {
                if (is_array($item)) {
                    $items[$key] = $normalize($item);
                }
            }
            return $items;
        };
        return (string) json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
