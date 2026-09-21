<?php

declare(strict_types=1);

namespace app\modules\official\settings\Cache;

use app\modules\official\settings\Application\EffectiveSetting;

interface RevisionedSettingCache
{
    public function get(string $key): ?EffectiveSetting;

    public function put(string $key, EffectiveSetting $setting): void;
}
