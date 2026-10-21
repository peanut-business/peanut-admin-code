<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Identity\Auth\AuthClientSchemaMigrationProof;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

// Pure CHECK grammar only; this does not execute a migration or prove its postimage.
$check = (new ReflectionClass(AuthClientSchemaMigrationProof::class))->getMethod('clientCheck');
$metadata = <<<'SQL'
((char_length(`client_key`) between 1 and 64) and regexp_like(`client_key`,_utf8mb4\'^[a-z]\',_utf8mb4\'c\') and (not(regexp_like(`client_key`,_utf8mb4\'[^a-z0-9-]\',_utf8mb4\'c\'))))
SQL;
// Exact MySQL 8.4 CHECK_CONSTRAINTS bytes observed with an ordinary PDO.
if (hash('sha256', $metadata) !== 'ce393daacf073b800ad85403ce2cd0af93eceab38e8076663ec1e106a88b02b8') {
    throw new RuntimeException('Native CHECK metadata fixture differs.');
}
$direct = str_replace("\\'", "'", $metadata);
$accepted = [$metadata, $direct, str_replace('_utf8mb4', '', $direct),
    str_replace('char_length', 'CHAR_LENGTH', $metadata), '(' . $metadata . ')'];
foreach ($accepted as $clause) {
    if (!$check->invoke(null, $clause)) { throw new RuntimeException('Source CHECK representation rejected.'); }
}
$rejected = [
    str_replace('between 1 and 64', 'between 0 and 64', $metadata),
    str_replace('between 1 and 64', 'between 1 and 65', $metadata),
    str_replace(' and regexp_like', ' or regexp_like', $metadata),
    str_replace('(not(regexp_like', '(regexp_like', $metadata),
    str_replace('^[a-z]', '^[A-Z]', $metadata),
    str_replace('[^a-z0-9-]', '[^a-z0-9_-]', $metadata),
    str_replace("\\'c\\'", "\\'i\\'", $metadata),
    str_replace("\\'c\\'", "\\'C\\'", $metadata),
    str_replace('`client_key`', '`other_key`', $metadata),
    str_replace("\\'^[a-z]\\'", "\\'^[a-z]'", $metadata),
    str_replace("\\'^[a-z]\\'", "'^[a-z]\\'", $metadata),
    str_replace("\\'", "\\\\'", $metadata),
    str_replace('^[a-z]', '^\\[a-z]', $metadata),
    $metadata . ' OR 1', $metadata . ';', $metadata . ' -- comment',
    substr($metadata, 1), $metadata . ')', '',
];
foreach ($rejected as $clause) {
    if ($check->invoke(null, $clause)) { throw new RuntimeException('Changed CHECK grammar accepted.'); }
}
echo 'Auth CHECK grammar: ' . count($accepted) . ' representations accepted; '
    . count($rejected) . " changes rejected; no database executed.\n";
