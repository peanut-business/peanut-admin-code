<?php

declare(strict_types=1);

namespace app\common\composition;

use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleProvider;
use PeanutAdmin\Kernel\Module\ModuleProviderBindings;
use PeanutAdmin\Kernel\Module\TenantModuleEnableHook;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerContributor;
use PeanutAdmin\Modules\Task\Contract\TaskWorkerDefinition;
use PeanutAdmin\Modules\Task\Service\TaskWorkerDefinitionRegistry;
use Throwable;
use think\App;
use think\facade\Config;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferenceContributor;
use PeanutAdmin\Modules\File\Contract\FileDocumentReferencePolicy;
use PeanutAdmin\Modules\File\Service\FileDocumentReferenceRegistry;

/** Registers compiled Module bindings into the one ThinkPHP container. */
final readonly class ModuleComposition
{
    public function __construct(private App $app) {}

    public function register(CompiledModuleRegistry $registry): void
    {
        $providerClasses = [];
        $providers = [];
        $taskWorkers = [];
        $fileDocumentPolicy = null;
        $documentOwnerPolicies = [];
        foreach ($registry->modules as $manifest) {
            $moduleKey = $manifest->data['key'] ?? null;
            $backend = $manifest->data['backend'] ?? null;
            $providerClass = is_array($backend) ? ($backend['provider'] ?? null) : null;
            if (!is_string($moduleKey) || $moduleKey === ''
                || !is_string($providerClass) || $providerClass === ''
                || isset($providerClasses[$providerClass])) {
                throw new ModuleException('MODULE_COMPOSITION_INVALID', 'Module provider identity is invalid or duplicated.');
            }

            $provider = $this->app->make($providerClass, [], true);
            if (!$provider instanceof ModuleProvider
                || !hash_equals($moduleKey, $provider->moduleKey())) {
                throw new ModuleException('MODULE_COMPOSITION_INVALID', "Module provider is invalid: {$moduleKey}");
            }
            $providerClasses[$providerClass] = true;
            $providers[$providerClass] = $provider;
            if ($provider instanceof \PeanutAdmin\Modules\Document\Contract\DocumentOwnerContributor) {
                foreach ($provider->documentOwnerPolicies() as $type => $policy) {
                    $key = $moduleKey . ':' . $type;
                    if (!is_string($type) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $type) !== 1
                        || isset($documentOwnerPolicies[$key]) || !is_string($policy)
                        || !is_a($policy, \PeanutAdmin\Modules\Document\Contract\DocumentOwnerPolicy::class, true)) {
                        throw new ModuleException('MODULE_DOCUMENT_OWNER_POLICY_INVALID', 'Invalid business Document owner policy contribution.');
                    }
                    $documentOwnerPolicies[$key] = $policy;
                }
            }
            if ($provider instanceof FileDocumentReferenceContributor) {
                $policy = $provider->fileDocumentReferencePolicy();
                if ($moduleKey !== 'official.document' || $fileDocumentPolicy !== null
                    || !is_a($policy, FileDocumentReferencePolicy::class, true)) {
                    throw new ModuleException('MODULE_FILE_REFERENCE_POLICY_INVALID', 'Invalid Document resource policy contribution.');
                }
                $fileDocumentPolicy = $policy;
            }
            if ($provider instanceof TaskWorkerContributor) {
                foreach ($provider->taskWorkerDefinitions() as $definition) {
                    if (!is_string($definition) || !is_a($definition, TaskWorkerDefinition::class, true)) {
                        throw new ModuleException('MODULE_TASK_WORKER_INVALID', "Module Task worker is invalid: {$moduleKey}");
                    }
                    $taskWorkers[] = ['module_key' => $moduleKey, 'definition' => $definition];
                }
            }
        }

        $taskRegistry = new TaskWorkerDefinitionRegistry($taskWorkers);
        $bindings = ModuleProviderBindings::collect(array_values($providers));
        $this->assertAliasGraphIsAcyclic($bindings);
        foreach ($bindings as $abstract => $concrete) {
            if ($this->app->bound($abstract)) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', "Module binding conflicts with Host binding: {$abstract}");
            }
        }
        if ($this->app->bound(TaskWorkerDefinitionRegistry::class) || $this->app->bound(FileDocumentReferenceRegistry::class)
            || $this->app->bound(\PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class)) {
            throw new ModuleException('MODULE_BINDING_CONFLICT', 'Task worker registry conflicts with a Host binding.');
        }
        foreach ($providers as $providerClass => $provider) {
            if ($this->app->bound($providerClass) || isset($bindings[$providerClass])) {
                throw new ModuleException('MODULE_BINDING_CONFLICT', "Module provider conflicts with a binding: {$providerClass}");
            }
        }
        foreach ($bindings as $abstract => $concrete) {
            $this->app->bind($abstract, $concrete);
        }
        foreach ($providers as $providerClass => $provider) {
            $this->app->instance($providerClass, $provider);
        }
        $this->app->instance(TaskWorkerDefinitionRegistry::class, $taskRegistry);
        $this->app->instance(FileDocumentReferenceRegistry::class, new FileDocumentReferenceRegistry($this->app, $fileDocumentPolicy));
        if (class_exists(\PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class)) {
            $this->app->instance(
                \PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry::class,
                new \PeanutAdmin\Modules\Document\Service\DocumentOwnerRegistry($this->app, $documentOwnerPolicies),
            );
        } elseif ($documentOwnerPolicies !== []) {
            throw new ModuleException('MODULE_DOCUMENT_OWNER_UNAVAILABLE', 'Business owner contributions require the installed Document module.');
        }
    }

    /** @return array<string, TenantModuleEnableHook> */
    public function tenantHooks(CompiledModuleRegistry $registry): array
    {
        $enableable = [];
        $hooks = [];
        foreach ($registry->modules as $manifest) {
            $moduleKey = $manifest->data['key'] ?? null;
            $backend = $manifest->data['backend'] ?? null;
            $providerClass = is_array($backend) ? ($backend['provider'] ?? null) : null;
            if (!is_string($moduleKey) || $moduleKey === '' || !is_string($providerClass) || $providerClass === '') {
                throw new ModuleException('MODULE_HOOK_INVALID', 'Compiled Module provider identity is invalid.');
            }
            $enableable[$moduleKey] = ($manifest->data['tenant']['enableable'] ?? false) === true;
            if (!$this->app->bound($providerClass)) {
                throw new ModuleException('MODULE_HOOK_INVALID', "Compiled Module provider is unavailable: {$moduleKey}");
            }
            $provider = $this->app->make($providerClass);
            if ($provider instanceof TenantModuleEnableHook) {
                if (!$enableable[$moduleKey] || !$provider instanceof ModuleProvider
                    || !hash_equals($moduleKey, $provider->moduleKey())) {
                    throw new ModuleException('MODULE_HOOK_INVALID', "Tenant Module hook is invalid: {$moduleKey}");
                }
                $hooks[$moduleKey] = $provider;
            }
        }

        $configured = Config::get('modules.tenant_hooks', []);
        if (!is_array($configured)) {
            throw new ModuleException('MODULE_HOOK_INVALID', 'Tenant Module hook configuration is invalid.');
        }
        foreach ($configured as $moduleKey => $hookClass) {
            if (!is_string($moduleKey) || !isset($enableable[$moduleKey]) || !$enableable[$moduleKey]
                || !is_string($hookClass) || $hookClass === ''
                || !is_a($hookClass, TenantModuleEnableHook::class, true)) {
                throw new ModuleException('MODULE_HOOK_INVALID', 'Tenant Module hook configuration is invalid.');
            }
            try {
                $hook = $this->app->make($hookClass);
            } catch (Throwable) {
                throw new ModuleException('MODULE_HOOK_INVALID', "Tenant Module hook cannot be resolved: {$moduleKey}");
            }
            if (!$hook instanceof TenantModuleEnableHook) {
                throw new ModuleException('MODULE_HOOK_INVALID', "Tenant Module hook is invalid: {$moduleKey}");
            }
            $hooks[$moduleKey] = $hook;
        }
        return $hooks;
    }

    /** @param array<class-string, class-string|\Closure> $bindings */
    private function assertAliasGraphIsAcyclic(array $bindings): void
    {
        foreach ($bindings as $abstract => $concrete) {
            if (!is_string($concrete)) {
                continue;
            }
            $seen = [$abstract => true];
            $next = $concrete;
            while (isset($bindings[$next]) && is_string($bindings[$next])) {
                if (isset($seen[$next])) {
                    throw new ModuleException('MODULE_BINDING_CONFLICT', "Cyclic Module binding: {$abstract}");
                }
                $seen[$next] = true;
                $next = $bindings[$next];
            }
        }
    }
}
