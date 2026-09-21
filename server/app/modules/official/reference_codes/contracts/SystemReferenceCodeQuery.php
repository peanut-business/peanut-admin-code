<?php
declare(strict_types=1);

namespace app\modules\official\reference_codes\contracts;

use app\modules\official\reference_codes\dto\DictionaryEntryDto;

interface SystemReferenceCodeQuery
{
    /** @return list<DictionaryEntryDto> */
    public function systemEntriesByType(string $type): array;
}
