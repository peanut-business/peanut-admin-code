<?php
declare(strict_types=1);

namespace app\common\value\installation;

use JsonException;
use RuntimeException;

/** Read a deployed root or generated-instance contract while keeping source and instance identities separate. */
final readonly class ApplicationReleaseVersions
{
    private const STRICT_SEMVER = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*))*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/D';
    private const V1_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:[-+][0-9A-Za-z.-]+)?$/D';
    private const V1_KEYS = [
        'schema_version',
        'protocol',
        'product_release',
        'scaffold_template',
        'generated_application_default',
        'core_php',
        'core_web',
    ];
    private const V2_KEYS = [
        'schema_version',
        'protocol',
        'source_product_version',
        'instance_version',
        'scaffold_template',
        'generated_instance_default',
        'core_php',
        'core_web',
    ];
    private const V3_KEYS = self::V2_KEYS;
    private const CORE_WEB_PACKAGES = [
        '@peanut-admin/client', '@peanut-admin/vue', '@peanut-admin/ui-vue',
        '@peanut-admin/nuxt', '@peanut-admin/uniapp', '@peanut-admin/testing',
    ];

    private function __construct(private array $values)
    {
    }

    /** Load exactly the supported fields while accepting harmless JSON key-order differences. */
    public static function load(string $path): self
    {
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID');
        }
        try {
            $document = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID', 0, $exception);
        }
        $keys = is_array($document) ? array_keys($document) : [];
        $v3 = is_array($document)
            && ($document['schema_version'] ?? null) === 3
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v3';
        $v2 = is_array($document)
            && ($document['schema_version'] ?? null) === 2
            && ($document['protocol'] ?? null) === 'peanut.release-versions.v2';
        $expectedKeys = $v3 ? self::V3_KEYS : ($v2 ? self::V2_KEYS : self::V1_KEYS);
        if (!is_array($document) || count($keys) !== count($expectedKeys)
            || array_diff($keys, $expectedKeys) !== [] || array_diff($expectedKeys, $keys) !== []
            || (!$v2 && !$v3 && (($document['schema_version'] ?? null) !== 1
                || ($document['protocol'] ?? null) !== 'peanut.release-versions.v1'))) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID');
        }
        $values = [];
        foreach ($expectedKeys as $key) {
            $value = $document[$key];
            if ($key === 'instance_version' && $value === null) {
                $values[$key] = null;
                continue;
            }
            if ($v3 && in_array($key, ['core_php', 'core_web'], true)) {
                $values[$key] = $value;
                continue;
            }
            if (!in_array($key, ['schema_version', 'protocol'], true)
                && (!is_string($value)
                    || preg_match(($v2 || $v3) ? self::STRICT_SEMVER : self::V1_VERSION, $value) !== 1)) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_INVALID: ' . $key);
            }
            $values[$key] = $value;
        }
        if ($v2 && ($values['source_product_version'] !== $values['core_php']
                || $values['source_product_version'] !== $values['core_web']
                || $values['source_product_version'] !== $values['scaffold_template'])) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_PRODUCT_CORE_MISMATCH');
        }
        if ($v3) {
            self::assertV3Dependencies($values['core_php'], $values['core_web'], dirname($path));
            if ($values['source_product_version'] !== $values['scaffold_template']) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_PRODUCT_TEMPLATE_MISMATCH');
            }
        }
        return new self($values);
    }

    public function sourceProductVersion(): string
    {
        return $this->values['schema_version'] >= 2
            ? $this->values['source_product_version']
            : $this->values['product_release'];
    }

    public function instanceVersion(): ?string
    {
        return $this->values['schema_version'] >= 2 ? $this->values['instance_version'] : null;
    }

    public function releaseSequenceVersion(): string
    {
        return $this->instanceVersion() ?? $this->sourceProductVersion();
    }

    /** Default version for a new instance; it is never the running instance identity. */
    public function generatedInstanceDefault(): string
    {
        return $this->values['schema_version'] >= 2
            ? $this->values['generated_instance_default']
            : $this->values['generated_application_default'];
    }

    public function scaffoldTemplate(): string
    {
        return $this->values['scaffold_template'];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->values;
    }

    private static function assertV3Dependencies(mixed $php, mixed $web, string $root): void
    {
        $phpKeys = ['package', 'constraint', 'resolved_version', 'source_type', 'source_url', 'source_reference'];
        if (!is_array($php) || array_keys($php) !== $phpKeys
            || $php['package'] !== 'peanut-admin/core'
            || !is_string($php['constraint']) || preg_match('/^dev-[A-Za-z0-9._-]+$/D', $php['constraint']) !== 1
            || !is_string($php['resolved_version']) || !str_starts_with($php['resolved_version'], 'dev-')
            || $php['source_type'] !== 'git'
            || !is_string($php['source_url']) || preg_match('~^https://[^/?#]+/[^?#]+$~D', $php['source_url']) !== 1
            || !is_string($php['source_reference']) || preg_match('/^[0-9a-f]{40}$/D', $php['source_reference']) !== 1
            || $php['constraint'] !== $php['resolved_version']) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_CORE_PHP_INVALID');
        }
        $webKeys = ['source_type', 'source_url', 'source_reference', 'packages'];
        $packages = is_array($web) && array_keys($web) === $webKeys ? $web['packages'] : null;
        if (!is_array($web) || $web['source_type'] !== 'git'
            || !is_string($web['source_url']) || preg_match('~^https://[^/?#]+/[^?#]+$~D', $web['source_url']) !== 1
            || !is_string($web['source_reference']) || preg_match('/^[0-9a-f]{40}$/D', $web['source_reference']) !== 1) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_CORE_WEB_INVALID');
        }
        if (!is_array($packages) || array_keys($packages) !== self::CORE_WEB_PACKAGES) {
            throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_CORE_WEB_INVALID');
        }
        foreach ($packages as $identity) {
            if (!is_array($identity) || array_keys($identity) !== ['version', 'archive', 'sha256']
                || !is_string($identity['version']) || preg_match(self::STRICT_SEMVER, $identity['version']) !== 1
                || !is_string($identity['archive'])
                || preg_match('#^packages/core-web/peanut-admin-[a-z-]+-[0-9A-Za-z.+-]+\.tgz$#D', $identity['archive']) !== 1
                || !is_string($identity['sha256']) || preg_match('/^[0-9a-f]{64}$/D', $identity['sha256']) !== 1) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_CORE_WEB_INVALID');
            }
            $archive = $root . '/' . $identity['archive'];
            $digest = is_file($archive) && !is_link($archive) ? hash_file('sha256', $archive) : false;
            if (!is_string($digest) || !hash_equals($identity['sha256'], $digest)) {
                throw new RuntimeException('APPLICATION_RELEASE_VERSIONS_CORE_WEB_ARCHIVE_INVALID');
            }
        }
    }
}
