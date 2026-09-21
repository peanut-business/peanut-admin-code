<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes\contracts;

use app\modules\official\reference_codes\dto\DictionaryEntryDto;
use app\modules\official\reference_codes\dto\DictionaryTypeDto;
use PeanutAdmin\Kernel\Auth\TenantContext;

interface TenantDictionaryCommands
{
    /** @param array<string,mixed> $values */
    public function createType(TenantContext $context, array $values): DictionaryTypeDto;

    /** @param array<string,mixed> $values */
    public function replaceType(TenantContext $context, int $id, array $values): DictionaryTypeDto;

    public function deleteType(TenantContext $context, int $id): void;

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void;

    /** @param array<string,mixed> $values */
    public function createEntry(TenantContext $context, array $values): DictionaryEntryDto;

    /** @param array<string,mixed> $values */
    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntryDto;

    public function deleteEntry(TenantContext $context, int $id): void;

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void;
}
