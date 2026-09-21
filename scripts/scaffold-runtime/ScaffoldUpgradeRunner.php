<?php
declare(strict_types=1);

namespace app\common\infrastructure\scaffold;

use app\common\validation\scaffold\ScaffoldPathGuard;
use app\common\value\scaffold\ScaffoldManifest;
use app\platform\value\plugin\PluginDescriptor;
use app\platform\exception\plugin\PluginLifecycleException;
use app\platform\infrastructure\plugin\PluginLockResolver;
use RuntimeException;
use Throwable;

/** Plan, apply, verify, and recover scaffold-owned changes with frozen application identities. */
final class ScaffoldUpgradeRunner
{
    public function __construct(
        private readonly ?int $failAfterReplacements = null,
        private readonly ?int $failAfterAdoptionWrites = null,
    ) {
        if (($failAfterReplacements !== null && $failAfterReplacements < 1)
            || ($failAfterAdoptionWrites !== null && $failAfterAdoptionWrites < 1)) {
            throw new RuntimeException('SCAFFOLD_FAULT_INJECTION_INVALID');
        }
    }

    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const VERSION_CONTRACT_V1_KEYS = [
        'schema_version',
        'protocol',
        'product_release',
        'scaffold_template',
        'generated_application_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V2_KEYS = [
        'schema_version',
        'protocol',
        'source_product_version',
        'instance_version',
        'scaffold_template',
        'generated_instance_default',
        'core_php',
        'core_web',
    ];
    private const VERSION_CONTRACT_V3_KEYS = self::VERSION_CONTRACT_V2_KEYS;
    private const CORE_WEB_PACKAGES = [
        '@peanut-admin/client',
        '@peanut-admin/vue',
        '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt',
        '@peanut-admin/uniapp',
        '@peanut-admin/testing',
    ];

