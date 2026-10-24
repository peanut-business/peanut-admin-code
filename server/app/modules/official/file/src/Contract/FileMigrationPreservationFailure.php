<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use RuntimeException;

/** File's bounded failure facts; never contains a metadata snapshot or field value. */
final class FileMigrationPreservationFailure extends RuntimeException
{
    public const CAUSE = 'FILE_PRESERVATION_ORIGINAL_SCHEMA_OR_COUNTER_CHANGED';
    public const LIMIT = 16;
    private readonly ?array $metadataDifference;

    public function __construct(array $diagnostic)
    {
        $this->metadataDifference = self::valid($diagnostic) ? $diagnostic : null;
        parent::__construct(self::CAUSE);
    }

    public function diagnostic(): ?array { return $this->metadataDifference; }

    /** Only literal metadata fields and list positions can leave File's observer. */
    public static function boundedPath(array $path): array
    {
        if ($path === []) { return ['@metadata']; }
        if (!array_is_list($path) || count($path) > 3) { return ['@shape']; }
        $root = $path[0];
        if (in_array($root, ['physical_table', 'ddl', 'auto_increment', 'complete'], true)) {
            return count($path) === 1 ? $path : ['@shape'];
        }
        if (!in_array($root, ['columns', 'primary', 'triggers'], true)) { return ['@shape']; }
        if (count($path) === 1) { return $path; }
        if (!is_int($path[1]) || $path[1] < 0) { return ['@shape']; }
        if (count($path) === 2) { return $path; }
        $fields = match ($root) {
            'columns' => ['COLUMN_NAME', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'GENERATION_EXPRESSION', 'CHARACTER_SET_NAME', 'COLLATION_NAME'],
            'triggers' => ['TRIGGER_NAME', 'EVENT_MANIPULATION', 'ACTION_ORDER', 'ACTION_CONDITION', 'ACTION_STATEMENT', 'ACTION_ORIENTATION', 'ACTION_TIMING', 'SQL_MODE', 'DEFINER', 'CHARACTER_SET_CLIENT', 'COLLATION_CONNECTION', 'DATABASE_COLLATION', 'CREATED'],
            default => [],
        };
        return in_array($path[2], $fields, true) ? $path : ['@shape'];
    }

    private static function valid(array $value): bool
    {
        if (array_keys($value) !== ['format', 'digest_format', 'table_role', 'comparison', 'before_sha256', 'expected_sha256', 'actual_sha256', 'difference_count', 'reported_count', 'truncated', 'differences']
            || $value['format'] !== 'file.preservation-metadata-difference.v1'
            || $value['digest_format'] !== 'php-serialize-guard-metadata.v1'
            || !in_array($value['table_role'], ['file', 'file_object', 'storage_account', 'storage_space', 'storage_route', 'file_image_asset', 'file_derivative', 'file_delivery_nonce', 'file_delivery_grant', 'file_reference', 'file_immutable_snapshot', 'file_visibility_batch', 'file_visibility_item', 'file_cate'], true)
            || !in_array($value['comparison'], ['unchanged_metadata', 'actual_without_declared_image_index'], true)) { return false; }
        foreach (['before_sha256', 'expected_sha256', 'actual_sha256'] as $key) {
            if (!is_string($value[$key]) || preg_match('/\A[a-f0-9]{64}\z/D', $value[$key]) !== 1) { return false; }
        }
        // This guard projects the declared addition out of actual, then compares to original metadata.
        if ($value['before_sha256'] !== $value['expected_sha256']
            || ($value['comparison'] === 'actual_without_declared_image_index' && $value['table_role'] !== 'file_object')
            || !is_int($value['difference_count']) || !is_int($value['reported_count'])
            || !is_array($value['differences']) || !array_is_list($value['differences'])
            || $value['reported_count'] !== count($value['differences']) || $value['reported_count'] < 1 || $value['reported_count'] > self::LIMIT
            || $value['difference_count'] < $value['reported_count']
            || $value['truncated'] !== ($value['difference_count'] > $value['reported_count'])) { return false; }
        $types = ['missing', 'null', 'bool', 'int', 'float', 'string', 'array', 'object', 'resource', 'unknown'];
        foreach ($value['differences'] as $difference) {
            if (!is_array($difference) || array_keys($difference) !== ['path', 'kind', 'expected_type', 'actual_type']
                || !is_array($difference['path'])
                || (!in_array($difference['path'], [['@metadata'], ['@shape']], true) && self::boundedPath($difference['path']) !== $difference['path'])
                || !in_array($difference['kind'], ['missing_expected', 'missing_actual', 'type', 'value', 'order', 'shape'], true)
                || !in_array($difference['expected_type'], $types, true) || !in_array($difference['actual_type'], $types, true)) { return false; }
        }
        return true;
    }
}
