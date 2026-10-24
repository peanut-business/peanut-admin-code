<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Database;

use RuntimeException;

/** Identity's bounded metadata failure facts, without snapshots or field values. */
final class IdentitySchemaMigrationProofFailure extends RuntimeException
{
    public const CAUSE = 'IDENTITY_SCHEMA_STAGE_ALTER_POSTSTATE_INVALID';
    public const LIMIT = 16;
    private readonly ?array $metadataDifference;

    public function __construct(array $diagnostic)
    {
        // A diagnostic must never replace the original failed business guard.
        $this->metadataDifference = self::valid($diagnostic) ? $diagnostic : null;
        parent::__construct(self::CAUSE);
    }

    public function diagnostic(): ?array { return $this->metadataDifference; }

    /** Only schema identifiers, fixed metadata keys and row positions can name a path. */
    public static function boundedPath(array $path): array
    {
        $fields = [
            'table' => ['TABLE_TYPE', 'ENGINE', 'TABLE_COLLATION', 'AUTO_INCREMENT', 'CREATE_OPTIONS', 'TABLE_COMMENT', 'ROW_FORMAT'],
            'columns' => ['COLUMN_NAME', 'ORDINAL_POSITION', 'COLUMN_TYPE', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA', 'CHARACTER_SET_NAME', 'COLLATION_NAME', 'COLUMN_COMMENT', 'GENERATION_EXPRESSION'],
            'indexes' => ['INDEX_NAME', 'NON_UNIQUE', 'SEQ_IN_INDEX', 'COLUMN_NAME', 'COLLATION', 'SUB_PART', 'PACKED', 'INDEX_TYPE', 'COMMENT', 'INDEX_COMMENT', 'IS_VISIBLE', 'EXPRESSION'],
            'checks' => ['CONSTRAINT_NAME', 'ENFORCED', 'CHECK_CLAUSE'],
            'foreign_keys' => ['CONSTRAINT_NAME', 'ORDINAL_POSITION', 'COLUMN_NAME', 'REFERENCED_TABLE_SCHEMA', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME', 'UPDATE_RULE', 'DELETE_RULE'],
        ];
        if (!array_is_list($path) || count($path) > 4 || !is_string($path[0] ?? null)
            || !in_array($path[0], ['table', 'charset', 'columns', 'indexes', 'checks', 'foreign_keys'], true)) { return ['@shape']; }
        $root = $path[0];
        if (count($path) === 1) { return $path; }
        $fieldPosition = match ($root) { 'table' => 1, 'columns', 'checks' => 2, 'indexes' => 3, 'foreign_keys' => 2, default => 0 };
        if ($fieldPosition === 0 || count($path) > $fieldPosition + 1) { return ['@shape']; }
        if (in_array($root, ['columns', 'indexes', 'checks'], true)) {
            if (!is_string($path[1]) || preg_match('/\A(?:[A-Za-z_][A-Za-z0-9_]{0,63}|#sha256:[a-f0-9]{64})\z/D', $path[1]) !== 1) {
                $path[1] = '#sha256:' . hash('sha256', serialize($path[1]));
            }
        }
        $position = $root === 'indexes' ? 2 : ($root === 'foreign_keys' ? 1 : null);
        if ($position !== null && isset($path[$position]) && (!is_int($path[$position]) || $path[$position] < 0)) { return ['@shape']; }
        if (isset($path[$fieldPosition]) && !in_array($path[$fieldPosition], $fields[$root], true)) { return ['@shape']; }
        return $path;
    }

    private static function valid(array $value): bool
    {
        if (array_keys($value) !== ['format', 'digest_format', 'expected_sha256', 'actual_sha256', 'difference_count', 'reported_count', 'truncated', 'differences']
            || $value['format'] !== 'identity.schema-alter-difference.v1'
            || $value['digest_format'] !== 'php-serialize-guard-metadata.v1'
            || !is_string($value['expected_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $value['expected_sha256']) !== 1
            || !is_string($value['actual_sha256']) || preg_match('/\A[a-f0-9]{64}\z/D', $value['actual_sha256']) !== 1
            || !is_int($value['difference_count']) || !is_int($value['reported_count'])
            || !is_array($value['differences']) || !array_is_list($value['differences'])
            || $value['reported_count'] !== count($value['differences']) || $value['reported_count'] < 1 || $value['reported_count'] > self::LIMIT
            || $value['difference_count'] < $value['reported_count']
            || $value['truncated'] !== ($value['difference_count'] > $value['reported_count'])) { return false; }
        foreach ($value['differences'] as $difference) {
            if (!is_array($difference) || array_keys($difference) !== ['path', 'kind'] || !is_array($difference['path'])
                || ($difference['path'] !== ['@shape'] && self::boundedPath($difference['path']) !== $difference['path'])
                || !in_array($difference['kind'], ['missing_expected', 'missing_actual', 'type', 'value', 'order', 'shape'], true)) { return false; }
        }
        return true;
    }
}
