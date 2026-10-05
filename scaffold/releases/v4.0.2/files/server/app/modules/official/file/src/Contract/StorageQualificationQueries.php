<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\File\Contract;

use think\facade\Db;

/** 受信平台配置检查的固定投影；保留原摘要，不返回密文、路径或存储模型，不访问外部存储。 */
final readonly class StorageQualificationQueries
{
    /** @return list<array{account_key:string,driver:string,configured:bool,credential_rotated_at:string|null,config_digest:string}> */
    public function subjects(string $digestKey): array
    {
        if (strlen($digestKey) < 32) {
            throw new \InvalidArgumentException('PROVIDER_QUALIFICATION_DIGEST_KEY_INVALID');
        }
        $rows = Db::name('storage_account')->alias('a')
            ->leftJoin('storage_space s', "s.account_id=a.id AND s.status='active'")
            ->field('a.id,a.account_key,a.driver,a.credential_ciphertext,a.credential_key_version,a.credential_rotated_at,a.status,a.updated_at')
            ->fieldRaw('COUNT(s.id) AS active_space_count,MAX(s.updated_at) AS space_updated_at')
            ->group('a.id,a.account_key,a.driver,a.credential_ciphertext,a.credential_key_version,a.credential_rotated_at,a.status,a.updated_at')
            ->order('a.id')->select()->toArray();
        return array_map(static function (array $row) use ($digestKey): array {
            $driver = (string) $row['driver'];
            $configured = (string) $row['status'] === 'active'
                && (int) $row['active_space_count'] > 0
                && ($driver === 'local' || (
                    trim((string) $row['credential_ciphertext']) !== ''
                    && trim((string) $row['credential_key_version']) !== ''
                    && trim((string) $row['credential_rotated_at']) !== ''
                ));
            $payload = implode("\0", array_map(static fn(mixed $value): string => (string) $value, [
                $row['id'], $row['account_key'], $driver, $row['credential_ciphertext'],
                $row['credential_key_version'], $row['credential_rotated_at'], $row['status'],
                $row['updated_at'], $row['active_space_count'], $row['space_updated_at'],
            ]));
            $rotatedAt = null;
            if ($driver !== 'local') {
                $timestamp = strtotime((string) $row['credential_rotated_at'] . ' UTC');
                $rotatedAt = $timestamp === false ? null : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
            }
            return [
                'account_key' => (string) $row['account_key'],
                'driver' => $driver,
                'configured' => $configured,
                'credential_rotated_at' => $rotatedAt,
                'config_digest' => hash_hmac('sha256', $payload, $digestKey),
            ];
        }, $rows);
    }
}
