<?php
declare(strict_types=1);

namespace app\platform\value\ops;

use app\modules\official\ops\infrastructure\PairedBackupProvider;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Versioned, path-free contract for one verified database/files backup pair.
 *
 * Artifact bytes remain owned by a trusted deployment adapter. This value
 * object accepts only their safe identity and integrity projection.
 */
final readonly class PairedBackupManifest
{
    public const SCHEMA_VERSION = 1;
    public const DATABASE_ARTIFACT = 'database.sql.gz';
    public const FILES_ARTIFACT = 'php-storage.tar.gz';
    private const CAPACITY_HEADROOM_BYTES = 1073741824;

    /** @param array<string, mixed> $manifest */
    private function __construct(private array $manifest)
    {
    }

    public static function fromJson(string $json): self
    {
        if ($json === '' || strlen($json) > 65536) {
            self::invalid();
        }
        try {
            $manifest = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::invalid();
        }
        if (!is_array($manifest)) {
            self::invalid();
        }
        return self::fromArray($manifest);
    }

    /** @param array<string, mixed> $manifest */
    public static function fromArray(array $manifest): self
    {
        self::exactKeys($manifest, [
            'schema_version',
            'backup_reference_key',
            'provider_key',
            'source',
            'resources',
            'runtime',
            'consistency_window',
            'capacity_preflight',
            'artifacts',
            'responsibility',
        ]);
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || !is_string($manifest['backup_reference_key'] ?? null)
            || preg_match('/^backup_[a-f0-9]{32}$/D', $manifest['backup_reference_key']) !== 1
            || ($manifest['provider_key'] ?? null) !== PairedBackupProvider::PROVIDER_KEY
        ) {
            self::invalid();
        }

        $source = self::map($manifest['source'] ?? null);
        self::exactKeys($source, ['commit', 'tree', 'release_key']);
        self::commit($source['commit'] ?? null);
        self::commit($source['tree'] ?? null);
        if (($source['release_key'] ?? null) !== null
            && (!is_string($source['release_key'])
                || preg_match('/^v(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)$/D', $source['release_key']) !== 1)
        ) {
            self::invalid();
        }

        $resources = self::map($manifest['resources'] ?? null);
        self::exactKeys($resources, ['deployment', 'database', 'application', 'backup']);
        foreach ($resources as $resourceId) {
            self::stableKey($resourceId, 128);
        }

        $runtime = self::map($manifest['runtime'] ?? null);
        self::exactKeys($runtime, [
            'compose_project',
            'compose_file',
            'compose_profile',
            'database_name',
            'storage_volume',
            'images',
        ]);
        self::stableKey($runtime['compose_project'] ?? null, 64);
        self::basename($runtime['compose_file'] ?? null);
        self::stableKey($runtime['compose_profile'] ?? null, 64);
        self::databaseName($runtime['database_name'] ?? null);
        self::storageVolume($runtime['storage_volume'] ?? null);
        $images = self::images($runtime['images'] ?? null);

        $window = self::map($manifest['consistency_window'] ?? null);
        self::exactKeys($window, ['mode', 'started_at', 'completed_at']);
        if (($window['mode'] ?? null) !== 'application-write-quiescence') {
            self::invalid();
        }
        $startedAt = self::instant($window['started_at'] ?? null);
        $completedAt = self::instant($window['completed_at'] ?? null);
        if ($completedAt < $startedAt) {
            self::invalid();
        }

        $capacity = self::map($manifest['capacity_preflight'] ?? null);
        self::exactKeys($capacity, ['source_bytes', 'required_bytes', 'available_bytes', 'available_inodes']);
        $sourceBytes = self::nonNegativeInteger($capacity['source_bytes'] ?? null);
        $requiredBytes = self::positiveInteger($capacity['required_bytes'] ?? null);
        $availableBytes = self::positiveInteger($capacity['available_bytes'] ?? null);
        self::positiveInteger($capacity['available_inodes'] ?? null);
        if ($sourceBytes > intdiv(PHP_INT_MAX - self::CAPACITY_HEADROOM_BYTES, 2)
            || $requiredBytes !== ($sourceBytes * 2) + self::CAPACITY_HEADROOM_BYTES
            || $availableBytes <= $requiredBytes
        ) {
            self::invalid();
        }

        $artifacts = self::artifacts($manifest['artifacts'] ?? null);

        $responsibility = self::map($manifest['responsibility'] ?? null);
        self::exactKeys($responsibility, ['retention_owner', 'cleanup_owner', 'restore_owner']);
        foreach ($responsibility as $ownerKey) {
            self::stableKey($ownerKey, 96);
        }

        return new self([
            'schema_version' => self::SCHEMA_VERSION,
            'backup_reference_key' => $manifest['backup_reference_key'],
            'provider_key' => PairedBackupProvider::PROVIDER_KEY,
            'source' => [
                'commit' => $source['commit'],
                'tree' => $source['tree'],
                'release_key' => $source['release_key'],
            ],
            'resources' => [
                'deployment' => $resources['deployment'],
                'database' => $resources['database'],
                'application' => $resources['application'],
                'backup' => $resources['backup'],
            ],
            'runtime' => [
                'compose_project' => $runtime['compose_project'],
                'compose_file' => $runtime['compose_file'],
                'compose_profile' => $runtime['compose_profile'],
                'database_name' => $runtime['database_name'],
                'storage_volume' => $runtime['storage_volume'],
                'images' => $images,
            ],
            'consistency_window' => [
                'mode' => 'application-write-quiescence',
                'started_at' => $window['started_at'],
                'completed_at' => $window['completed_at'],
            ],
            'capacity_preflight' => [
                'source_bytes' => $sourceBytes,
                'required_bytes' => $requiredBytes,
                'available_bytes' => $availableBytes,
                'available_inodes' => $capacity['available_inodes'],
            ],
            'artifacts' => $artifacts,
            'responsibility' => [
                'retention_owner' => $responsibility['retention_owner'],
                'cleanup_owner' => $responsibility['cleanup_owner'],
                'restore_owner' => $responsibility['restore_owner'],
            ],
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->manifest;
    }

    public function backupReferenceKey(): string
    {
        return $this->manifest['backup_reference_key'];
    }

    public function canonicalJson(): string
    {
        return json_encode(
            $this->manifest,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }

    /** @return list<array{role:string,reference:string,digest:string}> */
    private static function images(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 3) {
            self::invalid();
        }
        $expectedRoles = ['php', 'nginx', 'mysql'];
        $normalized = [];
        foreach ($value as $index => $image) {
            $image = self::map($image);
            self::exactKeys($image, ['role', 'reference', 'digest']);
            if (($image['role'] ?? null) !== $expectedRoles[$index]
                || !is_string($image['reference'] ?? null)
                || strlen($image['reference']) < 3
                || strlen($image['reference']) > 255
                || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/@:-]*$/D', $image['reference']) !== 1
                || !is_string($image['digest'] ?? null)
                || preg_match('/^sha256:[a-f0-9]{64}$/D', $image['digest']) !== 1
            ) {
                self::invalid();
            }
            $normalized[] = [
                'role' => $image['role'],
                'reference' => $image['reference'],
                'digest' => $image['digest'],
            ];
        }
        return $normalized;
    }

    /** @return list<array{kind:string,filename:string,bytes:int,sha256:string}> */
    private static function artifacts(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) !== 2) {
            self::invalid();
        }
        $expected = [
            ['kind' => 'database', 'filename' => self::DATABASE_ARTIFACT],
            ['kind' => 'files', 'filename' => self::FILES_ARTIFACT],
        ];
        $normalized = [];
        foreach ($value as $index => $artifact) {
            $artifact = self::map($artifact);
            self::exactKeys($artifact, ['kind', 'filename', 'bytes', 'sha256']);
            if (($artifact['kind'] ?? null) !== $expected[$index]['kind']
                || ($artifact['filename'] ?? null) !== $expected[$index]['filename']
                || !is_string($artifact['sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $artifact['sha256']) !== 1
            ) {
                self::invalid();
            }
            self::positiveInteger($artifact['bytes'] ?? null);
            $normalized[] = [
                'kind' => $artifact['kind'],
                'filename' => $artifact['filename'],
                'bytes' => $artifact['bytes'],
                'sha256' => $artifact['sha256'],
            ];
        }
        return $normalized;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value)) {
            self::invalid();
        }
        return $value;
    }

    /** @param array<string, mixed> $value @param list<string> $keys */
    private static function exactKeys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);
        if ($actual !== $keys) {
            self::invalid();
        }
    }

    private static function stableKey(mixed $value, int $maximum): void
    {
        if (!is_string($value)
            || strlen($value) > $maximum
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function basename(mixed $value): void
    {
        if (!is_string($value)
            || strlen($value) > 96
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function databaseName(mixed $value): void
    {
        if (!is_string($value)
            || strlen($value) > 64
            || preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function storageVolume(mixed $value): void
    {
        if (!is_string($value)
            || strlen($value) > 128
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D', $value) !== 1
        ) {
            self::invalid();
        }
    }

    private static function commit(mixed $value): void
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}$/D', $value) !== 1) {
            self::invalid();
        }
    }

    private static function instant(mixed $value): string
    {
        if (!is_string($value)
            || preg_match('/^(?:19|20)[0-9]{2}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]\.[0-9]{3}Z$/D', $value) !== 1
        ) {
            self::invalid();
        }
        $instant = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.v\Z', $value);
        if ($instant === false || $instant->format('Y-m-d\TH:i:s.v\Z') !== $value) {
            self::invalid();
        }
        return $value;
    }

    private static function nonNegativeInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 0) {
            self::invalid();
        }
        return $value;
    }

    private static function positiveInteger(mixed $value): int
    {
        if (!is_int($value) || $value < 1) {
            self::invalid();
        }
        return $value;
    }

    private static function invalid(): never
    {
        throw new InvalidArgumentException('Invalid paired backup manifest.');
    }
}
