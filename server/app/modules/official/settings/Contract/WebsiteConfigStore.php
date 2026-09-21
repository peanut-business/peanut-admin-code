<?php

declare(strict_types=1);

namespace app\modules\official\settings\Contract;

/** Application-owned persistence adapter for a website configuration document. */
interface WebsiteConfigStore
{
    /** @return array<string, mixed> */
    public function read(): array;

    /** @param array<string, string> $values */
    public function replaceAtomically(array $values): void;
}
