<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes\services;

use app\modules\official\reference_codes\contracts\DictionaryQuery;
use app\modules\official\reference_codes\contracts\SystemReferenceCodeQuery;
use app\modules\official\reference_codes\contracts\TenantDictionaryCommands;
use app\modules\official\reference_codes\dto\DictionaryEntryDto;
use app\modules\official\reference_codes\dto\DictionaryPageDto;
use app\modules\official\reference_codes\dto\DictionaryTypeDto;
use PeanutAdmin\Kernel\Auth\TenantContext;
use app\modules\official\reference_codes\Application\DictionaryService as CoreDictionaryService;
use app\modules\official\reference_codes\Contract\SystemDictionaryProvider;
use app\modules\official\reference_codes\DictionaryEntry;
use app\modules\official\reference_codes\DictionaryPage;
use app\modules\official\reference_codes\DictionaryType;

final class DictionaryRuntime implements DictionaryQuery, TenantDictionaryCommands, SystemReferenceCodeQuery
{
    public function __construct(
        private CoreDictionaryService $core,
        private SystemDictionaryProvider $system,
    ) {}

    public function types(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPageDto
    {
        return $this->toPage($this->core->types($context, $filters, $page, $pageSize));
    }

    public function entries(TenantContext $context, array $filters, int $page, int $pageSize): DictionaryPageDto
    {
        return $this->toPage($this->core->entries($context, $filters, $page, $pageSize));
    }

    public function type(TenantContext $context, int $id): ?DictionaryTypeDto
    {
        return $this->typeDto($this->core->type($context, $id));
    }

    public function enabledTypes(TenantContext $context): array
    {
        return array_map(fn (DictionaryType $type): DictionaryTypeDto => DictionaryTypeDto::fromCore($type), $this->core->enabledTypes($context));
    }

    public function entry(TenantContext $context, int $id): ?DictionaryEntryDto
    {
        return $this->entryDto($this->core->entry($context, $id));
    }

    public function enabledByType(TenantContext $context, string $type): array
    {
        return array_map(
            fn (array $entry): DictionaryEntryDto => DictionaryEntryDto::fromCore(DictionaryEntry::fromArray($entry, (string) ($entry['source'] ?? 'tenant'))),
            $this->core->enabledByType($context, $type),
        );
    }

    public function systemEntriesByType(string $type): array
    {
        return array_map(
            fn (DictionaryEntry $entry): DictionaryEntryDto => DictionaryEntryDto::fromCore($entry),
            $this->systemProviderEntries($type),
        );
    }

    public function createType(TenantContext $context, array $values): DictionaryTypeDto
    {
        return DictionaryTypeDto::fromCore($this->core->createType($context, $values));
    }

    public function replaceType(TenantContext $context, int $id, array $values): DictionaryTypeDto
    {
        return DictionaryTypeDto::fromCore($this->core->replaceType($context, $id, $values));
    }

    public function deleteType(TenantContext $context, int $id): void
    {
        $this->core->deleteType($context, $id);
    }

    public function setTypeDisabled(TenantContext $context, int $id, bool $disabled): void
    {
        $this->core->setTypeDisabled($context, $id, $disabled);
    }

    public function createEntry(TenantContext $context, array $values): DictionaryEntryDto
    {
        return DictionaryEntryDto::fromCore($this->core->createEntry($context, $values));
    }

    public function replaceEntry(TenantContext $context, int $id, array $values): DictionaryEntryDto
    {
        return DictionaryEntryDto::fromCore($this->core->replaceEntry($context, $id, $values));
    }

    public function deleteEntry(TenantContext $context, int $id): void
    {
        $this->core->deleteEntry($context, $id);
    }

    public function setEntryDisabled(TenantContext $context, int $id, bool $disabled): void
    {
        $this->core->setEntryDisabled($context, $id, $disabled);
    }

    private function toPage(DictionaryPage $page): DictionaryPageDto
    {
        $items = array_map(
            static fn (DictionaryType|DictionaryEntry $item): DictionaryTypeDto|DictionaryEntryDto
                => $item instanceof DictionaryType ? DictionaryTypeDto::fromCore($item) : DictionaryEntryDto::fromCore($item),
            $page->items,
        );
        return new DictionaryPageDto($items, $page->count, $page->page, $page->pageSize);
    }

    private function typeDto(?DictionaryType $type): ?DictionaryTypeDto
    {
        return $type === null ? null : DictionaryTypeDto::fromCore($type);
    }

    private function entryDto(?DictionaryEntry $entry): ?DictionaryEntryDto
    {
        return $entry === null ? null : DictionaryEntryDto::fromCore($entry);
    }

    /** @return list<DictionaryEntry> */
    private function systemProviderEntries(string $type): array
    {
        return $this->system->enabledEntriesByType($type);
    }
}
