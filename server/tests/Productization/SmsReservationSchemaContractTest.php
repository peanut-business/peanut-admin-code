<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Notification\Contract\SmsReservationMigrationProof;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Synthetic schema comparison only: this is not a live migration preservation proof.
$reflection = new ReflectionClass(SmsReservationMigrationProof::class);
$assert = $reflection->getMethod('assertSchema');
$column = static fn(string $name, int $position): array => ['COLUMN_NAME' => $name,
    'ORDINAL_POSITION' => (string) $position, 'COLUMN_TYPE' => 'bigint unsigned', 'IS_NULLABLE' => 'NO',
    'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null,
    'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
$index = static fn(string $name, string $field, int $position = 1, string $nonUnique = '0'): array => [
    'INDEX_NAME' => $name, 'NON_UNIQUE' => $nonUnique, 'SEQ_IN_INDEX' => (string) $position,
    'COLUMN_NAME' => $field, 'COLLATION' => 'A', 'SUB_PART' => null, 'PACKED' => null,
    'INDEX_TYPE' => 'BTREE', 'COMMENT' => '', 'INDEX_COMMENT' => '', 'IS_VISIBLE' => 'YES', 'EXPRESSION' => null];
$before = ['table' => ['ENGINE' => 'InnoDB', 'AUTO_INCREMENT' => '4'],
    'columns' => ['id' => $column('id', 1), 'verify_code_hash' => $column('verify_code_hash', 2), 'tail' => $column('tail', 3)],
    'indexes' => ['idx_original' => [$index('idx_original', 'id', 1, '1'), $index('idx_original', 'tail', 2, '1')],
        'PRIMARY' => [$index('PRIMARY', 'id')]], 'checks' => [], 'foreign_keys' => []];
$after = $before;
unset($after['columns']['tail']);
$position = 2;
foreach ($reflection->getConstant('COLUMNS') as $name => [$type, $charset, $collation, $comment]) {
    $after['columns'][$name] = array_replace($column($name, ++$position), ['COLUMN_TYPE' => $type,
        'IS_NULLABLE' => 'YES', 'CHARACTER_SET_NAME' => $charset, 'COLLATION_NAME' => $collation, 'COLUMN_COMMENT' => $comment]);
}
$after['columns']['tail'] = $column('tail', ++$position);
foreach ($reflection->getConstant('INDEXES') as $name => [$unique, $fields]) {
    foreach ($fields as $offset => $field) { $after['indexes'][$name][] = $index($name, $field, $offset + 1, $unique ? '0' : '1'); }
}
$check = $reflection->getConstant('CHECK');
$after['checks'][$check] = ['CONSTRAINT_NAME' => $check, 'ENFORCED' => 'YES', 'CHECK_CLAUSE' => $reflection->getConstant('PREDICATE')];
$assert->invoke(null, $before, $after);
$permuted = $after;
$permuted['indexes'] = array_reverse($permuted['indexes'], true);
$assert->invoke(null, $before, $permuted);
$faults = [];
$changed = $after; $changed['indexes']['idx_original'] = array_reverse($changed['indexes']['idx_original']); $faults[] = $changed;
$changed = $after; $changed['indexes']['uk_notice_sms_idempotency'][0]['NON_UNIQUE'] = '1'; $faults[] = $changed;
$changed = $after; unset($changed['indexes']['PRIMARY']); $faults[] = $changed;
$changed = $after; $changed['columns']['reservation_key']['COLUMN_TYPE'] = 'char(36)'; $faults[] = $changed;
$changed = $after; $changed['columns'] = array_reverse($changed['columns'], true); $faults[] = $changed;
$changed = $after; $changed['table']['AUTO_INCREMENT'] = '5'; $faults[] = $changed;
$changed = $after; $changed['checks'][$check]['ENFORCED'] = 'NO'; $faults[] = $changed;
$changed = $after; $changed['checks'][$check]['CHECK_CLAUSE'] = 'reservation_active IS NULL'; $faults[] = $changed;
$changed = $after; $changed['foreign_keys'][] = ['CONSTRAINT_NAME' => 'unexpected']; $faults[] = $changed;
foreach ($faults as $changed) {
    try { $assert->invoke(null, $before, $changed); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'NOTIFICATION_SMS_MIGRATION_SCHEMA_POSTIMAGE_INVALID') { continue; }
        throw $error;
    }
    throw new RuntimeException('Changed schema accepted.');
}
echo "SMS schema contract: 2 index-map orders accepted; 9 structural changes rejected; no database executed.\n";