    /**
     * Build the immutable scaffold plan without writing a plan file or ledger event.
     *
     * The Platform upgrade-readiness projection uses this method so a read request
     * can evaluate exactly the same ownership/conflict rules as the CLI preflight.
     */
    public function preview(string $projectRoot, string $fromManifestPath, string $toManifestPath): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        [$application, $applicationDigest] = $this->applicationManifest($root);
        $from = ScaffoldManifest::load($fromManifestPath);
        $to = ScaffoldManifest::load($toManifestPath);
        $this->assertReleaseChain($application, $from, $to);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $fromParameters = $this->parameters($application, (string)$application['application']['version']);
        $instanceVersion = $this->instanceVersion($versionContract);
        $targetParameters = $this->parameters($application, $instanceVersion);
        $actions = $this->classify($root, $application, $from, $to, $fromParameters, $targetParameters, $versionContract);
        $pluginProjection = null;
        if (version_compare($from->version(), '3.0.0', '>=')) {
            $pluginProjection = $this->pluginProjection($root);
            $actions = $this->projectPluginBoundary(
                $actions,
                $pluginProjection,
                $this->targetPluginIndex($to, $targetParameters, $versionContract),
            );
        }
        $summary = $this->summary($actions);
        $impact = $this->impact($actions);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $managedState = $this->actionState($root, $actions);
        $identity = [
            'from' => $this->releaseIdentity($from),
            'to' => $this->releaseIdentity($to),
            'edition' => $to->data['edition'] ?? null,
            'application_version' => $instanceVersion,
            'adoption_application_version' => $application['application']['version'],
            'version_contract' => $versionContract,
            'version_contract_sha256' => $versionContractDigest,
            'from_parameters' => $fromParameters,
            'target_parameters' => $targetParameters,
            'application_manifest_sha256' => $applicationDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'plugin_projection' => $pluginProjection,
        ];
        $candidate = 'scaffold-' . substr(hash('sha256', self::canonicalJson([$identity, $actions])), 0, 24);
        $status = $summary['conflicts'] === 0 ? 'ready' : 'blocked';
        return [
            'schema_version' => 2,
            'protocol' => 'peanut.scaffold-upgrade-plan.v2',
            'candidate' => $candidate,
            'status' => $status,
            'identity' => $identity,
            'manifest_paths' => ['from' => $from->path, 'to' => $to->path],
            'summary' => $summary,
            'impact' => $impact,
            'managed_pre_state' => $managedState['files'],
            'app_owned_pre_state' => $appOwnedState['files'],
            'actions' => $actions,
        ];
    }

    public function preflight(string $projectRoot, string $fromManifestPath, string $toManifestPath): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $plan = $this->preview($root, $fromManifestPath, $toManifestPath);
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'preflight', $plan['status'])) {
            $ledger->append($this->event(
                $plan,
                'preflight',
                $plan['status'],
                $plan['identity']['managed_pre_sha256'],
                null
            ));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Build a metadata-only ownership-adoption plan from an authenticated formal package. */
    public function adoptionPlan(string $projectRoot, string $packageRoot, string $signatureKeyId, array $trustedKeys): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot);
        $prepared = (new EditionUpgradePackage())->prepareAdoption($root, $packageRoot, $signatureKeyId, $trustedKeys);
        $plan = $this->buildAdoptionPlan($root, $prepared, $packageRoot, $signatureKeyId);
        $stateRoot = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades');
        $path = $stateRoot . '/plans/' . $plan['candidate'] . '.json';
        $this->writeJsonAtomic($path, $plan, 0600);
        $ledger = new ScaffoldUpgradeLedger($stateRoot . '/ledger.ndjson');
        if (!$this->hasEvent($ledger, $plan['candidate'], 'adoption-plan', 'ready')) {
            $ledger->append($this->event($plan, 'adoption-plan', 'ready', $plan['identity']['managed_pre_sha256'], null));
        }
        return $plan + ['plan_path' => $this->relative($root, $path)];
    }

    /** Apply only authenticated old baselines and ownership metadata; live application bytes are never replaced. */
    public function adoptionApply(
        string $projectRoot,
        string $planPath,
        string $confirmedPlanSha256,
        array $confirmedPaths,
        array $trustedKeys,
    ): array {
        return $this->locked($projectRoot, function (string $root) use ($planPath, $confirmedPlanSha256, $confirmedPaths, $trustedKeys): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            if (!hash_equals($plan['plan_sha256'], $confirmedPlanSha256)
                || array_values($confirmedPaths) !== $plan['paths']) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_CONFIRMATION_MISMATCH');
            }
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'adopted') {
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $prepared = (new EditionUpgradePackage())->prepareAdoption(
                $root,
                $plan['formal_package']['root'],
                $plan['formal_package']['signature_key_id'],
                $trustedKeys,
            );
            $expected = $this->buildAdoptionPlan(
                $root,
                $prepared,
                $plan['formal_package']['root'],
                $plan['formal_package']['signature_key_id'],
            );
            if (!hash_equals($plan['candidate'], $expected['candidate'])) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_REBIND_FAILED');
            }
            $recovery = $this->createRecoveryForPaths(
                $root,
                $plan['candidate'],
                $plan['metadata_writes'],
            );
            $ledger->append($this->event($plan, 'adoption-apply', 'started', $plan['identity']['managed_pre_sha256'], null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    $source = ScaffoldPathGuard::existingFileWithin(
                        $plan['formal_package']['root'],
                        $action['old']['source'],
                        'SCAFFOLD_ADOPTION_SOURCE_INVALID',
                    );
                    $content = file_get_contents($source);
                    if (!is_string($content) || !hash_equals($action['old']['sha256'], hash('sha256', $content))) {
                        throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $action['path']);
                    }
                    $this->writeFileAtomic(
                        ScaffoldPathGuard::projectPath($root, $action['baseline_path']),
                        $content,
                        0644,
                    );
                    $writes++;
                    $this->adoptionFault($writes);
                }
                $manifest = $this->nextAdoptedApplicationManifest($root, $plan);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $writes++;
                $this->adoptionFault($writes);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'adoption-apply', 'adopted', $plan['identity']['managed_pre_sha256'], $post, ['recovery' => $recovery]));
                return ['status' => 'adopted', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'adoption-apply', 'failed', $plan['identity']['managed_pre_sha256'], null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    public function adoptionRecover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadAdoptionPlan($root, $planPath);
            return $this->recoverCandidate($root, $plan, 'adoption-recover');
        });
    }

    /** Apply only the bytes and identities frozen by a fresh, ready plan. */
    public function apply(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if ($plan['status'] !== 'ready') throw new RuntimeException('SCAFFOLD_PLAN_BLOCKED');
            if (in_array($this->candidateState($ledger, $plan['candidate']), ['applied', 'verified'], true)) {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            $this->assertPlanFresh($root, $plan);
            $this->assertPlanRebound($root, $plan);
            $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
            $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
            $recovery = $this->createRecovery($root, $plan);
            $pre = $plan['identity']['managed_pre_sha256'];
            $ledger->append($this->event($plan, 'apply', 'started', $pre, null));
            try {
                $writes = 0;
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'delete') continue;
                    $this->assertActionFresh($root, $action);
                    $target = ScaffoldPathGuard::projectPath($root, $action['path']);
                    if (!unlink($target)) throw new RuntimeException('SCAFFOLD_ATOMIC_DELETE_FAILED: ' . $action['path']);
                    $this->pruneEmptyParents(dirname($target), $root);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if (!in_array($action['action'], ['create', 'replace', 'regenerate'], true)) continue;
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->targetContent($to, $action, $plan);
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $action['path']), $artifact, (int)$action['mode']);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                    $writes++;
                    if ($this->failAfterReplacements !== null && $writes >= $this->failAfterReplacements) {
                        throw new RuntimeException('SCAFFOLD_FAULT_INJECTED');
                    }
                }
                foreach ($plan['actions'] as $action) {
                    if ($action['action'] !== 'preserve') continue;
                    $this->assertActionFresh($root, $action);
                    $artifact = $this->baselineContent($root, $to, $action, $plan);
                    $baseline = '.peanut/scaffold-baseline/' . $to->version() . '/files/' . $action['path'];
                    $this->writeFileAtomic(ScaffoldPathGuard::projectPath($root, $baseline), $artifact, 0644);
                }
                $this->assertPluginProjection($root, $plan);
                $manifest = $this->nextApplicationManifest($root, $plan, $to);
                $this->writeJsonAtomic(ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json'), $manifest, 0644);
                $post = $this->managedDigestFromManifest($root, $manifest);
                $ledger->append($this->event($plan, 'apply', 'applied', $pre, $post, ['recovery' => $recovery]));
                return ['status' => 'applied', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'recovery' => $recovery, 'idempotent' => false];
            } catch (Throwable $exception) {
                $ledger->append($this->event($plan, 'apply', 'failed', $pre, null, ['error' => $exception->getMessage(), 'recovery' => $recovery]));
                throw $exception;
            }
        });
    }

    /** Verify the target scaffold and its newly adopted current-version snapshot. */
    public function verify(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $ledger = $this->ledger($root);
            if ($this->candidateState($ledger, $plan['candidate']) === 'verified') {
                $this->assertAppliedState($root, $plan);
                return ['status' => 'verified', 'candidate' => $plan['candidate'], 'idempotent' => true];
            }
            if (!in_array($this->candidateState($ledger, $plan['candidate']), ['applied','verified'], true)) throw new RuntimeException('SCAFFOLD_APPLY_NOT_COMMITTED');
            [$application, $actualAppOwned] = $this->assertAppliedState($root, $plan);
            $post = $this->managedDigestFromManifest($root, $application);
            $ledger->append($this->event($plan, 'verify', 'verified', $plan['identity']['managed_pre_sha256'], $post));
            return ['status' => 'verified', 'candidate' => $plan['candidate'], 'managed_post_sha256' => $post, 'app_owned_sha256' => $actualAppOwned['digest'], 'idempotent' => false];
        });
    }

    public function recover(string $projectRoot, string $planPath): array
    {
        return $this->locked($projectRoot, function (string $root) use ($planPath): array {
            $plan = $this->loadPlan($root, $planPath);
            $ledger = $this->ledger($root);
            $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
            if (!is_file($manifestPath)) throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
            $recovery = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
                || !hash_equals((string)($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
                throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
            }
            $already = $this->recoveryMatches($root, $recovery);
            if (!$already) {
                foreach ($recovery['files'] as $relative => $state) {
                    $target = ScaffoldPathGuard::projectPath($root, (string)$relative);
                    if ($state['present']) {
                        $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                        $content = file_get_contents($backup);
                        if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))) throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                        $this->writeFileAtomic($target, $content, (int)$state['mode']);
                    } elseif (file_exists($target)) {
                        if (!is_file($target) || is_link($target)) throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                        unlink($target);
                        $this->pruneEmptyParents(dirname($target), $root);
                    }
                }
                if (!$this->recoveryMatches($root, $recovery)) throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
            }
            $this->assertPluginProjection($root, $plan);
            if (!$this->hasEvent($ledger, $plan['candidate'], 'recover', 'recovered')) {
                $ledger->append($this->event($plan, 'recover', 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
            }
            return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
        });
    }

    private function assertReleaseChain(array $application, ScaffoldManifest $from, ScaffoldManifest $to): void
    {
        if (version_compare($from->version(), $to->version(), '>=') || ($application['template']['version'] ?? null) !== $from->version()
            || ($application['template']['source_commit'] ?? null) !== $from->release()['source_commit']
            || ($application['template']['source_tree'] ?? null) !== $from->release()['source_tree']) {
            throw new RuntimeException('SCAFFOLD_RELEASE_CHAIN_INVALID');
        }
        $applicationEdition = $application['edition'] ?? null;
        $fromEdition = $from->data['edition'] ?? null;
        $toEdition = $to->data['edition'] ?? null;
        $name = $application['application']['edition'] ?? null;
        if ($name === null && $applicationEdition === null && $fromEdition === null && $toEdition === null) {
            // Immutable pre-Edition releases have no Edition identity. Their
            // generic upgrade chain remains valid, but it cannot convert an
            // instance into either current Edition implicitly.
            return;
        }
        $expectedBootstrap = [
            'kind' => 'real-default-tenant',
            'code' => 'default',
            'tenant_identity' => 'required',
            'rbac' => 'required',
            'execution_context' => 'PeanutAdmin\\Kernel\\Context\\TenantSystemContext',
            'module_lifecycle' => 'required',
        ];
        if (!in_array($name, ['standalone', 'multi-tenant'], true)
            || !is_array($applicationEdition) || ($applicationEdition['name'] ?? null) !== $name
            || !is_array($fromEdition) || ($fromEdition['name'] ?? null) !== $name
            || !is_array($toEdition) || ($toEdition['name'] ?? null) !== $name
            || ($toEdition['deployment_mode'] ?? null) !== $name
            || ($toEdition['module_profile'] ?? null) !== 'official-default'
            || ($toEdition['tenant_bootstrap'] ?? null) !== $expectedBootstrap
            || (isset($applicationEdition['tenant_bootstrap'])
                && $applicationEdition['tenant_bootstrap'] !== $expectedBootstrap)
            || (isset($fromEdition['tenant_bootstrap'])
                && $fromEdition['tenant_bootstrap'] !== $expectedBootstrap)) {
            throw new RuntimeException('SCAFFOLD_EDITION_CHAIN_INVALID');
        }
    }

    /** @param array<string,mixed> $prepared */
    private function buildAdoptionPlan(
        string $root,
        array $prepared,
        string $packageRoot,
        string $signatureKeyId,
    ): array {
        $adoption = $prepared['adoption'] ?? null;
        if (!is_array($adoption) || !is_array($adoption['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_AVAILABLE');
        }
        [$application, $applicationDigest] = $this->applicationManifest($root);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        $to = ScaffoldManifest::load($prepared['to_manifest']);
        $parameters = $this->parameters($application, $this->instanceVersion($versionContract));
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) $applicationFiles[$file['path']] = $file;
        }
        $targetFiles = $to->files();
        $actions = [];
        foreach (EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS as $path) {
            $entry = $adoption['files'][$path] ?? null;
            $target = $targetFiles[$path] ?? null;
            $instance = $applicationFiles[$path] ?? null;
            if (!is_array($entry) || !is_array($target)
                || ($instance !== null && ($instance['classification'] ?? null) !== 'app-owned')) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_NOT_REQUIRED: ' . $path);
            }
            $current = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $path), $path);
            if (!$current['present']) throw new RuntimeException('SCAFFOLD_ADOPTION_FILE_MISSING: ' . $path);
            $oldSource = (string)$entry['absolute_source'];
            $oldBytes = file_get_contents($oldSource);
            if (!is_string($oldBytes) || !hash_equals((string)$entry['sha256'], hash('sha256', $oldBytes))) {
                throw new RuntimeException('SCAFFOLD_ADOPTION_SOURCE_DRIFT: ' . $path);
            }
            $targetBytes = $this->renderCurrentVersionArtifact($to, $target, $parameters, $versionContract);
            $actions[] = [
                'path' => $path,
                'old' => ['sha256' => hash('sha256', $oldBytes), 'mode' => $entry['mode'], 'source' => $oldSource],
                'current' => $current,
                'target' => ['sha256' => hash('sha256', $targetBytes), 'mode' => $target['mode']],
                'baseline_path' => '.peanut/scaffold-baseline/' . $adoption['source']['version'] . '/files/' . $path,
                'metadata_action' => $instance === null ? 'add-managed-record' : 'adopt-app-owned-record',
            ];
        }
        usort($actions, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $paths = array_column($actions, 'path');
        $managedState = $this->actionState($root, $actions);
        $appOwnedState = $this->ownershipState($root, $application, 'app-owned');
        $identity = [
            'from' => $adoption['source'],
            'to' => $this->releaseIdentity($to),
            'edition' => $to->data['edition'],
            'source_edition' => $application['edition'],
            'application_manifest_sha256' => $applicationDigest,
            'version_contract_sha256' => $versionContractDigest,
            'managed_pre_sha256' => $managedState['digest'],
            'app_owned_pre_sha256' => $appOwnedState['digest'],
            'package_inventory_sha256' => $prepared['package']['inventory_sha256'],
            'package_manifest_sha256' => $prepared['package']['manifest_sha256'],
        ];
        $candidateDigest = hash('sha256', self::canonicalJson([$identity, $actions]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($actions as $action) $metadataWrites[] = $action['baseline_path'];
        sort($metadataWrites, SORT_STRING);
        return [
            'schema_version' => 1,
            'protocol' => 'peanut.scaffold-ownership-adoption-plan.v1',
            'candidate' => 'ownership-adoption-' . substr($candidateDigest, 0, 24),
            'status' => 'ready',
            'plan_sha256' => 'sha256:' . $candidateDigest,
            'paths' => $paths,
            'paths_sha256' => 'sha256:' . hash('sha256', self::canonicalJson($paths)),
            'identity' => $identity,
            'formal_package' => [
                'root' => realpath($packageRoot),
                'signature_key_id' => $signatureKeyId,
            ],
            'metadata_writes' => $metadataWrites,
            'actions' => $actions,
        ];
    }

    private function nextAdoptedApplicationManifest(string $root, array $plan): array
    {
        [$application] = $this->applicationManifest($root);
        $adopted = array_fill_keys($plan['paths'], true);
        $files = [];
        foreach ($application['files'] as $file) {
            if (!isset($adopted[$file['path']])) $files[] = $file;
        }
        foreach ($plan['actions'] as $action) {
            $files[] = [
                'path' => $action['path'],
                'sha256' => $action['current']['sha256'],
                'mode' => $action['old']['mode'],
                'classification' => 'managed',
                'owner' => 'scaffold',
                'source' => $action['path'],
                'baseline_path' => $action['baseline_path'],
                'baseline_sha256' => $action['old']['sha256'],
            ];
        }
        usort($files, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'], ['managed', 'generated-managed'], true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification'] === 'app-owned'));
        $application['files'] = $files;
        $application['digests']['managed_tree_sha256'] = $this->manifestTreeDigest($managed);
        $application['digests']['app_owned_tree_sha256'] = $this->manifestTreeDigest($appOwned);
        $application['last_ownership_adoption'] = [
            'candidate' => $plan['candidate'],
            'source' => $plan['identity']['from'],
            'paths_sha256' => $plan['paths_sha256'],
        ];
        return $application;
    }

    private function adoptionFault(int $writes): void
    {
        if ($this->failAfterAdoptionWrites !== null && $writes >= $this->failAfterAdoptionWrites) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_FAULT_INJECTED');
        }
    }

    /**
     * Compare the recorded adoption rendering with the live tree and render the target
     * from the current application release frozen by preflight.
     */
    private function classify(
        string $root,
        array $application,
        ScaffoldManifest $from,
        ScaffoldManifest $to,
        array $fromParameters,
        array $targetParameters,
        array $versionContract,
    ): array
    {
        if ($from->renames() !== [] || $to->renames() !== []) throw new RuntimeException('SCAFFOLD_RENAME_UNSUPPORTED');
        $old = $from->files(); $new = $to->files(); $actions = [];
        $applicationFiles = [];
        foreach ($application['files'] as $file) {
            if (is_array($file) && is_string($file['path'] ?? null)) {
                $applicationFiles[$file['path']] = $file;
            }
        }
        $paths = array_unique(array_merge(array_keys($old), array_keys($new))); sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $before = $old[$path] ?? null; $after = $new[$path] ?? null;
            $projectPath = ScaffoldPathGuard::projectPath($root, $path);
            $current = $this->regularFileState($projectPath, $path);
            $instanceFile = $applicationFiles[$path] ?? null;
            if (($instanceFile['classification'] ?? null) === 'app-owned') {
                $file = $after ?? $before ?? [];
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'app_owned_adoption_required', true, $current, $target);
                continue;
            }
            if ($before !== null && (!$instanceFile
                || !in_array($instanceFile['classification'] ?? null, ['managed', 'generated-managed'], true))) {
                $file = $after ?? $before;
                $target = $after === null ? null : hash('sha256', $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract));
                $actions[] = $this->action($path, $file, 'conflict', 'managed_adoption_required', true, $current, $target);
                continue;
            }
            if ($after === null) {
                if (!$current['present']) {
                    $actions[] = $this->action($path, $before ?? [], 'conflict', 'managed_file_missing', true, $current, null);
                    continue;
                }
                $oldContent = $this->renderArtifact($from, $before, $fromParameters);
                $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
                $actions[] = (($this->renderedContentMatches($root, $path, $current, $oldContent)
                        || $this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                    && ($current['mode'] ?? null) === ($before['mode'] ?? null))
                    ? $this->action($path, $before, 'delete', 'upstream_removed_only', false, $current, null)
                    : $this->action($path, $before, 'conflict', 'project_modified_upstream_removed', true, $current, null);
                continue;
            }
            $targetContent = $this->renderCurrentVersionArtifact($to, $after, $targetParameters, $versionContract);
            $targetDigest = hash('sha256', $targetContent);
            if ($before === null) {
                $actions[] = $current['present']
                    ? $this->action($path, $after, 'conflict', 'new_path_already_exists', true, $current, $targetDigest)
                    : $this->action($path, $after, 'create', 'new_managed_file', false, $current, $targetDigest);
                continue;
            }
            $oldContent = $this->renderArtifact($from, $before, $fromParameters);
            $currentVersionContent = $this->renderCurrentVersionArtifact($from, $before, $targetParameters, $versionContract);
            $currentVersionDigest = hash('sha256', $currentVersionContent);
            if (!$current['present']) {
                $actions[] = $this->action($path, $after, 'conflict', 'managed_file_missing', true, $current, $targetDigest);
                continue;
            }
            if ($this->renderedContentMatches($root, $path, $current, $targetContent)
                && ($current['mode'] ?? null) === ($after['mode'] ?? null)) {
                $actions[] = $this->action($path, $after, 'preserve', 'already_at_target', false, $current, $targetDigest);
                continue;
            }
            $projectChanged = (!$this->renderedContentMatches($root, $path, $current, $oldContent)
                    && !$this->renderedContentMatches($root, $path, $current, $currentVersionContent))
                || ($current['mode'] ?? null) !== ($before['mode'] ?? null);
            $upstreamChanged = !hash_equals($currentVersionDigest, $targetDigest)
                || ($before['mode'] ?? null) !== ($after['mode'] ?? null);
            if ($projectChanged && $upstreamChanged) $actions[] = $this->action($path, $after, 'conflict', 'both_project_and_upstream_modified', true, $current, $targetDigest);
            elseif ($projectChanged) $actions[] = $this->action($path, $after, 'preserve', 'project_modified_only', false, $current, $targetDigest);
            else $actions[] = $this->action($path, $after, $after['classification'] === 'generated-managed' ? 'regenerate' : 'replace', $upstreamChanged ? 'upstream_modified_only' : 'application_version_projection', false, $current, $targetDigest);
        }
        return $actions;
    }

    private function action(string $path, array $file, string $action, string $reason, bool $conflict, ?array $current, ?string $target): array
    {
        return ['path' => $path, 'classification' => $file['classification'] ?? 'managed', 'owner' => $file['owner'] ?? 'host',
            'policy' => $file['policy'] ?? 'managed', 'transform' => $file['transform'] ?? 'copy', 'mode' => $file['mode'] ?? 0644,
            'source' => $file['source'] ?? null, 'template_sha256' => $file['template_sha256'] ?? null, 'action' => $action,
            'reason' => $reason, 'conflict' => $conflict, 'current' => $current, 'target_sha256' => $target];
    }

    private function renderArtifact(ScaffoldManifest $manifest, array $file, array $parameters): string
    {
        $path = $manifest->artifactPath($file);
        $raw = file_get_contents($path);
        if (!is_string($raw) || !hash_equals($file['template_sha256'], hash('sha256', $raw))) throw new RuntimeException('SCAFFOLD_ARTIFACT_DIGEST_MISMATCH: ' . $file['path']);
        $tokens=$manifest->release()['tokens'];
        $expectedKeys=$manifest->supportsApplicationVersion()
            ? ['product_name','slug','package_identity','application_version']
            : ['product_name','slug','package_identity'];
        if(array_keys($tokens)!==$expectedKeys)throw new RuntimeException('SCAFFOLD_RELEASE_TOKENS_INVALID');
        $values=[
            'product_name'=>$parameters['PRODUCT_NAME'],
            'slug'=>$parameters['SLUG'],
            'package_identity'=>$parameters['PACKAGE_IDENTITY'],
            'application_version'=>$parameters['APPLICATION_VERSION'],
        ];
        $rendered=$raw;
        foreach($tokens as $key=>$token)$rendered=str_replace($token,$values[$key],$rendered);
        if (($file['transform'] ?? null) === 'composer-lock') {
            $composer = $manifest->files()['server/composer.json'] ?? null;
            if (!is_array($composer)) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_MISSING');
            }
            $composerPath = $manifest->artifactPath($composer);
            $composerRaw = file_get_contents($composerPath);
            if (!is_string($composerRaw)
                || !hash_equals((string)($composer['template_sha256'] ?? ''), hash('sha256', $composerRaw))) {
                throw new RuntimeException('SCAFFOLD_COMPOSER_COMPANION_INVALID');
            }
            foreach ($tokens as $key => $token) {
                $composerRaw = str_replace($token, $values[$key], $composerRaw);
            }
            return ScaffoldManifest::renderComposerLock($rendered, $composerRaw);
        }
        return $rendered;
    }

    /**
     * Keep the application's current release and original generated default while
     * accepting the target scaffold/Core fields from the immutable target artifact.
     */
    private function renderCurrentVersionArtifact(
        ScaffoldManifest $manifest,
        array $file,
        array $parameters,
        array $versionContract,
    ): string {
        $rendered = $this->renderArtifact($manifest, $file, $parameters);
        if (($file['path'] ?? null) !== 'release-versions.json') {
            return $rendered;
        }
        $document = $this->normalizeVersionContractDocument(
            $rendered,
            'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID',
        );
        if ($document['schema_version'] >= 2) {
            $document['instance_version'] = $this->instanceVersion($versionContract);
        } else {
            $instanceVersion = $this->instanceVersion($versionContract);
            $document['product_release'] = $instanceVersion;
        }
        return json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** Render apply bytes only from the immutable parameters and version contract in the plan. */
    private function targetContent(ScaffoldManifest $manifest, array $action, array $plan): string
    {
        return $this->renderCurrentVersionArtifact(
            $manifest,
            $action,
            $plan['identity']['target_parameters'],
            $plan['identity']['version_contract'],
        );
    }

    /** Use the validated live Plugin bytes as the next baseline without rewriting the installed Plugin. */
    private function baselineContent(string $root, ScaffoldManifest $manifest, array $action, array $plan): string
    {
        if (($action['baseline_source'] ?? null) !== 'validated-plugin-projection') {
            return $this->targetContent($manifest, $action, $plan);
        }
        $path = ScaffoldPathGuard::projectPath($root, $action['path']);
        $state = $this->regularFileState($path, $action['path']);
        $content = $state['present'] ? file_get_contents($path) : false;
        if (!is_string($content)
            || $state !== $action['current']
            || !hash_equals((string)$action['target_sha256'], hash('sha256', $content))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED: ' . $action['path']);
        }
        return $content;
    }

    /**
     * Resolve the installed Plugin graph with the production verifier and freeze only
     * its normalized identities. Root bytes are covered by each canonical source digest.
     *
     * @return array<string,mixed>
     */
    private function pluginProjection(string $root): array
    {
        $lockPath = ScaffoldPathGuard::projectPath($root, 'plugins.lock');
        $lockState = $this->regularFileState($lockPath, 'plugins.lock');
        if (!$lockState['present']) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_LOCK_MISSING');
        }
        try {
            $resolved = (new PluginLockResolver(
                ScaffoldPathGuard::projectPath($root, 'server'),
                '../plugins.lock',
            ))->all();
            $lockBytes = file_get_contents($lockPath);
            $lockEndState = $this->regularFileState($lockPath, 'plugins.lock');
            if (!is_string($lockBytes)
                || $lockEndState !== $lockState
                || !hash_equals((string)$lockState['sha256'], hash('sha256', $lockBytes))) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
            }
            $lock = json_decode($lockBytes, true, 64, JSON_THROW_ON_ERROR);
        } catch (PluginLifecycleException $exception) {
            throw new RuntimeException(
                'SCAFFOLD_PLUGIN_PROJECTION_INVALID: ' . $exception->errorCode . ': ' . $exception->getMessage(),
                0,
                $exception,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock) || !is_array($lock['plugins'] ?? null) || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }

        $plugins = [];
        $artifactPaths = ['plugins.lock'];
        $rootPaths = [];
        foreach ($lock['plugins'] as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;
            $manifest = is_array($entry) ? ($entry['manifest'] ?? null) : null;
            if (!is_string($key) || !isset($resolved[$key]) || !is_string($manifest)) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
            }
            $manifest = ScaffoldManifest::path($manifest);
            $manifestState = $this->regularFileState(
                ScaffoldPathGuard::projectPath($root, $manifest),
                $manifest,
            );
            /** @var PluginDescriptor $descriptor */
            $descriptor = $resolved[$key];
            if (!$manifestState['present']
                || !hash_equals($descriptor->manifestDigest, (string)$manifestState['sha256'])) {
                throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
            }
            $moduleRoots = [];
            $frontendRoots = [];
            foreach ($descriptor->moduleRoots as $moduleKey => $absoluteRoot) {
                $moduleRoots[] = $this->relativeDirectory($root, $absoluteRoot);
            }
            foreach ($descriptor->frontend as $frontend) {
                $entry = is_array($frontend) ? ($frontend['entry'] ?? null) : null;
                if (!is_string($entry)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_MANIFEST_INVALID');
                }
                $frontendRoot = dirname(ScaffoldManifest::path($entry));
                $frontendPath = ScaffoldPathGuard::projectPath($root, $frontendRoot);
                if (!is_dir($frontendPath)) {
                    throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
                }
                $frontendRoots[] = $frontendRoot;
            }
            sort($moduleRoots, SORT_STRING);
            sort($frontendRoots, SORT_STRING);
            $plugins[$key] = [
                'version' => $descriptor->version,
                'manifest' => $manifest,
                'manifest_sha256' => $descriptor->manifestDigest,
                'source_sha256' => $descriptor->source['sha256'],
                'module_roots' => $moduleRoots,
                'frontend_roots' => $frontendRoots,
                'manifest_state' => $manifestState,
            ];
            $artifactPaths[] = $manifest;
            array_push($rootPaths, ...$moduleRoots, ...$frontendRoots);
        }
        if (array_diff(array_keys($resolved), array_keys($plugins)) !== []) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_LOCK_INVALID');
        }
        ksort($plugins, SORT_STRING);
        $artifactPaths = array_values(array_unique($artifactPaths));
        $rootPaths = array_values(array_unique($rootPaths));
        sort($artifactPaths, SORT_STRING);
        sort($rootPaths, SORT_STRING);
        $projection = [
            'schema_version' => 1,
            'lock_state' => $lockState,
            'plugins' => $plugins,
            'artifact_paths' => $artifactPaths,
            'root_paths' => $rootPaths,
        ];
        $projection['sha256'] = 'sha256:' . hash('sha256', self::canonicalJson($projection));
        return $projection;
    }

    /** @return array<string,array{manifest:string,module_roots:list<string>,frontend_roots:list<string>}> */
    private function targetPluginIndex(
        ScaffoldManifest $manifest,
        array $parameters,
        array $versionContract,
    ): array {
        $files = $manifest->files();
        $lockFile = $files['plugins.lock'] ?? null;
        if (!is_array($lockFile)) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_MISSING');
        }
        try {
            $lock = json_decode(
                $this->renderCurrentVersionArtifact($manifest, $lockFile, $parameters, $versionContract),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID', 0, $exception);
        }
        if (!is_array($lock)
            || ($lock['schema_version'] ?? null) !== 1
            || !is_array($lock['plugins'] ?? null)
            || !array_is_list($lock['plugins'])) {
            throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
        }
        $plugins = [];
        foreach ($lock['plugins'] as $entry) {
            if (!is_array($entry)
                || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string)($entry['key'] ?? '')) !== 1
                || !is_string($entry['manifest'] ?? null)
                || !is_array($entry['frontend'] ?? null)
                || !array_is_list($entry['frontend'])
                || !is_array($entry['modules'] ?? null)
                || $entry['modules'] === []
                || !array_is_list($entry['modules'])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $key = (string)$entry['key'];
            $manifestPath = ScaffoldManifest::path($entry['manifest']);
            if (isset($plugins[$key]) || !isset($files[$manifestPath])) {
                throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
            }
            $moduleRoots = [];
            $frontendRoots = [];
            foreach ($entry['modules'] as $module) {
                if (!is_array($module)
                    || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', (string)($module['key'] ?? '')) !== 1
                    || !is_string($module['root'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoot = ScaffoldManifest::path($module['root']);
                if (isset($moduleRoots[$moduleRoot])) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $moduleRoots[$moduleRoot] = true;
            }
            foreach ($entry['frontend'] ?? [] as $frontend) {
                if (!is_array($frontend) || !is_string($frontend['entry'] ?? null)) {
                    throw new RuntimeException('SCAFFOLD_TARGET_PLUGIN_LOCK_INVALID');
                }
                $frontendRoots[dirname(ScaffoldManifest::path($frontend['entry']))] = true;
            }
            $plugins[$key] = [
                'manifest' => $manifestPath,
                'module_roots' => array_keys($moduleRoots),
                'frontend_roots' => array_keys($frontendRoots),
            ];
            sort($plugins[$key]['module_roots'], SORT_STRING);
            sort($plugins[$key]['frontend_roots'], SORT_STRING);
        }
        ksort($plugins, SORT_STRING);
        return $plugins;
    }

    /** Keep the installed Plugin graph whole; scaffold upgrades cannot partially replace its derived metadata or roots. */
    private function projectPluginBoundary(array $actions, array $current, array $target): array
    {
        $currentArtifacts = array_fill_keys($current['artifact_paths'], true);
        $currentRoots = $current['root_paths'];
        $targetArtifacts = [];
        $adoptionRequired = [];
        foreach ($target as $key => $plugin) {
            $targetArtifacts[$plugin['manifest']] = $key;
            $installed = $current['plugins'][$key] ?? null;
            if (!is_array($installed)
                || $installed['manifest'] !== $plugin['manifest']
                || array_diff($plugin['module_roots'], $installed['module_roots']) !== []
                || array_diff($plugin['frontend_roots'], $installed['frontend_roots']) !== []) {
                $adoptionRequired[$key] = true;
            }
        }

        foreach ($actions as &$action) {
            $path = (string)$action['path'];
            if ($path === 'plugins.lock' && $adoptionRequired !== []) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (isset($targetArtifacts[$path]) && !isset($currentArtifacts[$path])) {
                $action['action'] = 'conflict';
                $action['reason'] = 'plugin_adoption_required';
                $action['conflict'] = true;
                continue;
            }
            if (in_array($action['reason'], ['app_owned_adoption_required', 'managed_adoption_required'], true)) {
                continue;
            }
            if (isset($currentArtifacts[$path]) || $this->withinAnyRoot($path, $currentRoots)) {
                $present = ($action['current']['present'] ?? false) === true;
                $action['action'] = $present ? 'preserve' : 'omit';
                $action['reason'] = $present ? 'installed_plugin_projection' : 'installed_plugin_target_only_omitted';
                $action['conflict'] = false;
                $action['target_sha256'] = $present ? $action['current']['sha256'] : null;
                $action['baseline_source'] = $present ? 'validated-plugin-projection' : 'absent-plugin-projection';
            }
        }
        unset($action);
        return $actions;
    }

    private function assertPluginProjection(string $root, array $plan): void
    {
        $expected = $plan['identity']['plugin_projection'] ?? null;
        if ($expected === null) {
            return;
        }
        if (!is_array($expected) || $this->pluginProjection($root) !== $expected) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_CHANGED');
        }
    }

    private function relativeDirectory(string $root, string $directory): string
    {
        $resolved = realpath($directory);
        if ($resolved === false
            || ($resolved !== $root && !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('SCAFFOLD_PLUGIN_PROJECTION_INVALID: PLUGIN_PATH_UNAVAILABLE');
        }
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($resolved, strlen($root) + 1));
        ScaffoldPathGuard::projectPath($root, ScaffoldManifest::path($relative));
        return $relative;
    }

    /** @param list<string> $roots */
    private function withinAnyRoot(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($path === $root || str_starts_with($path, $root . '/')) {
                return true;
            }
        }
        return false;
    }

    private function summary(array $actions): array
    {
        $summary = ['total' => count($actions), 'automatic' => 0, 'preserved' => 0, 'conflicts' => 0];
        foreach ($actions as $action) $action['conflict'] ? $summary['conflicts']++ : (in_array($action['action'], ['create','delete','replace','regenerate'], true) ? $summary['automatic']++ : $summary['preserved']++);
        return $summary;
    }

    private function impact(array $actions): array
    {
        $changes = [];
        $preserved = [];
        $conflicts = [];
        foreach ($actions as $action) {
            $item = ['path' => $action['path'], 'action' => $action['action'], 'reason' => $action['reason']];
            if ($action['conflict']) $conflicts[] = $item;
            elseif (in_array($action['action'], ['create', 'delete', 'replace', 'regenerate'], true)) $changes[] = $item;
            else $preserved[] = $item;
        }
        return [
            'message' => $conflicts === []
                ? sprintf('%d managed files will change; %d files will be preserved. Review the plan before apply.', count($changes), count($preserved))
                : sprintf('No files will be changed until %d conflicts are resolved.', count($conflicts)),
            'will_change' => $changes,
            'will_preserve' => $preserved,
            'must_resolve' => $conflicts,
            'ownership_notice' => 'App-owned files, third-party Modules and secrets are outside the automatic write set.',
        ];
    }

    /** @return array{APPLICATION_VERSION:string,PACKAGE_IDENTITY:string,PRODUCT_NAME:string,SLUG:string} */
    private function parameters(array $application, string $applicationVersion): array
    {
        return [
            'APPLICATION_VERSION' => $applicationVersion,
            'PACKAGE_IDENTITY' => (string)$application['application']['package_identity'],
            'PRODUCT_NAME' => (string)$application['application']['name'],
            'SLUG' => (string)$application['application']['slug'],
        ];
    }

    /**
     * Read the live application release authority without requiring installed
     * Composer dependencies and bind it to the currently adopted scaffold.
     *
     * @return array{0:array<string,int|string|null>,1:string}
     */
    private function versionContract(string $root, array $application): array
    {
        $path = ScaffoldPathGuard::projectPath($root, 'release-versions.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_INVALID');
        }
        $raw = file_get_contents($path);
        $document = $this->normalizeVersionContractDocument(
            is_string($raw) ? $raw : '',
            'SCAFFOLD_VERSION_CONTRACT_INVALID',
        );
        if ($document['schema_version'] === 3) {
            $this->assertV3ArchiveDigests($root, $document['core_web']);
        }
        if ($document['scaffold_template'] !== ($application['template']['version'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_IDENTITY_MISMATCH');
        }
        return [$document, 'sha256:' . hash('sha256', (string)$raw)];
    }

    /** Compare the known version contract semantically while all other managed files remain byte-exact. */
    private function renderedContentMatches(
        string $root,
        string $path,
        array $current,
        string $expected,
    ): bool {
        if (($current['present'] ?? false) !== true) {
            return false;
        }
        if ($path !== 'release-versions.json') {
            return hash_equals(hash('sha256', $expected), (string)$current['sha256']);
        }
        $actual = file_get_contents(ScaffoldPathGuard::projectPath($root, $path));
        return is_string($actual)
            && hash_equals(hash('sha256', $actual), (string)$current['sha256'])
            && $this->normalizeVersionContractDocument($actual, 'SCAFFOLD_VERSION_CONTRACT_INVALID')
                === $this->normalizeVersionContractDocument($expected, 'SCAFFOLD_VERSION_CONTRACT_TARGET_INVALID');
    }

    /** Parse the supported historical/current fields and return their stable semantic order. */
    private function normalizeVersionContractDocument(string $raw, string $error): array
    {
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        $keys = is_array($document) ? array_keys($document) : [];
        $v3 = is_array($document)
            && ($document['schema_version'] ?? null) === 3
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = is_array($document)
            && ($document['schema_version'] ?? null) === 2
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v2';
        $expectedKeys = $v3
            ? self::VERSION_CONTRACT_V3_KEYS
            : ($v2 ? self::VERSION_CONTRACT_V2_KEYS : self::VERSION_CONTRACT_V1_KEYS);
        if (!is_array($document) || count($keys) !== count($expectedKeys)
            || array_diff($keys, $expectedKeys) !== [] || array_diff($expectedKeys, $keys) !== []
            || (!$v2 && !$v3 && (($document['schema_version'] ?? null) !== 1
                || ($document['protocol'] ?? null) !== 'peanut.release-versions.v1'))) {
            throw new RuntimeException($error);
        }
        $normalized = [];
        foreach ($expectedKeys as $key) {
            $value = $document[$key];
            if ($key === 'instance_version' && $value === null) {
                $normalized[$key] = null;
                continue;
            }
            if ($v3 && in_array($key, ['core_php', 'core_web'], true)) {
                $normalized[$key] = $value;
                continue;
            }
            if (!in_array($key, ['schema_version', 'protocol'], true)
                && (!is_string($value)
                    || !(($v2 || $v3) ? $this->isStrictSemanticVersion($value) : $this->isSemanticVersion($value)))) {
                throw new RuntimeException($error . ': ' . $key);
            }
            $normalized[$key] = $value;
        }
        if ($v2 && ($normalized['source_product_version'] !== $normalized['core_php']
                || $normalized['source_product_version'] !== $normalized['core_web']
                || $normalized['source_product_version'] !== $normalized['scaffold_template'])) {
            throw new RuntimeException($error . ': product-core-version-mismatch');
        }
        if ($v3) {
            if ($normalized['source_product_version'] !== $normalized['scaffold_template']) {
                throw new RuntimeException($error . ': product-template-version-mismatch');
            }
            $this->assertV3DependencyIdentity($normalized['core_php'], $normalized['core_web'], $error);
        }
        return $normalized;
    }

    /** Resolve only the customer instance sequence used by scaffold apply/verify plans. */
    private function instanceVersion(array $versionContract): string
    {
        $version = $versionContract['schema_version'] >= 2
            ? ($versionContract['instance_version'] ?? null)
            : ($versionContract['product_release'] ?? null);
        if (!is_string($version)
            || !($versionContract['schema_version'] >= 2
                ? $this->isStrictSemanticVersion($version)
                : $this->isSemanticVersion($version))) {
            throw new RuntimeException('SCAFFOLD_INSTANCE_VERSION_INVALID');
        }
        return $version;
    }

    /** Accept the SemVer surface already supported by scaffold application identities. */
    private function isSemanticVersion(string $version): bool
    {
        return preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $version) === 1;
    }

    private function isStrictSemanticVersion(string $version): bool
    {
        return preg_match(self::STRICT_SEMVER, $version) === 1;
    }

    private function assertV3DependencyIdentity(mixed $php, mixed $web, string $error): void
    {
        if (!is_array($php)
            || array_keys($php) !== ['package', 'constraint', 'resolved_version', 'source_type', 'source_url', 'source_reference']
            || $php['package'] !== 'peanut-admin/core'
            || !is_string($php['constraint'])
            || preg_match('/^dev-[A-Za-z0-9._-]+#[0-9a-f]{40}$/D', $php['constraint']) !== 1
            || !is_string($php['resolved_version']) || !str_starts_with($php['resolved_version'], 'dev-')
            || $php['source_type'] !== 'git'
            || !is_string($php['source_url']) || preg_match('~^https://[^/?#]+/[^?#]+$~D', $php['source_url']) !== 1
            || !is_string($php['source_reference']) || preg_match('/^[0-9a-f]{40}$/D', $php['source_reference']) !== 1
            || !str_ends_with($php['constraint'], '#' . $php['source_reference'])) {
            throw new RuntimeException($error . ': core_php');
        }
        $packages = is_array($web)
            && array_keys($web) === ['source_type', 'source_url', 'source_reference', 'packages']
            ? $web['packages']
            : null;
        if (!is_array($web) || $web['source_type'] !== 'git'
            || !is_string($web['source_url']) || preg_match('~^https://[^/?#]+/[^?#]+$~D', $web['source_url']) !== 1
            || !is_string($web['source_reference']) || preg_match('/^[0-9a-f]{40}$/D', $web['source_reference']) !== 1
            || !is_array($packages) || array_keys($packages) !== self::CORE_WEB_PACKAGES) {
            throw new RuntimeException($error . ': core_web');
        }
        foreach ($packages as $identity) {
            if (!is_array($identity) || array_keys($identity) !== ['version', 'archive', 'sha256']
                || !is_string($identity['version']) || !$this->isStrictSemanticVersion($identity['version'])
                || !is_string($identity['archive'])
                || preg_match('#^packages/core-web/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\.tgz$#D', $identity['archive']) !== 1
                || !is_string($identity['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $identity['sha256']) !== 1) {
                throw new RuntimeException($error . ': core_web');
            }
        }
    }

    private function assertV3ArchiveDigests(string $root, array $web): void
    {
        foreach ($web['packages'] as $identity) {
            $path = ScaffoldPathGuard::projectPath($root, $identity['archive']);
            $digest = is_file($path) && !is_link($path) ? hash_file('sha256', $path) : false;
            if (!is_string($digest) || !hash_equals($identity['sha256'], $digest)) {
                throw new RuntimeException('SCAFFOLD_VERSION_CONTRACT_CORE_WEB_ARCHIVE_INVALID');
            }
        }
    }

    private function releaseIdentity(ScaffoldManifest $manifest): array { return $manifest->release() + ['manifest_sha256' => $manifest->digest()]; }
    private function assertManifestDigest(ScaffoldManifest $manifest, string $digest): void { if (!hash_equals($digest, $manifest->digest())) throw new RuntimeException('SCAFFOLD_MANIFEST_CHECKSUM_DRIFT'); }

    private function applicationManifest(string $root): array
    {
        $path = ScaffoldPathGuard::projectPath($root, '.peanut/application-manifest.json');
        if (!is_file($path) || is_link($path)) throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_MISSING');
        $raw = file_get_contents($path); $data = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
        if (!is_array($data) || !is_array($data['application'] ?? null) || !is_array($data['files'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        $protocol = $data['protocol'] ?? null;
        if (($data['schema_version'] ?? null) === 2 && $protocol === 'peanut.application-scaffold.v2') {
            $version = $data['application']['version'] ?? null;
        } elseif (($data['schema_version'] ?? null) === 1 && $protocol === 'peanut.application-scaffold.v1') {
            $version = $this->legacyApplicationVersion($root);
            $data['application']['version'] = $version;
        } else {
            throw new RuntimeException('SCAFFOLD_APPLICATION_MANIFEST_INVALID');
        }
        if (preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', (string)$version) !== 1) {
            throw new RuntimeException('SCAFFOLD_APPLICATION_VERSION_INVALID');
        }
        return [$data, 'sha256:' . hash('sha256', (string)$raw)];
    }

    private function legacyApplicationVersion(string $root): string
    {
        $path = ScaffoldPathGuard::projectPath($root, 'RELEASE_METADATA.json');
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE');
        }
        try {
            $metadata = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_UNAVAILABLE', 0, $exception);
        }
        $candidates = [];
        if (is_array($metadata)
            && ($metadata['schema_version'] ?? null) === 2
            && ($metadata['protocol'] ?? null) === 'peanut.release-metadata.v2'
            && is_string($metadata['instance_version'] ?? null)) {
            $candidates[] = $metadata['instance_version'];
        }
        if (is_array($metadata) && is_string($metadata['version'] ?? null)) {
            $candidates[] = $metadata['version'];
        }
        if (is_array($metadata['application'] ?? null) && is_string($metadata['application']['version'] ?? null)) {
            $candidates[] = $metadata['application']['version'];
        }
        $candidates = array_values(array_unique($candidates, SORT_STRING));
        if (count($candidates) !== 1
            || preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D', $candidates[0]) !== 1) {
            throw new RuntimeException('SCAFFOLD_LEGACY_APPLICATION_VERSION_AMBIGUOUS');
        }
        return $candidates[0];
    }

    private function regularFileState(string $path, string $relative): array
    {
        if (!file_exists($path)) return ['present' => false, 'sha256' => null, 'mode' => null];
        if (!is_file($path) || is_link($path)) throw new RuntimeException('SCAFFOLD_PATH_TYPE_REJECTED: ' . $relative);
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['nlink'] ?? 0) !== 1) throw new RuntimeException('SCAFFOLD_PATH_HARDLINK_REJECTED: ' . $relative);
        return ['present' => true, 'sha256' => hash_file('sha256', $path), 'mode' => fileperms($path) & 0777];
    }

    private function actionState(string $root, array $actions): array
    {
        $files = [];
        foreach ($actions as $action) $files[$action['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
        ksort($files, SORT_STRING); return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function ownershipState(string $root, array $application, string $classification): array
    {
        $files = [];
        foreach ($application['files'] as $file) if (($file['classification'] ?? null) === $classification) $files[$file['path']] = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
        ksort($files, SORT_STRING); return ['files' => $files, 'digest' => 'sha256:' . hash('sha256', self::canonicalJson($files))];
    }

    private function loadPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_PLAN_PATH_INVALID');
        $data = json_decode((string)file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || ($data['protocol'] ?? null) !== 'peanut.scaffold-upgrade-plan.v2' || preg_match('/^scaffold-[a-f0-9]{24}$/D', (string)($data['candidate'] ?? '')) !== 1) throw new RuntimeException('SCAFFOLD_PLAN_INVALID');
        $expected='scaffold-'.substr(hash('sha256',self::canonicalJson([$data['identity']??null,$data['actions']??null])),0,24);
        if(!hash_equals($expected,$data['candidate']))throw new RuntimeException('SCAFFOLD_PLAN_CHECKSUM_DRIFT');
        $expectedStatus=count(array_filter($data['actions'],static fn(array $action):bool=>($action['conflict']??null)===true))===0?'ready':'blocked';
        if(($data['status']??null)!==$expectedStatus)throw new RuntimeException('SCAFFOLD_PLAN_STATUS_DRIFT');
        if (($data['impact'] ?? null) !== $this->impact($data['actions'])) throw new RuntimeException('SCAFFOLD_PLAN_IMPACT_DRIFT');
        return $data;
    }

    private function loadAdoptionPlan(string $root, string $path): array
    {
        $resolved = ScaffoldPathGuard::existingFileWithin($root, $path, 'SCAFFOLD_ADOPTION_PLAN_PATH_INVALID');
        $data = json_decode((string)file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)
            || ($data['schema_version'] ?? null) !== 1
            || ($data['protocol'] ?? null) !== 'peanut.scaffold-ownership-adoption-plan.v1'
            || ($data['status'] ?? null) !== 'ready'
            || preg_match('/^ownership-adoption-[a-f0-9]{24}$/D', (string)($data['candidate'] ?? '')) !== 1
            || !is_array($data['identity'] ?? null)
            || !is_array($data['actions'] ?? null)
            || !is_array($data['paths'] ?? null)
            || !is_array($data['metadata_writes'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
        }
        $paths = EditionUpgradePackage::OWNERSHIP_ADOPTION_PATHS;
        sort($paths, SORT_STRING);
        $candidateDigest = hash('sha256', self::canonicalJson([$data['identity'], $data['actions']]));
        $metadataWrites = ['.peanut/application-manifest.json'];
        foreach ($data['actions'] as $action) {
            if (!is_array($action) || !is_string($action['path'] ?? null)) throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_INVALID');
            $metadataWrites[] = (string)($action['baseline_path'] ?? '');
        }
        sort($metadataWrites, SORT_STRING);
        if ($data['paths'] !== $paths
            || array_column($data['actions'], 'path') !== $paths
            || $data['metadata_writes'] !== $metadataWrites
            || !hash_equals('ownership-adoption-' . substr($candidateDigest, 0, 24), $data['candidate'])
            || !hash_equals('sha256:' . $candidateDigest, (string)($data['plan_sha256'] ?? ''))
            || !hash_equals('sha256:' . hash('sha256', self::canonicalJson($paths)), (string)($data['paths_sha256'] ?? ''))) {
            throw new RuntimeException('SCAFFOLD_ADOPTION_PLAN_CHECKSUM_DRIFT');
        }
        return $data;
    }

    private function assertActionFresh(string $root, array $action): void
    {
        $actual=$this->regularFileState(ScaffoldPathGuard::projectPath($root,$action['path']),$action['path']);
        if($actual!==$action['current'])throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED: '.$action['path']);
    }

    /** Reject any manifest, version authority, managed, app-owned, or release drift since preflight. */
    private function assertPlanFresh(string $root, array $plan): void
    {
        [$application, $manifestDigest] = $this->applicationManifest($root);
        if (!hash_equals($plan['identity']['application_manifest_sha256'], $manifestDigest)) throw new RuntimeException('SCAFFOLD_PLAN_APPLICATION_LOCK_CHANGED');
        $this->assertPluginProjection($root, $plan);
        [$versionContract, $versionContractDigest] = $this->versionContract($root, $application);
        if ($versionContract !== ($plan['identity']['version_contract'] ?? null)
            || $versionContractDigest !== ($plan['identity']['version_contract_sha256'] ?? null)
        ) {
            throw new RuntimeException('SCAFFOLD_PLAN_VERSION_CONTRACT_CHANGED');
        }
        $managed = $this->actionState($root, $plan['actions']);
        if (!hash_equals($plan['identity']['managed_pre_sha256'], $managed['digest'])) throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        [$application] = $this->applicationManifest($root); $app = $this->ownershipState($root, $application, 'app-owned');
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], $app['digest'])) throw new RuntimeException('SCAFFOLD_PLAN_PROJECT_CHANGED');
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['from']), $plan['identity']['from']['manifest_sha256']);
        $this->assertManifestDigest(ScaffoldManifest::load($plan['manifest_paths']['to']), $plan['identity']['to']['manifest_sha256']);
    }

    /**
     * Revalidate the complete committed target before an idempotent apply/verify returns success.
     *
     * @return array{0:array<string,mixed>,1:array{digest:string,files:array<string,mixed>}}
     */
    private function assertAppliedState(string $root, array $plan): array
    {
        [$application] = $this->applicationManifest($root);
        $to = ScaffoldManifest::load($plan['manifest_paths']['to']);
        $this->assertManifestDigest($to, $plan['identity']['to']['manifest_sha256']);
        if (($application['template']['version'] ?? null) !== $to->version()
            || ($application['template']['source_commit'] ?? null) !== $to->release()['source_commit']
            || ($application['template']['source_tree'] ?? null) !== $to->release()['source_tree']
            || ($application['application']['version'] ?? null) !== $plan['identity']['application_version']
            || ($application['edition'] ?? null) !== ($plan['identity']['edition'] ?? null)) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APPLICATION_IDENTITY_MISMATCH');
        }
        $actualAppOwned = $this->ownershipState(
            $root,
            ['files' => array_values(array_filter(
                $application['files'],
                static fn(array $file): bool => $file['classification'] === 'app-owned',
            ))],
            'app-owned',
        );
        if (!hash_equals($plan['identity']['app_owned_pre_sha256'], $actualAppOwned['digest'])) {
            throw new RuntimeException('SCAFFOLD_VERIFY_APP_OWNED_CHANGED');
        }
        $this->assertPluginProjection($root, $plan);
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed', 'generated-managed'], true)) {
                continue;
            }
            $path = ScaffoldPathGuard::projectPath($root, $file['path']);
            if (!is_file($path) || is_link($path)
                || !hash_equals((string)$file['sha256'], (string)hash_file('sha256', $path))
                || ((fileperms($path) & 0777) !== ($file['mode'] ?? 0644))) {
                throw new RuntimeException('SCAFFOLD_VERIFY_MANAGED_MISMATCH: ' . $file['path']);
            }
        }
        return [$application, $actualAppOwned];
    }

    /** Rebuild the plan from its immutable manifests so edited actions cannot claim another ownership class or path. */
    private function assertPlanRebound(string $root, array $plan): void
    {
        $expected = $this->preview(
            $root,
            (string)$plan['manifest_paths']['from'],
            (string)$plan['manifest_paths']['to'],
        );
        if (!hash_equals((string)$expected['candidate'], (string)$plan['candidate'])) {
            throw new RuntimeException('SCAFFOLD_PLAN_MANIFEST_REBIND_FAILED');
        }
    }

    private function ledger(string $root): ScaffoldUpgradeLedger { return new ScaffoldUpgradeLedger(ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/ledger.ndjson')); }
    private function hasEvent(ScaffoldUpgradeLedger $ledger, string $candidate, string $operation, string $status): bool { foreach ($ledger->entries($candidate) as $entry) if (($entry['operation'] ?? null) === $operation && ($entry['status'] ?? null) === $status) return true; return false; }
    private function candidateState(ScaffoldUpgradeLedger $ledger, string $candidate): ?string { $entries=$ledger->entries($candidate); return $entries === [] ? null : (string)($entries[array_key_last($entries)]['status'] ?? ''); }
    private function event(array $plan, string $operation, string $status, ?string $pre, ?string $post, array $extra = []): array { return ['schema_version'=>2,'candidate'=>$plan['candidate'],'operation'=>$operation,'status'=>$status,'from'=>$plan['identity']['from'],'to'=>$plan['identity']['to'],'pre_sha256'=>$pre,'post_sha256'=>$post] + $extra; }

    private function locked(string $projectRoot, callable $operation): array
    {
        $root = ScaffoldPathGuard::projectRoot($projectRoot); $path = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/project.lock'); ScaffoldPathGuard::ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+b'); if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('SCAFFOLD_PROJECT_LOCK_FAILED');
        try { return $operation($root); } finally { flock($handle, LOCK_UN); fclose($handle); }
    }

    private function createRecovery(string $root, array $plan): string
    {
        $paths = ['.peanut/application-manifest.json'];
        foreach ($plan['actions'] as $action) {
            $paths[] = $action['path'];
            $paths[] = '.peanut/scaffold-baseline/' . $plan['identity']['to']['version'] . '/files/' . $action['path'];
        }
        $paths = array_values(array_unique($paths)); sort($paths, SORT_STRING);
        return $this->createRecoveryForPaths($root, $plan['candidate'], $paths);
    }

    private function createRecoveryForPaths(string $root, string $candidate, array $paths): string
    {
        $directory = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $candidate);
        ScaffoldPathGuard::ensureDirectory($directory . '/files');
        chmod($directory, 0700); chmod($directory . '/files', 0700);
        $files = [];
        foreach ($paths as $relative) {
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $relative), $relative);
            if ($state['present']) {
                $content = file_get_contents(ScaffoldPathGuard::projectPath($root, $relative));
                if (!is_string($content)) throw new RuntimeException('SCAFFOLD_BACKUP_READ_FAILED: ' . $relative);
                $backupRelative = 'files/' . $relative;
                $this->writeFileAtomic($directory . '/' . $backupRelative, $content, 0600);
                $state['backup'] = $backupRelative;
            } else {
                $state['backup'] = null;
            }
            $files[$relative] = $state;
        }
        $recovery = ['schema_version'=>2,'protocol'=>'peanut.scaffold-recovery.v2','candidate'=>$candidate,
            'pre_tree_sha256'=>'sha256:'.hash('sha256',self::canonicalJson($files)),'files'=>$files];
        $path = $directory . '/recovery.json';
        if (is_file($path)) {
            $existing = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if ($existing !== $recovery) throw new RuntimeException('SCAFFOLD_RECOVERY_COLLISION');
        } else {
            $this->writeJsonAtomic($path, $recovery, 0600);
        }
        return $this->relative($root, $path);
    }

    private function recoverCandidate(string $root, array $plan, string $operation): array
    {
        $ledger = $this->ledger($root);
        $manifestPath = ScaffoldPathGuard::projectPath($root, '.peanut/upgrades/backups/' . $plan['candidate'] . '/recovery.json');
        if (!is_file($manifestPath)) throw new RuntimeException('SCAFFOLD_RECOVERY_NOT_FOUND');
        $recovery = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($recovery) || ($recovery['candidate'] ?? null) !== $plan['candidate'] || !is_array($recovery['files'] ?? null)
            || !hash_equals((string)($recovery['pre_tree_sha256'] ?? ''), 'sha256:' . hash('sha256', self::canonicalJson($recovery['files'])))) {
            throw new RuntimeException('SCAFFOLD_RECOVERY_INVALID');
        }
        $already = $this->recoveryMatches($root, $recovery);
        if (!$already) {
            foreach ($recovery['files'] as $relative => $state) {
                $target = ScaffoldPathGuard::projectPath($root, (string)$relative);
                if ($state['present']) {
                    $backup = ScaffoldPathGuard::existingFileWithin(dirname($manifestPath), dirname($manifestPath) . '/' . $state['backup'], 'SCAFFOLD_RECOVERY_BACKUP_INVALID');
                    $content = file_get_contents($backup);
                    if (!is_string($content) || !hash_equals($state['sha256'], hash('sha256', $content))) throw new RuntimeException('SCAFFOLD_RECOVERY_BACKUP_DRIFT');
                    $this->writeFileAtomic($target, $content, (int)$state['mode']);
                } elseif (file_exists($target)) {
                    if (!is_file($target) || is_link($target)) throw new RuntimeException('SCAFFOLD_RECOVERY_PATH_COLLISION: ' . $relative);
                    unlink($target);
                    $this->pruneEmptyParents(dirname($target), $root);
                }
            }
            if (!$this->recoveryMatches($root, $recovery)) throw new RuntimeException('SCAFFOLD_RECOVERY_VERIFY_FAILED');
        }
        $this->assertPluginProjection($root, $plan);
        if (!$this->hasEvent($ledger, $plan['candidate'], $operation, 'recovered')) {
            $ledger->append($this->event($plan, $operation, 'recovered', $plan['identity']['managed_pre_sha256'], $plan['identity']['managed_pre_sha256']));
        }
        return ['status' => 'recovered', 'candidate' => $plan['candidate'], 'tree_sha256' => $recovery['pre_tree_sha256'], 'idempotent' => $already];
    }

    /** Advance the scaffold identity and snapshot the current version used for this target rendering. */
    private function nextApplicationManifest(string $root, array $plan, ScaffoldManifest $to): array
    {
        [$application] = $this->applicationManifest($root);
        $oldByPath = [];
        foreach ($application['files'] as $file) $oldByPath[$file['path']] = $file;
        $managedPaths = array_column($plan['actions'], 'path');
        $files = [];
        foreach ($application['files'] as $file) {
            if (!in_array($file['classification'], ['managed','generated-managed'], true)) {
                $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $file['path']), $file['path']);
                $file['sha256'] = $state['sha256']; $file['mode'] = $state['mode'];
                $files[] = $file;
            }
        }
        foreach ($plan['actions'] as $action) {
            if (in_array($action['action'], ['delete', 'omit'], true)) continue;
            $state = $this->regularFileState(ScaffoldPathGuard::projectPath($root, $action['path']), $action['path']);
            if (!$state['present']) throw new RuntimeException('SCAFFOLD_APPLY_MANAGED_MISSING: ' . $action['path']);
            $files[] = ['path'=>$action['path'],'sha256'=>$state['sha256'],'mode'=>$state['mode'],'classification'=>$action['classification'],
                'owner'=>'scaffold','source'=>$action['path'],'baseline_path'=>'.peanut/scaffold-baseline/'.$to->version().'/files/'.$action['path'],
                'baseline_sha256'=>$action['target_sha256']];
        }
        usort($files, static fn(array $a,array $b): int => strcmp($a['path'],$b['path']));
        $managed = array_values(array_filter($files, static fn(array $f): bool => in_array($f['classification'],['managed','generated-managed'],true)));
        $appOwned = array_values(array_filter($files, static fn(array $f): bool => $f['classification']==='app-owned'));
        $application['template'] = ['version'=>$to->version(),'inventory_sha256'=>$to->release()['inventory_sha256'],
            'source_commit'=>$to->release()['source_commit'],'source_tree'=>$to->release()['source_tree']];
        $edition = $plan['identity']['edition'] ?? null;
        if (is_array($edition)) {
            $application['edition'] = $edition;
        }
        $application['schema_version'] = 2;
        $application['protocol'] = 'peanut.application-scaffold.v2';
        $application['application']['version'] = $plan['identity']['application_version'];
        $application['ownership']['baseline_root'] = '.peanut/scaffold-baseline/' . $to->version() . '/files';
        $application['digests'] = ['managed_tree_sha256'=>$this->manifestTreeDigest($managed),'app_owned_tree_sha256'=>$this->manifestTreeDigest($appOwned)];
        $application['files'] = $files;
        $application['last_scaffold_upgrade'] = [
            'candidate'=>$plan['candidate'],
            'from'=>$plan['identity']['from']['version'],
            'to'=>$to->version(),
        ];
        if (is_array($edition)) {
            $application['last_scaffold_upgrade']['edition_profile_sha256'] = $edition['source_sha256'];
            $application['last_scaffold_upgrade']['tenant_bootstrap'] = $edition['tenant_bootstrap'];
        }
        return $application;
    }

    private function managedDigestFromManifest(string $root, array $manifest): string
    {
        $files=[];
        foreach($manifest['files'] as $file) if(in_array($file['classification'],['managed','generated-managed'],true)) $files[$file['path']]=$this->regularFileState(ScaffoldPathGuard::projectPath($root,$file['path']),$file['path']);
        ksort($files,SORT_STRING); return 'sha256:'.hash('sha256',self::canonicalJson($files));
    }
    private function manifestTreeDigest(array $files): string { $rows=array_map(static fn(array $f):string=>$f['path']."\0".$f['sha256'],$files); sort($rows,SORT_STRING); return hash('sha256',implode("\n",$rows)); }
    private function recoveryMatches(string $root, array $recovery): bool { foreach ($recovery['files'] as $relative=>$state) { $actual=$this->regularFileState(ScaffoldPathGuard::projectPath($root,(string)$relative),(string)$relative); if ($actual['present'] !== $state['present'] || $actual['sha256'] !== $state['sha256'] || $actual['mode'] !== $state['mode']) return false; } return true; }

    private function writeFileAtomic(string $path, string $content, int $mode): void
    {
        ScaffoldPathGuard::ensureDirectory(dirname($path)); $tmp=dirname($path).'/.'.basename($path).'.stage-'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp,$content,LOCK_EX)===false || !chmod($tmp,$mode) || !rename($tmp,$path)) { @unlink($tmp); throw new RuntimeException('SCAFFOLD_ATOMIC_WRITE_FAILED: '.$path); }
    }
    private function pruneEmptyParents(string $directory,string $root): void
    {
        while($directory!==$root&&str_starts_with($directory,$root.DIRECTORY_SEPARATOR)){
            if(!is_dir($directory)||is_link($directory)||(scandir($directory)?:[])!==['.','..']||!rmdir($directory))return;
            $directory=dirname($directory);
        }
    }
    private function writeJsonAtomic(string $path, array $data, int $mode): void { $this->writeFileAtomic($path,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n",$mode); }
    private function relative(string $root,string $path): string { return str_replace(DIRECTORY_SEPARATOR,'/',substr($path,strlen($root)+1)); }
    private static function canonicalJson(array $value): string { self::sortRecursive($value); return json_encode($value,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    private static function sortRecursive(array &$value): void { if(!array_is_list($value))ksort($value,SORT_STRING); foreach($value as &$item)if(is_array($item))self::sortRecursive($item); }
}
