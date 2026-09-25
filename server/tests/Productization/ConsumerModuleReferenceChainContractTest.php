<?php

declare(strict_types=1);

function referenceChainContractExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function referenceChainContractSection(string $source, string $start, string $end): string
{
    $offset = strpos($source, $start);
    $limit = $offset === false ? false : strpos($source, $end, $offset + strlen($start));
    if ($offset === false || $limit === false) {
        throw new RuntimeException('reference-chain section is unavailable: ' . $start);
    }
    return substr($source, $offset, $limit - $offset);
}

$root = dirname(__DIR__, 3);
$createApp = file_get_contents($root . '/scripts/create-app');
referenceChainContractExpect(is_string($createApp), 'public create-app source is unavailable');
referenceChainContractExpect(
    preg_match(
        '/new ApplicationCreator\(\s*\$root,\s*\$inventoryPath,\s*null,\s*\$adoption->path,/s',
        $createApp,
    ) === 1,
    'public create-app must derive generation source identity independently from the adoption manifest',
);

$path = $root . '/scripts/consumer-module-reference-chain';
$source = file_get_contents($path);
referenceChainContractExpect(is_string($source), 'reference-chain source is unavailable');

$creation = referenceChainContractSection($source, 'def create_application(', 'def install_dependencies(');
referenceChainContractExpect(
    str_contains($creation, 'ROOT / "scripts/create-app"'),
    'application generation must use the public create-app command',
);
referenceChainContractExpect(
    !str_contains($creation, 'ApplicationCreator') && !str_contains($creation, 'source_builder'),
    'application generation retained the private ApplicationCreator bypass',
);
referenceChainContractExpect(
    str_contains($source, '--candidate-manifest is required for an explicit development candidate'),
    'an unsealed development candidate is not rejected explicitly',
);

$helper = referenceChainContractSection($source, 'def lifecycle_helper()', 'def helper_action(');
foreach ([
    'PluginPackageInstaller',
    'PlatformModuleRuntimeService',
    'PluginRuntimeGovernanceService',
] as $forbidden) {
    referenceChainContractExpect(
        !str_contains($helper, $forbidden),
        'the read/service helper retained a privileged Package lifecycle bypass: ' . $forbidden,
    );
}
foreach ([
    'PlatformTenantAdminService::class',
    'RoleAdministrationRuntime::class',
    'Acme\\Modules\\ReferenceChain\\Contract\\ReferenceChainCommands::class',
] as $service) {
    referenceChainContractExpect(
        str_contains($helper, $service),
        'the reference chain does not exercise the formal service: ' . $service,
    );
}
referenceChainContractExpect(
    str_contains($source, 'AdminAuthorizationQuery')
        && str_contains($source, "decide(\$context, \$principal, 'acme.reference-chain.write')"),
    'the generated Module service does not enforce its formal RBAC permission',
);

$lifecycle = referenceChainContractSection($source, 'def package_install(', 'def run_chain(');
foreach ([
    'module:install-package',
    'module:update-package',
    'module:disable-package',
    'module:uninstall-package',
] as $command) {
    referenceChainContractExpect(
        str_contains($lifecycle, $command),
        'the generated application public lifecycle command is missing: ' . $command,
    );
}
referenceChainContractExpect(
    !str_contains($lifecycle, 'helper_action(') && !str_contains($lifecycle, 'edition =='),
    'Package lifecycle still branches around the public Module CLI',
);
referenceChainContractExpect(
    str_contains($source, '"tenant_fixture_entry": "synthetic_sql_fixture_not_product_entry"')
        && str_contains($source, '"business_data_entry": "module_contract_service"'),
    'the summary does not distinguish fixture state from product/service acceptance',
);

// 真实 Python 资源选择器的正反例；静态源码检查不能代替资源边界行为。
$resourceProcess = proc_open(
    ['python3', $root . '/scripts/tests/consumer-module-resource-test.py'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $resourcePipes,
    $root,
);
referenceChainContractExpect(is_resource($resourceProcess), 'resource contract runner is unavailable');
$resourceOutput = stream_get_contents($resourcePipes[1]);
$resourceError = stream_get_contents($resourcePipes[2]);
fclose($resourcePipes[1]);
fclose($resourcePipes[2]);
referenceChainContractExpect(
    proc_close($resourceProcess) === 0
    && str_contains((string) $resourceOutput, 'CONSUMER-RESOURCE-CONTRACT-001 passed (9 cases)'),
    'reference-chain resource contract failed: ' . $resourceError,
);
echo $resourceOutput;

echo "CONSUMER-MODULE-REFERENCE-CHAIN-CONTRACT-001 passed\n";
