<?php

declare(strict_types=1);

use app\common\services\upgrade\PluginSourceDevelopmentHost;
use app\common\services\upgrade\SourceApplicationBackup;
use app\platform\services\plugin\PluginLifecycleService;
use PeanutAdmin\Modules\Identity\Database\IdentitySchemaMigrationProof;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Offline fixed-source/effect and additive metadata contracts only; no MySQL or migration execution.
$proof = new ReflectionClass(IdentitySchemaMigrationProof::class);
$expected = $proof->getMethod('expectedAlter');
$assert = $proof->getMethod('assertAlter');
$stages = $proof->getConstant('STAGES');
$lifecycle = (new ReflectionClass(PluginLifecycleService::class))->getConstant('PRESERVATION_STAGES');
$effects = (new ReflectionClass(SourceApplicationBackup::class))->getConstant('MODULE_DDL_STAGES');
$contracts = (new ReflectionClass(PluginSourceDevelopmentHost::class))->getConstant('CONTRACTS');
$expect = static function (bool $value, string $message): void {
    if (!$value) { throw new RuntimeException($message); }
};
$expect(count($stages) === 9 && $contracts['official.identity'] === IdentitySchemaMigrationProof::class, 'Identity stage registration differs.');
$migrations = [];
foreach ($stages as $key => [$sha, $table]) {
    $path = dirname(__DIR__, 2) . '/app/modules/official/identity/database/migrations/' . substr($key, strlen('official.identity:')) . '.sql';
    $expect(hash_file('sha256', $path) === $sha, 'Immutable source changed: ' . $key);
    $expect($lifecycle[$key][0] === $sha && $lifecycle[$key][1] === IdentitySchemaMigrationProof::class, 'Native lifecycle stage missing.');
    $expect($effects[$key] === [$sha, [$table], IdentitySchemaMigrationProof::class], 'Source effect stage differs.');
    foreach ($lifecycle[$key][2] as $predecessor) { $expect(isset($stages[$predecessor]) && strcmp($predecessor, $key) < 0, 'Non-native predecessor.'); }
    $migrations[] = ['migration_key' => $key, 'sha256' => $sha, 'disposition' => 'execute'];
}
$plan = ['modules' => [['module_key' => 'official.identity', 'migrations' => $migrations]]];
$closure = SourceApplicationBackup::pluginEffects($plan);
$expect($closure['closure_complete'] && count($closure['write_requirements']) === 9 && count($closure['pending']) === 9, 'Identity effect closure incomplete.');
foreach ($stages as [, $table]) { $expect(in_array($table, $closure['tables'], true), 'Missing fixed write table.'); }
$wrong = $plan; $wrong['modules'][0]['migrations'][0]['sha256'] = str_repeat('0', 64);
$expect(!SourceApplicationBackup::pluginEffects($wrong)['closure_complete'], 'Changed SQL digest admitted.');
$wrong = $plan; $wrong['modules'][0]['migrations'][0]['migration_key'] = 'official.identity:unknown';
$expect(!SourceApplicationBackup::pluginEffects($wrong)['closure_complete'], 'Unknown stage admitted.');

$column = static fn(string $name, int $ordinal): array => ['COLUMN_NAME' => $name, 'ORDINAL_POSITION' => (string) $ordinal,
    'COLUMN_TYPE' => 'bigint unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '',
    'CHARACTER_SET_NAME' => null, 'COLLATION_NAME' => null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
$base = ['table' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_0900_ai_ci',
    'AUTO_INCREMENT' => '12', 'CREATE_OPTIONS' => '', 'TABLE_COMMENT' => 'preserve', 'ROW_FORMAT' => 'Dynamic'],
    'charset' => 'utf8mb4', 'columns' => [], 'indexes' => ['custom' => [['preserved' => 'index']],
        'PRIMARY' => [['COLUMN_NAME' => 'id', 'NON_UNIQUE' => '0']]],
    'checks' => ['custom' => ['ENFORCED' => 'YES', 'CHECK_CLAUSE' => 'id>0']],
    'foreign_keys' => [['CONSTRAINT_NAME' => 'custom', 'DELETE_RULE' => 'RESTRICT']], 'ddl' => 'original SHOW CREATE'];
foreach (['id', 'tenant_id', 'name', 'event_type', 'tail'] as $offset => $name) { $base['columns'][$name] = $column($name, $offset + 1); }
$contacts = $expected->invoke(null, $base, 'contacts');
$expect(array_keys($contacts['columns']) === ['id', 'tenant_id', 'name', 'leader', 'mobile', 'event_type', 'tail'], 'Contact positions differ from native AFTER.');
$expect($contacts['columns']['leader']['COLUMN_TYPE'] === 'varchar(50)' && $contacts['columns']['mobile']['COLUMN_TYPE'] === 'varchar(20)'
    && $contacts['columns']['leader']['IS_NULLABLE'] === 'NO' && $contacts['columns']['leader']['COLUMN_DEFAULT'] === '', 'Contact defaults/types changed.');
