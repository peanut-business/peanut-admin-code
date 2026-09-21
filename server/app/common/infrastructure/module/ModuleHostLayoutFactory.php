<?php
declare(strict_types=1);

namespace app\common\infrastructure\module;

use app\common\value\module\ModulePhpNamespace;
use Composer\Autoload\ClassLoader;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;

/** Translates verified module Composer declarations into the Core host layout and runtime loader. */
final class ModuleHostLayoutFactory
{
    /** @param array<string,string> $moduleRoots Module key => verified absolute module root. */
    public static function fromModuleRoots(array $moduleRoots): ModuleHostLayout
    {
        return self::fromNamespaces(ModulePhpNamespace::map($moduleRoots));
    }

    public static function pathLayout(): ModuleHostLayout
    {
        return self::fromNamespaces([]);
    }

    /** @param array<string,string> $existingRoots Module key => verified absolute module root. */
    public static function assertNamespaceAvailable(
        string $moduleKey,
        string $namespace,
        array $existingRoots,
    ): void {
        $namespaces = ModulePhpNamespace::map($existingRoots);
        if (isset($namespaces[$moduleKey])) {
            throw new InvalidArgumentException('Module key already owns a PHP namespace.');
        }
        $namespaces[$moduleKey] = ModulePhpNamespace::validated($namespace);
        self::fromNamespaces($namespaces);
    }

    /**
     * Registers only roots already selected by deployment configuration or plugins.lock.
     * @param array<string,string> $moduleRoots Module key => verified absolute module root.
     */
    public static function registerRuntimeAutoload(array $moduleRoots, string $serverRoot): ModuleHostLayout
    {
        $namespaces = ModulePhpNamespace::map($moduleRoots);
        $vendorRoot = realpath(rtrim($serverRoot, '/') . '/vendor');
        if ($vendorRoot === false) {
            throw new InvalidArgumentException('Host Composer vendor root is unavailable.');
        }
        $loader = null;
        foreach (ClassLoader::getRegisteredLoaders() as $registeredVendor => $candidate) {
            if (realpath($registeredVendor) === $vendorRoot) {
                $loader = $candidate;
                break;
            }
        }
        if (!$loader instanceof ClassLoader) {
            throw new InvalidArgumentException('Host Composer loader is unavailable.');
        }

        $registered = $loader->getPrefixesPsr4();
        foreach ($namespaces as $moduleKey => $prefix) {
            $source = realpath($moduleRoots[$moduleKey] . '/src');
            if ($source === false || !is_dir($source)) {
                throw new InvalidArgumentException("Module PHP source root is unavailable: {$moduleKey}.");
            }
            foreach ($registered as $existingPrefix => $directories) {
                $samePrefix = strcasecmp($prefix, $existingPrefix) === 0;
                $overlaps = str_starts_with(strtolower($prefix), strtolower($existingPrefix))
                    || str_starts_with(strtolower($existingPrefix), strtolower($prefix));
                $sameSource = $samePrefix && count($directories) === 1
                    && realpath((string)$directories[0]) === $source;
                if ($overlaps && !$sameSource) {
                    throw new InvalidArgumentException(
                        "Module PHP namespace {$prefix} conflicts with an existing Composer prefix.",
                    );
                }
            }
            $loader->setPsr4($prefix, [$source]);
            $registered[$prefix] = [$source];
        }
        return self::fromNamespaces($namespaces);
    }

    /** @param array<string,string> $namespaces */
    private static function fromNamespaces(array $namespaces): ModuleHostLayout
    {
        return new ModuleHostLayout(
            'server/app/modules',
            'app\\modules',
            'web/src/modules',
            $namespaces,
        );
    }
}
