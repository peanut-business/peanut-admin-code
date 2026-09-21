<?php

declare(strict_types=1);

namespace app\modules\official\reference_codes\Contract;

use app\modules\official\reference_codes\DictionaryEntry;

interface SystemDictionaryProvider
{
    /** @return list<DictionaryEntry> */
    public function enabledEntriesByType(string $type): array;
}
