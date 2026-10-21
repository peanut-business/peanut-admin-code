<?php

declare(strict_types=1);

namespace app\common\services\upgrade;

use PDO;
use RuntimeException;

/** Technical observations only; owners retain transactions, tables and fixed semantic assertions. */
final class MySqlMetadataObservation
{
    public static function uncached(PDO $pdo, callable $read): mixed
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new RuntimeException('MYSQL_METADATA_DRIVER_UNSUPPORTED');
        }
        $original = $pdo->query('SELECT @@SESSION.information_schema_stats_expiry')->fetchColumn();
        if ((!is_int($original) && !is_string($original))
            || preg_match('/\A[0-9]{1,10}\z/D', (string) $original) !== 1
            || (int) $original > 4294967295) {
            throw new RuntimeException('MYSQL_METADATA_STATS_STATE_UNKNOWN');
        }
        try {
            $pdo->exec('SET SESSION information_schema_stats_expiry=0');
            return $read();
        } finally {
            $pdo->exec('SET SESSION information_schema_stats_expiry=' . (string) $original);
        }
    }

    /** Complete literal tokens only; preserve grouping and literal bytes for each owner's closed forms. */
    public static function checkTokens(string $sql): ?array
    {
        $pattern = <<<'RX'
~\G(?:\s+|(?<identifier>`[a-z_][a-z0-9_]*`|"[a-z_][a-z0-9_]*")|(?:_(?:utf8mb4|ascii))?(?:\\'(?<metadata_literal>[^'\\]*)\\'|(?<literal>'(?:''|[^'])*'))|(?<word>[a-z_][a-z0-9_]*|[0-9]+|<>|!=|>=|<=|[(),=<>+*%-]))~Ai
RX;
        $tokens = []; $offset = 0;
        while ($offset < strlen($sql)) {
            if (preg_match($pattern, $sql, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) { return null; }
            $offset += strlen($match[0]);
            if (trim($match[0]) === '') { continue; }
            $tokens[] = $match['metadata_literal'] !== null ? "'" . $match['metadata_literal'] . "'"
                : ($match['literal'] ?? strtolower($match['identifier'] !== null
                    ? substr($match['identifier'], 1, -1) : $match['word']));
        }
        return $tokens;
    }
}
