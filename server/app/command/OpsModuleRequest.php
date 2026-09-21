<?php
declare(strict_types=1);

namespace app\command;

use app\modules\official\ops\services\DeploymentModuleRequestService;
use app\platform\services\plugin\PluginRuntimeGovernanceService;
use app\common\execution\ModuleContextualCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use Throwable;

/** Deployment-only preparation of an opaque Module operation request. */
final class OpsModuleRequest extends ModuleContextualCommand
{
    protected function configure(): void
    {
        $this->setName('ops-module:request')
            ->addArgument('action', Argument::REQUIRED, 'preview or prepare')
            ->addOption('delivery-resource-id', null, Option::VALUE_REQUIRED, 'Registered delivery worker resource')
            ->addOption('target-resource-id', null, Option::VALUE_REQUIRED, 'Registered deployment target')
            ->addOption('operation', null, Option::VALUE_REQUIRED, 'update, retire, or purge')
            ->addOption('package-key', null, Option::VALUE_REQUIRED, 'Exact Package key')
            ->addOption('archive-sha256', null, Option::VALUE_OPTIONAL, 'Trusted inbox archive SHA-256', '')
            ->addOption('signature-key-id', null, Option::VALUE_OPTIONAL, 'Trusted signing key id', '')
            ->addOption('confirm-plan-digest', null, Option::VALUE_OPTIONAL, 'Exact retire/purge preview digest', '')
            ->setDescription('Preview or stage one registry-bound Module delivery request');
    }

    protected function handle(Input $input, Output $output): int
    {
        try {
            $config = Config::get('modules', []);
            if (!is_array($config)) throw new \RuntimeException('OPS_MODULE_CONFIG_INVALID');
            $catalogs = $this->moduleCatalogs();
            $service = new DeploymentModuleRequestService(
                dirname(__DIR__, 3),
                $config,
                $this->trustedKeys(),
                new PluginRuntimeGovernanceService(dirname(__DIR__, 2), $config, $catalogs),
                $catalogs,
            );
            $arguments = [
                trim((string)$input->getOption('delivery-resource-id')),
                trim((string)$input->getOption('target-resource-id')),
                trim((string)$input->getOption('operation')),
                trim((string)$input->getOption('package-key')),
                ($sha = trim((string)$input->getOption('archive-sha256'))) === '' ? null : $sha,
                ($key = trim((string)$input->getOption('signature-key-id'))) === '' ? null : $key,
            ];
            $action = trim((string)$input->getArgument('action'));
            $prepareArguments = [
                ...$arguments,
                ($digest = trim((string)$input->getOption('confirm-plan-digest'))) === '' ? null : $digest,
            ];
            $result = match ($action) {
                'preview' => $service->preview(...$arguments),
                'prepare' => $service->prepare(...$prepareArguments),
                default => throw new \RuntimeException('OPS_MODULE_REQUEST_ACTION_INVALID'),
            };
            $output->writeln(json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            return 0;
        } catch (Throwable $exception) {
            $code = preg_match('/^(OPS|PLUGIN|MODULE|PACKAGE)_[A-Z0-9_]+$/D', $exception->getMessage()) === 1
                ? $exception->getMessage() : 'OPS_MODULE_REQUEST_FAILED';
            $output->writeln(json_encode(['ok' => false, 'error_code' => $code], JSON_THROW_ON_ERROR));
            return 1;
        }
    }

    /** @return array<string,string> */
    private function trustedKeys(): array
    {
        $trusted = [];
        foreach ((array)Config::get('module_packages.trusted_ed25519_keys', []) as $keyId => $encoded) {
            $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
            if (is_string($keyId) && is_string($decoded) && strlen($decoded) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $trusted[$keyId] = $decoded;
            }
        }
        return $trusted;
    }
}
