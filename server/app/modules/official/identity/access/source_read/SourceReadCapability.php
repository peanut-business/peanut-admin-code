<?php

declare(strict_types=1);

namespace app\modules\official\identity\access\source_read;

final readonly class SourceReadCapability
{
    /** @param non-empty-list<string> $fields */
    public function __construct(
        public string $key,
        public string $action,
        public string $moduleKey,
        public string $readPermissionKey,
        public string $managePermissionKey,
        public array $fields,
    ) {
        foreach ([$key, $moduleKey, $readPermissionKey, $managePermissionKey] as $value) {
            if (preg_match('/^[a-z][a-z0-9.-]{2,159}$/D', $value) !== 1) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
            }
        }
        if (preg_match('/^[a-z][a-z0-9.-]{1,63}$/D', $action) !== 1
            || $fields === [] || !array_is_list($fields)) {
            throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
        }
        $seen = [];
        foreach ($fields as $field) {
            if (!is_string($field) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1
                || isset($seen[$field])) {
                throw new \InvalidArgumentException('SOURCE_READ_CAPABILITY_INVALID');
            }
            $seen[$field] = true;
        }
    }
}
