<?php
declare(strict_types=1);

namespace PeanutAdmin\Modules\Ops\Service;

use app\platform\infrastructure\plugin\PluginPackageInstaller;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use app\platform\infrastructure\plugin\ModuleCatalogApplier;
use think\facade\Db;

/** Creates immutable, registry-bound requests before Platform submission. */
final readonly class DeploymentModuleRequestService
{
    /** @param array<string,mixed> $moduleConfig @param array<string,string> $trustedKeys */
    public function __construct(
        private string $projectRoot,
        private array $moduleConfig,
        private array $trustedKeys,
        private PluginRuntimeGovernanceService $governance,
        private ModuleCatalogApplier $catalogs,
        private ?string $registryPath = null,
    ) {
    }

    /** @return array<string,mixed> */
    public function preview(
        string $deliveryResourceId,
        string $targetResourceId,
        string $operation,
        string $packageKey,
        ?string $archiveSha256,
        ?string $signatureKeyId,
    ): array {
        $resource = $this->deliveryResource($deliveryResourceId, $targetResourceId);
        $operation = $this->operation($operation);
        $packageKey = $this->packageKey($packageKey);
        $archiveSha256 = $this->archiveSha($operation, $archiveSha256);
        $signatureKeyId = $this->signatureKeyId($signatureKeyId);

        if ($operation === 'update') {
            $archive = $this->archivePath($resource, (string)$archiveSha256);
            $plan = (new PluginPackageInstaller(
                $this->projectRoot . '/server',
                $this->moduleConfig,
                $this->trustedKeys,
                $this->catalogs,
            ))->update($archive, $archiveSha256, $signatureKeyId, true);
            if (!hash_equals($packageKey, (string)($plan['package_key'] ?? ''))) {
                throw new \RuntimeException('OPS_MODULE_PACKAGE_IDENTITY_MISMATCH');
            }
            return [
                'operation' => $operation,
                'package_key' => $packageKey,
                'archive_sha256' => $archiveSha256,
                'signature_key_id' => $signatureKeyId,
                'plan' => $plan,
                'plan_digest' => $this->digest($plan),
            ];
        }

        $plan = $this->governance->preview($packageKey, $operation === 'purge');
        if (($plan['blockers'] ?? []) !== []) {
            throw new \RuntimeException('OPS_MODULE_PREFLIGHT_BLOCKED');
        }
        return [
            'operation' => $operation,
            'package_key' => (string)($plan['confirm_plan']['package_key'] ?? $packageKey),
            'archive_sha256' => null,
            'signature_key_id' => null,
            'plan' => $plan['confirm_plan'],
            'plan_digest' => (string)$plan['plan_digest'],
        ];
    }

    /** @return array<string,mixed> */
    public function prepare(
        string $deliveryResourceId,
        string $targetResourceId,
        string $operation,
        string $packageKey,
        ?string $archiveSha256,
        ?string $signatureKeyId,
        ?string $confirmPlanDigest,
    ): array {
        $resource = $this->deliveryResource($deliveryResourceId, $targetResourceId);
        $preview = $this->preview(
            $deliveryResourceId,
            $targetResourceId,
            $operation,
            $packageKey,
            $archiveSha256,
            $signatureKeyId,
        );
        if ($preview['operation'] !== 'update') {
            if (!is_string($confirmPlanDigest)
                || preg_match('/^[a-f0-9]{64}$/D', $confirmPlanDigest) !== 1
                || !hash_equals((string)$preview['plan_digest'], $confirmPlanDigest)
            ) {
                throw new \RuntimeException('OPS_MODULE_CONFIRM_PLAN_REQUIRED');
            }
        } elseif ($confirmPlanDigest !== null && $confirmPlanDigest !== '') {
            throw new \RuntimeException('OPS_MODULE_CONFIRM_PLAN_INVALID');
        }

        $environment = $this->singleEnvironment($resource['environments'] ?? null);
        $document = [
            'schema_version' => 1,
            'environment' => $environment,
            'target_resource_id' => $targetResourceId,
            'delivery_resource_id' => $deliveryResourceId,
            'operation' => (string)$preview['operation'],
            'package_key' => (string)$preview['package_key'],
            'archive_sha256' => $preview['archive_sha256'],
            'signature_key_id' => $preview['signature_key_id'],
            'confirm_plan' => $preview['operation'] === 'update' ? null : $preview['plan'],
            'confirm_plan_sha256' => $preview['operation'] === 'update' ? null : $preview['plan_digest'],
        ];
        $requestSha = $this->digest($document);
        $requestKey = 'modreq_' . substr($requestSha, 0, 32);
        $document['request_key'] = $requestKey;
        $document['request_sha256'] = $requestSha;
        $json = $this->canonicalJson($document) . "\n";
        $this->writeRequestManifest($resource, $requestKey, $json);

        Db::name('ops_module_request')->insertOrIgnore([
            'request_key' => $requestKey,
            'environment' => $environment,
            'target_resource_id' => $targetResourceId,
            'delivery_resource_id' => $deliveryResourceId,
            'operation' => $preview['operation'],
            'package_key' => $preview['package_key'],
            'archive_sha256' => $preview['archive_sha256'],
            'signature_key_id' => $preview['signature_key_id'],
            'confirm_plan_json' => $preview['operation'] === 'update'
                ? null : $this->canonicalJson($preview['plan']),
            'confirm_plan_sha256' => $preview['operation'] === 'update'
                ? null : $preview['plan_digest'],
            'request_sha256' => $requestSha,
            'state' => 'prepared',
        ]);
        return $document;
    }

    /** @return array<string,mixed> */
    public function assertPrepared(string $requestKey): array
    {
        if (preg_match('/^modreq_[a-f0-9]{32}$/D', $requestKey) !== 1) {
            throw new \RuntimeException('OPS_MODULE_REQUEST_KEY_INVALID');
        }
        $row = Db::name('ops_module_request')->where('request_key', $requestKey)
            ->whereIn('state', ['prepared', 'claimed'])->find();
        if (!is_array($row)) {
            throw new \RuntimeException('OPS_MODULE_REQUEST_UNAVAILABLE');
        }
        $resource = $this->deliveryResource(
            (string)$row['delivery_resource_id'],
            (string)$row['target_resource_id'],
        );
        $path = $this->requestManifestPath($resource, $requestKey);
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new \RuntimeException('OPS_MODULE_REQUEST_UNAVAILABLE');
        }
        $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($document)
            || !hash_equals((string)$row['request_sha256'], $this->digest(array_diff_key(
                $document,
                ['request_key' => true, 'request_sha256' => true],
            )))
            || !hash_equals($requestKey, (string)($document['request_key'] ?? ''))
        ) {
            throw new \RuntimeException('OPS_MODULE_REQUEST_IDENTITY_MISMATCH');
        }
        if ($row['archive_sha256'] !== null) {
            $this->archivePath($resource, (string)$row['archive_sha256']);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    public function execute(string $requestKey): array
    {
        $request = $this->assertPrepared($requestKey);
        $resource = $this->deliveryResource(
            (string)$request['delivery_resource_id'],
            (string)$request['target_resource_id'],
        );
        if ((string)$request['operation'] === 'update') {
            return (new PluginPackageInstaller(
                $this->projectRoot . '/server',
                $this->moduleConfig,
                $this->trustedKeys,
                $this->catalogs,
            ))->update(
                $this->archivePath($resource, (string)$request['archive_sha256']),
                (string)$request['archive_sha256'],
                $request['signature_key_id'] === null ? null : (string)$request['signature_key_id'],
                false,
            );
        }
        $plan = json_decode((string)$request['confirm_plan_json'], true, 128, JSON_THROW_ON_ERROR);
        if (!is_array($plan) || array_is_list($plan)) {
            throw new \RuntimeException('OPS_MODULE_CONFIRM_PLAN_INVALID');
        }
        return $this->governance->uninstall(
            (string)$request['package_key'],
            (string)$request['operation'] === 'purge',
            $plan,
            (string)$request['confirm_plan_sha256'],
        );
    }

    /** @return array<string,mixed> */
    private function deliveryResource(string $deliveryResourceId, string $targetResourceId): array
    {
        $registryPath = $this->registryPath ?? $this->projectRoot . '/resources/project-resources.json';
        $registry = json_decode((string)file_get_contents($registryPath), true, 512, JSON_THROW_ON_ERROR);
        $resource = $this->resource($registry, $deliveryResourceId);
        $target = $this->resource($registry, $targetResourceId);
        if (($resource['service_type'] ?? null) !== 'operator-triggered repository CLI worker over registered SSH deployment transport'
            || ($resource['deployment_resource_id'] ?? null) !== $targetResourceId
            || ($resource['deployment_root'] ?? null) !== ($target['deployment_root'] ?? null)
            || realpath($this->projectRoot) !== realpath((string)$resource['deployment_root'])
            || ($resource['fallback'] ?? null) !== 'none'
        ) {
            throw new \RuntimeException('OPS_MODULE_RESOURCE_BOUNDARY_INVALID');
        }
        return $resource;
    }

    /** @param array<string,mixed> $node @return array<string,mixed> */
    private function resource(array $node, string $id): array
    {
        if (($node['stable_resource_id'] ?? null) === $id) {
            return $node;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                try {
                    return $this->resource($value, $id);
                } catch (\RuntimeException $exception) {
                    if ($exception->getMessage() !== 'OPS_MODULE_RESOURCE_NOT_REGISTERED') {
                        throw $exception;
                    }
                }
            }
        }
        throw new \RuntimeException('OPS_MODULE_RESOURCE_NOT_REGISTERED');
    }

    /** @param array<string,mixed> $resource */
    private function archivePath(array $resource, string $sha256): string
    {
        $directory = (string)($resource['package_directory'] ?? '');
        $path = $directory . '/' . $sha256 . '.tar';
        if (!is_dir($directory) || is_link($directory) || !is_file($path) || is_link($path)
            || realpath(dirname($path)) !== realpath($directory)
            || !hash_equals($sha256, (string)hash_file('sha256', $path))
        ) {
            throw new \RuntimeException('OPS_MODULE_ARCHIVE_IDENTITY_INVALID');
        }
        return $path;
    }

    /** @param array<string,mixed> $resource */
    private function writeRequestManifest(array $resource, string $requestKey, string $json): void
    {
        $directory = (string)($resource['request_directory'] ?? '');
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException('OPS_MODULE_REQUEST_DIRECTORY_INVALID');
        }
        $path = $this->requestManifestPath($resource, $requestKey);
        if (is_file($path)) {
            if (!hash_equals(hash('sha256', $json), (string)hash_file('sha256', $path))) {
                throw new \RuntimeException('OPS_MODULE_REQUEST_IDENTITY_MISMATCH');
            }
            return;
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
            || !chmod($temporary, 0600)
            || !rename($temporary, $path)
        ) {
            @unlink($temporary);
            throw new \RuntimeException('OPS_MODULE_REQUEST_WRITE_FAILED');
        }
    }

    /** @param array<string,mixed> $resource */
    private function requestManifestPath(array $resource, string $requestKey): string
    {
        return rtrim((string)($resource['request_directory'] ?? ''), '/') . '/' . $requestKey . '.json';
    }

    private function operation(string $operation): string
    {
        if (!in_array($operation, ['update', 'retire', 'purge'], true)) {
            throw new \RuntimeException('OPS_MODULE_OPERATION_INVALID');
        }
        return $operation;
    }

    private function packageKey(string $packageKey): string
    {
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $packageKey) !== 1) {
            throw new \RuntimeException('OPS_MODULE_PACKAGE_KEY_INVALID');
        }
        return $packageKey;
    }

    private function archiveSha(string $operation, ?string $sha256): ?string
    {
        $sha256 = $sha256 === null ? '' : trim($sha256);
        if (($operation === 'update' && preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1)
            || ($operation !== 'update' && $sha256 !== '')
        ) {
            throw new \RuntimeException('OPS_MODULE_ARCHIVE_REFERENCE_INVALID');
        }
        return $sha256 === '' ? null : $sha256;
    }

    private function signatureKeyId(?string $keyId): ?string
    {
        $keyId = $keyId === null ? '' : trim($keyId);
        if ($keyId !== '' && preg_match('/^[A-Za-z0-9._-]{1,128}$/D', $keyId) !== 1) {
            throw new \RuntimeException('OPS_MODULE_SIGNATURE_KEY_INVALID');
        }
        return $keyId === '' ? null : $keyId;
    }

    private function singleEnvironment(mixed $environments): string
    {
        if (!is_array($environments) || count($environments) !== 1 || !is_string($environments[0])) {
            throw new \RuntimeException('OPS_MODULE_RESOURCE_BOUNDARY_INVALID');
        }
        return $environments[0];
    }

    private function digest(array $value): string
    {
        return hash('sha256', $this->canonicalJson($value));
    }

    private function canonicalJson(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $normalize($child);
            }
            return $item;
        };
        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
