<?php
declare(strict_types=1);

namespace app\modules\official\file\contracts;

interface FileStorage
{
    /** @return array{file_key:string,object_key:string,access_type:string,url:string,original_name:string} */
    public function storePath(int $tenantId, ?int $memberId, string $purpose, string $sourcePath, string $originalName, string $mediaType): array;

    public function delete(int $tenantId, string $fileKey): void;

    public function accessUrlForTenant(int $tenantId, string $fileKey): string;

    /** @return array{path:string,temporary:bool,filename:string,media_type:string} */
    public function openForTenant(int $tenantId, string $fileKey): array;

    /** @return array{path:string,temporary:bool,filename:string,media_type:string,disposition:string} */
    public function authorizedDownload(int $tenantId, string $fileKey, string $token): array;
}