$assert->invoke(null, $base, $contacts + ['ddl' => 'new SHOW CREATE'], 'contacts');
$nativeOrder = $contacts; $nativeOrder['indexes'] = array_reverse($contacts['indexes'], true);
$assert->invoke(null, $base, $nativeOrder, 'contacts'); // Native collation orders lowercase names before PRIMARY.
$assert->invoke(null, $contacts, $contacts, 'contacts'); // The immutable conditional statements genuinely do nothing.
$member = $expected->invoke(null, $contacts, 'leader_member');
$expect(array_keys($member['columns']) === ['id', 'tenant_id', 'name', 'leader', 'leader_member_id', 'mobile', 'event_type', 'tail'], 'Member position differs.');
$expect($member['columns']['leader_member_id']['IS_NULLABLE'] === 'YES'
    && array_column($member['indexes']['idx_department_leader_member'], 'COLUMN_NAME') === ['tenant_id', 'leader_member_id']
    && $member['indexes']['idx_department_leader_member'][0]['NON_UNIQUE'] === '1', 'Member field/index contract differs.');
$assert->invoke(null, $contacts, $member, 'leader_member');
$nativeOrder = $member; $nativeOrder['indexes'] = array_reverse($member['indexes'], true);
$assert->invoke(null, $contacts, $nativeOrder, 'leader_member');
$assert->invoke(null, $member, $member, 'leader_member');
$audit = $expected->invoke(null, $base, 'audit');
$expect(array_slice(array_keys($audit['columns']), -2) === ['revocation_fact_key', 'revocation_fact_sha256']
    && $audit['columns']['revocation_fact_key']['IS_NULLABLE'] === 'YES'
    && $audit['columns']['revocation_fact_sha256']['COLUMN_TYPE'] === 'char(64)'
    && $audit['columns']['revocation_fact_key']['COLLATION_NAME'] === 'ascii_bin'
    && $audit['indexes']['uk_identity_revocation_fact'][0]['NON_UNIQUE'] === '0'
    && array_column($audit['indexes']['idx_identity_revocation_scan'], 'COLUMN_NAME') === ['tenant_id', 'event_type', 'id'], 'Audit additive contract differs.');
$assert->invoke(null, $base, $audit, 'audit');

$faults = [];
$changed = $contacts; $changed['table']['AUTO_INCREMENT'] = '13'; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['columns']['tail']['COLUMN_TYPE'] = 'int'; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['columns']['mobile']['COLUMN_DEFAULT'] = null; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['columns']['leader']['COLLATION_NAME'] = 'utf8mb4_general_ci'; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['columns']['tail']['ORDINAL_POSITION'] = '5'; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; unset($changed['indexes']['custom']); $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['checks']['custom']['ENFORCED'] = 'NO'; $faults[] = [$base, $changed, 'contacts'];
$changed = $contacts; $changed['foreign_keys'][0]['DELETE_RULE'] = 'CASCADE'; $faults[] = [$base, $changed, 'contacts'];
$changed = $member; $changed['indexes']['idx_department_leader_member'][0]['NON_UNIQUE'] = '0'; $faults[] = [$contacts, $changed, 'leader_member'];
$changed = $member; $changed['indexes']['idx_department_leader_member'] = array_reverse($changed['indexes']['idx_department_leader_member']); $faults[] = [$contacts, $changed, 'leader_member'];
$changed = $audit; $changed['indexes']['uk_identity_revocation_fact'][0]['NON_UNIQUE'] = '1'; $faults[] = [$base, $changed, 'audit'];
$changed = $audit; $changed['columns']['revocation_fact_key']['IS_NULLABLE'] = 'NO'; $faults[] = [$base, $changed, 'audit'];
$changed = $audit; $changed['table']['ENGINE'] = 'MyISAM'; $faults[] = [$base, $changed, 'audit'];
foreach ($faults as [$before, $after, $kind]) {
    try { $assert->invoke(null, $before, $after, $kind); }
    catch (RuntimeException $error) {
        if ($error->getMessage() === 'IDENTITY_SCHEMA_STAGE_ALTER_POSTSTATE_INVALID') { continue; }
        throw $error;
    }
    throw new RuntimeException('Changed existing/additive metadata accepted.');
}
$invalidBefore = [[$audit, 'audit'], [$base, 'leader_member']];
$changed = $contacts; $changed['indexes']['idx_department_leader_member'] = [['unexpected' => true]]; $invalidBefore[] = [$changed, 'leader_member'];
$changed = $contacts; $changed['columns']['leader']['COLUMN_TYPE'] = 'int'; $invalidBefore[] = [$changed, 'contacts'];
foreach ($invalidBefore as [$before, $kind]) {
    try { $expected->invoke(null, $before, $kind); }
    catch (RuntimeException $error) {
        if (str_starts_with($error->getMessage(), 'IDENTITY_SCHEMA_STAGE_')) { continue; }
        throw $error;
    }
    throw new RuntimeException('Unsupported partial prestate accepted.');
}
echo "Identity schema contract: 9 fixed native/effect registrations, 2 source refusals, 7 additive/no-op/native-index-order metadata cases, 17 metadata refusals; no database executed.\n";
