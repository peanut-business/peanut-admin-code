<?php

declare(strict_types=1);

namespace app\common\value\installation;

use JsonException;
use RuntimeException;

/** Strict identity reader for generated/source application layouts without a server release identity. */
final readonly class ApplicationSourceIdentity
{
    /** @param array<string,mixed> $application @param array<string,mixed> $versions */
    private function __construct(
        private array $application,
        private array $versions,
        private array $tenantBootstrap,
    ) {}

    public static function load(string $serverRoot): self
    {
        $projectRoot = dirname($serverRoot);
        $manifest = self::jsonFile($projectRoot . '/.peanut/application-manifest.json', 'APPLICATION_SOURCE_MANIFEST_INVALID');
        $application = $manifest['application'] ?? null;
        $edition = $manifest['edition'] ?? null;
        if (!is_array($application)
            || !is_array($edition)
            || ($manifest['schema_version'] ?? null) !== 2
            || ($manifest['protocol'] ?? null) !== 'peanut.application-scaffold.v2'
            || !is_string($application['name'] ?? null)
            || trim($application['name']) === ''
            || !is_string($application['slug'] ?? null)
            || preg_match('/^[a-z][a-z0-9-]{0,62}[a-z0-9]$/D', $application['slug']) !== 1
            || !is_string($application['package_identity'] ?? null)
            || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $application['package_identity']) !== 1
            || !is_string($application['version'] ?? null)
            || !in_array($application['edition'] ?? null, ['standalone', 'multi-tenant'], true)
            || !in_array($application['profile'] ?? null, ['minimal', 'standard', 'full'], true)
            || ($edition['name'] ?? null) !== $application['edition']
            || ($edition['deployment_mode'] ?? null) !== $application['edition']
            || !is_array($edition['tenant_bootstrap'] ?? null)) {
            throw new RuntimeException('APPLICATION_SOURCE_IDENTITY_INVALID');
        }

        // Preserve the existing installer's binding between the selected Edition
        // and its immutable generation or accepted upgrade identity.
        $profileDigest = $edition['source_sha256'] ?? null;
        $expectedDigest = isset($manifest['last_scaffold_upgrade'])
            ? ($manifest['last_scaffold_upgrade']['edition_profile_sha256'] ?? null)
            : ($manifest['generation_source']['edition_profile_sha256'] ?? null);
        if (!is_string($profileDigest) || !is_string($expectedDigest)
            || preg_match('/^[a-f0-9]{64}$/D', $profileDigest) !== 1
            || !hash_equals($profileDigest, $expectedDigest)) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }

        $versions = ApplicationReleaseVersions::load($projectRoot . '/release-versions.json');
        if (!hash_equals($application['version'], $versions->releaseSequenceVersion())) {
            throw new RuntimeException('APPLICATION_SOURCE_VERSION_MISMATCH');
        }

        return new self(
            [
                'slug' => $application['slug'],
                'edition' => $application['edition'],
                'version' => $application['version'],
                'profile' => $application['profile'],
                'package_identity' => $application['package_identity'],
                'name' => $application['name'],
            ],
            [
                'source_product_version' => $versions->sourceProductVersion(),
                'release_sequence_version' => $versions->releaseSequenceVersion(),
                'scaffold_template' => $versions->scaffoldTemplate(),
            ],
            $edition['tenant_bootstrap'],
        );
    }

    /** @return array<string,mixed> */
    public function applicationIdentity(): array
    {
        return $this->application;
    }

    /** @return array<string,mixed> */
    public function versions(): array
    {
        return $this->versions;
    }

    /** @return array<string,mixed> */
    public function tenantBootstrapContract(string $deploymentMode): array
    {
        if ($deploymentMode !== $this->application['edition']) {
            throw new RuntimeException('INSTALL_EDITION_MANIFEST_INVALID');
        }
        return $this->tenantBootstrap;
    }

    public function applicationSlug(): string
    {
        return $this->application['slug'];
    }

    /** @return array<string,mixed> */
    private static function jsonFile(string $path, string $error): array
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException($error);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException($error, 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException($error);
        }
        return $value;
    }
}
