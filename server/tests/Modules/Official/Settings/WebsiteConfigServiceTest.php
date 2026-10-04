<?php

declare(strict_types=1);

use PeanutAdmin\Modules\Settings\Infrastructure\BrandDefaults;
use PeanutAdmin\Modules\Settings\Application\WebsiteConfigService;
use PeanutAdmin\Modules\Settings\Contract\WebsiteConfigStore;

require_once defined('PHPUNIT_COMPOSER_INSTALL')
    ? PHPUNIT_COMPOSER_INSTALL
    : dirname(__DIR__, 4) . '/vendor/autoload.php';

final class MemoryWebsiteConfigStore implements WebsiteConfigStore
{
    public int $attempts = 0;
    public bool $fail = false;

    /** @param array<string, mixed> $values */
    public function __construct(public array $values = []) {}

    public function read(): array
    {
        return $this->values;
    }

    public function replaceAtomically(array $values): void
    {
        ++$this->attempts;
        if ($this->fail) {
            throw new RuntimeException('simulated storage failure');
        }
        $this->values = $values;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, string> */
function validPayload(): array
{
    $payload = array_fill_keys(WebsiteConfigService::fields(), '');
    $payload['name'] = '  Peanut Admin  ';
    $payload['shop_name'] = ' Peanut Shop ';
    $payload['web_logo'] = ' /logo.png ';
    return $payload;
}

$store = new MemoryWebsiteConfigStore([
    'name' => 'Peanut Admin',
    'shop_name' => 'Peanut Shop',
    'web_logo' => 'logo.png',
    'ignored' => 'must-not-leak',
]);
$service = new WebsiteConfigService(
    $store,
    static fn(string $value): string => $value === '' ? '' : 'read:' . $value,
    static fn(string $value): string => $value === '' ? '' : 'stored:' . $value,
    BrandDefaults::website(),
);

$result = $service->get();
expect(array_keys($result) === WebsiteConfigService::fields(), 'read must expose only fixed fields');
expect($result['web_logo'] === 'read:logo.png', 'read mapper must handle image fields');
expect($result['pc_logo'] === 'read:brand/logo.svg', 'empty image fields must resolve to the bootstrap asset');
expect($result['pc_desc'] === 'Peanut Admin 全端管理脚手架', 'missing fields must use the bootstrap default');

$service->save(validPayload());
expect($store->attempts === 1, 'valid save must produce one atomic store call');
expect($store->values['name'] === 'Peanut Admin', 'text fields must be trimmed');
expect($store->values['shop_name'] === 'Peanut Shop', 'required fields must be preserved');
expect($store->values['web_logo'] === 'stored:/logo.png', 'storage mapper must handle image fields');
expect(array_keys($store->values) === WebsiteConfigService::fields(), 'save must write one complete field set');

$invalidPayloads = [];
$emptyName = validPayload();
$emptyName['name'] = '   ';
$invalidPayloads[] = $emptyName;
$longTitle = validPayload();
$longTitle['pc_title'] = str_repeat('a', 121);
$invalidPayloads[] = $longTitle;
$nonString = validPayload();
$nonString['pc_desc'] = ['invalid'];
$invalidPayloads[] = $nonString;
$unsafeUrl = validPayload();
$unsafeUrl['official_url'] = 'javascript:alert(1)';
$invalidPayloads[] = $unsafeUrl;

foreach ($invalidPayloads as $payload) {
    $before = $store->values;
    $attempts = $store->attempts;
    try {
        $service->save($payload);
        throw new RuntimeException('invalid payload must fail');
    } catch (InvalidArgumentException) {
    }
    expect($store->attempts === $attempts, 'invalid payload must not call the store');
    expect($store->values === $before, 'invalid payload must not change stored values');
}

$store->fail = true;
$before = $store->values;
try {
    $service->save(validPayload());
    throw new RuntimeException('storage failure must escape');
} catch (RuntimeException $exception) {
    expect($exception->getMessage() === 'simulated storage failure', 'storage failure must not be replaced');
}
expect($store->values === $before, 'failed atomic store must leave values unchanged');

echo "PB04-SETTINGS-WEBSITE-001 passed\n";
